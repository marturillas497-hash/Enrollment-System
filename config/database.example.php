<?php
/**
 * Template for config/database.php. Copy this file to database.php and
 * fill in DB_PASS. database.php is gitignored — never commit it with real
 * credentials.
 */

require_once __DIR__ . '/app.php';

define('DB_HOST', APP_ENV === 'development' ? 'localhost' : 'your-host-here');
define('DB_NAME', APP_ENV === 'development' ? 'enrollment_system' : 'your-db-name-here');
define('DB_USER', APP_ENV === 'development' ? 'root' : 'your-db-user-here');
define('DB_PASS', APP_ENV === 'development' ? '' : 'your-db-password-here');

function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
        }
    }

    return $pdo;
}