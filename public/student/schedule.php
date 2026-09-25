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

$offerings = [];
if ($latestEnrollment) {
    $stmt = $pdo->prepare(
        'SELECT s.subject_code, s.subject_name, co.day_of_week, co.start_time, co.end_time, co.room,
                t.last_name, t.first_name
         FROM Enrolled_subject es
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         JOIN Subject s ON s.subject_id = co.subject_id
         JOIN Teacher t ON t.teacher_id = co.teacher_id
         WHERE es.enrollment_id = :eid
         ORDER BY co.start_time'
    );
    $stmt->execute(['eid' => $latestEnrollment['enrollment_id']]);
    $offerings = $stmt->fetchAll();
}

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$byDay = array_fill_keys($days, []);
foreach ($offerings as $o) {
    if (isset($byDay[$o['day_of_week']])) {
        $byDay[$o['day_of_week']][] = $o;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Schedule</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Schedule</h1>
    <p class="text-muted">Your current-term classes, by day.</p>

    <div class="row">
        <?php foreach ($days as $day): ?>
            <div class="col-md-4 mb-3">
                <div class="card h-100">
                    <div class="card-header"><?= htmlspecialchars($day) ?></div>
                    <div class="card-body">
                        <?php if (empty($byDay[$day])): ?>
                            <p class="text-muted small mb-0">No classes</p>
                        <?php else: ?>
                            <?php foreach ($byDay[$day] as $o): ?>
                                <div class="mb-2 pb-2 border-bottom">
                                    <strong><?= htmlspecialchars($o['subject_code']) ?></strong><br>
                                    <span class="text-muted small">
                                        <?= htmlspecialchars($o['start_time'] . '–' . $o['end_time']) ?>
                                        <?php if ($o['room']): ?> · <?= htmlspecialchars($o['room']) ?><?php endif; ?><br>
                                        <?= htmlspecialchars($o['last_name'] . ', ' . $o['first_name']) ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
