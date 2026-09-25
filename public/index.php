<?php
// Login is the front door now that every real workflow is built — this file exists
// only so visiting the folder root (no filename) still lands somewhere sensible.
header('Location: /enrollment-system/public/login.php');
exit;
