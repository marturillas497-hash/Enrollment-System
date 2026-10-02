<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../src/helpers/password_helper.php';

$user = requireLogin();
$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $error = validateNewPassword($newPassword, $confirmPassword, $user['username']) ?? '';

    if ($error === '') {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            'UPDATE Accounts SET password_hash = :hash, must_change_password = 0,
                    session_version = session_version + 1
             WHERE account_id = :id'
        );
        $stmt->execute([
            'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id'   => $user['account_id'],
        ]);

        // Other logins die with the version bump; keep this one alive.
        $stmt = $pdo->prepare('SELECT session_version FROM Accounts WHERE account_id = :id');
        $stmt->execute(['id' => $user['account_id']]);
        session_regenerate_id(true);
        $_SESSION['user']['session_version'] = (int)$stmt->fetchColumn();
        $_SESSION['user']['must_change'] = false;

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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/tokens.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components.css">
</head>
<body>
    <div class="auth-card">
        <h1 class="h4 mb-2">Set a New Password</h1>
        <p class="text-muted">You're using a temporary password. Set a permanent one to continue.</p>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success mb-0">
                Password updated.
                <a href="<?= htmlspecialchars(DASHBOARD_BY_ROLE[$user['role']]) ?>">Continue to your dashboard</a>
            </div>
        <?php else: ?>
            <form method="post" novalidate>
                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password</label>
                    <div class="password-field-wrap">
                        <input type="password" class="form-control neu-input pw-input" id="new_password" name="new_password"
                               required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle-btn" data-toggle-for="new_password" tabindex="-1" aria-label="Show or hide password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="form-text">At least 8 characters.</div>
                </div>
                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirm Password</label>
                    <div class="password-field-wrap">
                        <input type="password" class="form-control neu-input pw-input" id="confirm_password" name="confirm_password"
                               required minlength="8" autocomplete="new-password">
                        <button type="button" class="password-toggle-btn" data-toggle-for="confirm_password" tabindex="-1" aria-label="Show or hide password">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="form-text" id="match-text"></div>
                </div>
                <button type="submit" class="btn btn-neu-primary w-100">Update Password</button>
            </form>

            <script src="<?= BASE_URL ?>/assets/js/password-form.js"></script>
        <?php endif; ?>
    </div>
</body>
</html>
