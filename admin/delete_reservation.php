<?php
// No spaces or ANYTHING before <?php
error_reporting(0);
ini_set('display_errors', 0);

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Simple response function
function sendDeleteResponse($success, $error = null) {
    $response = ['success' => $success];
    if ($error) $response['error'] = $error;
    echo json_encode($response);
    exit();
}

// Check authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    sendDeleteResponse(false, 'Unauthorized');
}

// Get input data
$input = json_decode(file_get_contents('php://input'), true);
$reservation_id = $input['reservation_id'] ?? '';
$password = $input['password'] ?? '';

// Verify password
if ($password !== 'AdminDelete2026') {
    sendDeleteResponse(false, 'Invalid password');
}

if (empty($reservation_id)) {
    sendDeleteResponse(false, 'No reservation ID provided');
}

$conn = getConnection();

// Get table_id before deleting reservation
$stmt = $conn->prepare("SELECT table_id FROM reservations WHERE reservation_id = ?");
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$result = $stmt->get_result();
$reservation = $result->fetch_assoc();
$stmt->close();

if (!$reservation) {
    sendDeleteResponse(false, 'Reservation not found');
}

$table_id = $reservation['table_id'];

// Start transaction
$conn->begin_transaction();

try {
    // Delete from split_payments first
    $stmt = $conn->prepare("DELETE FROM split_payments WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $stmt->close();
    
    // Delete from ticket_codes
    $stmt = $conn->prepare("DELETE FROM ticket_codes WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $stmt->close();
    
    // Delete the reservation
    $stmt = $conn->prepare("DELETE FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $stmt->close();
    
    // Release the table back to available
    $updateTable = $conn->prepare("
        UPDATE tables 
        SET status = 'available', 
            current_reservation_id = NULL,
            reserved_until = NULL
        WHERE table_number = ?
    ");
    $updateTable->bind_param("s", $table_id);
    $updateTable->execute();
    $updateTable->close();
    
    $conn->commit();
    
    sendDeleteResponse(true);
    
} catch (Exception $e) {
    $conn->rollback();
    sendDeleteResponse(false, $e->getMessage());
}

$conn->close();
?>