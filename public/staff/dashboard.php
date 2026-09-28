<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
$user = requireRole(['admission_staff']);
$pdo = getDbConnection();

$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM Admission_Application WHERE status = 'pending'")->fetchColumn();

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM Admission_Application WHERE status = 'validated' AND validated_by = :aid"
);
$stmt->execute(['aid' => $user['account_id']]);
$validatedByMe = (int)$stmt->fetchColumn();

$validatedThisWeek = (int)$pdo->query(
    "SELECT COUNT(*) FROM Admission_Application
     WHERE status = 'validated' AND date_validated >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
)->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admission Staff Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php require __DIR__ . '/../../includes/navbar.php'; ?>
    <div class="container">
        <h1>Admission Staff Dashboard</h1>

        <?php if ($pendingCount === 0): ?>
            <p class="text-muted small mb-2">You're all caught up. No applications are waiting for review.</p>
        <?php endif; ?>
        <div class="stat-grid">
            <?= statCard($pendingCount, 'Pending Applications', 'inbox', BASE_URL . '/staff/review-application.php', $pendingCount > 0) ?>
            <?= statCard($validatedThisWeek, 'Validated (Last 7 Days)', 'calendar-check', BASE_URL . '/staff/review-application.php?tab=validated') ?>
            <?= statCard($validatedByMe, 'Validated by You (Total)', 'person-check') ?>
        </div>
    </div>
</body>
</html>
