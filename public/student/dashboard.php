<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';

$user = requireRole(['student']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Student WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$student = $stmt->fetch();

if ($student === false) {
    require __DIR__ . '/../../includes/navbar.php';
    echo '<div class="container"><div class="alert alert-danger">'
        . 'This account has no student profile on record. If this was created through the dev test '
        . 'account creator, that tool only makes a bare login — it was never placed as a real student. '
        . 'Contact the registrar.</div></div>';
    exit;
}

// Most recent enrollment (any term), for carrying curriculum/section/year-level forward.
$stmt = $pdo->prepare(
    'SELECT e.*, st.school_year, st.semester, sec.section_name
     FROM Enrollment e
     JOIN School_term st ON st.term_id = e.term_id
     JOIN Section sec ON sec.section_id = e.section_id
     WHERE e.student_id = :sid AND e.status <> \'rejected\'
     ORDER BY e.enrollment_id DESC LIMIT 1'
);
$stmt->execute(['sid' => $student['student_id']]);
$latestEnrollment = $stmt->fetch();

// Is there a term open right now, and what's the status of this student's enrollment in it (if any)?
$currentTerm = $pdo->query("SELECT * FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC LIMIT 1")->fetch();

/*
 * Starting over after a rejected selection: the rejected enrollment and its subject rows are
 * removed so the student can pick again or request a program shift for the open term.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restart' && $currentTerm) {
    try {
        $pdo->beginTransaction();
        $find = $pdo->prepare(
            "SELECT enrollment_id FROM Enrollment WHERE student_id = :sid AND term_id = :tid AND status = 'rejected' FOR UPDATE"
        );
        $find->execute(['sid' => $student['student_id'], 'tid' => $currentTerm['term_id']]);
        $rejectedId = $find->fetchColumn();
        if ($rejectedId) {
            $pdo->prepare('DELETE FROM Enrolled_subject WHERE enrollment_id = :id')->execute(['id' => $rejectedId]);
            $pdo->prepare('DELETE FROM Enrollment WHERE enrollment_id = :id')->execute(['id' => $rejectedId]);
        }
        $pdo->commit();
        flashSet('dash_msg', 'Your rejected selection was cleared. You can choose subjects again or request a program shift.');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        flashSet('dash_err', errorMessage($e, 'Could not clear the rejected selection.'));
    }
    header('Location: ' . BASE_URL . '/student/dashboard.php');
    exit;
}

$dashMessage = flashGet('dash_msg');
$dashError = flashGet('dash_err');

$currentTermEnrollmentStatus = null;
$rejectionReason = '';
if ($currentTerm) {
    $stmt = $pdo->prepare('SELECT status, rejection_reason FROM Enrollment WHERE student_id = :sid AND term_id = :tid');
    $stmt->execute(['sid' => $student['student_id'], 'tid' => $currentTerm['term_id']]);
    $currentRow = $stmt->fetch();
    $currentTermEnrollmentStatus = $currentRow ? $currentRow['status'] : null;
    $rejectionReason = $currentRow ? (string)$currentRow['rejection_reason'] : '';
}
$alreadyEnrolledThisTerm = $currentTermEnrollmentStatus !== null;

$isIrregular = $latestEnrollment && $latestEnrollment['student_standing'] === 'irregular';

$needsShiftSubjects = false;
if ($currentTerm && $latestEnrollment && $latestEnrollment['source_shift_request_id']
    && (int)$latestEnrollment['term_id'] === (int)$currentTerm['term_id'] && $latestEnrollment['status'] === 'approved') {
    $count = $pdo->prepare('SELECT COUNT(*) FROM Enrolled_subject WHERE enrollment_id = :eid');
    $count->execute(['eid' => $latestEnrollment['enrollment_id']]);
    $needsShiftSubjects = (int)$count->fetchColumn() === 0;
}

$canEnroll = $student['overall_status'] === 'active' && $currentTerm && !$alreadyEnrolledThisTerm
    && $latestEnrollment && !$isIrregular;
$canEnrollIrregular = $student['overall_status'] === 'active' && $currentTerm && !$alreadyEnrolledThisTerm
    && $latestEnrollment && $isIrregular;

// A pending shift request blocks BOTH self-enroll paths — the registrar's shift
// approval will create this term's Enrollment row instead, once evaluated.
$stmt = $pdo->prepare("SELECT 1 FROM Program_shift_request WHERE student_id = :sid AND status = 'pending'");
$stmt->execute(['sid' => $student['student_id']]);
$hasPendingShift = $stmt->fetch() !== false;
if ($hasPendingShift) {
    $canEnroll = false;
    $canEnrollIrregular = false;
}

$pastFinalYear = false;
if ($canEnroll && $currentTerm && $latestEnrollment) {
    $nextYear = nextYearLevel((int)$latestEnrollment['year_level'], $currentTerm, (string)$latestEnrollment['school_year']);
    if ($nextYear > curriculumMaxYear($pdo, (int)$latestEnrollment['curriculum_id'])) {
        $pastFinalYear = true;
        $canEnroll = false;
    }
}

// Credited subjects — from an approved program shift AND/OR transferee credit evaluation.
// Both can apply to the same student over time, so both are checked and merged.
$creditedSubjects = [];
if ($latestEnrollment && $latestEnrollment['source_shift_request_id']) {
    $stmt = $pdo->prepare(
        'SELECT sub.subject_code, sub.subject_name, "Credited from previous program" AS source_note
         FROM Shift_credit sc JOIN Subject sub ON sub.subject_id = sc.credited_subject_id
         WHERE sc.request_id = :rid'
    );
    $stmt->execute(['rid' => $latestEnrollment['source_shift_request_id']]);
    $creditedSubjects = array_merge($creditedSubjects, $stmt->fetchAll());
}
if ($student['student_type'] === 'transferee') {
    $stmt = $pdo->prepare(
        "SELECT sub.subject_code, sub.subject_name,
                CONCAT('Credited from ', tc.previous_school) AS source_note
         FROM Transferee_credit tc JOIN Subject sub ON sub.subject_id = tc.credited_subject_id
         WHERE tc.student_id = :sid"
    );
    $stmt->execute(['sid' => $student['student_id']]);
    $creditedSubjects = array_merge($creditedSubjects, $stmt->fetchAll());
}

// Subjects under the most recent enrollment, with grades if posted.
$subjects = [];
if ($latestEnrollment) {
    $stmt = $pdo->prepare(
        'SELECT es.*, s.subject_code, s.subject_name, s.units, t.last_name, t.first_name,
                co.day_of_week, co.start_time, co.end_time, co.room
         FROM Enrolled_subject es
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         JOIN Subject s ON s.subject_id = co.subject_id
         JOIN Teacher t ON t.teacher_id = co.teacher_id
         WHERE es.enrollment_id = :eid
         ORDER BY s.subject_code'
    );
    $stmt->execute(['eid' => $latestEnrollment['enrollment_id']]);
    $subjects = $stmt->fetchAll();
}

$totalUnits = 0;
foreach ($subjects as $s) {
    $totalUnits += (float)$s['units'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Student Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-1"><?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></h1>
    <p class="text-muted"><?= htmlspecialchars($student['student_id_number']) ?> — <?= htmlspecialchars($student['overall_status']) ?></p>

    <?php if ($dashMessage): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="5000"><?= htmlspecialchars($dashMessage) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($dashError): ?><div class="alert alert-danger"><?= htmlspecialchars($dashError) ?></div><?php endif; ?>

    <?php if ($hasPendingShift): ?>
        <div class="alert alert-warning">
            <i class="bi bi-hourglass-split"></i> You have a pending program shift request. The registrar will evaluate your credits and approve or reject it,
            then you'll choose your subjects for the new program.
            <a href="<?= BASE_URL ?>/student/shift-request.php">View status</a>
        </div>
    <?php elseif ($needsShiftSubjects): ?>
        <div class="alert alert-success d-flex justify-content-between align-items-center">
            <div>Your program shift was approved. Choose your subjects for <strong><?= htmlspecialchars($currentTerm['school_year'] . ' — Semester ' . $currentTerm['semester']) ?></strong>.</div>
            <a href="<?= BASE_URL ?>/student/enroll-irregular.php" class="btn btn-primary">Choose Subjects</a>
        </div>
    <?php elseif ($canEnroll): ?>
        <div class="alert alert-primary d-flex justify-content-between align-items-center">
            <div>A new term is open: <strong><?= htmlspecialchars($currentTerm['school_year'] . ' — Semester ' . $currentTerm['semester']) ?></strong></div>
            <div>
                <a href="<?= BASE_URL ?>/student/shift-request.php" class="btn btn-outline-primary">Request Program Shift</a>
                <a href="<?= BASE_URL ?>/student/enroll.php" class="btn btn-primary">Enroll</a>
            </div>
        </div>
    <?php elseif ($canEnrollIrregular): ?>
        <div class="alert alert-primary d-flex justify-content-between align-items-center">
            <div>
                A new term is open: <strong><?= htmlspecialchars($currentTerm['school_year'] . ' — Semester ' . $currentTerm['semester']) ?></strong>
                — your standing is <strong>irregular</strong>, so you'll pick your own subjects this term.
            </div>
            <div>
                <a href="<?= BASE_URL ?>/student/shift-request.php" class="btn btn-outline-primary">Request Program Shift</a>
                <a href="<?= BASE_URL ?>/student/enroll-irregular.php" class="btn btn-primary">Choose Subjects</a>
            </div>
        </div>
    <?php elseif ($pastFinalYear): ?>
        <div class="alert alert-info">
            You have gone through every year of your curriculum. Ask the registrar about your graduation.
        </div>
    <?php elseif ($currentTerm && $currentTermEnrollmentStatus === 'rejected'): ?>
        <div class="alert alert-danger d-flex justify-content-between align-items-center gap-3">
            <div>
                Your subject selection for <strong><?= htmlspecialchars($currentTerm['school_year'] . ', Semester ' . $currentTerm['semester']) ?></strong> was rejected.
                <?php if ($rejectionReason !== ''): ?><div class="mt-1">Reason: <?= htmlspecialchars($rejectionReason) ?></div><?php endif; ?>
                <div class="small mt-1">Start over to pick new subjects or request a program shift.</div>
            </div>
            <form method="post">
                <input type="hidden" name="action" value="restart">
                <button type="submit" class="btn btn-primary text-nowrap">Start Over</button>
            </form>
        </div>
    <?php elseif ($currentTerm && $alreadyEnrolledThisTerm && $currentTermEnrollmentStatus === 'pending'): ?>
        <div class="alert alert-warning">
            <i class="bi bi-hourglass-split"></i> Your subject selections for <?= htmlspecialchars($currentTerm['school_year'] . ' — Semester ' . $currentTerm['semester']) ?>
            are submitted and waiting on registrar approval.
            <a href="<?= BASE_URL ?>/student/shift-request.php">Want to shift programs instead?</a>
        </div>
    <?php elseif ($currentTerm && $alreadyEnrolledThisTerm): ?>
        <div class="alert alert-success">You're enrolled for <?= htmlspecialchars($currentTerm['school_year'] . ' — Semester ' . $currentTerm['semester']) ?>.</div>
    <?php elseif (!$latestEnrollment): ?>
        <div class="alert alert-warning">No enrollment record found. Contact the registrar.</div>
    <?php elseif ($student['overall_status'] === 'graduated'): ?>
        <div class="alert alert-success">You have graduated. Congratulations!</div>
    <?php elseif ($student['overall_status'] !== 'active'): ?>
        <div class="alert alert-warning">Your account is marked <?= htmlspecialchars($student['overall_status']) ?> — contact the registrar to enroll.</div>
    <?php endif; ?>

    <?php if ($latestEnrollment): ?>
        <div class="card mb-3">
            <div class="card-header">
                <?= htmlspecialchars($latestEnrollment['school_year'] . ' — Semester ' . $latestEnrollment['semester']) ?>
                — Section <?= htmlspecialchars($latestEnrollment['section_name']) ?>, Year <?= $latestEnrollment['year_level'] ?>
                <?= statusBadge($latestEnrollment['student_standing']) ?>
                <span class="badge bg-light text-dark"><?= rtrim(rtrim(number_format($totalUnits, 2), '0'), '.') ?> units</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
<table class="table mb-0">
                    <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Instructor</th><th>Schedule</th><th>Grade</th></tr></thead>
                    <tbody>
                    <?php foreach ($creditedSubjects as $c): ?>
                        <tr class="table-info">
                            <td colspan="2"><?= htmlspecialchars($c['subject_code'] . ' — ' . $c['subject_name']) ?></td>
                            <td colspan="3" class="text-muted"><?= htmlspecialchars($c['source_note']) ?></td>
                            <td><?= statusBadge('credited') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach ($subjects as $s): ?>
                        <tr>
                            <td><?= htmlspecialchars($s['subject_code']) ?></td>
                            <td><?= htmlspecialchars($s['subject_name']) ?></td>
                            <td><?= $s['units'] ?></td>
                            <td><?= htmlspecialchars($s['last_name'] . ', ' . $s['first_name']) ?></td>
                            <td><?= htmlspecialchars(formatSchedule($s['day_of_week'], $s['start_time'], $s['end_time'], $s['room'] ?? '')) ?></td>
                            <td><?= $s['grade'] !== null ? htmlspecialchars($s['grade']) : '<span class="text-muted">—</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($subjects) && empty($creditedSubjects)): ?><tr><td colspan="6" class="text-muted">No subjects on record.</td></tr><?php endif; ?>
                    </tbody>
                </table>
</div>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
