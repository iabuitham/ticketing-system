<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

echo "<h2>Test WhatsApp Image</h2>";

// Create temp directory
$tempDir = '../uploads/temp_tickets/';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
    echo "<p>Created temp directory: $tempDir</p>";
}

// First, create a test image
$testImageUrl = "https://quickchart.io/qr?text=TEST-IMAGE&size=200&margin=2";
$testImageData = @file_get_contents($testImageUrl);

if ($testImageData) {
    $tempFile = $tempDir . 'test_image.png';
    file_put_contents($tempFile, $testImageData);
    echo "<p>✅ Test image created at: " . realpath($tempFile) . "</p>";
    echo "<img src='../uploads/temp_tickets/test_image.png' width='100'><br>";
} else {
    echo "<p style='color:red'>❌ Failed to create test image. Check internet connection.</p>";
}

// Show current settings
$instanceId = getSetting('ultramsg_instance_id', '');
$token = getSetting('ultramsg_token', '');
$enabled = getSetting('enable_whatsapp', '0');

echo "<div style='background:#f0f0f0; padding:10px; margin:20px 0;'>";
echo "<strong>Current Ultramsg Settings:</strong><br>";
echo "Instance ID: " . (!empty($instanceId) ? substr($instanceId, 0, 10) . "..." : '❌ NOT SET') . "<br>";
echo "Token: " . (!empty($token) ? substr($token, 0, 10) . "..." : '❌ NOT SET') . "<br>";
echo "WhatsApp Enabled: " . ($enabled == '1' ? '✅ YES' : '❌ NO') . "<br>";
echo "</div>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = $_POST['phone'];
    $imageSource = $_POST['image_source'];
    $caption = "🎫 Test QR Code\nTicket: TEST-001\nValid for testing only";
    
    if ($imageSource == 'url') {
        $imagePath = "https://quickchart.io/qr?text=TEST-URL-IMAGE&size=200&margin=2";
        echo "<p>Using URL: $imagePath</p>";
    } else {
        $imagePath = $tempFile;
        echo "<p>Using file: $imagePath</p>";
    }
    
    if (file_exists($imagePath) || filter_var($imagePath, FILTER_VALIDATE_URL)) {
        echo "<p>Sending to: $phone</p>";
        $result = sendWhatsAppImage($phone, $imagePath, $caption);
        
        if ($result) {
            echo "<p style='color:green'>✅ Image sent successfully to $phone</p>";
        } else {
            echo "<p style='color:red'>❌ Failed to send image.</p>";
            echo "<p>Check the error logs for details.</p>";
        }
    } else {
        echo "<p style='color:red'>❌ Image source not found: $imagePath</p>";
    }
}
?>
<form method="POST">
    <label>Phone Number (Jordan):</label>
    <input type="text" name="phone" placeholder="79XXXXXXX" required>
    <small>Enter without country code (e.g., 797314111)</small>
    <br><br>
    <label>Image Source:</label>
    <select name="image_source">
        <option value="file">Local File (temp_tickets/test_image.png)</option>
        <option value="url">URL (quickchart.io)</option>
    </select>
    <br><br>
    <button type="submit">Send Test Image</button>
</form>