<?php
/**
 * Session bootstrap + auth guard helpers.
 * Every page that needs to know who's logged in should require this file.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

// A session cookie with no explicit lifetime is a "session cookie" in the strict
// sense — some browsers drop it the moment the window fully closes, others don't
// consistently. Setting an explicit lifetime here makes "resume my session after
// closing the browser" actually reliable, for as long as this lifetime allows.
// gc_maxlifetime has to match, or PHP may garbage-collect the session data on the
// server before the cookie itself expires.
const SESSION_LIFETIME_SECONDS = 60 * 60 * 24; // 24 hours

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME_SECONDS);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME_SECONDS,
        'path'     => '/',
        'httponly' => true,   // JS can't read the cookie — blocks a whole class of session-theft via XSS
        'samesite' => 'Lax',
    ]);
    session_start();
}

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

/** True if the account's session_version still matches the one stored at login. */
function sessionIsCurrent(array $user): bool
{
    $stmt = getDbConnection()->prepare('SELECT session_version FROM Accounts WHERE account_id = :id');
    $stmt->execute(['id' => $user['account_id']]);
    $version = $stmt->fetchColumn();

    return $version !== false && (int)$version === (int)($user['session_version'] ?? 0);
}

/** Redirects to login.php if nobody's logged in. Call at the top of every protected page. */
function requireLogin(): array
{
    $user = currentUser();
    if ($user === null) {
        header('Location: ' . BASE_URL . '/login.php');
        exit;
    }
    if (!sessionIsCurrent($user)) {
        logoutUser();
        header('Location: ' . BASE_URL . '/login.php?ended=1');
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
