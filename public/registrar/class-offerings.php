<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../src/helpers/schedule_helper.php';
require_once __DIR__ . '/../../src/helpers/academic_helper.php';
require_once __DIR__ . '/../../src/helpers/instructor_helper.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);
$myDepartmentId = $stmt->fetchColumn();

$error = '';
$warning = '';
$message = flashGet('offering_msg') ?? '';

// Subjects are shared across every department (e.g. GE1 is taken by multiple programs), so they are not scoped.
$subjects = $pdo->query('SELECT subject_id, subject_code, subject_name FROM Subject ORDER BY subject_id DESC')->fetchAll();

$teachers = $pdo->prepare(
    'SELECT t.teacher_id, t.last_name, t.first_name
     FROM Teacher t JOIN Accounts a ON a.account_id = t.account_id
     WHERE t.department_id = :dept AND a.is_active = 1
     ORDER BY t.teacher_id DESC'
);
$teachers->execute(['dept' => $myDepartmentId]);
$teachers = $teachers->fetchAll();
$myTeacherIds = array_column($teachers, 'teacher_id');

$sections = $pdo->prepare(
    'SELECT sec.section_id, sec.section_name, sec.year_level, p.program_code
     FROM Section sec JOIN Program p ON p.program_id = sec.program_id
     WHERE p.department_id = :dept
     ORDER BY p.program_code, sec.year_level, sec.section_name'
);
$sections->execute(['dept' => $myDepartmentId]);
$sections = $sections->fetchAll();
$multiProgram = count(array_unique(array_column($sections, 'program_code'))) > 1;
$mySectionIds = array_column($sections, 'section_id');

$terms = $pdo->query("SELECT term_id, school_year, semester FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC")->fetchAll();

// Rooms are managed by the administrator. Registrars only pick from them.
$rooms = $pdo->query(
    'SELECT r.room_id, r.room_code, r.capacity, r.is_active, rt.type_name, rt.is_unlimited
     FROM Room r JOIN Room_type rt ON rt.room_type_id = r.room_type_id ORDER BY rt.type_name, r.room_code'
)->fetchAll();
$roomById = [];
foreach ($rooms as $r) {
    $roomById[(int)$r['room_id']] = $r;
}

$deleteError = '';
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/** Meetings posted by the form: parallel arrays, rows left completely empty are ignored. */
function postedMeetings(): array|string
{
    $d = (array)($_POST['meeting_day'] ?? []);
    $s = (array)($_POST['meeting_start'] ?? []);
    $e = (array)($_POST['meeting_end'] ?? []);
    $r = (array)($_POST['meeting_room'] ?? []);
    $out = [];
    foreach ($d as $i => $day) {
        $day = trim((string)$day);
        $start = substr(trim((string)($s[$i] ?? '')), 0, 5);
        $end = substr(trim((string)($e[$i] ?? '')), 0, 5);
        $room = trim((string)($r[$i] ?? ''));
        if ($room !== '' && !ctype_digit($room)) {
            return 'Pick a room from the list.';
        }
        if ($day === '' && $start === '' && $end === '' && $room === '') {
            continue;
        }
        if ($day === '' || $start === '' || $end === '') {
            return 'Each meeting needs a day, a start time and an end time.';
        }
        if (!in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], true)) {
            return 'Pick a day from Monday to Saturday.';
        }
        if ($start >= $end) {
            return 'Each meeting must start before it ends.';
        }
        $out[] = ['day' => $day, 'start' => $start, 'end' => $end, 'room' => $room];
    }
    foreach ($out as $i => $a) {
        foreach ($out as $j => $b) {
            if ($j > $i && $a['day'] === $b['day'] && $a['start'] < $b['end'] && $b['start'] < $a['end']) {
                return 'Two meetings of this class overlap on ' . $a['day'] . '.';
            }
        }
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_offering') {
    $stmt = $pdo->prepare(
        'SELECT st.status AS term_status, p.department_id,
                (SELECT COUNT(*) FROM Enrolled_subject es WHERE es.offering_id = co.offering_id) AS enrolled_count
         FROM Class_Offering co
         JOIN Section sec ON sec.section_id = co.section_id
         JOIN Program p ON p.program_id = sec.program_id
         JOIN School_term st ON st.term_id = co.term_id
         WHERE co.offering_id = :id'
    );
    $stmt->execute(['id' => ctype_digit((string)($_POST['offering_id'] ?? '')) ? (int)$_POST['offering_id'] : 0]);
    $target = $stmt->fetch();

    if (!$target || (int)$target['department_id'] !== (int)$myDepartmentId) {
        $deleteError = 'That class is not in your department.';
    } elseif ($target['term_status'] !== 'ongoing') {
        $deleteError = 'Only classes in an open term can be deleted.';
    } elseif ((int)$target['enrolled_count'] > 0) {
        $deleteError = $target['enrolled_count'] . ' student(s) are enrolled in this class, so it cannot be deleted. Move them to another class from the section roster first.';
    } else {
        try {
            $pdo->prepare('DELETE FROM Class_Offering WHERE offering_id = :id')->execute(['id' => (int)$_POST['offering_id']]);
            flashSet('offering_msg', 'Class deleted.');
            header('Location: ' . $_SERVER['REQUEST_URI']);
            exit;
        } catch (Exception $e) {
            $deleteError = errorMessage($e, 'Could not delete the class.');
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subjectId = $_POST['subject_id'] ?? '';
    $teacherId = $_POST['teacher_id'] ?? '';
    $sectionId = $_POST['section_id'] ?? '';
    $termId = $_POST['term_id'] ?? '';
    $offeringId = (int)($_POST['offering_id'] ?? 0);
    $editLocked = false;
    $editEnrolled = 0;
    $existing = false;

    if ($offeringId) {
        $ex = $pdo->prepare(
            'SELECT co.*, st.status AS term_status, p.department_id
             FROM Class_Offering co JOIN Section sec ON sec.section_id = co.section_id
             JOIN Program p ON p.program_id = sec.program_id JOIN School_term st ON st.term_id = co.term_id
             WHERE co.offering_id = :id'
        );
        $ex->execute(['id' => $offeringId]);
        $existing = $ex->fetch();
        if ($existing && (int)$existing['department_id'] === (int)$myDepartmentId && $existing['term_status'] === 'ongoing') {
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM Enrolled_subject WHERE offering_id = :id');
            $cnt->execute(['id' => $offeringId]);
            $editEnrolled = (int)$cnt->fetchColumn();
            if ($editEnrolled > 0) {
                $editLocked = true;
                $subjectId = $_POST['subject_id'] = (string)$existing['subject_id'];
                $sectionId = $_POST['section_id'] = (string)$existing['section_id'];
                $termId = $_POST['term_id'] = (string)$existing['term_id'];
            }
        }
    }

    $meetings = postedMeetings();

    if ($offeringId && (!$existing || (int)$existing['department_id'] !== (int)$myDepartmentId || $existing['term_status'] !== 'ongoing')) {
        $error = 'That class cannot be edited. It is not in your department, or its term is closed.';
    } elseif ($subjectId === '' || $teacherId === '' || $sectionId === '' || $termId === '') {
        $error = 'Term, subject, teacher and section are required.';
    } elseif (is_string($meetings)) {
        $error = $meetings;
    } elseif (!in_array((int)$teacherId, array_map('intval', $myTeacherIds), true) || !in_array((int)$sectionId, array_map('intval', $mySectionIds), true)) {
        // Defense in depth. The dropdowns only list this department's own teachers and sections.
        $error = 'That teacher is inactive or not in your department, or that section is not in your department.';
    } elseif (!in_array((int)$termId, array_map('intval', array_column($terms, 'term_id')), true)) {
        $error = 'That term is not open.';
    } else {
        /*
         * A class is one subject for one section in one term, with one teacher. Meetings are its weekly
         * time slots. A class with no meetings shows as TBA (for example OJT, thesis or a clinical rotation).
         */
        $dup = $pdo->prepare('SELECT 1 FROM Class_Offering WHERE term_id = :t AND section_id = :sec AND subject_id = :sub AND offering_id <> :self');
        $dup->execute(['t' => $termId, 'sec' => $sectionId, 'sub' => $subjectId, 'self' => $offeringId]);
        if ($dup->fetch() !== false) {
            $error = 'This section already has a class for that subject this term. Edit that class to add or change its meetings.';
        }

        // Rooms must exist, and an inactive room is only allowed when this class already uses it.
        if ($error === '') {
            $currentRoomIds = [];
            if ($offeringId) {
                $cr = $pdo->prepare('SELECT DISTINCT room_id FROM Class_Meeting WHERE offering_id = :o AND room_id IS NOT NULL');
                $cr->execute(['o' => $offeringId]);
                $currentRoomIds = array_map('intval', $cr->fetchAll(PDO::FETCH_COLUMN));
            }
            foreach ($meetings as $m) {
                if ($m['room'] === '') { continue; }
                $rm = $roomById[(int)$m['room']] ?? null;
                if ($rm === null) {
                    $error = 'Pick a room from the list.';
                    break;
                }
                if ((int)$rm['is_active'] !== 1 && !in_array((int)$m['room'], $currentRoomIds, true)) {
                    $error = 'Room ' . $rm['room_code'] . ' is inactive. Pick another room, or ask the administrator to reactivate it.';
                    break;
                }
            }
        }

        // Teacher and section cannot be in two places at once. Rooms are checked across every department.
        if ($error === '') {
            $teacherSection = $pdo->prepare(
                'SELECT s.subject_code, t.last_name, sec.section_name, cm.day_of_week, cm.start_time, cm.end_time
                 FROM Class_Meeting cm
                 JOIN Class_Offering co ON co.offering_id = cm.offering_id
                 JOIN Subject s ON s.subject_id = co.subject_id
                 JOIN Teacher t ON t.teacher_id = co.teacher_id
                 JOIN Section sec ON sec.section_id = co.section_id
                 WHERE co.term_id = :term AND cm.day_of_week = :day AND (co.teacher_id = :teacher OR co.section_id = :section)
                   AND co.offering_id <> :self AND cm.start_time < :end_time AND cm.end_time > :start_time
                 LIMIT 1'
            );
            $roomClash = $pdo->prepare(
                'SELECT s.subject_code, t.last_name, sec.section_name, cm.day_of_week, cm.start_time, cm.end_time
                 FROM Class_Meeting cm
                 JOIN Class_Offering co ON co.offering_id = cm.offering_id
                 JOIN Subject s ON s.subject_id = co.subject_id
                 JOIN Teacher t ON t.teacher_id = co.teacher_id
                 JOIN Section sec ON sec.section_id = co.section_id
                 WHERE co.term_id = :term AND cm.day_of_week = :day AND cm.room_id = :room
                   AND co.offering_id <> :self AND cm.start_time < :end_time AND cm.end_time > :start_time
                 LIMIT 1'
            );
            foreach ($meetings as $m) {
                $p = ['term' => $termId, 'day' => $m['day'], 'self' => $offeringId, 'end_time' => $m['end'], 'start_time' => $m['start']];
                $teacherSection->execute($p + ['teacher' => $teacherId, 'section' => $sectionId]);
                $hit = $teacherSection->fetch();
                if ($hit) {
                    $error = 'Schedule conflict: ' . $hit['subject_code'] . ' already runs ' . formatSchedule($hit['day_of_week'], $hit['start_time'], $hit['end_time'])
                        . ' with teacher ' . $hit['last_name'] . ' / section ' . $hit['section_name'] . ' in this term.';
                    break;
                }
                // A type with no capacity limit (off-campus or clinical site) never conflicts.
                if ($m['room'] !== '' && (int)$roomById[(int)$m['room']]['is_unlimited'] !== 1) {
                    $roomClash->execute($p + ['room' => (int)$m['room']]);
                    $hit = $roomClash->fetch();
                    if ($hit) {
                        $error = 'Room conflict: "' . $roomById[(int)$m['room']]['room_code'] . '" is already booked for ' . $hit['subject_code'] . ' '
                            . formatSchedule($hit['day_of_week'], $hit['start_time'], $hit['end_time'])
                            . ' with teacher ' . $hit['last_name'] . ' / section ' . $hit['section_name'] . ' in this term.';
                        break;
                    }
                }
            }
        }

        // A class that already has students: the new meetings must not overlap their other classes.
        if ($error === '' && $offeringId && $editEnrolled > 0) {
            $clash = $pdo->prepare(
                'SELECT sub.subject_code, st.last_name, COUNT(*) OVER () AS total
                 FROM Enrolled_subject es
                 JOIN Enrolled_subject es2 ON es2.enrollment_id = es.enrollment_id AND es2.offering_id <> es.offering_id
                 JOIN Class_Offering co2 ON co2.offering_id = es2.offering_id
                 JOIN Class_Meeting cm2 ON cm2.offering_id = co2.offering_id
                 JOIN Subject sub ON sub.subject_id = co2.subject_id
                 JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
                 JOIN Student st ON st.student_id = e.student_id
                 WHERE es.offering_id = :self AND cm2.day_of_week = :day
                   AND cm2.start_time < :end_time AND cm2.end_time > :start_time
                 LIMIT 1'
            );
            foreach ($meetings as $m) {
                $clash->execute(['self' => $offeringId, 'day' => $m['day'], 'end_time' => $m['end'], 'start_time' => $m['start']]);
                $hit = $clash->fetch();
                if ($hit) {
                    $error = 'A meeting on ' . $m['day'] . ' overlaps ' . $hit['subject_code'] . ' for ' . $hit['last_name']
                        . ((int)$hit['total'] > 1 ? ' and ' . ((int)$hit['total'] - 1) . ' other enrolled student(s)' : '')
                        . '. Pick a time that fits their schedules.';
                    break;
                }
            }
        }

        if ($error === '') {
            try {
                $pdo->beginTransaction();
                if ($offeringId) {
                    $pdo->prepare('UPDATE Class_Offering SET subject_id = :sub, teacher_id = :teacher, section_id = :sec, term_id = :term WHERE offering_id = :self')
                        ->execute(['sub' => $subjectId, 'teacher' => $teacherId, 'sec' => $sectionId, 'term' => $termId, 'self' => $offeringId]);
                    $pdo->prepare('DELETE FROM Class_Meeting WHERE offering_id = :self')->execute(['self' => $offeringId]);
                    $classId = $offeringId;
                    $message = 'Class updated.';
                } else {
                    $pdo->prepare('INSERT INTO Class_Offering (subject_id, teacher_id, section_id, term_id) VALUES (:sub, :teacher, :sec, :term)')
                        ->execute(['sub' => $subjectId, 'teacher' => $teacherId, 'sec' => $sectionId, 'term' => $termId]);
                    $classId = (int)$pdo->lastInsertId();
                    $message = $meetings ? 'Class scheduled.' : 'Class added with no meetings yet (shown as TBA).';
                }
                $ins = $pdo->prepare('INSERT INTO Class_Meeting (offering_id, day_of_week, start_time, end_time, room_id) VALUES (:o, :d, :s, :e, :r)');
                foreach ($meetings as $m) {
                    $ins->execute(['o' => $classId, 'd' => $m['day'], 's' => $m['start'], 'e' => $m['end'], 'r' => $m['room'] !== '' ? (int)$m['room'] : null]);
                }
                $pdo->commit();

                // Non-blocking: section size plus students joining from other sections, against each room's capacity.
                $joiners = $pdo->prepare(
                    'SELECT COUNT(*) FROM Enrolled_subject es JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
                     WHERE es.offering_id = :o AND (e.section_id IS NULL OR e.section_id <> :sec)'
                );
                $joiners->execute(['o' => $classId, 'sec' => $sectionId]);
                $expected = sectionOccupancy($pdo, (int)$sectionId) + (int)$joiners->fetchColumn();
                $tooSmall = [];
                foreach ($meetings as $m) {
                    $rm = $m['room'] !== '' ? $roomById[(int)$m['room']] : null;
                    if ($rm && (int)$rm['is_unlimited'] !== 1 && $rm['capacity'] !== null && $expected > (int)$rm['capacity']) {
                        $tooSmall[$rm['room_code']] = (int)$rm['capacity'];
                    }
                }
                foreach ($tooSmall as $code => $cap) {
                    $warning .= 'Room ' . $code . ' seats ' . $cap . ', but about ' . $expected . ' student(s) will attend this class (the section plus students joining from other sections). ';
                }

                // Non-blocking: a teacher over their units or weekly hours limit.
                $limits = $pdo->prepare('SELECT max_units, max_hours_per_week FROM Teacher WHERE teacher_id = :id');
                $limits->execute(['id' => $teacherId]);
                $limitRow = $limits->fetch();
                $overText = $limitRow ? loadWarningText($limitRow, teacherLoad($pdo, (int)$teacherId, (int)$termId)) : '';
                if ($overText !== '') {
                    $warning .= 'This teacher is now over their limit this term: ' . $overText . '. ';
                }
            } catch (Exception $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $error = errorMessage($e, 'Could not save the class.');
                $message = '';
            }
        }
    }
}

$offerings = $pdo->prepare(
    "SELECT co.*, s.subject_code, s.subject_name, t.last_name, t.first_name, sec.section_name, sec.year_level, p.program_code, st.school_year, st.semester,
            st.status AS term_status, (SELECT COUNT(*) FROM Enrolled_subject es WHERE es.offering_id = co.offering_id) AS enrolled_count
     FROM Class_Offering co
     JOIN Subject s ON s.subject_id = co.subject_id
     JOIN Teacher t ON t.teacher_id = co.teacher_id
     JOIN Section sec ON sec.section_id = co.section_id
     JOIN Program p ON p.program_id = sec.program_id
     JOIN School_term st ON st.term_id = co.term_id
     WHERE p.department_id = :dept
     ORDER BY st.term_id DESC, sec.year_level, sec.section_name, s.subject_code"
);
$offerings->execute(['dept' => $myDepartmentId]);
$offerings = $offerings->fetchAll();

$meetingsByClass = [];
if ($offerings) {
    $mstmt = $pdo->prepare(
        "SELECT cm.*, r.room_code AS room FROM Class_Meeting cm
         LEFT JOIN Room r ON r.room_id = cm.room_id
         JOIN Class_Offering co ON co.offering_id = cm.offering_id
         JOIN Section sec ON sec.section_id = co.section_id
         JOIN Program p ON p.program_id = sec.program_id
         WHERE p.department_id = :dept
         ORDER BY FIELD(cm.day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), cm.start_time"
    );
    $mstmt->execute(['dept' => $myDepartmentId]);
    foreach ($mstmt->fetchAll() as $m) {
        $meetingsByClass[(int)$m['offering_id']][] = $m;
    }
}

// Filter options come from the classes themselves, and a filter only shows when it has more than one choice.
// The term filter opens on the most recent ongoing term because that is the one being scheduled.
$termOptions = [];
$sectionOptions = [];
foreach ($offerings as $o) {
    $termOptions[(int) $o['term_id']] = $o['school_year'] . ' S' . $o['semester'];
    $sectionOptions[(int) $o['section_id']] = sectionLabel($o['year_level'], $o['section_name'], $multiProgram ? $o['program_code'] : null);
}
asort($sectionOptions);
$showTermFilter = count($termOptions) > 1;
$showSectionFilter = count($sectionOptions) > 1;

$termParam = is_string($_GET['term'] ?? null) ? $_GET['term'] : null;
$filterTerm = 0; // 0 = all terms
if ($termParam === 'all') {
    $filterTerm = 0;
} elseif ($termParam !== null && ctype_digit($termParam) && isset($termOptions[(int) $termParam])) {
    $filterTerm = (int) $termParam;
} elseif ($showTermFilter) {
    foreach (array_keys($termOptions) as $tid) {
        if (in_array($tid, array_map('intval', array_column($terms, 'term_id')), true)) { $filterTerm = $tid; break; }
    }
}
$filterSection = 0;
if (is_string($_GET['section'] ?? null) && ctype_digit($_GET['section']) && isset($sectionOptions[(int) $_GET['section']])) {
    $filterSection = (int) $_GET['section'];
}
$visibleOfferings = array_values(array_filter($offerings, fn($o) =>
    ($filterTerm === 0 || (int) $o['term_id'] === $filterTerm)
    && ($filterSection === 0 || (int) $o['section_id'] === $filterSection)
));
// Columns that would repeat the same value on every row are left out.
$showTermCol = $showTermFilter && $filterTerm === 0;
$showSectionCol = $filterSection === 0;
$colCount = 4 + ($showTermCol ? 1 : 0) + ($showSectionCol ? 1 : 0);

$view = ($_GET['view'] ?? '') === 'week' ? 'week' : 'list';
$by = in_array($_GET['by'] ?? '', ['teacher', 'room'], true) ? $_GET['by'] : 'section';
$weekTerm = $filterTerm !== 0 ? $filterTerm : (int)($terms[0]['term_id'] ?? 0);
$weekClasses = array_values(array_filter($offerings, fn($o) => (int)$o['term_id'] === $weekTerm));
$weekOptions = [];
foreach ($weekClasses as $o) {
    if ($by === 'section') {
        $weekOptions[(string)$o['section_id']] = sectionLabel($o['year_level'], $o['section_name'], $multiProgram ? $o['program_code'] : null);
    } elseif ($by === 'teacher') {
        $weekOptions[(string)$o['teacher_id']] = $o['last_name'] . ', ' . $o['first_name'];
    } else {
        foreach ($meetingsByClass[(int)$o['offering_id']] ?? [] as $m) {
            if (!empty($m['room'])) {
                $weekOptions[(string)$m['room']] = (string)$m['room'];
            }
        }
    }
}
asort($weekOptions);
$weekPick = is_string($_GET['pick'] ?? null) && isset($weekOptions[$_GET['pick']]) ? $_GET['pick'] : (string)(array_key_first($weekOptions) ?? '');
$weekEvents = [];
$weekTba = [];
foreach ($weekClasses as $o) {
    $sec = sectionLabel($o['year_level'], $o['section_name'], $multiProgram ? $o['program_code'] : null);
    $teacher = $o['last_name'] . ', ' . $o['first_name'];
    $mine = $by === 'section' ? (string)$o['section_id'] === $weekPick : ($by === 'teacher' ? (string)$o['teacher_id'] === $weekPick : false);
    $classMeetings = $meetingsByClass[(int)$o['offering_id']] ?? [];
    if (!$classMeetings) {
        if ($mine) {
            $weekTba[] = $o['subject_code'] . ' (' . ($by === 'section' ? $teacher : $sec) . ')';
        }
        continue;
    }
    foreach ($classMeetings as $m) {
        if ($by === 'room' ? (string)($m['room'] ?? '') !== $weekPick : !$mine) {
            continue;
        }
        $lines = $by === 'section' ? [$m['room'] ?? '', $teacher] : ($by === 'teacher' ? [$m['room'] ?? '', $sec] : [$sec, $teacher]);
        $weekEvents[] = [
            'day_of_week' => $m['day_of_week'], 'start_time' => $m['start_time'], 'end_time' => $m['end_time'],
            'title' => $o['subject_code'], 'lines' => $lines,
        ];
    }
}
$byLabels = ['section' => 'Section', 'teacher' => 'Teacher', 'room' => 'Room'];
$viewTabs = [
    'list' => ['label' => 'List', 'href' => '?term=' . ($filterTerm !== 0 ? $filterTerm : 'all')],
    'week' => ['label' => 'Week', 'href' => '?view=week&term=' . $weekTerm],
];

$reopenMeetings = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '' && ($_POST['action'] ?? '') !== 'delete_offering') {
    foreach ((array)($_POST['meeting_day'] ?? []) as $i => $day) {
        $reopenMeetings[] = [
            'day' => (string)$day, 'start' => (string)(((array)($_POST['meeting_start'] ?? []))[$i] ?? ''),
            'end' => (string)(((array)($_POST['meeting_end'] ?? []))[$i] ?? ''), 'room_id' => (string)(((array)($_POST['meeting_room'] ?? []))[$i] ?? ''),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Class Offerings</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/schedule.css">
</head>
<body class="bg-light">
<?php require __DIR__ . '/../../includes/navbar.php'; ?>
<div class="container<?= $view === 'week' ? ' container-xl' : '' ?>">
    <h1 class="h4 mb-3">Class Offerings</h1>
    <?php
    $genDraft = $_SESSION['gen_draft'] ?? null;
    $draftTermOk = $genDraft && (int)$genDraft['dept_id'] === (int)$myDepartmentId && in_array((int)$genDraft['term_id'], array_map('intval', array_column($terms, 'term_id')), true);
    if ($draftTermOk): ?>
        <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>You have an unsaved Auto-generate draft (<?= count($genDraft['rows']) ?> class<?= count($genDraft['rows']) === 1 ? '' : 'es' ?>). Nothing in it is saved yet.</span>
            <a class="btn btn-sm btn-primary" href="<?= BASE_URL ?>/registrar/generate-classes.php?step=preview">Continue draft</a>
        </div>
    <?php endif; ?>

    <?php if ($message): ?><div class="alert alert-success alert-dismissible fade show" data-auto-dismiss="4000"><?= htmlspecialchars($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($warning): ?><div class="alert alert-warning"><?= htmlspecialchars(trim($warning)) ?></div><?php endif; ?>
    <?php if ($deleteError): ?><div class="alert alert-danger"><?= htmlspecialchars($deleteError) ?></div><?php endif; ?>
    <?php $reopenForm = $error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'delete_offering'; ?>
    <?php if ($error && !$reopenForm): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php if (empty($terms)): ?>
        <div class="alert alert-warning">No ongoing term. Ask the administrator to open one first.</div>
    <?php else: ?>
    <div class="mb-3">
        <button type="button" class="btn btn-primary" onclick="openOfferingModal({})"><i class="bi bi-plus-lg"></i> Schedule a Class</button>
        <a href="<?= BASE_URL ?>/registrar/generate-classes.php" class="btn btn-outline-primary ms-2"><i class="bi bi-magic"></i> Auto-generate</a>
    </div>
    <div class="modal fade" id="offeringModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content" id="offeringForm">
          <input type="hidden" name="offering_id" id="offeringId">
          <div class="modal-header">
            <h5 class="modal-title" id="offeringModalTitle">Schedule a Class</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <?php if ($reopenForm): ?><div class="alert alert-danger js-modal-alert"><?= htmlspecialchars($error) ?></div><?php endif; ?>
            <div class="alert alert-info" id="offeringLockNote" hidden>Subject, section and term are locked because <span id="offeringLockCount">0</span> student(s) are enrolled. Teacher and meetings can still change.</div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Term</label>
                    <select class="form-select" name="term_id" required>
                        <?php foreach ($terms as $t): ?>
                            <option value="<?= $t['term_id'] ?>"><?= htmlspecialchars($t['school_year'] . ' - Sem ' . $t['semester']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-8 mb-3">
                    <label class="form-label">Subject</label>
                    <select class="form-select js-search" name="subject_id" data-placeholder="Search subjects">
                        <option value="">Select</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?= $s['subject_id'] ?>"><?= htmlspecialchars($s['subject_code'] . ' - ' . $s['subject_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Teacher</label>
                    <select class="form-select js-search" name="teacher_id" data-placeholder="Search teachers">
                        <option value="">Select</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['teacher_id'] ?>"><?= htmlspecialchars($t['last_name'] . ', ' . $t['first_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Section</label>
                    <select class="form-select js-search" name="section_id" data-placeholder="Search sections">
                        <option value="">Select</option>
                        <?php foreach ($sections as $s): ?>
                            <option value="<?= $s['section_id'] ?>"><?= htmlspecialchars(sectionLabel($s['year_level'], $s['section_name'], $multiProgram ? $s['program_code'] : null)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <label class="form-label mb-0">Meetings</label>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addMeetingRow({})"><i class="bi bi-plus-lg"></i> Add meeting</button>
            </div>
            <div id="meetingRows"></div>
            <div class="form-text" id="noMeetingsNote">No meetings means the class shows as TBA, for example OJT, thesis or a clinical rotation. A subject that meets twice a week needs two meetings in the same class.</div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" id="offeringSubmit">Schedule Class</button>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?= tabBar($viewTabs, $view) ?>
    <?php if ($view === 'list'): ?>
    <?php if ($showTermFilter || $showSectionFilter): ?>
    <form method="get" class="toolbar">
        <?php if ($showTermFilter): ?>
        <div class="toolbar-field">
            <label for="term">Term</label>
            <select name="term" id="term" class="form-select" onchange="this.form.submit()">
                <option value="all" <?= $filterTerm === 0 ? 'selected' : '' ?>>All terms</option>
                <?php foreach ($termOptions as $tid => $tlabel): ?>
                    <option value="<?= $tid ?>" <?= $filterTerm === $tid ? 'selected' : '' ?>><?= htmlspecialchars($tlabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($showSectionFilter): ?>
        <div class="toolbar-field">
            <label for="section">Section</label>
            <select name="section" id="section" class="form-select" onchange="this.form.submit()">
                <option value="">All sections</option>
                <?php foreach ($sectionOptions as $sid => $slabel): ?>
                    <option value="<?= $sid ?>" <?= $filterSection === $sid ? 'selected' : '' ?>><?= htmlspecialchars($slabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <noscript><button type="submit" class="btn btn-primary">Apply</button></noscript>
        <?php if ($termParam !== null || $filterSection !== 0): ?><a href="class-offerings.php" class="btn btn-outline-secondary">Reset</a><?php endif; ?>
        <span class="toolbar-count">Showing <?= count($visibleOfferings) ?> class<?= count($visibleOfferings) === 1 ? '' : 'es' ?></span>
    </form>
    <?php endif; ?>

    <div class="table-responsive">
<table class="table table-hover bg-white">
        <thead><tr><?php if ($showTermCol): ?><th>Term</th><?php endif; ?><th>Subject</th><th>Teacher</th><?php if ($showSectionCol): ?><th>Section</th><?php endif; ?><th>Meetings</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($visibleOfferings as $o): $classMeetings = $meetingsByClass[(int)$o['offering_id']] ?? []; ?>
            <tr>
                <?php if ($showTermCol): ?><td><?= htmlspecialchars($o['school_year'] . ' S' . $o['semester']) ?></td><?php endif; ?>
                <td><?= htmlspecialchars($o['subject_code']) ?></td>
                <td><?= htmlspecialchars($o['last_name'] . ', ' . $o['first_name']) ?></td>
                <?php if ($showSectionCol): ?><td><?= htmlspecialchars(sectionLabel($o['year_level'], $o['section_name'], $multiProgram ? $o['program_code'] : null)) ?></td><?php endif; ?>
                <td>
                    <?php if ($classMeetings): foreach ($classMeetings as $m): ?>
                        <div><?= htmlspecialchars(formatSchedule($m['day_of_week'], $m['start_time'], $m['end_time'], $m['room'] ?? '')) ?></div>
                    <?php endforeach; else: ?>
                        <span class="text-muted">TBA</span>
                    <?php endif; ?>
                </td>
                <td class="text-nowrap">
                    <?php if ($o['term_status'] === 'ongoing'): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary"
                                data-offering="<?= htmlspecialchars(json_encode([
                                    'offering_id' => (int)$o['offering_id'], 'term_id' => (string)$o['term_id'], 'subject_id' => (string)$o['subject_id'],
                                    'teacher_id' => (string)$o['teacher_id'], 'section_id' => (string)$o['section_id'],
                                    'meetings' => array_map(fn($m) => [
                                        'day' => $m['day_of_week'], 'start' => substr((string)$m['start_time'], 0, 5),
                                        'end' => substr((string)$m['end_time'], 0, 5), 'room_id' => $m['room_id'] === null ? '' : (string)$m['room_id'],
                                    ], $classMeetings),
                                    'locked' => (int)$o['enrolled_count'] > 0, 'enrolled' => (int)$o['enrolled_count'],
                                ]), ENT_QUOTES) ?>"
                                onclick="openOfferingModal(JSON.parse(this.dataset.offering))">Edit</button>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="action" value="delete_offering">
                            <input type="hidden" name="offering_id" value="<?= (int)$o['offering_id'] ?>">
                            <?php if ((int)$o['enrolled_count'] > 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger js-delete-blocked" data-enrolled="<?= (int)$o['enrolled_count'] ?>"
                                        data-roster="<?= BASE_URL ?>/registrar/section-roster.php?id=<?= (int)$o['section_id'] ?>">Delete</button>
                            <?php else: ?>
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        data-confirm="Delete this class and its meetings? No students are enrolled in it." data-confirm-label="Delete" data-confirm-tone="danger">Delete</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($visibleOfferings)): ?>
            <tr><td colspan="<?= $colCount ?>" class="text-muted">
                <?php if (empty($offerings)): ?>No classes yet.
                <?php elseif ($filterSection === 0 && $filterTerm !== 0): ?>No classes scheduled for this term yet.
                <?php else: ?>No classes match this filter.<?php endif; ?>
            </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
    <?php if (empty($termOptions)): ?>
        <p class="text-muted">No classes yet, so there is nothing to draw.</p>
    <?php else: ?>
    <form method="get" class="toolbar">
        <input type="hidden" name="view" value="week">
        <div class="toolbar-field toolbar-field-sm">
            <label for="wterm">Term</label>
            <select name="term" id="wterm" class="form-select" onchange="this.form.pick.value=''; this.form.submit()">
                <?php foreach ($termOptions as $tid => $tlabel): ?>
                    <option value="<?= $tid ?>" <?= $weekTerm === $tid ? 'selected' : '' ?>><?= htmlspecialchars($tlabel) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field toolbar-field-sm">
            <label for="wby">Show by</label>
            <select name="by" id="wby" class="form-select" onchange="this.form.pick.value=''; this.form.submit()">
                <?php foreach ($byLabels as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $by === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="toolbar-field">
            <label for="wpick"><?= htmlspecialchars($byLabels[$by]) ?></label>
            <select name="pick" id="wpick" class="form-select" onchange="this.form.submit()">
                <?php foreach ($weekOptions as $value => $label): ?>
                    <option value="<?= htmlspecialchars((string)$value) ?>" <?= $weekPick === (string)$value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <noscript><button type="submit" class="btn btn-primary">View</button></noscript>
    </form>
    <?php if ($by === 'room'): ?>
        <p class="text-muted small">Only your department's classes are drawn. Other departments may also use this room.</p>
    <?php endif; ?>
    <?php if (empty($weekOptions)): ?>
        <p class="text-muted">No classes in this term yet.</p>
    <?php else: ?>
        <?= renderScheduleGrid($weekEvents) ?>
        <?php if ($weekTba): ?>
            <p class="small text-muted mt-2 mb-0">No set time (TBA): <?= htmlspecialchars(implode(', ', $weekTba)) ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
</div>
<div class="modal fade" id="deleteBlockedModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">This class cannot be deleted</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p><strong id="dbCount"></strong> student(s) are enrolled in this class. Move them to another class first:</p>
        <ul class="mb-2">
            <li><strong>Irregular students:</strong> open the section roster and use <em>Change classes</em> to swap this subject to another class.</li>
            <li><strong>Regular students:</strong> use <em>Move section</em> on the roster.</li>
        </ul>
        <p class="mb-0 text-muted small">To change only the teacher or the meetings, use <em>Edit</em> instead. Students keep their seat.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <a href="#" class="btn btn-primary" id="dbRoster">Open section roster</a>
      </div>
    </div>
  </div>
</div>
<script>
document.querySelectorAll('.js-delete-blocked').forEach(function (b) {
    b.addEventListener('click', function () {
        document.getElementById('dbCount').textContent = b.dataset.enrolled;
        document.getElementById('dbRoster').href = b.dataset.roster;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteBlockedModal')).show();
    });
});
</script>
<script>
var meetingDays = <?= json_encode($days) ?>;
var roomList = <?= json_encode(array_map(fn($r) => [
    'id' => (int)$r['room_id'], 'code' => $r['room_code'], 'type' => $r['type_name'],
    'cap' => $r['capacity'] === null || (int)$r['is_unlimited'] === 1 ? null : (int)$r['capacity'], 'active' => (int)$r['is_active'] === 1,
], $rooms), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
function roomOptionsHtml(currentId) {
    var html = '<option value="">No room</option>', byType = {}, order = [];
    roomList.forEach(function (r) {
        if (!r.active && String(r.id) !== String(currentId)) { return; }
        if (!byType[r.type]) { byType[r.type] = []; order.push(r.type); }
        byType[r.type].push(r);
    });
    order.forEach(function (type) {
        html += '<optgroup label="' + type.replace(/"/g, '&quot;') + '">';
        byType[type].forEach(function (r) {
            var label = r.code + (r.cap ? ' (seats ' + r.cap + ')' : '') + (r.active ? '' : ' - inactive');
            html += '<option value="' + r.id + '"' + (String(r.id) === String(currentId) ? ' selected' : '') + '>' + label.replace(/</g, '&lt;') + '</option>';
        });
        html += '</optgroup>';
    });
    return html;
}
function fillModalForm(form, values) {
    ['term_id', 'subject_id', 'teacher_id', 'section_id'].forEach(function (name) {
        var el = form.elements[name];
        if (!el || values[name] === undefined) { return; }
        if (el.tomselect) { el.tomselect.setValue(values[name]); } else { el.value = values[name]; }
    });
}
function refreshMeetingNote() {
    document.getElementById('noMeetingsNote').hidden = document.querySelectorAll('#meetingRows .meeting-row').length > 0;
}
function addMeetingRow(m) {
    var row = document.createElement('div');
    row.className = 'row g-2 mb-2 meeting-row';
    var dayOptions = '<option value="">Day</option>' + meetingDays.map(function (d) {
        return '<option value="' + d + '"' + (m.day === d ? ' selected' : '') + '>' + d + '</option>';
    }).join('');
    row.innerHTML =
        '<div class="col-md-3"><select class="form-select" name="meeting_day[]">' + dayOptions + '</select></div>' +
        '<div class="col-md-2"><input type="time" class="form-control" name="meeting_start[]" value="' + (m.start || '') + '"></div>' +
        '<div class="col-md-2"><input type="time" class="form-control" name="meeting_end[]" value="' + (m.end || '') + '"></div>' +
        '<div class="col-md-4"><select class="form-select" name="meeting_room[]">' + roomOptionsHtml(m.room_id) + '</select></div>' +
        '<div class="col-md-1"><button type="button" class="btn btn-outline-danger w-100" aria-label="Remove meeting">&times;</button></div>';
    row.querySelector('button').addEventListener('click', function () { row.remove(); refreshMeetingNote(); });
    document.getElementById('meetingRows').appendChild(row);
    refreshMeetingNote();
}
function openOfferingModal(values) {
    var form = document.getElementById('offeringForm');
    if (!form) { return; }
    var editing = !!values.offering_id, locked = !!values.locked;
    if (!editing) {
        form.reset();
        form.querySelectorAll('select.js-search').forEach(function (el) { if (el.tomselect) { el.tomselect.clear(true); } });
    }
    document.getElementById('offeringId').value = values.offering_id || '';
    fillModalForm(form, values);
    ['term_id', 'subject_id', 'section_id'].forEach(function (n) {
        var el = form.elements[n];
        if (!el) { return; }
        if (el.tomselect) { if (locked) { el.tomselect.disable(); } else { el.tomselect.enable(); } } else { el.disabled = locked; }
    });
    document.getElementById('meetingRows').innerHTML = '';
    (values.meetings || []).forEach(addMeetingRow);
    if (!editing && !(values.meetings || []).length) { addMeetingRow({}); }
    refreshMeetingNote();
    document.getElementById('offeringLockNote').hidden = !locked;
    document.getElementById('offeringLockCount').textContent = values.enrolled || 0;
    document.getElementById('offeringModalTitle').textContent = editing ? 'Edit Class' : 'Schedule a Class';
    document.getElementById('offeringSubmit').textContent = editing ? 'Save Changes' : 'Schedule Class';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('offeringModal')).show();
}
<?php if ($reopenForm && !empty($terms)): ?>
document.addEventListener('DOMContentLoaded', function () {
    openOfferingModal(<?= json_encode(array_merge(
        array_intersect_key($_POST, array_flip(['offering_id', 'term_id', 'subject_id', 'teacher_id', 'section_id'])),
        ['meetings' => $reopenMeetings, 'locked' => $editLocked, 'enrolled' => $editEnrolled]
    ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
});
<?php endif; ?>
</script>
</body>
</html>