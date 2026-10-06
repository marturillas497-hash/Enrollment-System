<?php
/**
 * Invite and reset links sent on someone else's behalf (admin for staff, registrar for students).
 * The link is only ever emailed. Nothing here returns it, so no screen can display a link or a password.
 *
 * LIVE DEPLOYMENT: the links are built from SITE_URL in config/app.php. Before going live, make sure the
 * server's config/app.php has APP_ENV set to 'production' and a SITE_URL that is the real live address
 * (including /public). If it still points at localhost, every invite email will contain a localhost link.
 * Never overwrite the server's config/app.php with the local copy.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/reset_token_helper.php';
require_once __DIR__ . '/mail_helper.php';

/**
 * Sends the right link for the account: an invite (24 hours) if it has never been activated,
 * otherwise a password reset link (30 minutes). Voids the earlier unused link first.
 *
 * Returns ['result' => sent|throttled|failed|no_email|inactive|missing, 'kind' => invite|reset, 'email' => string].
 */
function sendAccountLink(PDO $pdo, int $accountId): array
{
    $stmt = $pdo->prepare(
        "SELECT a.username, a.email, a.is_active, a.activated_at,
                COALESCE(r.first_name, t.first_name, sa.first_name, s.first_name) AS first_name,
                COALESCE(r.last_name, t.last_name, sa.last_name, s.last_name) AS last_name
         FROM Accounts a
         LEFT JOIN Registrar r ON r.account_id = a.account_id
         LEFT JOIN Teacher t ON t.account_id = a.account_id
         LEFT JOIN Admission_Staff sa ON sa.account_id = a.account_id
         LEFT JOIN Student s ON s.account_id = a.account_id
         WHERE a.account_id = :id"
    );
    $stmt->execute(['id' => $accountId]);
    $account = $stmt->fetch();

    if (!$account) {
        return ['result' => 'missing', 'kind' => 'reset', 'email' => ''];
    }

    $kind = $account['activated_at'] === null ? 'invite' : 'reset';
    $email = trim((string)($account['email'] ?? ''));

    if ((int)$account['is_active'] !== 1) {
        return ['result' => 'inactive', 'kind' => $kind, 'email' => $email];
    }
    if ($email === '') {
        return ['result' => 'no_email', 'kind' => $kind, 'email' => ''];
    }

    $token = createPasswordResetToken($pdo, $accountId, $kind === 'invite' ? INVITE_TTL_HOURS * 60 : PASSWORD_RESET_TTL_MINUTES);
    if ($token === null) {
        return ['result' => 'throttled', 'kind' => $kind, 'email' => $email];
    }

    $url = SITE_URL . '/reset-password.php?token=' . $token;
    $name = trim(($account['first_name'] ?? '') . ' ' . ($account['last_name'] ?? ''));
    if ($name === '') {
        $name = $account['username'];
    }

    $sent = $kind === 'invite'
        ? sendInviteEmail($email, $name, $account['username'], $url, INVITE_TTL_HOURS)
        : sendPasswordResetLinkEmail($email, $name, $account['username'], $url, PASSWORD_RESET_TTL_MINUTES);

    return ['result' => $sent ? 'sent' : 'failed', 'kind' => $kind, 'email' => $email];
}

/** Turns a sendAccountLink() result into [isSuccess, message] for a flash or alert. */
function accountLinkMessage(array $r): array
{
    $what = $r['kind'] === 'invite' ? 'An invite' : 'A password reset link';
    switch ($r['result']) {
        case 'sent':
            return [true, $what . ' was sent to ' . $r['email'] . '.'];
        case 'throttled':
            return [false, 'A link was sent to this account very recently. Wait a minute and try again (limit is 3 per hour).'];
        case 'no_email':
            return [false, 'No email is on file for this account, so nothing was sent.'];
        case 'inactive':
            return [false, 'This account is deactivated. Reactivate it first.'];
        case 'missing':
            return [false, 'Account not found.'];
        default:
            return [false, $what . ' could not be emailed to ' . $r['email'] . '. Check the address and try again.'];
    }
}
