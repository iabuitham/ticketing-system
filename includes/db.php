<?php
/**
 * Database connection file
 */

function getConnection() {
    require_once __DIR__ . '/config.php';
    
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Set charset to utf8mb4 for proper Arabic/unicode support
    $conn->set_charset("utf8mb4");
    
    // Force MySQL to use Jordan timezone (UTC+3)
    $conn->query("SET time_zone = '" . DB_TIMEZONE . "'");
    
    return $conn;
}

// Current datetime in Jordan timezone (use this instead of NOW() if needed)
$currentDateTime = date('Y-m-d H:i:s');
?>