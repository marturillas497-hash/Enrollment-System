<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$enrollmentId = $_GET['enrollment_id'] ?? ($_POST['enrollment_id'] ?? null);
$error = '';
$message = '';

// Scoped to MY department via the enrollment's curriculum/program.
$stmt = $pdo->prepare(
    'SELECT e.*, s.student_id_number, s.last_name, s.first_name, st.school_year, st.semester
     FROM Enrollment e
     JOIN Student s ON s.student_id = e.student_id
     JOIN School_term st ON st.term_id = e.term_id
     JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
     JOIN Program p ON p.program_id = c.program_id
     WHERE e.enrollment_id = :id AND p.department_id = :dept'
);
$stmt->execute(['id' => $enrollmentId, 'dept' => $myDepartmentId]);
$enrollment = $stmt->fetch();

if ($enrollment === false) {
    require __DIR__ . '/../../includes/navbar.php';
    echo '<div class="container"><div class="alert alert-danger">Enrollment not found, or not in your department.</div></div>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_subject') {
    $offeringId = $_POST['offering_id'] ?? '';
    if ($offeringId === '') {
        $error = 'Select a subject to add.';
    } else {
        try {
            $pdo->prepare('INSERT INTO Enrolled_subject (enrollment_id, offering_id) VALUES (:eid, :oid)')
                ->execute(['eid' => $enrollmentId, 'oid' => $offeringId]);
            $message = 'Subject added.';
        } catch (Exception $e) {
            $error = 'Could not add — this subject may already be on the roster.';
        }
    }
}

$stmt = $pdo->prepare(
    'SELECT es.enrolled_subject_id, sub.subject_code, sub.subject_name, es.grade
     FROM Enrolled_subject es
     JOIN Class_Offering co ON co.offering_id = es.offering_id
     JOIN Subject sub ON sub.subject_id = co.subject_id
     WHERE es.enrollment_id = :eid'
);
$stmt->execute(['eid' => $enrollmentId]);
$currentSubjects = $stmt->fetchAll();

$credited = [];
if ($enrollment['source_shift_request_id']) {
    $stmt = $pdo->prepare(
        'SELECT sub.subject_code, sub.subject_name
         FROM Shift_credit sc JOIN Subject sub ON sub.subject_id = sc.credited_subject_id
         WHERE sc.request_id = :rid'
    );
    $stmt->execute(['rid' => $enrollment['source_shift_request_id']]);
    $credited = $stmt->fetchAll();
}

$stmt = $pdo->prepare(
    'SELECT co.offering_id, sub.subject_code, sub.subject_name
     FROM Class_Offering co
     JOIN Subject sub ON sub.subject_id = co.subject_id
     WHERE co.term_id = :term_id AND co.section_id = :section_id
       AND co.offering_id NOT IN (
           SELECT offering_id FROM Enrolled_subject WHERE enrollment_id = :eid
       )
     ORDER BY sub.subject_code'
);
$stmt->execute(['term_id' => $enrollment['term_id'], 'section_id' => $enrollment['section_id'], 'eid' => $enrollmentId]);
$availableOfferings = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Subjects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container container-md">
    <h1 class="h4 mb-1">
        <?= htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']) ?>
        (<?= htmlspecialchars($enrollment['student_id_number']) ?>)
    </h1>
    <p class="text-muted mb-3">
        <?= htmlspecialchars($enrollment['school_year'] . ' — Semester ' . $enrollment['semester']) ?>,
        Year <?= $enrollment['year_level'] ?>
    </p>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card mb-3">
        <div class="card-header">Current Subject Load</div>
        <div class="card-body p-0">
            <div class="table-responsive">
<table class="table mb-0">
                <thead><tr><th>Subject</th><th>Source</th></tr></thead>
                <tbody>
                <?php foreach ($credited as $c): ?>
                    <tr class="table-info">
                        <td><?= htmlspecialchars($c['subject_code'] . ' — ' . $c['subject_name']) ?></td>
                        <td><span class="badge bg-info text-dark">CREDITED</span></td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($currentSubjects as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['subject_code'] . ' — ' . $s['subject_name']) ?></td>
                        <td>Enrolled this term</td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($credited) && empty($currentSubjects)): ?>
                    <tr><td colspan="2" class="text-muted">No subjects loaded yet.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
</div>
        </div>
    </div>

    <form method="post" class="card">
        <div class="card-body">
            <input type="hidden" name="action" value="add_subject">
            <input type="hidden" name="enrollment_id" value="<?= $enrollmentId ?>">
            <label class="form-label">Add a subject (from offerings scheduled for this section/term)</label>
            <div class="d-flex gap-2">
                <select class="form-select" name="offering_id" required>
                    <option value="">Select</option>
                    <?php foreach ($availableOfferings as $o): ?>
                        <option value="<?= $o['offering_id'] ?>"><?= htmlspecialchars($o['subject_code'] . ' — ' . $o['subject_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary text-nowrap">Add Subject</button>
            </div>
            <div class="form-text">
                Prerequisite checking isn't built into this list yet — verify manually against the
                curriculum until the irregular-enrollment prerequisite filter is in place.
            </div>
        </div>
    </form>
</div>
</body>
</html>