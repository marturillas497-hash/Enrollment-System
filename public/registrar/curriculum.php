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

$programs = $pdo->prepare('SELECT program_id, program_code, program_name FROM Program WHERE department_id = :dept ORDER BY program_code');
$programs->execute(['dept' => $myDepartmentId]);
$programs = $programs->fetchAll();
$myProgramIds = array_column($programs, 'program_id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $programId = $_POST['program_id'] ?? '';
        $name = trim($_POST['curriculum_name'] ?? '');
        $year = $_POST['effective_year'] ?? '';
        $cloneFrom = $_POST['clone_from'] ?? ''; // curriculum_id to clone, or '' for blank

        if ($programId === '' || $name === '' || $year === '') {
            $error = 'Program, name, and effective year are required.';
        } elseif (!in_array((int)$programId, $myProgramIds, true)) {
            // Defense in depth: the dropdown only lists your own department's programs,
            // but a manipulated request could still try a different program_id.
            $error = 'That program is not in your department.';
        } else {
            try {
                $pdo->beginTransaction();

                // New curriculum becomes the active one; retire any other active curriculum for this program.
                $pdo->prepare('UPDATE Curriculum SET is_active = 0 WHERE program_id = :pid')
                    ->execute(['pid' => $programId]);

                $stmt = $pdo->prepare(
                    'INSERT INTO Curriculum (program_id, curriculum_name, effective_year, is_active)
                     VALUES (:pid, :name, :year, 1)'
                );
                $stmt->execute(['pid' => $programId, 'name' => $name, 'year' => $year]);
                $newCurriculumId = (int)$pdo->lastInsertId();

                if ($cloneFrom !== '') {
                    $pdo->prepare(
                        'INSERT INTO Curriculum_subject (curriculum_id, subject_id, year_level, semester)
                         SELECT :new_id, subject_id, year_level, semester FROM Curriculum_subject WHERE curriculum_id = :old_id'
                    )->execute(['new_id' => $newCurriculumId, 'old_id' => $cloneFrom]);
                }

                $pdo->commit();
                $message = 'Curriculum created' . ($cloneFrom !== '' ? ', cloned from the previous version.' : '.');
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Could not create curriculum. ' . $e->getMessage();
            }
        }
    } elseif ($action === 'remove_subject') {
        $curriculumId = $_POST['curriculum_id'] ?? '';
        $subjectId = $_POST['subject_id'] ?? '';
        $ownCheck = $pdo->prepare('SELECT 1 FROM Curriculum c JOIN Program p ON p.program_id = c.program_id WHERE c.curriculum_id = :id AND p.department_id = :dept');
        $ownCheck->execute(['id' => $curriculumId, 'dept' => $myDepartmentId]);
        if ($ownCheck->fetch() === false) {
            $error = 'That curriculum is not in your department.';
        } else {
            $pdo->prepare('DELETE FROM Curriculum_subject WHERE curriculum_id = :c AND subject_id = :s')
                ->execute(['c' => $curriculumId, 's' => $subjectId]);
            $message = 'Subject removed from curriculum.';
        }
    } elseif ($action === 'add_subject') {
        $curriculumId = $_POST['curriculum_id'] ?? '';
        $subjectId = $_POST['subject_id'] ?? '';
        $yearLevel = $_POST['year_level'] ?? '';
        $semester = $_POST['semester'] ?? '';

        $ownCheck = $pdo->prepare('SELECT 1 FROM Curriculum c JOIN Program p ON p.program_id = c.program_id WHERE c.curriculum_id = :id AND p.department_id = :dept');
        $ownCheck->execute(['id' => $curriculumId, 'dept' => $myDepartmentId]);

        if ($ownCheck->fetch() === false) {
            $error = 'That curriculum is not in your department.';
        } elseif ($subjectId === '' || $yearLevel === '' || $semester === '') {
            $error = 'Pick a subject, year level, and semester.';
        } else {
            try {
                $pdo->prepare(
                    'INSERT INTO Curriculum_subject (curriculum_id, subject_id, year_level, semester)
                     VALUES (:c, :s, :yl, :sem)'
                )->execute(['c' => $curriculumId, 's' => $subjectId, 'yl' => $yearLevel, 'sem' => $semester]);
                $message = 'Subject added to curriculum.';
            } catch (Exception $e) {
                $error = 'That subject is already in this curriculum.';
            }
        }
    }
}

// Expanded view: managing one curriculum's subject list
$expandId = $_GET['curriculum_id'] ?? ($_POST['curriculum_id'] ?? null);
$expanded = null;
$curriculumSubjects = [];
$allSubjects = [];
if ($expandId) {
    $stmt = $pdo->prepare(
        'SELECT c.*, p.program_code FROM Curriculum c JOIN Program p ON p.program_id = c.program_id
         WHERE c.curriculum_id = :id AND p.department_id = :dept'
    );
    $stmt->execute(['id' => $expandId, 'dept' => $myDepartmentId]);
    $expanded = $stmt->fetch();

    if ($expanded) {
        $stmt = $pdo->prepare(
            'SELECT cs.*, s.subject_code, s.subject_name, s.units
             FROM Curriculum_subject cs JOIN Subject s ON s.subject_id = cs.subject_id
             WHERE cs.curriculum_id = :id ORDER BY cs.year_level, cs.semester, s.subject_code'
        );
        $stmt->execute(['id' => $expandId]);
        $curriculumSubjects = $stmt->fetchAll();

        $allSubjects = $pdo->query('SELECT subject_id, subject_code, subject_name FROM Subject ORDER BY subject_code')->fetchAll();
    }
}

$curricula = $pdo->prepare(
    'SELECT c.*, p.program_code FROM Curriculum c JOIN Program p ON p.program_id = c.program_id
     WHERE p.department_id = :dept
     ORDER BY p.program_code, c.effective_year DESC'
);
$curricula->execute(['dept' => $myDepartmentId]);
$curricula = $curricula->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Curriculum</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Manage Curriculum</h1>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($expanded): ?>

        <h2 class="h5">
            <?= htmlspecialchars($expanded['curriculum_name']) ?>
            (<?= htmlspecialchars($expanded['program_code']) ?>, <?= $expanded['effective_year'] ?>)
            <?php if ($expanded['is_active']): ?><span class="badge bg-success">Active</span><?php endif; ?>
        </h2>

        <form method="post" class="card mb-3">
            <div class="card-body row align-items-end">
                <input type="hidden" name="action" value="add_subject">
                <input type="hidden" name="curriculum_id" value="<?= $expanded['curriculum_id'] ?>">
                <div class="col-md-5 mb-2">
                    <label class="form-label">Subject</label>
                    <select class="form-select" name="subject_id" required>
                        <option value="">Select a subject</option>
                        <?php foreach ($allSubjects as $s): ?>
                            <option value="<?= $s['subject_id'] ?>"><?= htmlspecialchars($s['subject_code'] . ' — ' . $s['subject_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <label class="form-label">Year Level</label>
                    <input type="number" min="1" max="5" class="form-control" name="year_level" required>
                </div>
                <div class="col-md-2 mb-2">
                    <label class="form-label">Semester</label>
                    <input type="number" min="1" max="3" class="form-control" name="semester" required>
                </div>
                <div class="col-md-2 mb-2">
                    <button type="submit" class="btn btn-primary w-100">Add</button>
                </div>
            </div>
        </form>

        <?php
        // Group into [year_level][semester] => rows, so the display can show a
        // clear header per year/semester instead of repeating those two columns
        // on every single row.
        $grouped = [];
        foreach ($curriculumSubjects as $cs) {
            $grouped[$cs['year_level']][$cs['semester']][] = $cs;
        }
        ?>

        <?php if (empty($grouped)): ?>
            <div class="alert alert-secondary">No subjects assigned yet.</div>
        <?php endif; ?>

        <?php foreach ($grouped as $yearLevel => $semesters): ?>
            <?php foreach ($semesters as $semester => $rows): ?>
                <div class="card mb-3">
                    <div class="card-header fw-bold">
                        Year <?= htmlspecialchars($yearLevel) ?> — Semester <?= htmlspecialchars($semester) ?>
                    </div>
                    <div class="table-responsive">
                    <table class="table bg-white mb-0">
                        <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th></th></tr></thead>
                        <tbody>
                        <?php $semesterUnits = 0; ?>
                        <?php foreach ($rows as $cs): ?>
                            <?php $semesterUnits += (float)$cs['units']; ?>
                            <tr>
                                <td><?= htmlspecialchars($cs['subject_code']) ?></td>
                                <td><?= htmlspecialchars($cs['subject_name']) ?></td>
                                <td><?= $cs['units'] ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Remove this subject from the curriculum?')">
                                        <input type="hidden" name="action" value="remove_subject">
                                        <input type="hidden" name="curriculum_id" value="<?= $expanded['curriculum_id'] ?>">
                                        <input type="hidden" name="subject_id" value="<?= $cs['subject_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light fw-bold">
                                <td colspan="2" class="text-end">Total Units</td>
                                <td><?= rtrim(rtrim(number_format($semesterUnits, 2), '0'), '.') ?></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <a href="<?= BASE_URL ?>/registrar/curriculum.php">&larr; Back to all curricula</a>

    <?php else: ?>

        <form method="post" class="card mb-4">
            <div class="card-body">
                <input type="hidden" name="action" value="create">
                <h2 class="h6">Create a New Curriculum</h2>
                <p class="text-muted small">Creating a new curriculum for a program automatically retires that
                    program's current active one — only one curriculum per program is active at a time.</p>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Program</label>
                        <select class="form-select" name="program_id" id="program_id" required>
                            <option value="">Select a program</option>
                            <?php foreach ($programs as $p): ?>
                                <option value="<?= $p['program_id'] ?>"><?= htmlspecialchars($p['program_code']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 mb-3">
                        <label class="form-label">Curriculum Name</label>
                        <input class="form-control" name="curriculum_name" placeholder="e.g. BSIS Curriculum 2026" required>
                    </div>
                    <div class="col-md-2 mb-3">
                        <label class="form-label">Effective Year</label>
                        <input type="number" class="form-control" name="effective_year" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Starting Point</label>
                    <select class="form-select" name="clone_from">
                        <option value="">Start blank</option>
                        <?php foreach ($curricula as $c): ?>
                            <option value="<?= $c['curriculum_id'] ?>">
                                Clone from: <?= htmlspecialchars($c['program_code'] . ' — ' . $c['curriculum_name'] . ' (' . $c['effective_year'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Copies that curriculum's subject list into the new one — you can still add/remove after.</div>
                </div>
                <button type="submit" class="btn btn-primary">Create Curriculum</button>
            </div>
        </form>

        <div class="table-responsive">
<table class="table table-hover bg-white">
            <thead><tr><th>Program</th><th>Curriculum</th><th>Year</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($curricula as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['program_code']) ?></td>
                    <td><?= htmlspecialchars($c['curriculum_name']) ?></td>
                    <td><?= $c['effective_year'] ?></td>
                    <td><?= $c['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Retired</span>' ?></td>
                    <td>
                        <a href="?curriculum_id=<?= $c['curriculum_id'] ?>" class="btn btn-sm btn-outline-primary">Manage Subjects</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
</div>

    <?php endif; ?>
</div>
</body>
</html>
