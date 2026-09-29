<?php
/**
 * Random temp password for newly-created accounts (registrar, student, etc.).
 * Excludes 0/O and 1/l/I — this often gets read off a printed slip, so
 * ambiguous characters cause real support tickets.
 */
function generateTempPassword(int $length = 10): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $max = strlen($chars) - 1;

    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    return $password;
}

/** Shared rule set for change-password and reset-password. Null means OK. */
function validateNewPassword(string $password, string $confirm, string $username = ''): ?string
{
    if (strlen($password) < 8) {
        return 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        return 'Passwords do not match.';
    }
    if ($username !== '' && strcasecmp($password, $username) === 0) {
        return 'Password cannot be the same as your username.';
    }
    return null;
}
