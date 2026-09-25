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
