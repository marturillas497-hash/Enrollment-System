<?php
require_once __DIR__ . '/../../includes/session.php';

$user = currentUser();
if ($user !== null && $user['role'] === 'admission_staff') {
    header('Location: /enrollment-system/public/staff/dashboard.php');
} else {
    header('Location: /enrollment-system/public/login.php');
}
exit;
