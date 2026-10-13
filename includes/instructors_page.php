<?php
/**
 * Shared Instructors page. The including page sets $pdo, $readOnly, $scopeDepartmentId (null means every
 * department) and $selfUrl before requiring this file.
 */
require_once __DIR__ . '/../src/helpers/tag_helper.php';
require_once __DIR__ . '/../src/helpers/instructor_helper.php';

$error = '';
$message = '';
$warning = '';
$e = fn($v): string => htmlspecialchars((string)$v);

$termId = ongoingTermId($pdo);
$termLabel = 'No ongoing term';
if ($termId) {
    $t = $pdo->prepare('SELECT school_year, semester FROM School_term WHERE term_id = :id');
    $t->execute(['id' => $termId]);
    $t = $t->fetch();
    $termLabel = 'SY ' . $t['school_year'] . ', ' . ((int)$t['semester'] === 3 ? 'Summer' : 'Semester ' . (int)$t['semester']);
}

/** One teacher with department and account status, or false when out of scope. */
$fetchTeacher = function (int $teacherId) use ($pdo, $scopeDepartmentId) {
    $stmt = $pdo->prepare(
        'SELECT t.*, d.department_name, a.username, a.email, a.is_active
         FROM Teacher t JOIN Accounts a ON a.account_id = t.account_id JOIN Department d ON d.department_id = t.department_id
         WHERE t.teacher_id = :id' . ($scopeDepartmentId !== null ? ' AND t.department_id = :dept' : '')
    );
    $params = ['id' => $teacherId] + ($scopeDepartmentId !== null ? ['dept' => $scopeDepartmentId] : []);
    $stmt->execute($params);
    return $stmt->fetch();
};

if (!$readOnly && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_instructor') {
    $postId = ctype_digit((string)($_POST['teacher_id'] ?? '')) ? (int)$_POST['teacher_id'] : 0;
    $teacher = $fetchTeacher($postId);
    $caps = validateTeacherCaps((string)($_POST['max_units'] ?? ''), (string)($_POST['max_hours_per_week'] ?? ''));
    $newTags = parseNewTags((string)($_POST['new_tags'] ?? ''));

    if (!$teacher) {
        $error = 'That teacher was not found.';
    } elseif (is_string($caps)) {
        $error = $caps;
    } elseif (is_string($newTags)) {
        $error = $newTags;
    } else {
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE Teacher SET max_units = :u, max_hours_per_week = :h WHERE teacher_id = :id')
                ->execute(['u' => $caps['units'], 'h' => $caps['hours'], 'id' => $postId]);
            replaceTeacherTags($pdo, $postId, resolveTagIds($pdo, (array)($_POST['tag_ids'] ?? []), $newTags));
            $pdo->commit();
            $message = 'Instructor profile saved.';
            // Lowering a limit below the current load is allowed. It only warns.
            $over = loadWarningText(['max_units' => $caps['units'], 'max_hours_per_week' => $caps['hours']], teacherLoad($pdo, $postId, $termId));
            if ($over !== '') {
                $warning = 'This teacher is now over the limit this term: ' . $over . '. Their classes were not changed.';
            }
        } catch (Exception $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = errorMessage($ex, 'Could not save the instructor profile.');
        }
    }
}

$detailId = ctype_digit((string)($_GET['id'] ?? '')) ? (int)$_GET['id'] : (int)($_POST['teacher_id'] ?? 0);
$detail = $detailId ? $fetchTeacher($detailId) : false;
$tagsAll = allTags($pdo);

$departments = $pdo->query('SELECT department_id, department_name FROM Department ORDER BY department_name')->fetchAll();
$search = trim((string)($_GET['q'] ?? ''));
$filterDept = $scopeDepartmentId ?? (ctype_digit((string)($_GET['dept'] ?? '')) ? (int)$_GET['dept'] : 0);

if ($detail) {
    $detailTags = $pdo->prepare('SELECT t.tag_id, t.tag_name FROM Teacher_tag tt JOIN Tag t ON t.tag_id = tt.tag_id WHERE tt.teacher_id = :id ORDER BY t.tag_name');
    $detailTags->execute(['id' => $detailId]);
    $detailTags = $detailTags->fetchAll();
    $load = teacherLoad($pdo, $detailId, $termId);

    $stmt = $pdo->prepare(
        'SELECT co.offering_id, co.day_of_week, co.start_time, co.end_time, co.room, s.subject_code, s.subject_name, s.units,
                sec.section_name, sec.year_level, st.term_id, st.school_year, st.semester, st.status AS term_status,
                (SELECT COUNT(*) FROM Enrolled_subject es WHERE es.offering_id = co.offering_id) AS enrolled_count
         FROM Class_Schedule co
         JOIN Subject s ON s.subject_id = co.subject_id
         JOIN Section sec ON sec.section_id = co.section_id
         JOIN School_term st ON st.term_id = co.term_id
         WHERE co.teacher_id = :tid
         ORDER BY st.term_id DESC, s.subject_code, sec.section_name'
    );
    $stmt->execute(['tid' => $detailId]);
    $byTerm = [];
    foreach (foldMeetings($stmt->fetchAll(), 'offering_id') as $c) {
        $byTerm[(int)$c['term_id']][] = $c;
    }
    $overText = loadWarningText($detail, $load);
} else {
    $where = [];
    $params = [];
    if ($filterDept) {
        $where[] = 't.department_id = :dept';
        $params['dept'] = $filterDept;
    }
    if ($search !== '') {
        $where[] = "(t.last_name LIKE :q1 OR t.first_name LIKE :q2 OR a.username LIKE :q3)";
        $params += ['q1' => "%$search%", 'q2' => "%$search%", 'q3' => "%$search%"];
    }
    $stmt = $pdo->prepare(
        'SELECT t.*, d.department_name, a.is_active
         FROM Teacher t JOIN Accounts a ON a.account_id = t.account_id JOIN Department d ON d.department_id = t.department_id'
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY t.last_name, t.first_name'
    );
    $stmt->execute($params);
    $teachers = $stmt->fetchAll();
    $loads = $termId ? teacherLoadMap($pdo, $termId) : [];
    $tagMap = [];
    foreach ($pdo->query('SELECT tt.teacher_id, t.tag_name FROM Teacher_tag tt JOIN Tag t ON t.tag_id = tt.tag_id ORDER BY t.tag_name')->fetchAll() as $r) {
        $tagMap[(int)$r['teacher_id']][] = $r['tag_name'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Instructors</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-1">Instructors</h1>
    <p class="text-muted small mb-3">Load is counted per class (one subject for one section), not per meeting. Shown for <?= $e($termLabel) ?>.<?= $readOnly ? ' Read only.' : ' Account actions such as invites and deactivation stay in Staff Accounts.' ?></p>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="6000"><?= $e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($warning): ?><div class="alert alert-warning"><?= $e($warning) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

<?php if ($detailId && !$detail): ?>
    <div class="alert alert-warning">That instructor was not found.</div>
    <a href="<?= $e($selfUrl) ?>" class="btn btn-outline-secondary btn-sm">Back to Instructors</a>

<?php elseif ($detail): ?>
    <a href="<?= $e($selfUrl) ?>" class="btn btn-outline-secondary btn-sm mb-3">Back to Instructors</a>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5 mb-1"><?= $e($detail['last_name'] . ', ' . $detail['first_name']) ?> <?= statusBadge($detail['is_active'] ? 'active' : 'inactive') ?></h2>
            <div class="text-muted mb-2"><?= $e($detail['department_name']) ?> · <?= $e($detail['username']) ?><?= $detail['email'] ? ' · ' . $e($detail['email']) : '' ?></div>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="small text-muted">Units this term</div>
                    <div class="fs-5"><?= fmtLoad($load['units']) ?> / <?= (int)$detail['max_units'] ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted">Hours a week this term</div>
                    <div class="fs-5"><?= fmtLoad($load['hours']) ?> / <?= $detail['max_hours_per_week'] !== null ? fmtLoad((float)$detail['max_hours_per_week']) : 'no cap' ?></div>
                </div>
            </div>
            <?php if ($overText !== ''): ?><div class="alert alert-warning mt-3 mb-0 py-2 small">Over the limit this term: <?= $e($overText) ?>.</div><?php endif; ?>
            <div class="mt-3"><span class="small text-muted">Tags</span><br>
                <?= $detailTags ? implode(' ', array_map(fn($g) => '<span class="badge text-bg-light border">' . $e($g['tag_name']) . '</span>', $detailTags)) : '<span class="text-muted">None</span>' ?>
            </div>
        </div>
    </div>

    <?php if (!$readOnly): ?>
    <form method="post" class="card mb-4">
        <div class="card-body">
            <h3 class="h6">Academic profile</h3>
            <input type="hidden" name="action" value="save_instructor">
            <input type="hidden" name="teacher_id" value="<?= $detailId ?>">
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Max units</label>
                    <input type="number" min="1" max="60" class="form-control" name="max_units" value="<?= $e($_POST['max_units'] ?? $detail['max_units']) ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Max hours per week <span class="text-muted">(optional)</span></label>
                    <input type="number" min="1" max="80" step="0.5" class="form-control" name="max_hours_per_week" placeholder="No cap"
                           value="<?= $e($_POST['max_hours_per_week'] ?? ($detail['max_hours_per_week'] !== null ? fmtLoad((float)$detail['max_hours_per_week']) : '')) ?>">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Tags</label>
                <?= renderTagPicker($tagsAll, $_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '' ? array_map('intval', (array)($_POST['tag_ids'] ?? [])) : array_column($detailTags, 'tag_id'), (string)($_POST['new_tags'] ?? '')) ?>
            </div>
            <button type="submit" class="btn btn-primary">Save profile</button>
            <div class="form-text mt-2">Lowering a limit below the current load is allowed. It only warns, and no class is changed.</div>
        </div>
    </form>
    <?php endif; ?>

    <h3 class="h6">Classes</h3>
    <?php if (!$byTerm): ?><div class="text-muted">No classes yet.</div><?php endif; ?>
    <?php foreach ($byTerm as $tid => $classes): $first = $classes[0]; ?>
        <div class="mt-3 mb-1 fw-semibold">SY <?= $e($first['school_year']) ?>, <?= (int)$first['semester'] === 3 ? 'Summer' : 'Semester ' . (int)$first['semester'] ?> <?= statusBadge($first['term_status']) ?></div>
        <div class="table-responsive">
        <table class="table table-sm bg-white align-middle">
            <thead><tr><th>Subject</th><th>Section</th><th>Schedule</th><th>Units</th><th>Enrolled</th></tr></thead>
            <tbody>
            <?php foreach ($classes as $c): ?>
                <tr>
                    <td><strong><?= $e($c['subject_code']) ?></strong> <span class="text-muted"><?= $e($c['subject_name']) ?></span></td>
                    <td><?= $e(sectionLabel($c['year_level'], $c['section_name'])) ?></td>
                    <td class="small"><?= scheduleLinesHtml($c) ?></td>
                    <td><?= fmtLoad((float)$c['units']) ?></td>
                    <td><?= (int)$c['enrolled_count'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endforeach; ?>

<?php else: ?>
    <form method="get" class="d-flex mb-3 search-bar">
        <input type="text" class="form-control me-2" name="q" placeholder="Search by name or username" value="<?= $e($search) ?>">
        <?php if ($scopeDepartmentId === null && count($departments) > 1): ?>
            <select class="form-select me-2 w-auto" name="dept" aria-label="Department">
                <option value="">All departments</option>
                <?php foreach ($departments as $d): ?><option value="<?= (int)$d['department_id'] ?>"<?= $filterDept === (int)$d['department_id'] ? ' selected' : '' ?>><?= $e($d['department_name']) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button type="submit" class="btn btn-outline-primary">Search</button>
        <?php if ($search !== '' || ($scopeDepartmentId === null && $filterDept)): ?><a href="<?= $e($selfUrl) ?>" class="btn btn-outline-secondary ms-2">Clear</a><?php endif; ?>
    </form>

    <div class="table-responsive">
    <table class="table table-hover bg-white align-middle">
        <thead><tr><th>Name</th><?php if ($scopeDepartmentId === null): ?><th>Department</th><?php endif; ?><th>Tags</th><th>Units</th><th>Hours / week</th><th>Classes</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($teachers as $t):
            $ld = $loads[(int)$t['teacher_id']] ?? ['classes' => 0, 'units' => 0.0, 'hours' => 0.0];
            $over = loadWarningText($t, $ld) !== '';
            $tags = $tagMap[(int)$t['teacher_id']] ?? []; ?>
            <tr>
                <td><?= $e($t['last_name'] . ', ' . $t['first_name']) ?></td>
                <?php if ($scopeDepartmentId === null): ?><td><?= $e($t['department_name']) ?></td><?php endif; ?>
                <td class="small"><?= $tags ? $e(implode(', ', $tags)) : '<span class="text-muted">None</span>' ?></td>
                <td class="<?= $over ? 'text-danger fw-semibold' : '' ?>"><?= fmtLoad($ld['units']) ?> / <?= (int)$t['max_units'] ?></td>
                <td class="<?= $over ? 'text-danger fw-semibold' : '' ?>"><?= fmtLoad($ld['hours']) ?> / <?= $t['max_hours_per_week'] !== null ? fmtLoad((float)$t['max_hours_per_week']) : '<span class="text-muted">no cap</span>' ?></td>
                <td><?= (int)$ld['classes'] ?></td>
                <td><?= statusBadge($t['is_active'] ? 'active' : 'inactive') ?></td>
                <td><a class="btn btn-sm btn-outline-primary" href="<?= $e($selfUrl) ?>?id=<?= (int)$t['teacher_id'] ?>">View</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$teachers): ?><tr><td colspan="8" class="text-muted"><?= $search !== '' ? 'No instructors match that search.' : 'No teachers yet.' ?></td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
<?php endif; ?>
</div>
</body>
</html>
