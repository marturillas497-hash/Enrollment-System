<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$error = '';
$message = '';
$editId = $_GET['edit'] ?? null;

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
            $editId = $id ?: null;
        } else {
            if ($id) {
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
        try {
            $pdo->prepare('DELETE FROM Subject WHERE subject_id = :id')->execute(['id' => $id]);
            $message = "Subject #$id deleted.";
        } catch (Exception $e) {
            $error = "Can't delete this subject — it's still referenced by a curriculum, offering, or prerequisite.";
        }
    }
}

$editing = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM Subject WHERE subject_id = :id');
    $stmt->execute(['id' => $editId]);
    $editing = $stmt->fetch();
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Subjects</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Manage Subjects</h1>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post" class="card mb-4">
        <div class="card-body">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="subject_id" value="<?= htmlspecialchars($editing['subject_id'] ?? '') ?>">
            <h2 class="h6"><?= $editing ? 'Edit Subject #' . $editing['subject_id'] : 'Add a Subject' ?></h2>
            <div class="row">
                <div class="col-md-2 mb-3">
                    <label class="form-label">Code</label>
                    <input class="form-control" name="subject_code" value="<?= htmlspecialchars($editing['subject_code'] ?? '') ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Name</label>
                    <input class="form-control" name="subject_name" value="<?= htmlspecialchars($editing['subject_name'] ?? '') ?>" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="subject_description" rows="3"><?= htmlspecialchars($editing['subject_description'] ?? '') ?></textarea>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">Units</label>
                    <input type="number" min="1" class="form-control" name="units" value="<?= htmlspecialchars($editing['units'] ?? '') ?>" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Add Subject' ?></button>
            <?php if ($editing): ?>
                <a href="/enrollment-system/public/registrar/subjects.php" class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

    <form method="get" class="d-flex mb-3 search-bar">
        <input type="text" class="form-control me-2" name="q" placeholder="Search by code, name, or description"
               value="<?= htmlspecialchars($search) ?>">
        <button type="submit" class="btn btn-outline-primary">Search</button>
        <?php if ($search !== ''): ?>
            <a href="/enrollment-system/public/registrar/subjects.php" class="btn btn-outline-secondary ms-2">Clear</a>
        <?php endif; ?>
    </form>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><th>Code</th><th>Name</th><th>Description</th><th>Units</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($subjects as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['subject_code']) ?></td>
                <td><?= htmlspecialchars($s['subject_name']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($s['subject_description'] ?? '') ?></td>
                <td><?= $s['units'] ?></td>
                <td>
                    <a href="?edit=<?= $s['subject_id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this subject?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="subject_id" value="<?= $s['subject_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($subjects)): ?>
            <tr><td colspan="5" class="text-muted"><?= $search !== '' ? 'No subjects match that search.' : 'No subjects yet.' ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>
