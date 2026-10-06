<?php
require_once __DIR__ . '/../includes/session.php';

/* Logging out changes state, so it only happens on a POST that passed the CSRF check. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logoutUser();
}
header('Location: ' . BASE_URL . '/login.php');
exit;
