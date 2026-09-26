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

    $stmt = $pdo->prepare(
        'UPDATE Enrolled_subject SET grade = :grade, remarks = :remarks WHERE enrolled_subject_id = :id'
    );

    $failedRows = [];
    foreach ($grades as $enrolledSubjectId => $gradeValue) {
        $gradeValue = trim($gradeValue);
        $remarkValue = trim($remarks[$enrolledSubjectId] ?? '');

        // Empty grade = leave ungraded (NULL). Otherwise it must be a valid number in the schema's CHECK range.
        if ($gradeValue === '') {
            $gradeToSave = null;
        } elseif (is_numeric($gradeValue) && $gradeValue >= 1.00 && $gradeValue <= 5.00) {
            $gradeToSave = number_format((float)$gradeValue, 2, '.', '');
        } else {
            $failedRows[] = $enrolledSubjectId;
            continue;
        }

        $stmt->execute([
            'grade' => $gradeToSave,
            'remarks' => $remarkValue !== '' ? $remarkValue : null,
            'id' => $enrolledSubjectId,
        ]);
    }

    if (!empty($failedRows)) {
        $error = 'Some grades were out of range (must be 1.00–5.00) and were not saved. Fix and resubmit those rows.';
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
     WHERE es.offering_id = :oid
     ORDER BY s.last_name, s.first_name'
);
$stmt->execute(['oid' => $offeringId]);
$roster = $stmt->fetchAll();
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

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($termLocked): ?><div class="alert alert-secondary">This term is closed. Grades are locked and shown read-only.</div><?php endif; ?>

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
                        <input type="text" class="form-control form-control-sm" <?= $termLocked ? 'disabled' : '' ?>
                               name="grade[<?= $r['enrolled_subject_id'] ?>]"
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
            <button type="submit" class="btn btn-primary">Save Grades</button>
        <?php endif; ?>
    </form>
</div>
</body>
</html>
