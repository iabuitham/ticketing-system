<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

$ticket_code = isset($_GET['ticket_code']) ? sanitizeInput($_GET['ticket_code']) : '';

if (empty($ticket_code)) {
    die('No ticket code provided');
}

$conn = getConnection();

// Get ticket details
$stmt = $conn->prepare("SELECT t.*, r.name, r.phone, r.table_id, r.reservation_id 
                        FROM ticket_codes t 
                        JOIN reservations r ON t.reservation_id = r.reservation_id 
                        WHERE t.ticket_code = ?");
$stmt->bind_param("s", $ticket_code);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$ticket) {
    die('Ticket not found');
}

$eventName = getSetting('site_name', 'Event');
$eventDate = $_SESSION['selected_event_date'] ?? date('F j, Y', strtotime('+30 days'));
$eventTime = $_SESSION['selected_event_time'] ?? '6:00 PM';
$eventVenue = $_SESSION['selected_event_venue'] ?? 'Grand Hall, Amman';
$typeLabel = ucfirst($ticket['guest_type']);
$ticketNumber = str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT);

// Load your template image
$templatePath = '../assets/images/ticket_template.png';

if (!file_exists($templatePath)) {
    // Fallback: create a simple ticket if template not found
    $width = 500;
    $height = 700;
    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    $purple = imagecolorallocate($image, 79, 70, 229);
    imagefill($image, 0, 0, $white);
    imagefilledrectangle($image, 0, 0, $width, 180, $purple);
} else {
    $image = imagecreatefrompng($templatePath);
}

// Get image dimensions
$width = imagesx($image);
$height = imagesy($image);

// Text colors
$textColor = imagecolorallocate($image, 30, 41, 59);
$labelColor = imagecolorallocate($image, 100, 116, 139);
$whiteColor = imagecolorallocate($image, 255, 255, 255);

// Calculate center positions
$centerX = $width / 2;

// Add text to template (adjust these coordinates based on your template)
$y = 100;

// Event Name (centered)
$eventNameText = substr($eventName, 0, 30);
$textWidth = strlen($eventNameText) * imagefontwidth(5);
$textX = ($width - $textWidth) / 2;
imagestring($image, 5, $textX, 80, $eventNameText, $textColor);

// Event Date
imagestring($image, 4, 50, 160, "DATE:", $labelColor);
imagestring($image, 4, 150, 160, $eventDate, $textColor);

// Event Time
imagestring($image, 4, 50, 190, "TIME:", $labelColor);
imagestring($image, 4, 150, 190, $eventTime, $textColor);

// Venue
imagestring($image, 4, 50, 220, "VENUE:", $labelColor);
imagestring($image, 4, 150, 220, substr($eventVenue, 0, 30), $textColor);

// Customer Name
imagestring($image, 5, 50, 280, "TICKET HOLDER:", $labelColor);
imagestring($image, 5, 220, 280, substr($ticket['name'], 0, 25), $textColor);

// Table
imagestring($image, 5, 50, 320, "TABLE:", $labelColor);
imagestring($image, 5, 150, 320, $ticket['table_id'], $textColor);

// Ticket Type
$typeText = $typeLabel . " Ticket #" . $ticketNumber;
imagestring($image, 4, 50, 360, "TICKET TYPE:", $labelColor);
imagestring($image, 4, 180, 360, $typeText, $textColor);

// Generate QR code and overlay onto template
$qrSize = 160;
$qrUrl = "https://quickchart.io/qr?text=" . urlencode($ticket['ticket_code']) . "&size={$qrSize}&margin=2";
$qrData = @file_get_contents($qrUrl);

if ($qrData) {
    $tempFile = tempnam(sys_get_temp_dir(), 'qr_');
    file_put_contents($tempFile, $qrData);
    $qrImage = imagecreatefrompng($tempFile);
    
    if ($qrImage) {
        $qrWidth = imagesx($qrImage);
        $qrHeight = imagesy($qrImage);
        
        // Center the QR code
        $qrX = ($width - $qrWidth) / 2;
        $qrY = 420;
        
        imagecopy($image, $qrImage, $qrX, $qrY, 0, 0, $qrWidth, $qrHeight);
        imagedestroy($qrImage);
    }
    unlink($tempFile);
}

// Add ticket code at the bottom
$ticketCodeText = "Ticket ID: " . $ticket['ticket_code'];
$textWidth = strlen($ticketCodeText) * imagefontwidth(3);
$textX = ($width - $textWidth) / 2;
imagestring($image, 3, $textX, 610, $ticketCodeText, $labelColor);

// Add footer
imagestring($image, 3, 150, 650, "Scan QR code at entrance", $labelColor);

// Output the final image
header('Content-Type: image/png');
header('Content-Disposition: inline; filename="ticket_' . $ticket['ticket_code'] . '.png"');
imagepng($image);
imagedestroy($image);
?>