<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['student']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Student WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$student = $stmt->fetch();

$currentTerm = $pdo->query("SELECT * FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC LIMIT 1")->fetch();

$stmt = $pdo->prepare(
    'SELECT e.*, st.school_year AS prev_school_year, st.semester AS prev_semester
     FROM Enrollment e JOIN School_term st ON st.term_id = e.term_id
     WHERE e.student_id = :sid ORDER BY e.enrollment_id DESC LIMIT 1'
);
$stmt->execute(['sid' => $student['student_id']]);
$previous = $stmt->fetch();

$error = '';
$result = null;

// Re-check every eligibility condition here too — the dashboard's checks are for display,
// this is what actually guards the write.
if (!$currentTerm) {
    $error = 'No term is currently open.';
} elseif (!$previous) {
    $error = 'No prior enrollment found — contact the registrar.';
} elseif ($previous['student_standing'] !== 'regular') {
    $error = 'Your standing is irregular — use the subject picker instead of automatic enrollment.';
} elseif ($student['overall_status'] !== 'active') {
    $error = 'Your account is not active for enrollment — contact the registrar.';
} else {
    $shiftCheck = $pdo->prepare("SELECT 1 FROM Program_shift_request WHERE student_id = :sid AND status = 'pending'");
    $shiftCheck->execute(['sid' => $student['student_id']]);
    if ($shiftCheck->fetch() !== false) {
        $error = 'You have a pending program shift request — the registrar will enroll you once it is evaluated.';
    }

    $check = $pdo->prepare('SELECT 1 FROM Enrollment WHERE student_id = :sid AND term_id = :tid');
    $check->execute(['sid' => $student['student_id'], 'tid' => $currentTerm['term_id']]);
    if ($error === '' && $check->fetch() !== false) {
        $error = 'You are already enrolled for this term.';
    }
}

// What this enrollment will contain. The same query feeds the preview and the save, so they can't disagree.
$preview = null;
if ($error === '') {
    // Year level advances only when crossing into semester 1 of a new school year.
    $yearLevel = (int)$previous['year_level'];
    if ($currentTerm['semester'] == 1 && $currentTerm['school_year'] !== $previous['prev_school_year']) {
        $yearLevel++;
    }

    $stmt = $pdo->prepare(
        'SELECT cs.subject_id, s.subject_code, s.subject_name, s.units, co.offering_id,
                co.day_of_week, co.start_time, co.end_time, co.room
         FROM Curriculum_subject cs
         JOIN Subject s ON s.subject_id = cs.subject_id
         LEFT JOIN Class_Offering co
                ON co.subject_id = cs.subject_id AND co.section_id = :section_id AND co.term_id = :term_id
         WHERE cs.curriculum_id = :curriculum_id AND cs.year_level = :year_level AND cs.semester = :semester
         ORDER BY s.subject_code, co.start_time'
    );
    $stmt->execute([
        'section_id' => $previous['section_id'], 'term_id' => $currentTerm['term_id'],
        'curriculum_id' => $previous['curriculum_id'], 'year_level' => $yearLevel,
        'semester' => $currentTerm['semester'],
    ]);
    $rows = $stmt->fetchAll();

    $sec = $pdo->prepare('SELECT section_name, year_level FROM Section WHERE section_id = :id');
    $sec->execute(['id' => $previous['section_id']]);
    $sec = $sec->fetch();

    $scheduled = [];
    $unscheduled = [];
    $units = 0.0;
    $counted = [];
    foreach ($rows as $r) {
        if ($r['offering_id'] !== null) {
            $scheduled[] = $r;
            if (!isset($counted[$r['subject_id']])) { $units += (float)$r['units']; $counted[$r['subject_id']] = true; }
        } elseif (!isset($counted[$r['subject_id']])) {
            $unscheduled[$r['subject_id']] = $r['subject_code'];
        }
    }
    $preview = [
        'year_level' => $yearLevel, 'scheduled' => $scheduled, 'unscheduled' => array_values($unscheduled),
        'units' => $units, 'section' => $sec ? sectionLabel($sec['year_level'], $sec['section_name']) : '—',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $preview !== null) {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            "INSERT INTO Enrollment (student_id, term_id, curriculum_id, section_id, year_level, date_enrolled, status, student_standing)
             VALUES (:student_id, :term_id, :curriculum_id, :section_id, :year_level, NOW(), 'approved', 'regular')"
        );
        $stmt->execute([
            'student_id' => $student['student_id'], 'term_id' => $currentTerm['term_id'],
            'curriculum_id' => $previous['curriculum_id'], 'section_id' => $previous['section_id'],
            'year_level' => $preview['year_level'],
        ]);
        $newEnrollmentId = (int)$pdo->lastInsertId();

        $insertOffering = $pdo->prepare('INSERT INTO Enrolled_subject (enrollment_id, offering_id) VALUES (:eid, :oid)');
        $enrolledCodes = [];
        foreach ($preview['scheduled'] as $m) {
            $insertOffering->execute(['eid' => $newEnrollmentId, 'oid' => $m['offering_id']]);
            $enrolledCodes[$m['subject_code']] = true;
        }

        $pdo->commit();
        $result = ['year_level' => $preview['year_level'], 'enrolled' => array_keys($enrolledCodes), 'missing' => $preview['unscheduled']];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = errorMessage($e, 'Enrollment failed.');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Enroll</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container container-sm">

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn btn-outline-secondary">Back to Dashboard</a>

    <?php elseif ($result): ?>
        <div class="alert alert-success">
            <h2 class="h5">Enrolled — Year <?= $result['year_level'] ?></h2>
            <p>Subjects enrolled: <?= htmlspecialchars(implode(', ', $result['enrolled']) ?: 'none') ?></p>
            <?php if (!empty($result['missing'])): ?>
                <p class="mb-0 text-warning">
                    Not scheduled this term (no class offering exists yet):
                    <?= htmlspecialchars(implode(', ', $result['missing'])) ?>. Contact the registrar.
                </p>
            <?php endif; ?>
        </div>
        <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn btn-primary">Back to Dashboard</a>

    <?php else: ?>
        <h1 class="h4 mb-1">Confirm Enrollment</h1>
        <p class="text-muted">
            <?= htmlspecialchars($currentTerm['school_year'] . ' — Semester ' . $currentTerm['semester']) ?>
            · Year <?= (int)$preview['year_level'] ?> · Section <?= htmlspecialchars($preview['section']) ?>
        </p>

        <div class="table-responsive">
        <table class="table bg-white">
            <thead><tr><th>Subject</th><th>Units</th><th>Schedule</th></tr></thead>
            <tbody>
            <?php foreach ($preview['scheduled'] as $m): ?>
                <tr>
                    <td><?= htmlspecialchars($m['subject_code'] . ' — ' . $m['subject_name']) ?></td>
                    <td><?= $m['units'] ?></td>
                    <td><?= htmlspecialchars(formatSchedule($m['day_of_week'], $m['start_time'], $m['end_time'], $m['room'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($preview['scheduled'])): ?><tr><td colspan="3" class="text-muted">No scheduled classes found for you yet.</td></tr><?php endif; ?>
            </tbody>
            <tfoot><tr><th>Total</th><th><?= $preview['units'] % 1 === 0 ? (int)$preview['units'] : number_format($preview['units'], 2) ?></th><th></th></tr></tfoot>
        </table>
        </div>

        <?php if (!empty($preview['unscheduled'])): ?>
            <div class="alert alert-warning">
                Not scheduled this term yet, so they won't be added: <?= htmlspecialchars(implode(', ', $preview['unscheduled'])) ?>.
                Contact the registrar after you enroll.
            </div>
        <?php endif; ?>

        <form method="post">
            <button type="submit" class="btn btn-primary w-100">Confirm Enroll</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>