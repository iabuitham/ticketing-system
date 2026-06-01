<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$reservation_id = $input['reservation_id'] ?? '';
$table_number = $input['table_number'] ?? '';

if (empty($reservation_id) || empty($table_number)) {
    echo json_encode(['success' => false, 'error' => 'Missing reservation ID or table number']);
    exit();
}

$conn = getConnection();
$selected_event_id = $_SESSION['selected_event_id'] ?? 0;

// Check if table is already assigned to another active reservation
$checkStmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM reservations 
    WHERE table_id = ? 
    AND event_id = ?
    AND status NOT IN ('cancelled', 'completed', 'expired')
    AND reservation_id != ?
");
$checkStmt->bind_param("sis", $table_number, $selected_event_id, $reservation_id);
$checkStmt->execute();
$checkResult = $checkStmt->get_result()->fetch_assoc();
$checkStmt->close();

if ($checkResult['count'] > 0) {
    echo json_encode(['success' => false, 'error' => 'Table already assigned to another active reservation']);
    $conn->close();
    exit();
}

// Update reservation with table number
$updateStmt = $conn->prepare("UPDATE reservations SET table_id = ? WHERE reservation_id = ?");
$updateStmt->bind_param("ss", $table_number, $reservation_id);

if ($updateStmt->execute()) {
    // Get reservation details for WhatsApp notification
    $resStmt = $conn->prepare("SELECT name, phone FROM reservations WHERE reservation_id = ?");
    $resStmt->bind_param("s", $reservation_id);
    $resStmt->execute();
    $reservation = $resStmt->get_result()->fetch_assoc();
    $resStmt->close();
    
    if ($reservation) {
        // Format phone number
        $cleanPhone = preg_replace('/[^0-9]/', '', $reservation['phone']);
        if (substr($cleanPhone, 0, 1) == '0') $cleanPhone = substr($cleanPhone, 1);
        if (substr($cleanPhone, 0, 3) != '962') $cleanPhone = '962' . $cleanPhone;
        
        // Get event name
        $eventStmt = $conn->prepare("SELECT event_name FROM event_settings WHERE id = ?");
        $eventStmt->bind_param("i", $selected_event_id);
        $eventStmt->execute();
        $event = $eventStmt->get_result()->fetch_assoc();
        $eventStmt->close();
        $eventName = $event['event_name'] ?? 'Event';
        
        // Bilingual WhatsApp message
        $message = "🍽️ *TABLE ASSIGNED | تم تخصيص الطاولة* 🍽️\n\n";
        $message .= "Dear {$reservation['name']} | عزيزنا {$reservation['name']},\n\n";
        $message .= "Your table has been assigned for {$eventName}.\n";
        $message .= "تم تخصيص طاولتك لحدث {$eventName}.\n\n";
        $message .= "📋 *Reservation ID | رقم الحجز:* {$reservation_id}\n";
        $message .= "🍽️ *Table Number | رقم الطاولة:* {$table_number}\n\n";
        $message .= "Please proceed to your assigned table upon arrival.\n";
        $message .= "يرجى التوجه إلى طاولتك المخصصة عند الوصول.\n\n";
        $message .= "We look forward to serving you! 🎉\n";
        $message .= "نتطلع لخدمتك! 🎉";
        
        sendWhatsAppMessage($cleanPhone, $message);
    }
    
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$updateStmt->close();
$conn->close();
?>