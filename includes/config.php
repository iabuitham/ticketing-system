<?php
/**
 * Database configuration for ezyro hosting
 */

// Database configuration (these look correct)
define('DB_HOST', 'sql103.ezyro.com');
define('DB_USER', 'ezyro_41780028');
define('DB_NAME', 'ezyro_41780028_ticketing_system');
define('DB_PASS', '6dfb6092a4');

// Base URL - FIXED (remove /admin)
define('BASE_URL', 'https://restorandticketingsystem.unaux.com/');

// Error reporting (turn off in production later)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set timezone
date_default_timezone_set('Asia/Amman');
?>