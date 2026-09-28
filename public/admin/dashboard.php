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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php require __DIR__ . '/../../includes/navbar.php'; ?>
    <div class="container">
        <h1>Admin Dashboard</h1>

        <div class="stat-grid">
            <?= statCard((int)($roleCounts['registrar'] ?? 0), 'Registrars', 'person-badge', BASE_URL . '/admin/staff.php?role=registrar') ?>
            <?= statCard((int)($roleCounts['teacher'] ?? 0), 'Teachers', 'person-workspace', BASE_URL . '/admin/staff.php?role=teacher') ?>
            <?= statCard((int)($roleCounts['admission_staff'] ?? 0), 'Admission Staff', 'person-check', BASE_URL . '/admin/staff.php?role=admission_staff') ?>
            <?= statCard($departmentCount . ' / ' . $programCount, 'Departments / Programs', 'building') ?>
        </div>

        <div class="toolbar mb-4">
            <a href="<?= BASE_URL ?>/admin/register-registrar.php" class="btn btn-outline-primary">+ Registrar</a>
            <a href="<?= BASE_URL ?>/admin/register-teacher.php" class="btn btn-outline-primary">+ Teacher</a>
            <a href="<?= BASE_URL ?>/admin/register-admission-staff.php" class="btn btn-outline-primary">+ Admission Staff</a>
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
                        <td><?= statusBadge($a['role']) ?></td>
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
