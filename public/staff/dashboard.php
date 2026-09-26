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

        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <a href="<?= BASE_URL ?>/staff/review-application.php" class="text-decoration-none">
                    <div class="card text-center">
                        <div class="card-body">
                            <div class="display-6"><?= $pendingCount ?></div>
                            <div class="text-muted">Pending Applications</div>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6"><?= $validatedThisWeek ?></div>
                        <div class="text-muted">Validated (Last 7 Days)</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4 mb-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6"><?= $validatedByMe ?></div>
                        <div class="text-muted">Validated by You (Total)</div>
                    </div>
                </div>
            </div>
        </div>

        <a href="<?= BASE_URL ?>/staff/review-application.php" class="btn btn-primary">Review Applications</a>
    </div>
</body>
</html>
