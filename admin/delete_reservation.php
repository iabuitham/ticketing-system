<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$reservation_id = isset($data['reservation_id']) ? trim($data['reservation_id']) : '';
$password = isset($data['password']) ? $data['password'] : '';

// Verify password (default: AdminDelete2026)
if ($password !== 'AdminDelete2026') {
    echo json_encode(['success' => false, 'error' => 'Invalid password']);
    exit();
}

if (empty($reservation_id)) {
    echo json_encode(['success' => false, 'error' => 'No reservation ID provided']);
    exit();
}

$conn = getConnection();

// Start transaction
$conn->begin_transaction();

try {
    // Delete from split_payments first (child table)
    $conn->query("DELETE FROM split_payments WHERE reservation_id = '$reservation_id'");
    
    // Delete from ticket_codes
    $conn->query("DELETE FROM ticket_codes WHERE reservation_id = '$reservation_id'");
    
    // Delete from reservations
    $conn->query("DELETE FROM reservations WHERE reservation_id = '$reservation_id'");
    
    $conn->commit();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>