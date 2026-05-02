<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

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
$conn->begin_transaction();

try {
    // Get reservation with customer details
    $stmt = $conn->prepare("SELECT total_amount, additional_amount_due, name, phone FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $reservation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$reservation) {
        throw new Exception('Reservation not found');
    }
    
    // Get total paid
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM split_payments WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $paidResult = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $totalAmount = floatval($reservation['total_amount']);
    $totalPaid = floatval($paidResult['total_paid']);
    $amountDue = $totalAmount - $totalPaid;
    
    // Calculate payment total
    $paymentTotal = 0;
    foreach ($splits as $split) {
        $paymentTotal += floatval($split['amount']);
    }
    
    // Allow small rounding differences
    if (abs($paymentTotal - $amountDue) > 0.1) {
        if ($paymentTotal > $amountDue) {
            throw new Exception("Payment total exceeds amount due");
        }
        if ($paymentTotal < $amountDue) {
            throw new Exception("Payment total is less than amount due");
        }
    }
    
    // Process splits
    foreach ($splits as $split) {
        $method = $split['method'];
        $amount = floatval($split['amount']);
        $receipt_id = isset($split['receipt_id']) ? $split['receipt_id'] : null;
        $received_by = isset($split['received_by']) ? $split['received_by'] : null;
        
        $stmt = $conn->prepare("INSERT INTO split_payments (reservation_id, payment_method, amount, receipt_id, received_by, payment_date) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssdss", $reservation_id, $method, $amount, $receipt_id, $received_by);
        $stmt->execute();
        $stmt->close();
    }
    
    // Update reservation
    $newTotalPaid = $totalPaid + $paymentTotal;
    $newAdditionalDue = max(0, $totalAmount - $newTotalPaid);
    $newStatus = ($newAdditionalDue <= 0) ? 'paid' : 'registered';
    
    $update = $conn->prepare("UPDATE reservations SET status = ?, additional_amount_due = ?, updated_at = NOW() WHERE reservation_id = ?");
    $update->bind_param("sds", $newStatus, $newAdditionalDue, $reservation_id);
    $update->execute();
    $update->close();
    
    $conn->commit();
    
    // ========== SEND WHATSAPP MESSAGES ==========
    $customerPhone = $reservation['phone'];
    $customerName = $reservation['name'];
    $currencySymbol = getCurrencySymbol();
    
    // 1. Send payment confirmation
    $paymentMessage = "💰 *PAYMENT CONFIRMATION* 💰\n\n";
    $paymentMessage .= "Dear {$customerName},\n\n";
    $paymentMessage .= "We have received your payment.\n\n";
    $paymentMessage .= "💵 *Amount:* {$currencySymbol} " . number_format($paymentTotal, 2) . "\n";
    $paymentMessage .= "📋 *Reservation ID:* {$reservation_id}\n";
    
    if ($newAdditionalDue > 0) {
        $paymentMessage .= "⚠️ *Remaining Balance:* {$currencySymbol} " . number_format($newAdditionalDue, 2) . "\n\n";
        $paymentMessage .= "Please complete the remaining payment.\n";
    } else {
        $paymentMessage .= "✅ *Status:* FULLY PAID\n\n";
    }
    
    $paymentMessage .= "Thank you for your payment! 🙏";
    
    sendWhatsAppMessage($customerPhone, $paymentMessage);
    
    // 2. If fully paid, send tickets
    if ($newAdditionalDue <= 0) {
        // Get all tickets
        $ticketsStmt = $conn->prepare("SELECT * FROM ticket_codes WHERE reservation_id = ? AND is_active = 1 ORDER BY guest_type, guest_number");
        $ticketsStmt->bind_param("s", $reservation_id);
        $ticketsStmt->execute();
        $tickets = $ticketsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $ticketsStmt->close();
        
        $eventName = isset($_SESSION['selected_event_name']) ? $_SESSION['selected_event_name'] : 'Event';
        $baseUrl = getSetting('base_url', 'https://restorandticketingsystem.unaux.com/');
        
        // Generate tickets page link
        $ticketsPageUrl = $baseUrl . "public/reservation_tickets.php?id=" . urlencode($reservation_id);
        
        // Send header message with link to tickets page
        $headerMessage = "🎟️ *YOUR TICKETS ARE READY!* 🎟️\n\n";
        $headerMessage .= "Dear {$customerName},\n\n";
        $headerMessage .= "Thank you for your payment! Your tickets are ready.\n\n";
        $headerMessage .= "📋 *Reservation ID:* {$reservation_id}\n";
        $headerMessage .= "🎪 *Event:* {$eventName}\n";
        $headerMessage .= "📱 *Total Tickets:* " . count($tickets) . "\n\n";
        $headerMessage .= "🔗 *View all your tickets online:*\n";
        $headerMessage .= "{$ticketsPageUrl}\n\n";
        $headerMessage .= "💾 You can also save each ticket image below.\n";
        $headerMessage .= "📱 Show the tickets at the entrance.\n\n";
        $headerMessage .= "We look forward to seeing you! 🎉";
        
        sendWhatsAppMessage($customerPhone, $headerMessage);
        
        // Send each ticket as QR code image (backup method)
        $ticketCount = 0;
        foreach ($tickets as $ticket) {
            $ticketCount++;
            $typeLabel = ucfirst($ticket['guest_type']);
            $ticketNumber = str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT);
            
            // Generate QR code URL
            $qrUrl = "https://quickchart.io/qr?text=" . urlencode($ticket['ticket_code']) . "&size=250&margin=2";
            
            $caption = "🎫 *{$typeLabel} Ticket #{$ticketNumber}*\n";
            $caption .= "ID: {$ticket['ticket_code']}\n";
            $caption .= "Valid for one-time entry\n";
            $caption .= "Show this QR code at the entrance";
            
            // Send using URL method
            sendWhatsAppImage($customerPhone, $qrUrl, $caption);
            
            usleep(500000); // 0.5 sec delay
        }
        
        // Send closing message
        if ($ticketCount > 0) {
            $closingMessage = "✅ *All {$ticketCount} ticket(s) sent!*\n\n";
            $closingMessage .= "📸 Each ticket has been sent as an image above.\n";
            $closingMessage .= "🔗 Or view them all at: {$ticketsPageUrl}\n\n";
            $closingMessage .= "💾 Press and hold on each image to save to your phone.\n";
            $closingMessage .= "📱 Show the saved images at the entrance for scanning.\n\n";
            $closingMessage .= "Thank you for choosing us! 🎉";
            
            sendWhatsAppMessage($customerPhone, $closingMessage);
        }
    }
    // ========== END WHATSAPP MESSAGES ==========
    
    echo json_encode([
        'success' => true,
        'remaining_due' => $newAdditionalDue,
        'status' => $newStatus
    ]);
    
} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

$conn->close();
?>