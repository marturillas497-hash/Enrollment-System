<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../src/helpers/password_helper.php';
require_once __DIR__ . '/../src/helpers/reset_token_helper.php';

// Token is in the URL and this page loads CDN assets — keep it out of Referer and caches.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$pdo = getDbConnection();
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

$rawToken = $isPost ? ($_POST['token'] ?? '') : ($_GET['token'] ?? '');
$token = is_string($rawToken) ? trim($rawToken) : '';

$reset = findValidPasswordReset($pdo, $token);
$isInvite = $reset !== null && $reset['activated_at'] === null;
$error = '';

if ($isPost && $reset !== null) {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $error = validateNewPassword($newPassword, $confirmPassword, $reset['username']) ?? '';

    if ($error === '') {
        if (resetPasswordWithToken($pdo, $token, $newPassword)) {
            header('Location: ' . BASE_URL . '/login.php?reset=1');
            exit;
        }
        $reset = null;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <title><?= $isInvite ? 'Set Your Password' : 'Reset Password' ?> — MIST Enrollment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/tokens.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components.css">
</head>
<body>
    <div class="auth-card">
        <?php if ($reset === null): ?>
            <h1 class="h4 mb-2">Link Not Valid</h1>
            <div class="alert alert-danger mb-0">
                This link is invalid, has expired, or has already been used.
            </div>
            <a href="<?= BASE_URL ?>/forgot-password.php" class="btn btn-neu-primary w-100 mt-3">Request a New Link</a>
        <?php else: ?>
            <h1 class="h4 mb-2"><?= $isInvite ? 'Welcome to MIST' : 'Reset Your Password' ?></h1>
            <p class="text-muted"><?= $isInvite ? 'Choose a password for' : 'Choose a new password for' ?> <strong><?= htmlspecialchars($reset['username']) ?></strong>.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" novalidate>
                <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password</label>
                    <div class="password-field-wrap">
                        <input type="password" class="form-control neu-input pw-input" id="new_password" name="new_password"
                               required minlength="8" autocomplete="new-password" autofocus>
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
                <button type="submit" class="btn btn-neu-primary w-100"><?= $isInvite ? 'Set Password' : 'Reset Password' ?></button>
            </form>

            <script src="<?= BASE_URL ?>/assets/js/password-form.js"></script>
        <?php endif; ?>
    </div>
</body>
</html>
