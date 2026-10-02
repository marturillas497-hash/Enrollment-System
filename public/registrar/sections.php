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
        } elseif (!in_array((int)$programId, $myProgramIds, true)) {
            $error = 'That program is not in your department.';
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
                }
            }

            if ($error === '') {
            try {
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
            } catch (Exception $e) {
                // Backstop for the DB-level uq_section_program_year_name constraint — the
                // dupCheck above catches this in the normal case, this catches a race
                // between two simultaneous saves, or a duplicate created by a direct DB edit.
                $error = "A section named \"$name\" already exists at Year $yearLevel for this program.";
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

$reopenForm = $error !== '' && ($_POST['action'] ?? '') === 'save';
$form = ['section_id' => '', 'section_name' => '', 'year_level' => '', 'program_id' => '', 'max_slots' => ''];
if ($reopenForm) {
    foreach ($form as $k => $_) { $form[$k] = (string)($_POST[$k] ?? ''); }
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sections</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Sections</h1>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="4000"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error && !$reopenForm): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="mb-3">
        <button type="button" class="btn btn-primary" onclick="openSectionModal({})"><i class="bi bi-plus-lg"></i> Add Section</button>
    </div>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><?php if (count($programs) !== 1): ?><th>Program</th><?php endif; ?><th>Section</th><th>Year</th><th>Max Slots</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($sections as $s): ?>
            <tr>
                <?php if (count($programs) !== 1): ?><td><?= htmlspecialchars($s['program_code']) ?></td><?php endif; ?>
                <td><?= htmlspecialchars($s['section_name']) ?></td>
                <td><?= $s['year_level'] ?></td>
                <td><?= $s['max_slots'] ?></td>
                <td>
                    <a href="<?= BASE_URL ?>/registrar/section-roster.php?id=<?= $s['section_id'] ?>" class="btn btn-sm btn-outline-secondary">Roster</a>
                    <button type="button" class="btn btn-sm btn-outline-primary"
                            data-section="<?= htmlspecialchars(json_encode(['section_id' => $s['section_id'], 'section_name' => $s['section_name'], 'year_level' => $s['year_level'], 'program_id' => $s['program_id'], 'max_slots' => $s['max_slots']]), ENT_QUOTES) ?>"
                            onclick="openSectionModal(JSON.parse(this.dataset.section))">Edit</button>
                    <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="section_id" value="<?= $s['section_id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                data-confirm="Delete this section?" data-confirm-label="Delete" data-confirm-tone="danger">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($sections)): ?><tr><td colspan="5" class="text-muted">No sections yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>

<div class="modal fade" id="sectionModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="section_id" id="sectionId">
      <div class="modal-header">
        <h5 class="modal-title" id="sectionModalTitle">Add Section</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <?php if ($reopenForm): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if (count($programs) === 1): ?>
            <input type="hidden" name="program_id" id="sectionProgram" value="<?= (int)$programs[0]['program_id'] ?>">
        <?php else: ?>
            <div class="mb-3">
                <label class="form-label">Program</label>
                <select class="form-select" name="program_id" id="sectionProgram" required>
                    <option value="">Select</option>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= $p['program_id'] ?>"><?= htmlspecialchars($p['program_code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        <div class="mb-3">
            <label class="form-label">Section Name</label>
            <input class="form-control" name="section_name" id="sectionName" placeholder="A" required>
        </div>
        <div class="row">
            <div class="col-6 mb-3">
                <label class="form-label">Year Level</label>
                <input type="number" min="1" max="5" class="form-control" name="year_level" id="sectionYear" required>
            </div>
            <div class="col-6 mb-3">
                <label class="form-label">Max Slots</label>
                <input type="number" min="1" class="form-control" name="max_slots" id="sectionSlots" required>
            </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary" id="sectionSave">Save</button>
      </div>
    </form>
  </div>
</div>

<script>
var singleProgram = <?= count($programs) === 1 ? 'true' : 'false' ?>;
function openSectionModal(d) {
    var editing = !!d.section_id;
    document.getElementById('sectionId').value = d.section_id || '';
    if (!singleProgram) { document.getElementById('sectionProgram').value = d.program_id || ''; }
    document.getElementById('sectionName').value = d.section_name || '';
    document.getElementById('sectionYear').value = d.year_level || '';
    document.getElementById('sectionSlots').value = d.max_slots || '';
    document.getElementById('sectionModalTitle').textContent = editing ? 'Edit Section' : 'Add Section';
    document.getElementById('sectionSave').textContent = editing ? 'Save Changes' : 'Add Section';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('sectionModal')).show();
}
<?php if ($reopenForm): ?>
document.addEventListener('DOMContentLoaded', function () { openSectionModal(<?= json_encode($form, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>); });
<?php endif; ?>
</script>
</body>
</html>