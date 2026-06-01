<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$conn = getConnection();

// Simple query - get all available tables (status = 'available')
$query = "
    SELECT table_number, section 
    FROM tables 
    WHERE is_active = 1 
    AND status = 'available'
    ORDER BY CAST(table_number AS UNSIGNED), table_number
";

$result = $conn->query($query);

$tables = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $tables[] = [
            'table_number' => $row['table_number'],
            'section' => $row['section'] ?? ''
        ];
    }
}

$conn->close();

// If no tables with status 'available', return all active tables as fallback
if (empty($tables)) {
    $conn2 = getConnection();
    $fallbackQuery = "SELECT table_number, section FROM tables WHERE is_active = 1 ORDER BY CAST(table_number AS UNSIGNED), table_number";
    $fallbackResult = $conn2->query($fallbackQuery);
    while ($row = $fallbackResult->fetch_assoc()) {
        $tables[] = [
            'table_number' => $row['table_number'],
            'section' => $row['section'] ?? ''
        ];
    }
    $conn2->close();
}

echo json_encode(['success' => true, 'tables' => $tables]);
?>