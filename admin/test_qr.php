<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$test_ticket_code = 'TEST-TICKET-001';

// Generate QR code and save to file
$qrUrl = "https://quickchart.io/qr?text=" . urlencode($test_ticket_code) . "&size=250&margin=2";
$qrImageData = @file_get_contents($qrUrl);

if ($qrImageData) {
    $tempDir = '../uploads/temp_tickets/';
    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
    $tempFile = $tempDir . 'test_qr.png';
    file_put_contents($tempFile, $qrImageData);
    
    echo "<h2>QR Code Generated Successfully!</h2>";
    echo "<img src='../uploads/temp_tickets/test_qr.png' width='200'><br>";
    echo "File saved to: " . realpath($tempFile) . "<br>";
    
    // Test sending via WhatsApp
    echo "<h3>Send Test QR Code to WhatsApp</h3>";
    echo '<form method="POST">';
    echo '<label>Phone Number (Jordan, e.g., 96279XXXXXXX):</label><br>';
    echo '<input type="text" name="phone" placeholder="962797314111" required><br><br>';
    echo '<button type="submit" name="send_test">Send Test QR Code</button>';
    echo '</form>';
    
    if (isset($_POST['send_test'])) {
        $phone = $_POST['phone'];
        $caption = "🎫 Test QR Code\nTicket ID: {$test_ticket_code}";
        
        $result = sendWhatsAppImage($phone, $tempFile, $caption);
        
        if ($result) {
            echo "<p style='color: green;'>✅ QR code sent successfully to {$phone}!</p>";
        } else {
            echo "<p style='color: red;'>❌ Failed to send. Check error logs.</p>";
        }
    }
} else {
    echo "<p style='color: red;'>Failed to generate QR code. Check internet connection.</p>";
}
?>