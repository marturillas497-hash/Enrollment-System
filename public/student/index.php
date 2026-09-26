<?php
require_once __DIR__ . '/../../includes/session.php';

$user = currentUser();
if ($user !== null && $user['role'] === 'student') {
    header('Location: ' . BASE_URL . '/student/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;
