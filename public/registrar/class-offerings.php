<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$error = '';
$message = '';

// Subjects are shared across every department (e.g. GE1 is taken by multiple programs) — not scoped.
$subjects = $pdo->query('SELECT subject_id, subject_code, subject_name FROM Subject ORDER BY subject_id DESC')->fetchAll();

$teachers = $pdo->prepare('SELECT teacher_id, last_name, first_name FROM Teacher WHERE department_id = :dept ORDER BY teacher_id DESC');
$teachers->execute(['dept' => $myDepartmentId]);
$teachers = $teachers->fetchAll();
$myTeacherIds = array_column($teachers, 'teacher_id');

$sections = $pdo->prepare(
    'SELECT sec.section_id, sec.section_name, sec.year_level, p.program_code
     FROM Section sec JOIN Program p ON p.program_id = sec.program_id
     WHERE p.department_id = :dept
     ORDER BY p.program_code, sec.year_level, sec.section_name'
);
$sections->execute(['dept' => $myDepartmentId]);
$sections = $sections->fetchAll();
$multiProgram = count(array_unique(array_column($sections, 'program_code'))) > 1;
$mySectionIds = array_column($sections, 'section_id');

$terms = $pdo->query("SELECT term_id, school_year, semester FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subjectId = $_POST['subject_id'] ?? '';
    $teacherId = $_POST['teacher_id'] ?? '';
    $sectionId = $_POST['section_id'] ?? '';
    $termId = $_POST['term_id'] ?? '';
    $room = trim($_POST['room'] ?? '');
    $day = $_POST['day_of_week'] ?? '';
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $offeringId = (int)($_POST['offering_id'] ?? 0);
    $editLocked = false;
    $editEnrolled = 0;
    $existing = false;

    if ($offeringId) {
        $ex = $pdo->prepare(
            'SELECT co.*, st.status AS term_status, p.department_id
             FROM Class_Offering co JOIN Section sec ON sec.section_id = co.section_id
             JOIN Program p ON p.program_id = sec.program_id JOIN School_term st ON st.term_id = co.term_id
             WHERE co.offering_id = :id'
        );
        $ex->execute(['id' => $offeringId]);
        $existing = $ex->fetch();
        if ($existing && (int)$existing['department_id'] === (int)$myDepartmentId && $existing['term_status'] === 'ongoing') {
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM Enrolled_subject WHERE offering_id = :id');
            $cnt->execute(['id' => $offeringId]);
            $editEnrolled = (int)$cnt->fetchColumn();
            if ($editEnrolled > 0) {
                $editLocked = true;
                $subjectId = $_POST['subject_id'] = (string)$existing['subject_id'];
                $sectionId = $_POST['section_id'] = (string)$existing['section_id'];
                $termId = $_POST['term_id'] = (string)$existing['term_id'];
            }
        }
    }

    if ($offeringId && (!$existing || (int)$existing['department_id'] !== (int)$myDepartmentId || $existing['term_status'] !== 'ongoing')) {
        $error = 'That class cannot be edited. It is not in your department, or its term is closed.';
    } elseif ($subjectId === '' || $teacherId === '' || $sectionId === '' || $termId === '' || $day === '' || $startTime === '' || $endTime === '') {
        $error = 'All fields except room are required.';
    } elseif ($startTime >= $endTime) {
        $error = 'Start time must be before end time.';
    } elseif (!in_array((int)$teacherId, $myTeacherIds, true) || !in_array((int)$sectionId, $mySectionIds, true)) {
        // Defense in depth — the dropdowns only list your department's own teachers/sections.
        $error = 'That teacher or section is not in your department.';
    } elseif (!in_array((int)$termId, array_map('intval', array_column($terms, 'term_id')), true)) {
        $error = 'That term is not open.';
    } else {
        // Conflict check: same teacher OR same section, same term/day, overlapping time.
        $stmt = $pdo->prepare(
            "SELECT co.*, s.subject_code, t.last_name, sec.section_name
             FROM Class_Offering co
             JOIN Subject s ON s.subject_id = co.subject_id
             JOIN Teacher t ON t.teacher_id = co.teacher_id
             JOIN Section sec ON sec.section_id = co.section_id
             WHERE co.term_id = :term_id AND co.day_of_week = :day
               AND (co.teacher_id = :teacher_id OR co.section_id = :section_id)
               AND co.offering_id <> :self
               AND co.start_time < :end_time AND co.end_time > :start_time"
        );
        $stmt->execute([
            'term_id' => $termId, 'day' => $day, 'teacher_id' => $teacherId, 'section_id' => $sectionId,
            'end_time' => $endTime, 'start_time' => $startTime, 'self' => $offeringId,
        ]);
        $conflict = $stmt->fetch();
        $roomConflict = false;

        // Room conflict is a separate axis — deliberately NOT scoped to :dept, since a
        // physical room can be double-booked by a registrar in a different department.
        if (!$conflict && $room !== '') {
            $stmt = $pdo->prepare(
                "SELECT co.*, s.subject_code, t.last_name, sec.section_name
                 FROM Class_Offering co
                 JOIN Subject s ON s.subject_id = co.subject_id
                 JOIN Teacher t ON t.teacher_id = co.teacher_id
                 JOIN Section sec ON sec.section_id = co.section_id
                 WHERE co.term_id = :term_id AND co.day_of_week = :day
                   AND LOWER(co.room) = LOWER(:room)
                   AND co.offering_id <> :self
                   AND co.start_time < :end_time AND co.end_time > :start_time"
            );
            $stmt->execute([
                'term_id' => $termId, 'day' => $day, 'room' => $room,
                'end_time' => $endTime, 'start_time' => $startTime, 'self' => $offeringId,
            ]);
            $conflict = $stmt->fetch();
            $roomConflict = (bool)$conflict;
        }

        $conflictWhen = $conflict ? formatSchedule($conflict['day_of_week'], $conflict['start_time'], $conflict['end_time']) : '';
        if ($conflict && $roomConflict) {
            $error = "Room conflict: \"{$conflict['room']}\" is already booked for {$conflict['subject_code']} "
                . "{$conflictWhen} "
                . "with teacher {$conflict['last_name']} / section {$conflict['section_name']} in this term.";
        } elseif ($conflict) {
            $error = "Schedule conflict: {$conflict['subject_code']} already runs "
                . "{$conflictWhen} "
                . "with teacher {$conflict['last_name']} / section {$conflict['section_name']} in this term.";
        } elseif ($offeringId) {
            $clash = $pdo->prepare(
                'SELECT sub.subject_code, st.last_name, COUNT(*) OVER () AS total
                 FROM Enrolled_subject es
                 JOIN Enrolled_subject es2 ON es2.enrollment_id = es.enrollment_id AND es2.offering_id <> es.offering_id
                 JOIN Class_Offering co2 ON co2.offering_id = es2.offering_id
                 JOIN Subject sub ON sub.subject_id = co2.subject_id
                 JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
                 JOIN Student st ON st.student_id = e.student_id
                 WHERE es.offering_id = :self AND co2.day_of_week = :day
                   AND co2.start_time < :end_time AND co2.end_time > :start_time
                 LIMIT 1'
            );
            $clash->execute(['self' => $offeringId, 'day' => $day, 'end_time' => $endTime, 'start_time' => $startTime]);
            $hit = $clash->fetch();
            if ($hit) {
                $error = 'That time overlaps ' . $hit['subject_code'] . ' for ' . $hit['last_name']
                    . ((int)$hit['total'] > 1 ? ' and ' . ((int)$hit['total'] - 1) . ' other enrolled student(s)' : '')
                    . '. Pick a time that fits their schedules.';
            } else {
                $pdo->prepare(
                    'UPDATE Class_Offering SET subject_id = :subject_id, teacher_id = :teacher_id, section_id = :section_id,
                        term_id = :term_id, room = :room, day_of_week = :day, start_time = :start_time, end_time = :end_time
                     WHERE offering_id = :self'
                )->execute([
                    'subject_id' => $subjectId, 'teacher_id' => $teacherId, 'section_id' => $sectionId, 'term_id' => $termId,
                    'room' => $room ?: null, 'day' => $day, 'start_time' => $startTime, 'end_time' => $endTime, 'self' => $offeringId,
                ]);
                $message = 'Class offering updated.';
            }
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO Class_Offering (subject_id, teacher_id, section_id, term_id, room, day_of_week, start_time, end_time)
                 VALUES (:subject_id, :teacher_id, :section_id, :term_id, :room, :day, :start_time, :end_time)'
            );
            $stmt->execute([
                'subject_id' => $subjectId, 'teacher_id' => $teacherId, 'section_id' => $sectionId, 'term_id' => $termId,
                'room' => $room ?: null, 'day' => $day, 'start_time' => $startTime, 'end_time' => $endTime,
            ]);
            $message = 'Class offering scheduled.';
        }
    }
}

$offerings = $pdo->prepare(
    "SELECT co.*, s.subject_code, s.subject_name, t.last_name, t.first_name, sec.section_name, sec.year_level, p.program_code, st.school_year, st.semester,
            st.status AS term_status, (SELECT COUNT(*) FROM Enrolled_subject es WHERE es.offering_id = co.offering_id) AS enrolled_count
     FROM Class_Offering co
     JOIN Subject s ON s.subject_id = co.subject_id
     JOIN Teacher t ON t.teacher_id = co.teacher_id
     JOIN Section sec ON sec.section_id = co.section_id
     JOIN Program p ON p.program_id = sec.program_id
     JOIN School_term st ON st.term_id = co.term_id
     WHERE p.department_id = :dept
     ORDER BY st.term_id DESC,
              FIELD(co.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
              co.start_time"
);
$offerings->execute(['dept' => $myDepartmentId]);
$offerings = $offerings->fetchAll();

// Filter options come from the offerings themselves, and a filter only shows when it has
// more than one choice. The term filter opens on the most recent ongoing term because
// that is the one being scheduled; "All terms" is one click away.
$termOptions = [];
$sectionOptions = [];
foreach ($offerings as $o) {
    $termOptions[(int) $o['term_id']] = $o['school_year'] . ' S' . $o['semester'];
    $sectionOptions[(int) $o['section_id']] = sectionLabel($o['year_level'], $o['section_name'], $multiProgram ? $o['program_code'] : null);
}
asort($sectionOptions);
$showTermFilter = count($termOptions) > 1;
$showSectionFilter = count($sectionOptions) > 1;

$termParam = is_string($_GET['term'] ?? null) ? $_GET['term'] : null;
$filterTerm = 0; // 0 = all terms
if ($termParam === 'all') {
    $filterTerm = 0;
} elseif ($termParam !== null && ctype_digit($termParam) && isset($termOptions[(int) $termParam])) {
    $filterTerm = (int) $termParam;
} elseif ($showTermFilter) {
    foreach (array_keys($termOptions) as $tid) {
        if (in_array($tid, array_map('intval', array_column($terms, 'term_id')), true)) { $filterTerm = $tid; break; }
    }
}
$filterSection = 0;
if (is_string($_GET['section'] ?? null) && ctype_digit($_GET['section']) && isset($sectionOptions[(int) $_GET['section']])) {
    $filterSection = (int) $_GET['section'];
}
$visibleOfferings = array_values(array_filter($offerings, fn($o) =>
    ($filterTerm === 0 || (int) $o['term_id'] === $filterTerm)
    && ($filterSection === 0 || (int) $o['section_id'] === $filterSection)
));
// Columns that would repeat the same value on every row are left out.
$showTermCol = $showTermFilter && $filterTerm === 0;
$showSectionCol = $filterSection === 0;
$colCount = 6 + ($showTermCol ? 1 : 0) + ($showSectionCol ? 1 : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Class Offerings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Class Offerings</h1>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="4000"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php $reopenForm = $error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST'; ?>
    <?php if ($error && !$reopenForm): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (empty($terms)): ?>
        <div class="alert alert-warning">No ongoing term. Open one in <a href="<?= BASE_URL ?>/registrar/terms.php">School Terms</a> first.</div>
    <?php else: ?>
    <div class="mb-3">
        <button type="button" class="btn btn-primary" onclick="openOfferingModal({})"><i class="bi bi-plus-lg"></i> Schedule a Class</button>
    </div>
    <div class="modal fade" id="offeringModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content" id="offeringForm">
          <input type="hidden" name="offering_id" id="offeringId">
          <div class="modal-header">
            <h5 class="modal-title" id="offeringModalTitle">Schedule a Class</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?php if ($reopenForm): ?><div class="alert alert-danger js-modal-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <div class="alert alert-info" id="offeringLockNote" hidden>Subject, section and term are locked because <span id="offeringLockCount">0</span> student(s) are enrolled. Teacher, room, day and time can still change.</div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Term</label>
                    <select class="form-select" name="term_id" required>
                        <?php foreach ($terms as $t): ?>
                            <option value="<?= $t['term_id'] ?>"><?= htmlspecialchars($t['school_year'] . ' — Sem ' . $t['semester']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8 mb-3">
                    <label class="form-label">Subject</label>
                    <select class="form-select js-search" name="subject_id" data-placeholder="Search subjects">
                        <option value="">Select</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= $s['subject_id'] ?>"><?= htmlspecialchars($s['subject_code'] . ' — ' . $s['subject_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Teacher</label>
                    <select class="form-select js-search" name="teacher_id" data-placeholder="Search teachers">
                        <option value="">Select</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['teacher_id'] ?>"><?= htmlspecialchars($t['last_name'] . ', ' . $t['first_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Section</label>
                    <select class="form-select js-search" name="section_id" data-placeholder="Search sections">
                        <option value="">Select</option>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?= $s['section_id'] ?>"><?= htmlspecialchars(sectionLabel($s['year_level'], $s['section_name'], $multiProgram ? $s['program_code'] : null)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Day</label>
                    <select class="form-select" name="day_of_week" required>
                        <option value="">Select</option>
                        <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d): ?>
                            <option value="<?= $d ?>"><?= $d ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Start</label>
                    <input type="time" class="form-control" name="start_time" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">End</label>
                    <input type="time" class="form-control" name="end_time" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Room</label>
                    <input class="form-control" name="room" placeholder="Room 101">
                </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" id="offeringSubmit">Schedule Class</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($showTermFilter || $showSectionFilter): ?>
    <form method="get" class="toolbar">
        <?php if ($showTermFilter): ?>
        <div class="toolbar-field">
            <label for="term">Term</label>
            <select name="term" id="term" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $filterTerm === 0 ? 'selected' : '' ?>>All terms</option>
                <?php foreach ($termOptions as $tid => $tlabel): ?>
                    <option value="<?= $tid ?>" <?= $filterTerm === $tid ? 'selected' : '' ?>><?= htmlspecialchars($tlabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($showSectionFilter): ?>
        <div class="toolbar-field">
            <label for="section">Section</label>
            <select name="section" id="section" class="form-select" onchange="this.form.submit()">
                <option value="">All sections</option>
                <?php foreach ($sectionOptions as $sid => $slabel): ?>
                    <option value="<?= $sid ?>" <?= $filterSection === $sid ? 'selected' : '' ?>><?= htmlspecialchars($slabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <noscript><button type="submit" class="btn btn-primary">Apply</button></noscript>
        <?php if ($termParam !== null || $filterSection !== 0): ?><a href="class-offerings.php" class="btn btn-outline-secondary">Reset</a><?php endif; ?>
        <span class="toolbar-count">Showing <?= count($visibleOfferings) ?> class<?= count($visibleOfferings) === 1 ? '' : 'es' ?></span>
    </form>
    <?php endif; ?>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><?php if ($showTermCol): ?><th>Term</th><?php endif; ?><th>Subject</th><th>Teacher</th><?php if ($showSectionCol): ?><th>Section</th><?php endif; ?><th>Day</th><th>Time</th><th>Room</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($visibleOfferings as $o): ?>
            <tr>
                <?php if ($showTermCol): ?><td><?= htmlspecialchars($o['school_year'] . ' S' . $o['semester']) ?></td><?php endif; ?>
                <td><?= htmlspecialchars($o['subject_code']) ?></td>
                <td><?= htmlspecialchars($o['last_name'] . ', ' . $o['first_name']) ?></td>
                <?php if ($showSectionCol): ?><td><?= htmlspecialchars(sectionLabel($o['year_level'], $o['section_name'], $multiProgram ? $o['program_code'] : null)) ?></td><?php endif; ?>
                <td><?= htmlspecialchars($o['day_of_week']) ?></td>
                <td><?= htmlspecialchars(formatTimeRange($o['start_time'], $o['end_time'])) ?></td>
                <td><?= htmlspecialchars($o['room'] ?? '') ?></td>
                <td>
                    <?php if ($o['term_status'] === 'ongoing'): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-offering="<?= htmlspecialchars(json_encode([
                                    'offering_id' => (int)$o['offering_id'], 'term_id' => (string)$o['term_id'], 'subject_id' => (string)$o['subject_id'],
                                    'teacher_id' => (string)$o['teacher_id'], 'section_id' => (string)$o['section_id'], 'day_of_week' => $o['day_of_week'],
                                    'start_time' => substr((string)$o['start_time'], 0, 5), 'end_time' => substr((string)$o['end_time'], 0, 5),
                                    'room' => $o['room'] ?? '', 'locked' => (int)$o['enrolled_count'] > 0, 'enrolled' => (int)$o['enrolled_count'],
                                ]), ENT_QUOTES) ?>"
                                onclick="openOfferingModal(JSON.parse(this.dataset.offering))">Edit</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($visibleOfferings)): ?>
            <tr><td colspan="<?= $colCount ?>" class="text-muted">
                <?php if (empty($offerings)): ?>No class offerings yet.
                <?php elseif ($filterSection === 0 && $filterTerm !== 0): ?>No classes scheduled for this term yet.
                <?php else: ?>No classes match this filter.<?php endif; ?>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</div>
<script>
function fillModalForm(form, values) {
    Object.keys(values).forEach(function (name) {
        var el = form.elements[name];
        if (!el || el.type === 'hidden') { return; }
        if (el.tomselect) { el.tomselect.setValue(values[name]); } else { el.value = values[name]; }
    });
}
function openOfferingModal(values) {
    var form = document.getElementById('offeringForm');
    if (!form) { return; }
    var editing = !!values.offering_id, locked = !!values.locked;
    if (!editing && Object.keys(values).length === 0) {
        form.reset();
        form.querySelectorAll('select.js-search').forEach(function (el) { if (el.tomselect) { el.tomselect.clear(true); } });
    }
    document.getElementById('offeringId').value = values.offering_id || '';
    fillModalForm(form, values);
    ['term_id', 'subject_id', 'section_id'].forEach(function (n) {
        var el = form.elements[n];
        if (!el) { return; }
        if (el.tomselect) { if (locked) { el.tomselect.disable(); } else { el.tomselect.enable(); } } else { el.disabled = locked; }
    });
    document.getElementById('offeringLockNote').hidden = !locked;
    document.getElementById('offeringLockCount').textContent = values.enrolled || 0;
    document.getElementById('offeringModalTitle').textContent = editing ? 'Edit Class' : 'Schedule a Class';
    document.getElementById('offeringSubmit').textContent = editing ? 'Save Changes' : 'Schedule Class';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('offeringModal')).show();
}
<?php if ($reopenForm && !empty($terms)): ?>
document.addEventListener('DOMContentLoaded', function () {
    openOfferingModal(<?= json_encode(array_merge(array_intersect_key($_POST, array_flip(['offering_id', 'term_id', 'subject_id', 'teacher_id', 'section_id', 'day_of_week', 'start_time', 'end_time', 'room'])), ['locked' => $editLocked, 'enrolled' => $editEnrolled]), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
});
<?php endif; ?>
</script>
</body>
</html>