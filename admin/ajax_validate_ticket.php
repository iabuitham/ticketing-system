<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$ticket_code = isset($input['ticket_code']) ? sanitizeInput($input['ticket_code']) : '';

if (empty($ticket_code)) {
    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'No ticket code provided']);
    exit();
}

$conn = getConnection();
$conn->set_charset("utf8mb4");
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

// Find ticket
$stmt = $conn->prepare("
    SELECT t.*, r.name, r.phone, r.table_id, r.reservation_id, r.event_id, e.event_name, e.event_date, e.venue
    FROM ticket_codes t 
    JOIN reservations r ON CAST(t.reservation_id AS CHAR) = CAST(r.reservation_id AS CHAR)
    LEFT JOIN event_settings e ON r.event_id = e.id
    WHERE CAST(t.ticket_code AS CHAR) = ?
");
$stmt->bind_param("s", $ticket_code);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$ticket) {
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => '❌ Invalid Ticket Code',
        'details' => 'This ticket code does not exist in our system.'
    ]);
    $conn->close();
    exit();
}

if ($ticket['is_active'] == 0) {
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => '❌ Ticket Deactivated',
        'details' => 'This ticket has been deactivated by administrator.',
        'customer' => $ticket['name'],
        'ticket_type' => ucfirst($ticket['guest_type'])
    ]);
    $conn->close();
    exit();
}

if ($ticket['is_scanned'] == 1) {
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => '❌ Ticket Already Used',
        'details' => 'This ticket was already scanned on ' . date('M d, Y H:i:s', strtotime($ticket['scanned_at'])),
        'customer' => $ticket['name'],
        'scanned_at' => $ticket['scanned_at']
    ]);
    $conn->close();
    exit();
}

// Mark as scanned
$update = $conn->prepare("UPDATE ticket_codes SET is_scanned = 1, scanned_at = NOW() WHERE id = ?");
$update->bind_param("i", $ticket['id']);
$update->execute();
$update->close();

// Log the scan
$logStmt = $conn->prepare("INSERT INTO scan_logs (ticket_code, reservation_id, scanned_by, scanned_at) VALUES (?, ?, ?, NOW())");
$logStmt->bind_param("sss", $ticket_code, $ticket['reservation_id'], $_SESSION['admin_username']);
$logStmt->execute();
$logStmt->close();

$conn->close();

$details = "🎫 " . ucfirst($ticket['guest_type']) . " Ticket #" . str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT) . "\n";
$details .= "👤 Customer: " . $ticket['name'] . "\n";
$details .= "🍽️ Table: " . ($ticket['table_id'] ?: 'Not assigned') . "\n";
$details .= "📋 Reservation: " . $ticket['reservation_id'];

echo json_encode([
    'success' => true,
    'status' => 'success',
    'message' => '✅ Ticket Valid - Entry Granted!',
    'details' => $details,
    'customer' => $ticket['name'],
    'table_id' => $ticket['table_id'],
    'ticket_type' => $ticket['guest_type'],
    'ticket_number' => $ticket['guest_number'],
    'reservation_id' => $ticket['reservation_id']
]);
?>