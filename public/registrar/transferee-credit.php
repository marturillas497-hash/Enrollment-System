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

$studentId = $_GET['student_id'] ?? ($_POST['student_id'] ?? null);
$error = '';
$message = flashGet('tc_msg') ?? '';
$view = ($_GET['view'] ?? '') === 'add' ? 'add' : 'credits';

// Scoped to MY department via the student's current program (latest enrollment's curriculum) —
// same pattern as students.php / irregular-enrollments.php.
$stmt = $pdo->prepare(
    'SELECT s.*, e.enrollment_id, e.term_id, e.section_id, e.curriculum_id, st.school_year, st.semester
     FROM Student s
     JOIN Enrollment e ON e.enrollment_id = (
         SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id
         ORDER BY e2.enrollment_id DESC LIMIT 1
     )
     JOIN School_term st ON st.term_id = e.term_id
     JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
     JOIN Program p ON p.program_id = c.program_id
     WHERE s.student_id = :id AND s.student_type = \'transferee\' AND p.department_id = :dept'
);
$stmt->execute(['id' => $studentId, 'dept' => $myDepartmentId]);
$student = $stmt->fetch();

if ($student === false) {
    require __DIR__ . '/../../includes/navbar.php';
    echo '<div class="container"><div class="alert alert-danger">'
        . 'Transferee student not found, not a transferee, or not in your department.</div></div>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_credit') {
        // Upsert: one credit per curriculum subject. subject_id here is the
        // curriculum row being credited — the registrar picks the ROW, not a
        // subject from a big dropdown, so there's no chance of crediting the
        // wrong subject by mistake.
        $subjectId = $_POST['subject_id'] ?? '';
        $creditId = $_POST['credit_id'] ?? '';
        $previousSchool = trim($_POST['previous_school'] ?? '');
        $previousSubject = trim($_POST['previous_subject_description'] ?? '');
        $previousGrade = trim($_POST['previous_grade'] ?? '');

        // Confirm subject_id is actually part of THIS student's curriculum — the
        // pencil icon only ever sends a rendered curriculum row, but the handler
        // shouldn't trust that on its own, never trust a raw ID.
        $curriculumSubjectCheck = $pdo->prepare(
            'SELECT 1 FROM Curriculum_subject WHERE curriculum_id = :cid AND subject_id = :sid'
        );
        $curriculumSubjectCheck->execute(['cid' => $student['curriculum_id'], 'sid' => $subjectId]);
        $subjectInCurriculum = $subjectId !== '' && $curriculumSubjectCheck->fetch() !== false;

        if ($previousSchool === '' || $previousGrade === '' || $subjectId === '') {
            $error = 'Previous school and grade are required.';
        } elseif (!$subjectInCurriculum) {
            $error = 'That subject is not part of this student\'s curriculum.';
        } elseif (!is_numeric($previousGrade) || $previousGrade < 1.00 || $previousGrade > 5.00) {
            $error = 'Previous grade must be between 1.00 and 5.00.';
        } elseif ($creditId === '' && hasEnrolledRecord($pdo, (int)$studentId, (int)$subjectId)) {
            $error = 'This student already has an enrollment record for that subject, so it cannot also be credited.';
        } elseif ($creditId !== '') {
            $pdo->prepare(
                'UPDATE Transferee_credit SET previous_school = :school, previous_subject_description = :desc,
                    previous_grade = :grade WHERE credit_id = :id AND student_id = :sid'
            )->execute([
                'school' => $previousSchool, 'desc' => $previousSubject ?: null,
                'grade' => number_format((float)$previousGrade, 2, '.', ''),
                'id' => $creditId, 'sid' => $studentId,
            ]);
            $message = 'Credit updated.';
        } else {
            $pdo->prepare(
                'INSERT INTO Transferee_credit (student_id, previous_school, previous_subject_description, previous_grade, credited_subject_id)
                 VALUES (:sid, :school, :desc, :grade, :subject_id)'
            )->execute([
                'sid' => $studentId, 'school' => $previousSchool,
                'desc' => $previousSubject ?: null,
                'grade' => number_format((float)$previousGrade, 2, '.', ''),
                'subject_id' => $subjectId,
            ]);
            $message = 'Credit added.';
        }
    } elseif ($action === 'delete_credit') {
        $creditId = $_POST['credit_id'] ?? '';
        $pdo->prepare('DELETE FROM Transferee_credit WHERE credit_id = :id AND student_id = :sid')
            ->execute(['id' => $creditId, 'sid' => $studentId]);
        $message = 'Credit removed.';
    } elseif ($action === 'add_subjects') {
        $pickRows = buildPickerRows($pdo, (int)$studentId, (int)$student['curriculum_id'], (int)$student['term_id'], null, (int)$student['enrollment_id']);
        $toInsert = pickerSelectedOfferings($pickRows, $_POST);
        if (empty($toInsert)) {
            $error = 'Tick at least one subject that is available to add.';
        } elseif (($clash = findScheduleConflict($pdo, array_merge(array_column(rosterSlots($pdo, (int)$student['enrollment_id']), 'offering_id'), $toInsert))) !== null) {
            $error = $clash;
        } else {
            try {
                $pdo->beginTransaction();
                $insert = $pdo->prepare('INSERT INTO Enrolled_subject (enrollment_id, offering_id) VALUES (:eid, :oid)');
                foreach ($toInsert as $oid) {
                    $insert->execute(['eid' => $student['enrollment_id'], 'oid' => $oid]);
                }
                $pdo->commit();
                flashSet('tc_msg', count($toInsert) . ' subject' . (count($toInsert) === 1 ? '' : 's') . ' added to the roster.');
                header('Location: ' . BASE_URL . '/registrar/transferee-credit.php?student_id=' . (int)$studentId . '&view=add');
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = errorMessage($e, 'Could not add the subjects.');
            }
        }
        $view = 'add';
    }
}

// Curriculum + program header info.
$stmt = $pdo->prepare(
    'SELECT c.curriculum_name, p.program_code, p.program_name
     FROM Curriculum c JOIN Program p ON p.program_id = c.program_id
     WHERE c.curriculum_id = :cid'
);
$stmt->execute(['cid' => $student['curriculum_id']]);
$curriculumInfo = $stmt->fetch();

// Every subject in the curriculum, grouped by year/semester, each carrying its
// existing credit (if any) via LEFT JOIN — this is the heart of the new view.
$stmt = $pdo->prepare(
    'SELECT cs.year_level, cs.semester, s.subject_id, s.subject_code, s.subject_name, s.units,
            tc.credit_id, tc.previous_school, tc.previous_subject_description, tc.previous_grade
     FROM Curriculum_subject cs
     JOIN Subject s ON s.subject_id = cs.subject_id
     LEFT JOIN Transferee_credit tc ON tc.credited_subject_id = cs.subject_id AND tc.student_id = :sid
     WHERE cs.curriculum_id = :cid
     ORDER BY cs.year_level, cs.semester, s.subject_code'
);
$stmt->execute(['sid' => $studentId, 'cid' => $student['curriculum_id']]);
$curriculumRows = $stmt->fetchAll();

$grouped = [];
foreach ($curriculumRows as $r) {
    $grouped[$r['year_level']][$r['semester']][] = $r;
}

// Progress, and one tab per year level (tabs only show when there is more than one year).
// The page opens on the first year that still has an uncredited subject, since that is
// where the registrar has work left to do.
$subjectTotal = count($curriculumRows);
$creditedTotal = 0;
$uncreditedByYear = [];
foreach ($curriculumRows as $r) {
    $yl = (int) $r['year_level'];
    $uncreditedByYear[$yl] = $uncreditedByYear[$yl] ?? 0;
    if ($r['credit_id'] !== null) { $creditedTotal++; } else { $uncreditedByYear[$yl]++; }
}
$yearKeys = array_keys($uncreditedByYear);
sort($yearKeys);
$selectedYear = $yearKeys[0] ?? null;
foreach ($yearKeys as $yk) {
    if ($uncreditedByYear[$yk] > 0) { $selectedYear = $yk; break; }
}
if (is_string($_GET['year'] ?? null) && ctype_digit($_GET['year']) && in_array((int) $_GET['year'], $yearKeys, true)) {
    $selectedYear = (int) $_GET['year'];
}

// Real, already-enrolled subjects (separate from credits) — kept for context.
$currentSubjects = $pdo->prepare(
    'SELECT sub.subject_code, sub.subject_name
     FROM Enrolled_subject es
     JOIN Class_Offering co ON co.offering_id = es.offering_id
     JOIN Subject sub ON sub.subject_id = co.subject_id
     WHERE es.enrollment_id = :eid'
);
$currentSubjects->execute(['eid' => $student['enrollment_id']]);
$currentSubjects = $currentSubjects->fetchAll();

$pickerRows = $view === 'add'
    ? buildPickerRows($pdo, (int)$studentId, (int)$student['curriculum_id'], (int)$student['term_id'], null, (int)$student['enrollment_id'])
    : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transferee Credit Evaluation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container container-xl">
    <h1 class="h4 mb-1">
        <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
        (<?= htmlspecialchars($student['student_id_number']) ?>)
    </h1>
    <p class="text-muted mb-3">
        Transferee — <?= htmlspecialchars($student['school_year'] . ' — Semester ' . $student['semester']) ?>
    </p>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="4000"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 mb-0"><?= htmlspecialchars($curriculumInfo['curriculum_name']) ?></h2>
            <p class="text-muted mb-0"><?= htmlspecialchars($curriculumInfo['program_code'] . ' — ' . $curriculumInfo['program_name']) ?></p>
            <div class="mt-3"><?= progressMeter($creditedTotal, $subjectTotal, 'subjects credited') ?></div>
        </div>
    </div>

    <?= tabBar([
        'credits' => ['label' => 'Credits', 'href' => '?student_id=' . (int)$studentId . '&view=credits'],
        'add' => ['label' => 'Add Subjects', 'href' => '?student_id=' . (int)$studentId . '&view=add'],
    ], $view) ?>

    <?php if ($view === 'add'): ?>
        <p class="text-muted">
            Tick the subjects this student still needs to take, and pick the class for each. Classes from any section are offered. Credited subjects and subjects already on the roster
            are not listed. Subjects with an unmet prerequisite (checked against MIST grades, shift credit and transferee credit)
            are greyed out and re-checked when you save.
        </p>
        <form method="post">
            <input type="hidden" name="action" value="add_subjects">
            <input type="hidden" name="student_id" value="<?= (int)$studentId ?>">
            <?php $pickerExisting = rosterSlots($pdo, (int)$student['enrollment_id']); require __DIR__ . '/../../includes/subject_picker.php'; ?>
            <?php if (!empty($pickerRows)): ?><button type="submit" class="btn btn-primary">Add Selected Subjects</button><?php endif; ?>
        </form>
    <?php else: ?>

    <?php if (count($yearKeys) > 1): ?>
        <?php
        $yearTabs = [];
        foreach ($yearKeys as $yk) {
            $yearTabs[$yk] = [
                'label' => 'Year ' . $yk,
                'count' => $uncreditedByYear[$yk],
                'href'  => '?student_id=' . (int) $studentId . '&year=' . $yk,
            ];
        }
        ?>
        <?= tabBar($yearTabs, (string) $selectedYear) ?>
        <p class="text-muted small mb-3">The number on each tab is how many subjects in that year are not credited yet.</p>
    <?php endif; ?>

    <?php foreach ($grouped as $yearLevel => $semesters): ?>
        <?php if ((int) $yearLevel !== $selectedYear) { continue; } ?>
        <?php foreach ($semesters as $semester => $rows): ?>
            <div class="card mb-3">
                <div class="card-header fw-bold">Year <?= htmlspecialchars($yearLevel) ?> — Semester <?= htmlspecialchars($semester) ?></div>
                <div class="table-responsive">
                <table class="table bg-white mb-0">
                    <thead><tr><th>Subject</th><th>Grade Credited</th><th>Remarks</th><th class="col-w-100"></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $hasCredit = $r['credit_id'] !== null;
                        $passed = $hasCredit && (float)$r['previous_grade'] <= 3.00;
                        ?>
                        <tr class="<?= $hasCredit ? 'table-info' : '' ?>">
                            <td><?= htmlspecialchars($r['subject_code'] . ' — ' . $r['subject_name']) ?></td>
                            <td><?= $hasCredit ? htmlspecialchars($r['previous_grade']) : '<span class="text-muted">—</span>' ?></td>
                            <td>
                                <?php if ($hasCredit): ?>
                                    <?= statusBadge($passed ? 'passed' : 'failed') ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        title="<?= $hasCredit ? 'Edit credit' : 'Credit this subject' ?>"
                                        onclick="openCreditModal('edit', <?= $r['subject_id'] ?>,
                                            '<?= htmlspecialchars(addslashes($r['subject_code'] . ' — ' . $r['subject_name'])) ?>',
                                            '<?= $r['credit_id'] ?? '' ?>',
                                            '<?= htmlspecialchars(addslashes($r['previous_school'] ?? '')) ?>',
                                            '<?= htmlspecialchars(addslashes($r['previous_subject_description'] ?? '')) ?>',
                                            '<?= $r['previous_grade'] ?? '' ?>')">
                                    <i class="bi bi-pencil-fill"></i>
                                </button>
                                <?php if ($hasCredit): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" title="View credit"
                                            onclick="openCreditModal('view', <?= $r['subject_id'] ?>,
                                                '<?= htmlspecialchars(addslashes($r['subject_code'] . ' — ' . $r['subject_name'])) ?>',
                                                '<?= $r['credit_id'] ?>',
                                                '<?= htmlspecialchars(addslashes($r['previous_school'] ?? '')) ?>',
                                                '<?= htmlspecialchars(addslashes($r['previous_subject_description'] ?? '')) ?>',
                                                '<?= $r['previous_grade'] ?? '' ?>')">
                                        <i class="bi bi-eye-fill"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if (empty($grouped)): ?><div class="alert alert-secondary">This curriculum has no subjects assigned yet.</div><?php endif; ?>

    <div class="card mb-3">
        <div class="card-header">Subjects Actually Enrolled This Term</div>
        <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Subject</th></tr></thead>
            <tbody>
            <?php foreach ($currentSubjects as $s): ?>
                <tr><td><?= htmlspecialchars($s['subject_code'] . ' — ' . $s['subject_name']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($currentSubjects)): ?><tr><td class="text-muted">None yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php endif; ?>
</div>

<!-- Shared modal for crediting/viewing a single curriculum subject -->
<div class="modal fade" id="creditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="save_credit">
      <input type="hidden" name="student_id" value="<?= $studentId ?>">
      <input type="hidden" name="subject_id" id="creditSubjectId">
      <input type="hidden" name="credit_id" id="creditId">
      <div class="modal-header">
        <h5 class="modal-title" id="creditModalTitle">Credit Subject</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="fw-bold" id="creditSubjectLabel"></p>
        <div class="mb-3">
            <label class="form-label">Previous School</label>
            <input type="text" class="form-control" id="previous_school" name="previous_school">
        </div>
        <div class="mb-3">
            <label class="form-label">Subject Description (as it appeared there)</label>
            <input type="text" class="form-control" id="previous_subject_description" name="previous_subject_description" placeholder="e.g. Intro to Programming">
        </div>
        <div class="mb-3">
            <label class="form-label">Grade There</label>
            <input type="text" class="form-control" id="previous_grade" name="previous_grade" placeholder="1.00–5.00">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto" id="removeCreditBtn"
                onclick="document.getElementById('deleteCreditForm').submit();">Remove Credit</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary" id="saveCreditBtn">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Separate plain form for the Remove Credit action, submitted via JS above -->
<form method="post" id="deleteCreditForm" class="d-none">
    <input type="hidden" name="action" value="delete_credit">
    <input type="hidden" name="student_id" value="<?= $studentId ?>">
    <input type="hidden" name="credit_id" id="deleteCreditId">
</form>

<script>
function openCreditModal(mode, subjectId, subjectLabel, creditId, school, description, grade) {
    document.getElementById('creditSubjectId').value = subjectId;
    document.getElementById('creditId').value = creditId;
    document.getElementById('creditSubjectLabel').textContent = subjectLabel;
    document.getElementById('previous_school').value = school;
    document.getElementById('previous_subject_description').value = description;
    document.getElementById('previous_grade').value = grade;
    document.getElementById('deleteCreditId').value = creditId;

    const isView = mode === 'view';
    document.getElementById('previous_school').disabled = isView;
    document.getElementById('previous_subject_description').disabled = isView;
    document.getElementById('previous_grade').disabled = isView;
    document.getElementById('saveCreditBtn').style.display = isView ? 'none' : 'inline-block';
    document.getElementById('removeCreditBtn').style.display = (isView || !creditId) ? 'none' : 'inline-block';
    document.getElementById('creditModalTitle').textContent = isView ? 'View Credit' : (creditId ? 'Edit Credit' : 'Credit Subject');

    new bootstrap.Modal(document.getElementById('creditModal')).show();
}
</script>
</body>
</html>