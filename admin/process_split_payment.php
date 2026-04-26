<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Disable error display for JSON response
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$reservation_id = $_POST['reservation_id'] ?? '';
$splits_json = $_POST['splits'] ?? '[]';
$splits = json_decode($splits_json, true);

if (empty($reservation_id) || empty($splits)) {
    echo json_encode(['success' => false, 'error' => 'Invalid payment data']);
    exit();
}

$conn = getConnection();
$conn->begin_transaction();

try {
    // Get current reservation and total paid
    $stmt = $conn->prepare("SELECT total_amount, additional_amount_due, 
        COALESCE((SELECT SUM(amount) FROM split_payments WHERE reservation_id = ?), 0) as total_paid 
        FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("ss", $reservation_id, $reservation_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$result) {
        throw new Exception('Reservation not found');
    }
    
    $totalAmount = floatval($result['total_amount']);
    $totalPaid = floatval($result['total_paid']);
    $remainingDue = $totalAmount - $totalPaid;
    
    // Calculate total payment from splits
    $paymentTotal = 0;
    foreach ($splits as $split) {
        $paymentTotal += floatval($split['amount']);
    }
    
    if (abs($paymentTotal - $remainingDue) > 0.01 && $paymentTotal < $remainingDue) {
        throw new Exception('Payment total does not match amount due');
    }
    
    if ($paymentTotal > $remainingDue) {
        throw new Exception('Payment total exceeds amount due');
    }
    
    // Create uploads directory if not exists
    $uploadDir = '../uploads/payments/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Process each payment split
    $fileIndex = 0;
    foreach ($splits as $split) {
        $method = $split['method'];
        $amount = floatval($split['amount']);
        $receipt_id = $split['receipt_id'] ?? null;
        $received_by = $split['received_by'] ?? null;
        $proof_path = null;
        
        // Handle file upload for CliQ
        if ($method == 'cliq' && isset($_FILES["file_$fileIndex"])) {
            $file = $_FILES["file_$fileIndex"];
            $fileExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
            
            if (!in_array($fileExt, $allowedExts)) {
                throw new Exception('Invalid file type for CliQ evidence');
            }
            
            $fileName = time() . '_' . $reservation_id . '_' . $fileIndex . '.' . $fileExt;
            $targetPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                $proof_path = 'uploads/payments/' . $fileName;
            } else {
                throw new Exception('Failed to upload CliQ screenshot');
            }
            $fileIndex++;
        }
        
        // Insert split payment record
        $stmt = $conn->prepare("INSERT INTO split_payments 
            (reservation_id, payment_method, amount, receipt_id, proof_path, received_by, payment_date) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("ssdsss", $reservation_id, $method, $amount, $receipt_id, $proof_path, $received_by);
        
        if (!$stmt->execute()) {
            throw new Exception('Failed to save payment split');
        }
        $stmt->close();
    }
    
    // Calculate new totals
    $newTotalPaid = $totalPaid + $paymentTotal;
    $newAdditionalDue = max(0, $totalAmount - $newTotalPaid);
    
    // Determine new status
    if ($newAdditionalDue <= 0) {
        $newStatus = 'paid';
    } else {
        $newStatus = 'registered';
    }
    
    // Update reservation
    $update = $conn->prepare("UPDATE reservations SET status = ?, additional_amount_due = ?, updated_at = NOW() WHERE reservation_id = ?");
    $update->bind_param("sds", $newStatus, $newAdditionalDue, $reservation_id);
    
    if (!$update->execute()) {
        throw new Exception('Failed to update reservation');
    }
    $update->close();
    
    // Get customer phone for WhatsApp
    $customerStmt = $conn->prepare("SELECT name, phone FROM reservations WHERE reservation_id = ?");
    $customerStmt->bind_param("s", $reservation_id);
    $customerStmt->execute();
    $customer = $customerStmt->get_result()->fetch_assoc();
    $customerStmt->close();
    
    $conn->commit();
    
    // Send WhatsApp confirmation (don't let it break the JSON response)
    $currencySymbol = getCurrencySymbol();
    $paymentMessage = "💰 *Payment Received!*\n\n";
    $paymentMessage .= "Dear {$customer['name']},\n\n";
    $paymentMessage .= "We have received your payment.\n\n";
    $paymentMessage .= "💵 *Amount:* {$currencySymbol} " . number_format($paymentTotal, 2) . "\n";
    
    if ($newAdditionalDue > 0) {
        $paymentMessage .= "⚠️ *Remaining Balance:* {$currencySymbol} " . number_format($newAdditionalDue, 2) . "\n\n";
    } else {
        $paymentMessage .= "✅ *Status:* Fully Paid\n\n";
    }
    
    $paymentMessage .= "Thank you for your payment! 🙏";
    
    // Try to send WhatsApp but don't fail if it doesn't work
    @sendWhatsAppMessage($customer['phone'], $paymentMessage);
    
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