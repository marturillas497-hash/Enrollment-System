<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['teacher']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Teacher WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$teacher = $stmt->fetch();

if ($teacher === false) {
    require __DIR__ . '/../../includes/navbar.php';
    echo '<div class="container"><div class="alert alert-danger">'
        . 'This account has no teacher profile on record. Contact the admin.</div></div>';
    exit;
}

$stmt = $pdo->prepare(
    "SELECT co.offering_id, s.subject_code, s.subject_name, sec.section_name, p.program_code,
            st.school_year, st.semester, st.status AS term_status, co.day_of_week, co.start_time, co.end_time,
            (SELECT COUNT(*) FROM Enrolled_subject es WHERE es.offering_id = co.offering_id) AS enrolled_count
     FROM Class_Offering co
     JOIN Subject s ON s.subject_id = co.subject_id
     JOIN Section sec ON sec.section_id = co.section_id
     JOIN Program p ON p.program_id = sec.program_id
     JOIN School_term st ON st.term_id = co.term_id
     WHERE co.teacher_id = :tid
     ORDER BY st.term_id DESC, s.subject_code"
);
$stmt->execute(['tid' => $teacher['teacher_id']]);
$offerings = $stmt->fetchAll();

// Summary counts, scoped to the current ongoing term only — a teacher's total
// students/ungraded count from every past term isn't a useful "needs attention" number.
$totalStudentsThisTerm = 0;
$ungradedCount = 0;
foreach ($offerings as $o) {
    if ($o['term_status'] === 'ongoing') {
        $totalStudentsThisTerm += (int)$o['enrolled_count'];

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM Enrolled_subject WHERE offering_id = :oid AND grade IS NULL'
        );
        $stmt->execute(['oid' => $o['offering_id']]);
        $ungradedCount += (int)$stmt->fetchColumn();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Teacher Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-1"><?= htmlspecialchars($teacher['first_name'] . ' ' . $teacher['last_name']) ?></h1>
    <p class="text-muted mb-4">Your class offerings, all terms</p>

    <div class="row mb-4">
        <div class="col-md-6 mb-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="display-6"><?= $totalStudentsThisTerm ?></div>
                    <div class="text-muted">Students This Term</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="card text-center">
                <div class="card-body">
                    <div class="display-6"><?= $ungradedCount ?></div>
                    <div class="text-muted">Ungraded This Term</div>
                </div>
            </div>
        </div>
    </div>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><th>Term</th><th>Subject</th><th>Section</th><th>Schedule</th><th>Students</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($offerings as $o): ?>
            <tr>
                <td>
                    <?= htmlspecialchars($o['school_year'] . ' S' . $o['semester']) ?>
                    <?php if ($o['term_status'] === 'ongoing'): ?><span class="badge bg-success">ongoing</span><?php endif; ?>
                </td>
                <td><?= htmlspecialchars($o['subject_code'] . ' — ' . $o['subject_name']) ?></td>
                <td><?= htmlspecialchars($o['program_code'] . ' ' . $o['section_name']) ?></td>
                <td><?= htmlspecialchars(($o['day_of_week'] ?? '') . ' ' . ($o['start_time'] ?? '') . '–' . ($o['end_time'] ?? '')) ?></td>
                <td><?= $o['enrolled_count'] ?></td>
                <td><a href="/enrollment-system/public/teacher/grade-entry.php?offering_id=<?= $o['offering_id'] ?>"
                       class="btn btn-sm btn-outline-primary">Enter Grades</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($offerings)): ?><tr><td colspan="6" class="text-muted">No class offerings assigned yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>
