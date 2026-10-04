<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/password_helper.php';
require_once __DIR__ . '/../../src/helpers/mail_helper.php';
require_once __DIR__ . '/../../src/helpers/schedule_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$studentId = $_GET['id'] ?? ($_POST['id'] ?? null);

$stmt = $pdo->prepare(
    "SELECT s.*, a.username, a.email AS account_email,
            e.enrollment_id AS latest_enrollment_id, e.year_level, e.student_standing,
            sec.section_name, p.program_code, p.program_name, term.school_year, term.semester
     FROM Student s
     JOIN Accounts a ON a.account_id = s.account_id
     JOIN Enrollment e ON e.enrollment_id = (
         SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id
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
$regenerated = null;
$mailWarning = '';

$flash = flashGet('regenerated');
if ($flash) {
    $regenerated = $flash['regenerated'];
    $mailWarning = $flash['mailWarning'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate_password') {
    $newPassword = generateTempPassword();
    $pdo->prepare(
        'UPDATE Accounts SET password_hash = :hash, must_change_password = 1, session_version = session_version + 1 WHERE account_id = :id'
    )->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $student['account_id']]);

    $regenerated = ['username' => $student['username'], 'password' => $newPassword, 'email' => $student['account_email']];
    if ($student['account_email']) {
        $mailSent = sendPasswordResetEmail($student['account_email'], $student['first_name'] . ' ' . $student['last_name'], $student['username'], $newPassword);
        if (!$mailSent) {
            $mailWarning = 'The password was reset, but the email could not be sent. Share the credentials below manually.';
        }
    } else {
        $mailWarning = 'No email is on file for this student. Share the credentials below manually.';
    }

    flashSet('regenerated', ['regenerated' => $regenerated, 'mailWarning' => $mailWarning]);
    header('Location: ' . BASE_URL . '/registrar/student-view.php?id=' . $studentId . '&tab=password');
    exit;
}

$tabs = ['general', 'schedule', 'subjects', 'password'];
$tab = in_array($_GET['tab'] ?? '', $tabs, true) ? $_GET['tab'] : 'general';
$isTransferee = $student['student_type'] === 'transferee';

$application = null;
$documents = [];
if ($student['application_id']) {
    $stmt = $pdo->prepare('SELECT * FROM Admission_Application WHERE application_id = :id');
    $stmt->execute(['id' => $student['application_id']]);
    $application = $stmt->fetch() ?: null;

    $stmt = $pdo->prepare('SELECT document_type, status FROM Enrollment_Document WHERE application_id = :id ORDER BY document_id');
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
         WHERE r.student_id = :sid ORDER BY sub.subject_code'
    );
    $stmt->execute(['sid' => $studentId]);
    $shiftCredits = $stmt->fetchAll();
}

$row = fn($label, $value) => '<dt class="col-sm-4">' . htmlspecialchars($label) . '</dt><dd class="col-sm-8">'
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

    <?= tabBar([
        'general' => ['label' => 'General', 'href' => $base . 'general'],
        'schedule' => ['label' => 'Schedule', 'href' => $base . 'schedule'],
        'subjects' => ['label' => 'Subjects', 'href' => $base . 'subjects'],
        'password' => ['label' => 'Password', 'href' => $base . 'password'],
    ], $tab) ?>

    <?php if ($tab === 'general'): ?>
        <div class="row g-3">
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header">Enrollment</div><div class="card-body"><dl class="row mb-0">
                    <?= $row('ID number', $student['student_id_number']) ?>
                    <?= $row('Username', $student['username']) ?>
                    <?= $row('Account email', $student['account_email']) ?>
                    <?= $row('Program', $student['program_code'] . ' — ' . $student['program_name']) ?>
                    <?= $row('Year level', $student['year_level']) ?>
                    <?= $row('Section', $student['section_name']) ?>
                    <?= $row('Latest term', $student['school_year'] ? $student['school_year'] . ' Semester ' . $student['semester'] : null) ?>
                    <?= $row('Student type', ucfirst($student['student_type'])) ?>
                </dl></div></div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header">Application</div><div class="card-body"><dl class="row mb-0">
                    <?php if ($application): ?>
                        <?= $row('Application #', $application['application_id']) ?>
                        <?= $row('Submitted', $application['application_date']) ?>
                        <?= $row('Validated', $application['date_validated']) ?>
                        <?= $row('Evaluated year level', $application['evaluated_year_level']) ?>
                        <?php if ($isTransferee): ?><?= $row('Applicant reported year', $application['applicant_year_level'] ?? null) ?><?php endif; ?>
                        <dt class="col-sm-4">Documents</dt>
                        <dd class="col-sm-8">
                            <?php foreach ($documents as $d): ?>
                                <div><?= htmlspecialchars($d['document_type']) ?> <?= statusBadge($d['status']) ?></div>
                            <?php endforeach; ?>
                            <?php if (!$documents): ?><span class="text-muted">&mdash;</span><?php endif; ?>
                        </dd>
                    <?php else: ?>
                        <dd class="col-12 text-muted mb-0">No application is linked to this student.</dd>
                    <?php endif; ?>
                </dl></div></div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header">Personal</div><div class="card-body"><dl class="row mb-0">
                    <?= $row('Full name', $fullName) ?>
                    <?= $row('Birthdate', $student['birthdate']) ?>
                    <?= $row('Address', $join([$student['purok'], $student['barangay'], $student['municipality'], $student['province']], ', ')) ?>
                    <?= $row('Contact no.', $student['contact_no']) ?>
                    <?= $row('Email', $student['email_address']) ?>
                </dl></div></div>
            </div>
            <div class="col-lg-6">
                <div class="card h-100"><div class="card-header">Family and guardian</div><div class="card-body"><dl class="row mb-0">
                    <?= $row('Father', $join([$student['father_first_name'], $student['father_middle_name'], $student['father_last_name'], $student['father_suffix']])) ?>
                    <?= $row('Father occupation', $student['father_occupation']) ?>
                    <?= $row('Mother', $join([$student['mother_first_name'], $student['mother_middle_name'], $student['mother_maiden_name']])) ?>
                    <?= $row('Mother occupation', $student['mother_occupation']) ?>
                    <?= $row('Guardian', $student['guardian_name']) ?>
                    <?= $row('Relationship', $student['guardian_relationship']) ?>
                    <?= $row('Guardian contact', $student['guardian_contact_no']) ?>
                </dl></div></div>
            </div>
        </div>

    <?php elseif ($tab === 'schedule'): ?>
        <p class="text-muted">Classes for the latest enrollment<?= $student['school_year'] ? ' (' . htmlspecialchars($student['school_year'] . ' Semester ' . $student['semester']) . ')' : '' ?>.</p>
        <?= $events ? renderScheduleGrid($events) : '<div class="alert alert-info">No classes on this enrollment yet.</div>' ?>

    <?php elseif ($tab === 'subjects'): ?>
        <?php if ($isTransferee): ?>
            <div class="mb-3 d-flex gap-2">
                <a href="<?= BASE_URL ?>/registrar/transferee-credit.php?student_id=<?= $studentId ?>&view=credits" class="btn btn-outline-info btn-sm">Credit evaluation</a>
                <a href="<?= BASE_URL ?>/registrar/transferee-credit.php?student_id=<?= $studentId ?>&view=add" class="btn btn-outline-primary btn-sm">Add subjects</a>
            </div>
        <?php endif; ?>

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
        <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($mailWarning): ?><div class="alert alert-warning"><?= htmlspecialchars($mailWarning) ?></div><?php endif; ?>
        <?php if ($regenerated): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <strong>New temporary password generated.</strong>
                <?php if (!$mailWarning): ?>Email has been sent to <strong><?= htmlspecialchars($regenerated['email']) ?></strong>.<?php endif; ?>
                Their old password no longer works.
                <dl class="row mb-0 mt-2">
                    <dt class="col-sm-2">Username</dt><dd class="col-sm-10"><code><?= htmlspecialchars($regenerated['username']) ?></code></dd>
                    <dt class="col-sm-2">New Password</dt><dd class="col-sm-10"><code><?= htmlspecialchars($regenerated['password']) ?></code></dd>
                </dl>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <div class="card"><div class="card-body">
            <p class="mb-1">Username: <code><?= htmlspecialchars($student['username']) ?></code></p>
            <p class="text-muted">Email on file: <?= $student['account_email'] ? htmlspecialchars($student['account_email']) : 'none' ?></p>
            <form method="post">
                <input type="hidden" name="action" value="regenerate_password">
                <input type="hidden" name="id" value="<?= $studentId ?>">
                <button type="submit" class="btn btn-outline-warning"
                        data-confirm="Generate a new temporary password for this student? Their current password will stop working immediately."
                        data-confirm-label="Regenerate" data-confirm-tone="warning">Regenerate Password</button>
            </form>
        </div></div>
    <?php endif; ?>
</div>
</body>
</html>
