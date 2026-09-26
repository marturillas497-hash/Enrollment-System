<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['teacher']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Teacher WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$teacher = $stmt->fetch();

if ($teacher === false) {
    require __DIR__ . '/../../includes/navbar.php';
    echo '<div class="container"><div class="alert alert-danger">'
        . 'This account has no teacher profile on record. Contact the admin.</div></div>';
    exit;
}

// Scoped to the current ongoing term only — "my students" means who I'm teaching
// right now, not a full history of every student across every past term.
$stmt = $pdo->prepare(
    "SELECT st_.student_id_number, st_.first_name, st_.last_name, s.subject_code, s.subject_name, sec.section_name
     FROM Class_Offering co
     JOIN Enrolled_subject es ON es.offering_id = co.offering_id
     JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
     JOIN Student st_ ON st_.student_id = e.student_id
     JOIN Subject s ON s.subject_id = co.subject_id
     JOIN Section sec ON sec.section_id = co.section_id
     JOIN School_term term ON term.term_id = co.term_id
     WHERE co.teacher_id = :tid AND term.status = 'ongoing'
     ORDER BY st_.last_name, st_.first_name, s.subject_code"
);
$stmt->execute(['tid' => $teacher['teacher_id']]);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">My Students</h1>
    <p class="text-muted">Everyone enrolled in your classes this term, across every section.</p>
    <div class="table-responsive">
    <table class="table table-hover bg-white">
        <thead><tr><th>ID Number</th><th>Name</th><th>Subject</th><th>Section</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars($r['student_id_number']) ?></td>
                <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                <td><?= htmlspecialchars($r['subject_code'] . ' — ' . $r['subject_name']) ?></td>
                <td><?= htmlspecialchars($r['section_name']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?><tr><td colspan="4" class="text-muted">No students this term.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
</body>
</html>
