<?php
/** One-time session messages, used so a POST can redirect and a refresh can't re-submit it. */
function flashSet(string $key, $value): void
{
    $_SESSION['flash'][$key] = $value;
}

function flashGet(string $key)
{
    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }
    $value = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $value;
}

/** Logs the real error with a short reference and returns a message safe to show users. */
function errorMessage(Throwable $e, string $prefix = 'Something went wrong.'): string
{
    $ref = strtoupper(bin2hex(random_bytes(3)));
    error_log("[MIST $ref] " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    return $prefix . ' Please try again. If it keeps happening, give the administrator this reference: ' . $ref . '.';
}

/** Existing staff accounts with the same email or the same first and last name. */
function findDuplicateStaff(PDO $pdo, string $firstName, string $lastName, string $email): array
{
    $stmt = $pdo->prepare(
        "SELECT a.username, a.email, a.role
         FROM Accounts a
         LEFT JOIN Registrar r ON r.account_id = a.account_id AND a.role = 'registrar'
         LEFT JOIN Teacher t ON t.account_id = a.account_id AND a.role = 'teacher'
         LEFT JOIN Admission_Staff sa ON sa.account_id = a.account_id AND a.role = 'admission_staff'
         WHERE a.role IN ('registrar', 'teacher', 'admission_staff')
           AND (LOWER(a.email) = LOWER(:email)
                OR (LOWER(COALESCE(r.first_name, t.first_name, sa.first_name)) = LOWER(:first_name)
                    AND LOWER(COALESCE(r.last_name, t.last_name, sa.last_name)) = LOWER(:last_name)))"
    );
    $stmt->execute(['email' => $email, 'first_name' => $firstName, 'last_name' => $lastName]);
    return $stmt->fetchAll();
}
