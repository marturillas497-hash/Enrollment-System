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

$alreadyEnrolledThisTerm = false;
if ($currentTerm) {
    $check = $pdo->prepare('SELECT 1 FROM Enrollment WHERE student_id = :sid AND term_id = :tid');
    $check->execute(['sid' => $student['student_id'], 'tid' => $currentTerm['term_id']]);
    $alreadyEnrolledThisTerm = $check->fetch() !== false;
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canRequest) {
    $toCurriculumId = $_POST['to_curriculum_id'] ?? '';
    if ($toCurriculumId === '') {
        $error = 'Please select a program to shift into.';
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
    <?php elseif ($latestRequest && $latestRequest['status'] === 'rejected'): ?>
        <div class="alert alert-warning">
            Your last shift request (to <?= htmlspecialchars($latestRequest['program_code']) ?>) was rejected.
            <?php if ($latestRequest['remarks']): ?>Reason: <?= htmlspecialchars($latestRequest['remarks']) ?><?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$submitted && !$hasOpenRequest): ?>
        <?php if (!$currentTerm): ?>
            <div class="alert alert-secondary">No term is currently open — shift requests are only accepted during an open enrollment period.</div>
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
