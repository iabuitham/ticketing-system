<?php
// Turn off all error reporting for JSON response
error_reporting(0);
ini_set('display_errors', 0);

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Set JSON header
header('Content-Type: application/json');

// Check authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// Get POST data
$reservation_id = isset($_POST['reservation_id']) ? $_POST['reservation_id'] : '';
$splits_json = isset($_POST['splits']) ? $_POST['splits'] : '[]';
$splits = json_decode($splits_json, true);

if (empty($reservation_id)) {
    echo json_encode(['success' => false, 'error' => 'No reservation ID provided']);
    exit();
}

if (empty($splits)) {
    echo json_encode(['success' => false, 'error' => 'No payment splits provided']);
    exit();
}

$conn = getConnection();

// Start transaction
$conn->begin_transaction();

try {
    // Get current reservation
    $stmt = $conn->prepare("SELECT total_amount, additional_amount_due, name, phone FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $reservation = $result->fetch_assoc();
    $stmt->close();
    
    if (!$reservation) {
        throw new Exception('Reservation not found');
    }
    
    // Get total paid from split_payments
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM split_payments WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $paidResult = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $totalAmount = floatval($reservation['total_amount']);
    $totalPaid = floatval($paidResult['total_paid']);
    $remainingDue = $totalAmount - $totalPaid;
    
    // Calculate total payment from splits
    $paymentTotal = 0;
    foreach ($splits as $split) {
        $paymentTotal += floatval($split['amount']);
    }
    
    // Round to 2 decimal places
    $paymentTotal = round($paymentTotal, 2);
    $remainingDue = round($remainingDue, 2);
    
    // Validate payment amount
    if ($paymentTotal > $remainingDue + 0.01) {
        throw new Exception("Payment total ($paymentTotal) exceeds amount due ($remainingDue)");
    }
    
    if ($paymentTotal < $remainingDue - 0.01) {
        throw new Exception("Payment total ($paymentTotal) is less than amount due ($remainingDue)");
    }
    
    // Process each payment split
    foreach ($splits as $split) {
        $method = $split['method'];
        $amount = floatval($split['amount']);
        $receipt_id = isset($split['receipt_id']) ? $split['receipt_id'] : null;
        $received_by = isset($split['received_by']) ? $split['received_by'] : null;
        
        $stmt = $conn->prepare("INSERT INTO split_payments 
            (reservation_id, payment_method, amount, receipt_id, received_by, payment_date) 
            VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssdss", $reservation_id, $method, $amount, $receipt_id, $received_by);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to save payment split: ' . $stmt->error);
        }
        $stmt->close();
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
    
    $conn->commit();
    
    // Send WhatsApp notification (don't let it break the JSON)
    if ($newAdditionalDue <= 0) {
        try {
            $eventName = isset($_SESSION['selected_event_name']) ? $_SESSION['selected_event_name'] : 'Event';
            $baseUrl = getSetting('base_url', 'http://localhost/ticketing-system/');
            $ticketLink = $baseUrl . "public/view_tickets.php?token=" . base64_encode($reservation_id);
            
            $message = "🎟️ *YOUR TICKETS ARE READY!* 🎟️\n\n";
            $message .= "Dear {$reservation['name']},\n\n";
            $message .= "Thank you for your payment!\n\n";
            $message .= "📋 *Reservation ID:* {$reservation_id}\n";
            $message .= "🎪 *Event:* {$eventName}\n\n";
            $message .= "🔗 View your tickets: {$ticketLink}\n\n";
            $message .= "We look forward to seeing you! 🎉";
            
            sendWhatsAppMessage($reservation['phone'], $message);
        } catch (Exception $e) {
            // Don't throw, just log
            error_log("WhatsApp error: " . $e->getMessage());
        }
    }
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Payment processed successfully',
        'remaining_due' => $newAdditionalDue,
        'status' => $newStatus
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>