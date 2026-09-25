<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

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
    <title>My Subjects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">My Subjects <span class="badge bg-light text-dark"><?= rtrim(rtrim(number_format($totalUnits, 2), '0'), '.') ?> units</span></h1>
    <div class="table-responsive">
    <table class="table table-hover bg-white">
        <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Instructor</th><th>Schedule</th><th>Grade</th></tr></thead>
        <tbody>
        <?php foreach ($creditedSubjects as $c): ?>
            <tr class="table-info">
                <td colspan="2"><?= htmlspecialchars($c['subject_code'] . ' — ' . $c['subject_name']) ?></td>
                <td colspan="3" class="text-muted"><?= htmlspecialchars($c['source_note']) ?></td>
                <td><span class="badge bg-info text-dark">CREDITED</span></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($subjects as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['subject_code']) ?></td>
                <td><?= htmlspecialchars($s['subject_name']) ?></td>
                <td><?= $s['units'] ?></td>
                <td><?= htmlspecialchars($s['last_name'] . ', ' . $s['first_name']) ?></td>
                <td><?= htmlspecialchars($s['day_of_week'] . ' ' . $s['start_time'] . '–' . $s['end_time'] . ' ' . ($s['room'] ?? '')) ?></td>
                <td><?= $s['grade'] !== null ? htmlspecialchars($s['grade']) : '<span class="text-muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($subjects) && empty($creditedSubjects)): ?><tr><td colspan="6" class="text-muted">No subjects on record.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
</body>
</html>
