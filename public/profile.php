<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';

$user = requireLogin();
$pdo = getDbConnection();

$usernameError = '';
$usernameSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_username') {
    $newUsername = trim($_POST['new_username'] ?? '');

    if ($newUsername === '') {
        $usernameError = 'Username cannot be empty.';
    } elseif (strlen($newUsername) < 4) {
        $usernameError = 'Username must be at least 4 characters.';
    } elseif ($newUsername === $user['username']) {
        $usernameError = 'That is already your username.';
    } else {
        // The DB has a UNIQUE index on username, but checking first lets us show a
        // friendly message instead of letting a raw constraint violation surface.
        $check = $pdo->prepare('SELECT 1 FROM Accounts WHERE username = :u AND account_id != :id');
        $check->execute(['u' => $newUsername, 'id' => $user['account_id']]);

        if ($check->fetch() !== false) {
            $usernameError = 'That username is already taken.';
        } else {
            $pdo->prepare('UPDATE Accounts SET username = :u WHERE account_id = :id')
                ->execute(['u' => $newUsername, 'id' => $user['account_id']]);

            // Keep the live session in sync — otherwise the navbar and the rest of
            // this page would keep showing the old username until the next login.
            $_SESSION['user']['username'] = $newUsername;
            $user['username'] = $newUsername;
            $usernameSuccess = 'Username updated. Use it the next time you log in.';
        }
    }
}

$profile = null;
switch ($user['role']) {
    case 'student':
        $stmt = $pdo->prepare(
            'SELECT s.*, a.username FROM Student s JOIN Accounts a ON a.account_id = s.account_id
             WHERE s.account_id = :aid'
        );
        $stmt->execute(['aid' => $user['account_id']]);
        $profile = $stmt->fetch();
        break;
    case 'teacher':
        $stmt = $pdo->prepare(
            'SELECT t.*, a.username, d.department_name FROM Teacher t
             JOIN Accounts a ON a.account_id = t.account_id
             JOIN Department d ON d.department_id = t.department_id
             WHERE t.account_id = :aid'
        );
        $stmt->execute(['aid' => $user['account_id']]);
        $profile = $stmt->fetch();
        break;
    case 'registrar':
        $stmt = $pdo->prepare(
            'SELECT r.*, a.username, d.department_name FROM Registrar r
             JOIN Accounts a ON a.account_id = r.account_id
             JOIN Department d ON d.department_id = r.department_id
             WHERE r.account_id = :aid'
        );
        $stmt->execute(['aid' => $user['account_id']]);
        $profile = $stmt->fetch();
        break;
    case 'admission_staff':
        $stmt = $pdo->prepare(
            'SELECT sa.*, a.username FROM Admission_Staff sa
             JOIN Accounts a ON a.account_id = sa.account_id
             WHERE sa.account_id = :aid'
        );
        $stmt->execute(['aid' => $user['account_id']]);
        $profile = $stmt->fetch();
        break;
    case 'admin':
        // No profile table for admin — see the earlier design note: admins are
        // seeded directly rather than self-registering, so there's no name on file.
        $profile = null;
        break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Profile</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../includes/navbar.php'; ?>
<div class="container container-sm">
    <h1 class="h4 mb-3">My Profile</h1>

    <?php if ($usernameSuccess): ?>
        <div class="alert alert-success"><?= htmlspecialchars($usernameSuccess) ?></div>
    <?php endif; ?>
    <?php if ($usernameError): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($usernameError) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-sm-4">Username</dt>
                <dd class="col-sm-8"><?= htmlspecialchars($user['username']) ?></dd>

                <dt class="col-sm-4">Role</dt>
                <dd class="col-sm-8"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $user['role']))) ?></dd>

                <?php if ($profile && $user['role'] === 'student'): ?>
                    <dt class="col-sm-4">Student ID Number</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($profile['student_id_number']) ?></dd>
                    <dt class="col-sm-4">Full Name</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($profile['first_name'] . ' ' . $profile['middle_name'] . ' ' . $profile['last_name']) ?></dd>
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($profile['overall_status']) ?></dd>
                <?php elseif ($profile && in_array($user['role'], ['teacher', 'registrar'], true)): ?>
                    <dt class="col-sm-4">Full Name</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($profile['first_name'] . ' ' . ($profile['middle_name'] ?? '') . ' ' . $profile['last_name']) ?></dd>
                    <dt class="col-sm-4">Department</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($profile['department_name']) ?></dd>
                <?php elseif ($profile && $user['role'] === 'admission_staff'): ?>
                    <dt class="col-sm-4">Full Name</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($profile['first_name'] . ' ' . ($profile['middle_name'] ?? '') . ' ' . $profile['last_name']) ?></dd>
                <?php elseif ($user['role'] === 'admin'): ?>
                    <dd class="col-sm-12 text-muted">No additional profile information is stored for admin accounts.</dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header">Change Username</div>
        <div class="card-body">
            <form method="post" novalidate>
                <input type="hidden" name="action" value="change_username">
                <label class="form-label">New Username</label>
                <div class="d-flex gap-2">
                    <input type="text" class="form-control" name="new_username"
                           value="<?= htmlspecialchars($user['username']) ?>" minlength="4" required>
                    <button type="submit" class="btn btn-primary text-nowrap">Update</button>
                </div>
                <div class="form-text">
                    <?php if ($user['role'] === 'student'): ?>
                        Heads up: your username is normally your student ID number. Changing it won't
                        change your student ID number itself, so the two will no longer match.
                    <?php else: ?>
                        You'll use this the next time you log in — your current session stays active.
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <a href="/enrollment-system/public/change-password.php" class="btn btn-outline-primary mt-3">Change Password</a>
</div>
</body>
</html>
