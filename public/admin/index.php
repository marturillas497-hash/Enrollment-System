<?php
require_once __DIR__ . '/../../includes/session.php';

$user = currentUser();
if ($user !== null && $user['role'] === 'admin') {
    header('Location: /enrollment-system/public/admin/dashboard.php');
} else {
    header('Location: /enrollment-system/public/login.php');
}
exit;
