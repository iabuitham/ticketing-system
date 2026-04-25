<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$reservation_id = $input['reservation_id'] ?? '';
$paid_amount = floatval($input['paid_amount'] ?? 0);
$payment_method = $input['payment_method'] ?? '';
$amount_due = floatval($input['amount_due'] ?? 0);

// Validate inputs
if (empty($reservation_id)) {
    echo json_encode(['success' => false, 'error' => 'Reservation ID is required']);
    exit();
}

if ($paid_amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment amount']);
    exit();
}

if (empty($payment_method)) {
    echo json_encode(['success' => false, 'error' => 'Payment method is required']);
    exit();
}

if ($paid_amount < $amount_due) {
    echo json_encode(['success' => false, 'error' => 'Payment amount is less than amount due']);
    exit();
}

$conn = getConnection();

// Get current reservation to verify it exists
$stmt = $conn->prepare("SELECT * FROM reservations WHERE reservation_id = ?");
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reservation) {
    echo json_encode(['success' => false, 'error' => 'Reservation not found']);
    $conn->close();
    exit();
}

// Calculate new amount due (should be 0 since we require full payment)
$new_amount_due = max(0, $amount_due - $paid_amount);
$status = $new_amount_due == 0 ? 'paid' : 'registered';

// Update reservation
$update = $conn->prepare("UPDATE reservations SET additional_amount_due = ?, status = ?, payment_method = ? WHERE reservation_id = ?");
$update->bind_param("dsss", $new_amount_due, $status, $payment_method, $reservation_id);

if ($update->execute()) {
    echo json_encode([
        'success' => true, 
        'message' => 'Payment processed successfully',
        'new_status' => $status,
        'amount_paid' => $paid_amount,
        'remaining_due' => $new_amount_due
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $conn->error]);
}

$update->close();
$conn->close();
?>