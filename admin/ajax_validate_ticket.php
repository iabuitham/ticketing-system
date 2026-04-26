<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$ticket_code = isset($input['ticket_code']) ? sanitizeInput($input['ticket_code']) : '';

if (empty($ticket_code)) {
    echo json_encode(['status' => 'error', 'message' => 'No ticket code provided']);
    exit();
}

$conn = getConnection();

// Find ticket
$stmt = $conn->prepare("SELECT t.*, r.name, r.phone, r.table_id, r.reservation_id 
                        FROM ticket_codes t 
                        JOIN reservations r ON t.reservation_id = r.reservation_id 
                        WHERE t.ticket_code = ?");
$stmt->bind_param("s", $ticket_code);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticket) {
    echo json_encode(['status' => 'error', 'message' => '❌ Invalid Ticket ID!']);
    $conn->close();
    exit();
}

if ($ticket['is_scanned'] == 1) {
    echo json_encode([
        'status' => 'error', 
        'message' => '❌ Ticket already used!',
        'details' => 'Used at: ' . date('M d, Y H:i:s', strtotime($ticket['scanned_at'])) . '<br>Customer: ' . htmlspecialchars($ticket['name'])
    ]);
    $conn->close();
    exit();
}

// Mark as scanned
$update = $conn->prepare("UPDATE ticket_codes SET is_scanned = 1, scanned_at = NOW() WHERE id = ?");
$update->bind_param("i", $ticket['id']);
$update->execute();
$update->close();

$conn->close();

// Return success
$typeLabel = ucfirst($ticket['guest_type']);
echo json_encode([
    'status' => 'success',
    'message' => '✅ Ticket Valid! Entry Granted!',
    'details' => "Ticket Type: $typeLabel<br>Customer: " . htmlspecialchars($ticket['name']) . "<br>Table: " . htmlspecialchars($ticket['table_id'])
]);
?>