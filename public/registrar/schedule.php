<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/schedule_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$terms = $pdo->query(
    'SELECT DISTINCT st.term_id, st.school_year, st.semester, st.status
     FROM School_term st JOIN Class_Offering co ON co.term_id = st.term_id
     ORDER BY st.term_id DESC'
)->fetchAll();

$defaultTerm = $terms ? (string)$terms[0]['term_id'] : '';
foreach ($terms as $t) {
    if ($t['status'] === 'ongoing') { $defaultTerm = (string)$t['term_id']; break; }
}
$termId = is_string($_GET['term'] ?? null) ? $_GET['term'] : $defaultTerm;
if (!in_array($termId, array_map('strval', array_column($terms, 'term_id')), true)) { $termId = $defaultTerm; }

$mode = in_array($_GET['mode'] ?? '', ['teacher', 'room'], true) ? $_GET['mode'] : 'section';
$selected = is_string($_GET['sel'] ?? null) ? $_GET['sel'] : '';

$options = [];
if ($mode === 'section') {
    $stmt = $pdo->prepare(
        'SELECT sec.section_id, sec.section_name, sec.year_level, p.program_code
         FROM Section sec JOIN Program p ON p.program_id = sec.program_id
         WHERE p.department_id = :dept ORDER BY p.program_code, sec.year_level, sec.section_name'
    );
    $stmt->execute(['dept' => $myDepartmentId]);
    $rows = $stmt->fetchAll();
    $multi = count(array_unique(array_column($rows, 'program_code'))) > 1;
    foreach ($rows as $r) {
        $options[(string)$r['section_id']] = sectionLabel($r['year_level'], $r['section_name'], $multi ? $r['program_code'] : null);
    }
} elseif ($mode === 'teacher') {
    $stmt = $pdo->prepare('SELECT teacher_id, last_name, first_name FROM Teacher WHERE department_id = :dept ORDER BY last_name, first_name');
    $stmt->execute(['dept' => $myDepartmentId]);
    foreach ($stmt->fetchAll() as $r) {
        $options[(string)$r['teacher_id']] = $r['last_name'] . ', ' . $r['first_name'];
    }
} else {
    // Rooms are shared across departments, so this lists every room in use.
    $stmt = $pdo->prepare("SELECT DISTINCT room FROM Class_Offering WHERE room IS NOT NULL AND room <> '' AND term_id = :tid ORDER BY room");
    $stmt->execute(['tid' => $termId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $room) {
        $options[$room] = $room;
    }
}
if (!isset($options[$selected])) { $selected = (string)(array_key_first($options) ?? ''); }

$events = [];
if ($selected !== '' && $termId !== '') {
    $where = ['section' => 'co.section_id', 'teacher' => 'co.teacher_id', 'room' => 'co.room'][$mode];
    $stmt = $pdo->prepare(
        "SELECT s.subject_code, co.day_of_week, co.start_time, co.end_time, co.room,
                sec.section_name, sec.year_level, p.program_code, t.last_name, t.first_name
         FROM Class_Offering co
         JOIN Subject s ON s.subject_id = co.subject_id
         JOIN Section sec ON sec.section_id = co.section_id
         JOIN Program p ON p.program_id = sec.program_id
         JOIN Teacher t ON t.teacher_id = co.teacher_id
         WHERE co.term_id = :tid AND $where = :sel
         ORDER BY co.start_time"
    );
    $stmt->execute(['tid' => $termId, 'sel' => $selected]);
    foreach ($stmt->fetchAll() as $o) {
        $sec = sectionLabel($o['year_level'], $o['section_name'], $mode === 'room' ? $o['program_code'] : null);
        $teacher = $o['last_name'] . ', ' . $o['first_name'];
        $lines = $mode === 'section' ? [$o['room'], $teacher] : ($mode === 'teacher' ? [$o['room'], $sec] : [$sec, $teacher]);
        $events[] = [
            'day_of_week' => $o['day_of_week'], 'start_time' => $o['start_time'], 'end_time' => $o['end_time'],
            'title' => $o['subject_code'], 'lines' => $lines,
        ];
    }
}
$modeLabels = ['section' => 'By Section', 'teacher' => 'By Teacher', 'room' => 'By Room'];
$tabs = [];
foreach ($modeLabels as $key => $label) {
    $tabs[$key] = ['label' => $label, 'href' => '?mode=' . $key . '&term=' . urlencode($termId)];
}
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
<div class="container container-xl">
    <h1 class="h4 mb-3">Schedule</h1>

    <?php if (empty($terms)): ?>
        <div class="alert alert-secondary">No class offerings exist yet, so there is nothing to show.</div>
    <?php else: ?>
        <?= tabBar($tabs, $mode) ?>
        <form method="get" class="toolbar">
            <input type="hidden" name="mode" value="<?= htmlspecialchars($mode) ?>">
            <div class="toolbar-field toolbar-field-sm">
                <label for="term">Term</label>
                <select name="term" id="term" class="form-select">
                    <?php foreach ($terms as $t): ?>
                        <option value="<?= $t['term_id'] ?>" <?= $termId === (string)$t['term_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['school_year'] . ' S' . $t['semester']) ?><?= $t['status'] === 'ongoing' ? ' (ongoing)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="toolbar-field">
                <label for="sel"><?= htmlspecialchars(['section' => 'Section', 'teacher' => 'Teacher', 'room' => 'Room'][$mode]) ?></label>
                <select name="sel" id="sel" class="form-select js-search" data-placeholder="Search">
                    <?php foreach ($options as $value => $label): ?>
                        <option value="<?= htmlspecialchars((string)$value) ?>" <?= $selected === (string)$value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-outline-primary">View</button>
        </form>

        <?php if ($mode === 'room'): ?>
            <p class="text-muted small">Rooms are shared, so this includes classes from every department.</p>
        <?php endif; ?>
        <?php if (empty($options)): ?>
            <p class="text-muted">Nothing to choose from here yet.</p>
        <?php else: ?>
            <?= renderScheduleGrid($events) ?>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
