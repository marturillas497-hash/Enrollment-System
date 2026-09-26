<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/password_helper.php';
require_once __DIR__ . '/../../src/helpers/mail_helper.php';

$user = requireRole(['admin']);
$pdo = getDbConnection();

$error = '';
$regenerated = null;
$mailWarning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate_password') {
    $accountId = $_POST['account_id'] ?? '';

    $stmt = $pdo->prepare(
        "SELECT a.username, a.email,
                COALESCE(r.last_name, t.last_name, sa.last_name) AS last_name,
                COALESCE(r.first_name, t.first_name, sa.first_name) AS first_name
         FROM Accounts a
         LEFT JOIN Registrar r ON r.account_id = a.account_id AND a.role = 'registrar'
         LEFT JOIN Teacher t ON t.account_id = a.account_id AND a.role = 'teacher'
         LEFT JOIN Admission_Staff sa ON sa.account_id = a.account_id AND a.role = 'admission_staff'
         WHERE a.account_id = :id AND a.role IN ('registrar','teacher','admission_staff')"
    );
    $stmt->execute(['id' => $accountId]);
    $account = $stmt->fetch();

    if (!$account) {
        $error = 'Staff account not found.';
    } else {
        $newPassword = generateTempPassword();
        $pdo->prepare('UPDATE Accounts SET password_hash = :hash, must_change_password = 1 WHERE account_id = :id')
            ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $accountId]);
        $regenerated = ['username' => $account['username'], 'password' => $newPassword, 'email' => $account['email']];

        if ($account['email']) {
            $staffName = $account['first_name'] . ' ' . $account['last_name'];
            $mailSent = sendPasswordResetEmail($account['email'], $staffName, $account['username'], $newPassword);
            if (!$mailSent) {
                $mailWarning = 'The password was reset, but the email could not be sent. Share the credentials below manually.';
            }
        } else {
            $mailWarning = 'No email is on file for this account. Share the credentials below manually.';
        }
    }
}

$staff = $pdo->query(
    "SELECT a.account_id, a.role, a.username,
            COALESCE(r.last_name, t.last_name, sa.last_name) AS last_name,
            COALESCE(r.first_name, t.first_name, sa.first_name) AS first_name,
            d.department_name
     FROM Accounts a
     LEFT JOIN Registrar r ON r.account_id = a.account_id AND a.role = 'registrar'
     LEFT JOIN Teacher t ON t.account_id = a.account_id AND a.role = 'teacher'
     LEFT JOIN Admission_Staff sa ON sa.account_id = a.account_id AND a.role = 'admission_staff'
     LEFT JOIN Department d ON d.department_id = COALESCE(r.department_id, t.department_id)
     WHERE a.role IN ('registrar','teacher','admission_staff')
     ORDER BY a.role, last_name"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Staff Accounts</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Staff Accounts</h1>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($mailWarning): ?>
        <div class="alert alert-warning"><?= htmlspecialchars($mailWarning) ?></div>
    <?php endif; ?>

    <?php if ($regenerated): ?>
        <div class="alert alert-success">
            <strong>New temporary password generated.</strong>
            <?php if (!$mailWarning): ?>
                Email has been sent to <strong><?= htmlspecialchars($regenerated['email']) ?></strong>.
            <?php endif; ?>
            Their old password no longer works.
            <dl class="row mb-0 mt-2">
                <dt class="col-sm-2">Username</dt>
                <dd class="col-sm-10"><code><?= htmlspecialchars($regenerated['username']) ?></code></dd>
                <dt class="col-sm-2">New Password</dt>
                <dd class="col-sm-10"><code><?= htmlspecialchars($regenerated['password']) ?></code></dd>
            </dl>
        </div>
    <?php endif; ?>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><th>Role</th><th>Name</th><th>Department</th><th>Username</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($staff as $s): ?>
            <tr>
                <td><span class="badge bg-secondary"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $s['role']))) ?></span></td>
                <td><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
                <td><?= $s['department_name'] ? htmlspecialchars($s['department_name']) : '<span class="text-muted">—</span>' ?></td>
                <td><?= htmlspecialchars($s['username']) ?></td>
                <td>
                    <form method="post" onsubmit="return confirm('Generate a new temporary password for this account? Their current password will stop working immediately.')">
                        <input type="hidden" name="action" value="regenerate_password">
                        <input type="hidden" name="account_id" value="<?= $s['account_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-warning">Regenerate Password</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($staff)): ?><tr><td colspan="5" class="text-muted">No staff accounts yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>