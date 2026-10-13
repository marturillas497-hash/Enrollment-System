<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/session.php';

$user = requireRole(['registrar']);
$pdo = getDbConnection();

$stmt = $pdo->prepare('SELECT department_id FROM Registrar WHERE account_id = :aid');
$stmt->execute(['aid' => $user['account_id']]);

$readOnly = true;
$scopeDepartmentId = (int)$stmt->fetchColumn();
$selfUrl = BASE_URL . '/registrar/instructors.php';

require __DIR__ . '/../../includes/instructors_page.php';
