<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$studentId = $_GET['student_id'] ?? ($_POST['student_id'] ?? null);
$error = '';
$message = '';

// Scoped to MY department via the student's current program (latest enrollment's curriculum) —
// same pattern as students.php / irregular-enrollments.php.
$stmt = $pdo->prepare(
    'SELECT s.*, e.enrollment_id, e.term_id, e.section_id, e.curriculum_id, st.school_year, st.semester
     FROM Student s
     JOIN Enrollment e ON e.enrollment_id = (
         SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id
         ORDER BY e2.enrollment_id DESC LIMIT 1
     )
     JOIN School_term st ON st.term_id = e.term_id
     JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
     JOIN Program p ON p.program_id = c.program_id
     WHERE s.student_id = :id AND s.student_type = \'transferee\' AND p.department_id = :dept'
);
$stmt->execute(['id' => $studentId, 'dept' => $myDepartmentId]);
$student = $stmt->fetch();

if ($student === false) {
    require __DIR__ . '/../../includes/navbar.php';
    echo '<div class="container"><div class="alert alert-danger">'
        . 'Transferee student not found, not a transferee, or not in your department.</div></div>';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_credit') {
        // Upsert: one credit per curriculum subject. subject_id here is the
        // curriculum row being credited — the registrar picks the ROW, not a
        // subject from a big dropdown, so there's no chance of crediting the
        // wrong subject by mistake.
        $subjectId = $_POST['subject_id'] ?? '';
        $creditId = $_POST['credit_id'] ?? '';
        $previousSchool = trim($_POST['previous_school'] ?? '');
        $previousSubject = trim($_POST['previous_subject_description'] ?? '');
        $previousGrade = trim($_POST['previous_grade'] ?? '');

        // Confirm subject_id is actually part of THIS student's curriculum — the
        // pencil icon only ever sends a rendered curriculum row, but the handler
        // shouldn't trust that on its own, never trust a raw ID.
        $curriculumSubjectCheck = $pdo->prepare(
            'SELECT 1 FROM Curriculum_subject WHERE curriculum_id = :cid AND subject_id = :sid'
        );
        $curriculumSubjectCheck->execute(['cid' => $student['curriculum_id'], 'sid' => $subjectId]);
        $subjectInCurriculum = $subjectId !== '' && $curriculumSubjectCheck->fetch() !== false;

        if ($previousSchool === '' || $previousGrade === '' || $subjectId === '') {
            $error = 'Previous school and grade are required.';
        } elseif (!$subjectInCurriculum) {
            $error = 'That subject is not part of this student\'s curriculum.';
        } elseif (!is_numeric($previousGrade) || $previousGrade < 1.00 || $previousGrade > 5.00) {
            $error = 'Previous grade must be between 1.00 and 5.00.';
        } elseif ($creditId !== '') {
            $pdo->prepare(
                'UPDATE Transferee_credit SET previous_school = :school, previous_subject_description = :desc,
                    previous_grade = :grade WHERE credit_id = :id AND student_id = :sid'
            )->execute([
                'school' => $previousSchool, 'desc' => $previousSubject ?: null,
                'grade' => number_format((float)$previousGrade, 2, '.', ''),
                'id' => $creditId, 'sid' => $studentId,
            ]);
            $message = 'Credit updated.';
        } else {
            $pdo->prepare(
                'INSERT INTO Transferee_credit (student_id, previous_school, previous_subject_description, previous_grade, credited_subject_id)
                 VALUES (:sid, :school, :desc, :grade, :subject_id)'
            )->execute([
                'sid' => $studentId, 'school' => $previousSchool,
                'desc' => $previousSubject ?: null,
                'grade' => number_format((float)$previousGrade, 2, '.', ''),
                'subject_id' => $subjectId,
            ]);
            $message = 'Credit added.';
        }
    } elseif ($action === 'delete_credit') {
        $creditId = $_POST['credit_id'] ?? '';
        $pdo->prepare('DELETE FROM Transferee_credit WHERE credit_id = :id AND student_id = :sid')
            ->execute(['id' => $creditId, 'sid' => $studentId]);
        $message = 'Credit removed.';
    } elseif ($action === 'add_subject') {
        $offeringId = $_POST['offering_id'] ?? '';
        if ($offeringId === '') {
            $error = 'Select a subject to add.';
        } else {
            /*
             * Confirm the offering actually belongs to THIS student's own term/section
             * before anything else — never trust the raw offering_id. Only then
             * re-check prerequisites server-side (the disabled dropdown option is a
             * UI convenience, not the actual enforcement).
             */
            $subjectStmt = $pdo->prepare(
                'SELECT subject_id FROM Class_Offering
                 WHERE offering_id = :id AND term_id = :term_id AND section_id = :section_id'
            );
            $subjectStmt->execute([
                'id' => $offeringId, 'term_id' => $student['term_id'], 'section_id' => $student['section_id'],
            ]);
            $offeringSubjectId = $subjectStmt->fetchColumn();

            if (!$offeringSubjectId) {
                $error = 'That offering is not scheduled for this student\'s term/section.';
            } else {
                $missingPrereqs = [];
                $prereqStmt = $pdo->prepare(
                    'SELECT r.subject_id, r.subject_code
                     FROM Prerequisite p JOIN Subject r ON r.subject_id = p.prerequisite_subject_id
                     WHERE p.subject_id = :sid'
                );
                $prereqStmt->execute(['sid' => $offeringSubjectId]);
                foreach ($prereqStmt->fetchAll() as $req) {
                    if (!hasCompletedSubject($pdo, (int)$studentId, (int)$req['subject_id'])) {
                        $missingPrereqs[] = $req['subject_code'];
                    }
                }

                if ($missingPrereqs) {
                    $error = 'Missing prerequisite(s): ' . implode(', ', $missingPrereqs) . '.';
                } else {
                    try {
                        $pdo->prepare('INSERT INTO Enrolled_subject (enrollment_id, offering_id) VALUES (:eid, :oid)')
                            ->execute(['eid' => $student['enrollment_id'], 'oid' => $offeringId]);
                        $message = 'Subject added.';
                    } catch (Exception $e) {
                        $error = 'Could not add — this subject may already be on the roster.';
                    }
                }
            }
        }
    }
}

// Curriculum + program header info.
$stmt = $pdo->prepare(
    'SELECT c.curriculum_name, p.program_code, p.program_name
     FROM Curriculum c JOIN Program p ON p.program_id = c.program_id
     WHERE c.curriculum_id = :cid'
);
$stmt->execute(['cid' => $student['curriculum_id']]);
$curriculumInfo = $stmt->fetch();

// Every subject in the curriculum, grouped by year/semester, each carrying its
// existing credit (if any) via LEFT JOIN — this is the heart of the new view.
$stmt = $pdo->prepare(
    'SELECT cs.year_level, cs.semester, s.subject_id, s.subject_code, s.subject_name, s.units,
            tc.credit_id, tc.previous_school, tc.previous_subject_description, tc.previous_grade
     FROM Curriculum_subject cs
     JOIN Subject s ON s.subject_id = cs.subject_id
     LEFT JOIN Transferee_credit tc ON tc.credited_subject_id = cs.subject_id AND tc.student_id = :sid
     WHERE cs.curriculum_id = :cid
     ORDER BY cs.year_level, cs.semester, s.subject_code'
);
$stmt->execute(['sid' => $studentId, 'cid' => $student['curriculum_id']]);
$curriculumRows = $stmt->fetchAll();

$grouped = [];
foreach ($curriculumRows as $r) {
    $grouped[$r['year_level']][$r['semester']][] = $r;
}

// Progress, and one tab per year level (tabs only show when there is more than one year).
// The page opens on the first year that still has an uncredited subject, since that is
// where the registrar has work left to do.
$subjectTotal = count($curriculumRows);
$creditedTotal = 0;
$uncreditedByYear = [];
foreach ($curriculumRows as $r) {
    $yl = (int) $r['year_level'];
    $uncreditedByYear[$yl] = $uncreditedByYear[$yl] ?? 0;
    if ($r['credit_id'] !== null) { $creditedTotal++; } else { $uncreditedByYear[$yl]++; }
}
$yearKeys = array_keys($uncreditedByYear);
sort($yearKeys);
$selectedYear = $yearKeys[0] ?? null;
foreach ($yearKeys as $yk) {
    if ($uncreditedByYear[$yk] > 0) { $selectedYear = $yk; break; }
}
if (is_string($_GET['year'] ?? null) && ctype_digit($_GET['year']) && in_array((int) $_GET['year'], $yearKeys, true)) {
    $selectedYear = (int) $_GET['year'];
}

// Real, already-enrolled subjects (separate from credits) — kept for context.
$currentSubjects = $pdo->prepare(
    'SELECT sub.subject_code, sub.subject_name
     FROM Enrolled_subject es
     JOIN Class_Offering co ON co.offering_id = es.offering_id
     JOIN Subject sub ON sub.subject_id = co.subject_id
     WHERE es.enrollment_id = :eid'
);
$currentSubjects->execute(['eid' => $student['enrollment_id']]);
$currentSubjects = $currentSubjects->fetchAll();

// Credited subject IDs, so they're excluded from the "still needs to be taken" list below.
$creditedSubjectIds = [];
foreach ($curriculumRows as $r) {
    if ($r['credit_id'] !== null) {
        $creditedSubjectIds[] = $r['subject_id'];
    }
}

$sql = 'SELECT co.offering_id, sub.subject_id, sub.subject_code, sub.subject_name
        FROM Class_Offering co
        JOIN Subject sub ON sub.subject_id = co.subject_id
        WHERE co.term_id = :term_id AND co.section_id = :section_id
          AND co.offering_id NOT IN (SELECT offering_id FROM Enrolled_subject WHERE enrollment_id = :eid)';
$params = ['term_id' => $student['term_id'], 'section_id' => $student['section_id'], 'eid' => $student['enrollment_id']];
if (!empty($creditedSubjectIds)) {
    $placeholders = [];
    foreach ($creditedSubjectIds as $i => $subjectId) {
        $key = "credited$i";
        $placeholders[] = ":$key";
        $params[$key] = $subjectId;
    }
    $sql .= ' AND sub.subject_id NOT IN (' . implode(',', $placeholders) . ')';
}
$sql .= ' ORDER BY sub.subject_code';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$candidateOfferings = $stmt->fetchAll();

// Same prerequisite pattern as enroll-irregular.php / shift-add-subjects.php —
// attach missing prereqs (if any) so the dropdown below can grey it out.
$prereqStmt = $pdo->prepare(
    'SELECT r.subject_id, r.subject_code
     FROM Prerequisite p JOIN Subject r ON r.subject_id = p.prerequisite_subject_id
     WHERE p.subject_id = :sid'
);
$availableOfferings = [];
foreach ($candidateOfferings as $o) {
    $prereqStmt->execute(['sid' => $o['subject_id']]);
    $missing = [];
    foreach ($prereqStmt->fetchAll() as $req) {
        if (!hasCompletedSubject($pdo, (int)$studentId, (int)$req['subject_id'])) {
            $missing[] = $req['subject_code'];
        }
    }
    $o['missing_prereqs'] = $missing;
    $availableOfferings[] = $o;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transferee Credit Evaluation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container container-xl">
    <h1 class="h4 mb-1">
        <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?>
        (<?= htmlspecialchars($student['student_id_number']) ?>)
    </h1>
    <p class="text-muted mb-3">
        Transferee — <?= htmlspecialchars($student['school_year'] . ' — Semester ' . $student['semester']) ?>
    </p>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h5 mb-0"><?= htmlspecialchars($curriculumInfo['curriculum_name']) ?></h2>
            <p class="text-muted mb-0"><?= htmlspecialchars($curriculumInfo['program_code'] . ' — ' . $curriculumInfo['program_name']) ?></p>
            <div class="mt-3"><?= progressMeter($creditedTotal, $subjectTotal, 'subjects credited') ?></div>
        </div>
    </div>

    <?php if (count($yearKeys) > 1): ?>
        <?php
        $yearTabs = [];
        foreach ($yearKeys as $yk) {
            $yearTabs[$yk] = [
                'label' => 'Year ' . $yk,
                'count' => $uncreditedByYear[$yk],
                'href'  => '?student_id=' . (int) $studentId . '&year=' . $yk,
            ];
        }
        ?>
        <?= tabBar($yearTabs, (string) $selectedYear) ?>
        <p class="text-muted small mb-3">The number on each tab is how many subjects in that year are not credited yet.</p>
    <?php endif; ?>

    <?php foreach ($grouped as $yearLevel => $semesters): ?>
        <?php if ((int) $yearLevel !== $selectedYear) { continue; } ?>
        <?php foreach ($semesters as $semester => $rows): ?>
            <div class="card mb-3">
                <div class="card-header fw-bold">Year <?= htmlspecialchars($yearLevel) ?> — Semester <?= htmlspecialchars($semester) ?></div>
                <div class="table-responsive">
                <table class="table bg-white mb-0">
                    <thead><tr><th>Subject</th><th>Grade Credited</th><th>Remarks</th><th class="col-w-100"></th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $hasCredit = $r['credit_id'] !== null;
                        $passed = $hasCredit && (float)$r['previous_grade'] <= 3.00;
                        ?>
                        <tr class="<?= $hasCredit ? 'table-info' : '' ?>">
                            <td><?= htmlspecialchars($r['subject_code'] . ' — ' . $r['subject_name']) ?></td>
                            <td><?= $hasCredit ? htmlspecialchars($r['previous_grade']) : '<span class="text-muted">—</span>' ?></td>
                            <td>
                                <?php if ($hasCredit): ?>
                                    <?= statusBadge($passed ? 'passed' : 'failed') ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-primary"
                                        title="<?= $hasCredit ? 'Edit credit' : 'Credit this subject' ?>"
                                        onclick="openCreditModal('edit', <?= $r['subject_id'] ?>,
                                            '<?= htmlspecialchars(addslashes($r['subject_code'] . ' — ' . $r['subject_name'])) ?>',
                                            '<?= $r['credit_id'] ?? '' ?>',
                                            '<?= htmlspecialchars(addslashes($r['previous_school'] ?? '')) ?>',
                                            '<?= htmlspecialchars(addslashes($r['previous_subject_description'] ?? '')) ?>',
                                            '<?= $r['previous_grade'] ?? '' ?>')">
                                    <i class="bi bi-pencil-fill"></i>
                                </button>
                                <?php if ($hasCredit): ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" title="View credit"
                                            onclick="openCreditModal('view', <?= $r['subject_id'] ?>,
                                                '<?= htmlspecialchars(addslashes($r['subject_code'] . ' — ' . $r['subject_name'])) ?>',
                                                '<?= $r['credit_id'] ?>',
                                                '<?= htmlspecialchars(addslashes($r['previous_school'] ?? '')) ?>',
                                                '<?= htmlspecialchars(addslashes($r['previous_subject_description'] ?? '')) ?>',
                                                '<?= $r['previous_grade'] ?? '' ?>')">
                                        <i class="bi bi-eye-fill"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if (empty($grouped)): ?><div class="alert alert-secondary">This curriculum has no subjects assigned yet.</div><?php endif; ?>

    <div class="card mb-3">
        <div class="card-header">Subjects Actually Enrolled This Term</div>
        <div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Subject</th></tr></thead>
            <tbody>
            <?php foreach ($currentSubjects as $s): ?>
                <tr><td><?= htmlspecialchars($s['subject_code'] . ' — ' . $s['subject_name']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (empty($currentSubjects)): ?><tr><td class="text-muted">None yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <form method="post" class="card">
        <div class="card-body">
            <input type="hidden" name="action" value="add_subject">
            <input type="hidden" name="student_id" value="<?= $studentId ?>">
            <label class="form-label">Add a Remaining Subject (not credited, still needs to be taken)</label>
            <div class="d-flex gap-2">
                <select class="form-select" name="offering_id" required>
                    <option value="">Select</option>
                    <?php foreach ($availableOfferings as $o): ?>
                        <option value="<?= $o['offering_id'] ?>" <?= $o['missing_prereqs'] ? 'disabled' : '' ?>>
                            <?= htmlspecialchars($o['subject_code'] . ' — ' . $o['subject_name']) ?><?php if ($o['missing_prereqs']): ?>
                                (needs <?= htmlspecialchars(implode(', ', $o['missing_prereqs'])) ?>)
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary text-nowrap">Add Subject</button>
            </div>
            <div class="form-text">
                This list already excludes anything credited above, and anything already on the roster.
                Subjects with an unmet prerequisite (checked against MIST grades, shift credit, and
                transferee credit) are greyed out and re-checked server-side on submit.
            </div>
        </div>
    </form>
</div>

<!-- Shared modal for crediting/viewing a single curriculum subject -->
<div class="modal fade" id="creditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <input type="hidden" name="action" value="save_credit">
      <input type="hidden" name="student_id" value="<?= $studentId ?>">
      <input type="hidden" name="subject_id" id="creditSubjectId">
      <input type="hidden" name="credit_id" id="creditId">
      <div class="modal-header">
        <h5 class="modal-title" id="creditModalTitle">Credit Subject</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="fw-bold" id="creditSubjectLabel"></p>
        <div class="mb-3">
            <label class="form-label">Previous School</label>
            <input type="text" class="form-control" id="previous_school" name="previous_school">
        </div>
        <div class="mb-3">
            <label class="form-label">Subject Description (as it appeared there)</label>
            <input type="text" class="form-control" id="previous_subject_description" name="previous_subject_description" placeholder="e.g. Intro to Programming">
        </div>
        <div class="mb-3">
            <label class="form-label">Grade There</label>
            <input type="text" class="form-control" id="previous_grade" name="previous_grade" placeholder="1.00–5.00">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-danger me-auto" id="removeCreditBtn"
                onclick="document.getElementById('deleteCreditForm').submit();">Remove Credit</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="submit" class="btn btn-primary" id="saveCreditBtn">Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Separate plain form for the Remove Credit action, submitted via JS above -->
<form method="post" id="deleteCreditForm" class="d-none">
    <input type="hidden" name="action" value="delete_credit">
    <input type="hidden" name="student_id" value="<?= $studentId ?>">
    <input type="hidden" name="credit_id" id="deleteCreditId">
</form>

<script>
function openCreditModal(mode, subjectId, subjectLabel, creditId, school, description, grade) {
    document.getElementById('creditSubjectId').value = subjectId;
    document.getElementById('creditId').value = creditId;
    document.getElementById('creditSubjectLabel').textContent = subjectLabel;
    document.getElementById('previous_school').value = school;
    document.getElementById('previous_subject_description').value = description;
    document.getElementById('previous_grade').value = grade;
    document.getElementById('deleteCreditId').value = creditId;

    const isView = mode === 'view';
    document.getElementById('previous_school').disabled = isView;
    document.getElementById('previous_subject_description').disabled = isView;
    document.getElementById('previous_grade').disabled = isView;
    document.getElementById('saveCreditBtn').style.display = isView ? 'none' : 'inline-block';
    document.getElementById('removeCreditBtn').style.display = (isView || !creditId) ? 'none' : 'inline-block';
    document.getElementById('creditModalTitle').textContent = isView ? 'View Credit' : (creditId ? 'Edit Credit' : 'Credit Subject');

    new bootstrap.Modal(document.getElementById('creditModal')).show();
}
</script>
</body>
</html>