<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/password_helper.php';
require_once __DIR__ . '/../../src/helpers/mail_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$message = '';
$error = '';
$regenerated = null; // ['username'=>, 'password'=>] shown once after regenerating
$mailWarning = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate_password') {
    $accountId = $_POST['account_id'] ?? '';

    // Confirm both that this is a student account AND that their current program (via their
    // latest enrollment) is in this registrar's own department.
    $stmt = $pdo->prepare(
        "SELECT a.username, a.email, s.first_name, s.last_name FROM Accounts a
         JOIN Student s ON s.account_id = a.account_id
         JOIN Enrollment e ON e.enrollment_id = (
             SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id
             ORDER BY e2.enrollment_id DESC LIMIT 1
         )
         JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
         JOIN Program p ON p.program_id = c.program_id
         WHERE a.account_id = :id AND a.role = 'student' AND p.department_id = :dept"
    );
    $stmt->execute(['id' => $accountId, 'dept' => $myDepartmentId]);
    $account = $stmt->fetch();

    if (!$account) {
        $error = 'Student account not found in your department.';
    } else {
        $newPassword = generateTempPassword();
        $stmt = $pdo->prepare(
            'UPDATE Accounts SET password_hash = :hash, must_change_password = 1 WHERE account_id = :id'
        );
        $stmt->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $accountId]);

        $regenerated = ['username' => $account['username'], 'password' => $newPassword, 'email' => $account['email']];

        if ($account['email']) {
            $studentName = $account['first_name'] . ' ' . $account['last_name'];
            $mailSent = sendPasswordResetEmail($account['email'], $studentName, $account['username'], $newPassword);
            if (!$mailSent) {
                $mailWarning = 'The password was reset, but the email could not be sent. Share the credentials below manually.';
            }
        } else {
            $mailWarning = 'No email is on file for this student. Share the credentials below manually.';
        }
    }
}

// --- Search, filters and sorting. Every value is checked against a whitelist or the
// department's own data before it goes near the SQL. ---
$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';

// Filter options are built from the students this registrar can actually see (latest
// enrollment, own department), and a filter is only shown when it has more than one
// choice. A dropdown with a single option would just be clutter.
$scopeSql = "FROM Student s
             JOIN Enrollment e ON e.enrollment_id = (
                 SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id
                 ORDER BY e2.enrollment_id DESC LIMIT 1
             )
             JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
             JOIN Program p ON p.program_id = c.program_id
             WHERE p.department_id = :dept";

$stmt = $pdo->prepare("SELECT DISTINCT p.program_id, p.program_code $scopeSql ORDER BY p.program_code");
$stmt->execute(['dept' => $myDepartmentId]);
$programOptions = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // program_id => program_code

$stmt = $pdo->prepare("SELECT DISTINCT e.year_level $scopeSql ORDER BY e.year_level");
$stmt->execute(['dept' => $myDepartmentId]);
$yearOptions = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$stmt = $pdo->prepare("SELECT DISTINCT s.overall_status $scopeSql");
$stmt->execute(['dept' => $myDepartmentId]);
$allStatusLabels = ['active' => 'Active', 'on_leave' => 'On Leave', 'graduated' => 'Graduated', 'dropped' => 'Dropped'];
$statusOptions = array_intersect_key($allStatusLabels, array_flip($stmt->fetchAll(PDO::FETCH_COLUMN)));

$showProgramFilter = count($programOptions) > 1;
$showYearFilter    = count($yearOptions) > 1;
$showStatusFilter  = count($statusOptions) > 1;

// Names are shown as "First Last", so the name sorts use first name to match.
$sortOptions = [
    'name_az' => ['label' => 'Name (A to Z)',      'sql' => 's.first_name, s.last_name'],
    'name_za' => ['label' => 'Name (Z to A)',      'sql' => 's.first_name DESC, s.last_name DESC'],
    'last_az' => ['label' => 'Last name (A to Z)', 'sql' => 's.last_name, s.first_name'],
    'id'      => ['label' => 'ID number',          'sql' => 's.student_id_number'],
];

$filterProgram = 0;
if (is_string($_GET['program'] ?? null) && ctype_digit($_GET['program']) && isset($programOptions[(int) $_GET['program']])) {
    $filterProgram = (int) $_GET['program'];
}
$filterYear = 0;
if (is_string($_GET['year'] ?? null) && ctype_digit($_GET['year']) && in_array((int) $_GET['year'], $yearOptions, true)) {
    $filterYear = (int) $_GET['year'];
}
$filterStatus = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
if (!isset($statusOptions[$filterStatus])) { $filterStatus = ''; }
$sortKey = is_string($_GET['sort'] ?? null) ? $_GET['sort'] : 'name_az';
if (!isset($sortOptions[$sortKey])) { $sortKey = 'name_az'; }

$sql = "SELECT s.student_id, s.student_id_number, s.last_name, s.first_name, s.overall_status, s.account_id,
               s.student_type,
               p.program_code, e.year_level, sec.section_name, term.school_year, term.semester
        FROM Student s
        JOIN Enrollment e ON e.enrollment_id = (
            SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id
            ORDER BY e2.enrollment_id DESC LIMIT 1
        )
        JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
        JOIN Program p ON p.program_id = c.program_id
        LEFT JOIN Section sec ON sec.section_id = e.section_id
        LEFT JOIN School_term term ON term.term_id = e.term_id
        WHERE p.department_id = :dept";
$params = ['dept' => $myDepartmentId];
if ($search !== '') {
    $sql .= ' AND (s.last_name LIKE :q1 OR s.first_name LIKE :q2 OR s.student_id_number LIKE :q3)';
    $params['q1'] = "%$search%";
    $params['q2'] = "%$search%";
    $params['q3'] = "%$search%";
}
if ($filterProgram) { $sql .= ' AND p.program_id = :program'; $params['program'] = $filterProgram; }
if ($filterYear)    { $sql .= ' AND e.year_level = :year';    $params['year'] = $filterYear; }
if ($filterStatus !== '') { $sql .= ' AND s.overall_status = :status'; $params['status'] = $filterStatus; }
$sql .= ' ORDER BY ' . $sortOptions[$sortKey]['sql'];

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();
$isFiltered = ($search !== '' || $filterProgram || $filterYear || $filterStatus !== '' || $sortKey !== 'name_az');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Students</h1>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($mailWarning): ?>
        <div class="alert alert-warning"><?= htmlspecialchars($mailWarning) ?></div>
    <?php endif; ?>

    <?php if ($regenerated): ?>
        <div class="alert alert-success">
            <strong>New temporary password generated.</strong>
            <?php if (!$mailWarning): ?>
                Email has been sent to <strong><?= htmlspecialchars($regenerated['email']) ?></strong>.
            <?php endif; ?>
            Their old password no longer works.
            <dl class="row mb-0 mt-2">
                <dt class="col-sm-2">Username</dt>
                <dd class="col-sm-10"><code><?= htmlspecialchars($regenerated['username']) ?></code></dd>
                <dt class="col-sm-2">New Password</dt>
                <dd class="col-sm-10"><code><?= htmlspecialchars($regenerated['password']) ?></code></dd>
            </dl>
        </div>
    <?php endif; ?>

    <form method="get" class="toolbar">
        <div class="toolbar-field toolbar-field-wide">
            <label for="q">Search</label>
            <input type="text" class="form-control" id="q" name="q" placeholder="Name or ID number"
                   value="<?= htmlspecialchars($search) ?>">
        </div>
        <?php if ($showProgramFilter): ?>
        <div class="toolbar-field toolbar-field-sm">
            <label for="program">Program</label>
            <select name="program" id="program" class="form-select" onchange="this.form.submit()">
                <option value="">All programs</option>
                <?php foreach ($programOptions as $pid => $code): ?>
                    <option value="<?= (int) $pid ?>" <?= $filterProgram === (int) $pid ? 'selected' : '' ?>><?= htmlspecialchars($code) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($showYearFilter): ?>
        <div class="toolbar-field toolbar-field-sm">
            <label for="year">Year level</label>
            <select name="year" id="year" class="form-select" onchange="this.form.submit()">
                <option value="">All years</option>
                <?php foreach ($yearOptions as $y): ?>
                    <option value="<?= $y ?>" <?= $filterYear === $y ? 'selected' : '' ?>>Year <?= $y ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($showStatusFilter): ?>
        <div class="toolbar-field toolbar-field-sm">
            <label for="status">Status</label>
            <select name="status" id="status" class="form-select" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach ($statusOptions as $value => $label): ?>
                    <option value="<?= $value ?>" <?= $filterStatus === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="toolbar-field toolbar-field-sm">
            <label for="sort">Sort by</label>
            <select name="sort" id="sort" class="form-select" onchange="this.form.submit()">
                <?php foreach ($sortOptions as $value => $opt): ?>
                    <option value="<?= $value ?>" <?= $sortKey === $value ? 'selected' : '' ?>><?= htmlspecialchars($opt['label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary">Search</button>
        <?php if ($isFiltered): ?><a href="students.php" class="btn btn-outline-secondary">Reset</a><?php endif; ?>
        <span class="toolbar-count">Showing <?= count($students) ?> student<?= count($students) === 1 ? '' : 's' ?></span>
    </form>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead>
            <tr><th>ID Number</th><th>Name</th><th>Program</th><th>Section</th><th>Term</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($students as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['student_id_number']) ?></td>
                <td><?= htmlspecialchars($s['first_name'] . ' ' . $s['last_name']) ?></td>
                <td><?= htmlspecialchars($s['program_code']) ?></td>
                <td><?= $s['section_name'] ? htmlspecialchars($s['section_name'] . ' (Yr ' . $s['year_level'] . ')') : '<span class="text-muted">—</span>' ?></td>
                <td><?= $s['school_year'] ? htmlspecialchars($s['school_year'] . ' S' . $s['semester']) : '<span class="text-muted">—</span>' ?></td>
                <td><?= statusBadge($s['overall_status']) ?></td>
                <td>
                    <form method="post" class="d-inline" onsubmit="return confirm('Generate a new temporary password for this student? Their current password will stop working immediately.')">
                        <input type="hidden" name="action" value="regenerate_password">
                        <input type="hidden" name="account_id" value="<?= $s['account_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-warning">Regenerate Password</button>
                    </form>
                    <?php if ($s['student_type'] === 'transferee'): ?>
                        <a href="<?= BASE_URL ?>/registrar/transferee-credit.php?student_id=<?= $s['student_id'] ?>"
                           class="btn btn-sm btn-outline-info">Credit Eval</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($students)): ?><tr><td colspan="7" class="text-muted"><?= $isFiltered ? 'No students match these filters.' : 'No students found.' ?></td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>