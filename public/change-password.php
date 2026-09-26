<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';

$user = requireLogin();
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            'UPDATE Accounts SET password_hash = :hash, must_change_password = 0
             WHERE account_id = :id'
        );
        $stmt->execute([
            'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id'   => $user['account_id'],
        ]);
        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password — MIST Enrollment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container container-xs">
        <div class="py-5">
            <h1 class="h4 mb-3">Set a New Password</h1>
            <p class="text-muted">You're using a temporary password. Set a permanent one to continue.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    Password updated.
                    <a href="<?= htmlspecialchars(DASHBOARD_BY_ROLE[$user['role']]) ?>">Continue to your dashboard</a>
                </div>
            <?php else: ?>
                <form method="post" novalidate>
                    <div class="mb-3">
                        <label for="new_password" class="form-label">New Password</label>
                        <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                    </div>
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Update Password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
