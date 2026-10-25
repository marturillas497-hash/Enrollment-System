<?php
/**
 * True if this student has ever passed the given subject, in any enrollment.
 * The grade is the only authority: 3.00 or better passes, anything above fails,
 * and a row with no grade (Dropped, Incomplete or not yet graded) never passes.
 */
function hasPassedSubject(PDO $pdo, int $studentId, int $subjectId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM Enrolled_subject es
         JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         WHERE e.student_id = :sid AND co.subject_id = :subid
           AND es.grade IS NOT NULL AND es.grade <= 3.00
         LIMIT 1"
    );
    $stmt->execute(['sid' => $studentId, 'subid' => $subjectId]);
    return $stmt->fetch() !== false;
}

/**
 * True if this student has ANY record (passed or not) of taking this subject —
 * used to avoid re-offering something they already completed, credited or not.
 */
function hasTakenOrCreditedSubject(PDO $pdo, int $studentId, int $subjectId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM Enrolled_subject es
         JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         WHERE e.student_id = :sid1 AND co.subject_id = :subid1 AND es.grade IS NOT NULL
         UNION
         SELECT 1 FROM Shift_credit sc
         JOIN Program_shift_request psr ON psr.request_id = sc.request_id
         WHERE psr.student_id = :sid2 AND sc.credited_subject_id = :subid2 AND psr.status = 'approved'
         UNION
         SELECT 1 FROM Transferee_credit tc
         WHERE tc.student_id = :sid3 AND tc.credited_subject_id = :subid3
         LIMIT 1"
    );
    $stmt->execute([
        'sid1' => $studentId, 'subid1' => $subjectId,
        'sid2' => $studentId, 'subid2' => $subjectId,
        'sid3' => $studentId, 'subid3' => $subjectId,
    ]);
    return $stmt->fetch() !== false;
}

/**
 * Every subject the student has completed, as [subject_id => true], in one query:
 * a graded pass (3.00 or better), an approved shift credit, or a transferee credit of 3.00 or better.
 */
function completedSubjectSet(PDO $pdo, int $studentId): array
{
    $stmt = $pdo->prepare(
        "SELECT co.subject_id FROM Enrolled_subject es
         JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         WHERE e.student_id = :sid1 AND es.grade IS NOT NULL AND es.grade <= 3.00
         UNION
         SELECT sc.credited_subject_id FROM Shift_credit sc
         JOIN Program_shift_request psr ON psr.request_id = sc.request_id
         WHERE psr.student_id = :sid2 AND psr.status = 'approved'
         UNION
         SELECT tc.credited_subject_id FROM Transferee_credit tc
         WHERE tc.student_id = :sid3 AND tc.previous_grade <= 3.00"
    );
    $stmt->execute(['sid1' => $studentId, 'sid2' => $studentId, 'sid3' => $studentId]);
    return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
}

/**
 * Year standing, computed in one pass over the curriculum: a student has N-year standing when every
 * subject in the years before N (all semesters) is completed. Failed, dropped, incomplete and ongoing
 * subjects do not count. Range 1 to 4. Pass $completed to reuse a set already loaded.
 */
function studentStanding(PDO $pdo, int $studentId, int $curriculumId, ?array $completed = null): int
{
    $completed ??= completedSubjectSet($pdo, $studentId);
    $stmt = $pdo->prepare('SELECT year_level, subject_id FROM Curriculum_subject WHERE curriculum_id = :cid');
    $stmt->execute(['cid' => $curriculumId]);
    $byYear = [];
    foreach ($stmt->fetchAll() as $row) {
        $byYear[(int)$row['year_level']][] = (int)$row['subject_id'];
    }
    $standing = 1;
    for ($year = 1; $year <= 3; $year++) {
        foreach ($byYear[$year] ?? [] as $subjectId) {
            if (!isset($completed[$subjectId])) {
                return $standing;
            }
        }
        $standing = $year + 1;
    }
    return $standing;
}

function standingLabel(int $standing): string
{
    $names = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th'];
    return ($names[$standing] ?? $standing . 'th') . ' year standing';
}

/** True if the student has any Enrolled_subject row for this subject, graded or not. */
function hasEnrolledRecord(PDO $pdo, int $studentId, int $subjectId): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM Enrolled_subject es
         JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         WHERE e.student_id = :sid AND co.subject_id = :subid LIMIT 1'
    );
    $stmt->execute(['sid' => $studentId, 'subid' => $subjectId]);
    return $stmt->fetch() !== false;
}

/**
 * True if the student has a failed, dropped or incomplete attempt at this subject,
 * which makes it a retake rather than a first take.
 */
function hasFailedAttempt(PDO $pdo, int $studentId, int $subjectId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM Enrolled_subject es
         JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         WHERE e.student_id = :sid AND co.subject_id = :subid
           AND (es.grade > 3.00 OR (es.grade IS NULL AND es.remarks IN ('Failed','Dropped','Incomplete')))
         LIMIT 1"
    );
    $stmt->execute(['sid' => $studentId, 'subid' => $subjectId]);
    return $stmt->fetch() !== false;
}

/**
 * How many active students currently call this section "home" — i.e. their most
 * recent Enrollment row points here. Used for capacity warnings at the two places
 * a student is newly assigned to a section (place-student.php, shift approval) —
 * NOT at self-enroll, since continuing students already hold their seat and
 * re-checking every term would treat every returning student as a new occupant.
 */
function sectionOccupancy(PDO $pdo, int $sectionId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM Student s
         JOIN Enrollment e ON e.enrollment_id = (
             SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id AND e2.status <> 'rejected'
             ORDER BY e2.enrollment_id DESC LIMIT 1
         )
         WHERE e.section_id = :sid AND s.overall_status = 'active'"
    );
    $stmt->execute(['sid' => $sectionId]);
    return (int)$stmt->fetchColumn();
}

/**
 * True if this student has completed the given subject well enough to satisfy it
 * as a prerequisite — an actual MIST grade (hasPassedSubject), an internal shift
 * credit (which only ever references an already-passed subject per the PRD), or a
 * transferee credit graded 3.00 or better at their previous school. hasPassedSubject
 * alone misses the latter two, which matters for the irregular enrollment picker and
 * transferee-credit.php's "add remaining subject" picker.
 */
function hasCompletedSubject(PDO $pdo, int $studentId, int $subjectId): bool
{
    if (hasPassedSubject($pdo, $studentId, $subjectId)) {
        return true;
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM Shift_credit sc
         JOIN Program_shift_request psr ON psr.request_id = sc.request_id
         WHERE psr.student_id = :sid1 AND sc.credited_subject_id = :subid1 AND psr.status = 'approved'
         UNION
         SELECT 1 FROM Transferee_credit tc
         WHERE tc.student_id = :sid2 AND tc.credited_subject_id = :subid2 AND tc.previous_grade <= 3.00
         LIMIT 1"
    );
    $stmt->execute([
        'sid1' => $studentId, 'subid1' => $subjectId,
        'sid2' => $studentId, 'subid2' => $subjectId,
    ]);
    return $stmt->fetch() !== false;
}

/**
 * True if $subjectId already (directly or transitively) requires $prereqId as
 * a prerequisite inside this curriculum, meaning adding "subjectId requires
 * prereqId" would close a loop. Walks the curriculum's whole prerequisite graph
 * (BFS), not just the direct pair, so it catches chains of any length.
 */
function prerequisiteWouldCreateCycle(PDO $pdo, int $curriculumId, int $subjectId, int $prereqId): bool
{
    $stmt = $pdo->prepare('SELECT prerequisite_subject_id FROM Curriculum_prerequisite WHERE curriculum_id = :cid AND subject_id = :id');

    $visited = [];
    $queue = [$prereqId];
    while ($queue) {
        $current = array_shift($queue);
        if ($current === $subjectId) {
            return true;
        }
        if (isset($visited[$current])) {
            continue;
        }
        $visited[$current] = true;

        $stmt->execute(['cid' => $curriculumId, 'id' => $current]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $next) {
            $queue[] = (int)$next;
        }
    }
    return false;
}

/**
 * Whether a student can file a program shift request right now, and if not, why.
 * Shared by the navbar (lock icon + tooltip) and student/shift-request.php.
 *
 * @return array{available: bool, reason: string}
 */
function shiftRequestStatus(PDO $pdo, array $student): array
{
    if ($student['overall_status'] !== 'active') {
        return ['available' => false, 'reason' => 'Your account is not active. Contact the registrar.'];
    }
    $term = $pdo->query("SELECT term_id FROM School_term WHERE status = 'ongoing' ORDER BY term_id DESC LIMIT 1")->fetch();
    if (!$term) {
        return ['available' => false, 'reason' => 'Available while a term is open.'];
    }
    $stmt = $pdo->prepare("SELECT 1 FROM Program_shift_request WHERE student_id = :sid AND status = 'pending'");
    $stmt->execute(['sid' => $student['student_id']]);
    if ($stmt->fetch() !== false) {
        return ['available' => false, 'reason' => 'You already have a pending shift request.'];
    }
    $stmt = $pdo->prepare('SELECT status, source_shift_request_id FROM Enrollment WHERE student_id = :sid AND term_id = :tid');
    $stmt->execute(['sid' => $student['student_id'], 'tid' => $term['term_id']]);
    $enrollment = $stmt->fetch();
    if ($enrollment) {
        if ($enrollment['status'] === 'rejected') {
            return ['available' => false, 'reason' => 'Your last subject selection was rejected. Start over from your dashboard first.'];
        }
        if ($enrollment['status'] === 'pending' && !$enrollment['source_shift_request_id']) {
            return ['available' => true, 'reason' => 'You have a pending subject selection. Withdraw it to request a shift instead.'];
        }
        return ['available' => false, 'reason' => 'Available before you enroll in the open term.'];
    }
    return ['available' => true, 'reason' => ''];
}

/** Highest year level the curriculum has subjects for. */
function curriculumMaxYear(PDO $pdo, int $curriculumId): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(year_level), 0) FROM Curriculum_subject WHERE curriculum_id = :cid');
    $stmt->execute(['cid' => $curriculumId]);
    return (int)$stmt->fetchColumn();
}

/** Year level a regular student lands in: it advances only when crossing into semester 1 of a new school year. */
function nextYearLevel(int $currentYear, array $term, string $previousSchoolYear): int
{
    if ((int)$term['semester'] === 1 && $term['school_year'] !== $previousSchoolYear) {
        return $currentYear + 1;
    }
    return $currentYear;
}

/**
 * Subject codes in the curriculum that the student has not completed yet, by a passing grade,
 * an approved shift credit or a passing transferee credit. Empty means ready to graduate.
 */
function missingCurriculumSubjects(PDO $pdo, int $studentId, int $curriculumId): array
{
    $stmt = $pdo->prepare(
        'SELECT s.subject_id, s.subject_code
         FROM Curriculum_subject cs JOIN Subject s ON s.subject_id = cs.subject_id
         WHERE cs.curriculum_id = :cid ORDER BY s.subject_code'
    );
    $stmt->execute(['cid' => $curriculumId]);

    $missing = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!hasCompletedSubject($pdo, $studentId, (int)$row['subject_id'])) {
            $missing[] = $row['subject_code'];
        }
    }
    return $missing;
}

/**
 * The one standing rule, used by term close (admin/terms.php) and by grade corrections. Returns one row per
 * enrollment matching $where (written against the aliases e and es) with its subject, failed and incomplete counts.
 */
function enrollmentStandingCounts(PDO $pdo, string $where, array $params): array
{
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
         WHERE $where
         GROUP BY e.enrollment_id"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Irregular when the enrollment has no subjects, a grade above 3.00, a Dropped row or an Incomplete row. */
function standingFromCounts(array $counts): string
{
    $irregular = (int)$counts['subject_count'] === 0 || (int)$counts['failed_count'] > 0 || (int)$counts['incomplete_count'] > 0;
    return $irregular ? 'irregular' : 'regular';
}

/** Recompute and store one enrollment's standing. Returns 'regular' or 'irregular'. */
function recomputeEnrollmentStanding(PDO $pdo, int $enrollmentId): string
{
    $rows = enrollmentStandingCounts($pdo, 'e.enrollment_id = :id', ['id' => $enrollmentId]);
    $standing = $rows ? standingFromCounts($rows[0]) : 'irregular';
    $pdo->prepare('UPDATE Enrollment SET student_standing = :standing WHERE enrollment_id = :id')
        ->execute(['standing' => $standing, 'id' => $enrollmentId]);
    return $standing;
}
