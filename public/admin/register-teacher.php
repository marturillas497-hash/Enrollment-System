<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/username_helper.php';
require_once __DIR__ . '/../../src/helpers/password_helper.php';
require_once __DIR__ . '/../../src/helpers/mail_helper.php';
require_once __DIR__ . '/../../src/helpers/invite_helper.php';

$user = requireRole(['admin']);
$pdo = getDbConnection();

$error = '';
$created = null;
$mailWarning = '';
$duplicates = [];

$flash = flashGet('staff_created');
if ($flash) {
    $created = $flash['created'];
    $mailWarning = $flash['mailWarning'];
}

$departments = $pdo->query('SELECT department_id, department_name FROM Department ORDER BY department_name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lastName     = trim($_POST['last_name'] ?? '');
    $firstName    = trim($_POST['first_name'] ?? '');
    $middleName   = trim($_POST['middle_name'] ?? '');
    $suffix       = trim($_POST['suffix'] ?? '');
    $departmentId = $_POST['department_id'] ?? '';
    $email        = trim($_POST['email'] ?? '');

    if ($lastName === '' || $firstName === '' || $departmentId === '' || $email === '') {
        $error = 'Last name, first name, department, and email are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
        $error = 'Enter a valid email address.';
    } elseif (!isset($_POST['confirm_duplicate']) && ($duplicates = findDuplicateStaff($pdo, $firstName, $lastName, $email))) {
        // form re-renders below with the warning
    } else {
        try {
            $pdo->beginTransaction();

            $username = generateUniqueUsername($pdo, $lastName, $firstName);
            $placeholder = placeholderPasswordHash();

            // 1. Accounts row — role=teacher, no password until the invite link is used.
            $stmt = $pdo->prepare(
                "INSERT INTO Accounts (username, email, password_hash, role, must_change_password)
                 VALUES (:username, :email, :hash, 'teacher', 0)"
            );
            $stmt->execute([
                'username' => $username,
                'email'    => $email,
                'hash'     => $placeholder,
            ]);
            $newAccountId = (int)$pdo->lastInsertId();

            // 2. Teacher profile row — department assignment.
            $stmt = $pdo->prepare(
                'INSERT INTO Teacher (account_id, department_id, last_name, first_name, middle_name, suffix)
                 VALUES (:account_id, :department_id, :last_name, :first_name, :middle_name, :suffix)'
            );
            $stmt->execute([
                'account_id'    => $newAccountId,
                'department_id' => $departmentId,
                'last_name'     => $lastName,
                'first_name'    => $firstName,
                'middle_name'   => $middleName !== '' ? $middleName : null,
                'suffix'        => $suffix !== '' ? $suffix : null,
            ]);

            $pdo->commit();

            $invite = sendAccountLink($pdo, $newAccountId);
            $created = ['username' => $username, 'email' => $email];
            if ($invite['result'] !== 'sent') {
                $mailWarning = 'The account was created, but the invite email could not be sent. Open Staff Accounts and use Resend invite once the address is confirmed.';
            }

            flashSet('staff_created', ['created' => $created, 'mailWarning' => $mailWarning]);
            header('Location: ' . BASE_URL . '/admin/register-teacher.php');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = errorMessage($e, 'Could not create the teacher account.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register Teacher</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
    <?php require __DIR__ . '/../../includes/navbar.php'; ?>
    <div class="container container-sm">

        <h1 class="h3 mb-4">Register a Teacher</h1>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($mailWarning): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($mailWarning) ?></div>
        <?php endif; ?>

        <?php if ($created): ?>
            <div class="card border-success mb-4">
                <div class="card-body">
                    <h2 class="h5 card-title text-success">Teacher account created</h2>
                    <?php if (!$mailWarning): ?>
                        <p class="card-text text-success">
                            An invite was emailed to <strong><?= htmlspecialchars($created['email']) ?></strong>. They have 24 hours to open it and choose their own password.
                        </p>
                    <?php endif; ?>
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Username</dt>
                        <dd class="col-sm-8"><code><?= htmlspecialchars($created['username']) ?></code></dd>
                    </dl>
                </div>
            </div>
            <a href="<?= BASE_URL ?>/admin/register-teacher.php" class="btn btn-outline-primary">
                Register Another
            </a>

        <?php elseif (empty($departments)): ?>
            <div class="alert alert-warning">
                No departments exist yet. Add at least one row to the <code>Department</code> table
                in phpMyAdmin before registering a teacher.
            </div>

        <?php else: ?>
            <form method="post" novalidate>
                <?php if ($duplicates): ?>
                    <div class="alert alert-warning">
                        <strong>Possible duplicate.</strong> A staff account with the same name or email already exists:
                        <ul class="mb-2">
                            <?php foreach ($duplicates as $d): ?>
                                <li><code><?= htmlspecialchars($d['username']) ?></code> (<?= htmlspecialchars(str_replace('_', ' ', $d['role'])) ?>, <?= htmlspecialchars($d['email'] ?? 'no email') ?>)</li>
                            <?php endforeach; ?>
                        </ul>
                        If this is a different person, press the button again to create the account anyway.
                    </div>
                    <input type="hidden" name="confirm_duplicate" value="1">
                <?php endif; ?>
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
                <div class="mb-3">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department_id" required>
                        <option value="">Select a department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['department_id'] ?>"<?= (string)($_POST['department_id'] ?? '') === (string)$dept['department_id'] ? ' selected' : '' ?>>
                                <?= htmlspecialchars($dept['department_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary w-100"><?= $duplicates ? 'Create Anyway' : 'Create Teacher Account' ?></button>
            </form>
        <?php endif; ?>

    </div>
</body>
</html>