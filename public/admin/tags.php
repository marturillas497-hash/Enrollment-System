<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/tag_helper.php';

$user = requireRole(['admin']);
$pdo = getDbConnection();

$error = '';
$message = '';
$reopen = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = ctype_digit((string)($_POST['tag_id'] ?? '')) ? (int)$_POST['tag_id'] : 0;

    if ($action === 'save_tag') {
        $name = normalizeTagName((string)($_POST['tag_name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 50) {
            $error = 'A tag name is required and can be at most 50 characters.';
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM Tag WHERE LOWER(tag_name) = LOWER(:n) AND tag_id <> :id');
            $dup->execute(['n' => $name, 'id' => $id]);
            if ($dup->fetch() !== false) {
                $error = 'A tag with that name already exists. Capitals do not make a new tag.';
            } else {
                try {
                    if ($id) {
                        $pdo->prepare('UPDATE Tag SET tag_name = :n WHERE tag_id = :id')->execute(['n' => $name, 'id' => $id]);
                        $message = 'Tag renamed everywhere it is used.';
                    } else {
                        $pdo->prepare('INSERT INTO Tag (tag_name) VALUES (:n)')->execute(['n' => $name]);
                        $message = 'Tag added.';
                    }
                } catch (Exception $e) {
                    $error = errorMessage($e, 'Could not save the tag.');
                }
            }
        }
        $reopen = $error !== '';
    } elseif ($action === 'merge_tag') {
        $into = ctype_digit((string)($_POST['into_tag_id'] ?? '')) ? (int)$_POST['into_tag_id'] : 0;
        $both = $pdo->prepare('SELECT COUNT(*) FROM Tag WHERE tag_id IN (:a, :b)');
        $both->execute(['a' => $id, 'b' => $into]);
        if ($id === 0 || $into === 0 || $id === $into || (int)$both->fetchColumn() !== 2) {
            $error = 'Pick two different tags to merge.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('INSERT IGNORE INTO Subject_tag (subject_id, tag_id) SELECT subject_id, :into FROM Subject_tag WHERE tag_id = :from')
                    ->execute(['into' => $into, 'from' => $id]);
                $pdo->prepare('INSERT IGNORE INTO Teacher_tag (teacher_id, tag_id) SELECT teacher_id, :into FROM Teacher_tag WHERE tag_id = :from')
                    ->execute(['into' => $into, 'from' => $id]);
                $pdo->prepare('DELETE FROM Tag WHERE tag_id = :from')->execute(['from' => $id]);
                $pdo->commit();
                $message = 'Tags merged. Every subject and teacher that had the old tag now has the other one.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = errorMessage($e, 'Could not merge the tags.');
            }
        }
    } elseif ($action === 'delete_tag') {
        try {
            $pdo->prepare('DELETE FROM Tag WHERE tag_id = :id')->execute(['id' => $id]);
            $message = 'Tag deleted. It was removed from every subject and teacher that had it.';
        } catch (Exception $e) {
            $error = errorMessage($e, 'Could not delete the tag.');
        }
    }
}

$tags = allTags($pdo);
$e = fn($v): string => htmlspecialchars((string)$v);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tags</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Tags</h1>
    <p class="text-muted small">Tags say what a subject is about and what a teacher can teach. A teacher fits a subject fully when they have all of its tags, partly when they have at least one. Registrars can also add new tags while editing a subject. Use Merge to join spellings such as "Algorithm" and "Algorithms".</p>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="6000"><?= $e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error && !$reopen): ?><div class="alert alert-danger"><?= $e($error) ?></div><?php endif; ?>

    <div class="mb-3"><button type="button" class="btn btn-primary" onclick="openTagModal({})"><i class="bi bi-plus-lg"></i> Add Tag</button></div>

    <div class="table-responsive">
    <table class="table table-hover bg-white align-middle">
        <thead><tr><th>Tag</th><th>Subjects</th><th>Teachers</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($tags as $t): ?>
            <tr>
                <td><?= $e($t['tag_name']) ?></td>
                <td><?= (int)$t['subject_count'] ?></td>
                <td><?= (int)$t['teacher_count'] ?></td>
                <td class="text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="openTagModal({tag_id: <?= (int)$t['tag_id'] ?>, tag_name: <?= $e(json_encode($t['tag_name'])) ?>})">Rename</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openMergeTag(<?= (int)$t['tag_id'] ?>, <?= $e(json_encode($t['tag_name'])) ?>)"<?= count($tags) < 2 ? ' disabled' : '' ?>>Merge</button>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="delete_tag">
                        <input type="hidden" name="tag_id" value="<?= (int)$t['tag_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" data-confirm-label="Delete" data-confirm-tone="danger"
                                data-confirm="<?= $e('Delete this tag? It will be removed from ' . (int)$t['subject_count'] . ' subject(s) and ' . (int)$t['teacher_count'] . ' teacher(s).') ?>">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$tags): ?><tr><td colspan="4" class="text-muted">No tags yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="modal fade" id="tagModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="save_tag">
      <input type="hidden" name="tag_id" id="tagId">
      <div class="modal-header">
        <h5 class="modal-title" id="tagModalTitle">Add Tag</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if ($reopen): ?><div class="alert alert-danger js-modal-alert"><?= $e($error) ?></div><?php endif; ?>
        <label class="form-label">Tag name</label>
        <input class="form-control" name="tag_name" id="tagName" maxlength="50" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Save</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade" id="mergeTagModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="merge_tag">
      <input type="hidden" name="tag_id" id="mergeFrom">
      <div class="modal-header">
        <h5 class="modal-title">Merge tag</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p>Every subject and teacher with <strong id="mergeName"></strong> will get the tag you pick below instead, and <strong id="mergeName2"></strong> will be deleted.</p>
        <label class="form-label">Keep this tag</label>
        <select class="form-select" name="into_tag_id" id="mergeInto" required>
            <?php foreach ($tags as $t): ?><option value="<?= (int)$t['tag_id'] ?>"><?= $e($t['tag_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary">Merge</button>
      </div>
    </form>
  </div>
</div>
<script>
function openTagModal(d) {
    document.getElementById('tagId').value = d.tag_id || '';
    document.getElementById('tagName').value = d.tag_name || '';
    document.getElementById('tagModalTitle').textContent = d.tag_id ? 'Rename Tag' : 'Add Tag';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('tagModal')).show();
}
function openMergeTag(id, name) {
    document.getElementById('mergeFrom').value = id;
    document.getElementById('mergeName').textContent = name;
    document.getElementById('mergeName2').textContent = name;
    var sel = document.getElementById('mergeInto');
    Array.prototype.forEach.call(sel.options, function (o) { o.hidden = o.disabled = (String(o.value) === String(id)); });
    for (var i = 0; i < sel.options.length; i++) { if (!sel.options[i].disabled) { sel.selectedIndex = i; break; } }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mergeTagModal')).show();
}
<?php if ($reopen): ?>
document.addEventListener('DOMContentLoaded', function () {
    openTagModal(<?= json_encode(['tag_id' => $_POST['tag_id'] ?? '', 'tag_name' => $_POST['tag_name'] ?? ''], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
});
<?php endif; ?>
</script>
</body>
</html>
