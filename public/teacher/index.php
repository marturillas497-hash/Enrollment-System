<?php
require_once __DIR__ . '/../../includes/session.php';

$user = currentUser();
if ($user !== null && $user['role'] === 'teacher') {
    header('Location: /enrollment-system/public/teacher/dashboard.php');
} else {
    header('Location: /enrollment-system/public/login.php');
}
exit;
