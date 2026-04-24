<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$conn = getConnection();

// Get total count and new since last check
$result = $conn->query("SELECT COUNT(*) as total FROM reservations WHERE DATE(created_at) = CURDATE()");
$total = $result->fetch_assoc()['total'];

// Get new reservations in the last minute
$newResult = $conn->query("SELECT COUNT(*) as new_count FROM reservations WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
$newCount = $newResult->fetch_assoc()['new_count'];

$conn->close();

echo json_encode([
    'success' => true,
    'total_count' => $total,
    'new_count' => $newCount
]);
?>