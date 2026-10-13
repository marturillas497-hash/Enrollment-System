<?php
/**
 * Teacher load. Load is counted per class (one subject for one section), not per meeting:
 * units are the subject's units, hours are the weekly meeting time of those classes.
 */

function ongoingTermId(PDO $pdo): int
{
    return (int)$pdo->query("SELECT term_id FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC LIMIT 1")->fetchColumn();
}

/** One teacher's load in a term: ['classes' => n, 'units' => n, 'hours' => n]. */
function teacherLoad(PDO $pdo, int $teacherId, int $termId): array
{
    $map = teacherLoadMap($pdo, $termId, $teacherId);
    return $map[$teacherId] ?? ['classes' => 0, 'units' => 0.0, 'hours' => 0.0];
}

/** Load of every teacher (or just $onlyTeacherId) in a term, keyed by teacher_id. */
function teacherLoadMap(PDO $pdo, int $termId, int $onlyTeacherId = 0): array
{
    $map = [];
    $filter = $onlyTeacherId > 0 ? ' AND co.teacher_id = :only' : '';
    $params = ['term' => $termId] + ($onlyTeacherId > 0 ? ['only' => $onlyTeacherId] : []);

    $stmt = $pdo->prepare(
        "SELECT co.teacher_id, COUNT(*) AS classes, COALESCE(SUM(s.units), 0) AS units
         FROM Class_Offering co JOIN Subject s ON s.subject_id = co.subject_id
         WHERE co.term_id = :term$filter GROUP BY co.teacher_id"
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $map[(int)$r['teacher_id']] = ['classes' => (int)$r['classes'], 'units' => (float)$r['units'], 'hours' => 0.0];
    }

    $stmt = $pdo->prepare(
        "SELECT co.teacher_id, COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(cm.end_time, cm.start_time))), 0) / 3600 AS hours
         FROM Class_Meeting cm JOIN Class_Offering co ON co.offering_id = cm.offering_id
         WHERE co.term_id = :term$filter GROUP BY co.teacher_id"
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll() as $r) {
        $tid = (int)$r['teacher_id'];
        $map[$tid] ??= ['classes' => 0, 'units' => 0.0, 'hours' => 0.0];
        $map[$tid]['hours'] = (float)$r['hours'];
    }
    return $map;
}

function fmtLoad(float $n): string
{
    return rtrim(rtrim(number_format($n, 2), '0'), '.');
}

/** Empty string when the load fits the teacher's limits, otherwise a plain sentence. */
function loadWarningText(array $teacher, array $load): string
{
    $parts = [];
    if ($load['units'] > (float)$teacher['max_units']) {
        $parts[] = fmtLoad($load['units']) . ' units against a limit of ' . (int)$teacher['max_units'];
    }
    if ($teacher['max_hours_per_week'] !== null && $load['hours'] > (float)$teacher['max_hours_per_week']) {
        $parts[] = fmtLoad($load['hours']) . ' hours a week against a limit of ' . fmtLoad((float)$teacher['max_hours_per_week']);
    }
    return $parts ? implode(' and ', $parts) : '';
}

/** Max units (whole number 1 to 60) and an optional weekly hours cap (blank means no cap). Returns the values or an error. */
function validateTeacherCaps(string $unitsRaw, string $hoursRaw): array|string
{
    $unitsRaw = trim($unitsRaw);
    $hoursRaw = trim($hoursRaw);
    if ($unitsRaw === '' || !ctype_digit($unitsRaw) || (int)$unitsRaw < 1 || (int)$unitsRaw > 60) {
        return 'Max units must be a whole number from 1 to 60.';
    }
    $hours = null;
    if ($hoursRaw !== '') {
        if (!is_numeric($hoursRaw) || (float)$hoursRaw < 1 || (float)$hoursRaw > 80 || abs((float)$hoursRaw * 2 - round((float)$hoursRaw * 2)) > 0.0001) {
            return 'Weekly hours cap must be from 1 to 80 in steps of half an hour, or left empty for no cap.';
        }
        $hours = (float)$hoursRaw;
    }
    return ['units' => (int)$unitsRaw, 'hours' => $hours];
}

/** Classes each teacher account has in the ongoing term, as short labels keyed by account_id. */
function ongoingClassLinesByAccount(PDO $pdo, array $accountIds): array
{
    $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
    if (!$accountIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($accountIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT t.account_id, s.subject_code, sec.section_name, sec.year_level
         FROM Teacher t
         JOIN Class_Offering co ON co.teacher_id = t.teacher_id
         JOIN School_term st ON st.term_id = co.term_id AND st.status = 'ongoing'
         JOIN Subject s ON s.subject_id = co.subject_id
         JOIN Section sec ON sec.section_id = co.section_id
         WHERE t.account_id IN ($in) ORDER BY s.subject_code, sec.year_level, sec.section_name"
    );
    $stmt->execute($accountIds);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['account_id']][] = $r['subject_code'] . ' (' . sectionLabel($r['year_level'], $r['section_name']) . ')';
    }
    return $out;
}
