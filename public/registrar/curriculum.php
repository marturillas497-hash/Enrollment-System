<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';

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

function curriculumNameTaken(PDO $pdo, int $programId, string $name): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM Curriculum WHERE program_id = :pid AND LOWER(curriculum_name) = LOWER(:name)');
    $stmt->execute(['pid' => $programId, 'name' => $name]);
    return $stmt->fetch() !== false;
}

/** Students who follow a curriculum: any non-rejected enrollment on it. */
function curriculumFollowers(PDO $pdo, int $curriculumId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT st.student_id, st.student_id_number, st.last_name, st.first_name
         FROM Enrollment e JOIN Student st ON st.student_id = e.student_id
         WHERE e.curriculum_id = :cid AND e.status <> 'rejected'
         ORDER BY st.last_name, st.first_name"
    );
    $stmt->execute(['cid' => $curriculumId]);
    return $stmt->fetchAll();
}

/**
 * Students who already hold a record for a subject under this curriculum: a graded or ongoing
 * enrollment row, an approved shift credit into it, or a transferee credit while following it.
 */
function curriculumSubjectRecords(PDO $pdo, int $curriculumId, int $subjectId): array
{
    $stmt = $pdo->prepare(
        'SELECT st.student_id, st.student_id_number, st.last_name, st.first_name
         FROM Enrolled_subject es
         JOIN Enrollment e ON e.enrollment_id = es.enrollment_id AND e.curriculum_id = :c1
         JOIN Class_Offering co ON co.offering_id = es.offering_id AND co.subject_id = :s1
         JOIN Student st ON st.student_id = e.student_id
         UNION
         SELECT st.student_id, st.student_id_number, st.last_name, st.first_name
         FROM Shift_credit sc
         JOIN Program_shift_request r ON r.request_id = sc.request_id AND r.to_curriculum_id = :c2 AND r.status = \'approved\'
         JOIN Student st ON st.student_id = r.student_id
         WHERE sc.credited_subject_id = :s2
         UNION
         SELECT st.student_id, st.student_id_number, st.last_name, st.first_name
         FROM Transferee_credit tc
         JOIN Student st ON st.student_id = tc.student_id
         WHERE tc.credited_subject_id = :s3
           AND EXISTS (SELECT 1 FROM Enrollment e2 WHERE e2.student_id = tc.student_id AND e2.curriculum_id = :c3)'
    );
    $stmt->execute(['c1' => $curriculumId, 's1' => $subjectId, 'c2' => $curriculumId, 's2' => $subjectId, 's3' => $subjectId, 'c3' => $curriculumId]);
    return $stmt->fetchAll();
}

function curriculumStudentList(array $students, int $limit = 8): string
{
    $names = array_map(fn($s) => $s['last_name'] . ', ' . $s['first_name'] . ' (' . $s['student_id_number'] . ')', array_slice($students, 0, $limit));
    $more = count($students) - count($names);
    return implode('; ', $names) . ($more > 0 ? " and $more more" : '');
}

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
        } elseif (curriculumNameTaken($pdo, (int)$programId, $name)) {
            $error = 'This program already has a curriculum with that name. Use a different name.';
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

                // A new curriculum opens for placement. Other open curricula of the program stay open.
                $stmt = $pdo->prepare(
                    'INSERT INTO Curriculum (program_id, curriculum_name, effective_year, is_active)
                     VALUES (:pid, :name, :year, 1)'
                );
                $stmt->execute(['pid' => $programId, 'name' => $name, 'year' => $year]);
                $newCurriculumId = (int)$pdo->lastInsertId();

                if ($cloneFrom !== '') {
                    $pdo->prepare(
                        'INSERT INTO Curriculum_subject (curriculum_id, subject_id, year_level, semester, required_standing)
                         SELECT :new_id, subject_id, year_level, semester, required_standing FROM Curriculum_subject WHERE curriculum_id = :old_id'
                    )->execute(['new_id' => $newCurriculumId, 'old_id' => $cloneFrom]);
                    $pdo->prepare(
                        'INSERT INTO Curriculum_prerequisite (curriculum_id, subject_id, prerequisite_subject_id)
                         SELECT :new_id, subject_id, prerequisite_subject_id FROM Curriculum_prerequisite WHERE curriculum_id = :old_id'
                    )->execute(['new_id' => $newCurriculumId, 'old_id' => $cloneFrom]);
                }

                $pdo->commit();
                $message = 'Curriculum created' . ($cloneFrom !== '' ? ', cloned from the previous version.' : '.');
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
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
            $error = 'That curriculum is already open for placement.';
        } else {
            try {
                $pdo->prepare('UPDATE Curriculum SET is_active = 1 WHERE curriculum_id = :id')->execute(['id' => $curriculumId]);
                $message = 'Curriculum opened for new placements.';
            } catch (Exception $e) {
                $error = errorMessage($e, 'Could not open the curriculum.');
            }
        }
    } elseif ($action === 'retire') {
        $curriculumId = $_POST['curriculum_id'] ?? '';
        $ownCheck = $pdo->prepare(
            'SELECT c.is_active FROM Curriculum c JOIN Program p ON p.program_id = c.program_id
             WHERE c.curriculum_id = :id AND p.department_id = :dept'
        );
        $ownCheck->execute(['id' => $curriculumId, 'dept' => $myDepartmentId]);
        $target = $ownCheck->fetch();
        if ($target === false) {
            $error = 'That curriculum is not in your department.';
        } elseif ((int)$target['is_active'] !== 1) {
            $error = 'That curriculum is already retired.';
        } else {
            try {
                $pdo->prepare('UPDATE Curriculum SET is_active = 0 WHERE curriculum_id = :id')->execute(['id' => $curriculumId]);
                $message = 'Curriculum retired. Students already on it are not affected.';
            } catch (Exception $e) {
                $error = errorMessage($e, 'Could not retire the curriculum.');
            }
        }
    } elseif ($action === 'remove_subject') {
        $curriculumId = $_POST['curriculum_id'] ?? '';
        $subjectId = $_POST['subject_id'] ?? '';
        $ownCheck = $pdo->prepare('SELECT 1 FROM Curriculum c JOIN Program p ON p.program_id = c.program_id WHERE c.curriculum_id = :id AND p.department_id = :dept');
        $ownCheck->execute(['id' => $curriculumId, 'dept' => $myDepartmentId]);
        if ($ownCheck->fetch() === false) {
            $error = 'That curriculum is not in your department.';
        } elseif (!ctype_digit((string)$subjectId)) {
            $error = 'Pick a subject to remove.';
        } else {
            $holders = curriculumSubjectRecords($pdo, (int)$curriculumId, (int)$subjectId);
            if ($holders) {
                $error = 'This subject cannot be removed because ' . count($holders) . ' student(s) already have a record for it: '
                    . curriculumStudentList($holders) . '.';
            } else {
                try {
                    $pdo->beginTransaction();
                    $links = $pdo->prepare('SELECT COUNT(*) FROM Curriculum_prerequisite WHERE curriculum_id = :c AND (subject_id = :s1 OR prerequisite_subject_id = :s2)');
                    $links->execute(['c' => $curriculumId, 's1' => $subjectId, 's2' => $subjectId]);
                    $linkCount = (int)$links->fetchColumn();
                    $pdo->prepare('DELETE FROM Curriculum_subject WHERE curriculum_id = :c AND subject_id = :s')
                        ->execute(['c' => $curriculumId, 's' => $subjectId]);
                    $pdo->commit();
                    $message = 'Subject removed from curriculum.' . ($linkCount > 0 ? ' ' . $linkCount . ' prerequisite link(s) went with it.' : '');
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $error = errorMessage($e, 'Could not remove the subject.');
                }
            }
        }
    } elseif ($action === 'save_prereqs') {
        $curriculumId = $_POST['curriculum_id'] ?? '';
        $subjectId = $_POST['subject_id'] ?? '';
        $prereqIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['prereq_ids'] ?? [])), fn($v) => $v > 0)));
        $standingPost = $_POST['required_standing'] ?? '';

        $ownCheck = $pdo->prepare('SELECT 1 FROM Curriculum c JOIN Program p ON p.program_id = c.program_id WHERE c.curriculum_id = :id AND p.department_id = :dept');
        $ownCheck->execute(['id' => $curriculumId, 'dept' => $myDepartmentId]);

        if ($ownCheck->fetch() === false) {
            $error = 'That curriculum is not in your department.';
        } else {
            /* A prerequisite must sit in an earlier year or semester. Order within a year: Sem 1, Sem 2, Summer. */
            $placement = $pdo->prepare('SELECT subject_id, year_level, year_level * 10 + semester AS slot FROM Curriculum_subject WHERE curriculum_id = :c');
            $placement->execute(['c' => $curriculumId]);
            $slots = [];
            $years = [];
            foreach ($placement->fetchAll() as $row) {
                $slots[(int)$row['subject_id']] = (int)$row['slot'];
                $years[(int)$row['subject_id']] = (int)$row['year_level'];
            }
            $requiredStanding = null;

            if (!ctype_digit((string)$subjectId) || !isset($slots[(int)$subjectId])) {
                $error = 'That subject is not in this curriculum.';
            } else {
                $sid = (int)$subjectId;
                if ($standingPost !== '') {
                    $requiredStanding = (int)$standingPost;
                    if ($requiredStanding < 2 || $requiredStanding > 4) {
                        $error = 'Year standing must be 2nd, 3rd or 4th year.';
                    } elseif ($requiredStanding > $years[$sid]) {
                        $error = 'A subject cannot ask for a higher year standing than the year it is placed in.';
                    }
                }
                foreach ($error === '' ? $prereqIds : [] as $rid) {
                    if ($rid === $sid) {
                        $error = 'A subject cannot be its own prerequisite.';
                    } elseif (!isset($slots[$rid])) {
                        $error = 'Every prerequisite must be a subject of this curriculum.';
                    } elseif ($slots[$rid] >= $slots[$sid]) {
                        $error = 'A prerequisite must come in an earlier year or semester than the subject.';
                    } elseif (prerequisiteWouldCreateCycle($pdo, (int)$curriculumId, $sid, $rid)) {
                        $error = 'That would create a circular prerequisite chain.';
                    }
                    if ($error !== '') {
                        break;
                    }
                }
            }

            if ($error === '') {
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM Curriculum_prerequisite WHERE curriculum_id = :c AND subject_id = :s')
                        ->execute(['c' => $curriculumId, 's' => $sid]);
                    $pdo->prepare('UPDATE Curriculum_subject SET required_standing = :rs WHERE curriculum_id = :c AND subject_id = :s')
                        ->execute(['rs' => $requiredStanding, 'c' => $curriculumId, 's' => $sid]);
                    $ins = $pdo->prepare('INSERT INTO Curriculum_prerequisite (curriculum_id, subject_id, prerequisite_subject_id) VALUES (:c, :s, :r)');
                    foreach ($prereqIds as $rid) {
                        $ins->execute(['c' => $curriculumId, 's' => $sid, 'r' => $rid]);
                    }
                    $pdo->commit();
                    $message = 'Requirements saved. They apply to future subject choices only.';
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) { $pdo->rollBack(); }
                    $error = errorMessage($e, 'Could not save the prerequisites.');
                }
            }
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
$followers = [];
$recordedSubjectIds = [];
$prereqMap = [];
$dependentsMap = [];
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

        $allSubjects = $pdo->query('SELECT subject_id, subject_code, subject_name, units, year_level FROM Subject ORDER BY subject_id DESC')->fetchAll();

        $followers = curriculumFollowers($pdo, (int)$expandId);
        $stmt = $pdo->prepare(
            'SELECT DISTINCT co.subject_id FROM Enrolled_subject es
             JOIN Enrollment e ON e.enrollment_id = es.enrollment_id AND e.curriculum_id = :c1
             JOIN Class_Offering co ON co.offering_id = es.offering_id
             UNION
             SELECT sc.credited_subject_id FROM Shift_credit sc
             JOIN Program_shift_request r ON r.request_id = sc.request_id AND r.to_curriculum_id = :c2 AND r.status = \'approved\'
             UNION
             SELECT tc.credited_subject_id FROM Transferee_credit tc
             WHERE EXISTS (SELECT 1 FROM Enrollment e2 WHERE e2.student_id = tc.student_id AND e2.curriculum_id = :c3)'
        );
        $stmt->execute(['c1' => $expandId, 'c2' => $expandId, 'c3' => $expandId]);
        $recordedSubjectIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $codeById = [];
        foreach ($curriculumSubjects as $cs) {
            $codeById[(int)$cs['subject_id']] = $cs['subject_code'];
        }
        $stmt = $pdo->prepare('SELECT subject_id, prerequisite_subject_id FROM Curriculum_prerequisite WHERE curriculum_id = :id');
        $stmt->execute(['id' => $expandId]);
        foreach ($stmt->fetchAll() as $row) {
            $sid = (int)$row['subject_id'];
            $rid = (int)$row['prerequisite_subject_id'];
            $prereqMap[$sid][] = $rid;
            $dependentsMap[$rid][] = $codeById[$sid] ?? '';
        }
    }
}

$curricula = $pdo->prepare(
    "SELECT c.*, p.program_code,
            (SELECT COUNT(DISTINCT e.student_id) FROM Enrollment e WHERE e.curriculum_id = c.curriculum_id AND e.status <> 'rejected') AS follower_count,
            (SELECT COUNT(*) FROM Section sec WHERE sec.curriculum_id = c.curriculum_id) AS section_count
     FROM Curriculum c JOIN Program p ON p.program_id = c.program_id
     WHERE p.department_id = :dept
     ORDER BY p.program_code, c.effective_year DESC, c.curriculum_id DESC"
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
            <?= statusBadge($expanded['is_active'] ? 'active' : 'retired', $expanded['is_active'] ? 'Open for placement' : 'Retired') ?>
        </h2>
        <p class="text-muted small"><?= count($followers) ?> student(s) follow this curriculum. They keep it for good. Removing a subject here only affects future choices.</p>

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
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="csYear">Year Level</label>
                        <select class="form-select" name="year_level" id="csYear">
                            <?php for ($y = 1; $y <= 4; $y++): ?><option value="<?= $y ?>"<?= $y === $defYear ? ' selected' : '' ?>>Year <?= $y ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <label class="form-label small mb-1" for="csSem">Semester</label>
                        <select class="form-select" name="semester" id="csSem">
                            <?php for ($m = 1; $m <= 3; $m++): ?><option value="<?= $m ?>"<?= $m === $defSem ? ' selected' : '' ?>>Semester <?= $m ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label small mb-1" for="csUsual">Usually in</label>
                        <select class="form-select" id="csUsual">
                            <option value="">All years</option>
                            <?php for ($y = 1; $y <= 4; $y++): ?><option value="<?= $y ?>">Year <?= $y ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-5">
                        <label class="form-label small mb-1" for="csSearch">Search</label>
                        <input type="search" class="form-control" id="csSearch" placeholder="Code or name" autocomplete="off">
                    </div>
                </div>
              </div>
              <div class="modal-body">
                <?php if ($reopenSubject): ?><div class="alert alert-danger js-modal-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
                <div class="alert alert-warning small d-none sticky-top" id="csYearWarn"></div>
                <?php if (empty($allSubjects)): ?>
                    <p class="text-muted mb-0">No subjects exist yet. Add some on the Subjects page first.</p>
                <?php else: ?>
                <div class="list-group">
                    <?php foreach ($allSubjects as $s): ?>
                        <?php $sid = (int) $s['subject_id']; $isPlaced = isset($placed[$sid]); ?>
                        <label class="list-group-item d-flex align-items-center gap-2 cs-row<?= $isPlaced ? ' text-muted' : '' ?>" data-search="<?= htmlspecialchars(strtolower($s['subject_code'] . ' ' . $s['subject_name'])) ?>" data-year="<?= (int) $s['year_level'] ?>" data-code="<?= htmlspecialchars($s['subject_code']) ?>">
                            <input class="form-check-input mt-0" type="checkbox" name="subject_ids[]" value="<?= $sid ?>"<?= $isPlaced ? ' disabled' : '' ?><?= !$isPlaced && in_array($sid, $postedIds, true) ? ' checked' : '' ?>>
                            <span class="fw-semibold"><?= htmlspecialchars($s['subject_code']) ?></span>
                            <span class="flex-grow-1"><?= htmlspecialchars($s['subject_name']) ?></span>
                            <span class="small text-muted"><?= rtrim(rtrim(number_format((float) $s['units'], 2), '0'), '.') ?> units</span>
                            <span class="badge text-bg-light border">Year <?= (int) $s['year_level'] ?></span>
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
                        <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Requires</th><th></th></tr></thead>
                        <tbody>
                        <?php $semesterUnits = 0; ?>
                        <?php foreach ($rows as $cs): ?>
                            <?php $semesterUnits += (float)$cs['units']; ?>
                            <tr>
                                <td><?= htmlspecialchars($cs['subject_code']) ?></td>
                                <td><?= htmlspecialchars($cs['subject_name']) ?></td>
                                <td><?= $cs['units'] ?></td>
                                <?php $myPrereqs = $prereqMap[(int)$cs['subject_id']] ?? []; ?>
                                <td>
                                    <?= $myPrereqs ? htmlspecialchars(implode(', ', array_map(fn($r) => $codeById[$r] ?? '?', $myPrereqs))) : ($cs['required_standing'] ? '' : '<span class="text-muted">None</span>') ?>
                                    <?php if ($cs['required_standing']): ?><span class="badge text-bg-light border"><?= htmlspecialchars(standingLabel((int)$cs['required_standing'])) ?></span><?php endif; ?>
                                </td>
                                <td class="text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-primary"
                                            onclick="openPrereqModal(<?= (int)$cs['subject_id'] ?>)">Prerequisites</button>
                                    <?php $hasRecord = in_array((int)$cs['subject_id'], $recordedSubjectIds, true); ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="remove_subject">
                                        <input type="hidden" name="curriculum_id" value="<?= $expanded['curriculum_id'] ?>">
                                        <input type="hidden" name="subject_id" value="<?= $cs['subject_id'] ?>">
                                        <?php if ($hasRecord): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" disabled
                                                    title="Students already have a record for this subject">Remove</button>
                                        <?php else: ?>
                                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                                    data-confirm="<?php
                                                $warn = 'Remove this subject from the curriculum?';
                                                $own = array_map(fn($r) => $codeById[$r] ?? '?', $prereqMap[(int)$cs['subject_id']] ?? []);
                                                $dep = array_filter($dependentsMap[(int)$cs['subject_id']] ?? []);
                                                if ($own) { $warn .= ' It requires ' . implode(', ', $own) . '.'; }
                                                if ($dep) { $warn .= ' It is required by ' . implode(', ', $dep) . '.'; }
                                                if ($own || $dep) { $warn .= ' Those prerequisite links are removed too.'; }
                                                if ($followers) { $warn .= ' ' . count($followers) . ' student(s) follow this curriculum. They will no longer need this subject for their progress, year standing or graduation.'; }
                                                echo htmlspecialchars($warn);
                                            ?>"
                                                    data-confirm-label="Remove" data-confirm-tone="danger">Remove</button>
                                        <?php endif; ?>
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
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="modal fade" id="prereqModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable">
                <form method="post" class="modal-content">
                    <input type="hidden" name="action" value="save_prereqs">
                    <input type="hidden" name="curriculum_id" value="<?= (int)$expanded['curriculum_id'] ?>">
                    <input type="hidden" name="subject_id" id="prereqSubjectId">
                    <div class="modal-header">
                        <h5 class="modal-title" id="prereqTitle">Requirements</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-2">Tick every subject that must be completed first. Only subjects in an earlier year or semester of this curriculum are listed. Every ticked subject is required.
                            <?= count($followers) ?> student(s) follow this curriculum. Changes apply to future subject choices only.</p>
                        <div id="prereqList"></div>
                        <div id="prereqEmpty" class="text-muted d-none">No subject comes before this one in the curriculum.</div>
                        <hr>
                        <label class="form-label" for="prereqStanding">Year standing needed</label>
                        <select class="form-select" name="required_standing" id="prereqStanding"></select>
                        <div class="form-text">Standing means every subject of the earlier years is completed. It cannot be higher than the year this subject is placed in.</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
        <script>
        var prereqSubjects = <?= json_encode(array_map(fn($cs) => [
            'id' => (int)$cs['subject_id'], 'code' => $cs['subject_code'], 'name' => $cs['subject_name'],
            'year' => (int)$cs['year_level'], 'sem' => (int)$cs['semester'],
            'standing' => $cs['required_standing'] === null ? '' : (int)$cs['required_standing'],
        ], $curriculumSubjects), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var prereqCurrent = <?= json_encode((object)$prereqMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        function openPrereqModal(subjectId) {
            var me = prereqSubjects.filter(function (s) { return s.id === subjectId; })[0];
            if (!me) { return; }
            var mySlot = me.year * 10 + me.sem;
            var have = (prereqCurrent[subjectId] || []).map(Number);
            var list = document.getElementById('prereqList');
            list.innerHTML = '';
            var shown = 0;
            prereqSubjects.forEach(function (s) {
                if (s.year * 10 + s.sem >= mySlot) { return; }
                var wrap = document.createElement('div');
                wrap.className = 'form-check';
                var box = document.createElement('input');
                box.type = 'checkbox'; box.className = 'form-check-input'; box.name = 'prereq_ids[]';
                box.value = s.id; box.id = 'pr' + s.id; box.checked = have.indexOf(s.id) !== -1;
                var label = document.createElement('label');
                label.className = 'form-check-label'; label.htmlFor = box.id;
                label.textContent = s.code + ' ' + s.name + ' (Year ' + s.year + ', Sem ' + s.sem + ')';
                wrap.appendChild(box); wrap.appendChild(label); list.appendChild(wrap);
                shown++;
            });
            document.getElementById('prereqEmpty').classList.toggle('d-none', shown !== 0);
            var standing = document.getElementById('prereqStanding');
            standing.innerHTML = '';
            var none = document.createElement('option');
            none.value = ''; none.textContent = 'None';
            standing.appendChild(none);
            var names = { 2: '2nd year standing', 3: '3rd year standing', 4: '4th year standing' };
            for (var n = 2; n <= me.year; n++) {
                var op = document.createElement('option');
                op.value = n; op.textContent = names[n];
                if (String(me.standing) === String(n)) { op.selected = true; }
                standing.appendChild(op);
            }
            standing.disabled = me.year < 2;
            document.getElementById('prereqSubjectId').value = subjectId;
            document.getElementById('prereqTitle').textContent = 'Requirements for ' + me.code;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('prereqModal')).show();
        }
        </script>

        <a href="<?= BASE_URL ?>/registrar/curriculum.php">&larr; Back to all curricula</a>

    <?php else: ?>

        <form method="post" class="card mb-4">
            <div class="card-body">
                <input type="hidden" name="action" value="create">
                <h2 class="h6">Create a New Curriculum</h2>
                <p class="text-muted small">A new curriculum opens for placement right away. A program may have several open
                    curricula at once. The registrar picks one at placement, and students never move to another curriculum.</p>
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
                        Either way, the new curriculum is open for placement and the others stay as they are.
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"
                        data-confirm="Create this curriculum and open it for new placements? Other open curricula of this program stay open."
                        data-confirm-label="Create" data-confirm-tone="warning">Create Curriculum</button>
            </div>
        </form>

        <div class="table-responsive">
<table class="table table-hover bg-white">
            <thead><tr><th>Program</th><th>Curriculum</th><th>Year</th><th>Status</th><th>Students</th><th>Sections</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($curricula as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['program_code']) ?></td>
                    <td><?= htmlspecialchars($c['curriculum_name']) ?></td>
                    <td><?= $c['effective_year'] ?></td>
                    <td><?= statusBadge($c['is_active'] ? 'active' : 'retired', $c['is_active'] ? 'Open' : 'Retired') ?></td>
                    <td><?= (int)$c['follower_count'] ?></td>
                    <td><?= (int)$c['section_count'] ?></td>
                    <td>
                        <a href="?curriculum_id=<?= $c['curriculum_id'] ?>" class="btn btn-sm btn-outline-primary">Manage Subjects</a>
                        <?php if (!$c['is_active']): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="reactivate">
                                <input type="hidden" name="curriculum_id" value="<?= $c['curriculum_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                        data-confirm="Open this curriculum for new placements, shifts and new sections? Other open curricula stay open."
                                        data-confirm-label="Open" data-confirm-tone="warning">Open</button>
                            </form>
                        <?php else: ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="retire">
                                <input type="hidden" name="curriculum_id" value="<?= $c['curriculum_id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                        data-confirm="Retire this curriculum? Students already on it are not affected. It only stops new placements, shifts and new sections."
                                        data-confirm-label="Retire" data-confirm-tone="warning">Retire</button>
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
    var yearSel = document.getElementById('csYear');
    var usual = document.getElementById('csUsual');
    var warn = document.getElementById('csYearWarn');
    function refreshCount() {
        var checked = form.querySelectorAll('input[name="subject_ids[]"]:checked');
        count.textContent = checked.length + ' selected';
        submit.disabled = checked.length === 0;
        var off = [];
        checked.forEach(function (c) {
            var r = c.closest('.cs-row');
            if (r.getAttribute('data-year') !== yearSel.value) {
                off.push(r.getAttribute('data-code') + ' (usually Year ' + r.getAttribute('data-year') + ')');
            }
        });
        warn.classList.toggle('d-none', off.length === 0);
        warn.textContent = off.length ? 'Adding to Year ' + yearSel.value + ': ' + off.join(', ') + '. You can still continue.' : '';
    }
    function filter() {
        var q = search.value.trim().toLowerCase(), u = usual.value, shown = 0;
        rows.forEach(function (r) {
            var hit = r.getAttribute('data-search').indexOf(q) !== -1 && (u === '' || r.getAttribute('data-year') === u);
            r.classList.toggle('d-none', !hit);
            if (hit) { shown++; }
        });
        if (empty) { empty.classList.toggle('d-none', shown !== 0); }
    }
    search.addEventListener('input', filter);
    usual.addEventListener('change', filter);
    form.addEventListener('change', refreshCount);
    modalEl.addEventListener('shown.bs.modal', function () { search.focus(); });
    refreshCount();
    window.openCurriculumSubjectModal = function (year) {
        document.getElementById('csYear').value = year;
        refreshCount();
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