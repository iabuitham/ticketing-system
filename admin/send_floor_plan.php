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
$phone = $input['phone'] ?? '';
$name = $input['name'] ?? '';

if (empty($reservation_id)) {
    echo json_encode(['success' => false, 'error' => 'No reservation ID provided']);
    exit();
}

$conn = getConnection();

// Get floor plan image for current event
$selected_event_id = $_SESSION['selected_event_id'] ?? 0;
$stmt = $conn->prepare("SELECT floor_plan_image, event_name FROM event_settings WHERE id = ?");
$stmt->bind_param("i", $selected_event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event || empty($event['floor_plan_image'])) {
    echo json_encode(['success' => false, 'error' => 'No floor plan available for this event']);
    $conn->close();
    exit();
}

$floorPlanUrl = getBaseUrl() . $event['floor_plan_image'];
$eventName = $event['event_name'];

// Clean phone number
$cleanPhone = preg_replace('/[^0-9]/', '', $phone);
if (substr($cleanPhone, 0, 1) == '0') $cleanPhone = substr($cleanPhone, 1);
if (substr($cleanPhone, 0, 3) != '962') $cleanPhone = '962' . $cleanPhone;

// Bilingual WhatsApp message
$message = "📍 *FLOOR PLAN | مخطط الطاولات* 📍\n\n";
$message .= "Dear {$name} | عزيزنا {$name},\n\n";
$message .= "Here is the floor plan for {$eventName}.\n";
$message .= "هذا هو مخطط الطاولات لحدث {$eventName}.\n\n";
$message .= "Please check available tables and choose your preferred one.\n";
$message .= "يرجى الاطلاع على الطاولات المتاحة واختيار ما يناسبك.\n\n";
$message .= "Once you arrive at the venue, our staff will guide you to your table.\n";
$message .= "عند وصولك إلى المكان، سيقوم فريقنا بتوجيهك إلى طاولتك.\n\n";
$message .= "We look forward to welcoming you! 🎉\n";
$message .= "نتطلع لاستقبالك! 🎉";

// Send text message first
sendWhatsAppMessage($cleanPhone, $message);

// Send floor plan image
$caption = "📍 Floor Plan for {$eventName} | مخطط الطاولات لحدث {$eventName}";
$imageSent = sendWhatsAppImage($cleanPhone, $floorPlanUrl, $caption);

$conn->close();

if ($imageSent) {
    echo json_encode(['success' => true, 'message' => 'Floor plan sent successfully']);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to send floor plan image. Please check WhatsApp configuration.']);
}
?>