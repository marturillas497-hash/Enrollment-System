<?php
require_once __DIR__ . '/../../includes/session.php';

$user = currentUser();
if ($user !== null && $user['role'] === 'registrar') {
    header('Location: ' . BASE_URL . '/registrar/dashboard.php');
} else {
    header('Location: ' . BASE_URL . '/login.php');
}
exit;
