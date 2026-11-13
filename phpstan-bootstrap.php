<?php

// Stand-ins for the constants WordPress defines in wp-config.php, so PHPStan can
// analyze any test code that reads them. Never loaded at runtime.
define('DB_HOST', 'localhost');
define('DB_NAME', 'wordpress');
define('DB_PASSWORD', '');
define('DB_USER', 'root');
