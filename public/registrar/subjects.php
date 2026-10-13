<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/tag_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$roomTypes = $pdo->query('SELECT room_type_id, type_name FROM Room_type ORDER BY type_name')->fetchAll();
$partKinds = ['Lecture', 'Laboratory', 'Activity', 'Other'];

$error = '';
$message = '';
$dupNameWarning = '';

/**
 * Where a subject is referenced. Subjects are shared by every department, so editing or deleting
 * one reaches curricula, offerings, prerequisites and credits well beyond the registrar's own.
 */
function subjectUsage(PDO $pdo, int $subjectId): array
{
    $one = function (string $sql) use ($pdo, $subjectId): int {
        preg_match_all('/:(\w+)/', $sql, $found);
        $params = array_fill_keys(array_unique($found[1]), $subjectId);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    };

    $usage = [
        'curricula' => $one('SELECT COUNT(DISTINCT curriculum_id) FROM Curriculum_subject WHERE subject_id = :id'),
        'departments' => $one(
            'SELECT COUNT(DISTINCT p.department_id) FROM Curriculum_subject cs
             JOIN Curriculum c ON c.curriculum_id = cs.curriculum_id JOIN Program p ON p.program_id = c.program_id
             WHERE cs.subject_id = :id'
        ),
        'offerings' => $one('SELECT COUNT(*) FROM Class_Offering WHERE subject_id = :id'),
        'prerequisites' => $one('SELECT COUNT(*) FROM Curriculum_prerequisite WHERE subject_id = :as_subject OR prerequisite_subject_id = :as_required'),
        'credits' => $one('SELECT (SELECT COUNT(*) FROM Shift_credit WHERE credited_subject_id = :shift_id) + (SELECT COUNT(*) FROM Transferee_credit WHERE credited_subject_id = :transfer_id)'),
    ];
    $usage['total'] = $usage['curricula'] + $usage['offerings'] + $usage['prerequisites'] + $usage['credits'];

    $parts = [];
    if ($usage['curricula'] > 0) {
        $parts[] = $usage['curricula'] . ' curricul' . ($usage['curricula'] === 1 ? 'um' : 'a')
            . ' in ' . $usage['departments'] . ' department' . ($usage['departments'] === 1 ? '' : 's');
    }
    if ($usage['offerings'] > 0) { $parts[] = $usage['offerings'] . ' class offering' . ($usage['offerings'] === 1 ? '' : 's'); }
    if ($usage['prerequisites'] > 0) { $parts[] = $usage['prerequisites'] . ' prerequisite link' . ($usage['prerequisites'] === 1 ? '' : 's'); }
    if ($usage['credits'] > 0) { $parts[] = $usage['credits'] . ' credit record' . ($usage['credits'] === 1 ? '' : 's'); }
    $usage['summary'] = $parts ? implode(', ', $parts) . '.' : '';

    return $usage;
}

/** The weekly parts exactly as posted, for refilling the form after an error. */
function postedPartsRaw(): array
{
    $rows = [];
    foreach ((array)($_POST['part_kind'] ?? []) as $i => $kind) {
        $rows[] = [
            'kind' => (string)$kind, 'hours' => (string)(((array)($_POST['part_hours'] ?? []))[$i] ?? ''),
            'meetings' => (string)(((array)($_POST['part_meetings'] ?? []))[$i] ?? ''),
            'room' => (string)(((array)($_POST['part_room'] ?? []))[$i] ?? ''),
            'fallback' => (string)(((array)($_POST['part_fallback'] ?? []))[$i] ?? ''),
        ];
    }
    return $rows;
}

/**
 * Weekly parts of a subject: a kind, hours per meeting, meetings per week and the room types it needs.
 * Zero hours means the class has no weekly meeting (OJT, thesis) and shows as TBA. Returns the parts or an error.
 */
function postedParts(array $roomTypeIds, array $kinds): array|string
{
    $out = [];
    foreach (postedPartsRaw() as $p) {
        if ($p['kind'] === '' && $p['hours'] === '' && $p['room'] === '') {
            continue;
        }
        if (!in_array($p['kind'], $kinds, true)) {
            return 'Pick a kind for every weekly part.';
        }
        if ($p['hours'] === '' || !is_numeric($p['hours']) || (float)$p['hours'] < 0 || (float)$p['hours'] > 5) {
            return 'Hours per meeting must be a number from 0 to 5, because a class block has to fit before or after lunch.';
        }
        $hours = (float)$p['hours'];
        if (abs($hours * 2 - round($hours * 2)) > 0.0001) {
            return 'Hours per meeting must be a multiple of half an hour.';
        }
        if ($hours === 0.0) {
            $out[] = ['kind' => $p['kind'], 'hours' => 0.0, 'meetings' => 1, 'room' => null, 'fallback' => null];
            continue;
        }
        if ($p['meetings'] === '' || !ctype_digit($p['meetings']) || (int)$p['meetings'] < 1 || (int)$p['meetings'] > 6) {
            return 'Meetings per week must be from 1 to 6.';
        }
        $meetings = (int)$p['meetings'];
        if (!ctype_digit($p['room']) || !in_array((int)$p['room'], $roomTypeIds, true)) {
            return 'Pick a preferred room type for every part that has hours.';
        }
        $fallback = null;
        if ($p['fallback'] !== '') {
            if (!ctype_digit($p['fallback']) || !in_array((int)$p['fallback'], $roomTypeIds, true)) {
                return 'Pick a valid fallback room type.';
            }
            if ((int)$p['fallback'] === (int)$p['room']) {
                return 'The fallback room type must differ from the preferred one.';
            }
            $fallback = (int)$p['fallback'];
        }
        $out[] = ['kind' => $p['kind'], 'hours' => $hours, 'meetings' => $meetings, 'room' => (int)$p['room'], 'fallback' => $fallback];
    }
    if (count($out) > 6) {
        return 'A subject can have at most 6 weekly parts.';
    }
    return $out;
}

function replaceSubjectParts(PDO $pdo, int $subjectId, array $parts): void
{
    $pdo->prepare('DELETE FROM Subject_requirement WHERE subject_id = :id')->execute(['id' => $subjectId]);
    $ins = $pdo->prepare(
        'INSERT INTO Subject_requirement (subject_id, part_kind, hours_per_meeting, meetings_per_week, preferred_room_type_id, fallback_room_type_id)
         VALUES (:s, :k, :h, :m, :r, :f)'
    );
    foreach ($parts as $p) {
        $ins->execute(['s' => $subjectId, 'k' => $p['kind'], 'h' => $p['hours'], 'm' => $p['meetings'], 'r' => $p['room'], 'f' => $p['fallback']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = $_POST['subject_id'] ?? '';
        $code = trim($_POST['subject_code'] ?? '');
        $name = trim($_POST['subject_name'] ?? '');
        $description = trim($_POST['subject_description'] ?? '');
        $units = $_POST['units'] ?? '';
        $yearLevel = $_POST['year_level'] ?? '';

        if ($code === '' || $name === '' || $units === '' || !ctype_digit($units)) {
            $error = 'Subject code, name, and a numeric unit count are required.';
        } elseif (!is_string($yearLevel) || !ctype_digit($yearLevel) || (int)$yearLevel < 1 || (int)$yearLevel > 4) {
            $error = 'Pick a year level from 1 to 4.';
        } elseif (is_string($parts = postedParts(array_map('intval', array_column($roomTypes, 'room_type_id')), $partKinds))) {
            $error = $parts;
        } elseif (is_string($newTagNames = parseNewTags((string)($_POST['new_tags'] ?? '')))) {
            $error = $newTagNames;
        } else {
            $codeCheck = $pdo->prepare('SELECT 1 FROM Subject WHERE LOWER(subject_code) = LOWER(:c) AND subject_id <> :id');
            $codeCheck->execute(['c' => $code, 'id' => $id ?: 0]);
            $nameCheck = $pdo->prepare('SELECT 1 FROM Subject WHERE LOWER(subject_name) = LOWER(:n) AND subject_id <> :id');
            $nameCheck->execute(['n' => $name, 'id' => $id ?: 0]);

            if ($codeCheck->fetch() !== false) {
                $error = "A subject with the code \"$code\" already exists.";
            } elseif (!isset($_POST['confirm_dup_name']) && $nameCheck->fetch() !== false) {
                $dupNameWarning = "A subject named \"$name\" already exists under a different code.";
            } else {
                try {
                    $pdo->beginTransaction();
                    if ($id) {
                        $stmt = $pdo->prepare(
                            'UPDATE Subject SET subject_code=:code, subject_name=:name, subject_description=:desc, units=:units, year_level=:year
                             WHERE subject_id=:id'
                        );
                        $stmt->execute(['code' => $code, 'name' => $name, 'desc' => $description ?: null, 'units' => $units, 'year' => (int)$yearLevel, 'id' => $id]);
                        $savedId = (int)$id;
                        $message = "Subject #$id updated.";
                    } else {
                        $stmt = $pdo->prepare(
                            'INSERT INTO Subject (subject_code, subject_name, subject_description, units, year_level)
                             VALUES (:code, :name, :desc, :units, :year)'
                        );
                        $stmt->execute(['code' => $code, 'name' => $name, 'desc' => $description ?: null, 'units' => $units, 'year' => (int)$yearLevel]);
                        $savedId = (int)$pdo->lastInsertId();
                        $message = 'Subject added.';
                    }
                    replaceSubjectTags($pdo, $savedId, resolveTagIds($pdo, (array)($_POST['tag_ids'] ?? []), $newTagNames));
                    replaceSubjectParts($pdo, $savedId, $parts);
                    $pdo->commit();
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $error = errorMessage($e, 'Could not save the subject.');
                    $message = '';
                }
            }
        }
    } elseif ($action === 'delete') {
        $id = $_POST['subject_id'] ?? '';
        $usage = subjectUsage($pdo, ctype_digit((string)$id) ? (int)$id : 0);
        if ($usage['total'] > 0) {
            $error = 'This subject is still in use: ' . $usage['summary'] . ' Remove it from those first.';
        } else {
            try {
                $pdo->prepare('DELETE FROM Subject WHERE subject_id = :id')->execute(['id' => $id]);
                $message = "Subject #$id deleted.";
            } catch (Exception $e) {
                $error = errorMessage($e, 'Could not delete this subject.');
            }
        }
    }
}

$reopenForm = ($error !== '' || $dupNameWarning !== '') && ($_POST['action'] ?? '') === 'save';
$form = ['subject_id' => '', 'subject_code' => '', 'subject_name' => '', 'subject_description' => '', 'units' => '', 'year_level' => ''];
if ($reopenForm) {
    foreach ($form as $k => $_) { $form[$k] = (string)($_POST[$k] ?? ''); }
    $form['tag_ids'] = array_map('intval', (array)($_POST['tag_ids'] ?? []));
    $form['new_tags'] = (string)($_POST['new_tags'] ?? '');
    $form['parts'] = postedPartsRaw();
}

$search = trim($_GET['q'] ?? '');
$filterYear = (is_string($_GET['year'] ?? null) && ctype_digit($_GET['year']) && (int)$_GET['year'] >= 1 && (int)$_GET['year'] <= 4)
    ? (int)$_GET['year'] : 0;
$where = [];
$params = [];
if ($search !== '') {
    $where[] = '(subject_code LIKE :q1 OR subject_name LIKE :q2 OR subject_description LIKE :q3)';
    $params += ['q1' => "%$search%", 'q2' => "%$search%", 'q3' => "%$search%"];
}
if ($filterYear !== 0) {
    $where[] = 'year_level = :yl';
    $params['yl'] = $filterYear;
}
$stmt = $pdo->prepare('SELECT * FROM Subject' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY subject_code');
$stmt->execute($params);
$subjects = $stmt->fetchAll();
foreach ($subjects as $i => $s) {
    $subjects[$i]['usage'] = subjectUsage($pdo, (int)$s['subject_id']);
}

$allTags = allTags($pdo);
$tagsBySubject = [];
foreach ($pdo->query('SELECT st.subject_id, t.tag_id, t.tag_name FROM Subject_tag st JOIN Tag t ON t.tag_id = st.tag_id ORDER BY t.tag_name')->fetchAll() as $r) {
    $tagsBySubject[(int)$r['subject_id']][] = $r;
}
$partsBySubject = [];
foreach ($pdo->query(
    'SELECT sr.*, rt.type_name FROM Subject_requirement sr
     LEFT JOIN Room_type rt ON rt.room_type_id = sr.preferred_room_type_id ORDER BY sr.requirement_id'
)->fetchAll() as $r) {
    $partsBySubject[(int)$r['subject_id']][] = $r;
}
$fmtHours = fn($h): string => rtrim(rtrim(number_format((float)$h, 2), '0'), '.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Subjects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Manage Subjects</h1>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="4000"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error && !$reopenForm): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="mb-3">
        <button type="button" class="btn btn-primary" onclick="openSubjectModal({})"><i class="bi bi-plus-lg"></i> Add Subject</button>
    </div>

    <form method="get" class="d-flex mb-3 search-bar">
        <input type="text" class="form-control me-2" name="q" placeholder="Search by code, name, or description"
               value="<?= htmlspecialchars($search) ?>">
        <select class="form-select me-2 w-auto" name="year" aria-label="Year level">
            <option value="">All years</option>
            <?php for ($y = 1; $y <= 4; $y++): ?><option value="<?= $y ?>"<?= $filterYear === $y ? ' selected' : '' ?>>Year <?= $y ?></option><?php endfor; ?>
        </select>
        <button type="submit" class="btn btn-outline-primary">Search</button>
        <?php if ($search !== '' || $filterYear !== 0): ?>
            <a href="<?= BASE_URL ?>/registrar/subjects.php" class="btn btn-outline-secondary ms-2">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><th>Code</th><th>Name</th><th>Description</th><th>Units</th><th>Year</th><th>Tags</th><th>Weekly parts</th><th>Used by</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($subjects as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['subject_code']) ?></td>
                <td><?= htmlspecialchars($s['subject_name']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($s['subject_description'] ?? '') ?></td>
                <td><?= $s['units'] ?></td>
                <td>Year <?= (int)$s['year_level'] ?></td>
                <?php $myTags = $tagsBySubject[(int)$s['subject_id']] ?? []; $myParts = $partsBySubject[(int)$s['subject_id']] ?? []; ?>
                <td class="small"><?= $myTags ? htmlspecialchars(implode(', ', array_column($myTags, 'tag_name'))) : '<span class="text-muted">None</span>' ?></td>
                <td class="small">
                    <?php if ($myParts): foreach ($myParts as $p): ?>
                        <div><?= htmlspecialchars($p['part_kind'] . ' ' . ((float)$p['hours_per_meeting'] === 0.0 ? 'TBA' : $fmtHours($p['hours_per_meeting']) . 'h x' . (int)$p['meetings_per_week'] . ((int)$p['meetings_per_week'] > 1 ? ' (' . $fmtHours($p['hours_per_meeting'] * $p['meetings_per_week']) . 'h/week)' : '') . ($p['type_name'] ? ', ' . $p['type_name'] : ''))) ?></div>
                    <?php endforeach; else: ?><span class="text-muted">Default: <?= (int)$s['units'] ?>h lecture</span><?php endif; ?>
                </td>
                <td class="small"><?= $s['usage']['total'] > 0 ? htmlspecialchars($s['usage']['summary']) : '<span class="text-muted">Not used</span>' ?></td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            data-subject="<?= htmlspecialchars(json_encode(['subject_id' => $s['subject_id'], 'subject_code' => $s['subject_code'], 'subject_name' => $s['subject_name'], 'subject_description' => $s['subject_description'] ?? '', 'units' => $s['units'], 'year_level' => $s['year_level'], 'tag_ids' => array_map('intval', array_column($myTags, 'tag_id')), 'parts' => array_map(fn($p) => ['kind' => $p['part_kind'], 'hours' => $fmtHours($p['hours_per_meeting']), 'meetings' => (string)(int)$p['meetings_per_week'], 'room' => $p['preferred_room_type_id'] === null ? '' : (string)$p['preferred_room_type_id'], 'fallback' => $p['fallback_room_type_id'] === null ? '' : (string)$p['fallback_room_type_id']], $myParts), 'usage_note' => $s['usage']['curricula'] > 0 ? 'Shared subject: used by ' . $s['usage']['summary'] . ' Any change to the code, name or units applies everywhere it is used.' : '']), ENT_QUOTES) ?>"
                            onclick="openSubjectModal(JSON.parse(this.dataset.subject))">Edit</button>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="subject_id" value="<?= $s['subject_id'] ?>">
                        <?php if ($s['usage']['total'] > 0): ?>
                            <button type="button" class="btn btn-sm btn-outline-danger" disabled title="In use, so it cannot be deleted">Delete</button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    data-confirm="Delete this subject? It is not used anywhere yet." data-confirm-label="Delete" data-confirm-tone="danger">Delete</button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($subjects)): ?>
            <tr><td colspan="9" class="text-muted"><?= $search !== '' || $filterYear !== 0 ? 'No subjects match that search.' : 'No subjects yet.' ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</div>

<div class="modal fade" id="subjectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="subject_id" id="subjectId">
      <div class="modal-header">
        <h5 class="modal-title" id="subjectModalTitle">Add Subject</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if ($reopenForm && $error !== ''): ?><div class="alert alert-danger js-modal-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <div class="alert alert-info" id="subjectUsageNote" hidden></div>
        <?php if ($dupNameWarning !== ''): ?><div class="alert alert-warning js-modal-alert"><?= htmlspecialchars($dupNameWarning) ?> Save anyway?<input type="hidden" name="confirm_dup_name" value="1"></div><?php endif; ?>
        <div class="row">
            <div class="col-5 mb-3">
                <label class="form-label">Code</label>
                <input class="form-control" name="subject_code" id="subjectCode" required>
            </div>
            <div class="col-3 mb-3">
                <label class="form-label">Units</label>
                <input type="number" min="1" class="form-control" name="units" id="subjectUnits" required>
            </div>
            <div class="col-4 mb-3">
                <label class="form-label">Year level</label>
                <select class="form-select" name="year_level" id="subjectYear" required>
                    <option value="">Select</option>
                    <?php for ($y = 1; $y <= 4; $y++): ?><option value="<?= $y ?>">Year <?= $y ?></option><?php endfor; ?>
                </select>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label">Name</label>
            <input class="form-control" name="subject_name" id="subjectName" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Description</label>
            <textarea class="form-control" name="subject_description" id="subjectDescription" rows="3"></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label">Tags</label>
            <?= renderTagPicker($allTags, []) ?>
            <div class="form-text">Tags decide which teachers fit this subject. A subject with no tags cannot be matched to a teacher automatically.</div>
        </div>
        <div class="mb-1 d-flex justify-content-between align-items-center">
            <label class="form-label mb-0">Weekly parts</label>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPartRow({})"><i class="bi bi-plus-lg"></i> Add part</button>
        </div>
        <div class="row g-2 small text-muted d-none" id="partHeader">
            <div class="col-md-3">Kind</div><div class="col-md-2">Hours per meeting</div><div class="col-md-2">Meetings per week</div><div class="col-md-2">Preferred room type</div><div class="col-md-2">Fallback</div>
        </div>
        <div id="partRows"></div>
        <div class="form-text">One row per kind of weekly class, for example a lecture and a lab. Zero hours means no weekly meeting (OJT, thesis), shown as TBA. Hours are per meeting, so a 3-unit lecture is usually 2 meetings of 1.5 hours. Guideline for a week: 1 lecture unit is about 1 hour and 1 lab unit about 3 hours. A meeting can be at most 5 hours. With no rows, the subject defaults to one lecture block in a Lecture Room, using its units as hours.</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="subjectSave">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
var roomTypeList = <?= json_encode($roomTypes, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
var partKinds = <?= json_encode($partKinds) ?>;
function typeOptions(selected, withNone) {
    var html = '<option value="">' + (withNone ? 'None' : 'Select') + '</option>';
    roomTypeList.forEach(function (t) {
        html += '<option value="' + t.room_type_id + '"' + (String(t.room_type_id) === String(selected) ? ' selected' : '') + '>' + String(t.type_name).replace(/</g, '&lt;') + '</option>';
    });
    return html;
}
function refreshPartHeader() {
    document.getElementById('partHeader').classList.toggle('d-none', document.querySelectorAll('#partRows .part-row').length === 0);
}
function addPartRow(p) {
    var row = document.createElement('div');
    row.className = 'row g-2 mb-2 part-row';
    var kinds = partKinds.map(function (k) { return '<option' + (p.kind === k ? ' selected' : '') + '>' + k + '</option>'; }).join('');
    row.innerHTML =
        '<div class="col-md-3"><select class="form-select" name="part_kind[]">' + kinds + '</select></div>' +
        '<div class="col-md-2"><input type="number" class="form-control" name="part_hours[]" min="0" max="5" step="0.5" value="' + (p.hours !== undefined ? p.hours : '') + '"></div>' +
        '<div class="col-md-2"><input type="number" class="form-control" name="part_meetings[]" min="1" max="6" value="' + (p.meetings || 1) + '"></div>' +
        '<div class="col-md-2"><select class="form-select" name="part_room[]">' + typeOptions(p.room, false) + '</select></div>' +
        '<div class="col-md-2"><select class="form-select" name="part_fallback[]">' + typeOptions(p.fallback, true) + '</select></div>' +
        '<div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100" aria-label="Remove part">&times;</button></div>';
    row.querySelector('button').addEventListener('click', function () { row.remove(); refreshPartHeader(); });
    document.getElementById('partRows').appendChild(row);
    refreshPartHeader();
}
function openSubjectModal(d) {
    var editing = !!d.subject_id;
    var picked = (d.tag_ids || []).map(String);
    document.querySelectorAll('.tag-check').forEach(function (cb) { cb.checked = picked.indexOf(cb.value) !== -1; });
    document.getElementById('newTags').value = d.new_tags || '';
    document.getElementById('partRows').innerHTML = '';
    (d.parts || []).forEach(addPartRow);
    refreshPartHeader();
    document.getElementById('subjectId').value = d.subject_id || '';
    document.getElementById('subjectCode').value = d.subject_code || '';
    document.getElementById('subjectName').value = d.subject_name || '';
    document.getElementById('subjectDescription').value = d.subject_description || '';
    document.getElementById('subjectUnits').value = d.units || '';
    document.getElementById('subjectYear').value = d.year_level || '';
    var note = document.getElementById('subjectUsageNote');
    note.textContent = d.usage_note || '';
    note.hidden = !d.usage_note;
    document.getElementById('subjectModalTitle').textContent = editing ? 'Edit Subject' : 'Add Subject';
    document.getElementById('subjectSave').textContent = editing ? 'Save Changes' : 'Add Subject';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('subjectModal')).show();
}
<?php if ($reopenForm): ?>
document.addEventListener('DOMContentLoaded', function () {
    openSubjectModal(<?= json_encode($form, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
<?php if ($dupNameWarning !== ''): ?>    document.getElementById('subjectSave').textContent = 'Save Anyway';
<?php endif; ?>});
<?php endif; ?>
</script>
</body>
</html>
