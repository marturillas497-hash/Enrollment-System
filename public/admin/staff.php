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

$flash = flashGet('regenerated');
if ($flash) {
    $regenerated = $flash['regenerated'];
    $mailWarning = $flash['mailWarning'];
}

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
        $pdo->prepare('UPDATE Accounts SET password_hash = :hash, must_change_password = 1, session_version = session_version + 1 WHERE account_id = :id')
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

        flashSet('regenerated', ['regenerated' => $regenerated, 'mailWarning' => $mailWarning]);
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

// Filter + sort options. Only whitelisted values ever reach the SQL,
// nothing from the query string is interpolated directly.
$roleOptions = [
    'registrar'       => 'Registrar',
    'teacher'         => 'Teacher',
    'admission_staff' => 'Admission Staff',
];
// Names are shown as "First Last", so the name sorts use first name to match what the
// admin sees. Rows with no name on file (blank first_name) always go to the bottom.
$sortOptions = [
    'role'    => ['label' => 'Role, then name',       'sql' => 'a.role, (COALESCE(r.first_name, t.first_name, sa.first_name) IS NULL), first_name, last_name'],
    'name_az' => ['label' => 'Name (A to Z)',         'sql' => '(COALESCE(r.first_name, t.first_name, sa.first_name) IS NULL), first_name, last_name'],
    'name_za' => ['label' => 'Name (Z to A)',         'sql' => '(COALESCE(r.first_name, t.first_name, sa.first_name) IS NULL), first_name DESC, last_name DESC'],
    'last_az' => ['label' => 'Last name (A to Z)',    'sql' => '(COALESCE(r.last_name, t.last_name, sa.last_name) IS NULL), last_name, first_name'],
    'newest'  => ['label' => 'Date added (newest)',   'sql' => 'a.created_at DESC, a.account_id DESC'],
    'oldest'  => ['label' => 'Date added (oldest)',   'sql' => 'a.created_at ASC, a.account_id ASC'],
];

$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$filterRole = is_string($_GET['role'] ?? null) ? $_GET['role'] : '';
if (!isset($roleOptions[$filterRole])) { $filterRole = ''; }
$sortKey = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'role';
if (!isset($sortOptions[$sortKey])) { $sortKey = 'role'; }

// FROM/JOIN/WHERE shared by the count query and the page query, so they can't drift apart.
$fromWhere =
    "FROM Accounts a
     LEFT JOIN Registrar r ON r.account_id = a.account_id AND a.role = 'registrar'
     LEFT JOIN Teacher t ON t.account_id = a.account_id AND a.role = 'teacher'
     LEFT JOIN Admission_Staff sa ON sa.account_id = a.account_id AND a.role = 'admission_staff'
     LEFT JOIN Department d ON d.department_id = COALESCE(r.department_id, t.department_id)
     WHERE a.role IN ('registrar','teacher','admission_staff')";
$staffParams = [];
if ($search !== '') {
    // Matches on name or username. Department isn't searched here since it's shown as a
    // plain label, not something an admin is likely to search staff by.
    $fromWhere .= " AND (COALESCE(r.first_name, t.first_name, sa.first_name) LIKE :q1
                    OR COALESCE(r.last_name, t.last_name, sa.last_name) LIKE :q2
                    OR a.username LIKE :q3)";
    $staffParams['q1'] = "%$search%";
    $staffParams['q2'] = "%$search%";
    $staffParams['q3'] = "%$search%";
}
if ($filterRole !== '') {
    $fromWhere .= " AND a.role = :role";
    $staffParams['role'] = $filterRole;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) $fromWhere");
$countStmt->execute($staffParams);
$totalStaff = (int) $countStmt->fetchColumn();
$pageInfo = paginationInfo($totalStaff, 15);

$staffSql =
    "SELECT a.account_id, a.role, a.username, a.created_at,
            COALESCE(r.last_name, t.last_name, sa.last_name) AS last_name,
            COALESCE(r.first_name, t.first_name, sa.first_name) AS first_name,
            d.department_name
     $fromWhere
     ORDER BY " . $sortOptions[$sortKey]['sql'] . '
     LIMIT :limit OFFSET :offset';

$staffStmt = $pdo->prepare($staffSql);
foreach ($staffParams as $key => $value) {
    $staffStmt->bindValue(':' . $key, $value);
}
$staffStmt->bindValue(':limit', $pageInfo['perPage'], PDO::PARAM_INT);
$staffStmt->bindValue(':offset', $pageInfo['offset'], PDO::PARAM_INT);
$staffStmt->execute();
$staff = $staffStmt->fetchAll();
$isFiltered = ($search !== '' || $filterRole !== '' || $sortKey !== 'role');

$pageQueryParams = array_filter([
    'q' => $search !== '' ? $search : null,
    'role' => $filterRole !== '' ? $filterRole : null,
    'sort' => $sortKey !== 'role' ? $sortKey : null,
], fn($v) => $v !== null);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
        <div class="alert alert-success alert-dismissible fade show">
            <!-- No data-auto-dismiss here on purpose: this box shows a one-time plaintext password the person still needs to copy. See CLAUDE-UI-REDESIGN.md. -->
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
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <form method="get" class="toolbar">
        <div class="toolbar-field toolbar-field-wide">
            <label for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" placeholder="Name or username"
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="toolbar-field">
            <label for="role">Role</label>
            <select name="role" id="role" class="form-select" onchange="this.form.submit()">
                <option value="">All roles</option>
                <?php foreach ($roleOptions as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $filterRole === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label for="sort">Sort by</label>
            <select name="sort" id="sort" class="form-select" onchange="this.form.submit()">
                <?php foreach ($sortOptions as $value => $opt): ?>
                    <option value="<?= $value ?>" <?= $sortKey === $value ? 'selected' : '' ?>><?= htmlspecialchars($opt['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($isFiltered): ?><a href="staff.php" class="btn btn-outline-secondary">Reset</a><?php endif; ?>
        <span class="toolbar-count">Showing <?= count($staff) ?> of <?= $totalStaff ?> account<?= $totalStaff === 1 ? '' : 's' ?></span>
    </form>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><th>Role</th><th>Name</th><th>Department</th><th>Username</th><th>Date Added</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($staff as $s): ?>
            <tr>
                <td><?= statusBadge($s['role']) ?></td>
                <td><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
                <td><?= $s['department_name'] ? htmlspecialchars($s['department_name']) : '<span class="text-muted">—</span>' ?></td>
                <td><?= htmlspecialchars($s['username']) ?></td>
                <td class="text-nowrap"><?= $s['created_at'] ? htmlspecialchars(date('M j, Y', strtotime($s['created_at']))) : '<span class="text-muted">-</span>' ?></td>
                <td>
                    <form method="post">
                        <input type="hidden" name="action" value="regenerate_password">
                        <input type="hidden" name="account_id" value="<?= $s['account_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-warning"
                                data-confirm="Generate a new temporary password for this account? Their current password will stop working immediately."
                                data-confirm-label="Regenerate" data-confirm-tone="warning">Regenerate Password</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($staff)): ?><tr><td colspan="6" class="text-muted"><?= $isFiltered ? 'No staff accounts match this search or filter.' : 'No staff accounts yet.' ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?= paginationNav($pageInfo['page'], $pageInfo['totalPages'], $pageQueryParams) ?>
</div>
</body>
</html>