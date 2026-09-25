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
