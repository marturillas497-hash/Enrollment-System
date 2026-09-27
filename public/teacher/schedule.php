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

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$dayIndex = array_flip($days);

// Time-slot grid, not just a day-grouped list. Range is whatever the actual
// classes span, rounded out to the hour, with a sane default when there's
// nothing scheduled yet.
$SLOT_MIN = 30;
$earliest = 7 * 60;
$latest = 18 * 60;
foreach ($offerings as $o) {
    [$sh, $sm] = array_map('intval', explode(':', $o['start_time']));
    [$eh, $em] = array_map('intval', explode(':', $o['end_time']));
    $earliest = min($earliest, $sh * 60 + $sm);
    $latest = max($latest, $eh * 60 + $em);
}
$earliest = (int)(floor($earliest / 60) * 60);
$latest = (int)(ceil($latest / 60) * 60);
$slotCount = max(1, (int)(($latest - $earliest) / $SLOT_MIN));

$slotLine = function (string $time) use ($earliest, $SLOT_MIN): int {
    [$h, $m] = array_map('intval', explode(':', $time));
    return 2 + (int)floor((($h * 60 + $m) - $earliest) / $SLOT_MIN); // +2: row 1 is the day header
};
$fmtTime = fn(string $t) => date('g:i A', strtotime($t));
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

    <?php if (empty($offerings)): ?>
        <p class="text-muted">No classes scheduled yet.</p>
    <?php else: ?>
        <div class="table-responsive">
        <div class="schedule-grid"
             style="grid-template-columns: 64px repeat(6, 1fr); grid-template-rows: auto repeat(<?= $slotCount ?>, 26px); min-width: 700px;">
            <div class="sg-corner"></div>
            <?php foreach ($days as $i => $day): ?>
                <div class="sg-day-header" style="grid-column: <?= $i + 2 ?>;"><?= htmlspecialchars(substr($day, 0, 3)) ?></div>
            <?php endforeach; ?>

            <?php for ($slot = 0; $slot < $slotCount; $slot++): ?>
                <?php $minutesAtSlot = $earliest + $slot * $SLOT_MIN; ?>
                <?php if ($minutesAtSlot % 60 === 0): ?>
                    <div class="sg-time-label" style="grid-row: <?= $slot + 2 ?>;">
                        <?= date('g A', mktime((int)($minutesAtSlot / 60), 0, 0)) ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($days as $i => $day): ?>
                    <div class="sg-cell" style="grid-row: <?= $slot + 2 ?>; grid-column: <?= $i + 2 ?>;"></div>
                <?php endforeach; ?>
            <?php endfor; ?>

            <?php foreach ($offerings as $o): ?>
                <?php if (!isset($dayIndex[$o['day_of_week']])) continue; ?>
                <div class="sg-event" style="
                    grid-column: <?= $dayIndex[$o['day_of_week']] + 2 ?>;
                    grid-row: <?= $slotLine($o['start_time']) ?> / <?= $slotLine($o['end_time']) ?>;">
                    <strong><?= htmlspecialchars($o['subject_code']) ?></strong>
                    <span><?= htmlspecialchars($fmtTime($o['start_time']) . '–' . $fmtTime($o['end_time'])) ?></span>
                    <?php if ($o['room']): ?><span><?= htmlspecialchars($o['room']) ?></span><?php endif; ?>
                    <span><?= htmlspecialchars($o['section_name']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>