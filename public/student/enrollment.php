<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['student']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Student WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$student = $stmt->fetch();

$stmt = $pdo->prepare(
    'SELECT e.*, st.school_year, st.semester, sec.section_name
     FROM Enrollment e
     JOIN School_term st ON st.term_id = e.term_id
     JOIN Section sec ON sec.section_id = e.section_id
     WHERE e.student_id = :sid
     ORDER BY e.enrollment_id DESC LIMIT 1'
);
$stmt->execute(['sid' => $student['student_id']]);
$latestEnrollment = $stmt->fetch();

$currentTerm = $pdo->query("SELECT * FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC LIMIT 1")->fetch();

$currentTermEnrollmentStatus = null;
if ($currentTerm) {
    $stmt = $pdo->prepare('SELECT status FROM Enrollment WHERE student_id = :sid AND term_id = :tid');
    $stmt->execute(['sid' => $student['student_id'], 'tid' => $currentTerm['term_id']]);
    $currentTermEnrollmentStatus = $stmt->fetchColumn() ?: null;
}
$alreadyEnrolledThisTerm = $currentTermEnrollmentStatus !== null;

$isIrregular = $latestEnrollment && $latestEnrollment['student_standing'] === 'irregular';

$canEnroll = $student['overall_status'] === 'active' && $currentTerm && !$alreadyEnrolledThisTerm
    && $latestEnrollment && !$isIrregular;
$canEnrollIrregular = $student['overall_status'] === 'active' && $currentTerm && !$alreadyEnrolledThisTerm
    && $latestEnrollment && $isIrregular;

$stmt = $pdo->prepare("SELECT 1 FROM Program_shift_request WHERE student_id = :sid AND status = 'pending'");
$stmt->execute(['sid' => $student['student_id']]);
$hasPendingShift = $stmt->fetch() !== false;
if ($hasPendingShift) {
    $canEnroll = false;
    $canEnrollIrregular = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Enrollment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Enrollment</h1>

    <?php if ($hasPendingShift): ?>
        <div class="alert alert-info">
            You have a pending program shift request — the registrar will place you in the new
            program's enrollment once it's evaluated and approved.
            <a href="/enrollment-system/public/student/shift-request.php">View status</a>
        </div>
    <?php elseif ($canEnroll): ?>
        <div class="alert alert-primary d-flex justify-content-between align-items-center">
            <div>A new term is open: <strong><?= htmlspecialchars($currentTerm['school_year'] . ' — Semester ' . $currentTerm['semester']) ?></strong></div>
            <div>
                <a href="/enrollment-system/public/student/shift-request.php" class="btn btn-outline-primary">Request Program Shift</a>
                <a href="/enrollment-system/public/student/enroll.php" class="btn btn-primary">Enroll</a>
            </div>
        </div>
    <?php elseif ($canEnrollIrregular): ?>
        <div class="alert alert-primary d-flex justify-content-between align-items-center">
            <div>
                A new term is open: <strong><?= htmlspecialchars($currentTerm['school_year'] . ' — Semester ' . $currentTerm['semester']) ?></strong>
                — your standing is <strong>irregular</strong>, so you'll pick your own subjects this term.
            </div>
            <a href="/enrollment-system/public/student/enroll-irregular.php" class="btn btn-primary">Choose Subjects</a>
        </div>
    <?php elseif ($currentTerm && $alreadyEnrolledThisTerm && $currentTermEnrollmentStatus === 'pending'): ?>
        <div class="alert alert-info">
            Your subject selections for <?= htmlspecialchars($currentTerm['school_year'] . ' — Semester ' . $currentTerm['semester']) ?>
            are submitted and waiting on registrar approval.
        </div>
    <?php elseif ($currentTerm && $alreadyEnrolledThisTerm): ?>
        <div class="alert alert-success">You're enrolled for <?= htmlspecialchars($currentTerm['school_year'] . ' — Semester ' . $currentTerm['semester']) ?>.</div>
    <?php elseif (!$latestEnrollment): ?>
        <div class="alert alert-warning">No enrollment record found. Contact the registrar.</div>
    <?php elseif ($student['overall_status'] !== 'active'): ?>
        <div class="alert alert-warning">Your account is marked <?= htmlspecialchars($student['overall_status']) ?> — contact the registrar to enroll.</div>
    <?php endif; ?>

    <?php if ($latestEnrollment): ?>
        <div class="card">
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Current Term</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($latestEnrollment['school_year'] . ' — Semester ' . $latestEnrollment['semester']) ?></dd>
                    <dt class="col-sm-4">Section</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($latestEnrollment['section_name']) ?></dd>
                    <dt class="col-sm-4">Year Level</dt>
                    <dd class="col-sm-8"><?= $latestEnrollment['year_level'] ?></dd>
                    <dt class="col-sm-4">Standing</dt>
                    <dd class="col-sm-8"><span class="badge bg-secondary"><?= htmlspecialchars($latestEnrollment['student_standing']) ?></span></dd>
                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8"><span class="badge bg-secondary"><?= htmlspecialchars($latestEnrollment['status']) ?></span></dd>
                </dl>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
