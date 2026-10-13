<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/schedule_helper.php';

$user = requireRole(['student']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Student WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$student = $stmt->fetch();

$stmt = $pdo->prepare(
    "SELECT * FROM Enrollment WHERE student_id = :sid AND status <> 'rejected' ORDER BY enrollment_id DESC LIMIT 1"
);
$stmt->execute(['sid' => $student['student_id']]);
$latestEnrollment = $stmt->fetch();

$offerings = [];
if ($latestEnrollment) {
    $stmt = $pdo->prepare(
        'SELECT s.subject_code, s.subject_name, co.day_of_week, co.start_time, co.end_time, co.room,
                t.last_name, t.first_name
         FROM Enrolled_subject es
         JOIN (SELECT o.offering_id, o.subject_id, o.teacher_id, o.section_id, o.term_id, cm.meeting_id, cm.day_of_week, cm.start_time, cm.end_time, cm.room_id, r.room_code AS room FROM Class_Offering o LEFT JOIN Class_Meeting cm ON cm.offering_id = o.offering_id LEFT JOIN Room r ON r.room_id = cm.room_id) co ON co.offering_id = es.offering_id
         JOIN Subject s ON s.subject_id = co.subject_id
         JOIN Teacher t ON t.teacher_id = co.teacher_id
         WHERE es.enrollment_id = :eid
         ORDER BY co.start_time'
    );
    $stmt->execute(['eid' => $latestEnrollment['enrollment_id']]);
    $offerings = $stmt->fetchAll();
}

$events = array_map(fn($o) => [
    'day_of_week' => $o['day_of_week'], 'start_time' => $o['start_time'], 'end_time' => $o['end_time'],
    'title' => $o['subject_code'], 'lines' => [$o['room'], $o['last_name'] . ', ' . $o['first_name']],
], $offerings);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Schedule</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/schedule.css">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Schedule</h1>
    <p class="text-muted">Your current-term classes.</p>

    <?= renderScheduleGrid($events) ?>
</div>
</body>
</html>