<?php
/**
 * Password-reset tokens. Only the SHA-256 hash is stored; the raw token
 * exists only in the emailed link.
 */
require_once __DIR__ . '/../../config/app.php';

if (!defined('PASSWORD_RESET_TTL_MINUTES')) {
    define('PASSWORD_RESET_TTL_MINUTES', 30);
}

if (!defined('INVITE_TTL_HOURS')) {
    define('INVITE_TTL_HOURS', 24);
}

const RESET_MIN_GAP_SECONDS = 60;
const RESET_MAX_PER_HOUR = 3;

function hashResetToken(string $token): string
{
    return hash('sha256', $token);
}

function isWellFormedResetToken(string $token): bool
{
    return (bool)preg_match('/^[a-f0-9]{64}$/', $token);
}

/**
 * Issues a fresh token and voids the account's earlier unused ones.
 * Returns null instead of a token if this account is being throttled.
 */
function createPasswordResetToken(PDO $pdo, int $accountId, ?int $ttlMinutes = null): ?string
{
    $pdo->exec('DELETE FROM Password_reset WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');

    $stmt = $pdo->prepare(
        'SELECT COALESCE(SUM(created_at > DATE_SUB(NOW(), INTERVAL ' . RESET_MIN_GAP_SECONDS . ' SECOND)), 0) AS recent,
                COALESCE(SUM(created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)), 0) AS hourly
         FROM Password_reset WHERE account_id = :id'
    );
    $stmt->execute(['id' => $accountId]);
    $counts = $stmt->fetch();

    if ((int)$counts['recent'] > 0 || (int)$counts['hourly'] >= RESET_MAX_PER_HOUR) {
        return null;
    }

    $pdo->prepare('UPDATE Password_reset SET used_at = NOW() WHERE account_id = :id AND used_at IS NULL')
        ->execute(['id' => $accountId]);

    $token = bin2hex(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO Password_reset (account_id, token_hash, expires_at)
         VALUES (:id, :hash, DATE_ADD(NOW(), INTERVAL ' . (int)($ttlMinutes ?? PASSWORD_RESET_TTL_MINUTES) . ' MINUTE))'
    )->execute(['id' => $accountId, 'hash' => hashResetToken($token)]);

    return $token;
}

/** Returns reset_id, account_id, username, activated_at if the token is active/unused/unexpired, else null. */
function findValidPasswordReset(PDO $pdo, string $token): ?array
{
    if (!isWellFormedResetToken($token)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT pr.reset_id, pr.account_id, a.username, a.activated_at
         FROM Password_reset pr
         JOIN Accounts a ON a.account_id = pr.account_id
         WHERE pr.token_hash = :hash AND pr.used_at IS NULL AND pr.expires_at > NOW()'
    );
    $stmt->execute(['hash' => hashResetToken($token)]);
    $row = $stmt->fetch();

    return $row !== false ? $row : null;
}

/**
 * Sets the new password, voids every open token for the account, and bumps
 * session_version so existing logins end. False if the token is no longer
 * valid (used/expired between page load and submit).
 */
function resetPasswordWithToken(PDO $pdo, string $token, string $newPassword): bool
{
    if (!isWellFormedResetToken($token)) {
        return false;
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT reset_id, account_id FROM Password_reset
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
             FOR UPDATE'
        );
        $stmt->execute(['hash' => hashResetToken($token)]);
        $row = $stmt->fetch();

        if ($row === false) {
            $pdo->rollBack();
            return false;
        }

        $pdo->prepare(
            'UPDATE Accounts
             SET password_hash = :hash, must_change_password = 0, session_version = session_version + 1,
                 activated_at = COALESCE(activated_at, NOW())
             WHERE account_id = :id'
        )->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $row['account_id']]);

        $pdo->prepare('UPDATE Password_reset SET used_at = NOW() WHERE account_id = :id AND used_at IS NULL')
            ->execute(['id' => $row['account_id']]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('resetPasswordWithToken failed: ' . $e->getMessage());
        return false;
    }
}

/** Kills every unused link for the account, for example after its email address changed. */
function voidOpenPasswordResets(PDO $pdo, int $accountId): void
{
    $pdo->prepare('UPDATE Password_reset SET used_at = NOW() WHERE account_id = :id AND used_at IS NULL')
        ->execute(['id' => $accountId]);
}

/**
 * Sign-in state per account: 'active' (has chosen a password), 'pending' (invite still valid)
 * or 'expired' (never activated and no valid invite left).
 */
function accountActivationStatuses(PDO $pdo, array $accountIds): array
{
    $accountIds = array_values(array_unique(array_map('intval', $accountIds)));
    if (!$accountIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($accountIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT a.account_id, a.activated_at IS NOT NULL AS activated,
                EXISTS (SELECT 1 FROM Password_reset pr
                        WHERE pr.account_id = a.account_id AND pr.used_at IS NULL AND pr.expires_at > NOW()) AS has_link
         FROM Accounts a WHERE a.account_id IN ($in)"
    );
    $stmt->execute($accountIds);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int)$r['account_id']] = (int)$r['activated'] === 1 ? 'active' : ((int)$r['has_link'] === 1 ? 'pending' : 'expired');
    }
    return $out;
}
