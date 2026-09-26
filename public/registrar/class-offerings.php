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

// Subjects are shared across every department (e.g. GE1 is taken by multiple programs) — not scoped.
$subjects = $pdo->query('SELECT subject_id, subject_code, subject_name FROM Subject ORDER BY subject_code')->fetchAll();

$teachers = $pdo->prepare('SELECT teacher_id, last_name, first_name FROM Teacher WHERE department_id = :dept ORDER BY last_name');
$teachers->execute(['dept' => $myDepartmentId]);
$teachers = $teachers->fetchAll();
$myTeacherIds = array_column($teachers, 'teacher_id');

$sections = $pdo->prepare(
    'SELECT sec.section_id, sec.section_name, p.program_code
     FROM Section sec JOIN Program p ON p.program_id = sec.program_id
     WHERE p.department_id = :dept
     ORDER BY p.program_code, sec.section_name'
);
$sections->execute(['dept' => $myDepartmentId]);
$sections = $sections->fetchAll();
$mySectionIds = array_column($sections, 'section_id');

$terms = $pdo->query("SELECT term_id, school_year, semester FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subjectId = $_POST['subject_id'] ?? '';
    $teacherId = $_POST['teacher_id'] ?? '';
    $sectionId = $_POST['section_id'] ?? '';
    $termId = $_POST['term_id'] ?? '';
    $room = trim($_POST['room'] ?? '');
    $day = $_POST['day_of_week'] ?? '';
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';

    if ($subjectId === '' || $teacherId === '' || $sectionId === '' || $termId === '' || $day === '' || $startTime === '' || $endTime === '') {
        $error = 'All fields except room are required.';
    } elseif ($startTime >= $endTime) {
        $error = 'Start time must be before end time.';
    } elseif (!in_array((int)$teacherId, $myTeacherIds, true) || !in_array((int)$sectionId, $mySectionIds, true)) {
        // Defense in depth — the dropdowns only list your department's own teachers/sections.
        $error = 'That teacher or section is not in your department.';
    } else {
        // Conflict check: same teacher OR same section, same term/day, overlapping time.
        $stmt = $pdo->prepare(
            "SELECT co.*, s.subject_code, t.last_name, sec.section_name
             FROM Class_Offering co
             JOIN Subject s ON s.subject_id = co.subject_id
             JOIN Teacher t ON t.teacher_id = co.teacher_id
             JOIN Section sec ON sec.section_id = co.section_id
             WHERE co.term_id = :term_id AND co.day_of_week = :day
               AND (co.teacher_id = :teacher_id OR co.section_id = :section_id)
               AND co.start_time < :end_time AND co.end_time > :start_time"
        );
        $stmt->execute([
            'term_id' => $termId, 'day' => $day, 'teacher_id' => $teacherId, 'section_id' => $sectionId,
            'end_time' => $endTime, 'start_time' => $startTime,
        ]);
        $conflict = $stmt->fetch();

        if ($conflict) {
            $error = "Schedule conflict: {$conflict['subject_code']} already runs "
                . "{$conflict['day_of_week']} {$conflict['start_time']}–{$conflict['end_time']} "
                . "with teacher {$conflict['last_name']} / section {$conflict['section_name']} in this term.";
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO Class_Offering (subject_id, teacher_id, section_id, term_id, room, day_of_week, start_time, end_time)
                 VALUES (:subject_id, :teacher_id, :section_id, :term_id, :room, :day, :start_time, :end_time)'
            );
            $stmt->execute([
                'subject_id' => $subjectId, 'teacher_id' => $teacherId, 'section_id' => $sectionId, 'term_id' => $termId,
                'room' => $room ?: null, 'day' => $day, 'start_time' => $startTime, 'end_time' => $endTime,
            ]);
            $message = 'Class offering scheduled.';
        }
    }
}

$offerings = $pdo->prepare(
    "SELECT co.*, s.subject_code, s.subject_name, t.last_name, t.first_name, sec.section_name, st.school_year, st.semester
     FROM Class_Offering co
     JOIN Subject s ON s.subject_id = co.subject_id
     JOIN Teacher t ON t.teacher_id = co.teacher_id
     JOIN Section sec ON sec.section_id = co.section_id
     JOIN Program p ON p.program_id = sec.program_id
     JOIN School_term st ON st.term_id = co.term_id
     WHERE p.department_id = :dept
     ORDER BY st.term_id DESC, co.day_of_week, co.start_time"
);
$offerings->execute(['dept' => $myDepartmentId]);
$offerings = $offerings->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Class Offerings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container">
    <h1 class="h4 mb-3">Class Offerings</h1>

    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (empty($terms)): ?>
        <div class="alert alert-warning">No ongoing term. Open one in <a href="<?= BASE_URL ?>/registrar/terms.php">School Terms</a> first.</div>
    <?php else: ?>
    <form method="post" class="card mb-4">
        <div class="card-body">
            <h2 class="h6">Schedule a Class</h2>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label class="form-label">Term</label>
                    <select class="form-select" name="term_id" required>
                        <?php foreach ($terms as $t): ?>
                            <option value="<?= $t['term_id'] ?>"><?= htmlspecialchars($t['school_year'] . ' — Sem ' . $t['semester']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Subject</label>
                    <select class="form-select" name="subject_id" required>
                        <option value="">Select</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= $s['subject_id'] ?>"><?= htmlspecialchars($s['subject_code'] . ' — ' . $s['subject_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Teacher</label>
                    <select class="form-select" name="teacher_id" required>
                        <option value="">Select</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['teacher_id'] ?>"><?= htmlspecialchars($t['last_name'] . ', ' . $t['first_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Section</label>
                    <select class="form-select" name="section_id" required>
                        <option value="">Select</option>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?= $s['section_id'] ?>"><?= htmlspecialchars($s['program_code'] . ' ' . $s['section_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Day</label>
                    <select class="form-select" name="day_of_week" required>
                        <option value="">Select</option>
                        <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d): ?>
                            <option value="<?= $d ?>"><?= $d ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">Start</label>
                    <input type="time" class="form-control" name="start_time" required>
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label">End</label>
                    <input type="time" class="form-control" name="end_time" required>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label">Room</label>
                    <input class="form-control" name="room" placeholder="Room 101">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Schedule Class</button>
        </div>
    </form>
    <?php endif; ?>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><th>Term</th><th>Subject</th><th>Teacher</th><th>Section</th><th>Day</th><th>Time</th><th>Room</th></tr></thead>
        <tbody>
        <?php foreach ($offerings as $o): ?>
            <tr>
                <td><?= htmlspecialchars($o['school_year'] . ' S' . $o['semester']) ?></td>
                <td><?= htmlspecialchars($o['subject_code']) ?></td>
                <td><?= htmlspecialchars($o['last_name'] . ', ' . $o['first_name']) ?></td>
                <td><?= htmlspecialchars($o['section_name']) ?></td>
                <td><?= htmlspecialchars($o['day_of_week']) ?></td>
                <td><?= htmlspecialchars($o['start_time'] . '–' . $o['end_time']) ?></td>
                <td><?= htmlspecialchars($o['room'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($offerings)): ?><tr><td colspan="7" class="text-muted">No class offerings yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>
</div>
</body>
</html>
