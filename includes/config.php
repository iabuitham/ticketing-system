<?php
/**
 * Database configuration for ezyro hosting
 */

// Database configuration
define('DB_HOST', 'sql103.ezyro.com');
define('DB_USER', 'ezyro_41780028');
define('DB_NAME', 'ezyro_41780028_ticketing_system');
define('DB_PASS', '6dfb6092a4');

// Base URL
define('BASE_URL', 'https://restorandticketingsystem.unaux.com/');

// Error reporting (turn off in production later)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set PHP timezone to Jordan time
date_default_timezone_set('Asia/Amman');

// MySQL timezone offset (Jordan is UTC+3)
define('DB_TIMEZONE', '+03:00');
?>