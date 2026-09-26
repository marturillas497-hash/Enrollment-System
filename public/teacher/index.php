<?php
require_once __DIR__ . '/../../includes/session.php';

$user = currentUser();
if ($user !== null && $user['role'] === 'teacher') {
    header('Location: ' . BASE_URL . '/teacher/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;
