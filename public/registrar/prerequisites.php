<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $subjectId = $_POST['subject_id'] ?? '';
        $prereqId = $_POST['prerequisite_subject_id'] ?? '';

        if ($subjectId === '' || $prereqId === '') {
            $error = 'Both subjects are required.';
        } elseif ($subjectId === $prereqId) {
            $error = 'A subject cannot be its own prerequisite.';
        } elseif (prerequisiteWouldCreateCycle($pdo, (int)$subjectId, (int)$prereqId)) {
            $error = 'That would create a circular prerequisite chain.';
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO Prerequisite (subject_id, prerequisite_subject_id) VALUES (:sid, :pid)'
                )->execute(['sid' => $subjectId, 'pid' => $prereqId]);
                $message = 'Prerequisite added.';
            } catch (Exception $e) {
                $error = 'That prerequisite pair already exists.';
            }
        }
    } elseif ($action === 'delete') {
        $subjectId = $_POST['subject_id'] ?? '';
        $prereqId = $_POST['prerequisite_subject_id'] ?? '';
        $pdo->prepare('DELETE FROM Prerequisite WHERE subject_id = :sid AND prerequisite_subject_id = :pid')
            ->execute(['sid' => $subjectId, 'pid' => $prereqId]);
        $message = 'Prerequisite removed.';
    }
}

$subjects = $pdo->query('SELECT subject_id, subject_code, subject_name FROM Subject ORDER BY subject_code')->fetchAll();

$prerequisites = $pdo->query(
    'SELECT p.subject_id, p.prerequisite_subject_id,
            s.subject_code AS subject_code, s.subject_name AS subject_name,
            r.subject_code AS req_code, r.subject_name AS req_name
     FROM Prerequisite p
     JOIN Subject s ON s.subject_id = p.subject_id
     JOIN Subject r ON r.subject_id = p.prerequisite_subject_id
     ORDER BY s.subject_code'
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prerequisites</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Subject Prerequisites</h1>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post" class="card mb-4">
        <div class="card-body row align-items-end">
            <input type="hidden" name="action" value="add">
            <div class="col-md-5 mb-2">
                <label class="form-label">Subject</label>
                <select class="form-select" name="subject_id" required>
                    <option value="">Select</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= $s['subject_id'] ?>"><?= htmlspecialchars($s['subject_code'] . ' — ' . $s['subject_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5 mb-2">
                <label class="form-label">Requires (must be passed first)</label>
                <select class="form-select" name="prerequisite_subject_id" required>
                    <option value="">Select</option>
                    <?php foreach ($subjects as $s): ?>
                        <option value="<?= $s['subject_id'] ?>"><?= htmlspecialchars($s['subject_code'] . ' — ' . $s['subject_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <button type="submit" class="btn btn-primary w-100">Add</button>
            </div>
        </div>
    </form>

    <div class="table-responsive">
    <table class="table table-hover bg-white">
        <thead><tr><th>Subject</th><th>Requires</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($prerequisites as $p): ?>
            <tr>
                <td><?= htmlspecialchars($p['subject_code'] . ' — ' . $p['subject_name']) ?></td>
                <td><?= htmlspecialchars($p['req_code'] . ' — ' . $p['req_name']) ?></td>
                <td>
                    <form method="post" onsubmit="return confirm('Remove this prerequisite requirement?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="subject_id" value="<?= $p['subject_id'] ?>">
                        <input type="hidden" name="prerequisite_subject_id" value="<?= $p['prerequisite_subject_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($prerequisites)): ?><tr><td colspan="3" class="text-muted">No prerequisites defined yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
</body>
</html>