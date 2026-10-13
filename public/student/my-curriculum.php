<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/ui_helper.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';

$user = requireRole(['student']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT * FROM Student WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$student = $stmt->fetch();
if ($student === false) {
    http_response_code(403);
    exit('No student profile is linked to this account.');
}
$studentId = (int)$student['student_id'];
$e = fn($v): string => htmlspecialchars((string)$v);

$stmt = $pdo->prepare(
    "SELECT e.curriculum_id, c.curriculum_name, c.effective_year, c.program_id, p.program_code, p.program_name
     FROM Enrollment e
     JOIN Curriculum c ON c.curriculum_id = e.curriculum_id
     JOIN Program p ON p.program_id = c.program_id
     WHERE e.student_id = :sid AND e.status <> 'rejected'
     ORDER BY e.enrollment_id DESC LIMIT 1"
);
$stmt->execute(['sid' => $studentId]);
$curr = $stmt->fetch();

$byYear = [];
$outside = [];
$totalUnits = 0.0;
$doneUnits = 0.0;
$standing = 1;
$newerNote = false;

if ($curr) {
    $cid = (int)$curr['curriculum_id'];

    $stmt = $pdo->prepare(
        'SELECT cs.subject_id, cs.year_level, cs.semester, cs.required_standing, s.subject_code, s.subject_name, s.units
         FROM Curriculum_subject cs JOIN Subject s ON s.subject_id = cs.subject_id
         WHERE cs.curriculum_id = :cid ORDER BY cs.year_level, cs.semester, s.subject_code'
    );
    $stmt->execute(['cid' => $cid]);
    $subjects = $stmt->fetchAll();

    /* Attempts: one per enrollment and subject, since a subject with two meetings has two rows. */
    $stmt = $pdo->prepare(
        "SELECT es.enrollment_id, co.subject_id, es.grade, es.remarks, e.status AS enr_status, t.status AS term_status,
                t.school_year, t.semester, s.subject_code, s.subject_name, s.units
         FROM Enrolled_subject es
         JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         JOIN School_term t ON t.term_id = e.term_id
         JOIN Subject s ON s.subject_id = co.subject_id
         WHERE e.student_id = :sid AND e.status IN ('approved', 'pending')
         ORDER BY e.enrollment_id"
    );
    $stmt->execute(['sid' => $studentId]);
    $attempts = [];
    foreach ($stmt->fetchAll() as $r) {
        $sid = (int)$r['subject_id'];
        $eid = (int)$r['enrollment_id'];
        $hasMark = $r['grade'] !== null || $r['remarks'] !== null;
        $prev = $attempts[$sid][$eid] ?? null;
        if ($prev === null || ($hasMark && $prev['grade'] === null && $prev['remarks'] === null)) {
            $attempts[$sid][$eid] = $r;
        }
    }

    $outcomeOf = function (array $a): array {
        if ($a['grade'] !== null && $a['grade'] !== '') {
            $g = number_format((float)$a['grade'], 2);
            return (float)$a['grade'] <= 3.0 ? ['Passed', $g] : ['Failed', $g];
        }
        if ($a['remarks'] === 'Dropped' || $a['remarks'] === 'Incomplete') {
            return [$a['remarks'], null];
        }
        if ($a['enr_status'] === 'pending') {
            return ['Pending approval', null];
        }
        return $a['term_status'] === 'ongoing' ? ['Taking now', null] : ['Incomplete', null];
    };

    $credits = [];
    $stmt = $pdo->prepare(
        "SELECT sc.credited_subject_id AS subject_id, s.subject_code, s.subject_name, s.units
         FROM Shift_credit sc
         JOIN Program_shift_request r ON r.request_id = sc.request_id AND r.status = 'approved'
         JOIN Subject s ON s.subject_id = sc.credited_subject_id
         WHERE r.student_id = :sid1
         UNION
         SELECT tc.credited_subject_id, s.subject_code, s.subject_name, s.units
         FROM Transferee_credit tc JOIN Subject s ON s.subject_id = tc.credited_subject_id
         WHERE tc.student_id = :sid2 AND tc.previous_grade <= 3.00"
    );
    $stmt->execute(['sid1' => $studentId, 'sid2' => $studentId]);
    foreach ($stmt->fetchAll() as $r) {
        $credits[(int)$r['subject_id']] = $r;
    }

    $completed = completedSubjectSet($pdo, $studentId);
    $standing = studentStanding($pdo, $studentId, $cid, $completed);

    $stmt = $pdo->prepare(
        'SELECT cp.subject_id, cp.prerequisite_subject_id, r.subject_code
         FROM Curriculum_prerequisite cp JOIN Subject r ON r.subject_id = cp.prerequisite_subject_id
         WHERE cp.curriculum_id = :cid ORDER BY r.subject_code'
    );
    $stmt->execute(['cid' => $cid]);
    $prereqs = [];
    foreach ($stmt->fetchAll() as $r) {
        $prereqs[(int)$r['subject_id']][(int)$r['prerequisite_subject_id']] = $r['subject_code'];
    }

    $inCurriculum = [];
    foreach ($subjects as $s) {
        $sid = (int)$s['subject_id'];
        $inCurriculum[$sid] = true;
        $units = (float)$s['units'];
        $totalUnits += $units;

        $list = array_values($attempts[$sid] ?? []);
        $count = count($list);
        $row = [
            'code' => $s['subject_code'], 'name' => $s['subject_name'], 'units' => $units,
            'status' => 'Not yet taken', 'grade' => null, 'attempts' => $count, 'locks' => [],
        ];

        $passed = null;
        foreach ($list as $a) {
            [$o, $g] = $outcomeOf($a);
            if ($o === 'Passed') {
                $passed = $g;
            }
        }
        if ($passed !== null) {
            $row['status'] = 'Passed';
            $row['grade'] = $passed;
        } elseif (isset($completed[$sid])) {
            $row['status'] = 'Credited';
        } elseif ($count > 0) {
            [$row['status'], $row['grade']] = $outcomeOf($list[$count - 1]);
        } else {
            $missing = [];
            foreach ($prereqs[$sid] ?? [] as $rid => $code) {
                if (!isset($completed[$rid])) {
                    $missing[] = $code;
                }
            }
            if ($missing) {
                $row['locks'][] = 'Requires ' . implode(', ', $missing);
            }
            if ($s['required_standing'] !== null && $standing < (int)$s['required_standing']) {
                $row['locks'][] = standingLabel((int)$s['required_standing']);
            }
        }
        if (isset($completed[$sid])) {
            $doneUnits += $units;
        }
        $byYear[(int)$s['year_level']][(int)$s['semester']][] = $row;
    }

    /* Anything taken or credited that this curriculum does not list, for example after a program shift. */
    foreach ($attempts as $sid => $byEnrollment) {
        if (isset($inCurriculum[$sid])) {
            continue;
        }
        $list = array_values($byEnrollment);
        $last = $list[count($list) - 1];
        $passedGrade = null;
        foreach ($list as $a) {
            [$o, $g] = $outcomeOf($a);
            if ($o === 'Passed') {
                $passedGrade = $g;
            }
        }
        [$o, $g] = $outcomeOf($last);
        $outside[$sid] = [
            'code' => $last['subject_code'], 'name' => $last['subject_name'], 'units' => (float)$last['units'],
            'status' => $passedGrade !== null ? 'Passed' : $o, 'grade' => $passedGrade ?? $g, 'attempts' => count($list),
        ];
    }
    foreach ($credits as $sid => $r) {
        if (isset($inCurriculum[$sid]) || isset($outside[$sid])) {
            continue;
        }
        $outside[$sid] = [
            'code' => $r['subject_code'], 'name' => $r['subject_name'], 'units' => (float)$r['units'],
            'status' => 'Credited', 'grade' => null, 'attempts' => 0,
        ];
    }

    $stmt = $pdo->prepare('SELECT 1 FROM Curriculum WHERE program_id = :pid AND is_active = 1 AND effective_year > :yr LIMIT 1');
    $stmt->execute(['pid' => $curr['program_id'], 'yr' => $curr['effective_year']]);
    $newerNote = $stmt->fetch() !== false;
}

$badgeFor = function (string $status): string {
    return match ($status) {
        'Taking now' => statusBadge('ongoing', 'Taking now'),
        'Pending approval' => statusBadge('pending', 'Pending approval'),
        'Not yet taken' => statusBadge('closed', 'Not yet taken'),
        default => statusBadge($status),
    };
};
$fmt = fn(float $n): string => rtrim(rtrim(number_format($n, 2), '0'), '.');
$percent = $totalUnits > 0 ? (int)round($doneUnits / $totalUnits * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Curriculum</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">My Curriculum</h1>

<?php if (!$curr): ?>
    <div class="alert alert-info">You have no enrollment yet, so there is no curriculum to show.</div>
<?php else: ?>
    <div class="card mb-3">
        <div class="card-body">
            <h2 class="h5 mb-1"><?= $e($curr['curriculum_name']) ?></h2>
            <div class="text-muted mb-2"><?= $e($curr['program_code']) ?> - <?= $e($curr['program_name']) ?> · effective <?= $e($curr['effective_year']) ?></div>
            <?php if ($newerNote): ?>
                <div class="alert alert-secondary py-2 small">Newer students in your program follow a newer curriculum. You keep this one for your whole stay.</div>
            <?php endif; ?>
            <div class="d-flex justify-content-between small mb-1">
                <span><?= $fmt($doneUnits) ?> of <?= $fmt($totalUnits) ?> units completed</span>
                <span><?= $e(standingLabel($standing)) ?></span>
            </div>
            <div class="progress" role="progressbar" aria-valuenow="<?= $percent ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar" style="width: <?= $percent ?>%"><?= $percent ?>%</div>
            </div>
        </div>
    </div>

    <?php foreach ($byYear as $year => $semesters): ?>
        <h2 class="h6 mt-4 mb-2">Year <?= $year ?></h2>
        <?php foreach ($semesters as $sem => $rows): ?>
            <div class="small text-muted mb-1"><?= (int)$sem === 3 ? 'Summer' : 'Semester ' . (int)$sem ?></div>
            <div class="table-responsive mb-3">
                <table class="table table-sm bg-white align-middle mb-0">
                    <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Status</th><th>Grade</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><?= $e($r['code']) ?></td>
                            <td><?= $e($r['name']) ?></td>
                            <td><?= $fmt($r['units']) ?></td>
                            <td>
                                <?= $badgeFor($r['status']) ?>
                                <?php if ($r['attempts'] > 1): ?><span class="text-muted small">attempt <?= $r['attempts'] ?></span><?php endif; ?>
                                <?php foreach ($r['locks'] as $lock): ?><div class="small text-muted"><?= $e($lock) ?></div><?php endforeach; ?>
                            </td>
                            <td><?= $r['grade'] !== null ? $e($r['grade']) : '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if ($outside): ?>
        <h2 class="h6 mt-4 mb-2">Outside this curriculum</h2>
        <p class="small text-muted">Subjects you took or were credited for that this curriculum does not list, for example from an earlier program.</p>
        <div class="table-responsive mb-3">
            <table class="table table-sm bg-white align-middle mb-0">
                <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Status</th><th>Grade</th></tr></thead>
                <tbody>
                <?php foreach ($outside as $r): ?>
                    <tr>
                        <td><?= $e($r['code']) ?></td>
                        <td><?= $e($r['name']) ?></td>
                        <td><?= $fmt($r['units']) ?></td>
                        <td><?= $badgeFor($r['status']) ?><?php if ($r['attempts'] > 1): ?> <span class="text-muted small">attempt <?= $r['attempts'] ?></span><?php endif; ?></td>
                        <td><?= $r['grade'] !== null ? $e($r['grade']) : '-' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div>
</body>
</html>
