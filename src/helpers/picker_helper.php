<?php
require_once __DIR__ . '/academic_helper.php';

/**
 * Subjects a student could still take, for the shared checkbox picker.
 * $sectionId limits offerings to one section; $rosterEnrollmentId hides subjects already on that roster.
 */
function buildPickerRows(PDO $pdo, int $studentId, int $curriculumId, int $termId, ?int $sectionId = null, ?int $rosterEnrollmentId = null): array
{
    $stmt = $pdo->prepare(
        'SELECT s.subject_id, s.subject_code, s.subject_name, s.units, MIN(cs.year_level) AS year_level, MIN(cs.semester) AS semester
         FROM Curriculum_subject cs JOIN Subject s ON s.subject_id = cs.subject_id
         WHERE cs.curriculum_id = :cid
         GROUP BY s.subject_id, s.subject_code, s.subject_name, s.units
         ORDER BY year_level, semester, s.subject_code'
    );
    $stmt->execute(['cid' => $curriculumId]);
    $subjects = $stmt->fetchAll();

    $onRoster = [];
    if ($rosterEnrollmentId) {
        $r = $pdo->prepare(
            'SELECT co.subject_id FROM Enrolled_subject es JOIN Class_Offering co ON co.offering_id = es.offering_id
             WHERE es.enrollment_id = :eid'
        );
        $r->execute(['eid' => $rosterEnrollmentId]);
        $onRoster = array_flip(array_map('intval', $r->fetchAll(PDO::FETCH_COLUMN)));
    }

    $prereqStmt = $pdo->prepare(
        'SELECT r.subject_id, r.subject_code FROM Prerequisite p
         JOIN Subject r ON r.subject_id = p.prerequisite_subject_id WHERE p.subject_id = :sid'
    );
    $offeringSql = 'SELECT co.offering_id, co.day_of_week, co.start_time, co.end_time, co.room, sec.section_name, sec.year_level
                    FROM Class_Offering co JOIN Section sec ON sec.section_id = co.section_id
                    WHERE co.subject_id = :subid AND co.term_id = :tid'
                 . ($sectionId ? ' AND co.section_id = :secid' : '')
                 . ' ORDER BY sec.year_level, sec.section_name, co.start_time';
    $offeringStmt = $pdo->prepare($offeringSql);

    $rows = [];
    foreach ($subjects as $subj) {
        $sid = (int)$subj['subject_id'];
        if (isset($onRoster[$sid]) || hasTakenOrCreditedSubject($pdo, $studentId, $sid)) {
            continue;
        }

        $prereqStmt->execute(['sid' => $sid]);
        $missing = [];
        foreach ($prereqStmt->fetchAll() as $req) {
            if (!hasCompletedSubject($pdo, $studentId, (int)$req['subject_id'])) {
                $missing[] = $req['subject_code'];
            }
        }

        $params = ['subid' => $sid, 'tid' => $termId];
        if ($sectionId) { $params['secid'] = $sectionId; }
        $offeringStmt->execute($params);
        $offerings = $offeringStmt->fetchAll();

        $rows[] = [
            'subject_id' => $sid,
            'subject_code' => $subj['subject_code'],
            'subject_name' => $subj['subject_name'],
            'units' => $subj['units'],
            'year_level' => (int)$subj['year_level'],
            'semester' => (int)$subj['semester'],
            'missing_prereqs' => $missing,
            'offerings' => $offerings,
            'locked' => $missing !== [] || $offerings === [],
        ];
    }
    return $rows;
}

/** Offering ids the person actually ticked, each re-checked against the row it belongs to. */
function pickerSelectedOfferings(array $rows, array $post): array
{
    $picked = $post['pick'] ?? [];
    $chosen = $post['offering'] ?? [];
    $ids = [];
    foreach ($rows as $row) {
        $sid = $row['subject_id'];
        if ($row['locked'] || empty($picked[$sid])) {
            continue;
        }
        $oid = (int)($chosen[$sid] ?? 0);
        foreach ($row['offerings'] as $o) {
            if ((int)$o['offering_id'] === $oid) {
                $ids[] = $oid;
                break;
            }
        }
    }
    return $ids;
}
