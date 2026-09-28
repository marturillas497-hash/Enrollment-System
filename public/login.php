<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';

// If already logged in, just send them to their dashboard
if (currentUser() !== null) {
    header('Location: ' . DASHBOARD_BY_ROLE[currentUser()['role']]);
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $selectedRole = $_POST['role'] ?? '';

    $validRoles = ['student', 'teacher', 'registrar', 'admission_staff', 'admin'];

    if ($username === '' || $password === '' || !in_array($selectedRole, $validRoles, true)) {
        $error = 'Please choose a role and enter both a username and password.';
    } else {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare(
            'SELECT account_id, username, password_hash, role, must_change_password
             FROM Accounts WHERE username = :username'
        );
        $stmt->execute(['username' => $username]);
        $account = $stmt->fetch();

        if ($account === false || !password_verify($password, $account['password_hash'])) {
            // Deliberately vague — don't reveal whether the username exists.
            $error = 'Invalid username or password.';
        } elseif ($account['role'] !== $selectedRole) {
            // Don't say what the real role is — just that this portal is wrong.
            $error = 'This account is not a ' . str_replace('_', ' ', $selectedRole) . ' account.';
        } else {
            // Prevent session fixation: get a fresh session ID on privilege change.
            session_regenerate_id(true);

            $_SESSION['user'] = [
                'account_id' => $account['account_id'],
                'username'   => $account['username'],
                'role'       => $account['role'],
            ];

            if ((int)$account['must_change_password'] === 1) {
                header('Location: ' . BASE_URL . '/change-password.php');
            } else {
                header('Location: ' . DASHBOARD_BY_ROLE[$account['role']]);
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — MIST Enrollment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
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
                <h2 class="h3 mb-4">Sign In</h2>

                <?php if ($error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="post" novalidate>
                    <div class="mb-3">
                        <label for="role" class="form-label">Login as</label>
                        <select class="form-select neu-select" id="role" name="role" required>
                            <option value="">Select a role</option>
                            <?php
                            $roleLabels = [
                                'student'         => 'Student',
                                'teacher'         => 'Instructor',
                                'registrar'       => 'Registrar',
                                'admission_staff' => 'Admission Staff',
                                'admin'           => 'Admin',
                            ];
                            $selected = $_POST['role'] ?? '';
                            foreach ($roleLabels as $value => $label):
                            ?>
                                <option value="<?= $value ?>" <?= $selected === $value ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="username" class="form-label">Username</label>
                        <input type="text" class="form-control neu-input" id="username" name="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required autofocus>
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <div class="password-field-wrap">
                            <input type="password" class="form-control neu-input pw-input" id="password" name="password" required>
                            <button type="button" id="togglePassword" tabindex="-1" class="password-toggle-btn">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <p class="mb-3"><a href="<?= BASE_URL ?>/forgot-password.php" class="small">Forgot password?</a></p>
                    <button type="submit" class="btn btn-neu-primary w-100">Sign In</button>
                </form>

                <script>
                    document.getElementById('togglePassword').addEventListener('click', function () {
                        const pw = document.getElementById('password');
                        const icon = this.querySelector('i');
                        const isHidden = pw.type === 'password';

                        pw.type = isHidden ? 'text' : 'password';
                        icon.className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
                    });
                </script>

                <hr>

                <p class="text-center mb-0 signup-line">
                    New applicant? <a href="<?= BASE_URL ?>/apply.php">Submit an Admission Application</a>
                </p>
            </div>
        </div>
    </div>
</body>
</html>