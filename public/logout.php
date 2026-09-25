<?php
require_once __DIR__ . '/../includes/session.php';

logoutUser();
header('Location: /enrollment-system/public/login.php');
exit;
