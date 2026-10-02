<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/schedule_helper.php';

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
    "SELECT s.subject_code, s.subject_name, sec.section_name, co.day_of_week, co.start_time, co.end_time, co.room
     FROM Class_Offering co
     JOIN Subject s ON s.subject_id = co.subject_id
     JOIN Section sec ON sec.section_id = co.section_id
     JOIN School_term st ON st.term_id = co.term_id
     WHERE co.teacher_id = :tid AND st.status = 'ongoing'
     ORDER BY co.start_time"
);
$stmt->execute(['tid' => $teacher['teacher_id']]);
$offerings = $stmt->fetchAll();

$events = array_map(fn($o) => [
    'day_of_week' => $o['day_of_week'], 'start_time' => $o['start_time'], 'end_time' => $o['end_time'],
    'title' => $o['subject_code'], 'lines' => [$o['room'], $o['section_name']],
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