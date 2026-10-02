<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['student']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Student WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$student = $stmt->fetch();

if ($student === false) {
    require __DIR__ . '/../../includes/navbar.php';
    echo '<div class="container"><div class="alert alert-danger">No student profile on record.</div></div>';
    exit;
}

// Most recent enrollment — tells us the student's current curriculum/program.
$stmt = $pdo->prepare(
    'SELECT e.*, c.program_id, c.curriculum_name
     FROM Enrollment e JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
     WHERE e.student_id = :sid ORDER BY e.enrollment_id DESC LIMIT 1'
);
$stmt->execute(['sid' => $student['student_id']]);
$currentEnrollment = $stmt->fetch();

$currentTerm = $pdo->query("SELECT * FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC LIMIT 1")->fetch();

$currentTermEnrollment = null;
if ($currentTerm) {
    $check = $pdo->prepare('SELECT enrollment_id, status, source_shift_request_id FROM Enrollment WHERE student_id = :sid AND term_id = :tid');
    $check->execute(['sid' => $student['student_id'], 'tid' => $currentTerm['term_id']]);
    $currentTermEnrollment = $check->fetch() ?: null;
}
$alreadyEnrolledThisTerm = $currentTermEnrollment !== null;
$pendingSelection = $currentTermEnrollment
    && $currentTermEnrollment['status'] === 'pending'
    && !$currentTermEnrollment['source_shift_request_id'];

// An existing request that hasn't been resolved yet blocks a new one.
$stmt = $pdo->prepare(
    "SELECT psr.*, c.curriculum_name, p.program_code
     FROM Program_shift_request psr
     JOIN Curriculum c ON c.curriculum_id = psr.to_curriculum_id
     JOIN Program p ON p.program_id = c.program_id
     WHERE psr.student_id = :sid ORDER BY psr.request_id DESC LIMIT 1"
);
$stmt->execute(['sid' => $student['student_id']]);
$latestRequest = $stmt->fetch();
$hasOpenRequest = $latestRequest && $latestRequest['status'] === 'pending';

$canRequest = $currentTerm && !$alreadyEnrolledThisTerm && !$hasOpenRequest
    && $student['overall_status'] === 'active' && $currentEnrollment;

$error = '';
$submitted = false;
$notice = flashGet('shift_notice');

// An irregular student's own pending subject selection can be withdrawn so they can shift instead.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'withdraw_pending' && $pendingSelection && !$hasOpenRequest) {
    try {
        $pdo->beginTransaction();
        $pdo->prepare(
            "DELETE es FROM Enrolled_subject es JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
             WHERE e.enrollment_id = :id AND e.student_id = :sid AND e.status = 'pending' AND e.source_shift_request_id IS NULL"
        )->execute(['id' => $currentTermEnrollment['enrollment_id'], 'sid' => $student['student_id']]);
        $pdo->prepare(
            "DELETE FROM Enrollment WHERE enrollment_id = :id AND student_id = :sid AND status = 'pending' AND source_shift_request_id IS NULL"
        )->execute(['id' => $currentTermEnrollment['enrollment_id'], 'sid' => $student['student_id']]);
        $pdo->commit();
        flashSet('shift_notice', 'Your pending subject selection was withdrawn. You can now request a program shift.');
        header('Location: ' . BASE_URL . '/student/shift-request.php');
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = errorMessage($e, 'Could not withdraw your selection.');
    }
}

// Curricula from OTHER programs than the one the student is currently in.
$curricula = [];
if ($currentEnrollment) {
    $stmt = $pdo->prepare(
        'SELECT c.curriculum_id, c.curriculum_name, p.program_code
         FROM Curriculum c JOIN Program p ON p.program_id = c.program_id
         WHERE c.is_active = 1 AND c.program_id != :pid'
    );
    $stmt->execute(['pid' => $currentEnrollment['program_id']]);
    $curricula = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canRequest && ($_POST['action'] ?? '') !== 'withdraw_pending') {
    $toCurriculumId = $_POST['to_curriculum_id'] ?? '';
    if ($toCurriculumId === '') {
        $error = 'Please select a program to shift into.';
    } elseif (!in_array((string)$toCurriculumId, array_map('strval', array_column($curricula, 'curriculum_id')), true)) {
        $error = 'That program is not available to shift into.';
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO Program_shift_request (student_id, from_curriculum_id, to_curriculum_id, request_date, effective_term_id, status, credit_evaluation_status)
             VALUES (:sid, :from_curr, :to_curr, CURDATE(), :term_id, 'pending', 'pending')"
        );
        $stmt->execute([
            'sid' => $student['student_id'], 'from_curr' => $currentEnrollment['curriculum_id'],
            'to_curr' => $toCurriculumId, 'term_id' => $currentTerm['term_id'],
        ]);
        $submitted = true;
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Request Program Shift</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container container-sm">
    <h1 class="h4 mb-3">Request a Program Shift</h1>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="alert alert-success"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

    <?php if ($submitted): ?>
        <div class="alert alert-success">
            Request submitted. The registrar will evaluate which of your passed subjects carry over,
            then approve or reject the shift. You'll see the outcome here.
        </div>
    <?php elseif ($latestRequest && $latestRequest['status'] === 'pending'): ?>
        <div class="alert alert-info">
            Pending request to shift into <strong><?= htmlspecialchars($latestRequest['program_code'] . ' — ' . $latestRequest['curriculum_name']) ?></strong>,
            submitted <?= htmlspecialchars($latestRequest['request_date']) ?>. Credit evaluation:
            <?= htmlspecialchars($latestRequest['credit_evaluation_status']) ?>.
        </div>
    <?php elseif ($latestRequest && $latestRequest['status'] === 'approved' && $currentTermEnrollment && (int)$currentTermEnrollment['source_shift_request_id'] === (int)$latestRequest['request_id']): ?>
        <div class="alert alert-success d-flex justify-content-between align-items-center">
            <div>Your shift to <strong><?= htmlspecialchars($latestRequest['program_code'] . ' — ' . $latestRequest['curriculum_name']) ?></strong> was approved.</div>
            <a href="<?= BASE_URL ?>/student/enroll-irregular.php" class="btn btn-primary">Choose Subjects</a>
        </div>
    <?php elseif ($latestRequest && $latestRequest['status'] === 'rejected'): ?>
        <div class="alert alert-warning">
            Your last shift request (to <?= htmlspecialchars($latestRequest['program_code']) ?>) was rejected.
            <?php if ($latestRequest['remarks']): ?>Reason: <?= htmlspecialchars($latestRequest['remarks']) ?><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$submitted && !$hasOpenRequest): ?>
        <?php if (!$currentTerm): ?>
            <div class="alert alert-secondary">No term is currently open — shift requests are only accepted during an open enrollment period.</div>
        <?php elseif ($pendingSelection): ?>
            <div class="alert alert-warning">
                You have a subject selection waiting on registrar approval for this term. To request a program shift
                instead, withdraw that selection first. You can always choose subjects again afterward if you change your mind.
            </div>
            <form method="post">
                <input type="hidden" name="action" value="withdraw_pending">
                <button type="submit" class="btn btn-outline-danger"
                        data-confirm="Withdraw your pending subject selection?" data-confirm-label="Withdraw" data-confirm-tone="danger">
                    Withdraw Pending Selection
                </button>
            </form>
        <?php elseif ($alreadyEnrolledThisTerm): ?>
            <div class="alert alert-secondary">You're already enrolled for this term. Shift requests can only be made before enrolling.</div>
        <?php elseif ($student['overall_status'] !== 'active'): ?>
            <div class="alert alert-secondary">Your account is not active — contact the registrar.</div>
        <?php elseif (empty($curricula)): ?>
            <div class="alert alert-secondary">No other active programs are available to shift into right now.</div>
        <?php else: ?>
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">Shift into</label>
                    <select class="form-select" name="to_curriculum_id" required>
                        <option value="">Select a program</option>
                        <?php foreach ($curricula as $c): ?>
                            <option value="<?= $c['curriculum_id'] ?>">
                                <?= htmlspecialchars($c['program_code'] . ' — ' . $c['curriculum_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary w-100">Submit Shift Request</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
