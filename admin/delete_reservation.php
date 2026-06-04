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
    // Delete related records from split_payments
    $stmt = $conn->prepare("DELETE FROM split_payments WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $debug['split_payments_deleted'] = $stmt->affected_rows;
    $stmt->close();
    
    // Delete related records from ticket_codes
    $stmt = $conn->prepare("DELETE FROM ticket_codes WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $debug['ticket_codes_deleted'] = $stmt->affected_rows;
    $stmt->close();
    
    // Delete from credit_notes if they exist
    $stmt = $conn->prepare("DELETE FROM credit_notes WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $debug['credit_notes_deleted'] = $stmt->affected_rows;
    $stmt->close();
    
    // Check if loyalty_points_transactions table exists before trying to delete
    $checkTable = $conn->query("SHOW TABLES LIKE 'loyalty_points_transactions'");
    if ($checkTable && $checkTable->num_rows > 0) {
        $stmt = $conn->prepare("DELETE FROM loyalty_points_transactions WHERE reservation_id = ?");
        $stmt->bind_param("s", $reservation_id);
        $stmt->execute();
        $debug['loyalty_deleted'] = $stmt->affected_rows;
        $stmt->close();
    } else {
        $debug['loyalty_deleted'] = 'Table does not exist - skipped';
    }
    
    // Finally delete the reservation
    $stmt = $conn->prepare("DELETE FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $debug['reservation_deleted'] = $stmt->affected_rows;
    $stmt->close();
    
    // Release the table (only if a table was assigned)
    if (!empty($table_id)) {
        $updateTable = $conn->prepare("UPDATE tables SET status = 'available', current_reservation_id = NULL, reserved_until = NULL WHERE table_number = ?");
        $updateTable->bind_param("s", $table_id);
        $updateTable->execute();
        $debug['table_updated'] = $updateTable->affected_rows;
        $updateTable->close();
    } else {
        $debug['table_updated'] = 'No table was assigned';
    }
    
    $conn->commit();
    
    sendDeleteResponse(true, null, $debug);
    
} catch (Exception $e) {
    $conn->rollback();
    sendDeleteResponse(false, $e->getMessage(), $debug);
}

$conn->close();
?>