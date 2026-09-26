<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/username_helper.php';
require_once __DIR__ . '/../../src/helpers/password_helper.php';
require_once __DIR__ . '/../../src/helpers/mail_helper.php';

$user = requireRole(['admin']);
$pdo = getDbConnection();

$error = '';
$created = null;
$mailWarning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lastName   = trim($_POST['last_name'] ?? '');
    $firstName  = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $suffix     = trim($_POST['suffix'] ?? '');
    $email      = trim($_POST['email'] ?? '');

    if ($lastName === '' || $firstName === '' || $email === '') {
        $error = 'Last name, first name, and email are required.';
    } else {
        try {
            $pdo->beginTransaction();

            $username = generateUniqueUsername($pdo, $lastName, $firstName);
            $tempPassword = generateTempPassword();

            $stmt = $pdo->prepare(
                "INSERT INTO Accounts (username, email, password_hash, role, must_change_password)
                 VALUES (:username, :email, :hash, 'admission_staff', 1)"
            );
            $stmt->execute(['username' => $username, 'email' => $email, 'hash' => password_hash($tempPassword, PASSWORD_DEFAULT)]);
            $newAccountId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'INSERT INTO Admission_Staff (account_id, last_name, first_name, middle_name, suffix)
                 VALUES (:account_id, :last_name, :first_name, :middle_name, :suffix)'
            );
            $stmt->execute([
                'account_id' => $newAccountId, 'last_name' => $lastName, 'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'suffix' => $suffix !== '' ? $suffix : null,
            ]);

            $pdo->commit();
            $created = ['username' => $username, 'password' => $tempPassword, 'email' => $email];

            $mailSent = sendAccountCredentialsEmail($email, $firstName . ' ' . $lastName, $username, $tempPassword);
            if (!$mailSent) {
                $mailWarning = 'The account was created, but the credentials email could not be sent. Share the credentials below manually.';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not create the admission staff account. ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register Admission Staff</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php require __DIR__ . '/../../includes/navbar.php'; ?>
    <div class="container container-sm">
        <h1 class="h3 mb-4">Register Admission Staff</h1>

        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if ($mailWarning): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($mailWarning) ?></div>
        <?php endif; ?>

        <?php if ($created): ?>
            <div class="card border-success mb-4">
                <div class="card-body">
                    <h2 class="h5 card-title text-success">Admission staff account created</h2>
                    <?php if (!$mailWarning): ?>
                        <p class="card-text text-success">
                            Email has been sent to <strong><?= htmlspecialchars($created['email']) ?></strong>.
                        </p>
                    <?php endif; ?>
                    <p class="card-text text-muted">
                        This password will not be shown again — if it's lost, it has to be reset,
                        not retrieved.
                    </p>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Username</dt>
                        <dd class="col-sm-8"><code><?= htmlspecialchars($created['username']) ?></code></dd>
                        <dt class="col-sm-4">Temporary Password</dt>
                        <dd class="col-sm-8"><code><?= htmlspecialchars($created['password']) ?></code></dd>
                    </dl>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/admin/register-admission-staff.php" class="btn btn-outline-primary">
                Register Another
            </a>
        <?php else: ?>
            <form method="post" novalidate>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Last Name</label>
                        <input type="text" class="form-control" name="last_name"
                               value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">First Name</label>
                        <input type="text" class="form-control" name="first_name"
                               value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Middle Name <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control" name="middle_name"
                               value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Suffix <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control" name="suffix"
                               value="<?= htmlspecialchars($_POST['suffix'] ?? '') ?>" placeholder="Jr., III, etc.">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" name="email"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Create Admission Staff Account</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>