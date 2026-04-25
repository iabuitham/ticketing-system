<?php
/**
 * Database configuration
 * Session settings should be set BEFORE session_start()
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');  // Change to your database username
define('DB_NAME', 'ticketing_system');  // Change to your database name
define('DB_PASS', '');  // Change to your database password

// Base URL (update this to your actual domain)
define('BASE_URL', 'http://localhost/ticketing-system/');

// Error reporting (turn off in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Note: Session settings should be set BEFORE session_start()
// These settings are now moved to the file that starts the session
// Do NOT set session settings here as session may already be started
?>