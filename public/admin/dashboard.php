<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
$user = requireRole(['admin']);
$pdo = getDbConnection();

$roleCounts = $pdo->query(
    "SELECT role, COUNT(*) AS total FROM Accounts
     WHERE role IN ('registrar','teacher','admission_staff')
     GROUP BY role"
)->fetchAll(PDO::FETCH_KEY_PAIR);

$departmentCount = (int)$pdo->query('SELECT COUNT(*) FROM Department')->fetchColumn();
$programCount = (int)$pdo->query('SELECT COUNT(*) FROM Program')->fetchColumn();

$recentAccounts = $pdo->query(
    "SELECT a.username, a.role, a.created_at,
            COALESCE(r.last_name, t.last_name, sa.last_name) AS last_name,
            COALESCE(r.first_name, t.first_name, sa.first_name) AS first_name
     FROM Accounts a
     LEFT JOIN Registrar r ON r.account_id = a.account_id AND a.role = 'registrar'
     LEFT JOIN Teacher t ON t.account_id = a.account_id AND a.role = 'teacher'
     LEFT JOIN Admission_Staff sa ON sa.account_id = a.account_id AND a.role = 'admission_staff'
     WHERE a.role IN ('registrar','teacher','admission_staff')
     ORDER BY a.created_at DESC LIMIT 8"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php require __DIR__ . '/../../includes/navbar.php'; ?>
    <div class="container">
        <h1>Admin Dashboard</h1>

        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6"><?= (int)($roleCounts['registrar'] ?? 0) ?></div>
                        <div class="text-muted">Registrars</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6"><?= (int)($roleCounts['teacher'] ?? 0) ?></div>
                        <div class="text-muted">Teachers</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6"><?= (int)($roleCounts['admission_staff'] ?? 0) ?></div>
                        <div class="text-muted">Admission Staff</div>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-center">
                    <div class="card-body">
                        <div class="display-6"><?= $departmentCount ?> / <?= $programCount ?></div>
                        <div class="text-muted">Departments / Programs</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Recently Created Accounts</div>
            <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th>Name</th><th>Role</th><th>Username</th><th>Created</th></tr></thead>
                <tbody>
                <?php foreach ($recentAccounts as $a): ?>
                    <tr>
                        <td><?= htmlspecialchars($a['first_name'] . ' ' . $a['last_name']) ?></td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $a['role']))) ?></span></td>
                        <td><?= htmlspecialchars($a['username']) ?></td>
                        <td><?= htmlspecialchars($a['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recentAccounts)): ?><tr><td colspan="4" class="text-muted">No accounts created yet.</td></tr><?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</body>
</html>
