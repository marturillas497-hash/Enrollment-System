<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$enrollmentId = $_GET['id'] ?? ($_POST['enrollment_id'] ?? null);
$error = '';
$message = '';

// Scoped to MY department via the curriculum's program.
$enrollment = null;
if ($enrollmentId) {
    $stmt = $pdo->prepare(
        "SELECT e.*, s.student_id_number, s.last_name, s.first_name, p.program_code, st.school_year, st.semester
         FROM Enrollment e
         JOIN Student s ON s.student_id = e.student_id
         JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
         JOIN Program p ON p.program_id = c.program_id
         JOIN School_term st ON st.term_id = e.term_id
         WHERE e.enrollment_id = :id AND e.status = 'pending' AND p.department_id = :dept"
    );
    $stmt->execute(['id' => $enrollmentId, 'dept' => $myDepartmentId]);
    $enrollment = $stmt->fetch();
}

if ($enrollment && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $pdo->prepare("UPDATE Enrollment SET status = 'approved', approved_by = :by WHERE enrollment_id = :id")
            ->execute(['by' => $user['account_id'], 'id' => $enrollmentId]);
        $message = 'Enrollment approved.';
        $enrollment = null;
    } elseif ($action === 'reject') {
        $pdo->prepare("UPDATE Enrollment SET status = 'rejected', approved_by = :by WHERE enrollment_id = :id")
            ->execute(['by' => $user['account_id'], 'id' => $enrollmentId]);
        $message = 'Enrollment rejected.';
        $enrollment = null;
    }
}

// Chosen subjects for the detail view
$chosenSubjects = [];
if ($enrollment) {
    $stmt = $pdo->prepare(
        'SELECT sub.subject_code, sub.subject_name, sub.units, sec.section_name,
                co.day_of_week, co.start_time, co.end_time
         FROM Enrolled_subject es
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         JOIN Subject sub ON sub.subject_id = co.subject_id
         JOIN Section sec ON sec.section_id = co.section_id
         WHERE es.enrollment_id = :eid'
    );
    $stmt->execute(['eid' => $enrollmentId]);
    $chosenSubjects = $stmt->fetchAll();
}

// List view: every pending irregular enrollment in MY department
$pendingList = [];
if (!$enrollment) {
    $stmt = $pdo->prepare(
        "SELECT e.enrollment_id, e.date_enrolled, s.student_id_number, s.last_name, s.first_name,
                p.program_code, st.school_year, st.semester
         FROM Enrollment e
         JOIN Student s ON s.student_id = e.student_id
         JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
         JOIN Program p ON p.program_id = c.program_id
         JOIN School_term st ON st.term_id = e.term_id
         WHERE e.status = 'pending' AND e.student_standing = 'irregular' AND p.department_id = :dept
         ORDER BY e.date_enrolled ASC"
    );
    $stmt->execute(['dept' => $myDepartmentId]);
    $pendingList = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Irregular Enrollments</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($enrollment): ?>
        <h1 class="h4 mb-1">
            <?= htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']) ?>
            (<?= htmlspecialchars($enrollment['student_id_number']) ?>)
        </h1>
        <p class="text-muted mb-3">
            <?= htmlspecialchars($enrollment['program_code'] . ' — ' . $enrollment['school_year'] . ' Semester ' . $enrollment['semester']) ?>,
            Year <?= $enrollment['year_level'] ?>
        </p>

        <div class="card mb-3">
            <div class="card-header">Subjects Chosen</div>
            <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Section</th><th>Schedule</th></tr></thead>
                <tbody>
                <?php foreach ($chosenSubjects as $s): ?>
                    <tr>
                        <td><?= htmlspecialchars($s['subject_code']) ?></td>
                        <td><?= htmlspecialchars($s['subject_name']) ?></td>
                        <td><?= $s['units'] ?></td>
                        <td><?= htmlspecialchars($s['section_name']) ?></td>
                        <td><?= htmlspecialchars($s['day_of_week'] . ' ' . $s['start_time'] . '–' . $s['end_time']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($chosenSubjects)): ?><tr><td colspan="5" class="text-muted">No subjects selected.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="enrollment_id" value="<?= $enrollmentId ?>">
            <button type="submit" class="btn btn-success" onclick="return confirm('Approve this enrollment?')">Approve</button>
        </form>
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="enrollment_id" value="<?= $enrollmentId ?>">
            <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Reject this enrollment?')">Reject</button>
        </form>

        <div class="mt-3"><a href="<?= BASE_URL ?>/registrar/irregular-enrollments.php">&larr; Back to list</a></div>

    <?php else: ?>
        <h1 class="h4 mb-3">Pending Irregular Enrollments</h1>
        <div class="table-responsive">
        <table class="table table-hover bg-white">
            <thead><tr><th>Student</th><th>Program</th><th>Term</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pendingList as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['first_name'] . ' ' . $p['last_name']) ?> (<?= htmlspecialchars($p['student_id_number']) ?>)</td>
                    <td><?= htmlspecialchars($p['program_code']) ?></td>
                    <td><?= htmlspecialchars($p['school_year'] . ' S' . $p['semester']) ?></td>
                    <td><?= htmlspecialchars($p['date_enrolled']) ?></td>
                    <td><a href="?id=<?= $p['enrollment_id'] ?>" class="btn btn-sm btn-outline-primary">Review</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($pendingList)): ?><tr><td colspan="5" class="text-muted">No pending irregular enrollments.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
