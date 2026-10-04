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

                /*
                 * The clone_from dropdown only ever lists this department's own
                 * curricula, but never trust a raw ID — re-check server-side, same
                 * principle as the ownCheck used by add_subject/remove_subject below.
                 */
                if ($cloneFrom !== '') {
                    $cloneCheck = $pdo->prepare(
                        'SELECT 1 FROM Curriculum c JOIN Program p ON p.program_id = c.program_id
                         WHERE c.curriculum_id = :id AND p.department_id = :dept'
                    );
                    $cloneCheck->execute(['id' => $cloneFrom, 'dept' => $myDepartmentId]);
                    if ($cloneCheck->fetch() === false) {
                        throw new Exception('The curriculum to clone from is not in your department.');
                    }
                }

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
                $error = errorMessage($e, 'Could not create curriculum.');
            }
        }
    } elseif ($action === 'reactivate') {
        $curriculumId = $_POST['curriculum_id'] ?? '';
        $ownCheck = $pdo->prepare(
            'SELECT c.program_id, c.is_active FROM Curriculum c JOIN Program p ON p.program_id = c.program_id
             WHERE c.curriculum_id = :id AND p.department_id = :dept'
        );
        $ownCheck->execute(['id' => $curriculumId, 'dept' => $myDepartmentId]);
        $target = $ownCheck->fetch();
        if ($target === false) {
            $error = 'That curriculum is not in your department.';
        } elseif ((int)$target['is_active'] === 1) {
            $error = 'That curriculum is already active.';
        } else {
            try {
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE Curriculum SET is_active = 0 WHERE program_id = :pid')->execute(['pid' => $target['program_id']]);
                $pdo->prepare('UPDATE Curriculum SET is_active = 1 WHERE curriculum_id = :id')->execute(['id' => $curriculumId]);
                $pdo->commit();
                $message = 'Curriculum reactivated. The previously active one is now retired.';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = errorMessage($e, 'Could not reactivate the curriculum.');
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
        $yearLevel = $_POST['year_level'] ?? '';
        $semester = $_POST['semester'] ?? '';
        $subjectIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['subject_ids'] ?? [])), fn($v) => $v > 0)));

        $ownCheck = $pdo->prepare('SELECT 1 FROM Curriculum c JOIN Program p ON p.program_id = c.program_id WHERE c.curriculum_id = :id AND p.department_id = :dept');
        $ownCheck->execute(['id' => $curriculumId, 'dept' => $myDepartmentId]);

        if ($ownCheck->fetch() === false) {
            $error = 'That curriculum is not in your department.';
        } elseif (!ctype_digit((string) $yearLevel) || (int) $yearLevel < 1 || (int) $yearLevel > 4
                  || !ctype_digit((string) $semester) || (int) $semester < 1 || (int) $semester > 3) {
            $error = 'Pick a valid year level and semester.';
        } elseif (empty($subjectIds)) {
            $error = 'Tick at least one subject.';
        } else {
            try {
                $in = implode(',', array_fill(0, count($subjectIds), '?'));
                $valid = $pdo->prepare("SELECT subject_id FROM Subject WHERE subject_id IN ($in)");
                $valid->execute($subjectIds);
                $validIds = array_map('intval', $valid->fetchAll(PDO::FETCH_COLUMN));

                $have = $pdo->prepare('SELECT subject_id FROM Curriculum_subject WHERE curriculum_id = ?');
                $have->execute([$curriculumId]);
                $haveIds = array_map('intval', $have->fetchAll(PDO::FETCH_COLUMN));

                $toAdd = array_values(array_diff($validIds, $haveIds));
                if (empty($toAdd)) {
                    $error = 'Those subjects are already in this curriculum.';
                } else {
                    $pdo->beginTransaction();
                    $ins = $pdo->prepare('INSERT INTO Curriculum_subject (curriculum_id, subject_id, year_level, semester) VALUES (?, ?, ?, ?)');
                    foreach ($toAdd as $sid) {
                        $ins->execute([$curriculumId, $sid, (int) $yearLevel, (int) $semester]);
                    }
                    $pdo->commit();
                    $skipped = count($subjectIds) - count($toAdd);
                    $message = count($toAdd) . ' subject(s) added to Year ' . (int) $yearLevel . ', Semester ' . (int) $semester . '.'
                        . ($skipped > 0 ? ' ' . $skipped . ' skipped (already in this curriculum).' : '');
                    $_GET['year'] = (string) (int) $yearLevel;
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = errorMessage($e, 'Could not add the subjects.');
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

        $allSubjects = $pdo->query('SELECT subject_id, subject_code, subject_name, units FROM Subject ORDER BY subject_id DESC')->fetchAll();
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

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="4000"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php $reopenSubject = $error !== '' && ($_POST['action'] ?? '') === 'add_subject'; ?>
    <?php if ($error && !$reopenSubject): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if ($expanded): ?>

        <h2 class="h5">
            <?= htmlspecialchars($expanded['curriculum_name']) ?>
            (<?= htmlspecialchars($expanded['program_code']) ?>, <?= $expanded['effective_year'] ?>)
            <?php if ($expanded['is_active']): ?><?= statusBadge('active') ?><?php endif; ?>
        </h2>

        <?php
        // Year tabs: only worth showing when the curriculum spans more than one year level.
        $yearKeys = array_values(array_unique(array_map('intval', array_column($curriculumSubjects, 'year_level'))));
        $selectedYear = (is_string($_GET['year'] ?? null) && ctype_digit($_GET['year']) && in_array((int) $_GET['year'], $yearKeys, true))
            ? (int) $_GET['year'] : ($yearKeys[0] ?? null);
        ?>
        <?php
        $placed = [];
        foreach ($curriculumSubjects as $cs) {
            $placed[(int) $cs['subject_id']] = 'Year ' . $cs['year_level'] . ' · Sem ' . $cs['semester'];
        }
        $postedIds = array_map('intval', (array) ($_POST['subject_ids'] ?? []));
        $defYear = $reopenSubject ? (int) ($_POST['year_level'] ?? 1) : (int) ($selectedYear ?? 1);
        $defSem = $reopenSubject ? (int) ($_POST['semester'] ?? 1) : 1;
        ?>
        <div class="mb-3">
            <button type="button" class="btn btn-primary" onclick="openCurriculumSubjectModal(<?= $defYear ?>)"><i class="bi bi-plus-lg"></i> Add Subjects</button>
        </div>
        <div class="modal fade" id="curriculumSubjectModal" tabindex="-1" aria-hidden="true">
          <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <form method="post" class="modal-content" id="curriculumSubjectForm">
              <input type="hidden" name="action" value="add_subject">
              <input type="hidden" name="curriculum_id" value="<?= $expanded['curriculum_id'] ?>">
              <div class="modal-header flex-wrap gap-2">
                <h5 class="modal-title">Add Subjects to Curriculum</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="row g-2 w-100">
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1" for="csYear">Year Level</label>
                        <select class="form-select" name="year_level" id="csYear">
                            <?php for ($y = 1; $y <= 4; $y++): ?><option value="<?= $y ?>"<?= $y === $defYear ? ' selected' : '' ?>>Year <?= $y ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1" for="csSem">Semester</label>
                        <select class="form-select" name="semester" id="csSem">
                            <?php for ($m = 1; $m <= 3; $m++): ?><option value="<?= $m ?>"<?= $m === $defSem ? ' selected' : '' ?>>Semester <?= $m ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label small mb-1" for="csSearch">Search</label>
                        <input type="search" class="form-control" id="csSearch" placeholder="Code or name" autocomplete="off">
                    </div>
                </div>
              </div>
              <div class="modal-body">
                <?php if ($reopenSubject): ?><div class="alert alert-danger js-modal-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <?php if (empty($allSubjects)): ?>
                    <p class="text-muted mb-0">No subjects exist yet. Add some on the Subjects page first.</p>
                <?php else: ?>
                <div class="list-group">
                    <?php foreach ($allSubjects as $s): ?>
                        <?php $sid = (int) $s['subject_id']; $isPlaced = isset($placed[$sid]); ?>
                        <label class="list-group-item d-flex align-items-center gap-2 cs-row<?= $isPlaced ? ' text-muted' : '' ?>" data-search="<?= htmlspecialchars(strtolower($s['subject_code'] . ' ' . $s['subject_name'])) ?>">
                            <input class="form-check-input mt-0" type="checkbox" name="subject_ids[]" value="<?= $sid ?>"<?= $isPlaced ? ' disabled' : '' ?><?= !$isPlaced && in_array($sid, $postedIds, true) ? ' checked' : '' ?>>
                            <span class="fw-semibold"><?= htmlspecialchars($s['subject_code']) ?></span>
                            <span class="flex-grow-1"><?= htmlspecialchars($s['subject_name']) ?></span>
                            <span class="small text-muted"><?= rtrim(rtrim(number_format((float) $s['units'], 2), '0'), '.') ?> units</span>
                            <?php if ($isPlaced): ?><span class="badge text-bg-secondary"><?= htmlspecialchars($placed[$sid]) ?></span><?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div id="csEmpty" class="text-muted small d-none mt-2">No subjects match your search.</div>
                <?php endif; ?>
              </div>
              <div class="modal-footer justify-content-between">
                <span class="text-muted small" id="csCount">0 selected</span>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="csSubmit" disabled>Add Selected</button>
                </div>
              </div>
            </form>
          </div>
        </div>

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

        <?php if (count($yearKeys) > 1): ?>
            <?php
            $yearTabs = [];
            foreach ($yearKeys as $yk) {
                $yearTabs[$yk] = [
                    'label' => 'Year ' . $yk,
                    'count' => count(array_filter($curriculumSubjects, fn($cs) => (int) $cs['year_level'] === $yk)),
                    'href'  => '?curriculum_id=' . (int) $expanded['curriculum_id'] . '&year=' . $yk,
                ];
            }
            ?>
            <?= tabBar($yearTabs, (string) $selectedYear) ?>
        <?php endif; ?>

        <?php foreach ($grouped as $yearLevel => $semesters): ?>
            <?php if ((int) $yearLevel !== $selectedYear) { continue; } ?>
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
                                    <form method="post">
                                        <input type="hidden" name="action" value="remove_subject">
                                        <input type="hidden" name="curriculum_id" value="<?= $expanded['curriculum_id'] ?>">
                                        <input type="hidden" name="subject_id" value="<?= $cs['subject_id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger"
                                                data-confirm="Remove this subject from the curriculum?" data-confirm-label="Remove" data-confirm-tone="danger">Remove</button>
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
                    <label class="form-label">Create from</label>
                    <select class="form-select" name="clone_from">
                        <option value="">Blank (empty curriculum)</option>
                        <?php foreach ($curricula as $c): ?>
                            <option value="<?= $c['curriculum_id'] ?>">
                                Copy of: <?= htmlspecialchars($c['program_code'] . ' — ' . $c['curriculum_name'] . ' (' . $c['effective_year'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">
                        <strong>Blank</strong> creates an empty curriculum that you fill in yourself.
                        <strong>Copy of</strong> brings over every subject, with its year and semester, so you only edit the differences.
                        Either way, the new curriculum becomes the active one and retires the current one for this program.
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"
                        data-confirm="The new curriculum becomes the active one for this program and the current one is retired. New students are placed into the active curriculum only, and a retired one can be reactivated from the list below. Continue?"
                        data-confirm-label="Create" data-confirm-tone="warning">Create Curriculum</button>
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
                    <td><?= statusBadge($c['is_active'] ? 'active' : 'retired') ?></td>
                    <td>
                        <a href="?curriculum_id=<?= $c['curriculum_id'] ?>" class="btn btn-sm btn-outline-primary">Manage Subjects</a>
                        <?php if (!$c['is_active']): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="reactivate">
                                <input type="hidden" name="curriculum_id" value="<?= $c['curriculum_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                        data-confirm="Make this the active curriculum for its program? The current active one will be retired, and new placements will use this one."
                                        data-confirm-label="Reactivate" data-confirm-tone="warning">Reactivate</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
</div>

    <?php endif; ?>
</div>
<script>
(function () {
    var modalEl = document.getElementById('curriculumSubjectModal');
    if (!modalEl) { return; }
    var form = document.getElementById('curriculumSubjectForm');
    var search = document.getElementById('csSearch');
    var rows = form.querySelectorAll('.cs-row');
    var count = document.getElementById('csCount');
    var empty = document.getElementById('csEmpty');
    var submit = document.getElementById('csSubmit');
    function refreshCount() {
        var n = form.querySelectorAll('input[name="subject_ids[]"]:checked').length;
        count.textContent = n + ' selected';
        submit.disabled = n === 0;
    }
    function filter() {
        var q = search.value.trim().toLowerCase(), shown = 0;
        rows.forEach(function (r) {
            var hit = r.getAttribute('data-search').indexOf(q) !== -1;
            r.classList.toggle('d-none', !hit);
            if (hit) { shown++; }
        });
        if (empty) { empty.classList.toggle('d-none', shown !== 0); }
    }
    search.addEventListener('input', filter);
    form.addEventListener('change', refreshCount);
    modalEl.addEventListener('shown.bs.modal', function () { search.focus(); });
    refreshCount();
    window.openCurriculumSubjectModal = function (year) {
        document.getElementById('csYear').value = year;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };
<?php if ($reopenSubject && $expanded): ?>
    document.addEventListener('DOMContentLoaded', function () {
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    });
<?php endif; ?>
})();
</script>
</body>
</html>