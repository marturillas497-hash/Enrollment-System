<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['student']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Student WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$student = $stmt->fetch();

$stmt = $pdo->prepare(
    'SELECT st.school_year, st.semester, s.subject_code, s.subject_name, s.units, es.grade, es.remarks
     FROM Enrolled_subject es
     JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
     JOIN Class_Offering co ON co.offering_id = es.offering_id
     JOIN Subject s ON s.subject_id = co.subject_id
     JOIN School_term st ON st.term_id = e.term_id
     WHERE e.student_id = :sid
     ORDER BY st.term_id DESC, s.subject_code'
);
$stmt->execute(['sid' => $student['student_id']]);
$rows = $stmt->fetchAll();

// Group by term for a cleaner read, same pattern as the curriculum-by-year/semester grouping.
$grouped = [];
foreach ($rows as $r) {
    $key = $r['school_year'] . ' — Semester ' . $r['semester'];
    $grouped[$key][] = $r;
}

// Average of the numeric grades posted in each term. Ungraded subjects are left out
// rather than counted as zero, since a null grade means "not graded yet", not a fail.
$termAverages = [];
foreach ($grouped as $termLabel => $termRows) {
    $numeric = array_filter(array_column($termRows, 'grade'), fn($g) => $g !== null);
    $termAverages[$termLabel] = count($numeric) > 0 ? array_sum($numeric) / count($numeric) : null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Grades</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Grades</h1>

    <?php foreach ($grouped as $termLabel => $termRows): ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><?= htmlspecialchars($termLabel) ?></span>
                <?php if ($termAverages[$termLabel] !== null): ?>
                    <span class="text-muted small">Average: <?= number_format($termAverages[$termLabel], 2) ?></span>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
            <table class="table mb-0">
                <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Grade</th><th>Remarks</th></tr></thead>
                <tbody>
                <?php foreach ($termRows as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['subject_code']) ?></td>
                        <td><?= htmlspecialchars($r['subject_name']) ?></td>
                        <td><?= $r['units'] ?></td>
                        <td><?= $r['grade'] !== null ? htmlspecialchars($r['grade']) : '<span class="text-muted">—</span>' ?></td>
                        <td><?= $r['remarks'] ? htmlspecialchars($r['remarks']) : '<span class="text-muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if (empty($grouped)): ?><div class="alert alert-secondary">No grades on record yet.</div><?php endif; ?>
</div>
</body>
</html>
