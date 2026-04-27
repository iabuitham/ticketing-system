<?php
// No session needed for image generation - remove to avoid errors
// session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

$ticket_code = isset($_GET['ticket_code']) ? $_GET['ticket_code'] : '';

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

$eventName = getSetting('site_name', 'Event Ticket');
$typeLabel = ucfirst($ticket['guest_type']);
$ticketNumber = str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT);

// Create image
$width = 500;
$height = 700;

// Create image canvas
$image = imagecreatetruecolor($width, $height);

// Colors
$white = imagecolorallocate($image, 255, 255, 255);
$black = imagecolorallocate($image, 0, 0, 0);
$purple = imagecolorallocate($image, 79, 70, 229);
$dark = imagecolorallocate($image, 30, 41, 59);
$gray = imagecolorallocate($image, 100, 116, 139);
$light_gray = imagecolorallocate($image, 241, 245, 249);
$green = imagecolorallocate($image, 16, 185, 129);
$yellow = imagecolorallocate($image, 245, 158, 11);

// Fill background
imagefill($image, 0, 0, $white);

// Draw header
imagefilledrectangle($image, 0, 0, $width, 180, $purple);

// Add header text
$text_color = $white;
imagestring($image, 5, 20, 30, "ENTRY PASS", $text_color);
imagestring($image, 5, 20, 55, $typeLabel . " TICKET", $text_color);
imagestring($image, 3, 20, 80, "Ticket #" . $ticketNumber, $text_color);

// Ticket body
$y = 200;

// Event name
imagestring($image, 5, 20, $y, "EVENT: " . substr($eventName, 0, 35), $dark);
$y += 30;

// Customer name
imagestring($image, 5, 20, $y, "CUSTOMER: " . substr($ticket['name'], 0, 30), $dark);
$y += 25;

// Phone
imagestring($image, 4, 20, $y, "Phone: " . $ticket['phone'], $gray);
$y += 25;

// Table
imagestring($image, 4, 20, $y, "Table: " . $ticket['table_id'], $gray);
$y += 25;

// Separator line
imageline($image, 20, $y, $width - 20, $y, $gray);
$y += 20;

// QR Code section
$qrSize = 150;
$qrX = ($width - $qrSize) / 2;
$qrY = $y;

// Draw QR code background
imagefilledrectangle($image, $qrX - 10, $qrY - 10, $qrX + $qrSize + 10, $qrY + $qrSize + 10, $light_gray);
imagerectangle($image, $qrX - 10, $qrY - 10, $qrX + $qrSize + 10, $qrY + $qrSize + 10, $purple);

// Generate QR code
$qrUrl = "https://quickchart.io/qr?text=" . urlencode($ticket['ticket_code']) . "&size={$qrSize}&margin=2";
$qrData = @file_get_contents($qrUrl);
if ($qrData) {
    $qrImage = imagecreatefromstring($qrData);
    if ($qrImage) {
        imagecopy($image, $qrImage, $qrX, $qrY, 0, 0, $qrSize, $qrSize);
        imagedestroy($qrImage);
    }
}

$y += $qrSize + 30;

// Ticket code
$ticketCodeText = "Ticket ID: " . $ticket['ticket_code'];
$textWidth = strlen($ticketCodeText) * imagefontwidth(4);
$textX = ($width - $textWidth) / 2;
imagestring($image, 4, $textX, $y, $ticketCodeText, $gray);
$y += 25;

// Status
$statusText = "STATUS: VALID";
imagestring($image, 5, 20, $y, $statusText, $green);

// Footer
imagefilledrectangle($image, 0, $height - 50, $width, $height, $light_gray);
imagestring($image, 3, 20, $height - 40, "Scan QR code at entrance", $gray);
imagestring($image, 3, $width - 180, $height - 40, "One time use only", $gray);

// Output as PNG
header('Content-Type: image/png');
header('Content-Disposition: inline; filename="ticket_' . $ticket['ticket_code'] . '.png"');
imagepng($image);
imagedestroy($image);
?>