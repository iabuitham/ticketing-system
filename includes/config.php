<?php
/**
 * Database configuration for ezyro hosting
 */

// Database configuration
define('DB_HOST', 'db.fr-roub1.bengt.wasmernet.com');
define('DB_USER', 'f6415567768d8000b2eda39f5960');
define('DB_NAME', 'ticketing_system');
define('DB_PASS', '069ef641-5567-77a1-8000-8df0665e3366');

// Base URL
define('BASE_URL', 'https://ticketing-system.wasmer.app/');

// Error reporting (turn off in production later)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set PHP timezone to Jordan time
date_default_timezone_set('Asia/Amman');

// MySQL timezone offset (Jordan is UTC+3)
define('DB_TIMEZONE', '+03:00');
?>