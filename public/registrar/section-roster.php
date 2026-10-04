<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';
require_once __DIR__ . '/../../src/helpers/picker_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$sectionId = (int)($_GET['id'] ?? ($_POST['section_id'] ?? 0));
$stmt = $pdo->prepare(
    'SELECT sec.*, p.program_code FROM Section sec JOIN Program p ON p.program_id = sec.program_id
     WHERE sec.section_id = :id AND p.department_id = :dept'
);
$stmt->execute(['id' => $sectionId, 'dept' => $myDepartmentId]);
$section = $stmt->fetch();

if ($section === false) {
    require __DIR__ . '/../../includes/navbar.php';
    echo '<div class="container"><div class="alert alert-danger">Section not found, or not in your department.</div></div>';
    exit;
}

$term = $pdo->query("SELECT * FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC LIMIT 1")->fetch();
$termId = $term ? (int)$term['term_id'] : 0;

$error = '';
$message = flashGet('roster_msg') ?? '';
$warning = flashGet('roster_warn') ?? '';
$self = BASE_URL . '/registrar/section-roster.php?id=' . $sectionId;

function lockRosterEnrollment(PDO $pdo, int $enrollmentId, int $sectionId, int $termId, string $standing): array
{
    $stmt = $pdo->prepare(
        "SELECT e.*, s.first_name, s.last_name, s.overall_status
         FROM Enrollment e JOIN Student s ON s.student_id = e.student_id
         WHERE e.enrollment_id = :id AND e.section_id = :sec AND e.term_id = :tid AND e.status = 'approved' FOR UPDATE"
    );
    $stmt->execute(['id' => $enrollmentId, 'sec' => $sectionId, 'tid' => $termId]);
    $enr = $stmt->fetch();
    if (!$enr || $enr['student_standing'] !== $standing || $enr['overall_status'] !== 'active') {
        throw new RuntimeException("That student is not an active $standing student on this roster.");
    }
    return $enr;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $termId) {
    $action = $_POST['action'] ?? '';
    $eid = (int)($_POST['enrollment_id'] ?? 0);

    try {
        $pdo->beginTransaction();

        if ($action === 'move_section') {
            $enr = lockRosterEnrollment($pdo, $eid, $sectionId, $termId, 'regular');

            $stmt = $pdo->prepare(
                'SELECT sec.*, p.program_code FROM Section sec JOIN Program p ON p.program_id = sec.program_id
                 WHERE sec.section_id = :t AND p.department_id = :dept AND sec.program_id = :pid
                   AND sec.year_level = :yl AND sec.section_id != :cur'
            );
            $stmt->execute([
                't' => (int)($_POST['target_section_id'] ?? 0), 'dept' => $myDepartmentId,
                'pid' => $section['program_id'], 'yl' => $section['year_level'], 'cur' => $sectionId,
            ]);
            $target = $stmt->fetch();
            if (!$target) {
                throw new RuntimeException('Pick another section of the same program and year level.');
            }

            $graded = $pdo->prepare('SELECT COUNT(*) FROM Enrolled_subject WHERE enrollment_id = :e AND (grade IS NOT NULL OR remarks IS NOT NULL)');
            $graded->execute(['e' => $eid]);
            if ((int)$graded->fetchColumn() > 0) {
                throw new RuntimeException('Grades are already entered for this student this term, so the section cannot be changed.');
            }

            $rows = $pdo->prepare(
                'SELECT DISTINCT co.subject_id, sub.subject_code
                 FROM Enrolled_subject es JOIN Class_Offering co ON co.offering_id = es.offering_id
                 JOIN Subject sub ON sub.subject_id = co.subject_id
                 WHERE es.enrollment_id = :e'
            );
            $rows->execute(['e' => $eid]);
            $subjects = $rows->fetchAll();

            $find = $pdo->prepare('SELECT offering_id FROM Class_Offering WHERE subject_id = :sub AND section_id = :sec AND term_id = :tid');
            $remove = $pdo->prepare(
                'DELETE FROM Enrolled_subject WHERE enrollment_id = :e AND offering_id IN
                    (SELECT offering_id FROM Class_Offering WHERE subject_id = :sub AND term_id = :tid)'
            );
            $add = $pdo->prepare('INSERT INTO Enrolled_subject (enrollment_id, offering_id) VALUES (:e, :o)');

            $kept = [];
            foreach ($subjects as $sub) {
                $find->execute(['sub' => $sub['subject_id'], 'sec' => $target['section_id'], 'tid' => $termId]);
                $newOfferings = $find->fetchAll(PDO::FETCH_COLUMN);
                if (!$newOfferings) {
                    $kept[] = $sub['subject_code'];
                    continue;
                }
                $remove->execute(['e' => $eid, 'sub' => $sub['subject_id'], 'tid' => $termId]);
                foreach ($newOfferings as $oid) {
                    $add->execute(['e' => $eid, 'o' => $oid]);
                }
            }

            $all = $pdo->prepare('SELECT offering_id FROM Enrolled_subject WHERE enrollment_id = :e');
            $all->execute(['e' => $eid]);
            $clash = findScheduleConflict($pdo, $all->fetchAll(PDO::FETCH_COLUMN));
            if ($clash !== null) {
                throw new RuntimeException('The move would put two classes at the same time. ' . $clash);
            }

            $occupancy = sectionOccupancy($pdo, (int)$target['section_id']);
            $pdo->prepare('UPDATE Enrollment SET section_id = :t WHERE enrollment_id = :e')
                ->execute(['t' => $target['section_id'], 'e' => $eid]);
            $pdo->commit();

            flashSet('roster_msg', $enr['first_name'] . ' ' . $enr['last_name'] . ' was moved to ' . sectionLabel($target['year_level'], $target['section_name'], $target['program_code']) . '.');
            $notes = [];
            if ($kept) {
                $notes[] = 'No class exists in the new section for ' . implode(', ', $kept) . ', so those stayed on the old schedule. Add the missing offerings, then move again or change classes.';
            }
            if ($occupancy + 1 > (int)$target['max_slots']) {
                $notes[] = 'The new section is now over its limit of ' . (int)$target['max_slots'] . ' slots.';
            }
            if ($notes) { flashSet('roster_warn', implode(' ', $notes)); }
            header('Location: ' . $self);
            exit;
        }

        if ($action === 'change_classes') {
            $enr = lockRosterEnrollment($pdo, $eid, $sectionId, $termId, 'irregular');

            $cur = $pdo->prepare(
                'SELECT es.enrolled_subject_id, es.offering_id, es.grade, es.remarks, co.subject_id
                 FROM Enrolled_subject es JOIN Class_Offering co ON co.offering_id = es.offering_id
                 WHERE es.enrollment_id = :e'
            );
            $cur->execute(['e' => $eid]);
            $current = [];
            foreach ($cur->fetchAll() as $r) { $current[(int)$r['enrolled_subject_id']] = $r; }
            $isGraded = fn($r) => $r['grade'] !== null || $r['remarks'] !== null;

            // Removals
            $removals = [];
            foreach (array_keys($_POST['remove'] ?? []) as $esId) {
                $esId = (int)$esId;
                if (!isset($current[$esId])) { continue; }
                if ($isGraded($current[$esId])) {
                    throw new RuntimeException('A subject that already has a grade cannot be removed.');
                }
                $removals[$esId] = true;
            }

            // Swaps (a different class for the same subject)
            $offeringCheck = $pdo->prepare('SELECT subject_id, term_id FROM Class_Offering WHERE offering_id = :o');
            $swaps = [];
            foreach (($_POST['swap'] ?? []) as $esId => $newOid) {
                $esId = (int)$esId; $newOid = (int)$newOid;
                if (!isset($current[$esId]) || isset($removals[$esId]) || $newOid === (int)$current[$esId]['offering_id']) { continue; }
                if ($isGraded($current[$esId])) {
                    throw new RuntimeException('A subject that already has a grade cannot be changed.');
                }
                $offeringCheck->execute(['o' => $newOid]);
                $new = $offeringCheck->fetch();
                if (!$new || (int)$new['subject_id'] !== (int)$current[$esId]['subject_id'] || (int)$new['term_id'] !== $termId) {
                    throw new RuntimeException('That class is not an offering of the same subject this term.');
                }
                $swaps[$esId] = $newOid;
            }

            // Additions, re-checked against the same rules the picker shows
            $pickRows = buildPickerRows($pdo, (int)$enr['student_id'], (int)$enr['curriculum_id'], $termId, null, $eid);
            $additions = pickerSelectedOfferings($pickRows, $_POST);

            if (!$removals && !$swaps && !$additions) {
                throw new RuntimeException('Nothing was changed.');
            }

            $final = [];
            foreach ($current as $esId => $r) {
                if (isset($removals[$esId])) { continue; }
                $final[] = $swaps[$esId] ?? (int)$r['offering_id'];
            }
            $final = array_merge($final, $additions);
            if (empty($final)) {
                throw new RuntimeException('This would leave the student with no classes. Keep at least one.');
            }
            if (count($final) !== count(array_unique($final))) {
                throw new RuntimeException('The same class would be on the roster twice.');
            }
            if ($final) {
                $in = implode(',', array_fill(0, count($final), '?'));
                $times = $pdo->prepare("SELECT co.offering_id, co.day_of_week, co.start_time, co.end_time, sub.subject_code FROM Class_Offering co JOIN Subject sub ON sub.subject_id = co.subject_id WHERE co.offering_id IN ($in)");
                $times->execute($final);
                $slots = $times->fetchAll();
                for ($i = 0; $i < count($slots); $i++) {
                    for ($j = $i + 1; $j < count($slots); $j++) {
                        $a = $slots[$i]; $b = $slots[$j];
                        if ($a['day_of_week'] && $a['day_of_week'] === $b['day_of_week'] && $a['start_time'] && $b['start_time']
                            && $a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time']) {
                            throw new RuntimeException($a['subject_code'] . ' and ' . $b['subject_code'] . ' would overlap on ' . $a['day_of_week'] . '. Nothing was saved.');
                        }
                    }
                }
            }

            $upd = $pdo->prepare('UPDATE Enrolled_subject SET offering_id = :o WHERE enrolled_subject_id = :id AND enrollment_id = :e');
            foreach ($swaps as $esId => $newOid) { $upd->execute(['o' => $newOid, 'id' => $esId, 'e' => $eid]); }
            $del = $pdo->prepare('DELETE FROM Enrolled_subject WHERE enrolled_subject_id = :id AND enrollment_id = :e');
            foreach (array_keys($removals) as $esId) { $del->execute(['id' => $esId, 'e' => $eid]); }
            $ins = $pdo->prepare('INSERT INTO Enrolled_subject (enrollment_id, offering_id) VALUES (:e, :o)');
            foreach ($additions as $oid) { $ins->execute(['e' => $eid, 'o' => $oid]); }

            $pdo->commit();
            flashSet('roster_msg', 'Classes updated for ' . $enr['first_name'] . ' ' . $enr['last_name'] . ': '
                . count($swaps) . ' changed, ' . count($removals) . ' removed, ' . count($additions) . ' added.');
            header('Location: ' . $self);
            exit;
        }

        $pdo->rollBack();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e instanceof RuntimeException ? $e->getMessage() : errorMessage($e, 'Could not save the change.');
    }
}

$students = [];
$occupancy = 0;
if ($termId) {
    $stmt = $pdo->prepare(
        "SELECT e.enrollment_id, e.student_standing, s.student_id_number, s.first_name, s.last_name, s.overall_status,
                (SELECT COUNT(*) FROM Enrolled_subject es WHERE es.enrollment_id = e.enrollment_id) AS subject_count,
                (SELECT COUNT(*) FROM Enrolled_subject es WHERE es.enrollment_id = e.enrollment_id
                    AND (es.grade IS NOT NULL OR es.remarks IS NOT NULL)) AS graded_count
         FROM Enrollment e JOIN Student s ON s.student_id = e.student_id
         WHERE e.term_id = :tid AND e.section_id = :sec AND e.status = 'approved'
         ORDER BY s.last_name, s.first_name"
    );
    $stmt->execute(['tid' => $termId, 'sec' => $sectionId]);
    $students = $stmt->fetchAll();
    $occupancy = sectionOccupancy($pdo, $sectionId);
}

$holders = [];
if ($termId) {
    $rosterNumbers = array_column($students, 'student_id_number');
    $stmt = $pdo->prepare(
        "SELECT s.student_id_number, s.first_name, s.last_name, e.status, st.school_year, st.semester
         FROM Student s
         JOIN Enrollment e ON e.enrollment_id = (
             SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id ORDER BY e2.enrollment_id DESC LIMIT 1
         )
         JOIN School_term st ON st.term_id = e.term_id
         WHERE e.section_id = :sid AND s.overall_status = 'active'
         ORDER BY s.last_name, s.first_name"
    );
    $stmt->execute(['sid' => $sectionId]);
    foreach ($stmt->fetchAll() as $h) {
        if (!in_array($h['student_id_number'], $rosterNumbers, true)) { $holders[] = $h; }
    }
}

$targets = [];
if ($termId) {
    $stmt = $pdo->prepare(
        'SELECT sec.section_id, sec.section_name, sec.year_level, sec.max_slots FROM Section sec
         WHERE sec.program_id = :pid AND sec.year_level = :yl AND sec.section_id != :cur
         ORDER BY sec.section_name'
    );
    $stmt->execute(['pid' => $section['program_id'], 'yl' => $section['year_level'], 'cur' => $sectionId]);
    foreach ($stmt->fetchAll() as $t) {
        $t['occupancy'] = sectionOccupancy($pdo, (int)$t['section_id']);
        $targets[] = $t;
    }
}

// "Change classes" view for one irregular student.
$editing = null;
$editRows = [];
$editOptions = [];
$editId = (int)($_GET['enrollment'] ?? ($_POST['enrollment_id'] ?? 0));
if ($termId && $editId) {
    foreach ($students as $st) {
        if ((int)$st['enrollment_id'] === $editId && $st['student_standing'] === 'irregular') { $editing = $st; }
    }
}
$pickerRows = [];
if ($editing) {
    $stmt = $pdo->prepare('SELECT student_id, curriculum_id FROM Enrollment WHERE enrollment_id = :e');
    $stmt->execute(['e' => $editId]);
    $editEnr = $stmt->fetch();
    $pickerRows = buildPickerRows($pdo, (int)$editEnr['student_id'], (int)$editEnr['curriculum_id'], $termId, null, $editId);

    $stmt = $pdo->prepare(
        'SELECT es.enrolled_subject_id, es.offering_id, es.grade, es.remarks, co.subject_id, sub.subject_code, sub.subject_name
         FROM Enrolled_subject es JOIN Class_Offering co ON co.offering_id = es.offering_id
         JOIN Subject sub ON sub.subject_id = co.subject_id
         WHERE es.enrollment_id = :e ORDER BY sub.subject_code, co.start_time'
    );
    $stmt->execute(['e' => $editId]);
    $editRows = $stmt->fetchAll();
    $subjectIds = array_values(array_unique(array_map('intval', array_column($editRows, 'subject_id'))));
    if ($subjectIds) {
        $in = implode(',', array_fill(0, count($subjectIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT co.offering_id, co.subject_id, co.day_of_week, co.start_time, co.end_time, co.room,
                    sec.section_name, sec.year_level, p.program_code, t.last_name
             FROM Class_Offering co JOIN Section sec ON sec.section_id = co.section_id
             JOIN Program p ON p.program_id = sec.program_id JOIN Teacher t ON t.teacher_id = co.teacher_id
             WHERE co.term_id = ? AND co.subject_id IN ($in)
             ORDER BY sec.year_level, sec.section_name, co.start_time"
        );
        $stmt->execute(array_merge([$termId], $subjectIds));
        foreach ($stmt->fetchAll() as $o) { $editOptions[(int)$o['subject_id']][] = $o; }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Section Roster</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container container-xl">
    <a href="<?= BASE_URL ?>/registrar/sections.php" class="btn btn-sm btn-outline-secondary">&larr; Back to Sections</a>
    <h1 class="h4 mt-3 mb-1">Roster: <?= htmlspecialchars(sectionLabel($section['year_level'], $section['section_name'], $section['program_code'])) ?></h1>
    <?php if ($term): ?>
        <p class="text-muted">
            <?= htmlspecialchars($term['school_year'] . ' — Semester ' . $term['semester']) ?>
            · <?= $occupancy ?> of <?= (int)$section['max_slots'] ?> seats held · <?= count($students) ?> enrolled this term
        </p>
    <?php endif; ?>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="8000"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($warning): ?><div class="alert alert-warning"><?= htmlspecialchars($warning) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (!$term): ?>
        <div class="alert alert-secondary">No term is open. Rosters can only be changed while a term is ongoing.</div>
    <?php else: ?>

    <?php if ($editing): ?>
        <form method="post" class="card mb-4">
            <div class="card-header">Change classes: <?= htmlspecialchars($editing['first_name'] . ' ' . $editing['last_name']) ?> (<?= htmlspecialchars($editing['student_id_number']) ?>)</div>
            <div class="card-body">
                <input type="hidden" name="action" value="change_classes">
                <input type="hidden" name="section_id" value="<?= $sectionId ?>">
                <input type="hidden" name="enrollment_id" value="<?= $editId ?>">
                <p class="text-muted small">Swap a class for the same subject, remove a subject, or add new ones below. Any section is allowed, but two classes cannot overlap. Subjects that already have a grade are locked. Everything saves together or not at all.</p>
                <div class="table-responsive">
                <table class="table align-middle mb-3">
                    <thead><tr><th>Subject</th><th>Class</th><th>Remove</th></tr></thead>
                    <tbody>
                    <?php foreach ($editRows as $r): ?>
                        <?php $locked = $r['grade'] !== null || $r['remarks'] !== null; ?>
                        <tr>
                            <td><?= htmlspecialchars($r['subject_code'] . ' — ' . $r['subject_name']) ?></td>
                            <td>
                                <select class="form-select form-select-sm" name="swap[<?= $r['enrolled_subject_id'] ?>]" <?= $locked ? 'disabled' : '' ?>>
                                    <?php foreach ($editOptions[(int)$r['subject_id']] ?? [] as $o): ?>
                                        <option value="<?= $o['offering_id'] ?>" <?= (int)$o['offering_id'] === (int)$r['offering_id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars(sectionLabel($o['year_level'], $o['section_name'], $o['program_code']) . ' — ' . formatSchedule($o['day_of_week'], $o['start_time'], $o['end_time'], $o['room'] ?? '') . ' — ' . $o['last_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($locked): ?><div class="form-text">Graded, locked.</div><?php endif; ?>
                            </td>
                            <td><input type="checkbox" class="form-check-input" name="remove[<?= $r['enrolled_subject_id'] ?>]" value="1" <?= $locked ? 'disabled' : '' ?>></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($editRows)): ?><tr><td colspan="3" class="text-muted">This student has no subjects yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
                </div>
                <h2 class="h6 mt-4">Add subjects</h2>
                <?php require __DIR__ . '/../../includes/subject_picker.php'; ?>
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <a href="<?= htmlspecialchars($self) ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    <?php endif; ?>

    <p class="text-muted small">Students whose enrollment this term is in this section. Regular students move as a whole section. Irregular students keep their home section, and you change individual classes instead.</p>
    <div class="table-responsive">
    <table class="table table-hover bg-white">
        <thead><tr><th>ID Number</th><th>Name</th><th>Standing</th><th>Subjects</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($students as $st): ?>
            <?php $graded = (int)$st['graded_count'] > 0; $active = $st['overall_status'] === 'active'; ?>
            <tr>
                <td><?= htmlspecialchars($st['student_id_number']) ?></td>
                <td><?= htmlspecialchars($st['last_name'] . ', ' . $st['first_name']) ?></td>
                <td><?= statusBadge($st['student_standing']) ?></td>
                <td><?= (int)$st['subject_count'] ?><?= $graded ? ' <span class="badge text-bg-secondary">graded</span>' : '' ?></td>
                <td>
                    <?php if ($active && $st['student_standing'] === 'regular'): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" <?= $graded || !$targets ? 'disabled' : '' ?>
                                title="<?= $graded ? 'Grades are already entered' : (!$targets ? 'No other section for this program and year' : 'Move to another section') ?>"
                                onclick="openMove(<?= (int)$st['enrollment_id'] ?>, <?= htmlspecialchars(json_encode($st['first_name'] . ' ' . $st['last_name']), ENT_QUOTES) ?>)">Move section</button>
                    <?php elseif ($active): ?>
                        <a href="<?= htmlspecialchars($self . '&enrollment=' . (int)$st['enrollment_id']) ?>" class="btn btn-sm btn-outline-primary">Change classes</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?><tr><td colspan="5" class="text-muted">No students on this roster this term.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <?php if ($holders): ?>
        <div class="card mb-4 border-secondary-subtle">
            <div class="card-header text-muted small">Holding a seat in this section, not enrolled this term</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($holders as $h): ?>
                    <li class="list-group-item text-muted d-flex justify-content-between">
                        <span><?= htmlspecialchars($h['last_name'] . ', ' . $h['first_name']) ?> (<?= htmlspecialchars($h['student_id_number']) ?>)</span>
                        <span class="small">Latest enrollment: <?= htmlspecialchars($h['school_year'] . ' S' . $h['semester']) ?> (<?= htmlspecialchars($h['status']) ?>)</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<div class="modal fade" id="moveModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="move_section">
      <input type="hidden" name="section_id" value="<?= $sectionId ?>">
      <input type="hidden" name="enrollment_id" id="moveEnrollment">
      <div class="modal-header">
        <h5 class="modal-title">Move to another section</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p id="moveName" class="fw-bold"></p>
        <label class="form-label">New section</label>
        <select class="form-select" name="target_section_id" id="moveTarget" required>
            <option value="">Select</option>
            <?php foreach ($targets as $t): ?>
                <?php $full = $t['occupancy'] >= (int)$t['max_slots']; ?>
                <option value="<?= $t['section_id'] ?>" data-full="<?= $full ? '1' : '0' ?>"><?= htmlspecialchars(sectionLabel($t['year_level'], $t['section_name'])) ?> (<?= (int)$t['occupancy'] ?>/<?= (int)$t['max_slots'] ?><?= $full ? ', full' : '' ?>)</option>
            <?php endforeach; ?>
        </select>
        <div id="moveFullWarning" class="alert alert-warning mt-3 mb-0" hidden>That section is full. You can still move the student, but it will go over its limit.</div>
        <p class="form-text mt-3 mb-0">Their subjects switch to the same subjects in the new section. Subjects with no class there stay as they are and are listed afterward.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Move</button>
      </div>
    </form>
  </div>
</div>

<script>
function openMove(enrollmentId, name) {
    document.getElementById('moveEnrollment').value = enrollmentId;
    document.getElementById('moveName').textContent = name;
    document.getElementById('moveTarget').value = '';
    document.getElementById('moveFullWarning').hidden = true;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('moveModal')).show();
}
document.getElementById('moveTarget').addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    document.getElementById('moveFullWarning').hidden = !(opt && opt.dataset.full === '1');
});
</script>
</body>
</html>
