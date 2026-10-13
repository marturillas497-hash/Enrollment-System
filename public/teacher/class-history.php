<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/ui_helper.php';

$user = requireRole(['teacher']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT teacher_id FROM Teacher WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$teacherId = (int)$stmt->fetchColumn();
if ($teacherId === 0) {
    http_response_code(403);
    exit('No teacher profile is linked to this account.');
}

$e = fn($v): string => htmlspecialchars((string)$v);

/**
 * One student's outcome in a class. The grade alone decides Passed or Failed.
 * Dropped and Incomplete are manual remarks. Anything else is still ungraded.
 */
function chOutcome(?string $grade, ?string $remarks): string
{
    if ($grade !== null && $grade !== '') {
        return (float)$grade <= 3.0 ? 'Passed' : 'Failed';
    }
    if ($remarks === 'Dropped' || $remarks === 'Incomplete') {
        return $remarks;
    }
    return 'Ungraded';
}

/**
 * A class is the teacher's offerings for one subject, section and term.
 * Several meetings of the same class are folded into one entry.
 */
$stmt = $pdo->prepare(
    'SELECT co.offering_id, co.subject_id, co.section_id, co.term_id, co.room, co.day_of_week,
            co.start_time, co.end_time, sub.subject_code, sub.subject_name, sub.units,
            sec.section_name, sec.year_level, t.school_year, t.semester, t.status AS term_status
     FROM Class_Schedule co
     JOIN Subject sub ON sub.subject_id = co.subject_id
     JOIN Section sec ON sec.section_id = co.section_id
     JOIN School_term t ON t.term_id = co.term_id
     WHERE co.teacher_id = :tid
     ORDER BY t.school_year DESC, t.semester DESC, sub.subject_code, sec.section_name, co.offering_id'
);
$stmt->execute(['tid' => $teacherId]);

$classes = [];
$offeringKey = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = $r['term_id'] . '-' . $r['subject_id'] . '-' . $r['section_id'];
    $offeringKey[(int)$r['offering_id']] = $key;
    if (!isset($classes[$key])) {
        $classes[$key] = [
            'term_id' => (int)$r['term_id'], 'subject_id' => (int)$r['subject_id'], 'section_id' => (int)$r['section_id'],
            'school_year' => $r['school_year'], 'semester' => (int)$r['semester'], 'term_status' => $r['term_status'],
            'subject_code' => $r['subject_code'], 'subject_name' => $r['subject_name'], 'units' => (int)$r['units'],
            'section_name' => $r['section_name'], 'year_level' => (int)$r['year_level'],
            'meetings' => [], 'students' => [],
        ];
    }
    if ($r['day_of_week'] !== null && $r['day_of_week'] !== '') {
        $classes[$key]['meetings'][] = formatSchedule($r['day_of_week'], $r['start_time'], $r['end_time'], $r['room']);
    }
}

/**
 * Only approved enrollments count. A student appears once per class even when the class
 * has several meetings, and the row that carries a grade or remark wins.
 */
$stmt = $pdo->prepare(
    'SELECT es.offering_id, es.enrollment_id, es.grade, es.remarks,
            st.student_id_number, st.last_name, st.first_name, st.middle_name
     FROM Enrolled_subject es
     JOIN Enrollment e ON e.enrollment_id = es.enrollment_id AND e.status = \'approved\'
     JOIN Student st ON st.student_id = e.student_id
     JOIN Class_Offering co ON co.offering_id = es.offering_id
     WHERE co.teacher_id = :tid'
);
$stmt->execute(['tid' => $teacherId]);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $key = $offeringKey[(int)$r['offering_id']] ?? null;
    if ($key === null) {
        continue;
    }
    $eid = (int)$r['enrollment_id'];
    $existing = $classes[$key]['students'][$eid] ?? null;
    $hasMark = $r['grade'] !== null || $r['remarks'] !== null;
    if ($existing === null || ($hasMark && $existing['grade'] === null && $existing['remarks'] === null)) {
        $name = $r['last_name'] . ', ' . $r['first_name'] . ($r['middle_name'] ? ' ' . mb_substr($r['middle_name'], 0, 1) . '.' : '');
        $classes[$key]['students'][$eid] = [
            'id_number' => $r['student_id_number'], 'name' => $name,
            'grade' => $r['grade'], 'remarks' => $r['remarks'],
        ];
    }
}

foreach ($classes as &$c) {
    $counts = ['Passed' => 0, 'Failed' => 0, 'Incomplete' => 0, 'Dropped' => 0, 'Ungraded' => 0];
    $sum = 0.0;
    $graded = 0;
    foreach ($c['students'] as &$s) {
        $s['outcome'] = chOutcome($s['grade'], $s['remarks']);
        $counts[$s['outcome']]++;
        if ($s['grade'] !== null && $s['grade'] !== '') {
            $sum += (float)$s['grade'];
            $graded++;
        }
    }
    unset($s);
    uasort($c['students'], fn($a, $b) => strcasecmp($a['name'], $b['name']));
    $c['counts'] = $counts;
    $c['enrolled'] = count($c['students']);
    $c['average'] = $graded > 0 ? number_format($sum / $graded, 2) : null;
}
unset($c);

$subjectOptions = [];
foreach ($classes as $c) {
    $subjectOptions[$c['subject_id']] = $c['subject_code'] . ' - ' . $c['subject_name'];
}
asort($subjectOptions);

$filterSubject = (int)($_GET['subject'] ?? 0);
if ($filterSubject !== 0 && !isset($subjectOptions[$filterSubject])) {
    $filterSubject = 0;
}

$detail = null;
$detailMissing = false;
if (isset($_GET['term'], $_GET['subject_id'], $_GET['section'])) {
    $key = (int)$_GET['term'] . '-' . (int)$_GET['subject_id'] . '-' . (int)$_GET['section'];
    if (isset($classes[$key])) {
        $detail = $classes[$key];
    } else {
        $detailMissing = true;
    }
}

$byTerm = [];
foreach ($classes as $c) {
    if ($filterSubject !== 0 && $c['subject_id'] !== $filterSubject) {
        continue;
    }
    $byTerm[$c['term_id']]['info'] = $c;
    $byTerm[$c['term_id']]['classes'][] = $c;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Class History</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-1">Class History</h1>
    <p class="text-muted">Every class you have handled, newest first. This page is read only. Grades are entered on the Grades page while a term is ongoing.</p>

<?php if ($detailMissing): ?>
    <div class="alert alert-warning">That class was not found among your classes.</div>
<?php endif; ?>

<?php if ($detail): ?>
    <a href="<?= BASE_URL ?>/teacher/class-history.php<?= $filterSubject ? '?subject=' . $filterSubject : '' ?>" class="btn btn-outline-secondary btn-sm mb-3">Back to Class History</a>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5 mb-1"><?= $e($detail['subject_code']) ?> - <?= $e($detail['subject_name']) ?></h2>
            <div class="text-muted mb-2">
                <?= $e(sectionLabel($detail['year_level'], $detail['section_name'])) ?> ·
                SY <?= $e($detail['school_year']) ?>, <?= $detail['semester'] === 3 ? 'Summer' : 'Semester ' . $detail['semester'] ?> ·
                <?= statusBadge($detail['term_status']) ?>
            </div>
            <div class="mb-2">
                <?php if ($detail['meetings']): foreach ($detail['meetings'] as $m): ?>
                    <div><?= $e($m) ?></div>
                <?php endforeach; else: ?>
                    <div class="text-muted">No schedule set</div>
                <?php endif; ?>
            </div>
            <div>
                <strong><?= $detail['enrolled'] ?></strong> enrolled ·
                <?= $detail['counts']['Passed'] ?> passed ·
                <?= $detail['counts']['Failed'] ?> failed ·
                <?= $detail['counts']['Incomplete'] ?> incomplete ·
                <?= $detail['counts']['Dropped'] ?> dropped ·
                Average <strong><?= $detail['average'] ?? '-' ?></strong>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover bg-white">
            <thead><tr><th>Student ID</th><th>Name</th><th>Grade</th><th>Remark</th></tr></thead>
            <tbody>
            <?php foreach ($detail['students'] as $s): ?>
                <tr>
                    <td><?= $e($s['id_number']) ?></td>
                    <td><?= $e($s['name']) ?></td>
                    <td><?= $s['grade'] !== null ? number_format((float)$s['grade'], 2) : '-' ?></td>
                    <td><?= $s['outcome'] === 'Ungraded' ? '<span class="text-muted">Ungraded</span>' : statusBadge($s['outcome']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$detail['students']): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">No approved students in this class.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <form method="get" class="d-flex mb-3 search-bar">
        <select class="form-select me-2 w-auto" name="subject" aria-label="Subject">
            <option value="">All subjects</option>
            <?php foreach ($subjectOptions as $id => $label): ?>
                <option value="<?= $id ?>"<?= $filterSubject === $id ? ' selected' : '' ?>><?= $e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-outline-primary">Filter</button>
        <?php if ($filterSubject !== 0): ?>
            <a href="<?= BASE_URL ?>/teacher/class-history.php" class="btn btn-outline-secondary ms-2">Clear</a>
        <?php endif; ?>
    </form>

    <?php if (!$byTerm): ?>
        <div class="alert alert-info">No classes to show yet.</div>
    <?php endif; ?>

    <?php foreach ($byTerm as $group): $t = $group['info']; ?>
        <h2 class="h6 mt-4 mb-2">
            SY <?= $e($t['school_year']) ?>, <?= $t['semester'] === 3 ? 'Summer' : 'Semester ' . $t['semester'] ?>
            <?= statusBadge($t['term_status']) ?>
        </h2>
        <div class="table-responsive">
            <table class="table table-hover bg-white align-middle">
                <thead>
                    <tr>
                        <th>Subject</th><th>Section</th><th>Schedule</th><th class="text-center">Enrolled</th>
                        <th class="text-center">Passed</th><th class="text-center">Failed</th>
                        <th class="text-center">Incomplete</th><th class="text-center">Dropped</th>
                        <th class="text-center">Average</th><th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($group['classes'] as $c): ?>
                    <tr>
                        <td><strong><?= $e($c['subject_code']) ?></strong><br><small class="text-muted"><?= $e($c['subject_name']) ?></small></td>
                        <td><?= $e(sectionLabel($c['year_level'], $c['section_name'])) ?></td>
                        <td><small>
                            <?php if ($c['meetings']): foreach ($c['meetings'] as $m): ?><?= $e($m) ?><br><?php endforeach; else: ?><span class="text-muted">No schedule set</span><?php endif; ?>
                        </small></td>
                        <td class="text-center"><?= $c['enrolled'] ?><?php if ($c['counts']['Ungraded'] > 0): ?><br><small class="text-muted"><?= $c['counts']['Ungraded'] ?> ungraded</small><?php endif; ?></td>
                        <td class="text-center"><?= $c['counts']['Passed'] ?></td>
                        <td class="text-center"><?= $c['counts']['Failed'] ?></td>
                        <td class="text-center"><?= $c['counts']['Incomplete'] ?></td>
                        <td class="text-center"><?= $c['counts']['Dropped'] ?></td>
                        <td class="text-center"><?= $c['average'] ?? '-' ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="<?= BASE_URL ?>/teacher/class-history.php?term=<?= $c['term_id'] ?>&amp;subject_id=<?= $c['subject_id'] ?>&amp;section=<?= $c['section_id'] ?><?= $filterSubject ? '&amp;subject=' . $filterSubject : '' ?>">Grade sheet</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>
</body>
</html>
