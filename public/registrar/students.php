<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/password_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$message = '';
$error = '';
$regenerated = null; // ['username'=>, 'password'=>] shown once after regenerating

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'regenerate_password') {
    $accountId = $_POST['account_id'] ?? '';

    // Confirm both that this is a student account AND that their current program (via their
    // latest enrollment) is in this registrar's own department.
    $stmt = $pdo->prepare(
        "SELECT a.username FROM Accounts a
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

        $regenerated = ['username' => $account['username'], 'password' => $newPassword];
    }
}

$search = trim($_GET['q'] ?? '');
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
if ($search !== '') {
    $sql .= ' AND (s.last_name LIKE :q1 OR s.first_name LIKE :q2 OR s.student_id_number LIKE :q3)';
}
$sql .= ' ORDER BY s.last_name, s.first_name';

$stmt = $pdo->prepare($sql);
$params = ['dept' => $myDepartmentId];
if ($search !== '') {
    $params['q1'] = "%$search%";
    $params['q2'] = "%$search%";
    $params['q3'] = "%$search%";
}
$stmt->execute($params);
$students = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Students</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Students</h1>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($regenerated): ?>
        <div class="alert alert-success">
            <strong>New temporary password generated.</strong> Hand these to the student directly — this
            won't be shown again, and their old password no longer works.
            <dl class="row mb-0 mt-2">
                <dt class="col-sm-2">Username</dt>
                <dd class="col-sm-10"><code><?= htmlspecialchars($regenerated['username']) ?></code></dd>
                <dt class="col-sm-2">New Password</dt>
                <dd class="col-sm-10"><code><?= htmlspecialchars($regenerated['password']) ?></code></dd>
            </dl>
        </div>
    <?php endif; ?>

    <form method="get" class="d-flex mb-3 search-bar">
        <input type="text" class="form-control me-2" name="q" placeholder="Search by name or ID number"
               value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn btn-outline-primary">Search</button>
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
                <td><span class="badge bg-secondary"><?= htmlspecialchars($s['overall_status']) ?></span></td>
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
        <?php if (empty($students)): ?><tr><td colspan="7" class="text-muted">No students found.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>
