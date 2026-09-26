<?php
require_once __DIR__ . '/../../includes/session.php';

$user = currentUser();
if ($user !== null && $user['role'] === 'admission_staff') {
    header('Location: ' . BASE_URL . '/staff/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;
