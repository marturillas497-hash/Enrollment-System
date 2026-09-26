<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'open_term') {
        $schoolYear = trim($_POST['school_year'] ?? '');
        $semester = $_POST['semester'] ?? '';

        if ($schoolYear === '' || $semester === '') {
            $error = 'School year and semester are required.';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO School_term (school_year, semester, status) VALUES (:sy, :sem, 'ongoing')"
            );
            $stmt->execute(['sy' => $schoolYear, 'sem' => $semester]);
            $message = 'Term opened.';
        }
    } elseif ($action === 'close_term') {
        $termId = $_POST['term_id'] ?? '';

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

            $message = "Term #$termId closed. Standing recalculated: $regularCount regular, $irregularCount irregular.";
            if ($incompleteCount > 0) {
                $message .= " Note: $incompleteCount enrollment(s) had at least one incomplete or ungraded subject"
                    . " and were set to irregular standing pending resolution. Follow up with the relevant teachers.";
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Could not close the term. ' . $e->getMessage();
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

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="post" class="card mb-4">
        <div class="card-body row align-items-end">
            <input type="hidden" name="action" value="open_term">
            <div class="col-md-4 mb-2">
                <label class="form-label">School Year</label>
                <input class="form-control" name="school_year" placeholder="2025-2026" required>
            </div>
            <div class="col-md-3 mb-2">
                <label class="form-label">Semester</label>
                <select class="form-select" name="semester" required>
                    <option value="1">1</option>
                    <option value="2">2</option>
                    <option value="3">Summer (3)</option>
                </select>
            </div>
            <div class="col-md-3 mb-2">
                <button type="submit" class="btn btn-primary w-100">Open Term</button>
            </div>
        </div>
        <div class="card-body pt-0 text-muted small">
            The schema doesn't stop multiple terms from being "ongoing" at once — that's on you to manage.
            Close the old term before opening a new one, unless overlap is genuinely intended (e.g. summer
            running alongside a delayed previous semester).
        </div>
    </form>

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
                    <?= $t['status'] === 'ongoing'
                        ? '<span class="badge bg-success">Ongoing</span>'
                        : '<span class="badge bg-secondary">Closed</span>' ?>
                </td>
                <td><?= htmlspecialchars($t['date_closed'] ?? '') ?></td>
                <td>
                    <?php if ($t['status'] === 'ongoing'): ?>
                        <form method="post" onsubmit="return confirm('Close this term? Students will no longer be able to enroll in it.')">
                            <input type="hidden" name="action" value="close_term">
                            <input type="hidden" name="term_id" value="<?= $t['term_id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Close Term</button>
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
</body>
</html>