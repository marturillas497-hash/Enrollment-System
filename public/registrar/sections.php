<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$error = '';
$message = '';
$editId = $_GET['edit'] ?? null;

$programs = $pdo->prepare('SELECT program_id, program_code FROM Program WHERE department_id = :dept ORDER BY program_code');
$programs->execute(['dept' => $myDepartmentId]);
$programs = $programs->fetchAll();
$myProgramIds = array_column($programs, 'program_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = $_POST['section_id'] ?? '';
        $name = trim($_POST['section_name'] ?? '');
        $yearLevel = $_POST['year_level'] ?? '';
        $programId = $_POST['program_id'] ?? '';
        $maxSlots = $_POST['max_slots'] ?? '';

        if ($name === '' || $yearLevel === '' || $programId === '' || $maxSlots === '') {
            $error = 'All fields are required.';
            $editId = $id ?: null;
        } elseif (!in_array((int)$programId, $myProgramIds, true)) {
            $error = 'That program is not in your department.';
            $editId = $id ?: null;
        } else {
            // If editing, also confirm the section being edited already belongs to your department.
            if ($id) {
                $ownCheck = $pdo->prepare(
                    'SELECT 1 FROM Section sec JOIN Program p ON p.program_id = sec.program_id
                     WHERE sec.section_id = :id AND p.department_id = :dept'
                );
                $ownCheck->execute(['id' => $id, 'dept' => $myDepartmentId]);
                if ($ownCheck->fetch() === false) {
                    $error = 'That section is not in your department.';
                    $editId = null;
                }
            }

            if ($error === '') {
                // Prevent two sections with the same name at the same year level within
                // the same program — e.g. two different "1A" sections for BSIS Year 1.
                $dupCheck = $pdo->prepare(
                    'SELECT 1 FROM Section
                     WHERE program_id = :pid AND year_level = :yl AND LOWER(section_name) = LOWER(:name)
                       AND section_id != :id'
                );
                $dupCheck->execute(['pid' => $programId, 'yl' => $yearLevel, 'name' => $name, 'id' => $id ?: 0]);
                if ($dupCheck->fetch() !== false) {
                    $error = "A section named \"$name\" already exists at Year $yearLevel for this program.";
                    $editId = $id ?: null;
                }
            }

            if ($error === '') {
            if ($id) {
                $stmt = $pdo->prepare(
                    'UPDATE Section SET section_name=:name, year_level=:yl, program_id=:pid, max_slots=:slots
                     WHERE section_id=:id'
                );
                $stmt->execute(['name' => $name, 'yl' => $yearLevel, 'pid' => $programId, 'slots' => $maxSlots, 'id' => $id]);
                $message = "Section #$id updated.";
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO Section (section_name, year_level, program_id, max_slots)
                     VALUES (:name, :yl, :pid, :slots)'
                );
                $stmt->execute(['name' => $name, 'yl' => $yearLevel, 'pid' => $programId, 'slots' => $maxSlots]);
                $message = 'Section added.';
            }
            }
        }
    } elseif ($action === 'delete') {
        $id = $_POST['section_id'] ?? '';
        $ownCheck = $pdo->prepare(
            'SELECT 1 FROM Section sec JOIN Program p ON p.program_id = sec.program_id
             WHERE sec.section_id = :id AND p.department_id = :dept'
        );
        $ownCheck->execute(['id' => $id, 'dept' => $myDepartmentId]);
        if ($ownCheck->fetch() === false) {
            $error = 'That section is not in your department.';
        } else {
            try {
                $pdo->prepare('DELETE FROM Section WHERE section_id = :id')->execute(['id' => $id]);
                $message = "Section #$id deleted.";
            } catch (Exception $e) {
                $error = "Can't delete this section — it's still referenced by an enrollment or class offering.";
            }
        }
    }
}

$editing = null;
if ($editId) {
    $stmt = $pdo->prepare(
        'SELECT sec.* FROM Section sec JOIN Program p ON p.program_id = sec.program_id
         WHERE sec.section_id = :id AND p.department_id = :dept'
    );
    $stmt->execute(['id' => $editId, 'dept' => $myDepartmentId]);
    $editing = $stmt->fetch();
}

$sections = $pdo->prepare(
    'SELECT sec.*, p.program_code FROM Section sec JOIN Program p ON p.program_id = sec.program_id
     WHERE p.department_id = :dept
     ORDER BY p.program_code, sec.year_level, sec.section_name'
);
$sections->execute(['dept' => $myDepartmentId]);
$sections = $sections->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sections</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Sections</h1>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post" class="card mb-4">
        <div class="card-body">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="section_id" value="<?= htmlspecialchars($editing['section_id'] ?? '') ?>">
            <h2 class="h6"><?= $editing ? 'Edit Section #' . $editing['section_id'] : 'Add a Section' ?></h2>
            <div class="row align-items-end">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Program</label>
                    <select class="form-select" name="program_id" required>
                        <option value="">Select</option>
                        <?php foreach ($programs as $p): ?>
                            <option value="<?= $p['program_id'] ?>" <?= ($editing['program_id'] ?? '') == $p['program_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['program_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Section Name</label>
                    <input class="form-control" name="section_name" value="<?= htmlspecialchars($editing['section_name'] ?? '') ?>" placeholder="BSIS-1A" required>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">Year Level</label>
                    <input type="number" min="1" max="5" class="form-control" name="year_level" value="<?= htmlspecialchars($editing['year_level'] ?? '') ?>" required>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">Max Slots</label>
                    <input type="number" min="1" class="form-control" name="max_slots" value="<?= htmlspecialchars($editing['max_slots'] ?? '') ?>" required>
                </div>
                <div class="col-md-2 mb-3">
                    <button type="submit" class="btn btn-primary w-100"><?= $editing ? 'Save' : 'Add' ?></button>
                </div>
            </div>
            <?php if ($editing): ?>
                <a href="/enrollment-system/public/registrar/sections.php" class="d-inline-block mt-1">Cancel edit</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><th>Program</th><th>Section</th><th>Year</th><th>Max Slots</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sections as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['program_code']) ?></td>
                <td><?= htmlspecialchars($s['section_name']) ?></td>
                <td><?= $s['year_level'] ?></td>
                <td><?= $s['max_slots'] ?></td>
                <td>
                    <a href="?edit=<?= $s['section_id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this section?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="section_id" value="<?= $s['section_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($sections)): ?><tr><td colspan="5" class="text-muted">No sections yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>
