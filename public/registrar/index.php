<?php
require_once __DIR__ . '/../../includes/session.php';

$user = currentUser();
if ($user !== null && $user['role'] === 'registrar') {
    header('Location: /enrollment-system/public/registrar/dashboard.php');
} else {
    header('Location: /enrollment-system/public/login.php');
}
exit;
