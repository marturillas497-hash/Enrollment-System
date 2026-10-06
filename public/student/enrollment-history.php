<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['student']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Student WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$student = $stmt->fetch();

$stmt = $pdo->prepare(
    'SELECT e.*, st.school_year, st.semester, sec.section_name, p.program_code
     FROM Enrollment e
     JOIN School_term st ON st.term_id = e.term_id
     JOIN Section sec ON sec.section_id = e.section_id
     JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
     JOIN Program p ON p.program_id = c.program_id
     WHERE e.student_id = :sid
     ORDER BY e.enrollment_id DESC'
);
$stmt->execute(['sid' => $student['student_id']]);
$history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enrollment History</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Enrollment History</h1>
    <div class="table-responsive">
    <table class="table table-hover bg-white">
        <thead><tr><th>Term</th><th>Program</th><th>Section</th><th>Year</th><th>Standing</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td><?= htmlspecialchars($h['school_year'] . ' — Semester ' . $h['semester']) ?></td>
                <td><?= htmlspecialchars($h['program_code']) ?></td>
                <td><?= htmlspecialchars($h['section_name']) ?></td>
                <td><?= $h['year_level'] ?></td>
                <td><?= statusBadge($h['student_standing']) ?></td>
                <td>
                    <?= statusBadge($h['status']) ?>
                    <?php if ($h['status'] === 'rejected' && (string)$h['rejection_reason'] !== ''): ?>
                        <div class="small text-muted"><?= htmlspecialchars($h['rejection_reason']) ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($history)): ?><tr><td colspan="6" class="text-muted">No enrollment history yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
</body>
</html>
