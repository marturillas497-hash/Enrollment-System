<?php
/**
 * Sends transactional email via Brevo's HTTP API (not SMTP, InfinityFree
 * blocks outbound SMTP ports on free tier, so this uses curl over HTTPS
 * instead of PHPMailer).
 */
require_once __DIR__ . '/../../config/mail.php';
require_once __DIR__ . '/../../config/app.php';

/**
 * Sends one email. Returns true on success, false on failure.
 * Never throws, a failed send should not block account creation.
 */
function sendMail(string $toEmail, string $toName, string $subject, string $htmlBody): bool
{
    if ($toEmail === '') {
        return false;
    }

    $payload = [
        'sender'      => ['name' => MAIL_FROM_NAME, 'email' => MAIL_FROM_EMAIL],
        'to'          => [['email' => $toEmail, 'name' => $toName]],
        'subject'     => $subject,
        'htmlContent' => $htmlBody,
    ];

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'api-key: ' . BREVO_API_KEY,
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        error_log('sendMail curl error: ' . $curlErr);
        return false;
    }
    if ($status < 200 || $status >= 300) {
        error_log('sendMail failed (' . $status . '): ' . $response);
        return false;
    }
    return true;
}

/** Self-service "forgot password" email: a single-use link, no password in the mail. */
function sendPasswordResetLinkEmail(string $toEmail, string $toName, string $username, string $resetUrl, int $minutes): bool
{
    $subject = 'Reset your MIST Enrollment System password';
    $body = renderResetLinkEmailHtml($toName, $username, $resetUrl, $minutes);
    return sendMail($toEmail, $toName, $subject, $body);
}

function renderResetLinkEmailHtml(string $toName, string $username, string $resetUrl, int $minutes): string
{
    $navy = '#1e3a8a';
    $gold = '#c9a227';
    $logoUrl = SITE_URL . '/assets/mist-logo.png';
    $safeUrl = htmlspecialchars($resetUrl);

    return '
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e8edf5;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">
                    <tr>
                        <td align="center" style="background:' . $navy . ';padding:28px 24px;">
                            <img src="' . $logoUrl . '" width="64" height="64" alt="MIST" style="display:block;border:0;margin-bottom:8px;">
                            <div style="color:#ffffff;font-size:18px;font-weight:bold;">MIST Enrollment System</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 28px;color:#212529;font-size:15px;line-height:1.5;">
                            <p style="margin:0 0 16px;">Hi ' . htmlspecialchars($toName) . ',</p>
                            <p style="margin:0 0 20px;">We received a request to reset the password for the account <strong style="color:' . $navy . ';">' . htmlspecialchars($username) . '</strong>. Click the button below to choose a new password.</p>
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                                <tr>
                                    <td style="background:' . $navy . ';border-radius:6px;">
                                        <a href="' . $safeUrl . '" style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;">Reset Password</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 16px;border-left:4px solid ' . $gold . ';padding-left:12px;color:#495057;">This link expires in ' . $minutes . ' minutes and can only be used once.</p>
                            <p style="margin:0 0 8px;font-size:13px;color:#6c757d;">If the button does not work, copy and paste this link into your browser:</p>
                            <p style="margin:0 0 20px;font-size:12px;color:#6c757d;word-break:break-all;">' . $safeUrl . '</p>
                            <p style="margin:0;font-size:13px;color:#6c757d;">If you did not request this, you can ignore this email. Your password will not change.</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:18px;background:#f4f6fa;color:#8a94a3;font-size:12px;">
                            Makilala Institute of Science and Technology
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>';
}

/** First-time "set your password" email for a new account. The link is single-use. */
function sendInviteEmail(string $toEmail, string $toName, string $username, string $inviteUrl, int $hours): bool
{
    $subject = 'Welcome to the MIST Enrollment System: set your password';
    return sendMail($toEmail, $toName, $subject, renderInviteEmailHtml($toName, $username, $inviteUrl, $hours));
}

function renderInviteEmailHtml(string $toName, string $username, string $inviteUrl, int $hours): string
{
    $navy = '#1e3a8a';
    $gold = '#c9a227';
    $logoUrl = SITE_URL . '/assets/mist-logo.png';
    $safeUrl = htmlspecialchars($inviteUrl);

    return '
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e8edf5;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">
                    <tr>
                        <td align="center" style="background:' . $navy . ';padding:28px 24px;">
                            <img src="' . $logoUrl . '" width="64" height="64" alt="MIST" style="display:block;border:0;margin-bottom:8px;">
                            <div style="color:#ffffff;font-size:18px;font-weight:bold;">MIST Enrollment System</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px 28px;color:#212529;font-size:15px;line-height:1.5;">
                            <p style="margin:0 0 16px;">Hi ' . htmlspecialchars($toName) . ',</p>
                            <p style="margin:0 0 16px;">An account has been created for you. Your username is:</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid ' . $gold . ';border-radius:6px;margin-bottom:20px;">
                                <tr>
                                    <td style="padding:14px 20px;border-left:4px solid ' . $gold . ';">
                                        <div style="font-size:16px;font-weight:bold;color:' . $navy . ';">' . htmlspecialchars($username) . '</div>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 20px;">Choose your own password to start using it:</p>
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
                                <tr>
                                    <td style="background:' . $navy . ';border-radius:6px;">
                                        <a href="' . $safeUrl . '" style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;">Set Your Password</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 16px;border-left:4px solid ' . $gold . ';padding-left:12px;color:#495057;">This link expires in ' . $hours . ' hours and can only be used once.</p>
                            <p style="margin:0 0 8px;font-size:13px;color:#6c757d;">If the button does not work, copy and paste this link into your browser:</p>
                            <p style="margin:0 0 20px;font-size:12px;color:#6c757d;word-break:break-all;">' . $safeUrl . '</p>
                            <p style="margin:0;font-size:13px;color:#6c757d;">If you were not expecting this, you can ignore this email.</p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:18px;background:#f4f6fa;color:#8a94a3;font-size:12px;">
                            Makilala Institute of Science and Technology
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>';
}

/** Tells the old address that the account email changed. The new address is masked. */
function sendEmailChangedNotice(string $toEmail, string $toName, string $username, string $maskedNewEmail): bool
{
    $subject = 'The email on your MIST account was changed';
    $body = '
    <div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#212529;max-width:480px;">
        <p>Hi ' . htmlspecialchars($toName) . ',</p>
        <p>The email address on the account <strong>' . htmlspecialchars($username) . '</strong> was changed to <strong>' . htmlspecialchars($maskedNewEmail) . '</strong>.
        Password links will now go to the new address, and any link sent to this address earlier no longer works.</p>
        <p>If you did not expect this change, contact your school administrator.</p>
        <p style="color:#6c757d;font-size:13px;">Makilala Institute of Science and Technology</p>
    </div>';
    return sendMail($toEmail, $toName, $subject, $body);
}

/** j***@example.com */
function maskEmail(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false || $at < 1) {
        return '***';
    }
    return substr($email, 0, 1) . '***' . substr($email, $at);
}
