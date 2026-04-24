<?php
error_reporting(0);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json');

function sendResponse($success, $error = null) {
    $response = ['success' => $success];
    if ($error) $response['error'] = $error;
    echo json_encode($response);
    exit();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    sendResponse(false, 'Unauthorized');
}

$conn = new mysqli('localhost', 'root', '', 'ticketing_system');
if ($conn->connect_error) {
    sendResponse(false, 'Database connection failed');
}

$reservation_id = isset($_POST['reservation_id']) ? trim($_POST['reservation_id']) : '';
$total_amount = isset($_POST['total_amount']) ? floatval($_POST['total_amount']) : 0;
$splits_json = isset($_POST['splits']) ? $_POST['splits'] : '';
$splits = json_decode($splits_json, true);

if (empty($reservation_id) || empty($splits)) {
    sendResponse(false, 'Invalid request');
}

$result = $conn->query("SELECT * FROM reservations WHERE reservation_id = '$reservation_id'");
if (!$result || $result->num_rows === 0) {
    sendResponse(false, 'Reservation not found');
}

$uploadDir = '../uploads/';
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

$conn->begin_transaction();

try {
    $conn->query("UPDATE reservations SET status = 'paid' WHERE reservation_id = '$reservation_id'");
    
    $proofCounter = 0;
    foreach ($splits as $split) {
        $method = $split['method'];
        $amount = $split['amount'];
        $receipt_id = isset($split['receipt_id']) ? $split['receipt_id'] : null;
        $proof_path = null;
        
        if ($method == 'cliq') {
            $fileKey = "proof_$proofCounter";
            if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
                $fileName = 'proof_' . $reservation_id . '_' . time() . '_' . $proofCounter . '.' . $ext;
                if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $uploadDir . $fileName)) {
                    $proof_path = 'uploads/' . $fileName;
                }
            }
            $proofCounter++;
        }
        
        if (count($splits) == 1) {
            $conn->query("UPDATE reservations SET payment_method = '$method', receipt_id = " . ($receipt_id ? "'$receipt_id'" : "NULL") . " WHERE reservation_id = '$reservation_id'");
        }
        
        $conn->query("INSERT INTO split_payments (reservation_id, payment_method, amount, receipt_id, proof_path, payment_type) 
                     VALUES ('$reservation_id', '$method', $amount, " . ($receipt_id ? "'$receipt_id'" : "NULL") . ", " . ($proof_path ? "'$proof_path'" : "NULL") . ", 'initial')");
    }
    
    $conn->commit();
    sendResponse(true);
} catch (Exception $e) {
    $conn->rollback();
    sendResponse(false, $e->getMessage());
}

// Send payment confirmation WhatsApp
function sendPaymentConfirmation($reservation_id, $splits, $total_amount) {
    $conn = getConnection();
    $res = $conn->query("SELECT * FROM reservations WHERE reservation_id = '$reservation_id'")->fetch_assoc();
    $conn->close();
    
    $baseUrl = getBaseUrl();
    $ticketLink = $baseUrl . "admin/print_ticket.php?reservation_id=" . urlencode($reservation_id);
    
    $message = "✅ *PAYMENT CONFIRMED!* ✅\n\n";
    $message .= "Dear {$res['name']},\n\n";
    $message .= "Your payment of " . number_format($total_amount, 2) . " JOD has been successfully verified.\n\n";
    
    $message .= "💰 *Payment Breakdown:*\n";
    foreach ($splits as $split) {
        $methodName = $split['method'] == 'cash' ? 'Cash' : ($split['method'] == 'cliq' ? 'CliQ' : 'Visa');
        $message .= "• $methodName: " . number_format($split['amount'], 2) . " JOD\n";
    }
    
    $message .= "\n🎫 *Your e-ticket is ready!*\n";
    $message .= "Click the link below to view and download your ticket:\n";
    $message .= $ticketLink . "\n\n";
    $message .= "You can also print your ticket directly from this page.\n\n";
    $message .= "📱 *Event Details:*\n";
    $message .= "Please present your ticket (digital or printed) at the entrance.\n\n";
    $message .= "🎉 Thank you for choosing our event! Enjoy! 🎉";
    
    sendWhatsAppMessage($res['phone'], $message);
}

// Add this function at the end of process_split_payment.php
function sendTicketLinkAfterPayment($reservation_id, $splits) {
    $conn = getConnection();
    $res = $conn->query("SELECT * FROM reservations WHERE reservation_id = '$reservation_id'")->fetch_assoc();
    $tickets = $conn->query("SELECT * FROM ticket_codes WHERE reservation_id = '$reservation_id' ORDER BY guest_type, guest_number")->fetch_all(MYSQLI_ASSOC);
    $conn->close();
    
    $baseUrl = getBaseUrl();
    $ticketLink = $baseUrl . "admin/print_ticket.php?reservation_id=" . urlencode($reservation_id);
    $totalGuests = $res['adults'] + $res['teens'] + $res['kids'];
    $totalAmount = array_sum(array_column($splits, 'amount'));
    $currency = getSetting('currency', 'JOD');
    
    $message = "✅ *PAYMENT CONFIRMED! TICKETS READY!* ✅\n\n";
    $message .= "Dear {$res['name']},\n\n";
    $message .= "Your payment of " . number_format($totalAmount, 2) . " {$currency} has been successfully verified.\n\n";
    
    $message .= "💰 *Payment Breakdown:*\n";
    foreach ($splits as $split) {
        $methodName = $split['method'] == 'cash' ? 'Cash' : ($split['method'] == 'cliq' ? 'CliQ' : 'Visa');
        $message .= "• $methodName: " . number_format($split['amount'], 2) . " {$currency}\n";
    }
    
    $message .= "\n📋 *Reservation Details:*\n";
    $message .= "• Reservation ID: {$res['reservation_id']}\n";
    $message .= "• Table: {$res['table_id']}\n";
    $message .= "• Guests: {$totalGuests} ({$res['adults']} Adults, {$res['teens']} Teens, {$res['kids']} Kids)\n\n";
    
    $message .= "🎫 *Your Ticket Codes:*\n";
    foreach ($tickets as $ticket) {
        $typeIcon = $ticket['guest_type'] == 'adult' ? '👤' : ($ticket['guest_type'] == 'teen' ? '🧑' : '👶');
        $message .= "{$typeIcon} {$ticket['ticket_code']}\n";
    }
    
    $message .= "\n📎 *Download Your E-Ticket:*\n";
    $message .= $ticketLink . "\n\n";
    
    $message .= "📱 *Instructions:*\n";
    $message .= "• Click the link above to view your ticket\n";
    $message .= "• You can print or save as PDF\n";
    $message .= "• Present the ticket (digital or printed) at the entrance\n";
    $message .= "• Each ticket has a unique QR code for scanning\n\n";
    
    $message .= "🎉 Thank you for choosing our event! We look forward to welcoming you! 🎉";
    
    sendWhatsAppMessage($res['phone'], $message);
}

// Call this function after successful payment:
sendTicketLinkAfterPayment($reservation_id, $splits);
$conn->close();
?>