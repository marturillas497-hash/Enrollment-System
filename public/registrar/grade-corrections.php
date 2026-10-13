<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/correction_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT registrar_id, department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$registrar = $stmt->fetch();
$deptId = (int)$registrar['department_id'];

$error = '';
$message = flashGet('corr_msg') ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $correctionId = (int)($_POST['correction_id'] ?? 0);
    if ($action === 'approve') {
        [$ok, $text] = correctionApprove($pdo, $user, $registrar, $correctionId);
    } elseif ($action === 'reject') {
        [$ok, $text] = correctionReject($pdo, $user, $registrar, $correctionId, (string)($_POST['reason'] ?? ''));
    } else {
        [$ok, $text] = [false, 'Unknown action.'];
    }
    if ($ok) {
        flashSet('corr_msg', $text);
        header('Location: ' . BASE_URL . '/registrar/grade-corrections.php');
        exit;
    }
    $error = $text;
}

$tab = in_array($_GET['tab'] ?? '', ['pending', 'decided', 'flags'], true) ? $_GET['tab'] : 'pending';
$search = trim((string)($_GET['q'] ?? ''));

$fromSql = 'FROM Grade_correction gc
            JOIN Enrolled_subject es ON es.enrolled_subject_id = gc.enrolled_subject_id
            JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
            JOIN Student s ON s.student_id = e.student_id
            JOIN Class_Offering co ON co.offering_id = es.offering_id
            JOIN Subject sub ON sub.subject_id = co.subject_id
            JOIN Section sec ON sec.section_id = co.section_id
            JOIN Program p ON p.program_id = sec.program_id
            JOIN School_term st ON st.term_id = e.term_id
            JOIN Teacher t ON t.teacher_id = gc.requested_by
            LEFT JOIN Registrar r ON r.registrar_id = gc.decided_by';

$stmt = $pdo->prepare("SELECT COUNT(*) $fromSql WHERE gc.status = 'pending' AND p.department_id = :dept");
$stmt->execute(['dept' => $deptId]);
$pendingCount = (int)$stmt->fetchColumn();

$flagFrom = 'FROM Grade_correction_flag f
             JOIN Grade_correction gc ON gc.correction_id = f.correction_id
             JOIN Enrolled_subject es ON es.enrolled_subject_id = gc.enrolled_subject_id
             JOIN Class_Offering co ON co.offering_id = es.offering_id
             JOIN Section sec ON sec.section_id = co.section_id
             JOIN Program p ON p.program_id = sec.program_id
             JOIN Student s ON s.student_id = f.student_id
             JOIN Subject sub ON sub.subject_id = co.subject_id';
$stmt = $pdo->prepare("SELECT COUNT(*) $flagFrom WHERE f.resolved_at IS NULL AND p.department_id = :dept");
$stmt->execute(['dept' => $deptId]);
$flagCount = (int)$stmt->fetchColumn();

$rows = [];
$flags = [];
$pageInfo = ['page' => 1, 'perPage' => 15, 'offset' => 0, 'totalPages' => 1];
if ($tab === 'flags') {
    $stmt = $pdo->prepare(
        "SELECT f.flag_id, f.message, f.created_at, f.student_id, s.student_id_number, s.first_name, s.last_name, sub.subject_code
         $flagFrom WHERE f.resolved_at IS NULL AND p.department_id = :dept ORDER BY f.flag_id"
    );
    $stmt->execute(['dept' => $deptId]);
    $flags = $stmt->fetchAll();
} else {
    $where = ($tab === 'pending' ? "gc.status = 'pending'" : "gc.status <> 'pending'") . ' AND p.department_id = :dept';
    $params = ['dept' => $deptId];
    if ($search !== '') {
        $where .= ' AND (s.last_name LIKE :q1 OR s.first_name LIKE :q2 OR s.student_id_number LIKE :q3 OR sub.subject_code LIKE :q4)';
        foreach (['q1', 'q2', 'q3', 'q4'] as $key) {
            $params[$key] = '%' . $search . '%';
        }
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) $fromSql WHERE $where");
    $stmt->execute($params);
    $pageInfo = paginationInfo((int)$stmt->fetchColumn(), 15);

    $stmt = $pdo->prepare(
        "SELECT gc.*, s.student_id AS sid, s.student_id_number, s.first_name, s.last_name,
                sub.subject_code, sub.subject_name, sec.section_name, st.school_year, st.semester,
                t.first_name AS t_first, t.last_name AS t_last, r.first_name AS r_first, r.last_name AS r_last
         $fromSql WHERE $where
         ORDER BY " . ($tab === 'pending' ? 'gc.requested_at ASC' : 'gc.decided_at DESC, gc.correction_id DESC') . '
         LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $pageInfo['perPage'], PDO::PARAM_INT);
    $stmt->bindValue(':offset', $pageInfo['offset'], PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

$pageParams = array_filter(['tab' => $tab !== 'pending' ? $tab : null, 'q' => $search !== '' ? $search : null], fn($v) => $v !== null);
$e = fn($v): string => htmlspecialchars((string)$v);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grade Corrections</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-1">Grade Corrections</h1>
    <p class="text-muted small mb-3">Teachers ask to correct a grade after its term has closed. The grade changes only when you approve. Approving recalculates that term's standing and flags anything later that relied on the old result. Nothing is undone for you.</p>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="12000"><?= $e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

    <?= tabBar([
        'pending' => ['label' => 'Pending', 'count' => $pendingCount, 'href' => '?tab=pending'],
        'decided' => ['label' => 'Decided', 'count' => null, 'href' => '?tab=decided'],
        'flags' => ['label' => 'Flags to review', 'count' => $flagCount, 'href' => '?tab=flags'],
    ], $tab) ?>

    <?php if ($tab === 'flags'): ?>
        <p class="text-muted small">These later enrollments or statuses may have relied on a result that was corrected. Open the student, check them and mark each flag as reviewed. Nothing was changed automatically.</p>
        <div class="table-responsive">
        <table class="table bg-white">
            <thead><tr><th>Student</th><th>Corrected subject</th><th>What to review</th><th>Flagged</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($flags as $f): ?>
                <tr>
                    <td><?= $e($f['first_name'] . ' ' . $f['last_name']) ?><div class="text-muted small"><?= $e($f['student_id_number']) ?></div></td>
                    <td><?= $e($f['subject_code']) ?></td>
                    <td><?= $e($f['message']) ?></td>
                    <td class="text-nowrap"><?= $e(substr((string)$f['created_at'], 0, 10)) ?></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/registrar/student-view.php?id=<?= (int)$f['student_id'] ?>&amp;tab=general">Open student</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$flags): ?><tr><td colspan="5" class="text-muted">Nothing to review.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    <?php else: ?>
        <form method="get" class="d-flex gap-2 mb-3">
            <input type="hidden" name="tab" value="<?= $e($tab) ?>">
            <input type="search" class="form-control" name="q" value="<?= $e($search) ?>" placeholder="Search by student name, ID number or subject code" aria-label="Search corrections">
            <button type="submit" class="btn btn-outline-secondary">Search</button>
            <?php if ($search !== ''): ?><a class="btn btn-outline-secondary" href="?tab=<?= $e($tab) ?>">Clear</a><?php endif; ?>
        </form>

        <div class="table-responsive">
        <table class="table bg-white align-middle">
            <thead>
            <tr>
                <th>Student</th><th>Class</th><th>Change</th><th>Reason</th>
                <?php if ($tab === 'pending'): ?><th>Requested</th><th></th><?php else: ?><th>Outcome</th><th>Decided</th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>/registrar/student-view.php?id=<?= (int)$c['sid'] ?>&amp;tab=subjects"><?= $e($c['first_name'] . ' ' . $c['last_name']) ?></a>
                        <div class="text-muted small"><?= $e($c['student_id_number']) ?></div>
                    </td>
                    <td>
                        <strong><?= $e($c['subject_code']) ?></strong> <span class="text-muted small"><?= $e($c['section_name']) ?></span>
                        <div class="text-muted small"><?= $e($c['school_year'] . ' Semester ' . $c['semester']) ?> · <?= $e($c['t_first'] . ' ' . $c['t_last']) ?></div>
                    </td>
                    <td class="text-nowrap">
                        <?= $e(correctionResultLabel($c['old_grade'], $c['old_remarks'])) ?>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                        <strong><?= $e(correctionResultLabel($c['new_grade'], $c['new_remarks'])) ?></strong>
                    </td>
                    <td class="small"><?= $e($c['reason']) ?></td>
                    <?php if ($tab === 'pending'): ?>
                        <td class="text-nowrap small"><?= $e(substr((string)$c['requested_at'], 0, 10)) ?></td>
                        <td class="text-nowrap">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="approve">
                                <input type="hidden" name="correction_id" value="<?= (int)$c['correction_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-success" data-confirm="Approve this correction? The grade changes at once and this term's standing is recalculated." data-confirm-label="Approve" data-confirm-tone="success">Approve</button>
                            </form>
                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal"
                                    data-id="<?= (int)$c['correction_id'] ?>" data-name="<?= $e($c['first_name'] . ' ' . $c['last_name'] . ' · ' . $c['subject_code']) ?>">Reject</button>
                        </td>
                    <?php else: ?>
                        <td>
                            <?= statusBadge($c['status']) ?>
                            <?php if ($c['status'] === 'rejected' && $c['decision_reason']): ?><div class="small text-muted mt-1"><?= $e($c['decision_reason']) ?></div><?php endif; ?>
                        </td>
                        <td class="small text-nowrap">
                            <?= $e(substr((string)$c['decided_at'], 0, 10)) ?>
                            <?php if ($c['r_last']): ?><div class="text-muted"><?= $e($c['r_first'] . ' ' . $c['r_last']) ?></div><?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-muted"><?= $tab === 'pending' ? 'No corrections are waiting for you.' : 'Nothing decided yet.' ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
        <?= paginationNav($pageInfo['page'], $pageInfo['totalPages'], $pageParams) ?>
    <?php endif; ?>
</div>

<div class="modal fade" id="rejectModal" tabindex="-1" aria-labelledby="rejectModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="correction_id" id="rejectId" value="">
            <div class="modal-header">
                <h2 class="modal-title h5" id="rejectModalLabel">Reject this correction</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong id="rejectName"></strong></p>
                <label class="form-label small" for="rejectReason">Reason (the teacher sees this)</label>
                <textarea class="form-control" id="rejectReason" name="reason" rows="3" maxlength="500" required></textarea>
                <div class="form-text">The grade stays as it is.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger">Reject</button>
            </div>
        </form>
    </div>
</div>
<script>
    (function () {
        var modal = document.getElementById('rejectModal');
        if (!modal) { return; }
        modal.addEventListener('show.bs.modal', function (ev) {
            var b = ev.relatedTarget;
            document.getElementById('rejectId').value = b ? b.getAttribute('data-id') : '';
            document.getElementById('rejectName').textContent = b ? b.getAttribute('data-name') : '';
            document.getElementById('rejectReason').value = '';
        });
    })();
</script>
</body>
</html>
