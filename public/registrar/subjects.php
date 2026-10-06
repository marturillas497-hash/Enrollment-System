<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

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
        'prerequisites' => $one('SELECT COUNT(*) FROM Prerequisite WHERE subject_id = :as_subject OR prerequisite_subject_id = :as_required'),
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = $_POST['subject_id'] ?? '';
        $code = trim($_POST['subject_code'] ?? '');
        $name = trim($_POST['subject_name'] ?? '');
        $description = trim($_POST['subject_description'] ?? '');
        $units = $_POST['units'] ?? '';

        if ($code === '' || $name === '' || $units === '' || !ctype_digit($units)) {
            $error = 'Subject code, name, and a numeric unit count are required.';
        } else {
            $codeCheck = $pdo->prepare('SELECT 1 FROM Subject WHERE LOWER(subject_code) = LOWER(:c) AND subject_id <> :id');
            $codeCheck->execute(['c' => $code, 'id' => $id ?: 0]);
            $nameCheck = $pdo->prepare('SELECT 1 FROM Subject WHERE LOWER(subject_name) = LOWER(:n) AND subject_id <> :id');
            $nameCheck->execute(['n' => $name, 'id' => $id ?: 0]);

            if ($codeCheck->fetch() !== false) {
                $error = "A subject with the code \"$code\" already exists.";
            } elseif (!isset($_POST['confirm_dup_name']) && $nameCheck->fetch() !== false) {
                $dupNameWarning = "A subject named \"$name\" already exists under a different code.";
            } elseif ($id) {
                $stmt = $pdo->prepare(
                    'UPDATE Subject SET subject_code=:code, subject_name=:name, subject_description=:desc, units=:units
                     WHERE subject_id=:id'
                );
                $stmt->execute(['code' => $code, 'name' => $name, 'desc' => $description ?: null, 'units' => $units, 'id' => $id]);
                $message = "Subject #$id updated.";
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO Subject (subject_code, subject_name, subject_description, units)
                     VALUES (:code, :name, :desc, :units)'
                );
                $stmt->execute(['code' => $code, 'name' => $name, 'desc' => $description ?: null, 'units' => $units]);
                $message = 'Subject added.';
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
$form = ['subject_id' => '', 'subject_code' => '', 'subject_name' => '', 'subject_description' => '', 'units' => ''];
if ($reopenForm) {
    foreach ($form as $k => $_) { $form[$k] = (string)($_POST[$k] ?? ''); }
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $stmt = $pdo->prepare(
        'SELECT * FROM Subject
         WHERE subject_code LIKE :q1 OR subject_name LIKE :q2 OR subject_description LIKE :q3
         ORDER BY subject_code'
    );
    $stmt->execute(['q1' => "%$search%", 'q2' => "%$search%", 'q3' => "%$search%"]);
    $subjects = $stmt->fetchAll();
} else {
    $subjects = $pdo->query('SELECT * FROM Subject ORDER BY subject_code')->fetchAll();
}
foreach ($subjects as $i => $s) {
    $subjects[$i]['usage'] = subjectUsage($pdo, (int)$s['subject_id']);
}
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
        <button type="submit" class="btn btn-outline-primary">Search</button>
        <?php if ($search !== ''): ?>
            <a href="<?= BASE_URL ?>/registrar/subjects.php" class="btn btn-outline-secondary ms-2">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><th>Code</th><th>Name</th><th>Description</th><th>Units</th><th>Used by</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($subjects as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['subject_code']) ?></td>
                <td><?= htmlspecialchars($s['subject_name']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($s['subject_description'] ?? '') ?></td>
                <td><?= $s['units'] ?></td>
                <td class="small"><?= $s['usage']['total'] > 0 ? htmlspecialchars($s['usage']['summary']) : '<span class="text-muted">Not used</span>' ?></td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            data-subject="<?= htmlspecialchars(json_encode(['subject_id' => $s['subject_id'], 'subject_code' => $s['subject_code'], 'subject_name' => $s['subject_name'], 'subject_description' => $s['subject_description'] ?? '', 'units' => $s['units'], 'usage_note' => $s['usage']['curricula'] > 0 ? 'Shared subject: used by ' . $s['usage']['summary'] . ' Any change to the code, name or units applies everywhere it is used.' : '']), ENT_QUOTES) ?>"
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
            <tr><td colspan="6" class="text-muted"><?= $search !== '' ? 'No subjects match that search.' : 'No subjects yet.' ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</div>

<div class="modal fade" id="subjectModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
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
            <div class="col-8 mb-3">
                <label class="form-label">Code</label>
                <input class="form-control" name="subject_code" id="subjectCode" required>
            </div>
            <div class="col-4 mb-3">
                <label class="form-label">Units</label>
                <input type="number" min="1" class="form-control" name="units" id="subjectUnits" required>
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
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="subjectSave">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
function openSubjectModal(d) {
    var editing = !!d.subject_id;
    document.getElementById('subjectId').value = d.subject_id || '';
    document.getElementById('subjectCode').value = d.subject_code || '';
    document.getElementById('subjectName').value = d.subject_name || '';
    document.getElementById('subjectDescription').value = d.subject_description || '';
    document.getElementById('subjectUnits').value = d.units || '';
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
