<?php
error_reporting(0);
ini_set('display_errors', 0);

session_start();
header('Content-Type: application/json');

require_once '../includes/db.php';
require_once '../includes/functions.php';

function sendResponse($success, $error = null, $data = null)
{
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

// Fix timezone issue - set to Asia/Amman
date_default_timezone_set('Asia/Amman');

$conn->begin_transaction();

try {
 $proofCounter = 0;
 foreach ($splits as $split) {
  $method = $split['method'];
  $amount = floatval($split['amount']);
  $receipt_id = isset($split['receipt_id']) ? $split['receipt_id'] : null;
  $received_by = isset($split['received_by']) ? $split['received_by'] : ($_SESSION['admin_username'] ?? 'Admin');
  $proof_file = null;
  $proof_text = null;  // NEW: for text-based proof
  $notes = null;

  // Handle CliQ payment - supports BOTH screenshot AND text proof
  if ($method == 'cliq') {
   $fileKey = "proof_$proofCounter";

   // Check for file upload (screenshot)
   if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
     $ext = strtolower(pathinfo($_FILES[$fileKey]['name'], PATHINFO_EXTENSION));
     $allowed = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];
     if (in_array($ext, $allowed)) {
        $fileName = 'cliq_' . $reservation_id . '_' . time() . '_' . $proofCounter . '.' . $ext;
        if (move_uploaded_file($_FILES[$fileKey]['tmp_name'], $uploadDir . $fileName)) {
             $proof_file = $fileName;
             $notes = "CliQ payment with screenshot proof";
        }
     }
   }

   // Check for text proof (transaction ID or reference number)
   if (isset($split['proof_text']) && !empty($split['proof_text'])) {
     $proof_text = $split['proof_text'];
     $notes = "CliQ payment with reference: " . $proof_text;
   }

   // If no proof provided but receipt_id exists
   if (empty($proof_file) && empty($proof_text) && !empty($receipt_id)) {
     $proof_text = $receipt_id;
     $notes = "CliQ payment with reference: " . $receipt_id;
   }

   // Generate receipt_id if not provided
   if (empty($receipt_id)) {
     $receipt_id = 'CLIQ-' . strtoupper(uniqid());
   }

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

  // Insert into split_payments
  $stmt = $conn->prepare("
   INSERT INTO split_payments 
   (reservation_id, payment_method, amount, receipt_id, proof_file, proof_path, payment_type, received_by, notes, created_at) 
   VALUES (?, ?, ?, ?, ?, ?, 'additional', ?, ?, NOW())
  ");

  $receipt_id = $receipt_id ?? '';
  $proof_file = $proof_file ?? '';
  $proof_text_for_db = $proof_text ?? '';  // Store text proof in proof_path column
  $received_by = $received_by ?? 'System';
  $notes = $notes ?? '';

  $stmt->bind_param(
   "ssdsssss",
   $reservation_id,
   $method,
   $amount,
   $receipt_id,
   $proof_file,
   $proof_text_for_db,
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

 // Send WhatsApp confirmation
 sendPaymentConfirmation($reservation_id, $total_amount, $splits, $new_total_paid, $total_amount_due);

 sendResponse(true, null, ['total_paid' => $new_total_paid, 'status' => $new_status]);
} catch (Exception $e) {
 $conn->rollback();
 sendResponse(false, $e->getMessage());
}

$conn->close();

function sendPaymentConfirmation($reservation_id, $total_amount, $splits, $new_total_paid, $total_amount_due)
{
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

 $paymentBreakdown = "";
 foreach ($splits as $split) {
  $methodName = ucfirst($split['method']);
  $paymentBreakdown .= "• $methodName: " . $currencySymbol . " " . number_format($split['amount'], 2) . "\n";
 }

 if ($new_total_paid >= $total_amount_due) {
  $message = "✅ *PAYMENT CONFIRMED - FULLY PAID* ✅\n";
  $message .= "✅ *تم تأكيد الدفع - دفع كامل* ✅\n";

  $message .= "Dear {$reservation['name']} | عزيزنا {$reservation['name']},\n\n";

  $message .= "Your reservation is now *FULLY PAID*.\n";
  $message .= "حجزك الآن*مدفوع بالكامل*.\n\n";

  $message .= "💰 *Payment Details | تفاصيل الدفع:*\n";
  $message .= $paymentBreakdown;
  $message .= "\n";

  $message .= "📋 *Reservation ID | رقم الحجز:* {$reservation_id}\n";
  $message .= "🍽️ *Table | الطاولة:* {$reservation['table_id']}\n\n";

  $message .= "🎫 *YOUR TICKETS | تذاكرك:*\n";
  $message .= $ticketLink . "\n\n";

  $message .= "🎉 Thank you! | شكراً لك! 🎉\n";
 } else {
  $remaining = $total_amount_due - $new_total_paid;

  $message = "💰 *PARTIAL PAYMENT RECEIVED* 💰\n";
  $message .= "💰 *تم استلام دفعة جزئية* 💰\n";

  $message .= "Dear {$reservation['name']} | عزيزنا {$reservation['name']}،\n\n";

  $message .= "Payment received: " . $currencySymbol . " " . number_format($total_amount, 2) . "\n";
  $message .= "المبلغ المدفوع: " . $currencySymbol . " " . number_format($total_amount, 2) . "\n\n";

  $message .= "⚠️ *Remaining Balance | المبلغ المتبقي:* " . $currencySymbol . " " . number_format($remaining, 2) . "\n\n";

  $message .= "📋 *Reservation ID | رقم الحجز:* {$reservation_id}\n\n";

  $message .= "Complete the payment to receive your tickets.\n";
  $message .= "أكمل الدفع لاستلام تذاكرك.\n\n";

  $message .= "🎉 Thank you! | شكراً لك! 🎉\n";
 }

 sendWhatsAppMessage($reservation['phone'], $message);
}
