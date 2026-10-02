<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$error = '';
$message = '';
$showStandingLink = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'open_term') {
        $schoolYear = trim($_POST['school_year'] ?? '');
        $semester = $_POST['semester'] ?? '';

        if ($schoolYear === '' || $semester === '') {
            $error = 'School year and semester are required.';
        } elseif (!preg_match('/^\d{4}-\d{4}$/', $schoolYear) || !in_array($semester, ['1', '2', '3'], true)) {
            $error = 'Use the school year format 2026-2027 and a semester of 1, 2 or Summer.';
        } else {
            $dup = $pdo->prepare('SELECT 1 FROM School_term WHERE school_year = :sy AND semester = :sem');
            $dup->execute(['sy' => $schoolYear, 'sem' => $semester]);
            if ($dup->fetch() !== false) {
                $error = "A term for $schoolYear, semester $semester already exists.";
            } else {
                $others = $pdo->query("SELECT school_year, semester FROM School_term WHERE status = 'ongoing'")->fetchAll();
                $stmt = $pdo->prepare(
                    "INSERT INTO School_term (school_year, semester, status) VALUES (:sy, :sem, 'ongoing')"
                );
                $stmt->execute(['sy' => $schoolYear, 'sem' => $semester]);
                $message = 'Term opened.';
                if ($others) {
                    $names = implode(', ', array_map(fn($t) => $t['school_year'] . ' S' . $t['semester'], $others));
                    $message .= " Warning: $names is still ongoing. Students and shift requests only use the newest open term.";
                }
            }
        }
    } elseif ($action === 'close_term') {
        $termId = $_POST['term_id'] ?? '';

        $termCheck = $pdo->prepare("SELECT 1 FROM School_term WHERE term_id = :id AND status = 'ongoing'");
        $termCheck->execute(['id' => $termId]);
        if ($termCheck->fetch() === false) {
            $error = 'That term is not ongoing, so it cannot be closed.';
        } else {
        try {
            $pdo->beginTransaction();

            // A subject counts as failed/dropped if the teacher marked it so, or — if remarks
            // was left blank — if a posted grade is worse than 3.00 (the conventional passing line).
            // Explicit remarks always win over the numeric fallback.
            //
            // 'Incomplete' and truly-blank (nothing entered) are grouped into incomplete_count —
            // both mean "no final grade was posted" and must NOT be silently treated as passed.
            // Bugfix (Sept 2026): previously a row with remarks='Incomplete' matched neither the
            // failed_count nor the ungraded_count case, so it fell through and the enrollment was
            // marked 'regular' standing as if every subject had passed. Incomplete now forces
            // 'irregular' standing, same as a real failure, until it's resolved.
            $stmt = $pdo->prepare(
                "SELECT e.enrollment_id,
                        SUM(CASE
                            WHEN es.remarks IN ('Failed','Dropped') THEN 1
                            WHEN (es.remarks IS NULL OR es.remarks = '') AND es.grade IS NOT NULL AND es.grade > 3.00 THEN 1
                            ELSE 0
                        END) AS failed_count,
                        SUM(CASE
                            WHEN es.remarks = 'Incomplete' THEN 1
                            WHEN es.grade IS NULL AND (es.remarks IS NULL OR es.remarks = '') THEN 1
                            ELSE 0
                        END) AS incomplete_count
                 FROM Enrollment e
                 LEFT JOIN Enrolled_subject es ON es.enrollment_id = e.enrollment_id
                 WHERE e.term_id = :term_id
                 GROUP BY e.enrollment_id"
            );
            $stmt->execute(['term_id' => $termId]);
            $enrollments = $stmt->fetchAll();

            $updateStanding = $pdo->prepare(
                'UPDATE Enrollment SET student_standing = :standing WHERE enrollment_id = :id'
            );

            $regularCount = 0;
            $irregularCount = 0;
            $incompleteCount = 0;
            foreach ($enrollments as $e) {
                $standing = ($e['failed_count'] > 0 || $e['incomplete_count'] > 0) ? 'irregular' : 'regular';
                $updateStanding->execute(['standing' => $standing, 'id' => $e['enrollment_id']]);
                $standing === 'irregular' ? $irregularCount++ : $regularCount++;
                if ($e['incomplete_count'] > 0) {
                    $incompleteCount++;
                }
            }

            $pdo->prepare(
                "UPDATE School_term SET status = 'closed', closed_by = :by, date_closed = NOW() WHERE term_id = :id"
            )->execute(['by' => $user['account_id'], 'id' => $termId]);

            $pdo->commit();

            $message = "Term #$termId closed. Standing recalculated: $regularCount regular, $irregularCount irregular"
                . " ($incompleteCount with incomplete or ungraded subjects).";
            if ($incompleteCount > 0) {
                $message .= " Those enrollments were set to irregular pending resolution. Follow up with the relevant teachers.";
            }
            $showStandingLink = $irregularCount > 0;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = errorMessage($e, 'Could not close the term.');
        }
        }
    }
}

$terms = $pdo->query('SELECT * FROM School_term ORDER BY term_id DESC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>School Terms</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">School Terms</h1>
    <?= termBanner(array_values(array_filter($terms, fn($t) => $t['status'] === 'ongoing'))) ?>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="<?= $showStandingLink ? 15000 : 8000 ?>"><?= htmlspecialchars($message) ?><?php if ($showStandingLink): ?> <a href="<?= BASE_URL ?>/registrar/students.php?standing=irregular">View irregular students</a><?php endif; ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php $reopenForm = $error !== '' && ($_POST['action'] ?? '') === 'open_term'; ?>
    <?php if ($error && !$reopenForm): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php $ongoingTerms = array_values(array_filter($terms, fn($t) => $t['status'] === 'ongoing')); ?>
    <div class="mb-3">
        <button type="button" class="btn btn-primary" onclick="openTermModal({})"><i class="bi bi-plus-lg"></i> Open a Term</button>
    </div>

    <div class="modal fade" id="termModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog">
        <form method="post" class="modal-content" id="termForm">
          <input type="hidden" name="action" value="open_term">
          <div class="modal-header">
            <h5 class="modal-title">Open a Term</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?php if ($reopenForm): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <?php if ($ongoingTerms): ?>
                <div class="alert alert-warning">
                    <?= htmlspecialchars($ongoingTerms[0]['school_year'] . ' Semester ' . $ongoingTerms[0]['semester']) ?> is still ongoing.
                    If you open another term, students and shift requests will only see the newest one, and every department is affected.
                </div>
            <?php endif; ?>
            <div class="mb-3">
                <label class="form-label">School Year</label>
                <input class="form-control" name="school_year" placeholder="2025-2026" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Semester</label>
                <select class="form-select" name="semester" required>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">Summer (3)</option>
                </select>
            </div>
            <p class="text-muted small mb-0">Terms are shared by every department. Close the old term before opening a new one.</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary"><?= $ongoingTerms ? 'Open Anyway' : 'Open Term' ?></button>
          </div>
        </form>
      </div>
    </div>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><th>#</th><th>School Year</th><th>Semester</th><th>Status</th><th>Closed</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($terms as $t): ?>
            <tr>
                <td><?= $t['term_id'] ?></td>
                <td><?= htmlspecialchars($t['school_year']) ?></td>
                <td><?= $t['semester'] ?></td>
                <td>
                    <?= statusBadge($t['status']) ?>
                </td>
                <td><?= htmlspecialchars($t['date_closed'] ?? '') ?></td>
                <td>
                    <?php if ($t['status'] === 'ongoing'): ?>
                        <form method="post">
                            <input type="hidden" name="action" value="close_term">
                            <input type="hidden" name="term_id" value="<?= $t['term_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                    data-confirm="Close this term? Students will no longer be able to enroll in it."
                                    data-confirm-label="Close Term" data-confirm-tone="warning">Close Term</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($terms)): ?><tr><td colspan="6" class="text-muted">No terms yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>
<script>
function fillModalForm(form, values) {
    Object.keys(values).forEach(function (name) {
        var el = form.elements[name];
        if (!el || el.type === 'hidden') { return; }
        if (el.tomselect) { el.tomselect.setValue(values[name]); } else { el.value = values[name]; }
    });
}
function openTermModal(values) {
    fillModalForm(document.getElementById('termForm'), values);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('termModal')).show();
}
<?php if ($reopenForm): ?>
document.addEventListener('DOMContentLoaded', function () {
    openTermModal(<?= json_encode(array_intersect_key($_POST, array_flip(['school_year', 'semester'])), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
});
<?php endif; ?>
</script>
</body>
</html>