<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$requestId = $_GET['id'] ?? ($_POST['request_id'] ?? null);
$error = '';
$message = flashGet('shift_msg') ?? '';

// --- Load the request under review ---
// Scoped to MY department via the FROM side — the department a student is currently in
// is the one that evaluates and approves them leaving, not the destination.
$request = null;
if ($requestId) {
    $stmt = $pdo->prepare(
        "SELECT psr.*, s.student_id_number, s.last_name, s.first_name,
                fc.curriculum_name AS from_name, fp.program_code AS from_program,
                tc.curriculum_name AS to_name, tp.program_code AS to_program, tp.program_id AS to_program_id
         FROM Program_shift_request psr
         JOIN Student s ON s.student_id = psr.student_id
         JOIN Curriculum fc ON fc.curriculum_id = psr.from_curriculum_id
         JOIN Program fp ON fp.program_id = fc.program_id
         JOIN Curriculum tc ON tc.curriculum_id = psr.to_curriculum_id
         JOIN Program tp ON tp.program_id = tc.program_id
         WHERE psr.request_id = :id AND fp.department_id = :dept"
    );
    $stmt->execute(['id' => $requestId, 'dept' => $myDepartmentId]);
    $request = $stmt->fetch();
}

if ($request && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($request['status'] !== 'pending') {
        // The UI already hides these actions once a request is decided — this is the
        // actual guard, so a resubmitted/replayed form can't re-approve or re-reject
        // (e.g. double-approve creating a second Enrollment for the same shift).
        $error = 'This request has already been ' . $request['status'] . ' and cannot be changed.';
    } elseif ($action === 'save_credits') {
        $targetSectionId = $_POST['target_section_id'] ?? '';
        $targetYearLevel = $_POST['target_year_level'] ?? '';
        $credits = $_POST['credit'] ?? []; // [enrolled_subject_id => credited_subject_id or '']

        if ($targetSectionId === '' || $targetYearLevel === '') {
            $error = 'Target section and year level are required.';
        } elseif (!ctype_digit((string)$targetYearLevel) || (int)$targetYearLevel < 1 || (int)$targetYearLevel > 4) {
            $error = 'Year level must be between 1 and 4.';
        } else {
            try {
                $pdo->beginTransaction();

                // Replace any previous evaluation for this request with the fresh selections.
                $pdo->prepare('DELETE FROM Shift_credit WHERE request_id = :id')->execute(['id' => $requestId]);

                $insertCredit = $pdo->prepare(
                    'INSERT INTO Shift_credit (request_id, enrolled_subject_id, credited_subject_id, evaluated_by)
                     VALUES (:rid, :esid, :csid, :by)'
                );
                foreach ($credits as $enrolledSubjectId => $creditedSubjectId) {
                    if ($creditedSubjectId !== '') {
                        $insertCredit->execute([
                            'rid' => $requestId, 'esid' => $enrolledSubjectId,
                            'csid' => $creditedSubjectId, 'by' => $user['account_id'],
                        ]);
                    }
                }

                $pdo->prepare(
                    "UPDATE Program_shift_request SET target_section_id = :sec, target_year_level = :yl,
                        credit_evaluation_status = 'completed' WHERE request_id = :id"
                )->execute(['sec' => $targetSectionId, 'yl' => $targetYearLevel, 'id' => $requestId]);

                $pdo->commit();
                $message = 'Credit evaluation saved.';
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = errorMessage($e, 'Could not save evaluation.');
            }
        }
    } elseif ($action === 'approve') {
        if ($request['credit_evaluation_status'] !== 'completed') {
            $error = 'Save the credit evaluation before approving.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    "INSERT INTO Enrollment (student_id, term_id, curriculum_id, section_id, year_level, date_enrolled, status, approved_by, student_standing, source_shift_request_id)
                     VALUES (:sid, :term_id, :curr_id, :sec_id, :yl, NOW(), 'approved', :by, 'irregular', :rid)"
                );
                $stmt->execute([
                    'sid' => $request['student_id'], 'term_id' => $request['effective_term_id'],
                    'curr_id' => $request['to_curriculum_id'], 'sec_id' => $request['target_section_id'],
                    'yl' => $request['target_year_level'], 'by' => $user['account_id'], 'rid' => $requestId,
                ]);
                $newEnrollmentId = (int)$pdo->lastInsertId();

                $pdo->prepare(
                    "UPDATE Program_shift_request SET status = 'approved', approved_by = :by, applied_at = NOW() WHERE request_id = :id"
                )->execute(['by' => $user['account_id'], 'id' => $requestId]);

                $pdo->commit();
                flashSet('shift_msg', $request['first_name'] . ' ' . $request['last_name'] . "'s shift to " . $request['to_program'] . ' was approved. They will choose their subjects from their dashboard.');
                header('Location: ' . BASE_URL . '/registrar/shift-requests.php?tab=approved');
                exit;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = errorMessage($e, 'Could not approve.');
            }
        }
    } elseif ($action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        if ($reason === '') {
            $error = 'A rejection reason is required.';
        } else {
            $pdo->prepare(
                "UPDATE Program_shift_request SET status = 'rejected', approved_by = :by, remarks = :reason WHERE request_id = :id"
            )->execute(['by' => $user['account_id'], 'reason' => $reason, 'id' => $requestId]);
            $message = 'Request rejected.';
            $request = null;
        }
    }

    // Refresh after a save so the form reflects what's actually stored.
    if ($request && $action === 'save_credits') {
        $stmt = $pdo->prepare('SELECT * FROM Program_shift_request WHERE request_id = :id');
        $stmt->execute(['id' => $requestId]);
        $request = array_merge($request, $stmt->fetch());
    }
}

// --- Data for the detail view ---
$passedSubjects = [];
$existingCredits = [];
$targetSections = [];
$targetSubjects = [];
if ($request) {
    // Every subject the student has actually passed, across their whole history.
    $stmt = $pdo->prepare(
        "SELECT es.enrolled_subject_id, es.grade, es.remarks, sub.subject_code, sub.subject_name
         FROM Enrolled_subject es
         JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         JOIN Subject sub ON sub.subject_id = co.subject_id
         WHERE e.student_id = :sid AND (es.remarks = 'Passed' OR (es.grade IS NOT NULL AND es.grade <= 3.00))
         ORDER BY sub.subject_code"
    );
    $stmt->execute(['sid' => $request['student_id']]);
    $passedSubjects = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT enrolled_subject_id, credited_subject_id FROM Shift_credit WHERE request_id = :id');
    $stmt->execute(['id' => $requestId]);
    foreach ($stmt->fetchAll() as $row) {
        $existingCredits[$row['enrolled_subject_id']] = $row['credited_subject_id'];
    }

    $stmt = $pdo->prepare('SELECT section_id, section_name, year_level FROM Section WHERE program_id = :pid ORDER BY year_level, section_name');
    $stmt->execute(['pid' => $request['to_program_id']]);
    $targetSections = $stmt->fetchAll();

    $targetSubjects = $pdo->query('SELECT subject_id, subject_code, subject_name FROM Subject ORDER BY subject_code')->fetchAll();

    // Informational only — doesn't block approval. Only meaningful once a target
    // section has actually been picked and saved.
    $capacityWarning = '';
    if ($request['target_section_id']) {
        $sec = $pdo->prepare('SELECT section_name, max_slots FROM Section WHERE section_id = :id');
        $sec->execute(['id' => $request['target_section_id']]);
        $sec = $sec->fetch();
        if ($sec) {
            $occupied = sectionOccupancy($pdo, (int)$request['target_section_id']);
            if ($occupied + 1 > (int)$sec['max_slots']) {
                $capacityWarning = "Heads up: {$sec['section_name']} is at {$occupied}/{$sec['max_slots']} "
                    . "— approving this shift will put it over capacity.";
            }
        }
    }
}

// --- Read-only record for a decided request ---
$decidedInfo = null;
if ($request && $request['status'] === 'approved') {
    $sec = $pdo->prepare('SELECT section_name, year_level FROM Section WHERE section_id = :id');
    $sec->execute(['id' => $request['target_section_id']]);
    $sec = $sec->fetch();

    $cr = $pdo->prepare(
        'SELECT sub.subject_code, sub.subject_name, tsub.subject_code AS old_code
         FROM Shift_credit sc
         JOIN Subject sub ON sub.subject_id = sc.credited_subject_id
         JOIN Enrolled_subject es ON es.enrolled_subject_id = sc.enrolled_subject_id
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         JOIN Subject tsub ON tsub.subject_id = co.subject_id
         WHERE sc.request_id = :id ORDER BY sub.subject_code'
    );
    $cr->execute(['id' => $requestId]);

    $cnt = $pdo->prepare(
        'SELECT COUNT(es.enrolled_subject_id) FROM Enrollment e
         LEFT JOIN Enrolled_subject es ON es.enrollment_id = e.enrollment_id
         WHERE e.source_shift_request_id = :id'
    );
    $cnt->execute(['id' => $requestId]);

    $decidedInfo = [
        'section' => $sec ? sectionLabel($sec['year_level'], $sec['section_name']) : '—',
        'credits' => $cr->fetchAll(),
        'subjects' => (int)$cnt->fetchColumn(),
    ];
}

// --- List view ---
$requests = [];
if (!$request) {
    $stmt = $pdo->prepare(
        "SELECT psr.request_id, psr.status, psr.credit_evaluation_status, psr.request_date,
                s.student_id_number, s.last_name, s.first_name, tp.program_code AS to_program,
                (SELECT COUNT(*) FROM Enrollment e JOIN Enrolled_subject es ON es.enrollment_id = e.enrollment_id
                 WHERE e.source_shift_request_id = psr.request_id) AS subject_count
         FROM Program_shift_request psr
         JOIN Student s ON s.student_id = psr.student_id
         JOIN Curriculum fc ON fc.curriculum_id = psr.from_curriculum_id
         JOIN Program fp ON fp.program_id = fc.program_id
         JOIN Curriculum tc ON tc.curriculum_id = psr.to_curriculum_id
         JOIN Program tp ON tp.program_id = tc.program_id
         WHERE fp.department_id = :dept
         ORDER BY (psr.status = 'pending') DESC, psr.request_date ASC"
    );
    $stmt->execute(['dept' => $myDepartmentId]);
    $requests = $stmt->fetchAll();

    // Tabs: the registrar acts on Pending, the other two are history.
    $tabKey = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'pending';
    if (!in_array($tabKey, ['pending', 'approved', 'rejected'], true)) { $tabKey = 'pending'; }
    $tabCounts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
    foreach ($requests as $r) {
        if (isset($tabCounts[$r['status']])) { $tabCounts[$r['status']]++; }
    }
    $visibleRequests = array_values(array_filter($requests, fn($r) => $r['status'] === $tabKey));
    if ($tabKey !== 'pending') { $visibleRequests = array_reverse($visibleRequests); } // newest decisions first
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Program Shift Requests</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="4000"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($request): ?>
        <h1 class="h4 mb-1">
            <?= htmlspecialchars($request['first_name'] . ' ' . $request['last_name']) ?>
            (<?= htmlspecialchars($request['student_id_number']) ?>)
        </h1>
        <p class="text-muted mb-3">
            Shifting <?= htmlspecialchars($request['from_program']) ?> &rarr; <strong><?= htmlspecialchars($request['to_program'] . ' — ' . $request['to_name']) ?></strong>
            <?= statusBadge($request['status']) ?>
        </p>

        <?php if ($request['status'] !== 'pending'): ?>
            <?php if ($decidedInfo): ?>
                <div class="card mb-3">
                    <div class="card-header">Approved shift record</div>
                    <div class="card-body">
                        <p class="mb-1">Placed in section: <strong><?= htmlspecialchars($decidedInfo['section']) ?></strong>, Year <?= (int)$request['target_year_level'] ?></p>
                        <p class="mb-1">Approved: <?= $request['applied_at'] ? htmlspecialchars(date('M j, Y g:i A', strtotime($request['applied_at']))) : '—' ?></p>
                        <p class="mb-3">Student's subjects:
                            <?php if ($decidedInfo['subjects'] > 0): ?>
                                <strong><?= $decidedInfo['subjects'] ?> chosen</strong>
                            <?php else: ?>
                                <span class="text-muted">waiting on the student to choose</span>
                            <?php endif; ?>
                        </p>
                        <h2 class="h6">Credited subjects</h2>
                        <?php if ($decidedInfo['credits']): ?>
                            <ul class="mb-0">
                                <?php foreach ($decidedInfo['credits'] as $cr): ?>
                                    <li><?= htmlspecialchars($cr['old_code']) ?> &rarr; <?= htmlspecialchars($cr['subject_code'] . ' — ' . $cr['subject_name']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted mb-0">None credited.</p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php elseif ($request['status'] === 'rejected'): ?>
                <div class="alert alert-info">This request was rejected<?= $request['remarks'] ? ': ' . htmlspecialchars($request['remarks']) : '.' ?></div>
            <?php else: ?>
                <div class="alert alert-info">This request is already <?= htmlspecialchars($request['status']) ?>.</div>
            <?php endif; ?>
        <?php else: ?>

        <form method="post" class="card mb-3">
            <div class="card-header">Credit Evaluation — mark which passed subjects carry over</div>
            <div class="card-body">
                <input type="hidden" name="action" value="save_credits">
                <input type="hidden" name="request_id" value="<?= $requestId ?>">

                <div class="table-responsive">
<table class="table">
                    <thead><tr><th>Previously Passed</th><th>Grade</th><th>Credit Toward (new curriculum)</th></tr></thead>
                    <tbody>
                    <?php foreach ($passedSubjects as $ps): ?>
                        <tr>
                            <td><?= htmlspecialchars($ps['subject_code'] . ' — ' . $ps['subject_name']) ?></td>
                            <td><?= htmlspecialchars($ps['grade'] ?? $ps['remarks']) ?></td>
                            <td>
                                <select class="form-select form-select-sm" name="credit[<?= $ps['enrolled_subject_id'] ?>]">
                                    <option value="">— not credited —</option>
                                    <?php foreach ($targetSubjects as $ts): ?>
                                        <option value="<?= $ts['subject_id'] ?>"
                                            <?= (($existingCredits[$ps['enrolled_subject_id']] ?? '') == $ts['subject_id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ts['subject_code'] . ' — ' . $ts['subject_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($passedSubjects)): ?>
                        <tr><td colspan="3" class="text-muted">No passed subjects on record for this student yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
</div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Target Section (in new program)</label>
                        <select class="form-select" name="target_section_id" required>
                            <option value="">Select</option>
                            <?php foreach ($targetSections as $sec): ?>
                                <option value="<?= $sec['section_id'] ?>" <?= $request['target_section_id'] == $sec['section_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(sectionLabel($sec['year_level'], $sec['section_name'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">Target Year Level</label>
                        <input type="number" min="1" max="4" class="form-control" name="target_year_level"
                               value="<?= htmlspecialchars($request['target_year_level'] ?? '1') ?>" required>
                    </div>
                </div>
                <button type="submit" class="btn btn-outline-primary">Save Credit Evaluation</button>
                <?= statusBadge($request['credit_evaluation_status']) ?>
            </div>
        </form>

        <?php if ($capacityWarning): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($capacityWarning) ?></div>
        <?php endif; ?>

        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="request_id" value="<?= $requestId ?>">
            <button type="submit" class="btn btn-success" <?= $request['credit_evaluation_status'] !== 'completed' ? 'disabled' : '' ?>
                    data-confirm="Approve this shift? The student will then choose their own subjects." data-confirm-label="Approve" data-confirm-tone="success">
                Approve Shift
            </button>
        </form>

        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="request_id" value="<?= $requestId ?>">
            <input type="text" name="reason" placeholder="Rejection reason" class="form-control d-inline-block input-w-240">
            <button type="submit" class="btn btn-outline-danger" data-confirm="Reject this shift request?" data-confirm-label="Reject" data-confirm-tone="danger">Reject</button>
        </form>

        <?php endif; ?>
        <div class="mt-3"><a href="<?= BASE_URL ?>/registrar/shift-requests.php?tab=<?= htmlspecialchars($request['status']) ?>">&larr; Back to list</a></div>

    <?php else: ?>

        <h1 class="h4 mb-3">Program Shift Requests</h1>
        <?= tabBar([
            'pending'  => ['label' => 'Pending',  'count' => $tabCounts['pending'],  'href' => '?tab=pending'],
            'approved' => ['label' => 'Approved', 'count' => $tabCounts['approved'], 'href' => '?tab=approved'],
            'rejected' => ['label' => 'Rejected', 'count' => $tabCounts['rejected'], 'href' => '?tab=rejected'],
        ], $tabKey) ?>
        <div class="table-responsive">
<table class="table table-hover bg-white">
            <thead><tr><th>Student</th><th>Target Program</th><th>Requested</th><?php if ($tabKey === 'pending'): ?><th>Credit Eval</th><?php elseif ($tabKey === 'approved'): ?><th>Subjects</th><?php endif; ?><th></th></tr></thead>
            <tbody>
            <?php foreach ($visibleRequests as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?> (<?= htmlspecialchars($r['student_id_number']) ?>)</td>
                    <td><?= htmlspecialchars($r['to_program']) ?></td>
                    <td class="text-nowrap"><?= $r['request_date'] ? htmlspecialchars(date('M j, Y', strtotime($r['request_date']))) : '' ?></td>
                    <?php if ($tabKey === 'pending'): ?><td><?= statusBadge($r['credit_evaluation_status']) ?></td><?php elseif ($tabKey === 'approved'): ?><td><?= (int)$r['subject_count'] > 0 ? (int)$r['subject_count'] . ' chosen' : '<span class="text-muted">Waiting on student</span>' ?></td><?php endif; ?>
                    <td><a href="?id=<?= $r['request_id'] ?>" class="btn btn-sm btn-outline-primary"><?= $tabKey === 'pending' ? 'Review' : 'View' ?></a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($visibleRequests)): ?>
                <tr><td colspan="5" class="text-muted">
                    <?= $tabKey === 'pending' ? 'No pending shift requests. Nothing to review.' : 'No ' . $tabKey . ' requests yet.' ?>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
</div>

    <?php endif; ?>
</div>
</body>
</html>