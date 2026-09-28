<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../src/helpers/password_helper.php';
require_once __DIR__ . '/../src/helpers/mail_helper.php';

if (currentUser() !== null) {
    header('Location: ' . DASHBOARD_BY_ROLE[currentUser()['role']]);
    exit;
}

/** Best-effort name for the email greeting; admin has no profile row. */
function accountDisplayName(PDO $pdo, int $accountId, string $role): string
{
    $table = [
        'student'         => 'Student',
        'teacher'         => 'Teacher',
        'registrar'       => 'Registrar',
        'admission_staff' => 'Admission_Staff',
    ][$role] ?? null;

    if ($table === null) {
        return 'there';
    }

    $stmt = $pdo->prepare("SELECT first_name, last_name FROM {$table} WHERE account_id = :id");
    $stmt->execute(['id' => $accountId]);
    $row = $stmt->fetch();
    return $row ? trim($row['first_name'] . ' ' . $row['last_name']) : 'there';
}

$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($username !== '' && $email !== '') {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare('SELECT account_id, role, email FROM Accounts WHERE username = :u');
        $stmt->execute(['u' => $username]);
        $account = $stmt->fetch();

        // Both fields have to match the same account — and the response below is
        // identical either way — so this form can't be used to check whether a
        // given username or email exists.
        if ($account && $account['email'] !== null && strcasecmp(trim($account['email']), $email) === 0) {
            $name = accountDisplayName($pdo, (int)$account['account_id'], $account['role']);
            $newPassword = generateTempPassword();

            $pdo->prepare('UPDATE Accounts SET password_hash = :hash, must_change_password = 1 WHERE account_id = :id')
                ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $account['account_id']]);

            sendPasswordResetEmail($account['email'], $name, $username, $newPassword);
        }
    }

    $submitted = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password — MIST Enrollment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/tokens.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/components.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/login.css">
</head>
<body>
    <div class="login-box">
        <div class="brand-panel d-none d-md-flex flex-column justify-content-center col-md-5 p-5">
            <div class="blob blob-1"></div>
            <div class="blob blob-2"></div>
            <div class="brand-content">
                <img src="<?= BASE_URL ?>/assets/mist-logo.png" alt="MIST Logo" class="logo-slot logo-img">
                <p class="text-uppercase mb-1 welcome-label">Welcome</p>
                <h1 class="fw-bold mb-3">Makilala Institute of<br>Science and Technology</h1>
                <p class="mb-0 brand-tagline">
                    Enrollment System
                </p>
            </div>
        </div>

        <div class="mobile-brand-header d-flex d-md-none align-items-center gap-3">
            <img src="<?= BASE_URL ?>/assets/mist-logo.png" alt="MIST Logo" class="mobile-logo">
            <div>
                <p class="mb-0 mobile-brand-title">Makilala Institute of Science and Technology</p>
                <p class="mb-0 mobile-brand-tagline">Enrollment System</p>
            </div>
        </div>

        <div class="signin-panel flex-grow-1 d-flex flex-column justify-content-center">
            <div class="w-100 signin-form-wrap">
                <a href="<?= BASE_URL ?>/login.php" class="back-link small d-block mb-3">&larr; Back to Login</a>
                <h2 class="h3 mb-3">Forgot Password</h2>

                <?php if ($submitted): ?>
                    <div class="alert alert-success">
                        If that username and email match an account on file, a new password has
                        been sent to the email on file. Didn't get it? Double-check your entries,
                        or contact your registrar/admin.
                    </div>
                    <a href="<?= BASE_URL ?>/login.php" class="btn btn-neu-primary w-100">Back to Login</a>
                <?php else: ?>
                    <p class="text-muted mb-4">
                        Enter your username and the email on file. If they match, we'll email you a
                        new temporary password.
                    </p>

                    <form method="post" novalidate>
                        <div class="mb-3">
                            <label for="username" class="form-label">Username</label>
                            <input type="text" class="form-control neu-input" id="username" name="username"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                        </div>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control neu-input" id="email" name="email"
                                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                        </div>
                        <button type="submit" class="btn btn-neu-primary w-100">Send New Password</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>