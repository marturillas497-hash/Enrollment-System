<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['teacher']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Teacher WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$teacher = $stmt->fetch();

$offeringId = $_GET['offering_id'] ?? ($_POST['offering_id'] ?? null);

// Ownership check: this offering must belong to THIS teacher, not just any offering ID.
$stmt = $pdo->prepare(
    'SELECT co.*, s.subject_code, s.subject_name, sec.section_name, st.status AS term_status
     FROM Class_Offering co
     JOIN Subject s ON s.subject_id = co.subject_id
     JOIN Section sec ON sec.section_id = co.section_id
     JOIN School_term st ON st.term_id = co.term_id
     WHERE co.offering_id = :id AND co.teacher_id = :tid'
);
$stmt->execute(['id' => $offeringId, 'tid' => $teacher['teacher_id']]);
$offering = $stmt->fetch();

if ($offering === false) {
    require __DIR__ . '/../../includes/navbar.php';
    echo '<div class="container"><div class="alert alert-danger">'
        . 'That class offering does not exist or is not assigned to you.</div></div>';
    exit;
}

$termLocked = $offering['term_status'] === 'closed';

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grades') {
    if ($termLocked) {
        $error = 'This term is closed — grades can no longer be edited.';
    } else {
    $grades = $_POST['grade'] ?? [];   // [enrolled_subject_id => value]
    $remarks = $_POST['remarks'] ?? []; // [enrolled_subject_id => value]

    // offering_id is bound below too — $offeringId was already verified above to belong
    // to this teacher, so a spoofed enrolled_subject_id from another offering just matches
    // zero rows instead of silently updating a class this teacher doesn't own.
    $stmt = $pdo->prepare(
        'UPDATE Enrolled_subject SET grade = :grade, remarks = :remarks
         WHERE enrolled_subject_id = :id AND offering_id = :oid'
    );

    $current = $pdo->prepare("SELECT es.enrolled_subject_id, es.grade, es.remarks FROM Enrolled_subject es JOIN Enrollment e ON e.enrollment_id = es.enrollment_id WHERE es.offering_id = :oid AND e.status = 'approved'");
    $current->execute(['oid' => $offeringId]);
    $currentRows = [];
    foreach ($current->fetchAll() as $row) {
        $currentRows[(int)$row['enrolled_subject_id']] = $row;
    }

    $rangeRows = [];
    $ruleRows = [];
    foreach ($grades as $enrolledSubjectId => $gradeValue) {
        if (!isset($currentRows[(int)$enrolledSubjectId])) {
            continue;
        }
        $gradeValue = trim($gradeValue);
        $remarkValue = trim($remarks[$enrolledSubjectId] ?? '');

        // Empty grade = leave ungraded (NULL). Otherwise it must be a valid number in the schema's CHECK range.
        if ($gradeValue === '') {
            $gradeToSave = null;
        } elseif (is_numeric($gradeValue) && $gradeValue >= 1.00 && $gradeValue <= 5.00) {
            $gradeToSave = number_format((float)$gradeValue, 2, '.', '');
        } else {
            $rangeRows[] = $enrolledSubjectId;
            continue;
        }

        // Rows the teacher didn't touch are left alone, so older records aren't re-validated.
        $old = $currentRows[(int)$enrolledSubjectId];
        $oldGrade = $old['grade'] === null ? null : number_format((float)$old['grade'], 2, '.', '');
        if ($oldGrade === $gradeToSave && (string)($old['remarks'] ?? '') === $remarkValue) {
            continue;
        }

        /*
         * The grade decides Passed or Failed, never the remark. Dropped and Incomplete
         * must have no grade, and Passed or Failed cannot exist without one.
         */
        if ($gradeToSave !== null) {
            if (in_array($remarkValue, ['Dropped', 'Incomplete'], true)) {
                $ruleRows[] = $enrolledSubjectId;
                continue;
            }
            $remarkValue = (float)$gradeToSave <= 3.00 ? 'Passed' : 'Failed';
        } elseif (in_array($remarkValue, ['Passed', 'Failed'], true)) {
            $ruleRows[] = $enrolledSubjectId;
            continue;
        }

        $stmt->execute([
            'grade' => $gradeToSave,
            'remarks' => $remarkValue !== '' ? $remarkValue : null,
            'id' => $enrolledSubjectId,
            'oid' => $offeringId,
        ]);
    }

    $problems = [];
    if ($rangeRows) {
        $problems[] = 'Some grades were out of range (must be 1.00–5.00).';
    }
    if ($ruleRows) {
        $problems[] = 'Some rows broke the grade rules: Dropped and Incomplete cannot have a grade, and Passed or Failed needs a grade.';
    }
    if ($problems) {
        $error = implode(' ', $problems) . ' Those rows were not saved. Fix and resubmit them.';
    } else {
        $message = 'Grades saved.';
    }
    }
}

$stmt = $pdo->prepare(
    'SELECT es.enrolled_subject_id, es.grade, es.remarks, s.student_id_number, s.last_name, s.first_name
     FROM Enrolled_subject es
     JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
     JOIN Student s ON s.student_id = e.student_id
     WHERE es.offering_id = :oid AND e.status = \'approved\'
     ORDER BY s.last_name, s.first_name'
);
$stmt->execute(['oid' => $offeringId]);
$roster = $stmt->fetchAll();

$siblings = $pdo->prepare(
    "SELECT co.offering_id, co.day_of_week, co.start_time, co.end_time, sec.section_name, sec.year_level, p.program_code,
            (SELECT COUNT(*) FROM Enrolled_subject es JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
             WHERE es.offering_id = co.offering_id AND e.status = 'approved') AS enrolled
     FROM Class_Offering co JOIN Section sec ON sec.section_id = co.section_id JOIN Program p ON p.program_id = sec.program_id
     WHERE co.teacher_id = :tid AND co.subject_id = :sub AND co.term_id = :term
     ORDER BY sec.year_level, sec.section_name, FIELD(co.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), co.start_time"
);
$siblings->execute(['tid' => $teacher['teacher_id'], 'sub' => $offering['subject_id'], 'term' => $offering['term_id']]);
$siblings = $siblings->fetchAll();

$gradedCount = 0;
foreach ($roster as $r) {
    if ($r['grade'] !== null) { $gradedCount++; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grade Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-1"><?= htmlspecialchars($offering['subject_code'] . ' — ' . $offering['subject_name']) ?></h1>
    <p class="text-muted mb-3">Section <?= htmlspecialchars($offering['section_name']) ?></p>

    <?php if (count($siblings) > 1): ?>
        <div class="mb-3">
            <label class="form-label small mb-1" for="classSwitch">You teach this subject in <?= count($siblings) ?> classes this term</label>
            <select class="form-select" id="classSwitch" onchange="window.location.href = '?offering_id=' + this.value">
                <?php foreach ($siblings as $sib): ?>
                    <option value="<?= (int)$sib['offering_id'] ?>"<?= (int)$sib['offering_id'] === (int)$offering['offering_id'] ? ' selected' : '' ?>>
                        <?= htmlspecialchars(sectionLabel($sib['year_level'], $sib['section_name'], $sib['program_code']) . ' · ' . formatSchedule($sib['day_of_week'], $sib['start_time'], $sib['end_time']) . ' · ' . (int)$sib['enrolled'] . ' enrolled') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    <?php endif; ?>

    <?php if (!empty($roster)): ?>
        <?= progressMeter($gradedCount, count($roster), 'students graded') ?>
    <?php endif; ?>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="4000"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($termLocked): ?><div class="alert alert-secondary">This term is closed. Grades are locked and shown read-only.</div><?php endif; ?>

    <?php if (!$termLocked): ?>
        <p class="text-muted small">The grade decides the remark: 3.00 or better is Passed, above 3.00 is Failed. For Dropped or Incomplete, leave the grade empty.</p>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="save_grades">
        <input type="hidden" name="offering_id" value="<?= $offeringId ?>">
        <div class="table-responsive">
<table class="table bg-white">
            <thead><tr><th>ID Number</th><th>Name</th><th class="col-w-120">Grade</th><th>Remarks</th></tr></thead>
            <tbody>
            <?php foreach ($roster as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['student_id_number']) ?></td>
                    <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                    <td>
                        <input type="text" class="form-control form-control-sm grade-input" <?= $termLocked ? 'disabled' : '' ?>
                               name="grade[<?= $r['enrolled_subject_id'] ?>]" data-original="<?= htmlspecialchars($r['grade'] ?? '') ?>"
                               value="<?= htmlspecialchars($r['grade'] ?? '') ?>" placeholder="1.00–5.00">
                    </td>
                    <td>
                        <select class="form-select form-select-sm" <?= $termLocked ? 'disabled' : '' ?>
                                name="remarks[<?= $r['enrolled_subject_id'] ?>]">
                            <option value="" <?= ($r['remarks'] ?? '') === '' ? 'selected' : '' ?>>—</option>
                            <option value="Passed" <?= ($r['remarks'] ?? '') === 'Passed' ? 'selected' : '' ?>>Passed</option>
                            <option value="Failed" <?= ($r['remarks'] ?? '') === 'Failed' ? 'selected' : '' ?>>Failed</option>
                            <option value="Dropped" <?= ($r['remarks'] ?? '') === 'Dropped' ? 'selected' : '' ?>>Dropped</option>
                            <option value="Incomplete" <?= ($r['remarks'] ?? '') === 'Incomplete' ? 'selected' : '' ?>>Incomplete</option>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($roster)): ?><tr><td colspan="4" class="text-muted">No students enrolled in this offering.</td></tr><?php endif; ?>
            </tbody>
        </table>
</div>
        <?php if (!empty($roster) && !$termLocked): ?>
            <button type="submit" class="btn btn-primary" id="save-grades-btn">Save Grades</button>
            <span class="text-muted small ms-2" id="unsaved-note" hidden>You have unsaved changes.</span>
        <?php endif; ?>
    </form>
</div>
<script>
    /*
     * Range check and remark sync as the teacher types, plus a heads-up for unsaved edits.
     * This mirrors, not replaces, the rules the server enforces on save.
     */
    (function () {
        var form = document.querySelector('form');
        if (!form) { return; }
        var unsavedNote = document.getElementById('unsaved-note');
        var dirty = false;

        function checkRange(input) {
            var v = input.value.trim();
            var n = parseFloat(v);
            var outOfRange = v !== '' && (!/^\d+(\.\d+)?$/.test(v) || n < 1 || n > 5);
            input.classList.toggle('is-invalid', outOfRange);
        }

        function remarkSelect(input) {
            return input.closest('tr').querySelector('select[name^="remarks"]');
        }

        function isManual(select) {
            return !!select && ['Dropped', 'Incomplete'].indexOf(select.value) !== -1;
        }

        function applyLock(select, clear) {
            var input = select.closest('tr').querySelector('.grade-input');
            if (!input) { return; }
            var manual = isManual(select);
            if (manual && clear) {
                input.value = '';
                checkRange(input);
            }
            input.readOnly = manual;
        }

        function syncRemark(input) {
            var select = remarkSelect(input);
            if (!select || isManual(select)) { return; }
            var v = input.value.trim();
            var n = parseFloat(v);
            var valid = /^\d+(\.\d+)?$/.test(v) && n >= 1 && n <= 5;
            select.value = valid ? (n <= 3 ? 'Passed' : 'Failed') : '';
        }

        document.querySelectorAll('.grade-input').forEach(function (input) {
            checkRange(input);
            input.addEventListener('input', function () {
                checkRange(input);
                syncRemark(input);
                if (input.value !== input.dataset.original) {
                    dirty = true;
                    if (unsavedNote) { unsavedNote.hidden = false; }
                }
            });
        });
        document.querySelectorAll('select[name^="remarks"]').forEach(function (select) {
            applyLock(select, false);
            select.addEventListener('change', function () {
                var input = select.closest('tr').querySelector('.grade-input');
                applyLock(select, true);
                if (input) { syncRemark(input); }
                dirty = true;
                if (unsavedNote) { unsavedNote.hidden = false; }
            });
        });

        form.addEventListener('submit', function () { dirty = false; });
        window.addEventListener('beforeunload', function (e) {
            if (dirty) { e.preventDefault(); e.returnValue = ''; }
        });
    })();
</script>
</body>
</html>