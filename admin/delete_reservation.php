<?php
// Enable temporarily for debugging - remove after testing
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

function sendDeleteResponse($success, $error = null, $debug = null) {
    $response = ['success' => $success];
    if ($error) $response['error'] = $error;
    if ($debug) $response['debug'] = $debug;
    echo json_encode($response);
    exit();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    sendDeleteResponse(false, 'Unauthorized');
}

$input = json_decode(file_get_contents('php://input'), true);
$reservation_id = $input['reservation_id'] ?? '';
$password = $input['password'] ?? '';

if ($password !== 'AdminDelete2026') {
    sendDeleteResponse(false, 'Invalid password');
}

if (empty($reservation_id)) {
    sendDeleteResponse(false, 'No reservation ID provided');
}

$conn = getConnection();

// Debug info
$debug = [];

// Get table_id BEFORE deleting
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
$debug['table_id'] = $table_id;

$conn->begin_transaction();

try {
    // Delete related records
    $stmt = $conn->prepare("DELETE FROM split_payments WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $conn->prepare("DELETE FROM ticket_codes WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $stmt->close();
    
    $stmt = $conn->prepare("DELETE FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $stmt->close();
    
    // Release the table
    $updateTable = $conn->prepare("UPDATE tables SET status = 'available', current_reservation_id = NULL, reserved_until = NULL WHERE table_number = ?");
    $updateTable->bind_param("s", $table_id);
    $updateTable->execute();
    $debug['table_updated'] = $updateTable->affected_rows;
    $updateTable->close();
    
    $conn->commit();
    
    sendDeleteResponse(true, null, $debug);
    
} catch (Exception $e) {
    $conn->rollback();
    sendDeleteResponse(false, $e->getMessage(), $debug);
}

$conn->close();
?>