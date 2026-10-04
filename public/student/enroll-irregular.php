<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';
require_once __DIR__ . '/../../src/helpers/picker_helper.php';

$user = requireRole(['student']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Student WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$student = $stmt->fetch();

$stmt = $pdo->prepare(
    'SELECT * FROM Enrollment WHERE student_id = :sid ORDER BY enrollment_id DESC LIMIT 1'
);
$stmt->execute(['sid' => $student['student_id']]);
$latestEnrollment = $stmt->fetch();

$currentTerm = $pdo->query("SELECT * FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC LIMIT 1")->fetch();

$error = '';
$submitted = false;

// Right after an approved shift the student's new enrollment exists but has no subjects yet;
// this page fills that enrollment instead of creating a pending one.
$shiftMode = false;
if ($latestEnrollment && $currentTerm && $latestEnrollment['source_shift_request_id']
    && (int)$latestEnrollment['term_id'] === (int)$currentTerm['term_id'] && $latestEnrollment['status'] === 'approved') {
    $count = $pdo->prepare('SELECT COUNT(*) FROM Enrolled_subject WHERE enrollment_id = :eid');
    $count->execute(['eid' => $latestEnrollment['enrollment_id']]);
    $shiftMode = (int)$count->fetchColumn() === 0;
}

// --- Re-verify every eligibility condition server-side — this guards the write,
// the dashboard's version of these checks is only for what button to show. ---
$eligible = true;
if (!$currentTerm) { $eligible = false; $error = 'No term is currently open.'; }
elseif (!$latestEnrollment) { $eligible = false; $error = 'No prior enrollment found — contact the registrar.'; }
elseif ($latestEnrollment['student_standing'] !== 'irregular') { $eligible = false; $error = 'This page is only for irregular-standing students.'; }
elseif ($student['overall_status'] !== 'active') { $eligible = false; $error = 'Your account is not active for enrollment.'; }

if ($eligible) {
    $check = $pdo->prepare('SELECT 1 FROM Enrollment WHERE student_id = :sid AND term_id = :tid');
    $check->execute(['sid' => $student['student_id'], 'tid' => $currentTerm['term_id']]);
    if (!$shiftMode && $check->fetch() !== false) { $eligible = false; $error = 'You are already enrolled for this term.'; }

    $shiftCheck = $pdo->prepare("SELECT 1 FROM Program_shift_request WHERE student_id = :sid AND status = 'pending'");
    $shiftCheck->execute(['sid' => $student['student_id']]);
    if ($shiftCheck->fetch() !== false) { $eligible = false; $error = 'You have a pending program shift request.'; }
}

// --- Eligible subjects: curriculum minus anything taken or credited, with prerequisite and offering checks. ---
$subjectRows = $eligible ? buildPickerRows($pdo, (int)$student['student_id'], (int)$latestEnrollment['curriculum_id'], (int)$currentTerm['term_id']) : [];

// --- Handle submission ---
if ($eligible && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $toInsert = pickerSelectedOfferings($subjectRows, $_POST);

    if (empty($toInsert)) {
        $error = 'Select at least one subject.';
    } elseif (($clash = findScheduleConflict($pdo, $toInsert)) !== null) {
        $error = $clash;
    } else {
            try {
                $pdo->beginTransaction();

                if ($shiftMode) {
                    $pdo->prepare('SELECT enrollment_id FROM Enrollment WHERE enrollment_id = :eid FOR UPDATE')
                        ->execute(['eid' => $latestEnrollment['enrollment_id']]);
                    $count = $pdo->prepare('SELECT COUNT(*) FROM Enrolled_subject WHERE enrollment_id = :eid');
                    $count->execute(['eid' => $latestEnrollment['enrollment_id']]);
                    if ((int)$count->fetchColumn() > 0) {
                        $pdo->rollBack();
                        throw new RuntimeException('Subjects were already chosen for this enrollment.');
                    }
                    $newEnrollmentId = (int)$latestEnrollment['enrollment_id'];
                } else {
                    $stmt = $pdo->prepare(
                        "INSERT INTO Enrollment (student_id, term_id, curriculum_id, section_id, year_level, date_enrolled, status, student_standing)
                         VALUES (:sid, :term_id, :curr_id, :sec_id, :yl, NOW(), 'pending', 'irregular')"
                    );
                    $stmt->execute([
                        'sid' => $student['student_id'], 'term_id' => $currentTerm['term_id'],
                        'curr_id' => $latestEnrollment['curriculum_id'], 'sec_id' => $latestEnrollment['section_id'],
                        'yl' => $latestEnrollment['year_level'],
                    ]);
                    $newEnrollmentId = (int)$pdo->lastInsertId();
                }

                $insertStmt = $pdo->prepare('INSERT INTO Enrolled_subject (enrollment_id, offering_id) VALUES (:eid, :oid)');
                foreach ($toInsert as $offeringId) {
                    $insertStmt->execute(['eid' => $newEnrollmentId, 'oid' => $offeringId]);
                }

                $pdo->commit();
                $submitted = true;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = $e instanceof RuntimeException ? $e->getMessage() : errorMessage($e, 'Could not submit your selections.');
            }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Choose Subjects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container container-lg">
    <h1 class="h4 mb-3">Choose Your Subjects</h1>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($submitted): ?>
        <div class="alert alert-success">
            <?php if ($shiftMode): ?>
                Your subjects have been added to your new enrollment. They're on your dashboard now.
            <?php else: ?>
                Your subject selections have been submitted and are waiting on registrar approval.
                You'll see them on your dashboard once approved.
            <?php endif; ?>
        </div>
        <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn btn-primary">Back to Dashboard</a>

    <?php elseif ($eligible): ?>
        <?php if ($shiftMode): ?>
            <div class="alert alert-info">
                Your program shift was approved. Pick your subjects for the new program below. This is saved
                straight to your enrollment with no further approval, and you can only submit once.
            </div>
        <?php endif; ?>
        <p class="text-muted">
            Since your standing is irregular, you pick your own subjects each term. A subject is only
            selectable if you've passed its prerequisite (if it has one) and a class is actually offered
            this term.
        </p>

        <form method="post">
            <?php $pickerRows = $subjectRows; require __DIR__ . '/../../includes/subject_picker.php'; ?>
            <?php if (!empty($subjectRows)): ?>
                <button type="submit" class="btn btn-primary"><?= $shiftMode ? 'Save My Subjects' : 'Submit for Approval' ?></button>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
    <?php endif; ?>
</div>
</body>
</html>