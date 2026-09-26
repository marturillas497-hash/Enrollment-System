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

$stmt = $pdo->prepare(
    "SELECT co.offering_id, s.subject_code, s.subject_name, sec.section_name,
            (SELECT COUNT(*) FROM Enrolled_subject es WHERE es.offering_id = co.offering_id) AS enrolled_count,
            (SELECT COUNT(*) FROM Enrolled_subject es WHERE es.offering_id = co.offering_id AND es.grade IS NULL) AS ungraded_count
     FROM Class_Offering co
     JOIN Subject s ON s.subject_id = co.subject_id
     JOIN Section sec ON sec.section_id = co.section_id
     JOIN School_term st ON st.term_id = co.term_id
     WHERE co.teacher_id = :tid AND st.status = 'ongoing'
     ORDER BY s.subject_code"
);
$stmt->execute(['tid' => $teacher['teacher_id']]);
$offerings = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Grades</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Grades</h1>
    <p class="text-muted">Your current-term classes. Offerings with ungraded students are flagged.</p>
    <div class="table-responsive">
    <table class="table table-hover bg-white">
        <thead><tr><th>Subject</th><th>Section</th><th>Students</th><th>Ungraded</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($offerings as $o): ?>
            <tr class="<?= $o['ungraded_count'] > 0 ? 'table-warning' : '' ?>">
                <td><?= htmlspecialchars($o['subject_code'] . ' — ' . $o['subject_name']) ?></td>
                <td><?= htmlspecialchars($o['section_name']) ?></td>
                <td><?= $o['enrolled_count'] ?></td>
                <td>
                    <?php if ($o['ungraded_count'] > 0): ?>
                        <span class="badge bg-warning text-dark"><?= $o['ungraded_count'] ?> ungraded</span>
                    <?php else: ?>
                        <span class="badge bg-success">Complete</span>
                    <?php endif; ?>
                </td>
                <td><a href="<?= BASE_URL ?>/teacher/grade-entry.php?offering_id=<?= $o['offering_id'] ?>"
                       class="btn btn-sm btn-outline-primary">Enter Grades</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($offerings)): ?><tr><td colspan="5" class="text-muted">No current-term classes.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
</body>
</html>
