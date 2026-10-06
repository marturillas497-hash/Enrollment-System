<?php
/**
 * Session bootstrap + auth guard helpers.
 * Every page that needs to know who's logged in should require this file.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/helpers/ui_helper.php';
require_once __DIR__ . '/../src/helpers/flash_helper.php';

// A session cookie with no explicit lifetime is a "session cookie" in the strict
// sense — some browsers drop it the moment the window fully closes, others don't
// consistently. Setting an explicit lifetime here makes "resume my session after
// closing the browser" actually reliable, for as long as this lifetime allows.
// gc_maxlifetime has to match, or PHP may garbage-collect the session data on the
// server before the cookie itself expires.
const SESSION_LIFETIME_SECONDS = 60 * 60 * 24; // 24 hours

if (session_status() === PHP_SESSION_NONE) {
    /*
     * The Secure flag is only set when the request really came over HTTPS, so plain-http
     * local XAMPP still works while the live site never sends the cookie over http.
     */
    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME_SECONDS);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME_SECONDS,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,   // JS can't read the cookie — blocks a whole class of session-theft via XSS
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** One random token per session, created on first use. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/*
 * Every POST must carry the session's token. The check lives here so no page has to opt in,
 * and the buffer below adds the hidden field to every POST form, so no form has to be edited.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $sentToken = $_POST['csrf_token'] ?? '';
    if (!is_string($sentToken) || !hash_equals(csrfToken(), $sentToken)) {
        http_response_code(403);
        $reload = htmlspecialchars((string)($_SERVER['REQUEST_URI'] ?? BASE_URL . '/login.php'), ENT_QUOTES);
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Page expired</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head>'
            . '<body class="bg-light"><div class="container py-5" style="max-width:520px"><div class="alert alert-warning">'
            . '<strong>This page expired.</strong> Your request could not be verified, so nothing was saved. Reload the page and try again.'
            . '</div><a class="btn btn-primary" href="' . $reload . '">Reload the page</a></div></body></html>';
        exit;
    }
}

$mistCsrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
ob_start(function (string $html) use ($mistCsrfField): string {
    if (stripos($html, '<form') === false) {
        return $html;
    }
    return preg_replace('/(<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>)/i', '$1' . $mistCsrfField, $html);
});

const DASHBOARD_BY_ROLE = [
    'student'         => BASE_URL . '/student/dashboard.php',
    'teacher'         => BASE_URL . '/teacher/dashboard.php',
    'registrar'       => BASE_URL . '/registrar/dashboard.php',
    'admission_staff' => BASE_URL . '/staff/dashboard.php',
    'admin'           => BASE_URL . '/admin/dashboard.php',
];

/** Returns the logged-in user's session data, or null if nobody's logged in. */
function currentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * 'ok' while the session is still valid, 'inactive' once the admin deactivated the account,
 * and 'ended' when the account is gone or its session_version changed (password change or reset).
 */
function sessionStatus(array $user): string
{
    $stmt = getDbConnection()->prepare('SELECT session_version, is_active FROM Accounts WHERE account_id = :id');
    $stmt->execute(['id' => $user['account_id']]);
    $row = $stmt->fetch();

    if ($row === false) {
        return 'ended';
    }
    if ((int)$row['is_active'] !== 1) {
        return 'inactive';
    }
    return (int)$row['session_version'] === (int)($user['session_version'] ?? 0) ? 'ok' : 'ended';
}

/** Redirects to login.php if nobody's logged in. Call at the top of every protected page. */
function requireLogin(): array
{
    $user = currentUser();
    if ($user === null) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    $status = sessionStatus($user);
    if ($status !== 'ok') {
        logoutUser();
        header('Location: ' . BASE_URL . '/login.php?ended=' . ($status === 'inactive' ? 'inactive' : '1'));
        exit;
    }
    if (!empty($user['must_change']) && !in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), ['change-password.php', 'logout.php'], true)) {
        header('Location: ' . BASE_URL . '/change-password.php');
        exit;
    }
    return $user;
}

/**
 * Redirects to login.php if nobody's logged in, or shows a 403 if the logged-in
 * user's role isn't in the allowed list. Use this on role-specific pages.
 *
 * @param string[] $allowedRoles e.g. ['registrar'] or ['registrar', 'admin']
 */
function requireRole(array $allowedRoles): array
{
    $user = requireLogin();
    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        die('403 — you do not have access to this page.');
    }
    return $user;
}

/** Destroys the session entirely. */
function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie('PHPSESSID', '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}