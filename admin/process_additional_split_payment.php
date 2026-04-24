<?php
// Disable error reporting for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json');

// Simple response function
function sendResponse($success, $error = null) {
    $response = ['success' => $success];
    if ($error) $response['error'] = $error;
    echo json_encode($response);
    exit();
}

// Check authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    sendResponse(false, 'Unauthorized');
}

// Database connection
$conn = new mysqli('localhost', 'root', '', 'ticketing_system');
if ($conn->connect_error) {
    sendResponse(false, 'Database connection failed');
}

// Get POST data
$reservation_id = isset($_POST['reservation_id']) ? trim($_POST['reservation_id']) : '';
$total_amount = isset($_POST['total_amount']) ? floatval($_POST['total_amount']) : 0;
$splits_json = isset($_POST['splits']) ? $_POST['splits'] : '';
$splits = json_decode($splits_json, true);

if (empty($reservation_id) || empty($splits) || $total_amount <= 0) {
    sendResponse(false, 'Invalid request data');
}

// Get reservation
$result = $conn->query("SELECT * FROM reservations WHERE reservation_id = '$reservation_id'");
if (!$result || $result->num_rows === 0) {
    sendResponse(false, 'Reservation not found');
}
$res = $result->fetch_assoc();

// Create uploads directory if needed
$uploadDir = '../uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$conn->begin_transaction();

try {
    // Update additional amount due
    $new_additional_due = max(0, $res['additional_amount_due'] - $total_amount);
    
    // Update paid guests counts
    $adultPrice = 10;
    $teenPrice = 10;
    $priceResult = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_price_adult'");
    if ($priceResult && $row = $priceResult->fetch_assoc()) {
        $adultPrice = floatval($row['setting_value']);
    }
    $priceResult = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_price_teen'");
    if ($priceResult && $row = $priceResult->fetch_assoc()) {
        $teenPrice = floatval($row['setting_value']);
    }
    
    $remaining = $total_amount;
    $new_paid_adults = $res['paid_adults'];
    $new_paid_teens = $res['paid_teens'];
    $total_adults = $res['adults'];
    $total_teens = $res['teens'];
    
    while ($remaining >= $adultPrice && $new_paid_adults < $total_adults) {
        $remaining -= $adultPrice;
        $new_paid_adults++;
    }
    while ($remaining >= $teenPrice && $new_paid_teens < $total_teens) {
        $remaining -= $teenPrice;
        $new_paid_teens++;
    }
    
    // Update reservation
    $updateSql = "UPDATE reservations SET 
        additional_amount_due = $new_additional_due,
        paid_adults = $new_paid_adults,
        paid_teens = $new_paid_teens
        WHERE reservation_id = '$reservation_id'";
    
    if (!$conn->query($updateSql)) {
        throw new Exception("Failed to update reservation: " . $conn->error);
    }
    
    // Insert split payments
    $proofCounter = 0;
    foreach ($splits as $split) {
        $method = $split['method'];
        $amount = $split['amount'];
        $receipt_id = isset($split['receipt_id']) ? $split['receipt_id'] : null;
        $proof_path = null;
        
        // Handle file upload for CliQ
        if ($method == 'cliq') {
            $fileKey = "proof_$proofCounter";
            if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
                if (in_array($ext, $allowed)) {
                    $fileName = 'proof_' . $reservation_id . '_' . time() . '_' . $proofCounter . '.' . $ext;
                    if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $uploadDir . $fileName)) {
                        $proof_path = 'uploads/' . $fileName;
                    }
                }
            }
            $proofCounter++;
        }
        
        $insertSql = "INSERT INTO split_payments (reservation_id, payment_method, amount, receipt_id, proof_path, payment_type) 
                     VALUES ('$reservation_id', '$method', $amount, " . ($receipt_id ? "'$receipt_id'" : "NULL") . ", " . ($proof_path ? "'$proof_path'" : "NULL") . ", 'additional')";
        
        if (!$conn->query($insertSql)) {
            throw new Exception("Failed to insert split payment: " . $conn->error);
        }
    }
    
    $conn->commit();
    sendResponse(true);
    
} catch (Exception $e) {
    $conn->rollback();
    sendResponse(false, $e->getMessage());
}

// Add this function for additional payment confirmations
function sendAdditionalPaymentConfirmation($reservation_id, $total_amount, $splits) {
    $conn = getConnection();
    $res = $conn->query("SELECT * FROM reservations WHERE reservation_id = '$reservation_id'")->fetch_assoc();
    $conn->close();
    
    $baseUrl = getBaseUrl();
    $ticketLink = $baseUrl . "admin/print_ticket.php?reservation_id=" . urlencode($reservation_id);
    $currency = getSetting('currency', 'JOD');
    
    $message = "✅ *ADDITIONAL PAYMENT CONFIRMED!* ✅\n\n";
    $message .= "Dear {$res['name']},\n\n";
    $message .= "Your additional payment of " . number_format($total_amount, 2) . " {$currency} has been successfully verified.\n\n";
    
    $message .= "💰 *Payment Breakdown:*\n";
    foreach ($splits as $split) {
        $methodName = $split['method'] == 'cash' ? 'Cash' : ($split['method'] == 'cliq' ? 'CliQ' : 'Visa');
        $message .= "• $methodName: " . number_format($split['amount'], 2) . " {$currency}\n";
    }
    
    $message .= "\n🎫 *Your updated ticket is ready!*\n";
    $message .= "Click the link below to view your ticket:\n";
    $message .= $ticketLink . "\n\n";
    
    $message .= "🎉 Thank you for your payment! 🎉";
    
    sendWhatsAppMessage($res['phone'], $message);
}

$conn->close();
?>