<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['admin']);
$pdo = getDbConnection();

$readOnly = false;
$scopeDepartmentId = null;
$selfUrl = BASE_URL . '/admin/instructors.php';

require __DIR__ . '/../../includes/instructors_page.php';
