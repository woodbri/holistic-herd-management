<?php

// include database class
require_once(dirname(dirname(__FILE__)) . '/class/database.php');

// Connection parameters

define('DB_HOST', 'pgsql');
define('DB_USER', 'postgres');
define('DB_PASS', 'VERYSECRET');
define('DB_NAME', 'postgres');
define('DB_PORT', '5432');

?>
