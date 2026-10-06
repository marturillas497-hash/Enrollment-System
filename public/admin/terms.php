<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['admin']);
$pdo = getDbConnection();

$error = '';
$message = '';

/** Pending selections block closing; ungraded subjects get marked Incomplete when it closes. */
function termCloseCounts(PDO $pdo, int $termId): array
{
    $stmt = $pdo->prepare(
        "SELECT
            (SELECT COUNT(*) FROM Enrollment WHERE term_id = :t1 AND status = 'pending') AS pending,
            (SELECT COUNT(*) FROM Enrolled_subject es JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
              WHERE e.term_id = :t2 AND e.status = 'approved' AND es.grade IS NULL
                AND (es.remarks IS NULL OR es.remarks = '')) AS ungraded"
    );
    $stmt->execute(['t1' => $termId, 't2' => $termId]);
    $row = $stmt->fetch();
    return ['pending' => (int)$row['pending'], 'ungraded' => (int)$row['ungraded']];
}

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
            $open = $pdo->query("SELECT school_year, semester FROM School_term WHERE status = 'ongoing' LIMIT 1")->fetch();
            if ($dup->fetch() !== false) {
                $error = "A term for $schoolYear, semester $semester already exists.";
            } elseif ($open) {
                $error = 'Close the ongoing term (' . $open['school_year'] . ', ' . semesterLabel($open['semester']) . ') before opening a new one.';
            } else {
                try {
                    $stmt = $pdo->prepare(
                        "INSERT INTO School_term (school_year, semester, status)
                         SELECT :sy, :sem, 'ongoing' FROM DUAL
                         WHERE NOT EXISTS (SELECT 1 FROM School_term WHERE status = 'ongoing')"
                    );
                    $stmt->execute(['sy' => $schoolYear, 'sem' => $semester]);
                    if ($stmt->rowCount() === 1) {
                        $message = 'Term opened.';
                    } else {
                        $error = 'Another term was opened at the same moment. Close it first.';
                    }
                } catch (Exception $e) {
                    $error = errorMessage($e, 'Could not open the term.');
                }
            }
        }
    } elseif ($action === 'close_term') {
        $termId = ctype_digit((string)($_POST['term_id'] ?? '')) ? (int)$_POST['term_id'] : 0;

        try {
            $pdo->beginTransaction();

            $lock = $pdo->prepare('SELECT status FROM School_term WHERE term_id = :id FOR UPDATE');
            $lock->execute(['id' => $termId]);
            $termStatus = $lock->fetchColumn();

            if ($termStatus !== 'ongoing') {
                $pdo->rollBack();
                $error = 'That term is not ongoing, so it cannot be closed.';
            } else {
                $counts = termCloseCounts($pdo, $termId);

                if ($counts['pending'] > 0) {
                    $pdo->rollBack();
                    $error = $counts['pending'] . ' pending enrollment' . ($counts['pending'] === 1 ? '' : 's')
                        . ' must be approved or rejected by the registrars before this term can close.';
                } else {
                    /*
                     * Ungraded subjects of approved enrollments become Incomplete so every later
                     * check sees them. Dropped stays a teacher decision made before close.
                     */
                    $stamp = $pdo->prepare(
                        "UPDATE Enrolled_subject es JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
                         SET es.remarks = 'Incomplete'
                         WHERE e.term_id = :t AND e.status = 'approved' AND es.grade IS NULL
                           AND (es.remarks IS NULL OR es.remarks = '')"
                    );
                    $stamp->execute(['t' => $termId]);
                    $stamped = $stamp->rowCount();

                    /*
                     * Standing: the grade is the only authority. Above 3.00 is a failure, Dropped
                     * is a failure, Incomplete means no final grade. An enrollment with no subjects
                     * at all (a shift approval the student never filled) stays irregular.
                     */
                    $stmt = $pdo->prepare(
                        "SELECT e.enrollment_id,
                                COUNT(es.enrolled_subject_id) AS subject_count,
                                SUM(CASE
                                    WHEN es.grade IS NOT NULL AND es.grade > 3.00 THEN 1
                                    WHEN es.grade IS NULL AND es.remarks = 'Dropped' THEN 1
                                    ELSE 0
                                END) AS failed_count,
                                SUM(CASE
                                    WHEN es.enrolled_subject_id IS NOT NULL AND es.grade IS NULL AND es.remarks = 'Incomplete' THEN 1
                                    ELSE 0
                                END) AS incomplete_count
                         FROM Enrollment e
                         LEFT JOIN Enrolled_subject es ON es.enrollment_id = e.enrollment_id
                         WHERE e.term_id = :term_id AND e.status = 'approved'
                         GROUP BY e.enrollment_id"
                    );
                    $stmt->execute(['term_id' => $termId]);
                    $enrollments = $stmt->fetchAll();

                    $updateStanding = $pdo->prepare(
                        'UPDATE Enrollment SET student_standing = :standing WHERE enrollment_id = :id'
                    );

                    $regularCount = 0;
                    $irregularCount = 0;
                    $emptyCount = 0;
                    foreach ($enrollments as $e) {
                        $empty = (int)$e['subject_count'] === 0;
                        $irregular = $empty || (int)$e['failed_count'] > 0 || (int)$e['incomplete_count'] > 0;
                        $updateStanding->execute(['standing' => $irregular ? 'irregular' : 'regular', 'id' => $e['enrollment_id']]);
                        $irregular ? $irregularCount++ : $regularCount++;
                        if ($empty) {
                            $emptyCount++;
                        }
                    }

                    $pdo->prepare(
                        "UPDATE School_term SET status = 'closed', closed_by = :by, date_closed = NOW() WHERE term_id = :id"
                    )->execute(['by' => $user['account_id'], 'id' => $termId]);

                    $pdo->commit();

                    $message = "Term #$termId closed. Standing: $regularCount regular, $irregularCount irregular.";
                    if ($stamped > 0) {
                        $message .= " $stamped ungraded subject" . ($stamped === 1 ? ' was' : 's were') . ' marked Incomplete.';
                    }
                    if ($emptyCount > 0) {
                        $message .= " $emptyCount enrollment" . ($emptyCount === 1 ? ' has' : 's have') . ' no subjects and stay irregular.';
                    }
                }
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $error = errorMessage($e, 'Could not close the term.');
        }
    }
}

$terms = $pdo->query('SELECT * FROM School_term ORDER BY term_id DESC')->fetchAll();
$ongoingTerms = array_values(array_filter($terms, fn($t) => $t['status'] === 'ongoing'));
$closeCounts = [];
foreach ($ongoingTerms as $t) {
    $closeCounts[(int)$t['term_id']] = termCloseCounts($pdo, (int)$t['term_id']);
}
$reopenForm = $error !== '' && ($_POST['action'] ?? '') === 'open_term';
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
    <?= termBanner($ongoingTerms, true) ?>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="12000"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($error && !$reopenForm): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="mb-3">
        <button type="button" class="btn btn-primary" onclick="openTermModal({})"<?= $ongoingTerms ? ' disabled title="Close the ongoing term first"' : '' ?>><i class="bi bi-plus-lg"></i> Open a Term</button>
        <?php if ($ongoingTerms): ?><span class="text-muted small ms-2">Close the ongoing term before opening another.</span><?php endif; ?>
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
            <?php if ($reopenForm): ?><div class="alert alert-danger js-modal-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <div class="mb-3">
                <label class="form-label">School Year</label>
                <input class="form-control" name="school_year" placeholder="2026-2027" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Semester</label>
                <select class="form-select" name="semester" required>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">Summer</option>
                </select>
            </div>
            <p class="text-muted small mb-0">Terms are shared by every department. Close the old term before opening a new one.</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary">Open Term</button>
          </div>
        </form>
      </div>
    </div>

    <div class="table-responsive">
    <table class="table table-hover bg-white align-middle">
        <thead><tr><th>#</th><th>School Year</th><th>Semester</th><th>Status</th><th>Closed</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($terms as $t): ?>
            <tr>
                <td><?= (int)$t['term_id'] ?></td>
                <td><?= htmlspecialchars($t['school_year']) ?></td>
                <td><?= semesterLabel($t['semester']) ?></td>
                <td><?= statusBadge($t['status']) ?></td>
                <td><?= htmlspecialchars($t['date_closed'] ?? '') ?></td>
                <td>
                    <?php if ($t['status'] === 'ongoing'): ?>
                        <?php
                            $c = $closeCounts[(int)$t['term_id']];
                            $confirmText = 'Close this term for every department? '
                                . $c['ungraded'] . ' ungraded subject' . ($c['ungraded'] === 1 ? '' : 's')
                                . ' will be marked Incomplete, every standing is recalculated and grade entry is locked. This cannot be undone.';
                        ?>
                        <form method="post">
                            <input type="hidden" name="action" value="close_term">
                            <input type="hidden" name="term_id" value="<?= (int)$t['term_id'] ?>">
                            <div class="small text-muted mb-1">
                                <?= $c['ungraded'] ?> ungraded subject<?= $c['ungraded'] === 1 ? '' : 's' ?>
                                &middot; <?= $c['pending'] ?> pending enrollment<?= $c['pending'] === 1 ? '' : 's' ?>
                            </div>
                            <?php if ($c['pending'] > 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger" disabled title="Registrars must approve or reject the pending enrollments first">Close Term</button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        data-confirm="<?= htmlspecialchars($confirmText) ?>"
                                        data-confirm-label="Close Term" data-confirm-tone="warning">Close Term</button>
                            <?php endif; ?>
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
        el.value = values[name];
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
