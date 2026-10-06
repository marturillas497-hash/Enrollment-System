<?php
/**
 * Stored for an account that has not chosen a password yet. It is not a valid hash, so
 * password_verify() always fails and nobody can log in until the invite link is used.
 */
function placeholderPasswordHash(): string
{
    return '!' . bin2hex(random_bytes(32));
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
