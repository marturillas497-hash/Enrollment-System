<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$terms = $pdo->query('SELECT * FROM School_term ORDER BY term_id DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>School Terms</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">School Terms</h1>
    <?= termBanner(array_values(array_filter($terms, fn($t) => $t['status'] === 'ongoing'))) ?>

    <p class="text-muted">Terms are opened and closed by the administrator. This list is read-only.</p>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><th>#</th><th>School Year</th><th>Semester</th><th>Status</th><th>Closed</th></tr></thead>
        <tbody>
        <?php foreach ($terms as $t): ?>
            <tr>
                <td><?= $t['term_id'] ?></td>
                <td><?= htmlspecialchars($t['school_year']) ?></td>
                <td><?= semesterLabel($t['semester']) ?></td>
                <td>
                    <?= statusBadge($t['status']) ?>
                </td>
                <td><?= htmlspecialchars($t['date_closed'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($terms)): ?><tr><td colspan="5" class="text-muted">No terms yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>