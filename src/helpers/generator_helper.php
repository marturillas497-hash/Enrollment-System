<?php
/**
 * Class offering generator. It builds a draft schedule for the ongoing term, validates it the same way
 * whether it came from the generator or from a manual edit, and saves it as ordinary classes.
 *
 * Hard rules (a draft with one cannot be saved): a teacher, a section or a room in two places at once,
 * a second class for the same subject in the same section and term, and a class with no teacher.
 * Soft rules (only warn): tag match, teacher limits, room type, room size, school days and hours, and a
 * teacher teaching more than GEN_MAX_CHAIN classes back to back.
 *
 * Placement is cost based: every feasible day and start is scored and the cheapest wins. A section's
 * classes are packed right after each other (little waiting), days start early, and a teacher never gets
 * more than GEN_MAX_CHAIN classes in a row before a break.
 */

require_once __DIR__ . '/tag_helper.php';
require_once __DIR__ . '/instructor_helper.php';
require_once __DIR__ . '/academic_helper.php';
require_once __DIR__ . '/flash_helper.php';
require_once __DIR__ . '/ui_helper.php';

const GEN_DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
const GEN_DAY_START = 450;    // 7:30 AM
const GEN_LUNCH_START = 690;  // 11:30 AM
const GEN_LUNCH_END = 750;    // 12:30 PM
const GEN_DAY_END = 1050;     // 5:30 PM
const GEN_MAX_SECTIONS = 5;   // sections per run, keeps the preview form under the host's input limit
const GEN_MAX_ATTEMPTS = 8;
const GEN_MIN_ATTEMPTS = 5;
const GEN_MAX_CHAIN = 2;       // most classes a teacher may teach back to back
const GEN_REST_MIN = 30;       // a gap shorter than this still counts as back to back
const GEN_DAY_CAP = 420;       // 7 hours a day for a section or a teacher, strict in the first two passes
const GEN_SOFT_DAY_CAP_SECTION = 360;
const GEN_LONG_WAIT = 90;      // a gap this long between a section's classes counts as a long wait
const GEN_STD_PAIRS = ['Monday|Thursday', 'Tuesday|Friday', 'Monday|Wednesday', 'Tuesday|Thursday', 'Wednesday|Friday'];

/** Busy time per teacher, section and room, as intervals of minutes. */
final class GenOccupancy
{
    private array $b = [];

    public function add(string $kind, int $id, string $day, int $s, int $e, string $label = ''): void
    {
        $this->b["$kind:$id:$day"][] = [$s, $e, $label];
    }

    public function remove(string $kind, int $id, string $day, int $s, int $e): void
    {
        $k = "$kind:$id:$day";
        foreach ($this->b[$k] ?? [] as $i => $iv) {
            if ($iv[0] === $s && $iv[1] === $e) {
                unset($this->b[$k][$i]);
                $this->b[$k] = array_values($this->b[$k]);
                return;
            }
        }
    }

    /** Label of the interval that overlaps, or null when free. */
    public function clash(string $kind, int $id, string $day, int $s, int $e): ?string
    {
        foreach ($this->b["$kind:$id:$day"] ?? [] as $iv) {
            if ($s < $iv[1] && $iv[0] < $e) {
                return $iv[2] !== '' ? $iv[2] : 'another class';
            }
        }
        return null;
    }

    /** Busy intervals as sorted [start, end] pairs. */
    public function intervals(string $kind, int $id, string $day): array
    {
        $out = array_map(fn($iv) => [$iv[0], $iv[1]], $this->b["$kind:$id:$day"] ?? []);
        usort($out, fn($a, $b) => $a[0] <=> $b[0]);
        return $out;
    }

    public function minutes(string $kind, int $id, string $day): int
    {
        $sum = 0;
        foreach ($this->b["$kind:$id:$day"] ?? [] as $iv) {
            $sum += $iv[1] - $iv[0];
        }
        return $sum;
    }
}

function genMin(string $t): ?int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})/', trim($t), $m)) {
        return null;
    }
    $h = (int)$m[1];
    $mi = (int)$m[2];
    return ($h > 23 || $mi > 59) ? null : $h * 60 + $mi;
}

function genHm(int $min): string
{
    return sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
}

function genFmtTime(int $min): string
{
    return date('g:i A', strtotime(genHm($min)));
}

/** Everything the generator and validator need to know about the term, the department and what is already scheduled. */
function genLoadContext(PDO $pdo, int $termId, int $departmentId): array
{
    $ctx = ['term_id' => $termId, 'dept_id' => $departmentId];

    $stmt = $pdo->prepare('SELECT semester, school_year FROM School_term WHERE term_id = :id');
    $stmt->execute(['id' => $termId]);
    $termRow = $stmt->fetch();
    $ctx['semester'] = (int)($termRow['semester'] ?? 0);
    $ctx['school_year'] = (string)($termRow['school_year'] ?? '');

    $stmt = $pdo->prepare(
        'SELECT t.teacher_id, t.last_name, t.first_name, t.max_units, t.max_hours_per_week
         FROM Teacher t JOIN Accounts a ON a.account_id = t.account_id
         WHERE t.department_id = :d AND a.is_active = 1 ORDER BY t.last_name, t.first_name'
    );
    $stmt->execute(['d' => $departmentId]);
    $ctx['teachers'] = [];
    foreach ($stmt->fetchAll() as $t) {
        $ctx['teachers'][(int)$t['teacher_id']] = [
            'id' => (int)$t['teacher_id'], 'name' => $t['last_name'] . ', ' . $t['first_name'],
            'max_units' => (int)$t['max_units'], 'max_hours_per_week' => $t['max_hours_per_week'], 'tags' => [],
        ];
    }
    foreach ($pdo->query('SELECT teacher_id, tag_id FROM Teacher_tag')->fetchAll() as $r) {
        if (isset($ctx['teachers'][(int)$r['teacher_id']])) {
            $ctx['teachers'][(int)$r['teacher_id']]['tags'][] = (int)$r['tag_id'];
        }
    }

    $ctx['types'] = [];
    foreach ($pdo->query('SELECT room_type_id, type_name, is_unlimited FROM Room_type ORDER BY type_name')->fetchAll() as $t) {
        $ctx['types'][(int)$t['room_type_id']] = ['name' => $t['type_name'], 'unlimited' => (int)$t['is_unlimited'] === 1];
    }
    $lecture = $pdo->query("SELECT room_type_id FROM Room_type WHERE type_name = 'Lecture Room'")->fetchColumn();
    $ctx['lecture_type'] = $lecture !== false ? (int)$lecture : null;

    $ctx['rooms'] = [];
    $ctx['rooms_by_type'] = [];
    foreach ($pdo->query('SELECT room_id, room_code, room_type_id, capacity, is_active FROM Room ORDER BY room_code')->fetchAll() as $r) {
        $rid = (int)$r['room_id'];
        $tid = (int)$r['room_type_id'];
        $ctx['rooms'][$rid] = [
            'id' => $rid, 'code' => $r['room_code'], 'type_id' => $tid, 'capacity' => $r['capacity'] === null ? null : (int)$r['capacity'],
            'active' => (int)$r['is_active'] === 1, 'unlimited' => $ctx['types'][$tid]['unlimited'] ?? false,
        ];
        if ((int)$r['is_active'] === 1) {
            $ctx['rooms_by_type'][$tid][] = $rid;
        }
    }
    foreach ($ctx['rooms_by_type'] as $tid => $ids) {
        usort($ids, fn($a, $b) => ($ctx['rooms'][$a]['capacity'] ?? PHP_INT_MAX) <=> ($ctx['rooms'][$b]['capacity'] ?? PHP_INT_MAX));
        $ctx['rooms_by_type'][$tid] = $ids;
    }

    $stmt = $pdo->prepare(
        'SELECT sec.section_id, sec.section_name, sec.year_level, sec.curriculum_id, sec.max_slots, p.program_code
         FROM Section sec JOIN Program p ON p.program_id = sec.program_id
         WHERE p.department_id = :d ORDER BY p.program_code, sec.year_level, sec.section_name'
    );
    $stmt->execute(['d' => $departmentId]);
    $ctx['sections'] = [];
    foreach ($stmt->fetchAll() as $s) {
        $sid = (int)$s['section_id'];
        $occupied = function_exists('sectionOccupancy') ? (int)sectionOccupancy($pdo, $sid) : 0;
        $ctx['sections'][$sid] = [
            'id' => $sid, 'name' => $s['section_name'], 'year' => (int)$s['year_level'], 'curriculum_id' => (int)$s['curriculum_id'],
            'program' => $s['program_code'], 'label' => sectionLabel($s['year_level'], $s['section_name'], $s['program_code']), 'occupied' => $occupied,
            'size' => $occupied > 0 ? $occupied : (int)$s['max_slots'],
        ];
    }

    $votes = [];
    $stmt = $pdo->prepare(
        "SELECT e.section_id, e.year_level, t.school_year
         FROM Student s
         JOIN Enrollment e ON e.enrollment_id = (
             SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id AND e2.status <> 'rejected'
             ORDER BY e2.enrollment_id DESC LIMIT 1)
         JOIN School_term t ON t.term_id = e.term_id
         JOIN Section sec ON sec.section_id = e.section_id
         JOIN Program p ON p.program_id = sec.program_id
         WHERE s.overall_status = 'active' AND p.department_id = :d"
    );
    $stmt->execute(['d' => $departmentId]);
    $termInfo = ['semester' => $ctx['semester'], 'school_year' => $ctx['school_year']];
    foreach ($stmt->fetchAll() as $r) {
        $y = nextYearLevel((int)$r['year_level'], $termInfo, (string)$r['school_year']);
        $votes[(int)$r['section_id']][$y] = ($votes[(int)$r['section_id']][$y] ?? 0) + 1;
    }
    foreach ($ctx['sections'] as $sid => &$sx) {
        $sx['teach_year'] = max(1, min(4, $sx['year']));
        $sx['year_from_students'] = false;
        if (!empty($votes[$sid])) {
            $best = 0;
            $bestCount = 0;
            foreach ($votes[$sid] as $y => $n) {
                if ($n > $bestCount || ($n === $bestCount && $y > $best)) {
                    $best = $y;
                    $bestCount = $n;
                }
            }
            $cap = curriculumMaxYear($pdo, $sx['curriculum_id']) ?: 4;
            $sx['teach_year'] = max(1, min(4, $cap, $best));
            $sx['year_from_students'] = true;
        }
    }
    unset($sx);

    $ctx['existing'] = [];
    foreach ($pdo->query('SELECT section_id, subject_id FROM Class_Offering WHERE term_id = ' . (int)$termId)->fetchAll() as $r) {
        $ctx['existing'][$r['section_id'] . '-' . $r['subject_id']] = true;
    }

    $occ = new GenOccupancy();
    $stmt = $pdo->prepare(
        'SELECT co.teacher_id, co.section_id, cm.day_of_week, cm.start_time, cm.end_time, cm.room_id, s.subject_code
         FROM Class_Meeting cm JOIN Class_Offering co ON co.offering_id = cm.offering_id
         JOIN Subject s ON s.subject_id = co.subject_id WHERE co.term_id = :t'
    );
    $stmt->execute(['t' => $termId]);
    foreach ($stmt->fetchAll() as $m) {
        $s = genMin($m['start_time']);
        $e = genMin($m['end_time']);
        if ($s === null || $e === null) {
            continue;
        }
        $label = $m['subject_code'];
        $occ->add('teacher', (int)$m['teacher_id'], $m['day_of_week'], $s, $e, $label);
        $occ->add('section', (int)$m['section_id'], $m['day_of_week'], $s, $e, $label);
        if ($m['room_id'] !== null && empty($ctx['rooms'][(int)$m['room_id']]['unlimited'])) {
            $occ->add('room', (int)$m['room_id'], $m['day_of_week'], $s, $e, $label);
        }
    }
    $ctx['occ'] = $occ;
    $ctx['load'] = teacherLoadMap($pdo, $termId);
    return $ctx;
}

/** Tags, units and weekly parts for a set of subjects. A subject without parts gets one lecture block. */
function genSubjects(PDO $pdo, array $ctx, array $subjectIds): array
{
    $subjectIds = array_values(array_unique(array_map('intval', $subjectIds)));
    if (!$subjectIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($subjectIds), '?'));
    $out = [];

    $stmt = $pdo->prepare("SELECT subject_id, subject_code, subject_name, units FROM Subject WHERE subject_id IN ($in)");
    $stmt->execute($subjectIds);
    foreach ($stmt->fetchAll() as $s) {
        $out[(int)$s['subject_id']] = [
            'id' => (int)$s['subject_id'], 'code' => $s['subject_code'], 'name' => $s['subject_name'],
            'units' => (float)$s['units'], 'tags' => [], 'parts' => [],
        ];
    }
    $stmt = $pdo->prepare("SELECT subject_id, tag_id FROM Subject_tag WHERE subject_id IN ($in)");
    $stmt->execute($subjectIds);
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['subject_id']]['tags'][] = (int)$r['tag_id'];
    }
    $stmt = $pdo->prepare("SELECT * FROM Subject_requirement WHERE subject_id IN ($in) ORDER BY requirement_id");
    $stmt->execute($subjectIds);
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['subject_id']]['parts'][] = [
            'kind' => $r['part_kind'], 'hours' => (float)$r['hours_per_meeting'], 'meetings' => max(1, (int)$r['meetings_per_week']),
            'room' => $r['preferred_room_type_id'] === null ? null : (int)$r['preferred_room_type_id'],
            'fallback' => $r['fallback_room_type_id'] === null ? null : (int)$r['fallback_room_type_id'],
        ];
    }
    foreach ($out as &$s) {
        $s['has_parts'] = $s['parts'] !== [];
        if (!$s['parts']) {
            $s['parts'] = [['kind' => 'Lecture', 'hours' => min((float)$s['units'], 5.0), 'meetings' => 1, 'room' => $ctx['lecture_type'], 'fallback' => null]];
        }
    }
    unset($s);
    return $out;
}

/** What has to be fixed before the generator can do a good job. */
function genReadiness(PDO $pdo, array $ctx): array
{
    $stmt = $pdo->prepare(
        'SELECT s.subject_id, s.subject_code, s.subject_name
         FROM Curriculum_subject cs JOIN Subject s ON s.subject_id = cs.subject_id
         WHERE cs.curriculum_id = :c AND cs.year_level = :y AND cs.semester = :sem'
    );
    $subjects = [];
    foreach ($ctx['sections'] as $sec) {
        $stmt->execute(['c' => $sec['curriculum_id'], 'y' => $sec['teach_year'], 'sem' => $ctx['semester']]);
        foreach ($stmt->fetchAll() as $r) {
            $subjects[(int)$r['subject_id']] = $r;
        }
    }
    usort($subjects, fn($x, $y) => strcmp($x['subject_code'], $y['subject_code']));
    $info = genSubjects($pdo, $ctx, array_column($subjects, 'subject_id'));

    $noTags = [];
    $noParts = [];
    $missingTypes = [];
    foreach ($subjects as $s) {
        $i = $info[(int)$s['subject_id']];
        if (!$i['tags']) { $noTags[] = $s['subject_code'] . ' ' . $s['subject_name']; }
        if (!$i['has_parts']) { $noParts[] = $s['subject_code']; }
        foreach ($i['parts'] as $p) {
            if ($p['hours'] > 0 && ($p['room'] === null || empty($ctx['rooms_by_type'][$p['room']])) && ($p['fallback'] === null || empty($ctx['rooms_by_type'][$p['fallback']]))) {
                $name = $p['room'] !== null ? ($ctx['types'][$p['room']]['name'] ?? '?') : 'any room type';
                $missingTypes[$name][] = $s['subject_code'];
            }
        }
    }
    $teachersNoTags = [];
    foreach ($ctx['teachers'] as $t) {
        if (!$t['tags']) { $teachersNoTags[] = $t['name']; }
    }
    return [
        'subjects_total' => count($subjects), 'subjects_no_tags' => $noTags, 'subjects_no_parts' => $noParts,
        'teachers_no_tags' => $teachersNoTags, 'missing_room_types' => $missingTypes,
        'teachers_total' => count($ctx['teachers']), 'rooms_total' => count(array_filter($ctx['rooms'], fn($r) => $r['active'])),
    ];
}

/** Teachers who can take a subject, best first. Only full or partial tag matches qualify. */
function genCandidates(array $subject, array $ctx, array $loadNow): array
{
    $out = [];
    foreach ($ctx['teachers'] as $t) {
        $level = tagMatchLevel($subject['tags'], $t['tags']);
        if ($level !== 'full' && $level !== 'partial') {
            continue;
        }
        $out[] = ['id' => $t['id'], 'level' => $level, 'score' => ($loadNow[$t['id']]['units'] ?? 0) + mt_rand(0, 99) / 1000];
    }
    usort($out, fn($a, $b) => [$a['level'] === 'full' ? 0 : 1, $a['score']] <=> [$b['level'] === 'full' ? 0 : 1, $b['score']]);
    return $out;
}

/** Room for one meeting: [room_id or null] when found, false when nothing fits. */
function genPickRoom(array $req, string $day, int $s, int $e, int $size, bool $allowSmall, GenOccupancy $occ, array $ctx, bool $useFallback = true): array|false
{
    $anyRoom = false;
    foreach (($useFallback || $req['room'] === null ? [$req['room'], $req['fallback']] : [$req['room']]) as $typeId) {
        if ($typeId === null) {
            continue;
        }
        $ids = $ctx['rooms_by_type'][$typeId] ?? [];
        if (!$ids) {
            continue;
        }
        $anyRoom = true;
        if ($ctx['types'][$typeId]['unlimited'] ?? false) {
            return [$ids[0]];
        }
        foreach ($ids as $rid) {
            $cap = $ctx['rooms'][$rid]['capacity'];
            if (!$allowSmall && $cap !== null && $cap < $size) {
                continue;
            }
            if ($occ->clash('room', $rid, $day, $s, $e) === null) {
                return [$rid];
            }
        }
    }
    if ($anyRoom) {
        return false;
    }
    if (!$useFallback && $req['fallback'] !== null && !empty($ctx['rooms_by_type'][$req['fallback']])) {
        return false;
    }
    return [null];
}

function genMark(GenOccupancy $occ, array $m, ?int $teacherId, int $sectionId, array $ctx, string $label): void
{
    $occ->add('section', $sectionId, $m['day'], $m['s'], $m['e'], $label);
    if ($teacherId !== null) {
        $occ->add('teacher', $teacherId, $m['day'], $m['s'], $m['e'], $label);
    }
    if ($m['room_id'] !== null && empty($ctx['rooms'][$m['room_id']]['unlimited'])) {
        $occ->add('room', $m['room_id'], $m['day'], $m['s'], $m['e'], $label);
    }
}

function genUnmark(GenOccupancy $occ, array $m, ?int $teacherId, int $sectionId, array $ctx): void
{
    $occ->remove('section', $sectionId, $m['day'], $m['s'], $m['e']);
    if ($teacherId !== null) {
        $occ->remove('teacher', $teacherId, $m['day'], $m['s'], $m['e']);
    }
    if ($m['room_id'] !== null && empty($ctx['rooms'][$m['room_id']]['unlimited'])) {
        $occ->remove('room', $m['room_id'], $m['day'], $m['s'], $m['e']);
    }
}

/** Valid start times for a block of $len minutes: before lunch, then after lunch, on a 30 minute grid. */
function genStarts(int $len): array
{
    $starts = [];
    for ($s = GEN_DAY_START; $s + $len <= GEN_LUNCH_START; $s += 30) { $starts[] = $s; }
    for ($s = GEN_LUNCH_END; $s + $len <= GEN_DAY_END; $s += 30) { $starts[] = $s; }
    return $starts;
}

/** Idle minutes between consecutive classes of a sorted list of [start, end], not counting the lunch break. */
function genGaps(array $ivs): array
{
    $gaps = [];
    for ($i = 1; $i < count($ivs); $i++) {
        $gap = $ivs[$i][0] - $ivs[$i - 1][1];
        if ($gap <= 0) {
            continue;
        }
        $lunch = max(0, min($ivs[$i][0], GEN_LUNCH_END) - max($ivs[$i - 1][1], GEN_LUNCH_START));
        if ($gap - $lunch > 0) {
            $gaps[] = $gap - $lunch;
        }
    }
    return $gaps;
}

/** How many classes in a row (each starting within GEN_REST_MIN of the last end) the new interval would belong to. */
function genChainLength(array $ivs, int $s, int $e): int
{
    $ivs[] = [$s, $e];
    usort($ivs, fn($a, $b) => $a[0] <=> $b[0]);
    $at = 0;
    foreach ($ivs as $k => $iv) {
        if ($iv[0] === $s && $iv[1] === $e) {
            $at = $k;
            break;
        }
    }
    $n = 1;
    for ($j = $at; $j > 0 && $ivs[$j][0] - $ivs[$j - 1][1] < GEN_REST_MIN; $j--) { $n++; }
    for ($j = $at; $j < count($ivs) - 1 && $ivs[$j + 1][0] - $ivs[$j][1] < GEN_REST_MIN; $j++) { $n++; }
    return $n;
}

function genIsStdPair(string $a, string $b): bool
{
    return in_array($a . '|' . $b, GEN_STD_PAIRS, true) || in_array($b . '|' . $a, GEN_STD_PAIRS, true);
}

/**
 * Cost of putting one meeting at $s to $e on a day (lower is better), or null when a strict rule forbids it.
 * $secIv and $teachIv are the section's and the teacher's busy intervals that day. $placed are the earlier
 * meetings of the same class.
 */
function genSlotCost(array $secIv, array $teachIv, bool $hasTeacher, int $s, int $e, array $placed, bool $strict): ?float
{
    $len = $e - $s;
    $after = $secIv;
    $after[] = [$s, $e];
    usort($after, fn($a, $b) => $a[0] <=> $b[0]);
    $cost = (array_sum(genGaps($after)) - array_sum(genGaps($secIv))) / 10;

    if (!$secIv) {
        $cost += 1.5 + ($s - GEN_DAY_START) / 30;
    } else {
        $cost += (min($secIv[0][0], $s) - $secIv[0][0]) / 30;
    }
    $cost += max(0, $e - 960) / 60;

    $secMin = $len;
    foreach ($secIv as $iv) { $secMin += $iv[1] - $iv[0]; }
    if ($strict && $secMin > GEN_DAY_CAP) {
        return null;
    }
    if ($secMin > GEN_SOFT_DAY_CAP_SECTION) {
        $cost += 6.0 * ($secMin - GEN_SOFT_DAY_CAP_SECTION) / 60;
    }

    if ($hasTeacher) {
        if (!$teachIv) {
            $cost += 1.0;
        }
        if (genChainLength($teachIv, $s, $e) > GEN_MAX_CHAIN) {
            if ($strict) {
                return null;
            }
            $cost += 20.0;
        }
        $teachMin = $len;
        foreach ($teachIv as $iv) { $teachMin += $iv[1] - $iv[0]; }
        if ($strict && $teachMin > GEN_DAY_CAP) {
            return null;
        }
        if ($teachMin > GEN_DAY_CAP) {
            $cost += 6.0 * ($teachMin - GEN_DAY_CAP) / 60;
        }
    }

    if ($placed && $s !== $placed[0]['s']) {
        $cost += 4.0;
    }
    return $cost;
}

/** Place every meeting of one class. Returns the meetings, or null when it does not fit. */
function genPlaceMeetings(array $requests, ?int $teacherId, array $sec, string $label, GenOccupancy $occ, array $ctx): ?array
{
    foreach ([[true, false, false], [true, false, true], [false, true, true]] as $pass => [$distinctDays, $allowSmall, $useFallback]) {
        $strict = $pass < 2;
        $placed = [];
        $failed = false;
        foreach ($requests as $req) {
            $len = $req['minutes'];
            $usedDays = array_column($placed, 'day');
            $options = [];
            foreach (GEN_DAYS as $day) {
                if ($distinctDays && in_array($day, $usedDays, true)) {
                    continue;
                }
                $secIv = $occ->intervals('section', $sec['id'], $day);
                $teachIv = $teacherId !== null ? $occ->intervals('teacher', $teacherId, $day) : [];
                foreach (genStarts($len) as $s) {
                    $e = $s + $len;
                    if ($occ->clash('section', $sec['id'], $day, $s, $e) !== null) { continue; }
                    if ($teacherId !== null && $occ->clash('teacher', $teacherId, $day, $s, $e) !== null) { continue; }
                    $cost = genSlotCost($secIv, $teachIv, $teacherId !== null, $s, $e, $placed, $strict);
                    if ($cost === null) { continue; }
                    if ($placed && count($requests) === 2 && !genIsStdPair((string)$placed[0]['day'], $day)) {
                        $cost += 3.0;
                    }
                    $options[] = [$cost + mt_rand(0, 20) / 1000, $day, $s];
                }
            }
            usort($options, fn($a, $b) => $a[0] <=> $b[0]);
            $found = null;
            foreach ($options as [$cost, $day, $s]) {
                $room = genPickRoom($req, $day, $s, $s + $len, $sec['size'], $allowSmall, $occ, $ctx, $useFallback);
                if ($room === false) { continue; }
                $found = ['day' => $day, 's' => $s, 'e' => $s + $len, 'room_id' => $room[0], 'kind' => $req['kind']];
                break;
            }
            if ($found === null) {
                $failed = true;
                break;
            }
            genMark($occ, $found, $teacherId, $sec['id'], $ctx, $label);
            $placed[] = $found;
        }
        if (!$failed) {
            return $placed;
        }
        foreach ($placed as $m) {
            genUnmark($occ, $m, $teacherId, $sec['id'], $ctx);
        }
    }
    return null;
}

/** Classes still to schedule per section and year level: [section_id => [year => count]]. */
function genSectionPlan(PDO $pdo, array $ctx): array
{
    $stmt = $pdo->prepare('SELECT year_level, subject_id FROM Curriculum_subject WHERE curriculum_id = :c AND semester = :s');
    $plan = [];
    foreach ($ctx['sections'] as $sid => $sec) {
        $stmt->execute(['c' => $sec['curriculum_id'], 's' => $ctx['semester']]);
        $plan[$sid] = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        foreach ($stmt->fetchAll() as $r) {
            $y = (int)$r['year_level'];
            if ($y >= 1 && $y <= 4 && !isset($ctx['existing'][$sid . '-' . $r['subject_id']])) {
                $plan[$sid][$y]++;
            }
        }
    }
    return $plan;
}

/** Waiting, teacher stretches and single-class days of a set of draft rows. Used to compare attempts and to report. */
function genQuality(array $rows): array
{
    $sec = [];
    $teach = [];
    foreach ($rows as $r) {
        foreach ($r['meetings'] as $m) {
            $s = genMin((string)$m['start']);
            $e = genMin((string)$m['end']);
            if ($s === null || $e === null || $s >= $e || !in_array($m['day'], GEN_DAYS, true)) {
                continue;
            }
            $sec[$r['section_id'] . '|' . $m['day']][] = [$s, $e];
            if ($r['teacher_id'] !== null) {
                $teach[$r['teacher_id'] . '|' . $m['day']][] = [$s, $e];
            }
        }
    }
    $idle = 0;
    $long = 0;
    $longest = 0;
    $single = 0;
    foreach ($sec as $ivs) {
        usort($ivs, fn($a, $b) => $a[0] <=> $b[0]);
        if (count($ivs) === 1) {
            $single++;
        }
        foreach (genGaps($ivs) as $g) {
            $idle += $g;
            $long += $g >= GEN_LONG_WAIT ? 1 : 0;
            $longest = max($longest, $g);
        }
    }
    $chains = 0;
    foreach ($teach as $ivs) {
        usort($ivs, fn($a, $b) => $a[0] <=> $b[0]);
        $run = 1;
        for ($i = 1; $i < count($ivs); $i++) {
            if ($ivs[$i][0] - $ivs[$i - 1][1] < GEN_REST_MIN) {
                $run++;
            } else {
                $chains += $run > GEN_MAX_CHAIN ? 1 : 0;
                $run = 1;
            }
        }
        $chains += $run > GEN_MAX_CHAIN ? 1 : 0;
    }
    return ['idle' => $idle, 'long_waits' => $long, 'longest_wait' => $longest, 'teacher_chains' => $chains, 'single_days' => $single];
}

/** Lower is better, compared left to right: unplaced, no teacher, teacher stretches, long waits, single-class days, idle minutes. */
function genScore(array $result): array
{
    $q = genQuality($result['rows']);
    return [
        count($result['unplaced']),
        count(array_filter($result['rows'], fn($r) => $r['teacher_id'] === null)),
        $q['teacher_chains'], $q['long_waits'], $q['single_days'], $q['idle'],
    ];
}

/** One full pass over the tasks in its own random order. Returns ['rows', 'unplaced', 'timed_out']. */
function genAttempt(array $tasks, array $subjects, array $ctx, int $seed, float $deadline): array
{
    mt_srand($seed);
    shuffle($tasks);
    $order = $seed % 3;
    if ($order === 0) {
        usort($tasks, fn($a, $b) => $b['total'] <=> $a['total']);
    } elseif ($order === 1) {
        $tight = fn($t) => $t['cands'] === 0 ? PHP_INT_MAX : $t['cands'];
        usort($tasks, fn($a, $b) => [$tight($a), $b['total']] <=> [$tight($b), $a['total']]);
    } else {
        usort($tasks, fn($a, $b) => [$a['scarce'], $b['total']] <=> [$b['scarce'], $a['total']]);
    }

    $occ = clone $ctx['occ'];
    $loadNow = [];
    foreach ($ctx['teachers'] as $tid => $_) {
        $loadNow[$tid] = $ctx['load'][$tid] ?? ['units' => 0.0, 'hours' => 0.0];
    }

    $rows = [];
    $unplaced = [];
    $timedOut = false;
    $n = 0;
    foreach ($tasks as $task) {
        $sec = $task['section'];
        $sub = $subjects[$task['subject_id']];
        $info = ['section_id' => $sec['id'], 'subject_id' => $sub['id'], 'code' => $sub['code'], 'section' => $sec['label']];

        if (microtime(true) > $deadline) {
            $timedOut = true;
            $unplaced[] = $info + ['reason' => 'The time budget ran out. Run the generator again to fill the rest.'];
            continue;
        }

        $label = $sub['code'] . ' (draft)';
        $hours = $task['total'] / 60;
        $candidates = genCandidates($sub, $ctx, $loadNow);
        $withinLimits = array_values(array_filter($candidates, function ($c) use ($ctx, $loadNow, $sub, $hours) {
            $t = $ctx['teachers'][$c['id']];
            if ($loadNow[$c['id']]['units'] + $sub['units'] > $t['max_units']) { return false; }
            return $t['max_hours_per_week'] === null || $loadNow[$c['id']]['hours'] + $hours <= (float)$t['max_hours_per_week'];
        }));

        $teacherId = null;
        $meetings = null;
        foreach ($withinLimits as $c) {
            $meetings = $task['requests'] ? genPlaceMeetings($task['requests'], $c['id'], $sec, $label, $occ, $ctx) : [];
            if ($meetings !== null) {
                $teacherId = $c['id'];
                break;
            }
        }

        $note = '';
        if ($teacherId === null) {
            if (!$sub['tags']) {
                $note = 'This subject has no tags, so no teacher could be matched.';
            } elseif (!$candidates) {
                $note = 'No teacher has a tag that matches this subject.';
            } elseif (!$withinLimits) {
                $over = array_map(function ($c) use ($ctx, $loadNow, $sub, $hours) {
                    $t = $ctx['teachers'][$c['id']];
                    $u = $loadNow[$c['id']]['units'] + $sub['units'];
                    $h = $loadNow[$c['id']]['hours'] + $hours;
                    $bits = [];
                    if ($u > $t['max_units']) {
                        $bits[] = fmtLoad($u) . ' of ' . $t['max_units'] . ' units';
                    }
                    if ($t['max_hours_per_week'] !== null && $h > (float)$t['max_hours_per_week']) {
                        $bits[] = fmtLoad($h) . ' of ' . fmtLoad((float)$t['max_hours_per_week']) . ' hours a week';
                    }
                    return $t['name'] . ' (would reach ' . implode(' and ', $bits) . ')';
                }, array_slice($candidates, 0, 4));
                $more = count($candidates) - count($over);
                $note = 'Every teacher with a matching tag would go over a limit: ' . implode('; ', $over) . ($more > 0 ? '; and ' . $more . ' more' : '') . '.';
            } else {
                $names = array_map(fn($c) => $ctx['teachers'][$c['id']]['name'], array_slice($withinLimits, 0, 4));
                $more = count($withinLimits) - count($names);
                $note = 'Every matching teacher is busy at the times that fit: ' . implode(', ', $names) . ($more > 0 ? ', and ' . $more . ' more' : '') . '.';
            }
            $meetings = $task['requests'] ? genPlaceMeetings($task['requests'], null, $sec, $label, $occ, $ctx) : [];
        }
        if ($meetings === null) {
            $unplaced[] = $info + ['reason' => 'No free time and room was found for this class. Add rooms, or schedule it by hand.'];
            continue;
        }

        if ($teacherId !== null) {
            $loadNow[$teacherId]['units'] += $sub['units'];
            $loadNow[$teacherId]['hours'] += $hours;
        }
        $rows['r' . (++$n)] = [
            'key' => 'r' . $n, 'section_id' => $sec['id'], 'subject_id' => $sub['id'], 'teacher_id' => $teacherId, 'note' => $note,
            'meetings' => array_map(fn($m) => ['day' => $m['day'], 'start' => genHm($m['s']), 'end' => genHm($m['e']), 'room_id' => $m['room_id'], 'kind' => $m['kind']], $meetings),
        ];
    }

    uasort($rows, fn($a, $b) => [$ctx['sections'][$a['section_id']]['label'], $a['subject_id']] <=> [$ctx['sections'][$b['section_id']]['label'], $b['subject_id']]);
    return ['rows' => $rows, 'unplaced' => $unplaced, 'timed_out' => $timedOut];
}

/** The classes to schedule for the chosen sections and years, each with its weekly meeting requests. Returns [tasks, subjects]. */
function genBuildTasks(PDO $pdo, array $ctx, array $sectionIds, array $years): array
{
    $tasks = [];
    $stmt = $pdo->prepare(
        'SELECT subject_id FROM Curriculum_subject WHERE curriculum_id = :c AND year_level = :y AND semester = :s'
    );
    foreach ($sectionIds as $sid) {
        $sec = $ctx['sections'][$sid] ?? null;
        if (!$sec) {
            continue;
        }
        $year = (int)($years[$sid] ?? $sec['teach_year']);
        if ($year < 1 || $year > 4) {
            $year = $sec['teach_year'];
        }
        $stmt->execute(['c' => $sec['curriculum_id'], 'y' => $year, 's' => $ctx['semester']]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $subId) {
            if (!isset($ctx['existing'][$sid . '-' . $subId])) {
                $tasks[] = ['section' => $sec, 'subject_id' => (int)$subId];
            }
        }
    }
    $subjects = genSubjects($pdo, $ctx, array_column($tasks, 'subject_id'));

    foreach ($tasks as $i => $t) {
        $sub = $subjects[$t['subject_id']];
        $requests = [];
        foreach ($sub['parts'] as $p) {
            if ($p['hours'] <= 0) {
                continue;
            }
            for ($k = 0; $k < $p['meetings']; $k++) {
                $requests[] = ['kind' => $p['kind'], 'minutes' => (int)round($p['hours'] * 60), 'room' => $p['room'], 'fallback' => $p['fallback']];
            }
        }
        usort($requests, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
        $tasks[$i]['requests'] = $requests;
        $tasks[$i]['total'] = array_sum(array_column($requests, 'minutes'));
        $tasks[$i]['cands'] = count(genCandidates($sub, $ctx, []));
        $scarce = array_map(function ($r) use ($ctx) {
            $n = $r['room'] === null ? 0 : count($ctx['rooms_by_type'][$r['room']] ?? []);
            return $n === 0 ? PHP_INT_MAX : $n;
        }, $requests);
        $tasks[$i]['scarce'] = $scarce ? min($scarce) : PHP_INT_MAX;
    }

    return [$tasks, $subjects];
}

/**
 * Can the teachers and rooms cover the chosen sections? Estimates only, before anything is drafted.
 * Teachers: demand against what the teachers who could take the subject can still teach, per group of subjects
 * that share the same teachers. Rooms: blocks of time placed into what is left of each room's mornings and
 * afternoons (morning and afternoon are separate windows because of lunch).
 */
function genCapacity(PDO $pdo, array $ctx, array $sectionIds, array $years): array
{
    [$tasks, $subjects] = genBuildTasks($pdo, $ctx, $sectionIds, $years);
    $tagNames = [];
    foreach ($pdo->query('SELECT tag_id, tag_name FROM Tag')->fetchAll() as $t) {
        $tagNames[(int)$t['tag_id']] = $t['tag_name'];
    }

    $weekHours = GEN_DAY_CAP * count(GEN_DAYS) / 60;
    $groups = [];
    $noTeacher = [];
    foreach ($tasks as $t) {
        $sub = $subjects[$t['subject_id']];
        $ids = array_column(genCandidates($sub, $ctx, []), 'id');
        sort($ids);
        if (!$ids) {
            $noTeacher[$sub['code']] = true;
            continue;
        }
        $key = implode(',', $ids);
        if (!isset($groups[$key])) {
            $groups[$key] = ['ids' => $ids, 'units' => 0.0, 'hours' => 0.0, 'codes' => [], 'common' => null];
        }
        $groups[$key]['units'] += $sub['units'];
        $groups[$key]['hours'] += $t['total'] / 60;
        $groups[$key]['codes'][$sub['code']] = true;
        $groups[$key]['common'] = $groups[$key]['common'] === null ? $sub['tags'] : array_values(array_intersect($groups[$key]['common'], $sub['tags']));
    }
    $teacherLines = [];
    foreach ($groups as $g) {
        $haveUnits = 0.0;
        $haveHours = 0.0;
        foreach ($g['ids'] as $tid) {
            $tc = $ctx['teachers'][$tid];
            $load = $ctx['load'][$tid] ?? ['units' => 0.0, 'hours' => 0.0];
            $haveUnits += max(0.0, $tc['max_units'] - $load['units']);
            $cap = $tc['max_hours_per_week'] === null ? $weekHours : min($weekHours, (float)$tc['max_hours_per_week']);
            $haveHours += max(0.0, $cap - $load['hours']);
        }
        $names = array_map(fn($i) => $tagNames[$i] ?? '', $g['common'] ?: []);
        $codes = array_keys($g['codes']);
        $teacherLines[] = [
            'label' => implode(' / ', array_filter($names)) ?: $codes[0],
            'codes' => $codes, 'teachers' => count($g['ids']),
            'need_units' => $g['units'], 'have_units' => $haveUnits, 'need_hours' => $g['hours'], 'have_hours' => $haveHours,
            'short' => $g['units'] > $haveUnits + 0.0001 || $g['hours'] > $haveHours + 0.0001,
            'tight' => $g['units'] > $haveUnits * 0.9 || $g['hours'] > $haveHours * 0.9,
        ];
    }
    usort($teacherLines, fn($a, $b) => [$b['short'], $b['tight']] <=> [$a['short'], $a['tight']]);

    $bins = [];
    foreach ($ctx['rooms'] as $rid => $room) {
        if (!$room['active'] || $room['unlimited']) {
            continue;
        }
        foreach (GEN_DAYS as $day) {
            $busy = $ctx['occ']->intervals('room', $rid, $day);
            foreach ([[GEN_DAY_START, GEN_LUNCH_START], [GEN_LUNCH_END, GEN_DAY_END]] as [$ws, $we]) {
                $free = $we - $ws;
                foreach ($busy as [$bs, $be]) {
                    $free -= max(0, min($we, $be) - max($ws, $bs));
                }
                $bins[] = ['type' => $room['type_id'], 'cap' => $room['capacity'], 'free' => max(0, $free)];
            }
        }
    }
    $items = [];
    foreach ($tasks as $t) {
        foreach ($t['requests'] as $r) {
            if ($r['room'] !== null && !($ctx['types'][$r['room']]['unlimited'] ?? false)) {
                $items[] = ['minutes' => $r['minutes'], 'room' => $r['room'], 'fallback' => $r['fallback'], 'size' => $t['section']['size']];
            }
        }
    }
    usort($items, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
    $roomStats = [];
    foreach ($items as $it) {
        $name = $ctx['types'][$it['room']]['name'] ?? 'Room';
        $roomStats[$name] ??= ['type' => $name, 'needed' => 0, 'fits' => 0, 'fallback' => 0, 'minutes' => 0];
        $roomStats[$name]['needed']++;
        $roomStats[$name]['minutes'] += $it['minutes'];
        foreach ([$it['room'], $it['fallback']] as $n => $typeId) {
            if ($typeId === null) {
                continue;
            }
            $best = null;
            foreach ($bins as $bi => $bin) {
                if ($bin['type'] !== $typeId || $bin['free'] < $it['minutes'] || ($bin['cap'] !== null && $bin['cap'] < $it['size'])) {
                    continue;
                }
                if ($best === null || $bin['free'] < $bins[$best]['free']) {
                    $best = $bi;
                }
            }
            if ($best !== null) {
                $bins[$best]['free'] -= $it['minutes'];
                $roomStats[$name]['fits']++;
                $roomStats[$name]['fallback'] += $n;
                break;
            }
        }
    }
    $roomLines = array_values($roomStats);
    usort($roomLines, fn($a, $b) => [$b['needed'] - $b['fits'], $b['needed']] <=> [$a['needed'] - $a['fits'], $a['needed']]);

    return ['classes' => count($tasks), 'teachers' => $teacherLines, 'no_teacher' => array_keys($noTeacher), 'rooms' => $roomLines];
}

/**
 * Build a draft for the chosen sections. $years maps section_id to the year level to schedule (default: the
 * section's teaching year). Several arrangements are tried inside the time budget and the best one is kept.
 * Returns ['rows' => [...], 'unplaced' => [...], 'attempts' => n]. Nothing is saved.
 */
function genGenerate(PDO $pdo, array $ctx, array $sectionIds, int $seed, array $years = [], float $budgetSeconds = 10.0): array
{
    $deadline = microtime(true) + $budgetSeconds;

    [$tasks, $subjects] = genBuildTasks($pdo, $ctx, $sectionIds, $years);

    $best = null;
    $attempts = 0;
    do {
        $result = genAttempt($tasks, $subjects, $ctx, $seed + $attempts, $deadline);
        $attempts++;
        if ($best === null || (!$result['timed_out'] && (genScore($result) <=> genScore($best)) < 0)) {
            $best = $result;
        }
        $settled = $best['unplaced'] === [] && $attempts >= GEN_MIN_ATTEMPTS;
    } while (!$result['timed_out'] && !$settled && $attempts < GEN_MAX_ATTEMPTS && microtime(true) < $deadline);

    return ['rows' => $best['rows'], 'unplaced' => $best['unplaced'], 'attempts' => $attempts];
}

/**
 * Check a draft the same way for generated and hand-edited rows. Adds 'errors' (hard, block saving) and
 * 'warnings' (soft) to every row. Returns the annotated rows and the counts.
 */
function genValidate(PDO $pdo, array $ctx, array $rows): array
{
    $subjects = genSubjects($pdo, $ctx, array_column($rows, 'subject_id'));
    $occ = clone $ctx['occ'];
    $seen = [];
    $draftLoad = [];

    foreach ($rows as $key => &$row) {
        $row['errors'] = [];
        $row['warnings'] = [];
        $sec = $ctx['sections'][$row['section_id']] ?? null;
        $sub = $subjects[$row['subject_id']] ?? null;
        if (!$sec || !$sub) {
            $row['errors'][] = 'This class is no longer valid. Remove it.';
            continue;
        }
        $label = $sub['code'] . ' (draft)';
        $classKey = $sec['id'] . '-' . $sub['id'];
        if (isset($ctx['existing'][$classKey])) {
            $row['errors'][] = 'This section already has a class for this subject this term.';
        }
        if (isset($seen[$classKey])) {
            $row['errors'][] = 'This class is listed twice in the draft.';
        }
        $seen[$classKey] = true;

        $tid = $row['teacher_id'];
        if ($tid === null) {
            $row['errors'][] = 'Assign a teacher before saving.' . ($row['note'] !== '' ? ' ' . $row['note'] : '');
        } elseif (!isset($ctx['teachers'][$tid])) {
            $row['errors'][] = 'That teacher is not an active teacher of your department.';
        } else {
            $level = tagMatchLevel($sub['tags'], $ctx['teachers'][$tid]['tags']);
            if ($level === 'none' || $level === 'untagged') {
                $row['warnings'][] = $level === 'untagged' ? 'The subject has no tags, so the teacher could not be checked.' : 'This teacher has none of the subject\'s tags.';
            } elseif ($level === 'partial') {
                $row['warnings'][] = 'The teacher matches only some of the subject\'s tags.';
            }
        }

        $preferred = array_filter(array_column($sub['parts'], 'room'));
        $own = [];
        foreach ($row['meetings'] as $mi => $m) {
            $s = genMin((string)$m['start']);
            $e = genMin((string)$m['end']);
            $day = (string)$m['day'];
            if (!in_array($day, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], true) || $s === null || $e === null || $s >= $e) {
                $row['errors'][] = 'Meeting ' . ($mi + 1) . ' needs a day and a start time before its end time.';
                continue;
            }
            foreach ($own as [$od, $os, $oe]) {
                if ($od === $day && $s < $oe && $os < $e) {
                    $row['errors'][] = 'Two meetings of this class overlap on ' . $day . '.';
                }
            }
            $own[] = [$day, $s, $e];
            $when = $day . ' ' . genFmtTime($s) . ' to ' . genFmtTime($e);

            if (($hit = $occ->clash('section', $sec['id'], $day, $s, $e)) !== null) {
                $row['errors'][] = 'Section ' . $sec['label'] . ' already has ' . $hit . ' on ' . $when . '.';
            }
            if ($tid !== null && ($hit = $occ->clash('teacher', (int)$tid, $day, $s, $e)) !== null) {
                $row['errors'][] = 'The teacher is already teaching ' . $hit . ' on ' . $when . '.';
            }
            if ($tid !== null && genChainLength($occ->intervals('teacher', (int)$tid, $day), $s, $e) > GEN_MAX_CHAIN) {
                $row['warnings'][] = 'The teacher would teach more than ' . GEN_MAX_CHAIN . ' classes in a row on ' . $day . ' (' . $when . '). Leave a break so they can rest or check activities.';
            }
            $roomId = ($m['room_id'] ?? null) !== null && $m['room_id'] !== '' ? (int)$m['room_id'] : null;
            if ($roomId !== null) {
                $room = $ctx['rooms'][$roomId] ?? null;
                if (!$room) {
                    $row['errors'][] = 'Pick a valid room for meeting ' . ($mi + 1) . '.';
                } else {
                    if (!$room['unlimited'] && ($hit = $occ->clash('room', $roomId, $day, $s, $e)) !== null) {
                        $row['errors'][] = 'Room ' . $room['code'] . ' is already booked for ' . $hit . ' on ' . $when . '.';
                    }
                    if (!$room['active']) {
                        $row['warnings'][] = 'Room ' . $room['code'] . ' is inactive.';
                    }
                    if ($preferred && !in_array($room['type_id'], $preferred, true)) {
                        $typeNames = fn(array $ids) => implode(' or ', array_unique(array_map(fn($i) => $ctx['types'][$i]['name'] ?? 'another type', $ids)));
                        $fallbackIds = array_filter(array_column($sub['parts'], 'fallback'));
                        $roomType = $ctx['types'][$room['type_id']]['name'] ?? 'another type';
                        $isFallback = $fallbackIds && in_array($room['type_id'], $fallbackIds, true);
                        $row['warnings'][] = 'Room ' . $room['code'] . ' (' . $roomType . ') is ' . ($isFallback ? 'the fallback type, not the preferred room type' : 'not the preferred room type')
                            . '. This subject prefers ' . $typeNames($preferred)
                            . ($fallbackIds && !$isFallback ? ', with ' . $typeNames($fallbackIds) . ' as the fallback' : '') . '.';
                    }
                    if (!$room['unlimited'] && $room['capacity'] !== null && $room['capacity'] < $sec['size']) {
                        $row['warnings'][] = 'Room ' . $room['code'] . ' seats ' . $room['capacity'] . ', but the section has about ' . $sec['size'] . ' students.';
                    }
                }
            } else {
                $row['warnings'][] = 'Meeting ' . ($mi + 1) . ' has no room yet.';
            }
            if ($day === 'Saturday' || $s < GEN_DAY_START || $e > GEN_DAY_END || ($s < GEN_LUNCH_END && $e > GEN_LUNCH_START)) {
                $row['warnings'][] = $when . ' is outside school days or hours (Monday to Friday, 7:30 AM to 5:30 PM, no classes 11:30 AM to 12:30 PM).';
            }

            $occ->add('section', $sec['id'], $day, $s, $e, $label);
            if ($tid !== null) {
                $occ->add('teacher', (int)$tid, $day, $s, $e, $label);
            }
            if ($roomId !== null && isset($ctx['rooms'][$roomId]) && !$ctx['rooms'][$roomId]['unlimited']) {
                $occ->add('room', $roomId, $day, $s, $e, $label);
            }
            $draftLoad[(int)$tid]['hours'] = ($draftLoad[(int)$tid]['hours'] ?? 0) + ($e - $s) / 60;
        }
        if ($tid !== null && isset($ctx['teachers'][$tid])) {
            $draftLoad[(int)$tid]['units'] = ($draftLoad[(int)$tid]['units'] ?? 0) + $sub['units'];
            $draftLoad[(int)$tid]['keys'][] = $key;
        }
        $row['warnings'] = array_values(array_unique($row['warnings']));
        $row['errors'] = array_values(array_unique($row['errors']));
    }
    unset($row);

    foreach ($draftLoad as $tid => $d) {
        if (!isset($ctx['teachers'][$tid]) || empty($d['keys'])) {
            continue;
        }
        $base = $ctx['load'][$tid] ?? ['units' => 0.0, 'hours' => 0.0];
        $over = loadWarningText($ctx['teachers'][$tid] + ['max_hours_per_week' => $ctx['teachers'][$tid]['max_hours_per_week']],
            ['units' => $base['units'] + ($d['units'] ?? 0), 'hours' => $base['hours'] + ($d['hours'] ?? 0), 'classes' => 0]);
        if ($over !== '') {
            foreach ($d['keys'] as $k) {
                $rows[$k]['warnings'][] = 'Teacher would be over their limit this term: ' . $over . '.';
            }
        }
    }

    $blockers = 0;
    $conflicts = 0;
    $needTeacher = 0;
    $warned = 0;
    foreach ($rows as $r) {
        if ($r['errors']) { $blockers++; }
        if (array_filter($r['errors'], fn($m) => strpos($m, 'Assign a teacher before saving.') !== 0)) { $conflicts++; }
        if ($r['teacher_id'] === null) { $needTeacher++; }
        if ($r['warnings']) { $warned++; }
    }
    return ['rows' => $rows, 'subjects' => $subjects, 'blockers' => $blockers, 'conflicts' => $conflicts, 'need_teacher' => $needTeacher, 'warned' => $warned];
}

/**
 * Save a draft as ordinary classes. Takes a lock so two registrars cannot save at once, rebuilds the context
 * so every conflict is checked against what is stored right now, and writes everything in one transaction.
 * Returns ['saved' => n, 'run_id' => id] or an error message.
 */
function genSave(PDO $pdo, int $termId, int $departmentId, array $rows, int $accountId): array|string
{
    $got = (int)$pdo->query("SELECT GET_LOCK('mist_generate_classes', 8)")->fetchColumn();
    if ($got !== 1) {
        return 'Another registrar is saving classes right now. Try again in a moment.';
    }
    try {
        $ctx = genLoadContext($pdo, $termId, $departmentId);
        $check = genValidate($pdo, $ctx, $rows);
        if ($check['blockers'] > 0 || $check['need_teacher'] > 0) {
            return 'The draft changed since you last checked it. Press Check again and fix what is marked.';
        }
        if (!$rows) {
            return 'There is nothing to save.';
        }
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO Generation_run (term_id, created_by_account_id) VALUES (:t, :a)')->execute(['t' => $termId, 'a' => $accountId]);
        $runId = (int)$pdo->lastInsertId();
        $insClass = $pdo->prepare('INSERT INTO Class_Offering (subject_id, teacher_id, section_id, term_id, generation_run_id) VALUES (:sub, :t, :sec, :term, :run)');
        $insMeeting = $pdo->prepare('INSERT INTO Class_Meeting (offering_id, day_of_week, start_time, end_time, room_id) VALUES (:o, :d, :s, :e, :r)');
        foreach ($check['rows'] as $r) {
            $insClass->execute(['sub' => $r['subject_id'], 't' => $r['teacher_id'], 'sec' => $r['section_id'], 'term' => $termId, 'run' => $runId]);
            $offeringId = (int)$pdo->lastInsertId();
            foreach ($r['meetings'] as $m) {
                $insMeeting->execute([
                    'o' => $offeringId, 'd' => $m['day'], 's' => genHm((int)genMin($m['start'])), 'e' => genHm((int)genMin($m['end'])),
                    'r' => ($m['room_id'] ?? null) !== null && $m['room_id'] !== '' ? (int)$m['room_id'] : null,
                ]);
            }
        }
        $pdo->commit();
        return ['saved' => count($rows), 'run_id' => $runId];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return errorMessage($e, 'Could not save the classes.');
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('mist_generate_classes')")->fetchColumn();
    }
}

/** Remove the classes of one run that nobody is enrolled in. Classes with students stay. */
function genUndoRun(PDO $pdo, int $runId, int $departmentId): array
{
    $base = "FROM Class_Offering co
             JOIN Section sec ON sec.section_id = co.section_id
             JOIN Program p ON p.program_id = sec.program_id
             JOIN School_term st ON st.term_id = co.term_id AND st.status = 'ongoing'
             WHERE co.generation_run_id = :run AND p.department_id = :d";
    $stmt = $pdo->prepare("SELECT COUNT(*) $base");
    $stmt->execute(['run' => $runId, 'd' => $departmentId]);
    $total = (int)$stmt->fetchColumn();

    $del = $pdo->prepare(
        "DELETE co $base AND NOT EXISTS (SELECT 1 FROM Enrolled_subject es WHERE es.offering_id = co.offering_id)"
    );
    $del->execute(['run' => $runId, 'd' => $departmentId]);
    $removed = $del->rowCount();
    return ['removed' => $removed, 'kept' => $total - $removed];
}
