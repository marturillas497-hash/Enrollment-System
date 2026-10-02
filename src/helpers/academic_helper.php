<?php
/**
 * True if this student has ever passed the given subject, in any enrollment.
 * Uses the same pass/fail rule as term-close: remarks='Passed' wins if set,
 * otherwise falls back to grade <= 3.00 (the conventional passing line).
 */
function hasPassedSubject(PDO $pdo, int $studentId, int $subjectId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM Enrolled_subject es
         JOIN Enrollment e ON e.enrollment_id = es.enrollment_id
         JOIN Class_Offering co ON co.offering_id = es.offering_id
         WHERE e.student_id = :sid AND co.subject_id = :subid
           AND (es.remarks = 'Passed' OR (es.grade IS NOT NULL AND es.grade <= 3.00))
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
         WHERE psr.student_id = :sid2 AND sc.credited_subject_id = :subid2
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
             SELECT e2.enrollment_id FROM Enrollment e2 WHERE e2.student_id = s.student_id
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
 * alone misses the latter two, which matters for shift-add-subjects.php and
 * transferee-credit.php's "add remaining subject" pickers.
 */
function hasCompletedSubject(PDO $pdo, int $studentId, int $subjectId): bool
{
    if (hasPassedSubject($pdo, $studentId, $subjectId)) {
        return true;
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM Shift_credit sc
         JOIN Program_shift_request psr ON psr.request_id = sc.request_id
         WHERE psr.student_id = :sid1 AND sc.credited_subject_id = :subid1
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
 * a prerequisite — meaning adding "subjectId requires prereqId" would close a
 * loop. Walks the full Prerequisite graph (BFS), not just the direct pair, so
 * it catches chains of any length, not only A<->B.
 */
function prerequisiteWouldCreateCycle(PDO $pdo, int $subjectId, int $prereqId): bool
{
    $stmt = $pdo->prepare('SELECT prerequisite_subject_id FROM Prerequisite WHERE subject_id = :id');

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

        $stmt->execute(['id' => $current]);
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
        if ($enrollment['status'] === 'pending' && !$enrollment['source_shift_request_id']) {
            return ['available' => true, 'reason' => 'You have a pending subject selection. Withdraw it to request a shift instead.'];
        }
        return ['available' => false, 'reason' => 'Available before you enroll in the open term.'];
    }
    return ['available' => true, 'reason' => ''];
}
