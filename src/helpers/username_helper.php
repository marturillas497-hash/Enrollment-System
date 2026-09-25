<?php
/**
 * Builds a username as lastname_firstname, lowercased, non-letters stripped.
 * On collision, appends 2, 3, 4... until a free one is found.
 */
function generateUniqueUsername(PDO $pdo, string $lastName, string $firstName): string
{
    $clean = fn(string $s) => strtolower(preg_replace('/[^a-zA-Z]/', '', $s));

    $base = $clean($lastName) . '_' . $clean($firstName);
    $username = $base;
    $suffix = 1;

    $check = $pdo->prepare('SELECT 1 FROM Accounts WHERE username = :u');

    while (true) {
        $check->execute(['u' => $username]);
        if ($check->fetch() === false) {
            return $username;
        }
        $suffix++;
        $username = $base . $suffix;
    }
}
