<?php
// Turn on error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Create a log file
$log_file = __DIR__ . '/payment_debug.log';
file_put_contents($log_file, date('Y-m-d H:i:s') . " - Payment process started\n", FILE_APPEND);
file_put_contents($log_file, "POST data: " . print_r($_POST, true) . "\n", FILE_APPEND);

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    file_put_contents($log_file, "ERROR: Unauthorized\n", FILE_APPEND);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$reservation_id = $_POST['reservation_id'] ?? '';
$splits_json = $_POST['splits'] ?? '[]';
$splits = json_decode($splits_json, true);

file_put_contents($log_file, "Reservation ID: $reservation_id\n", FILE_APPEND);
file_put_contents($log_file, "Splits: " . print_r($splits, true) . "\n", FILE_APPEND);

if (empty($reservation_id) || empty($splits)) {
    file_put_contents($log_file, "ERROR: Invalid payment data\n", FILE_APPEND);
    echo json_encode(['success' => false, 'error' => 'Invalid payment data']);
    exit();
}

$conn = getConnection();

try {
    // Test database connection
    if (!$conn) {
        throw new Exception('Database connection failed');
    }
    file_put_contents($log_file, "Database connected\n", FILE_APPEND);
    
    // Get current reservation
    $stmt = $conn->prepare("SELECT total_amount, additional_amount_due, name, phone FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$result) {
        throw new Exception('Reservation not found');
    }
    
    file_put_contents($log_file, "Reservation found: " . print_r($result, true) . "\n", FILE_APPEND);
    
    // Get total paid
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM split_payments WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $paidResult = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $totalAmount = floatval($result['total_amount']);
    $totalPaid = floatval($paidResult['total_paid']);
    $remainingDue = $totalAmount - $totalPaid;
    
    file_put_contents($log_file, "Total Amount: $totalAmount, Total Paid: $totalPaid, Remaining: $remainingDue\n", FILE_APPEND);
    
    // Calculate total payment from splits
    $paymentTotal = 0;
    foreach ($splits as $split) {
        $paymentTotal += floatval($split['amount']);
    }
    
    file_put_contents($log_file, "Payment Total: $paymentTotal\n", FILE_APPEND);
    
    if (abs($paymentTotal - $remainingDue) > 0.01 && $paymentTotal < $remainingDue) {
        throw new Exception('Payment total does not match amount due');
    }
    
    if ($paymentTotal > $remainingDue) {
        throw new Exception('Payment total exceeds amount due');
    }
    
    // Process each payment split
    foreach ($splits as $split) {
        $method = $split['method'];
        $amount = floatval($split['amount']);
        $receipt_id = $split['receipt_id'] ?? null;
        $received_by = $split['received_by'] ?? null;
        
        $stmt = $conn->prepare("INSERT INTO split_payments 
            (reservation_id, payment_method, amount, receipt_id, received_by, payment_date) 
            VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssdss", $reservation_id, $method, $amount, $receipt_id, $received_by);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to save payment split: ' . $stmt->error);
        }
        $stmt->close();
        file_put_contents($log_file, "Saved split: $method - $amount\n", FILE_APPEND);
    }
    
    // Calculate new totals
    $newTotalPaid = $totalPaid + $paymentTotal;
    $newAdditionalDue = max(0, $totalAmount - $newTotalPaid);
    $newStatus = ($newAdditionalDue <= 0) ? 'paid' : 'registered';
    
    // Update reservation
    $update = $conn->prepare("UPDATE reservations SET status = ?, additional_amount_due = ?, updated_at = NOW() WHERE reservation_id = ?");
    $update->bind_param("sds", $newStatus, $newAdditionalDue, $reservation_id);
    
    if (!$update->execute()) {
        throw new Exception('Failed to update reservation');
    }
    $update->close();
    
    file_put_contents($log_file, "Reservation updated - Status: $newStatus, Additional Due: $newAdditionalDue\n", FILE_APPEND);
    
    // Send WhatsApp message if fully paid
    if ($newAdditionalDue <= 0) {
        $baseUrl = getSetting('base_url', 'http://localhost/ticketing-system/');
        $ticketLink = $baseUrl . "public/view_tickets.php?token=" . base64_encode($reservation_id);
        $eventName = $_SESSION['selected_event_name'] ?? 'Event';
        $eventDate = $_SESSION['selected_event_date'] ?? '';
        $eventDateFormatted = $eventDate ? date('F j, Y', strtotime($eventDate)) : 'TBA';
        
        $ticketMessage = "🎟️ *YOUR TICKETS ARE READY!* 🎟️\n\n";
        $ticketMessage .= "Dear {$result['name']},\n\n";
        $ticketMessage .= "Thank you for your payment! Your tickets are now ready.\n\n";
        $ticketMessage .= "📋 *Reservation ID:* {$reservation_id}\n";
        $ticketMessage .= "🎪 *Event:* {$eventName}\n";
        $ticketMessage .= "📅 *Date:* {$eventDateFormatted}\n\n";
        $ticketMessage .= "*🔗 Click below to view and download your tickets:*\n";
        $ticketMessage .= "{$ticketLink}\n\n";
        $ticketMessage .= "We look forward to seeing you! 🎉\n";
        
        sendWhatsAppMessage($result['phone'], $ticketMessage);
        file_put_contents($log_file, "WhatsApp message sent to: {$result['phone']}\n", FILE_APPEND);
    }
    
    file_put_contents($log_file, "SUCCESS - Payment processed\n", FILE_APPEND);
    
    echo json_encode([
        'success' => true,
        'message' => 'Payment processed successfully',
        'remaining_due' => $newAdditionalDue,
        'status' => $newStatus
    ]);
    
} catch (Exception $e) {
    file_put_contents($log_file, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents($log_file, "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>