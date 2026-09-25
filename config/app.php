<?php
/**
 * App-wide config. Right now this is just the environment flag, which
 * gates dev-only tools like the test account creator in register.php.
 *
 * IMPORTANT: flip this to 'production' before this system goes anywhere
 * near real students/staff/data.
 */
define('APP_ENV', 'development');
