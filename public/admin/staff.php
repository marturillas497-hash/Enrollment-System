<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/mail_helper.php';
require_once __DIR__ . '/../../src/helpers/invite_helper.php';

$user = requireRole(['admin']);
$pdo = getDbConnection();

$error = '';
$modalError = '';
$mailWarning = flashGet('staff_warn') ?? '';
$staffMessage = flashGet('staff_msg');

$staffRoles = ['registrar' => 'Registrar', 'teacher' => 'Teacher', 'admission_staff' => 'Admission_Staff'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_link') {
    $accountId = ctype_digit((string)($_POST['account_id'] ?? '')) ? (int)$_POST['account_id'] : 0;

    $stmt = $pdo->prepare("SELECT account_id FROM Accounts WHERE account_id = :id AND role IN ('registrar','teacher','admission_staff')");
    $stmt->execute(['id' => $accountId]);

    if ($stmt->fetch() === false) {
        $error = 'Staff account not found.';
    } else {
        [$ok, $text] = accountLinkMessage(sendAccountLink($pdo, $accountId));
        flashSet($ok ? 'staff_msg' : 'staff_warn', $text);
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
}

/*
 * Edit details and activate or deactivate. Both only ever touch staff roles, never admin
 * or student accounts. Deactivating also bumps session_version so open sessions end at once.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['update_staff', 'set_active'], true)) {
    $action = $_POST['action'];
    $accountId = ctype_digit((string)($_POST['account_id'] ?? '')) ? (int)$_POST['account_id'] : 0;

    $stmt = $pdo->prepare("SELECT account_id, role, username, email, activated_at, is_active FROM Accounts WHERE account_id = :id AND role IN ('registrar','teacher','admission_staff')");
    $stmt->execute(['id' => $accountId]);
    $target = $stmt->fetch();

    if (!$target) {
        $modalError = 'Staff account not found.';
    } elseif ($action === 'set_active') {
        $makeActive = ($_POST['active'] ?? '') === '1';
        $pdo->prepare('UPDATE Accounts SET is_active = :a, session_version = session_version + 1 WHERE account_id = :id')
            ->execute(['a' => $makeActive ? 1 : 0, 'id' => $accountId]);
        flashSet('staff_msg', $target['username'] . ($makeActive ? ' was reactivated.' : ' was deactivated and signed out.'));
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    } else {
        $first = trim($_POST['first_name'] ?? '');
        $middle = trim($_POST['middle_name'] ?? '');
        $last = trim($_POST['last_name'] ?? '');
        $suffix = trim($_POST['suffix'] ?? '');
        $email = trim($_POST['email'] ?? '');

        $dup = $pdo->prepare("SELECT 1 FROM Accounts WHERE LOWER(email) = LOWER(:e) AND account_id <> :id AND role IN ('registrar','teacher','admission_staff')");
        $dup->execute(['e' => $email, 'id' => $accountId]);

        if ($first === '' || $last === '') {
            $modalError = 'First name and last name are required.';
        } elseif (max(strlen($first), strlen($middle), strlen($last), strlen($suffix)) > 100) {
            $modalError = 'Names must be 100 characters or fewer.';
        } elseif ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $modalError = 'Enter a valid email address.';
        } elseif ($dup->fetch() !== false) {
            $modalError = 'Another staff account already uses that email.';
        } else {
            try {
                $emailChanged = strcasecmp($email, (string)($target['email'] ?? '')) !== 0;
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE Accounts SET email = :e WHERE account_id = :id')->execute(['e' => $email, 'id' => $accountId]);
                $table = $staffRoles[$target['role']];
                $pdo->prepare("UPDATE {$table} SET first_name = :f, middle_name = :m, last_name = :l, suffix = :s WHERE account_id = :id")
                    ->execute(['f' => $first, 'm' => $middle !== '' ? $middle : null, 'l' => $last, 's' => $suffix !== '' ? $suffix : null, 'id' => $accountId]);
                if ($emailChanged) {
                    voidOpenPasswordResets($pdo, $accountId);
                    $pdo->prepare('INSERT INTO Account_email_change (account_id, changed_by, old_email, new_email) VALUES (:a, :by, :old, :new)')
                        ->execute(['a' => $accountId, 'by' => $user['account_id'], 'old' => ($target['email'] ?? '') !== '' ? $target['email'] : null, 'new' => $email]);
                }
                $pdo->commit();

                $msg = 'Details saved for ' . $target['username'] . '.';
                if ($emailChanged) {
                    $msg .= ' Their earlier password links were cancelled.';
                    if ($target['activated_at'] === null && (int)$target['is_active'] === 1) {
                        [$ok, $text] = accountLinkMessage(sendAccountLink($pdo, $accountId));
                        if ($ok) {
                            $msg .= ' ' . $text;
                        } else {
                            flashSet('staff_warn', $text . ' Use Resend invite.');
                        }
                    }
                    if (($target['email'] ?? '') !== '') {
                        sendEmailChangedNotice($target['email'], trim($first . ' ' . $last), $target['username'], maskEmail($email));
                    }
                }
                flashSet('staff_msg', $msg);
                header('Location: ' . $_SERVER['REQUEST_URI']);
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $modalError = errorMessage($e, 'Could not save the changes.');
            }
        }
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
    "SELECT a.account_id, a.role, a.username, a.email, a.is_active, a.activated_at, a.created_at,
            COALESCE(r.last_name, t.last_name, sa.last_name) AS last_name,
            COALESCE(r.first_name, t.first_name, sa.first_name) AS first_name,
            COALESCE(r.middle_name, t.middle_name, sa.middle_name) AS middle_name,
            COALESCE(r.suffix, t.suffix, sa.suffix) AS suffix,
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
$linkStates = accountActivationStatuses($pdo, array_column($staff, 'account_id'));
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
    <?php if ($modalError && empty($target)): ?><div class="alert alert-danger"><?= htmlspecialchars($modalError) ?></div><?php endif; ?>
    <?php if ($staffMessage): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="5000"><?= htmlspecialchars($staffMessage) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>

    <?php if ($mailWarning): ?>
        <div class="alert alert-warning"><?= htmlspecialchars($mailWarning) ?></div>
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
        <thead><tr><th>Role</th><th>Name</th><th>Department</th><th>Username</th><th>Status</th><th>Date Added</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($staff as $s): ?>
            <tr>
                <td><?= statusBadge($s['role']) ?></td>
                <td><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
                <td><?= $s['department_name'] ? htmlspecialchars($s['department_name']) : '<span class="text-muted">—</span>' ?></td>
                <td><?= htmlspecialchars($s['username']) ?></td>
                <td><?php
                    $ls = $linkStates[(int)$s['account_id']] ?? 'active';
                    if ((int)$s['is_active'] !== 1) { echo statusBadge('inactive'); }
                    elseif ($ls === 'pending') { echo '<span class="badge text-bg-warning">Invite pending</span>'; }
                    elseif ($ls === 'expired') { echo '<span class="badge text-bg-danger">Invite expired</span>'; }
                    else { echo statusBadge('active'); }
                ?></td>
                <td class="text-nowrap"><?= $s['created_at'] ? htmlspecialchars(date('M j, Y', strtotime($s['created_at']))) : '<span class="text-muted">-</span>' ?></td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-primary js-view-staff" title="View details" aria-label="View details"
                            data-staff="<?= htmlspecialchars(json_encode([
                                'account_id' => (int)$s['account_id'], 'username' => $s['username'],
                                'role' => ucwords(str_replace('_', ' ', $s['role'])), 'department' => $s['department_name'] ?? '',
                                'first_name' => $s['first_name'], 'middle_name' => $s['middle_name'] ?? '',
                                'last_name' => $s['last_name'], 'suffix' => $s['suffix'] ?? '',
                                'email' => $s['email'] ?? '', 'active' => (int)$s['is_active'] === 1, 'link_state' => $linkStates[(int)$s['account_id']] ?? 'active',
                                'created' => $s['created_at'] ? date('M j, Y', strtotime($s['created_at'])) : '',
                            ]), ENT_QUOTES) ?>"><i class="bi bi-eye"></i></button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($staff)): ?><tr><td colspan="7" class="text-muted"><?= $isFiltered ? 'No staff accounts match this search or filter.' : 'No staff accounts yet.' ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?= paginationNav($pageInfo['page'], $pageInfo['totalPages'], $pageQueryParams) ?>
</div>

<div class="modal fade" id="staffModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Staff Details</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if ($modalError): ?><div class="alert alert-danger js-modal-alert"><?= htmlspecialchars($modalError) ?></div><?php endif; ?>
        <dl class="row mb-3">
            <dt class="col-sm-3">Username</dt><dd class="col-sm-9" id="sdUsername"></dd>
            <dt class="col-sm-3">Role</dt><dd class="col-sm-9" id="sdRole"></dd>
            <dt class="col-sm-3">Department</dt><dd class="col-sm-9" id="sdDepartment"></dd>
            <dt class="col-sm-3">Status</dt><dd class="col-sm-9" id="sdStatus"></dd>
            <dt class="col-sm-3">Date Added</dt><dd class="col-sm-9 mb-0" id="sdCreated"></dd>
        </dl>

        <form method="post" id="staffEditForm" class="row g-2">
            <input type="hidden" name="action" value="update_staff">
            <input type="hidden" name="account_id" id="sdEditId">
            <div class="col-md-6"><label class="form-label" for="sdFirst">First name</label><input class="form-control" id="sdFirst" name="first_name" maxlength="100" required></div>
            <div class="col-md-6"><label class="form-label" for="sdMiddle">Middle name</label><input class="form-control" id="sdMiddle" name="middle_name" maxlength="100"></div>
            <div class="col-md-6"><label class="form-label" for="sdLast">Last name</label><input class="form-control" id="sdLast" name="last_name" maxlength="100" required></div>
            <div class="col-md-6"><label class="form-label" for="sdSuffix">Suffix</label><input class="form-control" id="sdSuffix" name="suffix" maxlength="100"></div>
            <div class="col-12"><label class="form-label" for="sdEmail">Email</label><input type="email" class="form-control" id="sdEmail" name="email" maxlength="255" required></div>
            <div class="col-12">
                <button type="button" class="btn btn-primary" id="sdSaveBtn">Save changes</button>
                <div class="alert alert-warning small mt-2 mb-0 d-none js-inline-confirm" id="sdEmailWarn">
                    <div class="fw-semibold mb-1">Change this account's email?</div>
                    <div class="mb-1"><span id="sdOldEmail"></span> &rarr; <span id="sdNewEmail"></span></div>
                    <div class="mb-2" id="sdEmailWarnText"></div>
                    <button type="submit" class="btn btn-sm btn-warning">Confirm change</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary js-inline-cancel">Go back</button>
                </div>
            </div>
        </form>

        <hr>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="fw-semibold mb-1">Account access</div>
                    <p class="small text-muted" id="sdAccessNote"></p>
                    <form method="post">
                        <input type="hidden" name="action" value="set_active">
                        <input type="hidden" name="account_id" id="sdActiveId">
                        <input type="hidden" name="active" id="sdActiveValue">
                        <button type="button" class="btn btn-sm" id="sdActiveBtn"></button>
                        <div class="alert alert-warning small mt-2 mb-0 d-none js-inline-confirm" id="sdActiveConfirm">
                            <div class="mb-2" id="sdActiveConfirmText"></div>
                            <button type="submit" class="btn btn-sm btn-danger" id="sdActiveYes"></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary js-inline-cancel">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="col-md-6">
                <div class="border rounded p-3 h-100">
                    <div class="fw-semibold mb-1">Sign-in link</div>
                    <p class="small text-muted" id="sdLinkNote"></p>
                    <form method="post">
                        <input type="hidden" name="action" value="send_link">
                        <input type="hidden" name="account_id" id="sdLinkId">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="sdLinkBtn"></button>
                        <div class="alert alert-warning small mt-2 mb-0 d-none js-inline-confirm" id="sdLinkConfirm">
                            <div class="mb-2" id="sdLinkConfirmText"></div>
                            <button type="submit" class="btn btn-sm btn-primary" id="sdLinkYes"></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary js-inline-cancel">Cancel</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
    var modalEl = document.getElementById('staffModal');

    var currentEmail = '';
    var currentLinkState = 'active';
    var linkAllowed = true;

    function resetConfirms() {
        modalEl.querySelectorAll('.js-inline-confirm').forEach(function (box) { box.classList.add('d-none'); });
        ['sdActiveBtn', 'sdSaveBtn'].forEach(function (id) { document.getElementById(id).classList.remove('d-none'); });
        document.getElementById('sdLinkBtn').classList.toggle('d-none', !linkAllowed);
    }

    function setText(id, value) { document.getElementById(id).textContent = value || '-'; }

    function openStaff(d, posted) {
        var v = posted || d;
        setText('sdUsername', d.username);
        setText('sdRole', d.role);
        setText('sdDepartment', d.department);
        var stateLabels = { active: 'Active', pending: 'Invite pending', expired: 'Invite expired' };
        setText('sdStatus', d.active ? (stateLabels[d.link_state] || 'Active') : 'Inactive');
        currentEmail = d.email || '';
        currentLinkState = d.link_state || 'active';
        linkAllowed = !!d.active;
        setText('sdCreated', d.created);
        ['sdEditId', 'sdActiveId', 'sdLinkId'].forEach(function (id) { document.getElementById(id).value = d.account_id; });
        document.getElementById('sdFirst').value = v.first_name || '';
        document.getElementById('sdMiddle').value = v.middle_name || '';
        document.getElementById('sdLast').value = v.last_name || '';
        document.getElementById('sdSuffix').value = v.suffix || '';
        document.getElementById('sdEmail').value = v.email || '';

        var btn = document.getElementById('sdActiveBtn');
        document.getElementById('sdActiveValue').value = d.active ? '0' : '1';
        btn.textContent = d.active ? 'Deactivate account' : 'Reactivate account';
        btn.className = 'btn btn-sm ' + (d.active ? 'btn-outline-danger' : 'btn-outline-success');
        document.getElementById('sdActiveConfirmText').textContent = d.active
            ? 'Deactivate this account? They are signed out right away and cannot log in until reactivated. Their records are kept.'
            : 'Reactivate this account so they can log in again?';
        var yes = document.getElementById('sdActiveYes');
        yes.textContent = d.active ? 'Deactivate' : 'Reactivate';
        yes.className = 'btn btn-sm ' + (d.active ? 'btn-danger' : 'btn-success');
        var activated = currentLinkState === 'active';
        var target = d.email || 'this account';
        document.getElementById('sdLinkBtn').textContent = activated ? 'Send reset link' : 'Resend invite';
        document.getElementById('sdLinkYes').textContent = activated ? 'Send reset link' : 'Resend invite';
        document.getElementById('sdLinkConfirmText').textContent = activated
            ? 'Email a reset link, valid for 30 minutes, to ' + target + '? Their current password keeps working until they use it.'
            : 'Email a fresh 24-hour invite to ' + target + '? Any earlier invite stops working.';
        document.getElementById('sdLinkNote').textContent = !d.active
            ? 'Reactivate the account before sending links.'
            : activated
                ? 'Emails them a link to choose a new password. The link is never shown here.'
                : (currentLinkState === 'pending'
                    ? 'They have not chosen a password yet. You can send the invite again.'
                    : 'Their invite expired. Send a fresh one.');
        resetConfirms();
        document.getElementById('sdAccessNote').textContent = d.active
            ? 'Deactivated accounts cannot log in or receive password reset emails.'
            : 'This account is deactivated and cannot log in.';

        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    document.querySelectorAll('.js-view-staff').forEach(function (button) {
        button.addEventListener('click', function () {
            openStaff(JSON.parse(button.getAttribute('data-staff')), null);
        });
    });

    /* Confirm dialogs cannot stack on a modal, so these confirm inline instead. */
    [['sdActiveBtn', 'sdActiveConfirm'], ['sdLinkBtn', 'sdLinkConfirm']].forEach(function (pair) {
        document.getElementById(pair[0]).addEventListener('click', function () {
            this.classList.add('d-none');
            document.getElementById(pair[1]).classList.remove('d-none');
        });
    });
    document.getElementById('sdSaveBtn').addEventListener('click', function () {
        var form = document.getElementById('staffEditForm');
        if (!form.reportValidity()) { return; }
        var next = document.getElementById('sdEmail').value.trim();
        if (next.toLowerCase() === currentEmail.trim().toLowerCase()) { form.submit(); return; }
        document.getElementById('sdOldEmail').textContent = currentEmail || '(none)';
        document.getElementById('sdNewEmail').textContent = next;
        document.getElementById('sdEmailWarnText').textContent =
            'Whoever controls the new address can reset this account\'s password. Earlier password links stop working and the old address is notified.'
            + (currentLinkState === 'active' ? '' : ' A fresh invite goes to the new address.');
        this.classList.add('d-none');
        document.getElementById('sdEmailWarn').classList.remove('d-none');
    });
    modalEl.querySelectorAll('.js-inline-cancel').forEach(function (b) { b.addEventListener('click', resetConfirms); });
    modalEl.addEventListener('hidden.bs.modal', function () {
        modalEl.querySelectorAll('.js-modal-alert').forEach(function (a) { a.remove(); });
    });

<?php if ($modalError && !empty($target)): ?>
    document.addEventListener('DOMContentLoaded', function () {
        var targetId = <?= (int)$target['account_id'] ?>;
        var posted = <?= json_encode(array_intersect_key($_POST, array_flip(['first_name', 'middle_name', 'last_name', 'suffix', 'email'])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        document.querySelectorAll('.js-view-staff').forEach(function (button) {
            var d = JSON.parse(button.getAttribute('data-staff'));
            if (d.account_id === targetId) { openStaff(d, posted); }
        });
    });
<?php endif; ?>
})();
</script>
</body>
</html>