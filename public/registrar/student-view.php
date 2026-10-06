<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/invite_helper.php';
require_once __DIR__ . '/../../src/helpers/schedule_helper.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$studentId = $_GET['id'] ?? ($_POST['id'] ?? null);

$stmt = $pdo->prepare(
    "SELECT s.*, a.username, a.email AS account_email, a.is_active AS account_active, a.activated_at AS account_activated_at,
            e.enrollment_id AS latest_enrollment_id, e.curriculum_id, e.year_level, e.student_standing,
            sec.section_name, p.program_code, p.program_name, term.school_year, term.semester
     FROM Student s
     JOIN Accounts a ON a.account_id = s.account_id
     JOIN Enrollment e ON e.enrollment_id = (
         SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id AND e2.status <> 'rejected'
         ORDER BY e2.enrollment_id DESC LIMIT 1
     )
     JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
     JOIN Program p ON p.program_id = c.program_id
     LEFT JOIN Section sec ON sec.section_id = e.section_id
     LEFT JOIN School_term term ON term.term_id = e.term_id
     WHERE s.student_id = :id AND p.department_id = :dept"
);
$stmt->execute(['id' => $studentId, 'dept' => $myDepartmentId]);
$student = $stmt->fetch();

if ($student === false) {
    require __DIR__ . '/../../includes/navbar.php';
    echo '<div class="container"><div class="alert alert-danger">Student not found or not in your department.</div>'
        . '<a href="' . BASE_URL . '/registrar/students.php" class="btn btn-outline-secondary">&larr; Back to Students</a></div>';
    exit;
}

$studentId = (int)$student['student_id'];
$error = '';
$emailError = '';
$accountPage = BASE_URL . '/registrar/student-view.php?id=' . $studentId . '&tab=password';

$statusMessage = flashGet('student_status_msg');
$linkMessage = flashGet('student_link_msg');
$linkWarning = flashGet('student_link_warn');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_link') {
    [$ok, $text] = accountLinkMessage(sendAccountLink($pdo, (int)$student['account_id']));
    flashSet($ok ? 'student_link_msg' : 'student_link_warn', $text);
    header('Location: ' . $accountPage);
    exit;
}

/*
 * Changing the email moves control of the account, so every earlier link is cancelled, the old
 * address is told, the change is logged, and a never-activated account gets a fresh invite.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_email') {
    $newEmail = trim($_POST['email'] ?? '');
    $oldEmail = trim((string)($student['account_email'] ?? ''));
    $accountId = (int)$student['account_id'];

    if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 255) {
        $emailError = 'Enter a valid email address.';
    } elseif (strcasecmp($newEmail, $oldEmail) === 0) {
        $emailError = 'That is already the email on file.';
    } else {
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE Accounts SET email = :e WHERE account_id = :id')->execute(['e' => $newEmail, 'id' => $accountId]);
            $pdo->prepare('UPDATE Student SET email_address = :e WHERE student_id = :id')->execute(['e' => $newEmail, 'id' => $studentId]);
            voidOpenPasswordResets($pdo, $accountId);
            $pdo->prepare('INSERT INTO Account_email_change (account_id, changed_by, old_email, new_email) VALUES (:a, :by, :old, :new)')
                ->execute(['a' => $accountId, 'by' => $user['account_id'], 'old' => $oldEmail !== '' ? $oldEmail : null, 'new' => $newEmail]);
            $pdo->commit();

            $msg = 'Email changed to ' . $newEmail . '. Earlier password links were cancelled.';
            $warnings = [];
            if ($student['account_activated_at'] === null && (int)$student['account_active'] === 1) {
                [$ok, $text] = accountLinkMessage(sendAccountLink($pdo, $accountId));
                if ($ok) {
                    $msg .= ' ' . $text;
                } else {
                    $warnings[] = $text . ' Use Resend invite.';
                }
            }
            if ($oldEmail !== '') {
                sendEmailChangedNotice($oldEmail, $student['first_name'] . ' ' . $student['last_name'], $student['username'], maskEmail($newEmail));
            }
            $dup = $pdo->prepare("SELECT 1 FROM Accounts WHERE LOWER(email) = LOWER(:e) AND account_id <> :id AND role = 'student' LIMIT 1");
            $dup->execute(['e' => $newEmail, 'id' => $accountId]);
            if ($dup->fetch() !== false) {
                $warnings[] = 'Another student account already uses this email address.';
            }

            flashSet('student_link_msg', $msg);
            if ($warnings) {
                flashSet('student_link_warn', implode(' ', $warnings));
            }
            header('Location: ' . $accountPage);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $emailError = errorMessage($e, 'Could not change the email.');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_status') {
    $newStatus = $_POST['status'] ?? '';
    $statusLabels = ['active' => 'Active', 'on_leave' => 'On Leave', 'dropped' => 'Dropped', 'graduated' => 'Graduated'];

    if (!isset($statusLabels[$newStatus])) {
        $error = 'Choose a valid status.';
    } elseif ($newStatus === $student['overall_status']) {
        $error = 'The student already has that status.';
    } else {
        $missing = $newStatus === 'graduated'
            ? missingCurriculumSubjects($pdo, $studentId, (int)$student['curriculum_id'])
            : [];

        if ($missing) {
            $shown = implode(', ', array_slice($missing, 0, 8));
            $more = count($missing) > 8 ? ' and ' . (count($missing) - 8) . ' more' : '';
            $error = 'Cannot mark this student as graduated yet. Not completed: ' . $shown . $more . '.';
        } else {
            $pdo->prepare('UPDATE Student SET overall_status = :status WHERE student_id = :id')
                ->execute(['status' => $newStatus, 'id' => $studentId]);
            flashSet('student_status_msg', 'Status changed to ' . $statusLabels[$newStatus] . '.');
            header('Location: ' . BASE_URL . '/registrar/student-view.php?id=' . $studentId . '&tab=general');
            exit;
        }
    }
}

/*
 * Updates the document checklist after placement, for example when a late report card arrives.
 * Only rows that belong to this student's own application can change.
 */
$docMessage = flashGet('student_doc_msg');
$docError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_documents' && $student['application_id']) {
    $posted = $_POST['doc_status'] ?? [];
    $stmt = $pdo->prepare('SELECT document_id, status FROM Enrollment_Document WHERE application_id = :id');
    $stmt->execute(['id' => $student['application_id']]);
    $currentDocs = array_column($stmt->fetchAll(), 'status', 'document_id');

    $changes = [];
    if (is_array($posted)) {
        foreach ($posted as $docId => $status) {
            if (isset($currentDocs[$docId]) && in_array($status, ['verified', 'missing'], true) && $currentDocs[$docId] !== $status) {
                $changes[(int)$docId] = $status;
            }
        }
    }

    if (!$changes) {
        $docError = 'No document was changed.';
    } else {
        try {
            $pdo->beginTransaction();
            $update = $pdo->prepare(
                'UPDATE Enrollment_Document SET status = :status, verified_by = :by WHERE document_id = :id AND application_id = :app'
            );
            foreach ($changes as $docId => $status) {
                $update->execute([
                    'status' => $status,
                    'by'     => $status === 'verified' ? $user['account_id'] : null,
                    'id'     => $docId,
                    'app'    => $student['application_id'],
                ]);
            }
            $pdo->commit();
            flashSet('student_doc_msg', 'Documents updated.');
            header('Location: ' . BASE_URL . '/registrar/student-view.php?id=' . $studentId . '&tab=general');
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $docError = errorMessage($e, 'Could not update the documents.');
        }
    }
}

$tabs = ['general', 'schedule', 'subjects', 'password'];
$tab = in_array($_GET['tab'] ?? '', $tabs, true) ? $_GET['tab'] : 'general';
if (in_array($_POST['action'] ?? '', ['send_link', 'change_email'], true)) {
    $tab = 'password';
}
$isTransferee = $student['student_type'] === 'transferee';

$application = null;
$documents = [];
if ($student['application_id']) {
    $stmt = $pdo->prepare('SELECT * FROM Admission_Application WHERE application_id = :id');
    $stmt->execute(['id' => $student['application_id']]);
    $application = $stmt->fetch() ?: null;

    $stmt = $pdo->prepare('SELECT document_id, document_type, status FROM Enrollment_Document WHERE application_id = :id ORDER BY document_id');
    $stmt->execute(['id' => $student['application_id']]);
    $documents = $stmt->fetchAll();
}

$events = [];
if ($tab === 'schedule') {
    $stmt = $pdo->prepare(
        'SELECT s.subject_code, co.day_of_week, co.start_time, co.end_time, co.room, t.last_name, t.first_name
         FROM Enrolled_subject es
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         JOIN Subject s ON s.subject_id = co.subject_id
         JOIN Teacher t ON t.teacher_id = co.teacher_id
         WHERE es.enrollment_id = :eid ORDER BY co.start_time'
    );
    $stmt->execute(['eid' => $student['latest_enrollment_id']]);
    $events = array_map(fn($o) => [
        'day_of_week' => $o['day_of_week'], 'start_time' => $o['start_time'], 'end_time' => $o['end_time'],
        'title' => $o['subject_code'], 'lines' => [$o['room'], $o['last_name'] . ', ' . $o['first_name']],
    ], $stmt->fetchAll());
}

$byEnrollment = [];
$transferCredits = [];
$shiftCredits = [];
if ($tab === 'subjects') {
    $stmt = $pdo->prepare(
        'SELECT e.enrollment_id, e.year_level, e.status, e.student_standing, term.school_year, term.semester, sec.section_name,
                sub.subject_code, sub.subject_name, sub.units, es.grade, es.remarks
         FROM Enrollment e
         JOIN School_term term ON term.term_id = e.term_id
         LEFT JOIN Section sec ON sec.section_id = e.section_id
         LEFT JOIN Enrolled_subject es ON es.enrollment_id = e.enrollment_id
         LEFT JOIN Class_Offering co ON co.offering_id = es.offering_id
         LEFT JOIN Subject sub ON sub.subject_id = co.subject_id
         WHERE e.student_id = :sid ORDER BY e.enrollment_id DESC, sub.subject_code'
    );
    $stmt->execute(['sid' => $studentId]);
    foreach ($stmt->fetchAll() as $r) {
        $byEnrollment[$r['enrollment_id']]['head'] = $r;
        if ($r['subject_code'] !== null) {
            $byEnrollment[$r['enrollment_id']]['rows'][] = $r;
        }
    }

    $stmt = $pdo->prepare(
        'SELECT sub.subject_code, sub.subject_name, tc.previous_school, tc.previous_grade
         FROM Transferee_credit tc JOIN Subject sub ON sub.subject_id = tc.credited_subject_id
         WHERE tc.student_id = :sid ORDER BY sub.subject_code'
    );
    $stmt->execute(['sid' => $studentId]);
    $transferCredits = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT sub.subject_code, sub.subject_name, es.grade
         FROM Shift_credit sc
         JOIN Program_shift_request r ON r.request_id = sc.request_id
         JOIN Subject sub ON sub.subject_id = sc.credited_subject_id
         LEFT JOIN Enrolled_subject es ON es.enrolled_subject_id = sc.enrolled_subject_id
         WHERE r.student_id = :sid AND r.status = \'approved\' ORDER BY sub.subject_code'
    );
    $stmt->execute(['sid' => $studentId]);
    $shiftCredits = $stmt->fetchAll();
}

/* Not $row: navbar.php reuses that name and would overwrite this closure. */
$infoRow = fn($label, $value) => '<dt class="col-sm-4">' . htmlspecialchars($label) . '</dt><dd class="col-sm-8">'
    . ($value !== null && trim((string)$value) !== '' ? htmlspecialchars((string)$value) : '<span class="text-muted">&mdash;</span>') . '</dd>';
$join = fn(array $parts, string $sep = ' ') => trim(implode($sep, array_filter($parts, fn($p) => $p !== null && trim((string)$p) !== '')));
$units = fn($u) => rtrim(rtrim(number_format((float)$u, 2), '0'), '.');

$fullName = $join([$student['first_name'], $student['middle_name'], $student['last_name'], $student['suffix']]);
$base = '?id=' . $studentId . '&tab=';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($fullName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/schedule.css">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <a href="<?= BASE_URL ?>/registrar/students.php" class="btn btn-sm btn-outline-secondary">&larr; Back to Students</a>
    <h1 class="h4 mt-3 mb-1"><?= htmlspecialchars($fullName) ?></h1>
    <p class="text-muted mb-3">
        <?= htmlspecialchars($student['student_id_number']) ?> ·
        <?= htmlspecialchars($student['program_code']) ?>
        <?= $student['section_name'] ? ' · ' . htmlspecialchars(sectionLabel($student['year_level'], $student['section_name'])) : '' ?>
        · <?= statusBadge($student['overall_status']) ?> <?= statusBadge($student['student_standing']) ?>
        <?= $isTransferee ? '<span class="badge text-bg-info">Transferee</span>' : '' ?>
    </p>

    <?php
    $tabItems = [
        'general' => ['label' => 'General', 'href' => $base . 'general'],
        'schedule' => ['label' => 'Schedule', 'href' => $base . 'schedule'],
        'subjects' => ['label' => 'Subjects', 'href' => $base . 'subjects'],
        'password' => ['label' => 'Account', 'href' => $base . 'password'],
    ];
    if ($isTransferee) {
        $creditBase = BASE_URL . '/registrar/transferee-credit.php?student_id=' . $studentId . '&view=';
        $tabItems['credit'] = ['label' => 'Credit Evaluation', 'href' => $creditBase . 'credits'];
        $tabItems['addsubjects'] = ['label' => 'Add Subjects', 'href' => $creditBase . 'add'];
    }
    ?>
    <?= tabBar($tabItems, $tab) ?>

    <?php if ($tab === 'general'): ?>
        <?php if ($docMessage): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="4000"><?= htmlspecialchars($docMessage) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
        <?php if ($docError): ?><div class="alert alert-danger"><?= htmlspecialchars($docError) ?></div><?php endif; ?>
        <?php if ($statusMessage): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="4000"><?= htmlspecialchars($statusMessage) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <div class="row g-3">
            <div class="col-12">
                <div class="card"><div class="card-header">Student status</div><div class="card-body">
                    <form method="post" class="row g-2 align-items-end">
                        <input type="hidden" name="action" value="set_status">
                        <input type="hidden" name="id" value="<?= $studentId ?>">
                        <div class="col-sm-6 col-md-4">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach (['active' => 'Active', 'on_leave' => 'On Leave', 'dropped' => 'Dropped', 'graduated' => 'Graduated'] as $value => $label): ?>
                                    <option value="<?= $value ?>"<?= $student['overall_status'] === $value ? ' selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary" data-confirm="Change this student's status? Only active students can enroll." data-confirm-label="Update">Update status</button>
                        </div>
                    </form>
                    <div class="form-text mt-2">Graduated is only allowed once every subject in the student's curriculum is completed.</div>
                </div></div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header">Enrollment</div><div class="card-body"><dl class="row mb-0">
                    <?= $infoRow('ID number', $student['student_id_number']) ?>
                    <?= $infoRow('Username', $student['username']) ?>
                    <?= $infoRow('Account email', $student['account_email']) ?>
                    <?= $infoRow('Program', $student['program_code'] . ' - ' . $student['program_name']) ?>
                    <?= $infoRow('Year level', $student['year_level']) ?>
                    <?= $infoRow('Section', $student['section_name']) ?>
                    <?= $infoRow('Latest term', $student['school_year'] ? $student['school_year'] . ' Semester ' . $student['semester'] : null) ?>
                    <?= $infoRow('Student type', ucfirst($student['student_type'])) ?>
                </dl></div></div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header">Application</div><div class="card-body"><dl class="row mb-0">
                    <?php if ($application): ?>
                        <?= $infoRow('Application #', $application['application_id']) ?>
                        <?= $infoRow('Submitted', $application['application_date']) ?>
                        <?= $infoRow('Validated', $application['date_validated']) ?>
                        <?= $infoRow('Evaluated year level', $application['evaluated_year_level']) ?>
                        <?php if ($isTransferee): ?><?= $infoRow('Applicant reported year', $application['applicant_year_level'] ?? null) ?><?php endif; ?>
                        <dt class="col-sm-4">Documents</dt>
                        <dd class="col-sm-8">
                            <?php if ($documents): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="set_documents">
                                    <input type="hidden" name="id" value="<?= $studentId ?>">
                                    <?php foreach ($documents as $d): ?>
                                        <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                                            <span><?= htmlspecialchars($d['document_type']) ?></span>
                                            <select class="form-select form-select-sm w-auto" name="doc_status[<?= (int)$d['document_id'] ?>]">
                                                <option value="verified" <?= $d['status'] === 'verified' ? 'selected' : '' ?>>Verified</option>
                                                <option value="missing" <?= $d['status'] === 'missing' ? 'selected' : '' ?>>Missing</option>
                                                <?php if ($d['status'] === 'submitted'): ?><option value="submitted" selected>Submitted</option><?php endif; ?>
                                            </select>
                                        </div>
                                    <?php endforeach; ?>
                                    <button type="submit" class="btn btn-outline-primary btn-sm mt-2">Save documents</button>
                                </form>
                            <?php else: ?><span class="text-muted">&mdash;</span><?php endif; ?>
                        </dd>
                    <?php else: ?>
                        <dd class="col-12 text-muted mb-0">No application is linked to this student.</dd>
                    <?php endif; ?>
                </dl></div></div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header">Personal</div><div class="card-body"><dl class="row mb-0">
                    <?= $infoRow('Full name', $fullName) ?>
                    <?= $infoRow('Birthdate', $student['birthdate']) ?>
                    <?= $infoRow('Address', $join([$student['purok'], $student['barangay'], $student['municipality'], $student['province']], ', ')) ?>
                    <?= $infoRow('Contact no.', $student['contact_no']) ?>
                    <?= $infoRow('Email', $student['email_address']) ?>
                </dl></div></div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header">Family and guardian</div><div class="card-body"><dl class="row mb-0">
                    <?= $infoRow('Father', $join([$student['father_first_name'], $student['father_middle_name'], $student['father_last_name'], $student['father_suffix']])) ?>
                    <?= $infoRow('Father occupation', $student['father_occupation']) ?>
                    <?= $infoRow('Mother', $join([$student['mother_first_name'], $student['mother_middle_name'], $student['mother_maiden_name']])) ?>
                    <?= $infoRow('Mother occupation', $student['mother_occupation']) ?>
                    <?= $infoRow('Guardian', $student['guardian_name']) ?>
                    <?= $infoRow('Relationship', $student['guardian_relationship']) ?>
                    <?= $infoRow('Guardian contact', $student['guardian_contact_no']) ?>
                </dl></div></div>
            </div>
        </div>

    <?php elseif ($tab === 'schedule'): ?>
        <p class="text-muted">Classes for the latest enrollment<?= $student['school_year'] ? ' (' . htmlspecialchars($student['school_year'] . ' Semester ' . $student['semester']) . ')' : '' ?>.</p>
        <?= $events ? renderScheduleGrid($events) : '<div class="alert alert-info">No classes on this enrollment yet.</div>' ?>

    <?php elseif ($tab === 'subjects'): ?>
        <?php foreach ($byEnrollment as $block): $h = $block['head']; ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between">
                    <span><?= htmlspecialchars($h['school_year'] . ' Semester ' . $h['semester']) ?> · Year <?= (int)$h['year_level'] ?><?= $h['section_name'] ? ' · ' . htmlspecialchars($h['section_name']) : '' ?></span>
                    <span><?= statusBadge($h['status']) ?> <?= statusBadge($h['student_standing']) ?></span>
                </div>
                <?php if (!empty($block['rows'])): ?>
                    <div class="table-responsive"><table class="table table-sm mb-0">
                        <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Grade</th><th>Remarks</th></tr></thead>
                        <tbody>
                        <?php foreach ($block['rows'] as $r): ?>
                            <tr>
                                <td><?= htmlspecialchars($r['subject_code']) ?></td>
                                <td><?= htmlspecialchars($r['subject_name']) ?></td>
                                <td><?= $units($r['units']) ?></td>
                                <td><?= $r['grade'] !== null ? number_format((float)$r['grade'], 2) : '<span class="text-muted">&mdash;</span>' ?></td>
                                <td><?= htmlspecialchars((string)$r['remarks']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table></div>
                <?php else: ?>
                    <div class="card-body text-muted">No subjects on this enrollment.</div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (!$byEnrollment): ?><div class="alert alert-info">No enrollments yet.</div><?php endif; ?>

        <?php if ($transferCredits || $shiftCredits): ?>
            <div class="card mb-3">
                <div class="card-header">Credited subjects</div>
                <div class="table-responsive"><table class="table table-sm mb-0">
                    <thead><tr><th>Code</th><th>Subject</th><th>Source</th><th>Grade</th></tr></thead>
                    <tbody>
                    <?php foreach ($transferCredits as $c): ?>
                        <tr><td><?= htmlspecialchars($c['subject_code']) ?></td><td><?= htmlspecialchars($c['subject_name']) ?></td>
                            <td><?= htmlspecialchars($c['previous_school']) ?></td><td><?= number_format((float)$c['previous_grade'], 2) ?></td></tr>
                    <?php endforeach; ?>
                    <?php foreach ($shiftCredits as $c): ?>
                        <tr><td><?= htmlspecialchars($c['subject_code']) ?></td><td><?= htmlspecialchars($c['subject_name']) ?></td>
                            <td>Program shift</td><td><?= $c['grade'] !== null ? number_format((float)$c['grade'], 2) : '&mdash;' ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <?php
        $linkState = accountActivationStatuses($pdo, [(int)$student['account_id']])[(int)$student['account_id']] ?? 'active';
        $stateBadges = ['active' => ['Active', 'success'], 'pending' => ['Invite pending', 'warning'], 'expired' => ['Invite expired', 'danger']];
        [$stateLabel, $stateTone] = $stateBadges[$linkState] ?? $stateBadges['active'];
        $emailOnFile = trim((string)$student['account_email']);
        $canSendLink = $emailOnFile !== '' && (int)$student['account_active'] === 1;
        $isActivated = $linkState === 'active';
        $linkConfirm = $isActivated
            ? 'Email a reset link, valid for 30 minutes, to ' . $emailOnFile . '? Their current password keeps working until they use it.'
            : 'Email a fresh 24-hour invite to ' . $emailOnFile . '? Any earlier invite stops working.';
        $linkLabel = $isActivated ? 'Send reset link' : 'Resend invite';
        ?>
        <?php if ($linkMessage): ?><div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($linkMessage) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
        <?php if ($linkWarning): ?><div class="alert alert-warning alert-dismissible fade show"><?= htmlspecialchars($linkWarning) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <div class="card"><div class="card-body">
            <dl class="row mb-3">
                <dt class="col-sm-3">Username</dt><dd class="col-sm-9"><code><?= htmlspecialchars($student['username']) ?></code></dd>
                <dt class="col-sm-3">Email on file</dt><dd class="col-sm-9"><?= $emailOnFile !== '' ? htmlspecialchars($emailOnFile) : '<span class="text-muted">none</span>' ?></dd>
                <dt class="col-sm-3">Account status</dt><dd class="col-sm-9"><span class="badge text-bg-<?= $stateTone ?>"><?= $stateLabel ?></span></dd>
            </dl>
            <p class="small text-muted">
                Passwords are never shown. <?= $isActivated
                    ? 'The student chose their own password. If they forgot it, send a reset link.'
                    : 'The student has not chosen a password yet. The invite link is only ever emailed.' ?>
            </p>
            <div class="d-flex flex-wrap gap-2">
                <form method="post">
                    <input type="hidden" name="action" value="send_link">
                    <input type="hidden" name="id" value="<?= $studentId ?>">
                    <button type="submit" class="btn btn-outline-warning"<?= $canSendLink ? '' : ' disabled' ?>
                            data-confirm="<?= htmlspecialchars($linkConfirm) ?>"
                            data-confirm-label="<?= $linkLabel ?>" data-confirm-tone="warning"><?= $linkLabel ?></button>
                </form>
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#changeEmailModal">Change email</button>
            </div>
            <?php if (!$canSendLink): ?><div class="form-text mt-2">No link can be sent until a valid email is on file.</div><?php endif; ?>
        </div></div>

        <div class="modal fade" id="changeEmailModal" tabindex="-1" aria-labelledby="changeEmailTitle" aria-hidden="true">
            <div class="modal-dialog"><div class="modal-content">
                <form method="post" id="changeEmailForm">
                    <input type="hidden" name="action" value="change_email">
                    <input type="hidden" name="id" value="<?= $studentId ?>">
                    <div class="modal-header">
                        <h2 class="modal-title h5" id="changeEmailTitle">Change email</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <?php if ($emailError): ?><div class="alert alert-danger js-modal-alert"><?= htmlspecialchars($emailError) ?></div><?php endif; ?>
                        <p class="small text-muted mb-2">Current email: <?= $emailOnFile !== '' ? htmlspecialchars($emailOnFile) : 'none' ?></p>
                        <label class="form-label" for="newEmail">New email</label>
                        <input type="email" class="form-control" id="newEmail" name="email" maxlength="255" required
                               value="<?= htmlspecialchars((string)($_POST['email'] ?? '')) ?>">
                        <div class="alert alert-warning small mt-3 mb-0 d-none" id="emailWarn">
                            <div class="fw-semibold mb-1">Change this student's email?</div>
                            <div class="mb-1"><span id="emailOld"></span> &rarr; <span id="emailNew"></span></div>
                            <div class="mb-2">Whoever controls the new address can reset this account's password. Earlier password links stop working and the old address is notified.<?= $isActivated ? '' : ' A fresh invite goes to the new address.' ?></div>
                            <button type="submit" class="btn btn-sm btn-warning">Confirm change</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="emailBack">Go back</button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="emailContinue">Continue</button>
                    </div>
                </form>
            </div></div>
        </div>
        <script>
        (function () {
            var modalEl = document.getElementById('changeEmailModal');
            var form = document.getElementById('changeEmailForm');
            var input = document.getElementById('newEmail');
            var warn = document.getElementById('emailWarn');
            var cont = document.getElementById('emailContinue');
            var current = <?= json_encode($emailOnFile, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            function reset() { warn.classList.add('d-none'); cont.classList.remove('d-none'); }

            cont.addEventListener('click', function () {
                if (!form.reportValidity()) { return; }
                var next = input.value.trim();
                if (next.toLowerCase() === current.toLowerCase()) { form.submit(); return; }
                document.getElementById('emailOld').textContent = current || '(none)';
                document.getElementById('emailNew').textContent = next;
                cont.classList.add('d-none');
                warn.classList.remove('d-none');
            });
            document.getElementById('emailBack').addEventListener('click', reset);
            modalEl.addEventListener('hidden.bs.modal', function () {
                reset();
                modalEl.querySelectorAll('.js-modal-alert').forEach(function (a) { a.remove(); });
            });
<?php if ($emailError): ?>
            document.addEventListener('DOMContentLoaded', function () { bootstrap.Modal.getOrCreateInstance(modalEl).show(); });
<?php endif; ?>
        })();
        </script>
    <?php endif; ?>
</div>
</body>
</html>
