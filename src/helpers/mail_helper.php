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

/**
 * Standard "your account is ready" email, same content shape for every
 * role (student, teacher, registrar, admission_staff).
 */
function sendAccountCredentialsEmail(string $toEmail, string $toName, string $username, string $tempPassword): bool
{
    $subject = 'Your MIST Enrollment System account is ready';
    $body = renderCredentialsEmailHtml(
        $toName,
        'Your account has been created. Use the credentials below to log in:',
        $username,
        $tempPassword
    );
    return sendMail($toEmail, $toName, $subject, $body);
}

/**
 * Password was regenerated (admin resetting staff, registrar resetting a
 * student). Same credentials layout, different intro copy since there is
 * no new account being introduced here.
 */
function sendPasswordResetEmail(string $toEmail, string $toName, string $username, string $tempPassword): bool
{
    $subject = 'Your MIST Enrollment System password has been reset';
    $body = renderCredentialsEmailHtml(
        $toName,
        'Your password has been reset. Use the temporary password below to log in:',
        $username,
        $tempPassword
    );
    return sendMail($toEmail, $toName, $subject, $body);
}

/**
 * Table-based, inline-styled HTML so this renders consistently across
 * Gmail, Outlook, Apple Mail, etc. No external CSS, no flexbox/grid, no JS,
 * all styling is inline per the constraints of HTML email.
 */
function renderCredentialsEmailHtml(string $toName, string $introLine, string $username, string $tempPassword): string
{
    $navy = '#1e3a8a';
    $gold = '#c9a227';
    $logoUrl = SITE_URL . '/assets/mist-logo.png';
    $loginUrl = SITE_URL . '/login.php';

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
                            <p style="margin:0 0 20px;">' . htmlspecialchars($introLine) . '</p>
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid ' . $gold . ';border-radius:6px;margin-bottom:20px;">
                                <tr>
                                    <td style="padding:16px 20px;border-left:4px solid ' . $gold . ';">
                                        <div style="font-size:13px;color:#6c757d;text-transform:uppercase;letter-spacing:.03em;">Username</div>
                                        <div style="font-size:16px;font-weight:bold;color:' . $navy . ';margin-bottom:12px;">' . htmlspecialchars($username) . '</div>
                                        <div style="font-size:13px;color:#6c757d;text-transform:uppercase;letter-spacing:.03em;">Temporary Password</div>
                                        <div style="font-size:16px;font-weight:bold;color:' . $navy . ';">' . htmlspecialchars($tempPassword) . '</div>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:0 0 20px;">You will be asked to change this password on your first login.</p>
                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background:' . $navy . ';border-radius:6px;">
                                        <a href="' . $loginUrl . '" style="display:inline-block;padding:12px 28px;color:#ffffff;text-decoration:none;font-weight:bold;font-size:14px;">Log In</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding:18px;background:#f4f6fa;color:#8a94a3;font-size:12px;">
                            Makilala Institute of Science and Technology &mdash; BSIS Department
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>';
}