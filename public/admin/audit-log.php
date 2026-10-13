<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/audit_helper.php';

$user = requireRole(['admin']);
$pdo = getDbConnection();

$filterAction = trim((string)($_GET['action'] ?? ''));
$search = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$validDate = fn(string $d): bool => $d === '' || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if (!$validDate($from)) { $from = ''; }
if (!$validDate($to)) { $to = ''; }

$actions = $pdo->query('SELECT DISTINCT action FROM Audit_log ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
if ($filterAction !== '' && !in_array($filterAction, $actions, true)) {
    $filterAction = '';
}

$where = '1 = 1';
$params = [];
if ($filterAction !== '') {
    $where .= ' AND al.action = :action';
    $params['action'] = $filterAction;
}
if ($search !== '') {
    $where .= ' AND (al.summary LIKE :q1 OR a.username LIKE :q2)';
    $params['q1'] = '%' . $search . '%';
    $params['q2'] = '%' . $search . '%';
}
if ($from !== '') {
    $where .= ' AND al.created_at >= :from';
    $params['from'] = $from . ' 00:00:00';
}
if ($to !== '') {
    $where .= ' AND al.created_at <= :to';
    $params['to'] = $to . ' 23:59:59';
}

$fromSql = 'FROM Audit_log al LEFT JOIN Accounts a ON a.account_id = al.actor_account_id';
$stmt = $pdo->prepare("SELECT COUNT(*) $fromSql WHERE $where");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pageInfo = paginationInfo($total, 15);

$stmt = $pdo->prepare(
    "SELECT al.*, a.username $fromSql WHERE $where ORDER BY al.audit_id DESC LIMIT :limit OFFSET :offset"
);
foreach ($params as $key => $value) {
    $stmt->bindValue(':' . $key, $value);
}
$stmt->bindValue(':limit', $pageInfo['perPage'], PDO::PARAM_INT);
$stmt->bindValue(':offset', $pageInfo['offset'], PDO::PARAM_INT);
$stmt->execute();
$entries = $stmt->fetchAll();

$pageParams = array_filter([
    'action' => $filterAction !== '' ? $filterAction : null,
    'q' => $search !== '' ? $search : null,
    'from' => $from !== '' ? $from : null,
    'to' => $to !== '' ? $to : null,
], fn($v) => $v !== null);
$isFiltered = $pageParams !== [];
$e = fn($v): string => htmlspecialchars((string)$v);

/** Pretty JSON for the details row, or '' when there is nothing stored. */
$pretty = function (?string $json) use ($e): string {
    if ($json === null || $json === '') { return ''; }
    $data = json_decode($json, true);
    return $e(is_array($data) ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $json);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audit Log</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-1">Audit Log</h1>
    <p class="text-muted small mb-3">A record of grade entries and corrections, placements, approvals, status changes and account deactivations. Newest first. It cannot be edited.</p>

    <form method="get" class="row g-2 align-items-end mb-3">
        <div class="col-12 col-md-3">
            <label class="form-label small mb-1" for="fAction">Action</label>
            <select class="form-select" id="fAction" name="action">
                <option value="">All actions</option>
                <?php foreach ($actions as $a): ?><option value="<?= $e($a) ?>"<?= $a === $filterAction ? ' selected' : '' ?>><?= $e(auditActionLabel($a)) ?></option><?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label class="form-label small mb-1" for="fQ">Search</label>
            <input type="search" class="form-control" id="fQ" name="q" value="<?= $e($search) ?>" placeholder="Name, ID number or username">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="fFrom">From</label>
            <input type="date" class="form-control" id="fFrom" name="from" value="<?= $e($from) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="fTo">To</label>
            <input type="date" class="form-control" id="fTo" name="to" value="<?= $e($to) ?>">
        </div>
        <div class="col-12 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary">Filter</button>
            <?php if ($isFiltered): ?><a class="btn btn-outline-secondary" href="?">Clear</a><?php endif; ?>
        </div>
    </form>

    <div class="table-responsive">
    <table class="table bg-white align-middle">
        <thead><tr><th class="text-nowrap">When</th><th>Who</th><th>Action</th><th>What happened</th></tr></thead>
        <tbody>
        <?php foreach ($entries as $row): $before = $pretty($row['before_json']); $after = $pretty($row['after_json']); ?>
            <tr>
                <td class="text-nowrap small"><?= $e($row['created_at']) ?></td>
                <td class="small">
                    <?= $row['username'] !== null ? $e($row['username']) : '<span class="text-muted">Deleted account</span>' ?>
                    <?php if ($row['actor_role']): ?><div class="text-muted"><?= $e(str_replace('_', ' ', $row['actor_role'])) ?></div><?php endif; ?>
                </td>
                <td><?= statusBadge('neutral', auditActionLabel($row['action']), 'info') ?></td>
                <td>
                    <?= $e($row['summary']) ?>
                    <?php if ($before !== '' || $after !== ''): ?>
                        <details class="small mt-1">
                            <summary class="text-muted">Details</summary>
                            <div class="row g-2 mt-1">
                                <?php if ($before !== ''): ?><div class="col-12 col-md-6"><div class="text-muted">Before</div><pre class="mb-0 small"><?= $before ?></pre></div><?php endif; ?>
                                <?php if ($after !== ''): ?><div class="col-12 col-md-6"><div class="text-muted">After</div><pre class="mb-0 small"><?= $after ?></pre></div><?php endif; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$entries): ?><tr><td colspan="4" class="text-muted"><?= $isFiltered ? 'Nothing matches those filters.' : 'Nothing has been logged yet.' ?></td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
    <p class="text-muted small"><?= $total ?> entr<?= $total === 1 ? 'y' : 'ies' ?><?= $isFiltered ? ' match' : '' ?>.</p>
    <?= paginationNav($pageInfo['page'], $pageInfo['totalPages'], $pageParams) ?>
</div>
</body>
</html>
