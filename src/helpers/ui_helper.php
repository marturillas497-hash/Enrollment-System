<?php
// UI helpers shared by every page. Loaded once from includes/navbar.php,
// so any page that includes the navbar can call these without a require.

if (!function_exists('statusBadge')) {
    /**
     * Render a colored status pill. This is the single place that decides
     * which status gets which color, so add new statuses to the map below
     * instead of writing <span class="badge ..."> by hand in a page.
     *
     * @param mixed       $status  Raw status value, e.g. 'pending' or 'on_leave'
     * @param string|null $label   Text to show (defaults to a tidy version of $status)
     * @param string|null $variant Force a color: success, warning, danger, info, neutral
     */
    function statusBadge($status, ?string $label = null, ?string $variant = null): string
    {
        static $map = [
            'approved'  => 'success', 'validated' => 'success', 'completed' => 'success',
            'complete'  => 'success', 'active'    => 'success', 'verified'  => 'success',
            'ongoing'   => 'success', 'passed'    => 'success',
            'pending'   => 'warning', 'submitted' => 'warning', 'on_leave'  => 'warning',
            'rejected'  => 'danger',  'dropped'   => 'danger',  'missing'   => 'danger',
            'failed'    => 'danger',
            'graduated' => 'info',    'credited'  => 'info',    'irregular' => 'info',
            'closed'    => 'neutral', 'retired'   => 'neutral', 'regular'   => 'neutral',
        ];
        static $allowed = ['success', 'warning', 'danger', 'info', 'neutral'];

        $key = strtolower(trim((string) $status));
        if ($variant === null || !in_array($variant, $allowed, true)) {
            $variant = $map[$key] ?? 'neutral';
        }
        if ($label === null) {
            $label = ucwords(str_replace('_', ' ', $key));
        }

        return '<span class="status-badge status-' . $variant . '">' . htmlspecialchars($label) . '</span>';
    }
}

if (!function_exists('statCard')) {
    /**
     * Render a dashboard number card (the number, a label, and an icon).
     * Pass $href to make the whole card a link, and $attention = true to
     * highlight it (used for "needs your action" counts above zero).
     * $icon is a Bootstrap Icons name without the "bi-" prefix, e.g. 'people'.
     */
    function statCard($value, string $label, string $icon, ?string $href = null, bool $attention = false): string
    {
        $class = 'stat-card' . ($attention ? ' stat-card-attention' : '');
        $inner = '<span class="stat-icon"><i class="bi bi-' . htmlspecialchars($icon) . '"></i></span>'
               . '<span><span class="stat-value">' . htmlspecialchars((string) $value) . '</span>'
               . '<span class="stat-label">' . htmlspecialchars($label) . '</span></span>';

        if ($href !== null) {
            return '<a href="' . htmlspecialchars($href) . '" class="' . $class . '">' . $inner . '</a>';
        }
        return '<div class="' . $class . '">' . $inner . '</div>';
    }
}

if (!function_exists('tabBar')) {
    /**
     * Render a row of tab links (each tab is its own page load via a query string).
     * $tabs is [key => ['label' => 'Pending', 'count' => 3, 'href' => '?tab=pending']].
     * Pass 'count' => null to hide the number.
     */
    function tabBar(array $tabs, string $active): string
    {
        $html = '<nav class="tab-bar">';
        foreach ($tabs as $key => $tab) {
            $class = 'tab-link' . ((string) $key === $active ? ' active' : '');
            $html .= '<a href="' . htmlspecialchars($tab['href']) . '" class="' . $class . '">' . htmlspecialchars($tab['label']);
            if (($tab['count'] ?? null) !== null) {
                $html .= '<span class="tab-count">' . (int) $tab['count'] . '</span>';
            }
            $html .= '</a>';
        }
        return $html . '</nav>';
    }
}

if (!function_exists('stepper')) {
    /**
     * Render a step indicator for a multi-page flow. $steps is a list of labels,
     * $current is the 1-based number of the step the user is on.
     */
    function stepper(array $steps, int $current): string
    {
        $html = '<ol class="stepper">';
        foreach (array_values($steps) as $i => $label) {
            $n = $i + 1;
            $state = $n < $current ? ' done' : ($n === $current ? ' current' : '');
            $html .= '<li class="stepper-step' . $state . '"><span class="stepper-dot">'
                   . ($n < $current ? '&#10003;' : $n) . '</span><span class="stepper-label">'
                   . htmlspecialchars($label) . '</span></li>';
        }
        return $html . '</ol>';
    }
}

if (!function_exists('termBanner')) {
    /**
     * Banner that says which term is open. $ongoingTerms is a list of School_term
     * rows (school_year, semester) that are currently 'ongoing'. Turns into a
     * warning when there is no open term or more than one.
     */
    function termBanner(array $ongoingTerms): string
    {
        $label = function (array $t): string {
            $sem = (int) $t['semester'] === 3 ? 'Summer' : 'Semester ' . (int) $t['semester'];
            return $t['school_year'] . ', ' . $sem;
        };
        if (count($ongoingTerms) === 0) {
            return '<div class="term-banner term-banner-warning"><i class="bi bi-exclamation-triangle"></i>'
                 . '<span>No term is open right now. Open one below before scheduling classes or placing students.</span></div>';
        }
        if (count($ongoingTerms) > 1) {
            $names = implode(' and ', array_map(fn($t) => htmlspecialchars($label($t)), $ongoingTerms));
            return '<div class="term-banner term-banner-warning"><i class="bi bi-exclamation-triangle"></i>'
                 . '<span>' . count($ongoingTerms) . ' terms are open at once: <strong>' . $names . '</strong>. '
                 . 'Usually only one should be, so close the old one unless the overlap is intended.</span></div>';
        }
        return '<div class="term-banner"><i class="bi bi-calendar-check"></i>'
             . '<span>Current term: <strong>' . htmlspecialchars($label($ongoingTerms[0])) . '</strong></span></div>';
    }
}

if (!function_exists('progressMeter')) {
    /** A small labelled progress bar, e.g. progressMeter(12, 40, 'subjects credited'). */
    function progressMeter(int $done, int $total, string $label): string
    {
        $pct = $total > 0 ? (int) round($done / $total * 100) : 0;
        return '<div class="progress-meter"><div class="progress-meter-text">' . $done . ' of ' . $total . ' '
             . htmlspecialchars($label) . '</div><div class="progress-meter-track"><div class="progress-meter-fill" style="width:'
             . $pct . '%"></div></div></div>';
    }
}
