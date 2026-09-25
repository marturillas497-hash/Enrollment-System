<?php
/**
 * Single PDO connection for the whole app.
 * Every model/service should require this file and use getDbConnection().
 */

// --- Adjust these if your local MySQL setup differs from XAMPP's defaults ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'bsis_enrollment_system');
define('DB_USER', 'root');
define('DB_PASS', ''); // XAMPP's default root password is empty

function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
            ]);
        } catch (PDOException $e) {
            // In development it's fine to see the raw message. Before this
            // goes anywhere near production, this should log the error and
            // show a generic message instead.
            die('Database connection failed: ' . $e->getMessage());
        }
    }

    return $pdo;
}
