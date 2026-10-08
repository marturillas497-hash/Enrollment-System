<?php
/**
 * Checks whether an email already belongs to a student or to an unplaced pending/validated
 * application. Returns '' when free, 'student' or 'application' otherwise.
 * Rejected applications and placed applications never count (a placed one is its Student row).
 */
function studentEmailConflict(PDO $pdo, string $email, ?int $exceptApplicationId = null, ?int $exceptStudentId = null): string
{
    $stmt = $pdo->prepare('SELECT 1 FROM Student WHERE email_address = :e AND student_id <> :sid LIMIT 1');
    $stmt->execute(['e' => $email, 'sid' => $exceptStudentId ?? 0]);
    if ($stmt->fetch() !== false) {
        return 'student';
    }

    $stmt = $pdo->prepare(
        "SELECT 1 FROM Admission_Application a
         WHERE a.email_address = :e AND a.application_id <> :aid AND a.status IN ('pending', 'validated')
           AND NOT EXISTS (SELECT 1 FROM Student s WHERE s.application_id = a.application_id)
         LIMIT 1"
    );
    $stmt->execute(['e' => $email, 'aid' => $exceptApplicationId ?? 0]);
    return $stmt->fetch() !== false ? 'application' : '';
}
