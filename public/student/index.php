<?php
require_once __DIR__ . '/../../includes/session.php';

$user = currentUser();
if ($user !== null && $user['role'] === 'student') {
    header('Location: /enrollment-system/public/student/dashboard.php');
} else {
    header('Location: /enrollment-system/public/login.php');
}
exit;
