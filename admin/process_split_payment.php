<?php
error_reporting(0);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';
require_once '../includes/functions.php';

function sendResponse($success, $error = null, $data = null) {
    $response = ['success' => $success];
    if ($error) $response['error'] = $error;
    if ($data) $response['data'] = $data;
    echo json_encode($response);
    exit();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    sendResponse(false, 'Unauthorized');
}

$conn = getConnection();

$reservation_id = isset($_POST['reservation_id']) ? trim($_POST['reservation_id']) : '';
$splits_json = isset($_POST['splits']) ? $_POST['splits'] : '';
$splits = json_decode($splits_json, true);

if (empty($reservation_id) || empty($splits)) {
    sendResponse(false, 'Invalid request data');
}

// Get reservation
$stmt = $conn->prepare("SELECT * FROM reservations WHERE reservation_id = ?");
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    sendResponse(false, 'Reservation not found');
}
$reservation = $result->fetch_assoc();
$stmt->close();

// Calculate total payment amount
$total_amount = 0;
foreach ($splits as $split) {
    $total_amount += floatval($split['amount']);
}

// Get current total paid
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM split_payments WHERE reservation_id = ?");
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$paidResult = $stmt->get_result()->fetch_assoc();
$current_paid = floatval($paidResult['total_paid']);
$stmt->close();

$new_total_paid = $current_paid + $total_amount;
$total_amount_due = floatval($reservation['total_amount']);

// Create uploads directory if needed
$uploadDir = '../uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$conn->begin_transaction();

try {
    $proofCounter = 0;
    foreach ($splits as $split) {
        $method = $split['method'];
        $amount = floatval($split['amount']);
        $receipt_id = isset($split['receipt_id']) ? $split['receipt_id'] : null;
        $received_by = isset($split['received_by']) ? $split['received_by'] : ($_SESSION['admin_username'] ?? 'Admin');
        $proof_file = null;
        $notes = null;
        
        // Handle file upload for CliQ
        if ($method == 'cliq') {
            $fileKey = "proof_$proofCounter";
            if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
                if (in_array($ext, $allowed)) {
                    $fileName = 'cliq_' . $reservation_id . '_' . time() . '_' . $proofCounter . '.' . $ext;
                    if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $uploadDir . $fileName)) {
                        $proof_file = $fileName;
                    }
                }
            }
            if (empty($receipt_id)) {
                $receipt_id = 'CLIQ-' . strtoupper(uniqid());
            }
            $notes = "CliQ payment " . ($proof_file ? "with proof" : "without proof");
            $proofCounter++;
        }
        
        // Handle Visa payment
        if ($method == 'visa') {
            if (empty($receipt_id)) {
                $receipt_id = 'VISA-' . strtoupper(uniqid());
            }
            $notes = "Visa payment with receipt ID: $receipt_id";
        }
        
        // Handle Cash payment
        if ($method == 'cash') {
            $notes = "Cash payment received by: $received_by";
        }
        
        // INSERT with correct column names from your database
        $stmt = $conn->prepare("
            INSERT INTO split_payments 
            (reservation_id, payment_method, amount, receipt_id, proof_file, payment_type, received_by, notes, created_at) 
            VALUES (?, ?, ?, ?, ?, 'additional', ?, ?, NOW())
        ");
        
        // Ensure no NULL values
        $receipt_id = $receipt_id ?? '';
        $proof_file = $proof_file ?? '';
        $received_by = $received_by ?? 'System';
        $notes = $notes ?? '';
        
        $stmt->bind_param("ssdssss", 
            $reservation_id, 
            $method, 
            $amount, 
            $receipt_id, 
            $proof_file, 
            $received_by, 
            $notes
        );
        
        if (!$stmt->execute()) {
            throw new Exception("Failed to insert split payment: " . $stmt->error);
        }
        $stmt->close();
    }
    
    // Update reservation status if fully paid
    $new_status = $reservation['status'];
    if ($new_total_paid >= $total_amount_due) {
        $new_status = 'paid';
        $updateStmt = $conn->prepare("UPDATE reservations SET status = ? WHERE reservation_id = ?");
        $updateStmt->bind_param("ss", $new_status, $reservation_id);
        $updateStmt->execute();
        $updateStmt->close();
    }
    
    $conn->commit();
    
    // Send WhatsApp confirmation (optional - uncomment if needed)
    // sendPaymentConfirmation($reservation_id, $total_amount, $splits, $new_total_paid, $total_amount_due);
    
    sendResponse(true, null, ['total_paid' => $new_total_paid, 'status' => $new_status]);
    
} catch (Exception $e) {
    $conn->rollback();
    sendResponse(false, $e->getMessage());
}

$conn->close();

/**
 * Send payment confirmation via WhatsApp (optional)
 */
function sendPaymentConfirmation($reservation_id, $total_amount, $splits, $new_total_paid, $total_amount_due) {
    $conn = getConnection();
    
    $stmt = $conn->prepare("SELECT * FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $reservation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    
    if (!$reservation) return;
    
    $baseUrl = getSetting('base_url', 'https://restorandticketingsystem.unaux.com');
    $ticketLink = $baseUrl . "public/reservation_tickets.php?id=" . urlencode($reservation_id);
    $currencySymbol = getCurrencySymbol();
    
    $message = "✅ *PAYMENT CONFIRMED!* ✅\n\n";
    $message .= "Dear {$reservation['name']},\n\n";
    $message .= "Your payment of " . $currencySymbol . " " . number_format($total_amount, 2) . " has been successfully processed.\n\n";
    
    $message .= "💰 *Payment Breakdown:*\n";
    foreach ($splits as $split) {
        $methodName = ucfirst($split['method']);
        $message .= "• $methodName: " . $currencySymbol . " " . number_format($split['amount'], 2) . "\n";
    }
    
    $message .= "\n📋 *Reservation ID:* {$reservation_id}\n";
    $message .= "🍽️ *Table:* {$reservation['table_id']}\n";
    
    if ($new_total_paid >= $total_amount_due) {
        $message .= "\n✅ *Status:* FULLY PAID\n";
        $message .= "\n🎫 *Your tickets are ready!*\n";
        $message .= $ticketLink . "\n\n";
    } else {
        $remaining = $total_amount_due - $new_total_paid;
        $message .= "\n⚠️ *Remaining Balance:* " . $currencySymbol . " " . number_format($remaining, 2) . "\n";
    }
    
    $message .= "🎉 Thank you for your payment! 🎉";
    
    sendWhatsAppMessage($reservation['phone'], $message);
}
?>