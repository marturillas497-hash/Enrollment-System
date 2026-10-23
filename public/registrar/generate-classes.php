<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';
require_once __DIR__ . '/../../src/helpers/generator_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$deptId = (int)$stmt->fetchColumn();
$termId = ongoingTermId($pdo);

$self = BASE_URL . '/registrar/generate-classes.php';
$e = fn($v): string => htmlspecialchars((string)$v);
$error = '';
$message = flashGet('gen_msg') ?? '';
$warning = flashGet('gen_warn') ?? '';

/** Apply what the registrar typed in the preview to the draft rows. */
function genApplyPost(array $draftRows): array
{
    $posted = (array)($_POST['row'] ?? []);
    $out = [];
    foreach ($draftRows as $key => $row) {
        $p = $posted[$key] ?? null;
        if ($p === null) {
            $out[$key] = $row;
            continue;
        }
        if (!empty($p['remove'])) {
            continue;
        }
        $teacher = (string)($p['teacher'] ?? '');
        $row['teacher_id'] = ctype_digit($teacher) && $teacher !== '' ? (int)$teacher : null;
        $meetings = [];
        foreach ((array)($p['day'] ?? []) as $i => $day) {
            $start = trim((string)(((array)($p['start'] ?? []))[$i] ?? ''));
            $end = trim((string)(((array)($p['end'] ?? []))[$i] ?? ''));
            $room = (string)(((array)($p['room'] ?? []))[$i] ?? '');
            if ($day === '' && $start === '' && $end === '' && $room === '') {
                continue;
            }
            $meetings[] = [
                'day' => (string)$day, 'start' => $start, 'end' => $end,
                'room_id' => ctype_digit($room) && $room !== '' ? (int)$room : null,
                'kind' => '',
            ];
        }
        $row['meetings'] = $meetings;
        $out[$key] = $row;
    }
    return $out;
}

/** The capacity check as an HTML fragment for the start screen. */
function genRenderCapacity(array $cap): string
{
    $e = fn($v): string => htmlspecialchars((string)$v);
    $n = fn($v): string => rtrim(rtrim(number_format((float)$v, 1), '0'), '.');
    $icons = [
        'bad' => '<i class="bi bi-x-circle-fill text-danger me-1"></i><span class="visually-hidden">Problem: </span>',
        'warn' => '<i class="bi bi-exclamation-triangle-fill text-warning me-1"></i><span class="visually-hidden">Tight: </span>',
        'ok' => '<i class="bi bi-check-circle-fill text-success me-1"></i><span class="visually-hidden">Fine: </span>',
    ];
    if ($cap['classes'] === 0) {
        return '<div class="small text-muted">Nothing is left to schedule for this selection.</div>';
    }
    $lines = [];
    if ($cap['no_teacher']) {
        $lines[] = $icons['bad'] . 'No teacher has a matching tag for ' . $e(implode(', ', $cap['no_teacher'])) . '. Add tags to the subject or a teacher first.';
    }
    foreach ($cap['teachers'] as $t) {
        $codes = array_slice($t['codes'], 0, 4);
        $more = count($t['codes']) - count($codes);
        $line = $icons[$t['short'] ? 'bad' : ($t['tight'] ? 'warn' : 'ok')]
            . '<strong>' . $e($t['label']) . '</strong> <span class="text-muted">(' . $e(implode(', ', $codes)) . ($more > 0 ? ' and ' . $more . ' more' : '') . ')</span>: needs '
            . $n($t['need_units']) . ' units and ' . $n($t['need_hours']) . ' hours a week. ' . $t['teachers'] . ' teacher' . ($t['teachers'] === 1 ? '' : 's')
            . ' can still take ' . $n($t['have_units']) . ' units and ' . $n($t['have_hours']) . ' hours.';
        if ($t['short']) {
            $line .= ' <span class="text-danger">Some of these classes will have no teacher.</span>';
        }
        $lines[] = $line;
    }
    foreach ($cap['rooms'] as $r) {
        $short = $r['needed'] - $r['fits'];
        $line = $icons[$short > 0 ? 'bad' : 'ok'] . '<strong>' . $e($r['type']) . '</strong>: ' . $r['needed'] . ' meeting' . ($r['needed'] === 1 ? '' : 's')
            . ' need this room type and ' . $r['fits'] . ' fit in the room time left.';
        if ($r['fallback'] > 0) {
            $line .= ' ' . $r['fallback'] . ' would use the fallback room type.';
        }
        if ($short > 0) {
            $line .= ' <span class="text-danger">' . $short . ' may not get a room, so those classes may be left out.</span>';
        }
        $lines[] = $line;
    }
    return '<div class="fw-semibold mb-1">Capacity for your selection <span class="text-muted fw-normal">(' . $cap['classes'] . ' class' . ($cap['classes'] === 1 ? '' : 'es') . ')</span></div>'
        . '<ul class="list-unstyled small mb-1"><li class="mb-1">' . implode('</li><li class="mb-1">', $lines) . '</li></ul>'
        . '<div class="form-text">An estimate before drafting. A teacher with several tags counts in every group they match, so a shared teacher can make a group look better than it is. The draft still checks every conflict.</div>';
}

$draft = $_SESSION['gen_draft'] ?? null;
if ($draft && ((int)$draft['term_id'] !== $termId || (int)$draft['dept_id'] !== $deptId)) {
    $draft = null;
    unset($_SESSION['gen_draft']);
}

if ($termId && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['capacity'])) {
    try {
        $ctx = genLoadContext($pdo, $termId, $deptId);
        $chosen = array_slice(array_values(array_filter(array_map('intval', (array)($_GET['sections'] ?? [])), fn($id) => isset($ctx['sections'][$id]))), 0, GEN_MAX_SECTIONS);
        $years = [];
        $getYears = (array)($_GET['year'] ?? []);
        foreach ($chosen as $sid) {
            $y = (int)($getYears[$sid] ?? 0);
            $years[$sid] = $y >= 1 && $y <= 4 ? $y : $ctx['sections'][$sid]['teach_year'];
        }
        $html = $chosen ? genRenderCapacity(genCapacity($pdo, $ctx, $chosen, $years)) : '';
    } catch (Throwable $ex) {
        error_log('[MIST capacity] ' . $ex->getMessage());
        http_response_code(500);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo $html;
    exit;
}

if ($termId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $cutOff = $action === '' || (isset($_POST['row']) && ($_POST['form_end'] ?? '') !== '1' && !in_array($action, ['discard', 'reshuffle'], true));

    if ($cutOff) {
        $error = 'The server cut this form off because it was too large, so nothing was changed. Remove some classes, or generate fewer sections at a time.';
    } elseif ($action === 'generate' || ($action === 'reshuffle' && $draft)) {
        $ctx = genLoadContext($pdo, $termId, $deptId);
        $chosen = $action === 'reshuffle'
            ? array_values(array_filter($draft['sections'], fn($id) => isset($ctx['sections'][$id])))
            : array_values(array_filter(array_map('intval', (array)($_POST['sections'] ?? [])), fn($id) => isset($ctx['sections'][$id])));
        $years = [];
        $postedYears = (array)($_POST['year'] ?? []);
        foreach ($chosen as $sid) {
            $y = $action === 'reshuffle' ? (int)($draft['years'][$sid] ?? 0) : (int)($postedYears[$sid] ?? 0);
            $years[$sid] = $y >= 1 && $y <= 4 ? $y : $ctx['sections'][$sid]['teach_year'];
        }
        if (!$chosen) {
            $error = 'Choose at least one section.';
        } elseif (count($chosen) > GEN_MAX_SECTIONS) {
            $error = 'Choose at most ' . GEN_MAX_SECTIONS . ' sections per run. Generate the rest in another run.';
        } else {
            $seed = random_int(1, 2147483647 - GEN_MAX_ATTEMPTS);
            $result = genGenerate($pdo, $ctx, $chosen, $seed, $years);
            $_SESSION['gen_draft'] = [
                'term_id' => $termId, 'dept_id' => $deptId, 'sections' => $chosen, 'years' => $years, 'seed' => $seed,
                'attempts' => $result['attempts'], 'rows' => $result['rows'], 'unplaced' => $result['unplaced'],
            ];
            header('Location: ' . $self . '?step=preview');
            exit;
        }
    } elseif ($action === 'discard') {
        unset($_SESSION['gen_draft']);
        flashSet('gen_msg', 'Draft discarded. Nothing was saved.');
        header('Location: ' . $self);
        exit;
    } elseif (($action === 'recheck' || $action === 'save') && $draft) {
        $draft['rows'] = genApplyPost($draft['rows']);
        $_SESSION['gen_draft'] = $draft;
        if ($action === 'save') {
            $result = genSave($pdo, $termId, $deptId, $draft['rows'], (int)$user['account_id']);
            if (is_string($result)) {
                $error = $result;
            } else {
                unset($_SESSION['gen_draft']);
                flashSet('gen_msg', $result['saved'] . ' class(es) saved. You can still edit them on Class Offerings, or undo this run below.');
                header('Location: ' . $self);
                exit;
            }
        }
    } elseif ($action === 'undo') {
        $res = genUndoRun($pdo, (int)($_POST['run_id'] ?? 0), $deptId);
        flashSet('gen_msg', $res['removed'] . ' class(es) removed.');
        if ($res['kept'] > 0) {
            flashSet('gen_warn', $res['kept'] . ' class(es) were kept because students are enrolled in them.');
        }
        header('Location: ' . $self);
        exit;
    }
}

$step = $draft && (($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['step'] ?? '') === 'preview') || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'generate')) ? 'preview' : 'start';
$ctx = $termId ? genLoadContext($pdo, $termId, $deptId) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auto-generate Classes</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/schedule.css">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/generator.css">
<div class="container">
    <h1 class="h4 mb-1">Auto-generate Classes</h1>
    <p class="text-muted small mb-3">Builds a draft schedule for the ongoing term, Monday to Friday, 7:30 AM to 5:30 PM with no classes from 11:30 AM to 12:30 PM. Classes are packed right after each other so students wait less, and a teacher gets at most 2 classes in a row before a break. Nothing is saved until you press Save. Existing classes are never changed. <a href="<?= BASE_URL ?>/registrar/class-offerings.php">Back to Class Offerings</a></p>

    <?php if ($message): ?><div class="alert alert-success"><?= $e($message) ?></div><?php endif; ?>
    <?php if ($warning): ?><div class="alert alert-warning"><?= $e($warning) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

<?php if (!$termId): ?>
    <div class="alert alert-warning">No ongoing term. Ask the administrator to open one first.</div>

<?php elseif ($step === 'start'): ?>
    <?php $ready = genReadiness($pdo, $ctx); ?>
    <?php if ($draft): ?>
        <div class="alert alert-info d-flex flex-wrap align-items-center gap-2">
            <span>You have an unsaved draft of <?= count($draft['rows']) ?> class<?= count($draft['rows']) === 1 ? '' : 'es' ?>. Generating again replaces it.</span>
            <a class="btn btn-sm btn-primary ms-auto" href="<?= $e($self) ?>?step=preview">Continue editing it</a>
        </div>
    <?php endif; ?>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h6">Readiness check</h2>
            <ul class="small mb-0">
                <li><?= $ready['teachers_total'] ?> active teacher(s) in your department, <?= $ready['rooms_total'] ?> active room(s), <?= $ready['subjects_total'] ?> subject(s) in this semester's curricula.</li>
                <?php if ($ready['teachers_total'] === 0): ?><li class="text-danger">No active teachers. Classes will be drafted without teachers.</li><?php endif; ?>
                <?php if ($ready['rooms_total'] === 0): ?><li class="text-danger">No rooms exist yet. Ask the administrator to add rooms.</li><?php endif; ?>
                <?php foreach ($ready['missing_room_types'] as $type => $codes): ?>
                    <li class="text-warning-emphasis">No active room of type "<?= $e($type) ?>" for: <?= $e(implode(', ', array_unique($codes))) ?>. Those meetings get no room.</li>
                <?php endforeach; ?>
                <?php if ($ready['subjects_no_tags']): ?><li class="text-warning-emphasis"><?= count($ready['subjects_no_tags']) ?> subject(s) have no tags, so they get no teacher: <?= $e(implode('; ', array_slice($ready['subjects_no_tags'], 0, 8))) ?><?= count($ready['subjects_no_tags']) > 8 ? '; and more' : '' ?>.</li><?php endif; ?>
                <?php if ($ready['subjects_no_parts']): ?><li class="text-muted"><?= count($ready['subjects_no_parts']) ?> subject(s) have no weekly parts and use the default of one lecture block in a Lecture Room.</li><?php endif; ?>
                <?php if ($ready['teachers_no_tags']): ?><li class="text-warning-emphasis">Teachers with no tags are never chosen: <?= $e(implode('; ', $ready['teachers_no_tags'])) ?>.</li><?php endif; ?>
            </ul>
        </div>
    </div>

    <form method="post" class="card mb-4">
        <div class="card-body">
            <h2 class="h6">Choose sections</h2>
            <input type="hidden" name="action" value="generate">
            <?php
            $plan = genSectionPlan($pdo, $ctx);
            $any = false;
            foreach ($ctx['sections'] as $sid => $sec):
                $todo = $plan[$sid][$sec['teach_year']] ?? 0;
                $anyYear = array_sum($plan[$sid]) > 0;
                if ($anyYear) { $any = true; } ?>
                <div class="d-flex flex-wrap align-items-center gap-3 mb-2 gen-section" data-plan="<?= $e(json_encode($plan[$sid])) ?>">
                    <div class="form-check mb-0 flex-grow-1">
                        <input class="form-check-input gen-pick" type="checkbox" name="sections[]" value="<?= $sid ?>" id="sec<?= $sid ?>"<?= $anyYear ? '' : ' disabled data-empty="1"' ?>>
                        <label class="form-check-label" for="sec<?= $sid ?>"><?= $e($sec['label']) ?><?= $sec['occupied'] > 0 ? ' <span class="text-muted small">· ' . $sec['occupied'] . ' student' . ($sec['occupied'] === 1 ? '' : 's') . '</span>' : '' ?>
                            <span class="text-muted small gen-todo"><?= $anyYear ? '(' . $todo . ' class' . ($todo === 1 ? '' : 'es') . ' to schedule' . ($todo === 0 ? ', all already scheduled' : '') . ')' : '(nothing to schedule)' ?></span></label>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <label class="small text-muted mb-0" for="year<?= $sid ?>">Teach year</label>
                        <select class="form-select form-select-sm gen-year" name="year[<?= $sid ?>]" id="year<?= $sid ?>" style="width: 6.5rem">
                            <?php for ($y = 1; $y <= 4; $y++): ?><option value="<?= $y ?>"<?= $y === $sec['teach_year'] ? ' selected' : '' ?>>Year <?= $y ?></option><?php endfor; ?>
                        </select>
                        <span class="small text-muted" style="width: 13rem"><?= $sec['year_from_students'] ? 'suggested from enrolled students' : 'suggested from the section' ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (!$ctx['sections']): ?><div class="text-muted">Your department has no sections yet.</div><?php endif; ?>
            <div id="genCapacity" class="mt-3"></div>
            <button type="submit" class="btn btn-primary mt-3"<?= $any ? '' : ' disabled' ?>>Generate preview</button>
            <div class="form-text">The generator plans one class per subject per section. It does not plan for retakes. Add catch-up classes by hand. Choose up to <?= GEN_MAX_SECTIONS ?> sections per run. <span id="genCapNote"></span></div>
        </div>
    </form>
    <script>
    (function () {
        var max = <?= GEN_MAX_SECTIONS ?>;
        var picks = document.querySelectorAll('.gen-pick');
        function refreshCap() {
            var n = 0;
            picks.forEach(function (p) { if (p.checked) { n++; } });
            picks.forEach(function (p) { if (!p.dataset.empty) { p.disabled = !p.checked && n >= max; } });
            var note = document.getElementById('genCapNote');
            if (note) { note.textContent = n + ' of ' + max + ' chosen.'; }
        }
        picks.forEach(function (p) { p.addEventListener('change', refreshCap); });
        document.querySelectorAll('.gen-section').forEach(function (row) {
            var plan = JSON.parse(row.getAttribute('data-plan'));
            var sel = row.querySelector('.gen-year');
            var label = row.querySelector('.gen-todo');
            sel.addEventListener('change', function () {
                var n = plan[sel.value] || 0;
                label.textContent = '(' + n + ' class' + (n === 1 ? '' : 'es') + ' to schedule' + (n === 0 ? ', all already scheduled' : '') + ')';
            });
        });
        var capBox = document.getElementById('genCapacity');
        var capTimer = null;
        var capReq = 0;
        var selfUrl = <?= json_encode($self) ?>;
        function loadCapacity() {
            var q = new URLSearchParams();
            q.set('capacity', '1');
            var any = false;
            document.querySelectorAll('.gen-section').forEach(function (row) {
                var p = row.querySelector('.gen-pick');
                if (p.checked) {
                    any = true;
                    q.append('sections[]', p.value);
                    q.set('year[' + p.value + ']', row.querySelector('.gen-year').value);
                }
            });
            if (!any) {
                capBox.innerHTML = '<div class="small text-muted">Tick sections to see whether your teachers and rooms can cover them.</div>';
                return;
            }
            var mine = ++capReq;
            capBox.innerHTML = '<div class="small text-muted">Checking teachers and rooms...</div>';
            fetch(selfUrl + '?' + q.toString(), { credentials: 'same-origin' })
                .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
                .then(function (html) { if (mine === capReq) { capBox.innerHTML = html; } })
                .catch(function () { if (mine === capReq) { capBox.innerHTML = '<div class="small text-muted">Could not check capacity. You can still generate.</div>'; } });
        }
        function scheduleCapacity() { clearTimeout(capTimer); capTimer = setTimeout(loadCapacity, 250); }
        picks.forEach(function (p) { p.addEventListener('change', scheduleCapacity); });
        document.querySelectorAll('.gen-year').forEach(function (s) { s.addEventListener('change', scheduleCapacity); });
        refreshCap();
        loadCapacity();
    })();
    </script>

    <?php
    $runs = $pdo->prepare(
        "SELECT gr.run_id, gr.created_at, COUNT(co.offering_id) AS classes_left,
                SUM((SELECT COUNT(*) FROM Enrolled_subject es WHERE es.offering_id = co.offering_id) > 0) AS with_students
         FROM Generation_run gr
         JOIN Class_Offering co ON co.generation_run_id = gr.run_id
         JOIN Section sec ON sec.section_id = co.section_id
         JOIN Program p ON p.program_id = sec.program_id
         WHERE p.department_id = :d AND gr.term_id = :t
         GROUP BY gr.run_id, gr.created_at ORDER BY gr.run_id DESC LIMIT 5"
    );
    $runs->execute(['d' => $deptId, 't' => $termId]);
    $runs = $runs->fetchAll();
    ?>
    <?php if ($runs): ?>
    <h2 class="h6">Earlier runs this term</h2>
    <div class="table-responsive">
    <table class="table table-sm bg-white align-middle">
        <thead><tr><th>Saved</th><th>Classes</th><th>With students</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($runs as $r): ?>
            <tr>
                <td><?= $e(date('M j, Y g:i A', strtotime($r['created_at']))) ?></td>
                <td><?= (int)$r['classes_left'] ?></td>
                <td><?= (int)$r['with_students'] ?></td>
                <td>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="undo">
                        <input type="hidden" name="run_id" value="<?= (int)$r['run_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm-label="Undo run" data-confirm-tone="danger"
                                data-confirm="Remove the classes from this run that nobody is enrolled in? Classes with students, and classes you edited by hand, stay.">Undo this run</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="small text-muted">A class you change by hand on Class Offerings leaves its run, so Undo never removes it.</p>
    <?php endif; ?>

<?php else: ?>
    <?php
    $check = genValidate($pdo, $ctx, $draft['rows']);
    $rows = $check['rows'];
    $bySection = [];
    foreach ($rows as $r) { $bySection[$r['section_id']][] = $r; }
    $canSave = $rows && $check['blockers'] === 0 && $check['need_teacher'] === 0;
    $dayChoices = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $roomChoices = array_values(array_map(fn($r) => ['id' => $r['id'], 'code' => $r['code'], 'type' => $ctx['types'][$r['type_id']]['name'] ?? '', 'cap' => $r['unlimited'] ? null : $r['capacity'], 'active' => $r['active']], $ctx['rooms']));
    ?>
    <?php
    $dayShort = ['Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed', 'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat'];
    $fmtTime = function (string $t): string {
        $ts = $t === '' ? false : strtotime($t);
        return $ts === false ? $t : date('g:i A', $ts);
    };
    $dur = function (int $min): string {
        return $min < 60 ? $min . ' min' : intdiv($min, 60) . ' h' . ($min % 60 ? ' ' . ($min % 60) . ' min' : '');
    };
    $teacherMsg = 'Assign a teacher before saving.';
    $isOther = fn(string $m): bool => strpos($m, $teacherMsg) !== 0;
    $matchLabel = fn(string $lv): string => $lv === 'full' ? 'full match' : ($lv === 'partial' ? 'partial match' : ($lv === 'untagged' ? 'subject untagged' : 'no tag match'));
    $chip = fn(string $tone, string $icon, string $text): string => '<span class="gen-chip gen-chip--' . $tone . '"><i class="bi bi-' . $icon . '" aria-hidden="true"></i>' . $text . '</span>';
    $meetLine = function (array $m) use ($ctx, $fmtTime, $dayShort): string {
        if (($m['day'] ?? '') === '' || ($m['start'] ?? '') === '' || ($m['end'] ?? '') === '') {
            return 'Incomplete meeting';
        }
        $rid = ($m['room_id'] ?? null) !== null ? (int)$m['room_id'] : 0;
        return ($dayShort[$m['day']] ?? $m['day']) . ' ' . $fmtTime($m['start']) . ' – ' . $fmtTime($m['end']) . ' · ' . ($ctx['rooms'][$rid]['code'] ?? 'No room');
    };

    $issueRows = 0;
    $secStats = [];
    foreach ($bySection as $sid => $list) {
        $st = ['need' => 0, 'conf' => 0, 'warn' => 0];
        foreach ($list as $r) {
            $st['need'] += $r['teacher_id'] === null ? 1 : 0;
            $st['conf'] += array_filter($r['errors'], $isOther) ? 1 : 0;
            $st['warn'] += $r['warnings'] ? 1 : 0;
            $issueRows += ($r['errors'] || $r['warnings']) ? 1 : 0;
        }
        $st['state'] = ($st['need'] || $st['conf']) ? 'bad' : ($st['warn'] ? 'warn' : 'ok');
        $st['wait'] = genQuality($list)['idle'];
        $secStats[$sid] = $st;
    }
    $q = genQuality($rows);
    $unplacedCount = count($draft['unplaced']);
    $attempts = (int)($draft['attempts'] ?? 1);
    ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <h2 class="h6 mb-0 me-2">Preview <span class="text-muted fw-normal">· nothing is saved yet</span></h2>
                <?= $chip('info', 'journal-text', count($rows) . ' class' . (count($rows) === 1 ? '' : 'es') . ' drafted') ?>
                <?= $chip($check['need_teacher'] ? 'danger' : 'neutral', $check['need_teacher'] ? 'x-circle-fill' : 'check-circle-fill', $check['need_teacher'] . ' need a teacher') ?>
                <?= $chip($check['conflicts'] ? 'danger' : 'neutral', $check['conflicts'] ? 'x-circle-fill' : 'check-circle-fill', $check['conflicts'] . ' with a conflict') ?>
                <?= $chip($check['warned'] ? 'warning' : 'neutral', $check['warned'] ? 'exclamation-triangle-fill' : 'check-circle-fill', $check['warned'] . ' with a warning') ?>
                <?= $chip($unplacedCount ? 'danger' : 'neutral', $unplacedCount ? 'x-circle-fill' : 'check-circle-fill', $unplacedCount . ' could not be placed') ?>
            </div>
            <div class="small mt-2">
                Students wait <strong><?= $e($dur((int)$q['idle'])) ?></strong> in total between classes<?= $q['long_waits'] ? ' (longest ' . $e($dur((int)$q['longest_wait'])) . ', ' . $q['long_waits'] . ' wait' . ($q['long_waits'] === 1 ? '' : 's') . ' of ' . GEN_LONG_WAIT . ' min or more)' : '' ?>.
                <?= $q['teacher_chains'] ? '<span class="text-warning-emphasis">' . $q['teacher_chains'] . ' stretch' . ($q['teacher_chains'] === 1 ? '' : 'es') . ' where a teacher has more than ' . GEN_MAX_CHAIN . ' classes in a row.</span>' : 'No teacher has more than ' . GEN_MAX_CHAIN . ' classes in a row.' ?>
                <?= $q['single_days'] ? $q['single_days'] . ' section day' . ($q['single_days'] === 1 ? ' has' : 's have') . ' only one class.' : '' ?>
                <span class="gen-muted">Best of <?= $attempts ?> arrangement<?= $attempts === 1 ? '' : 's' ?> tried.</span>
            </div>
            <div class="gen-toolbar">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch" id="genOnlyIssues"<?= $issueRows ? '' : ' disabled' ?>>
                    <label class="form-check-label small" for="genOnlyIssues">Show only classes that need attention<?= $issueRows ? ' (' . $issueRows . ')' : '' ?></label>
                </div>
                <div class="ms-auto d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="genWeekOpen"><i class="bi bi-calendar-week" aria-hidden="true"></i> Week view</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="genExpandAll">Expand all</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="genCollapseAll">Collapse all</button>
                </div>
            </div>
            <details class="gen-help small mt-2">
                <summary>How to read this</summary>
                <p>A class with no teacher means no teacher with a matching tag was available. Pick one before saving. Conflicts (a teacher, section or room in two places at once) must be fixed. Everything else is a warning you can override. Full match means the teacher has all of the subject's tags, and partial match means only some of them.</p>
            </details>
        </div>
    </div>

    <form method="post" id="draftForm" data-seed="<?= $e((string)($draft['seed'] ?? '0')) ?>">
    <div id="genBody">
    <?php foreach ($bySection as $sid => $list): $st = $secStats[$sid]; ?>
        <details class="gen-sec" data-sec="<?= (int)$sid ?>" data-state="<?= $st['state'] ?>"<?= $st['state'] === 'ok' ? '' : ' open' ?>>
            <summary>
                <i class="bi bi-chevron-down gen-chevron" aria-hidden="true"></i>
                <span class="gen-sec-title"><?= $e($ctx['sections'][$sid]['label'] ?? 'Section') ?></span>
                <span class="gen-muted">Year <?= (int)($draft['years'][$sid] ?? ($ctx['sections'][$sid]['teach_year'] ?? 0)) ?> · <?= count($list) ?> class<?= count($list) === 1 ? '' : 'es' ?></span>
                <span class="gen-sec-chips">
                    <?php if ($st['need']): ?><?= $chip('danger', 'x-circle-fill', $st['need'] . ' need' . ($st['need'] === 1 ? 's' : '') . ' a teacher') ?><?php endif; ?>
                    <?php if ($st['conf']): ?><?= $chip('danger', 'x-circle-fill', $st['conf'] . ' conflict' . ($st['conf'] === 1 ? '' : 's')) ?><?php endif; ?>
                    <?php if ($st['warn']): ?><?= $chip('warning', 'exclamation-triangle-fill', $st['warn'] . ' warning' . ($st['warn'] === 1 ? '' : 's')) ?><?php endif; ?>
                    <?php if ($st['state'] === 'ok'): ?><?= $chip('success', 'check-circle-fill', 'All OK') ?><?php endif; ?>
                </span>
                <span class="gen-muted gen-sec-wait">Students wait <?= $e($dur((int)$st['wait'])) ?></span>
                <button type="button" class="btn btn-sm btn-outline-secondary gen-wk-open" data-sec="<?= (int)$sid ?>"><i class="bi bi-calendar-week" aria-hidden="true"></i> Week</button>
            </summary>
            <div class="gen-classes">
            <?php foreach ($list as $r):
                $k = $r['key'];
                $sub = $check['subjects'][$r['subject_id']] ?? ['code' => '?', 'name' => 'Unknown subject', 'units' => 0, 'tags' => []];
                $nOther = count(array_filter($r['errors'], $isOther));
                $needT = $r['teacher_id'] === null;
                $state = $r['errors'] ? 'bad' : ($r['warnings'] ? 'warn' : 'ok');
                $tc = $needT ? null : ($ctx['teachers'][(int)$r['teacher_id']] ?? null);
                ?>
                <div class="gen-class" data-state="<?= $state ?>" data-key="<?= $e($k) ?>" data-code="<?= $e($sub['code']) ?>">
                    <div class="gen-row-main">
                        <div>
                            <span class="gen-code"><?= $e($sub['code']) ?></span> <span class="gen-muted">· <?= rtrim(rtrim(number_format($sub['units'], 2), '0'), '.') ?> units</span>
                            <div class="gen-muted"><?= $e($sub['name']) ?></div>
                        </div>
                        <div>
                            <?php if ($tc): ?>
                                <span class="gen-teacher-name"><?= $e($tc['name']) ?></span>
                                <div class="gen-muted gen-teacher-match"><?= $e($matchLabel(tagMatchLevel($sub['tags'], $tc['tags']))) ?></div>
                            <?php else: ?>
                                <span class="gen-teacher-name gen-ic--danger"><?= $needT ? 'No teacher' : 'Unknown teacher' ?></span>
                                <div class="gen-muted gen-teacher-match"></div>
                            <?php endif; ?>
                        </div>
                        <div class="gen-meets">
                            <?php foreach ($r['meetings'] as $m): ?><div class="gen-meet"><?= $e($meetLine($m)) ?></div><?php endforeach; ?>
                            <?php if (!$r['meetings']): ?><span class="gen-muted">No weekly meeting (TBA)</span><?php endif; ?>
                        </div>
                        <div class="gen-status">
                            <?php if ($needT): ?><?= $chip('danger', 'x-circle-fill', 'Needs teacher') ?><?php endif; ?>
                            <?php if ($nOther): ?><?= $chip('danger', 'x-circle-fill', $nOther . ' conflict' . ($nOther === 1 ? '' : 's')) ?><?php endif; ?>
                            <?php if ($r['warnings']): ?><?= $chip('warning', 'exclamation-triangle-fill', count($r['warnings']) . ' warning' . (count($r['warnings']) === 1 ? '' : 's')) ?><?php endif; ?>
                            <?php if ($state === 'ok'): ?><?= $chip('success', 'check-circle-fill', 'OK') ?><?php endif; ?>
                            <span class="gen-chip gen-chip--info gen-dirty-tag"><i class="bi bi-pencil-fill" aria-hidden="true"></i>Edited, not checked</span>
                            <button type="button" class="btn btn-sm btn-outline-primary gen-edit" aria-expanded="<?= $r['errors'] ? 'true' : 'false' ?>" aria-controls="ed-<?= $e($k) ?>">
                                <span class="gen-edit-label"><?= $r['errors'] ? 'Close' : 'Edit' ?></span> <i class="bi bi-chevron-down" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>
                    <?php if ($r['errors'] || $r['warnings']): ?>
                        <ul class="gen-issues">
                            <?php foreach ($r['errors'] as $msg): ?><li class="gen-ic--danger"><i class="bi bi-x-circle-fill" aria-hidden="true"></i><span><?= $e($msg) ?></span></li><?php endforeach; ?>
                            <?php foreach ($r['warnings'] as $msg): ?><li class="gen-ic--warning"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i><span><?= $e($msg) ?></span></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <div class="gen-editor" id="ed-<?= $e($k) ?>"<?= $r['errors'] ? '' : ' hidden' ?>>
                        <div class="gen-editor-grid">
                            <div>
                                <label class="gen-label" for="t-<?= $e($k) ?>">Teacher</label>
                                <select class="form-select form-select-sm gen-teacher-select" id="t-<?= $e($k) ?>" name="row[<?= $e($k) ?>][teacher]">
                                    <option value="">Unassigned</option>
                                    <?php foreach ($ctx['teachers'] as $t): ?>
                                        <option value="<?= $t['id'] ?>"<?= (int)$r['teacher_id'] === $t['id'] ? ' selected' : '' ?>><?= $e($t['name']) ?> (<?= $e($matchLabel(tagMatchLevel($sub['tags'], $t['tags']))) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <div class="gen-label">Meetings</div>
                                <div id="mt-<?= $e($k) ?>">
                                <?php foreach ($r['meetings'] as $m): ?>
                                    <div class="d-flex flex-wrap gap-1 mb-1 meeting-line">
                                        <select class="form-select form-select-sm gen-w-day" name="row[<?= $e($k) ?>][day][]" aria-label="Day">
                                            <option value="">Day</option>
                                            <?php foreach ($dayChoices as $d): ?><option<?= $m['day'] === $d ? ' selected' : '' ?>><?= $d ?></option><?php endforeach; ?>
                                        </select>
                                        <input type="time" class="form-control form-control-sm gen-w-time" name="row[<?= $e($k) ?>][start][]" value="<?= $e($m['start']) ?>" aria-label="Start time">
                                        <input type="time" class="form-control form-control-sm gen-w-time" name="row[<?= $e($k) ?>][end][]" value="<?= $e($m['end']) ?>" aria-label="End time">
                                        <select class="form-select form-select-sm gen-w-room" name="row[<?= $e($k) ?>][room][]" aria-label="Room">
                                            <option value="">No room</option>
                                            <?php
                                            $byType = [];
                                            foreach ($roomChoices as $rc) {
                                                if ($rc['active'] || (int)($m['room_id'] ?? 0) === $rc['id']) { $byType[$rc['type']][] = $rc; }
                                            }
                                            foreach ($byType as $type => $rcs): ?>
                                                <optgroup label="<?= $e($type) ?>">
                                                <?php foreach ($rcs as $rc): ?><option value="<?= $rc['id'] ?>"<?= (int)($m['room_id'] ?? 0) === $rc['id'] ? ' selected' : '' ?>><?= $e($rc['code'] . ($rc['cap'] ? ' (seats ' . $rc['cap'] . ')' : '') . ($rc['active'] ? '' : ' - inactive')) ?></option><?php endforeach; ?>
                                                </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-sm btn-outline-danger gen-meeting-remove" aria-label="Remove meeting" title="Remove this meeting">&times;</button>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn btn-sm btn-link p-0" onclick="addMeeting('<?= $e($k) ?>')">+ Add meeting</button>
                            </div>
                        </div>
                        <label class="gen-remove"><input type="checkbox" name="row[<?= $e($k) ?>][remove]" value="1"> Remove from draft</label>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        </details>
    <?php endforeach; ?>
    </div>
    <?php if (!$rows): ?><div class="alert alert-info">Nothing was drafted. Every class may already exist, or none could be placed. See below.</div><?php endif; ?>

    <?php if ($draft['unplaced']): ?>
        <h2 class="h6 mt-4 mb-2">Could not be placed</h2>
        <div class="table-responsive">
        <table class="table table-sm bg-white">
            <thead><tr><th>Section</th><th>Subject</th><th>Why</th></tr></thead>
            <tbody>
            <?php foreach ($draft['unplaced'] as $u): ?>
                <tr><td><?= $e($u['section']) ?></td><td><?= $e($u['code']) ?></td><td><?= $e($u['reason']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <input type="hidden" name="form_end" value="1">
    <div class="gen-actions">
        <div class="gen-actions-status" aria-live="polite">
            <span id="genDirtyNote" hidden><i class="bi bi-pencil-fill gen-ic--warning" aria-hidden="true"></i> You have edits that are not checked yet. Press Check again before saving.</span>
            <span id="genStateNote">
            <?php if (!$rows): ?>
                <i class="bi bi-info-circle-fill" aria-hidden="true"></i> Nothing to save.
            <?php elseif ($canSave): ?>
                <i class="bi bi-check-circle-fill gen-ic--success" aria-hidden="true"></i> Ready to save <?= count($rows) ?> class<?= count($rows) === 1 ? '' : 'es' ?>.
            <?php else: ?>
                <i class="bi bi-x-circle-fill gen-ic--danger" aria-hidden="true"></i>
                <?= $check['need_teacher'] ? $check['need_teacher'] . ' still need a teacher. ' : '' ?><?= $check['conflicts'] ? $check['conflicts'] . ' have a conflict. ' : '' ?>Fix them, then press Check again to turn Save on.
            <?php endif; ?>
            </span>
        </div>
        <div class="gen-actions-buttons">
            <button type="submit" name="action" value="recheck" class="btn btn-outline-primary">Check again</button>
            <button type="submit" name="action" value="save" class="btn btn-primary"<?= $canSave ? '' : ' disabled' ?>>Save <?= count($rows) ?> class<?= count($rows) === 1 ? '' : 'es' ?></button>
            <button type="submit" name="action" value="reshuffle" class="btn btn-outline-secondary" formnovalidate
                    data-confirm-label="Try again" data-confirm-tone="warning" data-confirm="Build a different arrangement? Your edits to this draft are lost.">Try a different arrangement</button>
            <button type="submit" name="action" value="discard" class="btn btn-outline-danger" formnovalidate
                    data-confirm-label="Discard" data-confirm-tone="danger" data-confirm="Discard this draft? Nothing has been saved.">Discard</button>
        </div>
    </div>
    </form>

    <div class="modal fade" id="genWeekModal" tabindex="-1" aria-labelledby="genWeekTitle" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="genWeekTitle">Weekly view of the draft</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="gen-wk-controls">
                        <div>
                            <label class="gen-label" for="genWeekBy">Show by</label>
                            <select id="genWeekBy" class="form-select form-select-sm">
                                <option value="section">Section</option>
                                <option value="teacher">Teacher</option>
                                <option value="room">Room</option>
                            </select>
                        </div>
                        <div class="gen-wk-pick">
                            <label class="gen-label" for="genWeekPick">Which one</label>
                            <select id="genWeekPick" class="form-select form-select-sm"></select>
                        </div>
                    </div>
                    <div id="genWeekGrid"></div>
                    <p class="gen-muted mt-2 mb-0">
                        Drawn from the draft as it is now, including edits you have not checked yet. Red means a conflict or a class that needs attention, amber a warning,
                        a dashed outline an edit that is not checked, and a dashed box the time students wait between classes. Click a class to open its Edit panel.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
    var genDays = <?= json_encode($dayChoices) ?>;
    var genRooms = <?= json_encode($roomChoices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    function addMeeting(key) {
        var box = document.getElementById('mt-' + key);
        var line = document.createElement('div');
        line.className = 'd-flex flex-wrap gap-1 mb-1 meeting-line';
        var days = '<option value="">Day</option>' + genDays.map(function (d) { return '<option>' + d + '</option>'; }).join('');
        var byType = {}, order = [];
        genRooms.forEach(function (r) { if (!r.active) { return; } if (!byType[r.type]) { byType[r.type] = []; order.push(r.type); } byType[r.type].push(r); });
        var rooms = '<option value="">No room</option>' + order.map(function (t) {
            return '<optgroup label="' + String(t).replace(/"/g, '&quot;') + '">' + byType[t].map(function (r) {
                return '<option value="' + r.id + '">' + String(r.code).replace(/</g, '&lt;') + (r.cap ? ' (seats ' + r.cap + ')' : '') + '</option>';
            }).join('') + '</optgroup>';
        }).join('');
        var n = 'row[' + key + ']';
        line.innerHTML =
            '<select class="form-select form-select-sm gen-w-day" name="' + n + '[day][]" aria-label="Day">' + days + '</select>' +
            '<input type="time" class="form-control form-control-sm gen-w-time" name="' + n + '[start][]" aria-label="Start time">' +
            '<input type="time" class="form-control form-control-sm gen-w-time" name="' + n + '[end][]" aria-label="End time">' +
            '<select class="form-select form-select-sm gen-w-room" name="' + n + '[room][]" aria-label="Room">' + rooms + '</select>' +
            '<button type="button" class="btn btn-sm btn-outline-danger gen-meeting-remove" aria-label="Remove meeting" title="Remove this meeting">&times;</button>';
        box.appendChild(line);
        var cls = box.closest('.gen-class');
        if (window.genRefresh) { window.genRefresh(cls); window.genDirty(cls); }
    }
    (function () {
        var form = document.getElementById('draftForm');
        var body = document.getElementById('genBody');
        var seed = form.getAttribute('data-seed');
        var short = { Monday: 'Mon', Tuesday: 'Tue', Wednesday: 'Wed', Thursday: 'Thu', Friday: 'Fri', Saturday: 'Sat' };

        function t12(v) {
            var m = /^(\d{1,2}):(\d{2})/.exec(v || '');
            if (!m) { return ''; }
            var h = parseInt(m[1], 10);
            return ((h % 12) || 12) + ':' + m[2] + ' ' + (h >= 12 ? 'PM' : 'AM');
        }
        function roomText(sel) {
            if (!sel.value) { return 'No room'; }
            return sel.options[sel.selectedIndex].text.replace(/\s*\(seats[^)]*\)/, '').replace(/ - inactive$/, '');
        }
        window.genRefresh = function (cls) {
            var box = cls.querySelector('.gen-meets');
            box.innerHTML = '';
            var lines = cls.querySelectorAll('.meeting-line');
            if (!lines.length) {
                var none = document.createElement('span');
                none.className = 'gen-muted';
                none.textContent = 'No weekly meeting (TBA)';
                box.appendChild(none);
            }
            lines.forEach(function (l) {
                var f = l.querySelectorAll('select, input');
                var div = document.createElement('div');
                div.className = 'gen-meet';
                div.textContent = (!f[0].value || !f[1].value || !f[2].value) ? 'Incomplete meeting'
                    : (short[f[0].value] || f[0].value) + ' ' + t12(f[1].value) + ' – ' + t12(f[2].value) + ' · ' + roomText(f[3]);
                box.appendChild(div);
            });
            var ts = cls.querySelector('.gen-teacher-select');
            var tn = cls.querySelector('.gen-teacher-name');
            var tm = cls.querySelector('.gen-teacher-match');
            if (!ts.value) {
                tn.textContent = 'No teacher';
                tn.classList.add('gen-ic--danger');
                tm.textContent = '';
            } else {
                var m = /^(.*) \(([^()]*)\)$/.exec(ts.options[ts.selectedIndex].text);
                tn.textContent = m ? m[1] : ts.options[ts.selectedIndex].text;
                tm.textContent = m ? m[2] : '';
                tn.classList.remove('gen-ic--danger');
            }
        };
        window.genDirty = function (cls) {
            cls.classList.add('gen-dirty');
            document.getElementById('genDirtyNote').hidden = false;
            document.getElementById('genStateNote').hidden = true;
        };

        form.addEventListener('click', function (ev) {
            var edit = ev.target.closest('.gen-edit');
            if (edit) {
                var ed = document.getElementById(edit.getAttribute('aria-controls'));
                var opening = ed.hidden;
                ed.hidden = !opening;
                edit.setAttribute('aria-expanded', opening ? 'true' : 'false');
                edit.querySelector('.gen-edit-label').textContent = opening ? 'Close' : 'Edit';
                return;
            }
            var rm = ev.target.closest('.gen-meeting-remove');
            if (rm) {
                var cls = rm.closest('.gen-class');
                rm.closest('.meeting-line').remove();
                window.genRefresh(cls);
                window.genDirty(cls);
            }
        });
        form.addEventListener('change', function (ev) {
            var cls = ev.target.closest('.gen-editor') ? ev.target.closest('.gen-class') : null;
            if (!cls) { return; }
            if (ev.target.type === 'checkbox') { cls.dataset.remove = ev.target.checked ? '1' : ''; }
            window.genRefresh(cls);
            window.genDirty(cls);
        });
        form.addEventListener('input', function (ev) {
            var cls = ev.target.closest('.gen-editor') ? ev.target.closest('.gen-class') : null;
            if (cls && ev.target.type === 'time') { window.genRefresh(cls); window.genDirty(cls); }
        });

        var secs = document.querySelectorAll('.gen-sec');
        var openKey = 'genOpen-' + seed, saved = {};
        try { saved = JSON.parse(sessionStorage.getItem(openKey) || '{}'); } catch (e) { saved = {}; }
        secs.forEach(function (d) {
            var id = d.getAttribute('data-sec');
            if (id in saved) { d.open = !!saved[id]; }
            d.addEventListener('toggle', function () {
                saved[id] = d.open;
                try { sessionStorage.setItem(openKey, JSON.stringify(saved)); } catch (e) { }
            });
        });
        document.getElementById('genExpandAll').addEventListener('click', function () { secs.forEach(function (d) { d.open = true; }); });
        document.getElementById('genCollapseAll').addEventListener('click', function () { secs.forEach(function (d) { d.open = false; }); });
        document.getElementById('genOnlyIssues').addEventListener('change', function () {
            body.classList.toggle('gen-only-issues', this.checked);
            if (this.checked) {
                document.querySelectorAll('.gen-sec:not([data-state="ok"])').forEach(function (d) { d.open = true; });
            }
        });

        var scrollKey = 'genScroll-' + seed;
        form.addEventListener('submit', function (ev) {
            if (ev.submitter && ev.submitter.value === 'recheck') {
                try { sessionStorage.setItem(scrollKey, String(window.scrollY)); } catch (e) { }
            }
        });
        var y = null;
        try { y = sessionStorage.getItem(scrollKey); sessionStorage.removeItem(scrollKey); } catch (e) { y = null; }
        if (y !== null) { window.scrollTo(0, parseInt(y, 10) || 0); }
    })();
    </script>
    <script>
    (function () {
        var modalEl = document.getElementById('genWeekModal');
        var gridBox = document.getElementById('genWeekGrid');
        var bySel = document.getElementById('genWeekBy');
        var pickSel = document.getElementById('genWeekPick');
        var state = { by: 'section', pick: '' };
        var pendingKey = null;
        var LUNCH_S = 690, LUNCH_E = 750;
        var short = { Monday: 'Mon', Tuesday: 'Tue', Wednesday: 'Wed', Thursday: 'Thu', Friday: 'Fri', Saturday: 'Sat' };

        function el(tag, cls, text) {
            var n = document.createElement(tag);
            if (cls) { n.className = cls; }
            if (text !== undefined) { n.textContent = text; }
            return n;
        }
        function mins(v) {
            var m = /^(\d{1,2}):(\d{2})/.exec(v || '');
            return m ? parseInt(m[1], 10) * 60 + parseInt(m[2], 10) : null;
        }
        function t12(min) {
            var h = Math.floor(min / 60), m = min % 60;
            return ((h % 12) || 12) + ':' + (m < 10 ? '0' : '') + m + ' ' + (h >= 12 ? 'PM' : 'AM');
        }
        function rng(s, e) {
            var a = t12(s), b = t12(e);
            return a.slice(-2) === b.slice(-2) ? a.slice(0, -3) + ' – ' + b : a + ' – ' + b;
        }
        function dur(min) {
            return min >= 60 ? Math.floor(min / 60) + ' h' + (min % 60 ? ' ' + (min % 60) + ' min' : '') : min + ' min';
        }
        function roomText(sel) {
            return sel.options[sel.selectedIndex].text.replace(/\s*\(seats[^)]*\)/, '').replace(/ - inactive$/, '');
        }
        function teacherText(sel) {
            var t = sel.options[sel.selectedIndex].text;
            var m = /^(.*) \(([^()]*)\)$/.exec(t);
            return m ? m[1] : t;
        }

        function collect() {
            var out = [];
            document.querySelectorAll('.gen-class').forEach(function (cls) {
                if (cls.dataset.remove === '1') { return; }
                var sec = cls.closest('.gen-sec');
                var ts = cls.querySelector('.gen-teacher-select');
                cls.querySelectorAll('.meeting-line').forEach(function (l) {
                    var f = l.querySelectorAll('select, input');
                    var s = mins(f[1].value), e = mins(f[2].value);
                    if (!f[0].value || s === null || e === null || e <= s) { return; }
                    out.push({
                        key: cls.dataset.key, state: cls.dataset.state, dirty: cls.classList.contains('gen-dirty'), code: cls.dataset.code,
                        secId: sec.dataset.sec, sec: sec.querySelector('.gen-sec-title').textContent,
                        teacherId: ts.value || 'none', teacher: ts.value ? teacherText(ts) : 'No teacher',
                        roomId: f[3].value || 'none', room: f[3].value ? roomText(f[3]) : 'No room',
                        day: f[0].value, s: s, e: e, lane: 0, lanes: 1, clash: false
                    });
                });
            });
            return out;
        }

        function choices(ev, by) {
            var map = {}, order = [];
            if (by === 'section') {
                document.querySelectorAll('.gen-sec').forEach(function (d) {
                    map[d.dataset.sec] = d.querySelector('.gen-sec-title').textContent;
                    order.push(d.dataset.sec);
                });
            } else {
                ev.forEach(function (x) {
                    var id = by === 'teacher' ? x.teacherId : x.roomId;
                    if (id === 'none' || map[id] !== undefined) { return; }
                    map[id] = by === 'teacher' ? x.teacher : x.room;
                    order.push(id);
                });
                order.sort(function (a, b) { return map[a].localeCompare(map[b]); });
            }
            return { map: map, order: order };
        }

        function gapSegs(a, b) {
            if (b <= LUNCH_S || a >= LUNCH_E) { return [[a, b]]; }
            var out = [];
            if (a < LUNCH_S) { out.push([a, LUNCH_S]); }
            if (b > LUNCH_E) { out.push([LUNCH_E, b]); }
            return out;
        }

        function setLanes(list) {
            var cluster = [], reach = -1;
            function flush() {
                var lanes = [];
                cluster.forEach(function (x) {
                    var l = 0;
                    while (lanes[l] !== undefined && lanes[l] > x.s) { l++; }
                    lanes[l] = x.e;
                    x.lane = l;
                });
                cluster.forEach(function (x) { x.lanes = lanes.length; x.clash = cluster.length > 1; });
                cluster = [];
            }
            list.forEach(function (x) {
                if (cluster.length && x.s >= reach) { flush(); reach = -1; }
                cluster.push(x);
                reach = Math.max(reach, x.e);
            });
            if (cluster.length) { flush(); }
        }

        function drawGrid(mine, by) {
            var days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
            if (mine.some(function (x) { return x.day === 'Saturday'; })) { days.push('Saturday'); }
            var lo = 420, hi = 1080;
            mine.forEach(function (x) { lo = Math.min(lo, x.s); hi = Math.max(hi, x.e); });
            lo = Math.floor(lo / 60) * 60;
            hi = Math.ceil(hi / 60) * 60;
            var rows = (hi - lo) / 30;
            var lineStart = function (t) { return 2 + Math.floor((t - lo) / 30); };
            var lineEnd = function (t) { return 2 + Math.ceil((t - lo) / 30); };

            var grid = el('div', 'schedule-grid gen-wk-grid');
            grid.style.gridTemplateColumns = '64px repeat(' + days.length + ', 1fr)';
            grid.style.gridTemplateRows = 'auto repeat(' + rows + ', 26px)';
            grid.style.minWidth = '640px';
            grid.appendChild(el('div', 'sg-corner'));
            days.forEach(function (d, i) {
                var h = el('div', 'sg-day-header', short[d]);
                h.style.gridColumn = String(i + 2);
                grid.appendChild(h);
            });
            for (var slot = 0; slot < rows; slot++) {
                var minutes = lo + slot * 30;
                if (minutes % 60 === 0) {
                    var lab = el('div', 'sg-time-label', t12(minutes).replace(':00', ''));
                    lab.style.gridRow = String(slot + 2);
                    grid.appendChild(lab);
                }
                var lunch = minutes >= LUNCH_S && minutes < LUNCH_E;
                for (var d = 0; d < days.length; d++) {
                    var c = el('div', 'sg-cell' + (lunch ? ' gen-wk-lunch' : ''));
                    c.style.gridRow = String(slot + 2);
                    c.style.gridColumn = String(d + 2);
                    grid.appendChild(c);
                }
            }

            days.forEach(function (day, di) {
                var list = mine.filter(function (x) { return x.day === day; }).sort(function (a, b) { return a.s - b.s || a.e - b.e; });
                setLanes(list);
                if (by === 'section' && list.length > 1) {
                    var prevEnd = list[0].e;
                    for (var i = 1; i < list.length; i++) {
                        if (list[i].s > prevEnd) {
                            var segs = gapSegs(prevEnd, list[i].s);
                            var total = segs.reduce(function (n, g) { return n + g[1] - g[0]; }, 0);
                            segs.forEach(function (g) {
                                var w = el('div', 'gen-wk-wait' + (total >= 90 ? ' gen-wk-wait--long' : ''), g[1] - g[0] >= 30 ? dur(g[1] - g[0]) + ' wait' : '');
                                w.style.gridColumn = String(di + 2);
                                w.style.gridRow = lineStart(g[0]) + ' / ' + Math.max(lineStart(g[0]) + 1, lineEnd(g[1]));
                                grid.appendChild(w);
                            });
                        }
                        prevEnd = Math.max(prevEnd, list[i].e);
                    }
                }
                list.forEach(function (x) {
                    var cls = 'sg-event gen-wk-ev';
                    if (x.clash || x.state === 'bad') { cls += ' gen-wk-ev--bad'; } else if (x.state === 'warn') { cls += ' gen-wk-ev--warn'; }
                    if (x.dirty) { cls += ' gen-wk-ev--dirty'; }
                    var n = el('div', cls);
                    n.dataset.key = x.key;
                    n.tabIndex = 0;
                    n.setAttribute('role', 'button');
                    n.style.gridColumn = String(di + 2);
                    n.style.gridRow = lineStart(x.s) + ' / ' + Math.max(lineStart(x.s) + 1, lineEnd(x.e));
                    if (x.lanes > 1) {
                        n.style.width = 'calc(' + (100 / x.lanes) + '% - 4px)';
                        n.style.marginLeft = 'calc(' + (100 / x.lanes * x.lane) + '% + 2px)';
                    }
                    var extra = by === 'section' ? [x.room, x.teacher] : (by === 'teacher' ? [x.room, x.sec] : [x.sec, x.teacher]);
                    n.title = x.code + ' · ' + t12(x.s) + ' – ' + t12(x.e) + ' · ' + extra.join(' · ') + (x.clash ? ' · overlaps another class' : '');
                    n.appendChild(el('strong', '', x.code));
                    n.appendChild(el('span', '', rng(x.s, x.e)));
                    extra.forEach(function (t) { n.appendChild(el('span', '', t)); });
                    grid.appendChild(n);
                });
            });

            var wrap = el('div', 'table-responsive');
            wrap.appendChild(grid);
            return wrap;
        }

        function render() {
            var ev = collect();
            var by = state.by;
            var ch = choices(ev, by);
            if (ch.map[state.pick] === undefined) { state.pick = ch.order.length ? ch.order[0] : ''; }
            bySel.value = by;
            pickSel.innerHTML = '';
            ch.order.forEach(function (id) {
                var o = el('option', '', ch.map[id]);
                o.value = id;
                pickSel.appendChild(o);
            });
            pickSel.value = state.pick;
            gridBox.innerHTML = '';
            if (!ch.order.length) {
                gridBox.appendChild(el('p', 'text-muted', 'Nothing to show yet.'));
                return;
            }
            var mine = ev.filter(function (x) {
                return (by === 'section' ? x.secId : (by === 'teacher' ? x.teacherId : x.roomId)) === state.pick;
            });
            gridBox.appendChild(drawGrid(mine, by));
            if (!mine.length) {
                gridBox.appendChild(el('p', 'gen-muted mt-2 mb-0', 'No meetings are placed for this ' + by + ' in the draft.'));
            }
        }

        function openWeek(by, pick) {
            state.by = by;
            state.pick = pick || '';
            render();
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }

        document.getElementById('genWeekOpen').addEventListener('click', function () { openWeek('section', ''); });
        document.addEventListener('click', function (ev) {
            var b = ev.target.closest('.gen-wk-open');
            if (b) { ev.preventDefault(); openWeek('section', b.dataset.sec); }
        });
        bySel.addEventListener('change', function () { state.by = bySel.value; state.pick = ''; render(); });
        pickSel.addEventListener('change', function () { state.pick = pickSel.value; render(); });

        function jump(key) {
            var target = null;
            document.querySelectorAll('.gen-class').forEach(function (c) { if (c.dataset.key === key) { target = c; } });
            if (!target) { return; }
            target.closest('details').open = true;
            var edit = target.querySelector('.gen-edit');
            if (edit && edit.getAttribute('aria-expanded') === 'false') { edit.click(); }
            target.scrollIntoView({ behavior: 'smooth', block: 'center' });
            target.classList.add('gen-flash');
            setTimeout(function () { target.classList.remove('gen-flash'); }, 1800);
        }
        function pick(ev) {
            var n = ev.target.closest('.gen-wk-ev');
            if (!n) { return; }
            pendingKey = n.dataset.key;
            bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        }
        gridBox.addEventListener('click', pick);
        gridBox.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); pick(ev); }
        });
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (pendingKey !== null) { var k = pendingKey; pendingKey = null; jump(k); }
        });
    })();
    </script>
<?php endif; ?>
</div>
</body>
</html>