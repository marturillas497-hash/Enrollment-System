<?php
/**
 * Half-hour calendar grid shared by the student, teacher and registrar schedule pages.
 * Each event: day_of_week, start_time, end_time, title, lines (extra text lines, optional).
 */
function renderScheduleGrid(array $events): string
{
    $events = array_values(array_filter($events, fn($e) => !empty($e['day_of_week']) && !empty($e['start_time']) && !empty($e['end_time'])));
    if (empty($events)) {
        return '<p class="text-muted">No classes scheduled yet.</p>';
    }

    $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $dayIndex = array_flip($days);
    $slotMin = 30;
    $earliest = 7 * 60;
    $latest = 18 * 60;
    foreach ($events as $e) {
        [$sh, $sm] = array_map('intval', explode(':', $e['start_time']));
        [$eh, $em] = array_map('intval', explode(':', $e['end_time']));
        $earliest = min($earliest, $sh * 60 + $sm);
        $latest = max($latest, $eh * 60 + $em);
    }
    $earliest = (int)(floor($earliest / 60) * 60);
    $latest = (int)(ceil($latest / 60) * 60);
    $slotCount = max(1, (int)(($latest - $earliest) / $slotMin));

    $slotLine = function (string $time) use ($earliest, $slotMin): int {
        [$h, $m] = array_map('intval', explode(':', $time));
        return 2 + (int)floor((($h * 60 + $m) - $earliest) / $slotMin);
    };
    $esc = fn($v) => htmlspecialchars((string)$v);

    $html = '<div class="table-responsive"><div class="schedule-grid" style="grid-template-columns: 64px repeat(6, 1fr); grid-template-rows: auto repeat(' . $slotCount . ', 26px); min-width: 700px;">';
    $html .= '<div class="sg-corner"></div>';
    foreach ($days as $i => $day) {
        $html .= '<div class="sg-day-header" style="grid-column: ' . ($i + 2) . ';">' . $esc(substr($day, 0, 3)) . '</div>';
    }
    for ($slot = 0; $slot < $slotCount; $slot++) {
        $minutes = $earliest + $slot * $slotMin;
        if ($minutes % 60 === 0) {
            $html .= '<div class="sg-time-label" style="grid-row: ' . ($slot + 2) . ';">' . date('g A', mktime((int)($minutes / 60), 0, 0)) . '</div>';
        }
        foreach ($days as $i => $day) {
            $html .= '<div class="sg-cell" style="grid-row: ' . ($slot + 2) . '; grid-column: ' . ($i + 2) . ';"></div>';
        }
    }
    foreach ($events as $e) {
        if (!isset($dayIndex[$e['day_of_week']])) {
            continue;
        }
        $html .= '<div class="sg-event" style="grid-column: ' . ($dayIndex[$e['day_of_week']] + 2) . '; grid-row: ' . $slotLine($e['start_time']) . ' / ' . $slotLine($e['end_time']) . ';">';
        $html .= '<strong>' . $esc($e['title']) . '</strong>';
        $html .= '<span>' . $esc(formatTimeRange($e['start_time'], $e['end_time'])) . '</span>';
        foreach ($e['lines'] ?? [] as $line) {
            if ($line !== null && $line !== '') {
                $html .= '<span>' . $esc($line) . '</span>';
            }
        }
        $html .= '</div>';
    }
    return $html . '</div></div>';
}
