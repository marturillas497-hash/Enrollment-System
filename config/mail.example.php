<?php
/**
 * Brevo (Sendinblue) transactional email config.
 * Copy this file to mail.php and fill in real values. mail.php is
 * gitignored on purpose, never commit the real API key.
 */

define('BREVO_API_KEY', 'your-brevo-api-key-here');

// Must be a verified sender in your Brevo account (Senders, Domains & Dedicated IPs).
define('MAIL_FROM_EMAIL', 'noreply@example.com');
define('MAIL_FROM_NAME', 'MIST Enrollment System');
