<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/password_helper.php';
require_once __DIR__ . '/../../src/helpers/mail_helper.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$documentTypes = ['Birth Certificate', 'Form 137 / Report Card', 'Certificate of Good Moral Character', '2x2 ID Photos'];

$applicationId = $_GET['id'] ?? ($_POST['application_id'] ?? null);
$error = '';
$movedMessage = isset($_GET['moved']) ? 'Program updated — this application now belongs to a different department and no longer appears here.' : '';
$created = null; // ['username'=>, 'password'=>, 'student_id_number'=>, 'subjects'=>[]] after final commit
$mailWarning = '';
$stage = 'pick_term'; // pick_term -> confirm

$application = null;
if ($applicationId) {
    // Scoped to MY department — this is what makes an application disappear from my queue
    // the moment its program gets reassigned to a different department below.
    $stmt = $pdo->prepare(
        'SELECT a.*, p.program_id, p.program_code, p.program_name
         FROM Admission_Application a
         JOIN Program p ON p.program_id = a.program_id
         WHERE a.application_id = :id AND a.status = \'validated\' AND p.department_id = :dept'
    );
    $stmt->execute(['id' => $applicationId, 'dept' => $myDepartmentId]);
    $application = $stmt->fetch();

    if ($application) {
        // Already placed?
        $check = $pdo->prepare('SELECT student_id FROM Student WHERE application_id = :id');
        $check->execute(['id' => $applicationId]);
        if ($check->fetch() !== false) {
            $error = 'This application has already been placed.';
            $application = null;
        }
    }
}

// --- Review/edit applicant info + possible program reassignment (POST) ---
if ($application && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_application') {
    $f = fn(string $key) => trim($_POST[$key] ?? '');
    $newProgramId = $_POST['program_id'] ?? '';
    $newYearLevel = $_POST['evaluated_year_level'] ?? '';

    if ($newProgramId === '' || $newYearLevel === '') {
        $error = 'Program and year level are required.';
    } else {
        $stmt = $pdo->prepare(
            'UPDATE Admission_Application SET
                applicant_last_name = :last_name, applicant_first_name = :first_name,
                applicant_middle_name = :middle_name, applicant_suffix = :suffix,
                birthdate = :birthdate,
                applicant_province = :province, applicant_municipality = :municipality,
                applicant_barangay = :barangay, applicant_purok = :purok,
                contact_no = :contact_no, email_address = :email_address,
                guardian_name = :guardian_name, guardian_relationship = :guardian_relationship,
                guardian_contact_no = :guardian_contact_no,
                program_id = :program_id, evaluated_year_level = :year_level
             WHERE application_id = :id'
        );
        $stmt->execute([
            'last_name' => $f('applicant_last_name'), 'first_name' => $f('applicant_first_name'),
            'middle_name' => $f('applicant_middle_name') ?: null, 'suffix' => $f('applicant_suffix') ?: null,
            'birthdate' => $f('birthdate'),
            'province' => $f('applicant_province') ?: null, 'municipality' => $f('applicant_municipality') ?: null,
            'barangay' => $f('applicant_barangay') ?: null, 'purok' => $f('applicant_purok') ?: null,
            'contact_no' => $f('contact_no') ?: null, 'email_address' => $f('email_address') ?: null,
            'guardian_name' => $f('guardian_name') ?: null, 'guardian_relationship' => $f('guardian_relationship') ?: null,
            'guardian_contact_no' => $f('guardian_contact_no') ?: null,
            'program_id' => $newProgramId, 'year_level' => $newYearLevel,
            'id' => $applicationId,
        ]);

        // Did the program move to a different department? If so, this registrar loses
        // visibility into it immediately — that's the correct behavior, not a bug.
        $deptCheck = $pdo->prepare('SELECT department_id FROM Program WHERE program_id = :pid');
        $deptCheck->execute(['pid' => $newProgramId]);
        $newDepartmentId = $deptCheck->fetchColumn();

        if ((int)$newDepartmentId !== (int)$myDepartmentId) {
            header('Location: ' . BASE_URL . '/registrar/place-student.php?moved=1');
            exit;
        }

        // Still ours — re-fetch so the rest of the page reflects the update.
        $stmt = $pdo->prepare(
            'SELECT a.*, p.program_id, p.program_code, p.program_name
             FROM Admission_Application a JOIN Program p ON p.program_id = a.program_id
             WHERE a.application_id = :id'
        );
        $stmt->execute(['id' => $applicationId]);
        $application = $stmt->fetch();
    }
}

// --- Stage: load subjects (POST) ---
$subjectRows = [];
$chosenTermId = $chosenCurriculumId = $chosenSectionId = null;
$capacityWarning = '';

if ($application && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'load_subjects') {
    $chosenTermId = $_POST['term_id'] ?? '';
    $chosenCurriculumId = $_POST['curriculum_id'] ?? '';
    $chosenSectionId = $_POST['section_id'] ?? '';

    if ($chosenTermId === '' || $chosenCurriculumId === '' || $chosenSectionId === '') {
        $error = 'Please select a term, curriculum, and section.';
    } else {
        $term = $pdo->prepare('SELECT * FROM School_term WHERE term_id = :id');
        $term->execute(['id' => $chosenTermId]);
        $term = $term->fetch();

        $stmt = $pdo->prepare(
            'SELECT cs.subject_id, s.subject_code, s.subject_name, s.units,
                    co.offering_id, co.day_of_week, co.start_time, co.end_time, co.room
             FROM Curriculum_subject cs
             JOIN Subject s ON s.subject_id = cs.subject_id
             LEFT JOIN Class_Offering co
                    ON co.subject_id = cs.subject_id AND co.section_id = :section_id AND co.term_id = :term_id
             WHERE cs.curriculum_id = :curriculum_id
               AND cs.year_level = :year_level
               AND cs.semester = :semester
             ORDER BY s.subject_code'
        );
        $stmt->execute([
            'section_id'    => $chosenSectionId,
            'term_id'       => $chosenTermId,
            'curriculum_id' => $chosenCurriculumId,
            'year_level'    => $application['evaluated_year_level'],
            'semester'      => $term['semester'],
        ]);
        $subjectRows = $stmt->fetchAll();
        $stage = 'confirm';

        // Informational only — this student hasn't been placed yet, so occupancy + 1
        // is what the section would become. Registrar can still proceed either way.
        $sec = $pdo->prepare('SELECT section_name, max_slots FROM Section WHERE section_id = :id');
        $sec->execute(['id' => $chosenSectionId]);
        $sec = $sec->fetch();
        if ($sec) {
            $occupied = sectionOccupancy($pdo, (int)$chosenSectionId);
            if ($occupied + 1 > (int)$sec['max_slots']) {
                $capacityWarning = "Heads up: {$sec['section_name']} is at {$occupied}/{$sec['max_slots']} "
                    . "— placing this student here will put it over capacity.";
            }
        }
    }
}

// --- Stage: confirm placement (POST) — the actual transaction ---
if ($application && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_placement') {
    $termId = $_POST['term_id'] ?? '';
    $curriculumId = $_POST['curriculum_id'] ?? '';
    $sectionId = $_POST['section_id'] ?? '';
    $yearLevel = $application['evaluated_year_level'];
    $offeringIds = $_POST['offerings'] ?? []; // array of selected offering_id
    $docStatuses = $_POST['doc_status'] ?? []; // [document_type => 'verified'|'missing']

    /*
     * Never trust offerings[] directly — re-derive which offering_ids are actually
     * valid for this curriculum/year/semester/section/term and intersect against
     * that, same principle as the ownership check in grade-entry.php.
     */
    $termRow = $pdo->prepare('SELECT semester FROM School_term WHERE term_id = :id');
    $termRow->execute(['id' => $termId]);
    $termRow = $termRow->fetch();

    $validStmt = $pdo->prepare(
        'SELECT co.offering_id
         FROM Curriculum_subject cs
         JOIN Class_Offering co ON co.subject_id = cs.subject_id
            AND co.section_id = :section_id AND co.term_id = :term_id
         WHERE cs.curriculum_id = :curriculum_id
           AND cs.year_level = :year_level
           AND cs.semester = :semester'
    );
    $validStmt->execute([
        'section_id' => $sectionId, 'term_id' => $termId,
        'curriculum_id' => $curriculumId, 'year_level' => $yearLevel,
        'semester' => $termRow['semester'] ?? null,
    ]);
    $validOfferingIds = array_map('intval', array_column($validStmt->fetchAll(), 'offering_id'));
    $offeringIds = array_values(array_intersect(array_map('intval', $offeringIds), $validOfferingIds));

    try {
        $pdo->beginTransaction();

        // Chicken-and-egg problem: Student.account_id is NOT NULL, so the Account must exist
        // first — but the username IS the student_id_number, which needs the Student row's
        // real auto-increment ID to compute. Solved with a placeholder-then-rename: create the
        // Account with a temporary unique username, create the Student pointing at it, then
        // rename both to the real student_id_number once we know it.
        $tempPassword = generateTempPassword();
        $placeholderUsername = 'PENDING-' . bin2hex(random_bytes(6));
        $studentEmail = $application['email_address'];

        $stmt = $pdo->prepare(
            "INSERT INTO Accounts (username, email, password_hash, role, must_change_password)
             VALUES (:username, :email, :hash, 'student', 1)"
        );
        $stmt->execute(['username' => $placeholderUsername, 'email' => $studentEmail, 'hash' => password_hash($tempPassword, PASSWORD_DEFAULT)]);
        $newAccountId = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare(
            'INSERT INTO Student (
                student_id_number, account_id, application_id, last_name, first_name, middle_name, suffix,
                birthdate, province, municipality, barangay, purok,
                father_last_name, father_first_name, father_middle_name, father_suffix,
                mother_maiden_name, mother_first_name, mother_middle_name, father_occupation, mother_occupation,
                contact_no, email_address, guardian_name, guardian_relationship, guardian_contact_no,
                student_type, overall_status
            ) VALUES (
                :placeholder_number, :account_id, :application_id, :last_name, :first_name, :middle_name, :suffix,
                :birthdate, :province, :municipality, :barangay, :purok,
                :father_last_name, :father_first_name, :father_middle_name, :father_suffix,
                :mother_maiden_name, :mother_first_name, :mother_middle_name, :father_occupation, :mother_occupation,
                :contact_no, :email_address, :guardian_name, :guardian_relationship, :guardian_contact_no,
                :student_type, \'active\'
            )'
        );
        $stmt->execute([
            'placeholder_number' => $placeholderUsername, 'account_id' => $newAccountId, 'application_id' => $applicationId,
            'last_name' => $application['applicant_last_name'], 'first_name' => $application['applicant_first_name'],
            'middle_name' => $application['applicant_middle_name'], 'suffix' => $application['applicant_suffix'],
            'birthdate' => $application['birthdate'],
            'province' => $application['applicant_province'], 'municipality' => $application['applicant_municipality'],
            'barangay' => $application['applicant_barangay'], 'purok' => $application['applicant_purok'],
            'father_last_name' => $application['father_last_name'], 'father_first_name' => $application['father_first_name'],
            'father_middle_name' => $application['father_middle_name'], 'father_suffix' => $application['father_suffix'],
            'mother_maiden_name' => $application['mother_maiden_name'], 'mother_first_name' => $application['mother_first_name'],
            'mother_middle_name' => $application['mother_middle_name'],
            'father_occupation' => $application['father_occupation'], 'mother_occupation' => $application['mother_occupation'],
            'contact_no' => $application['contact_no'], 'email_address' => $application['email_address'],
            'guardian_name' => $application['guardian_name'], 'guardian_relationship' => $application['guardian_relationship'],
            'guardian_contact_no' => $application['guardian_contact_no'],
            'student_type' => $application['student_type'],
        ]);
        $newStudentId = (int)$pdo->lastInsertId();

        // Now rename both the Student's ID number and the Account's username to the real value.
        $studentIdNumber = date('Y') . '-' . str_pad((string)$newStudentId, 5, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE Student SET student_id_number = :n WHERE student_id = :id')
            ->execute(['n' => $studentIdNumber, 'id' => $newStudentId]);
        $pdo->prepare('UPDATE Accounts SET username = :n WHERE account_id = :id')
            ->execute(['n' => $studentIdNumber, 'id' => $newAccountId]);

        // 3. Enrollment — the registrar placing the student IS the approval.
        $stmt = $pdo->prepare(
            "INSERT INTO Enrollment (student_id, term_id, curriculum_id, section_id, year_level, date_enrolled, status, approved_by, student_standing)
             VALUES (:student_id, :term_id, :curriculum_id, :section_id, :year_level, NOW(), 'approved', :approved_by, 'regular')"
        );
        $stmt->execute([
            'student_id' => $newStudentId, 'term_id' => $termId, 'curriculum_id' => $curriculumId,
            'section_id' => $sectionId, 'year_level' => $yearLevel, 'approved_by' => $user['account_id'],
        ]);
        $newEnrollmentId = (int)$pdo->lastInsertId();

        // 4. Enrolled_subject — one row per confirmed offering
        $stmt = $pdo->prepare(
            'INSERT INTO Enrolled_subject (enrollment_id, offering_id) VALUES (:enrollment_id, :offering_id)'
        );
        $enrolledCount = 0;
        foreach ($offeringIds as $offeringId) {
            $stmt->execute(['enrollment_id' => $newEnrollmentId, 'offering_id' => $offeringId]);
            $enrolledCount++;
        }

        // 5. Enrollment_Document — one row per document type reviewed
        $stmt = $pdo->prepare(
            'INSERT INTO Enrollment_Document (document_type, status, verified_by, application_id)
             VALUES (:type, :status, :verified_by, :application_id)'
        );
        foreach ($documentTypes as $type) {
            $docStatus = $docStatuses[$type] ?? 'missing';
            $stmt->execute([
                'type' => $type,
                'status' => $docStatus,
                'verified_by' => $docStatus === 'verified' ? $user['account_id'] : null,
                'application_id' => $applicationId,
            ]);
        }

        $pdo->commit();

        $created = [
            'username' => $studentIdNumber,
            'password' => $tempPassword,
            'student_id_number' => $studentIdNumber,
            'subjects_count' => $enrolledCount,
            'student_id' => $newStudentId,
            'student_type' => $application['student_type'],
            'email' => $studentEmail,
        ];

        if ($studentEmail) {
            $studentName = $application['applicant_first_name'] . ' ' . $application['applicant_last_name'];
            $mailSent = sendAccountCredentialsEmail($studentEmail, $studentName, $studentIdNumber, $tempPassword);
            if (!$mailSent) {
                $mailWarning = 'The student was placed, but the credentials email could not be sent. Share the credentials below manually.';
            }
        } else {
            $mailWarning = 'No email was on file for this applicant. Share the credentials below manually.';
        }

        $application = null; // done, drop back to the list
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Could not complete placement. ' . $e->getMessage();
    }
}

// --- Dropdown data for stage: pick_term ---
$terms = $curricula = $sections = $allPrograms = [];
if ($application) {
    $terms = $pdo->query("SELECT * FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC")->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM Curriculum WHERE program_id = :pid AND is_active = 1');
    $stmt->execute(['pid' => $application['program_id']]);
    $curricula = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM Section WHERE program_id = :pid AND year_level = :yl');
    $stmt->execute(['pid' => $application['program_id'], 'yl' => $application['evaluated_year_level']]);
    $sections = $stmt->fetchAll();

    // Unscoped on purpose — reassignment can cross department lines (see the update_application handler above).
    $allPrograms = $pdo->query(
        'SELECT p.program_id, p.program_code, d.department_name
         FROM Program p JOIN Department d ON d.department_id = p.department_id
         ORDER BY d.department_name, p.program_code'
    )->fetchAll();
}

// --- List view: validated, not-yet-placed applications in MY department ---
$search = trim($_GET['q'] ?? '');
$pending = [];
if (!$application && !$created) {
    $sql = "SELECT a.application_id, a.applicant_last_name, a.applicant_first_name, a.evaluated_year_level,
                   p.program_code, a.date_validated
            FROM Admission_Application a
            JOIN Program p ON p.program_id = a.program_id
            LEFT JOIN Student st ON st.application_id = a.application_id
            WHERE a.status = 'validated' AND st.student_id IS NULL AND p.department_id = :dept";
    if ($search !== '') {
        $sql .= " AND (a.applicant_last_name LIKE :q1 OR a.applicant_first_name LIKE :q2)";
    }
    $sql .= " ORDER BY a.date_validated ASC";
    $stmt = $pdo->prepare($sql);
    $params = ['dept' => $myDepartmentId];
    if ($search !== '') {
        $params['q1'] = "%$search%";
        $params['q2'] = "%$search%";
    }
    $stmt->execute($params);
    $pending = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Place Student</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($movedMessage): ?>
        <div class="alert alert-info"><?= htmlspecialchars($movedMessage) ?></div>
    <?php endif; ?>

    <?php if ($created): ?>

        <?= stepper(['Review and assign', 'Confirm', 'Done'], 4) ?>

        <?php if ($mailWarning): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($mailWarning) ?></div>
        <?php endif; ?>

        <div class="card border-success mb-4">
            <div class="card-body">
                <h2 class="h5 card-title text-success">Student placed successfully</h2>
                <?php if (!$mailWarning): ?>
                    <p class="text-success">
                        Email has been sent to <strong><?= htmlspecialchars($created['email']) ?></strong>.
                    </p>
                <?php endif; ?>
                <p class="text-muted">This password will not be shown again.</p>
                <dl class="row mb-0">
                    <dt class="col-sm-4">Student ID Number / Username</dt>
                    <dd class="col-sm-8"><code><?= htmlspecialchars($created['student_id_number']) ?></code></dd>
                    <dt class="col-sm-4">Temporary Password</dt>
                    <dd class="col-sm-8"><code><?= htmlspecialchars($created['password']) ?></code></dd>
                    <dt class="col-sm-4">Subjects Enrolled</dt>
                    <dd class="col-sm-8"><?= (int)$created['subjects_count'] ?></dd>
                </dl>
            </div>
        </div>

        <?php if ($created['student_type'] === 'transferee'): ?>
            <a href="<?= BASE_URL ?>/registrar/transferee-credit.php?student_id=<?= $created['student_id'] ?>"
               class="btn btn-success">
                Continue to Transferee Credit Evaluation
            </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/registrar/place-student.php" class="btn btn-outline-primary">
            Place Another Student
        </a>

    <?php elseif ($application && $stage === 'confirm'): ?>

        <?= stepper(['Review and assign', 'Confirm', 'Done'], 2) ?>

        <h1 class="h4 mb-3">
            Confirm Placement — <?= htmlspecialchars($application['applicant_first_name'] . ' ' . $application['applicant_last_name']) ?>
        </h1>
        <p class="text-muted">
            <?= htmlspecialchars($application['program_code']) ?>, Year <?= $application['evaluated_year_level'] ?>
        </p>

        <?php if ($capacityWarning): ?>
            <div class="alert alert-warning"><?= htmlspecialchars($capacityWarning) ?></div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="action" value="confirm_placement">
            <input type="hidden" name="application_id" value="<?= $applicationId ?>">
            <input type="hidden" name="term_id" value="<?= htmlspecialchars($chosenTermId) ?>">
            <input type="hidden" name="curriculum_id" value="<?= htmlspecialchars($chosenCurriculumId) ?>">
            <input type="hidden" name="section_id" value="<?= htmlspecialchars($chosenSectionId) ?>">

            <?php if ($application['student_type'] === 'transferee'): ?>
                <div class="alert alert-info mb-3">
                    This is a transferee — subjects aren't auto-loaded here. After you confirm placement,
                    you'll go to the Transferee Credit Evaluation screen to record their previous school's
                    grades and load whichever subjects still need to be taken.
                </div>
            <?php else: ?>
            <div class="card mb-3">
                <div class="card-header">Subjects for this term</div>
                <div class="card-body">
                    <?php if (empty($subjectRows)): ?>
                        <p class="text-muted mb-0">No curriculum subjects found for this year level and semester.
                            Check that <code>Curriculum_subject</code> has rows for this curriculum.</p>
                    <?php endif; ?>
                    <div class="table-responsive">
<table class="table">
                        <thead><tr><th></th><th>Code</th><th>Subject</th><th>Units</th><th>Schedule</th></tr></thead>
                        <tbody>
                        <?php foreach ($subjectRows as $s): ?>
                            <tr class="<?= $s['offering_id'] === null ? 'table-warning' : '' ?>">
                                <td>
                                    <?php if ($s['offering_id'] !== null): ?>
                                        <input type="checkbox" name="offerings[]" value="<?= $s['offering_id'] ?>" checked>
                                    <?php else: ?>
                                        <span title="No class offering scheduled for this section/term">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($s['subject_code']) ?></td>
                                <td><?= htmlspecialchars($s['subject_name']) ?></td>
                                <td><?= $s['units'] ?></td>
                                <td>
                                    <?= $s['offering_id'] !== null
                                        ? htmlspecialchars($s['day_of_week'] . ' ' . $s['start_time'] . '–' . $s['end_time'] . ' ' . $s['room'])
                                        : '<span class="text-muted">not scheduled</span>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
</div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card mb-3">
                <div class="card-header">Document Verification</div>
                <div class="card-body">
                    <?php foreach ($documentTypes as $type): ?>
                        <div class="row align-items-center mb-2">
                            <div class="col-md-6"><?= htmlspecialchars($type) ?></div>
                            <div class="col-md-6">
                                <select class="form-select form-select-sm" name="doc_status[<?= htmlspecialchars($type) ?>]">
                                    <option value="verified" selected>Verified</option>
                                    <option value="missing">Missing</option>
                                </select>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="submit" class="btn btn-success w-100"
                    data-confirm="Create this student&#039;s account and enrollment? This cannot be undone here."
                    data-confirm-label="Confirm Placement" data-confirm-tone="success">
                Confirm Placement
            </button>
        </form>

    <?php elseif ($application): ?>

        <?= stepper(['Review and assign', 'Confirm', 'Done'], 1) ?>

        <h1 class="h4 mb-3">
            Place — <?= htmlspecialchars($application['applicant_first_name'] . ' ' . $application['applicant_last_name']) ?>
        </h1>
        <p class="text-muted">
            <?= htmlspecialchars($application['program_code']) ?>, evaluated at
            Year <?= $application['evaluated_year_level'] ?>, <?= htmlspecialchars($application['student_type']) ?>
        </p>

        <form method="post" class="card mb-3">
            <div class="card-header">Review Applicant Information</div>
            <div class="card-body">
                <input type="hidden" name="action" value="update_application">
                <input type="hidden" name="application_id" value="<?= $applicationId ?>">
                <?php $v = fn(string $key) => htmlspecialchars($application[$key] ?? ''); ?>

                <div class="row">
                    <div class="col-md-4 mb-3"><label class="form-label">Last Name</label>
                        <input class="form-control" name="applicant_last_name" value="<?= $v('applicant_last_name') ?>" required></div>
                    <div class="col-md-4 mb-3"><label class="form-label">First Name</label>
                        <input class="form-control" name="applicant_first_name" value="<?= $v('applicant_first_name') ?>" required></div>
                    <div class="col-md-2 mb-3"><label class="form-label">Middle Name</label>
                        <input class="form-control" name="applicant_middle_name" value="<?= $v('applicant_middle_name') ?>"></div>
                    <div class="col-md-2 mb-3"><label class="form-label">Suffix</label>
                        <input class="form-control" name="applicant_suffix" value="<?= $v('applicant_suffix') ?>"></div>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-3"><label class="form-label">Birthdate</label>
                        <input type="date" class="form-control" name="birthdate" value="<?= $v('birthdate') ?>" required></div>
                    <div class="col-md-3 mb-3"><label class="form-label">Province</label>
                        <input class="form-control" name="applicant_province" value="<?= $v('applicant_province') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">Municipality</label>
                        <input class="form-control" name="applicant_municipality" value="<?= $v('applicant_municipality') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">Barangay</label>
                        <input class="form-control" name="applicant_barangay" value="<?= $v('applicant_barangay') ?>"></div>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-3"><label class="form-label">Contact No.</label>
                        <input class="form-control" name="contact_no" value="<?= $v('contact_no') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">Email</label>
                        <input class="form-control" name="email_address" value="<?= $v('email_address') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">Guardian Name</label>
                        <input class="form-control" name="guardian_name" value="<?= $v('guardian_name') ?>"></div>
                    <div class="col-md-3 mb-3"><label class="form-label">Guardian Contact No.</label>
                        <input class="form-control" name="guardian_contact_no" value="<?= $v('guardian_contact_no') ?>"></div>
                </div>

                <hr>
                <div class="row">
                    <div class="col-md-8 mb-3">
                        <label class="form-label">Program</label>
                        <select class="form-select" name="program_id" required>
                            <?php foreach ($allPrograms as $p): ?>
                                <option value="<?= $p['program_id'] ?>" <?= $p['program_id'] == $application['program_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['program_code'] . ' — ' . $p['department_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            If the applicant chose the wrong program, reassign it here — even to a different
                            department. If you switch it out of your own department, this application will
                            move to that department's queue and disappear from yours.
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Year Level</label>
                        <input type="number" min="1" max="5" class="form-control" name="evaluated_year_level"
                               value="<?= $v('evaluated_year_level') ?>" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-outline-primary">Save Changes</button>
            </div>
        </form>

        <?php if (empty($terms) || empty($curricula) || empty($sections)): ?>
            <div class="alert alert-warning">
                Missing setup data before this student can be placed:
                <ul class="mb-0">
                    <?php if (empty($terms)): ?><li>No <code>School_term</code> with status 'ongoing'.</li><?php endif; ?>
                    <?php if (empty($curricula)): ?><li>No active <code>Curriculum</code> for this program.</li><?php endif; ?>
                    <?php if (empty($sections)): ?><li>No <code>Section</code> for this program at year <?= $application['evaluated_year_level'] ?>.</li><?php endif; ?>
                </ul>
            </div>
        <?php else: ?>
            <form method="post" class="card">
                <div class="card-body">
                    <input type="hidden" name="action" value="load_subjects">
                    <input type="hidden" name="application_id" value="<?= $applicationId ?>">

                    <div class="mb-3">
                        <label class="form-label">Term</label>
                        <select class="form-select" name="term_id" required>
                            <?php foreach ($terms as $t): ?>
                                <option value="<?= $t['term_id'] ?>">
                                    <?= htmlspecialchars($t['school_year'] . ' — Semester ' . $t['semester']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Curriculum</label>
                        <select class="form-select" name="curriculum_id" required>
                            <?php foreach ($curricula as $c): ?>
                                <option value="<?= $c['curriculum_id'] ?>">
                                    <?= htmlspecialchars($c['curriculum_name'] . ' (' . $c['effective_year'] . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Section</label>
                        <select class="form-select" name="section_id" required>
                            <?php foreach ($sections as $s): ?>
                                <option value="<?= $s['section_id'] ?>"><?= htmlspecialchars($s['section_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Load Subjects</button>
                </div>
            </form>
        <?php endif; ?>

    <?php else: ?>

        <h1 class="h4 mb-3">Validated Applications Awaiting Placement</h1>

        <form method="get" class="toolbar">
            <div class="toolbar-field toolbar-field-wide">
                <label for="q">Search</label>
                <input type="text" class="form-control" id="q" name="q" placeholder="Applicant name"
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($search !== ''): ?><a href="place-student.php" class="btn btn-outline-secondary">Reset</a><?php endif; ?>
            <span class="toolbar-count">Showing <?= count($pending) ?> applicant<?= count($pending) === 1 ? '' : 's' ?></span>
        </form>

        <div class="table-responsive">
<table class="table table-hover bg-white">
            <thead><tr><th>#</th><th>Name</th><th>Program</th><th>Year</th><th>Validated</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($pending as $a): ?>
                <tr>
                    <td><?= $a['application_id'] ?></td>
                    <td><?= htmlspecialchars($a['applicant_first_name'] . ' ' . $a['applicant_last_name']) ?></td>
                    <td><?= htmlspecialchars($a['program_code']) ?></td>
                    <td><?= $a['evaluated_year_level'] ?></td>
                    <td><?= htmlspecialchars($a['date_validated']) ?></td>
                    <td><a href="?id=<?= $a['application_id'] ?>" class="btn btn-sm btn-outline-primary">Place</a></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($pending)): ?>
                <tr><td colspan="6" class="text-muted"><?= $search !== '' ? 'No applicants match that name.' : 'No validated applications waiting.' ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
</div>

    <?php endif; ?>
</div>
</body>
</html>