<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Get a ticket code to test
$conn = getConnection();
$result = $conn->query("SELECT ticket_code FROM ticket_codes LIMIT 1");
$ticket = $result->fetch_assoc();
$conn->close();

if ($ticket) {
    $baseUrl = getSetting('base_url', 'http://localhost/ticketing-system/');
    $imageUrl = $baseUrl . "admin/generate_ticket_image.php?ticket_code=" . urlencode($ticket['ticket_code']);
    
    echo "<h2>Testing Ticket Image URL</h2>";
    echo "<p>Ticket Code: " . htmlspecialchars($ticket['ticket_code']) . "</p>";
    echo "<p>Image URL: <a href='$imageUrl' target='_blank'>$imageUrl</a></p>";
    echo "<p>Click the link to see if the image loads in your browser.</p>";
    echo "<p>If you see an image, the URL works. If you see an error, we need to fix the image generation.</p>";
    
    // Also test sending a test image via WhatsApp
    echo "<hr>";
    echo "<h3>Send Test Image via WhatsApp</h3>";
    echo '<form method="POST">';
    echo '<input type="text" name="phone" placeholder="Phone number (e.g., 962797314111)" required>';
    echo '<button type="submit" name="send_test">Send Test Image</button>';
    echo '</form>';
    
    if (isset($_POST['send_test'])) {
        $phone = $_POST['phone'];
        $result = sendWhatsAppImage($phone, $imageUrl, "Test Ticket Image");
        if ($result) {
            echo "<p style='color: green;'>✓ Test image sent successfully!</p>";
        } else {
            echo "<p style='color: red;'>✗ Failed to send test image. Check error logs.</p>";
        }
    }
} else {
    echo "No tickets found. Create a reservation first.";
}
?>