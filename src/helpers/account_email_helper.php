<?php
/**
 * Checks whether an email already belongs to any account or to an unplaced pending/validated
 * application. Returns '' when free, otherwise 'student', 'staff' or 'application'.
 * Rejected and placed applications never count (a placed one is its account).
 */
function accountEmailConflict(PDO $pdo, string $email, ?int $exceptAccountId = null, ?int $exceptApplicationId = null): string
{
    $stmt = $pdo->prepare('SELECT role FROM Accounts WHERE email = :e AND account_id <> :aid LIMIT 1');
    $stmt->execute(['e' => $email, 'aid' => $exceptAccountId ?? 0]);
    $role = $stmt->fetchColumn();
    if ($role !== false) {
        return $role === 'student' ? 'student' : 'staff';
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

function accountEmailConflictMessage(string $conflict): string
{
    return [
        'student' => 'Another student already uses this email address.',
        'staff' => 'A staff account already uses this email address.',
        'application' => 'Another pending or validated application already uses this email address.',
    ][$conflict] ?? '';
}
