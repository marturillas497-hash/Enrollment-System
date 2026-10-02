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
            'failed'    => 'danger',  'incomplete' => 'danger',
            'graduated' => 'info',    'credited'  => 'info',    'irregular' => 'warning',
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

if (!function_exists('paginationInfo')) {
    /**
     * Work out the current page number, LIMIT/OFFSET, and page count from $_GET['page'] and a
     * known total row count. Call this AFTER running a COUNT(*) query with the same WHERE clause
     * as the main query, and use the 'perPage'/'offset' it returns to LIMIT/OFFSET that main query.
     */
    function paginationInfo(int $totalRows, int $perPage = 15): array
    {
        $totalPages = max(1, (int) ceil($totalRows / $perPage));
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
        $page = min($page, $totalPages);
        return ['page' => $page, 'perPage' => $perPage, 'offset' => ($page - 1) * $perPage, 'totalPages' => $totalPages];
    }
}

if (!function_exists('paginationNav')) {
    /**
     * Render Prev / Page X of Y / Next links. $extraParams should be every filter/search/sort
     * value already active on the page (NOT raw $_GET — pass the same sanitized values the page
     * already validated for its own query), so paging forward or back doesn't silently drop them.
     * Returns '' when there's only one page, so it's safe to always echo the result.
     */
    function paginationNav(int $page, int $totalPages, array $extraParams = []): string
    {
        if ($totalPages <= 1) { return ''; }
        $build = function (int $p) use ($extraParams): string {
            return '?' . http_build_query(array_merge($extraParams, ['page' => $p]));
        };
        $html = '<nav class="pagination-nav" aria-label="Table pages">';
        $html .= $page > 1
            ? '<a href="' . htmlspecialchars($build($page - 1)) . '" class="btn btn-outline-secondary btn-sm">&larr; Prev</a>'
            : '<span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true">&larr; Prev</span>';
        $html .= '<span class="pagination-status">Page ' . $page . ' of ' . $totalPages . '</span>';
        $html .= $page < $totalPages
            ? '<a href="' . htmlspecialchars($build($page + 1)) . '" class="btn btn-outline-secondary btn-sm">Next &rarr;</a>'
            : '<span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true">Next &rarr;</span>';
        return $html . '</nav>';
    }
}

if (!function_exists('sectionLabel')) {
    /** "Year 1 – A", or "BSM · Year 1 – A" when a program code is passed. */
    function sectionLabel($yearLevel, string $sectionName, ?string $programCode = null): string
    {
        return ($programCode ? $programCode . ' · ' : '') . 'Year ' . (int) $yearLevel . ' – ' . $sectionName;
    }
}

if (!function_exists('formatTimeRange')) {
    /** "8:00 AM – 10:00 AM" from two TIME values. */
    function formatTimeRange(?string $start, ?string $end): string
    {
        if (!$start || !$end) {
            return '';
        }
        return date('g:i A', strtotime($start)) . ' – ' . date('g:i A', strtotime($end));
    }
}

if (!function_exists('formatSchedule')) {
    /** "Mon 8:00 AM – 10:00 AM · T-101" (room optional). */
    function formatSchedule(?string $day, ?string $start, ?string $end, ?string $room = null): string
    {
        $parts = [];
        if ($day) {
            $parts[] = substr($day, 0, 3);
        }
        $range = formatTimeRange($start, $end);
        if ($range !== '') {
            $parts[] = $range;
        }
        $text = implode(' ', $parts);
        return ($room !== null && $room !== '') ? $text . ' · ' . $room : $text;
    }
}
