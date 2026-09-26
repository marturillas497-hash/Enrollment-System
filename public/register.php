<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

// Guard rail: this bypasses every real business rule in the PRD (admin creates
// registrars, registrar admits students, etc.) and only touches Accounts —
// no Student/Teacher/Registrar/Admission_Staff profile row gets created.
// It exists purely so Phase 4 (auth) can be tested before Phases 6-7 (the real
// account-creation workflows) are built. Delete this file once those exist.
if (APP_ENV !== 'development') {
    http_response_code(404);
    die('Not found.');
}

$error = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    $validRoles = ['student', 'teacher', 'registrar', 'admission_staff', 'admin'];

    if ($username === '' || $password === '' || !in_array($role, $validRoles, true)) {
        $error = 'Fill in all fields and pick a valid role.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $pdo = getDbConnection();

        $check = $pdo->prepare('SELECT 1 FROM Accounts WHERE username = :u');
        $check->execute(['u' => $username]);

        if ($check->fetch() !== false) {
            $error = 'That username is already taken.';
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO Accounts (username, password_hash, role, must_change_password)
                 VALUES (:username, :hash, :role, 0)'
            );
            $stmt->execute([
                'username' => $username,
                'hash'     => password_hash($password, PASSWORD_DEFAULT),
                'role'     => $role,
            ]);
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dev Test Account Creator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <div class="container container-xs">
        <div class="py-5">
            <a href="<?= BASE_URL ?>/login.php" class="d-inline-block mb-3">&larr; Back</a>
            <div class="alert alert-warning">
                <strong>Dev-only.</strong> Creates a bare <code>Accounts</code> row with no profile.
                Real accounts get created by the admin/registrar/system flows built in later phases.
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="alert alert-success">
                    Test account created. <a href="<?= BASE_URL ?>/login.php">Log in</a>
                </div>
            <?php else: ?>
                <form method="post" novalidate>
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" name="username"
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password</label>
                        <input type="password" class="form-control" name="password" required minlength="8">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <select class="form-select" name="role" required>
                            <option value="">Select a role</option>
                            <option value="student">student</option>
                            <option value="teacher">teacher</option>
                            <option value="registrar">registrar</option>
                            <option value="admission_staff">admission_staff</option>
                            <option value="admin">admin</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Create Test Account</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
