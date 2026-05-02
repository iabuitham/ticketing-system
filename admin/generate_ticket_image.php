<?php
// Public ticket image generator - No admin session required
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
$eventDate = date('F j, Y', strtotime('+30 days'));
$eventTime = '6:00 PM';
$eventVenue = 'Grand Hall, Amman';
$typeLabel = ucfirst($ticket['guest_type']);
$ticketNumber = str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT);

// Create image
$width = 500;
$height = 700;
$image = imagecreatetruecolor($width, $height);

// Colors
$white = imagecolorallocate($image, 255, 255, 255);
$purple = imagecolorallocate($image, 79, 70, 229);
$dark = imagecolorallocate($image, 30, 41, 59);
$gray = imagecolorallocate($image, 100, 116, 139);

// Fill background
imagefill($image, 0, 0, $white);

// Draw header
imagefilledrectangle($image, 0, 0, $width, 180, $purple);

// Add header text
$white_color = imagecolorallocate($image, 255, 255, 255);
imagestring($image, 5, 150, 40, "ENTRY TICKET", $white_color);
imagestring($image, 4, 160, 70, strtoupper($typeLabel), $white_color);

// Add event name
imagestring($image, 5, 30, 210, "EVENT:", $gray);
imagestring($image, 5, 120, 210, substr($eventName, 0, 35), $dark);

// Add date
imagestring($image, 4, 30, 245, "DATE:", $gray);
imagestring($image, 4, 120, 245, $eventDate, $dark);

// Add time
imagestring($image, 4, 30, 275, "TIME:", $gray);
imagestring($image, 4, 120, 275, $eventTime, $dark);

// Add venue
imagestring($image, 4, 30, 305, "VENUE:", $gray);
imagestring($image, 4, 120, 305, substr($eventVenue, 0, 35), $dark);

// Add customer name
imagestring($image, 5, 30, 350, "TICKET HOLDER:", $gray);
imagestring($image, 5, 200, 350, substr($ticket['name'], 0, 25), $dark);

// Add table
imagestring($image, 5, 30, 385, "TABLE:", $gray);
imagestring($image, 5, 120, 385, $ticket['table_id'], $dark);

// Add ticket number
imagestring($image, 4, 30, 415, "TICKET #:", $gray);
imagestring($image, 4, 130, 415, $ticketNumber, $dark);

// Generate QR code
$qrSize = 160;
$qrUrl = "https://quickchart.io/qr?text=" . urlencode($ticket['ticket_code']) . "&size={$qrSize}&margin=2";
$qrData = @file_get_contents($qrUrl);

if ($qrData) {
    $qrImage = imagecreatefromstring($qrData);
    if ($qrImage) {
        $qrWidth = imagesx($qrImage);
        $qrHeight = imagesy($qrImage);
        $qrX = ($width - $qrWidth) / 2;
        $qrY = 470;
        imagecopy($image, $qrImage, $qrX, $qrY, 0, 0, $qrWidth, $qrHeight);
        imagedestroy($qrImage);
    }
}

// Add ticket ID
$ticketIdText = "ID: " . $ticket['ticket_code'];
$textWidth = strlen($ticketIdText) * imagefontwidth(3);
$textX = ($width - $textWidth) / 2;
imagestring($image, 3, $textX, 650, $ticketIdText, $gray);

// Add footer
imagestring($image, 3, 150, 675, "Scan QR code at entrance", $gray);

// Output image
header('Content-Type: image/png');
header('Content-Disposition: inline; filename="ticket_' . $ticket['ticket_code'] . '.png"');
imagepng($image);
imagedestroy($image);
?>