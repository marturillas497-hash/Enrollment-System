<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';

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
    if ($check->fetch() !== false) { $eligible = false; $error = 'You are already enrolled for this term.'; }

    $shiftCheck = $pdo->prepare("SELECT 1 FROM Program_shift_request WHERE student_id = :sid AND status = 'pending'");
    $shiftCheck->execute(['sid' => $student['student_id']]);
    if ($shiftCheck->fetch() !== false) { $eligible = false; $error = 'You have a pending program shift request.'; }
}

// --- Build the eligible subject list: curriculum subjects minus anything already
// taken/credited, each checked against Prerequisite and against this term's offerings. ---
$subjectRows = [];
if ($eligible) {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT cs.subject_id, s.subject_code, s.subject_name, s.units
         FROM Curriculum_subject cs JOIN Subject s ON s.subject_id = cs.subject_id
         WHERE cs.curriculum_id = :curr_id
         ORDER BY s.subject_code'
    );
    $stmt->execute(['curr_id' => $latestEnrollment['curriculum_id']]);
    $curriculumSubjects = $stmt->fetchAll();

    foreach ($curriculumSubjects as $subj) {
        if (hasTakenOrCreditedSubject($pdo, $student['student_id'], (int)$subj['subject_id'])) {
            continue; // already completed one way or another — don't offer it again
        }

        // Prerequisite check
        $prereqStmt = $pdo->prepare(
            'SELECT r.subject_id, r.subject_code, r.subject_name
             FROM Prerequisite p JOIN Subject r ON r.subject_id = p.prerequisite_subject_id
             WHERE p.subject_id = :sid'
        );
        $prereqStmt->execute(['sid' => $subj['subject_id']]);
        $prereqs = $prereqStmt->fetchAll();

        $missingPrereqs = [];
        foreach ($prereqs as $req) {
            if (!hasCompletedSubject($pdo, $student['student_id'], (int)$req['subject_id'])) {
                $missingPrereqs[] = $req['subject_code'];
            }
        }

        // Offerings for this subject, this term, any section (irregular students aren't
        // locked into one home section — that's the whole point of this flow).
        $offeringStmt = $pdo->prepare(
            'SELECT co.offering_id, co.day_of_week, co.start_time, co.end_time, co.room, sec.section_name
             FROM Class_Offering co JOIN Section sec ON sec.section_id = co.section_id
             WHERE co.subject_id = :subid AND co.term_id = :term_id'
        );
        $offeringStmt->execute(['subid' => $subj['subject_id'], 'term_id' => $currentTerm['term_id']]);
        $offerings = $offeringStmt->fetchAll();

        $subjectRows[] = [
            'subject_id' => $subj['subject_id'],
            'subject_code' => $subj['subject_code'],
            'subject_name' => $subj['subject_name'],
            'units' => $subj['units'],
            'locked' => !empty($missingPrereqs) || empty($offerings),
            'missing_prereqs' => $missingPrereqs,
            'offerings' => $offerings,
        ];
    }
}

// --- Handle submission ---
if ($eligible && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedOfferings = $_POST['offering'] ?? []; // [subject_id => offering_id]
    $selectedOfferings = array_filter($selectedOfferings, fn($v) => $v !== '');

    if (empty($selectedOfferings)) {
        $error = 'Select at least one subject.';
    } else {
        // Re-validate every selection against the same rules server-side — the disabled
        // dropdowns in the form are a UI convenience, not the actual enforcement.
        $validOfferingIds = [];
        foreach ($subjectRows as $row) {
            if ($row['locked']) continue;
            foreach ($row['offerings'] as $o) {
                $validOfferingIds[(int)$o['offering_id']] = true;
            }
        }

        $toInsert = [];
        foreach ($selectedOfferings as $subjectId => $offeringId) {
            if (isset($validOfferingIds[(int)$offeringId])) {
                $toInsert[] = (int)$offeringId;
            }
        }

        if (empty($toInsert)) {
            $error = 'None of your selections were valid — please try again.';
        } else {
            try {
                $pdo->beginTransaction();

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

                $insertStmt = $pdo->prepare('INSERT INTO Enrolled_subject (enrollment_id, offering_id) VALUES (:eid, :oid)');
                foreach ($toInsert as $offeringId) {
                    $insertStmt->execute(['eid' => $newEnrollmentId, 'oid' => $offeringId]);
                }

                $pdo->commit();
                $submitted = true;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Could not submit your selections. ' . $e->getMessage();
            }
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
            Your subject selections have been submitted and are waiting on registrar approval.
            You'll see them on your dashboard once approved.
        </div>
        <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn btn-primary">Back to Dashboard</a>

    <?php elseif ($eligible): ?>
        <p class="text-muted">
            Since your standing is irregular, you pick your own subjects each term. A subject is only
            selectable if you've passed its prerequisite (if it has one) and a class is actually offered
            this term.
        </p>

        <form method="post">
            <div class="table-responsive">
            <table class="table bg-white">
                <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Offering</th></tr></thead>
                <tbody>
                <?php foreach ($subjectRows as $row): ?>
                    <tr class="<?= $row['locked'] ? 'table-secondary' : '' ?>">
                        <td><?= htmlspecialchars($row['subject_code']) ?></td>
                        <td><?= htmlspecialchars($row['subject_name']) ?></td>
                        <td><?= $row['units'] ?></td>
                        <td>
                            <?php if ($row['locked']): ?>
                                <span class="text-muted small">
                                    <?php if (!empty($row['missing_prereqs'])): ?>
                                        Requires: <?= htmlspecialchars(implode(', ', $row['missing_prereqs'])) ?>
                                    <?php else: ?>
                                        Not offered this term
                                    <?php endif; ?>
                                </span>
                            <?php else: ?>
                                <select class="form-select form-select-sm" name="offering[<?= $row['subject_id'] ?>]">
                                    <option value="">— Skip —</option>
                                    <?php foreach ($row['offerings'] as $o): ?>
                                        <option value="<?= $o['offering_id'] ?>">
                                            <?= htmlspecialchars($o['section_name'] . ' — ' . $o['day_of_week'] . ' ' . $o['start_time'] . '–' . $o['end_time'] . ' ' . ($o['room'] ?? '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($subjectRows)): ?>
                    <tr><td colspan="4" class="text-muted">No subjects left in your curriculum to enroll in.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php if (!empty($subjectRows)): ?>
                <button type="submit" class="btn btn-primary">Submit for Approval</button>
            <?php endif; ?>
        </form>
    <?php else: ?>
        <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>
    <?php endif; ?>
</div>
</body>
</html>