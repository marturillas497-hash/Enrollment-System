<?php
require_once __DIR__ . '/../includes/session.php';

logoutUser();
header('Location: ' . BASE_URL . '/login.php');
exit;
