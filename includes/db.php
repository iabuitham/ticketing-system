<?php
/**
 * Database connection file
 * This file should only contain database connection functions
 */

function getConnection() {
    // Include database configuration
    require_once __DIR__ . '/config.php';
    
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Set charset to UTF-8
    $conn->set_charset("utf8mb4");
    
    return $conn;
}

// For backward compatibility
function getDB() {
    return getConnection();
}
?>