<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

echo "<h1>WhatsApp Debug</h1>";

// Test 1: Check if functions exist
echo "<h3>1. Function Check:</h3>";
echo "sendWhatsAppMessage exists: " . (function_exists('sendWhatsAppMessage') ? '✅ Yes' : '❌ No') . "<br>";
echo "sendWhatsAppImage exists: " . (function_exists('sendWhatsAppImage') ? '✅ Yes' : '❌ No') . "<br>";
echo "sendAllTicketsAsImages exists: " . (function_exists('sendAllTicketsAsImages') ? '✅ Yes' : '❌ No') . "<br>";

// Test 2: Check settings
echo "<h3>2. Settings Check:</h3>";
$instanceId = getSetting('ultramsg_instance_id', '');
$token = getSetting('ultramsg_token', '');
$enabled = getSetting('enable_whatsapp', '0');

echo "Instance ID: " . (!empty($instanceId) ? '✅ Set (' . substr($instanceId, 0, 10) . '...)' : '❌ Not set') . "<br>";
echo "Token: " . (!empty($token) ? '✅ Set (' . substr($token, 0, 10) . '...)' : '❌ Not set') . "<br>";
echo "WhatsApp Enabled: " . ($enabled == '1' ? '✅ Yes' : '❌ No') . "<br>";

// Test 3: Send a simple text message
echo "<h3>3. Send Test Text:</h3>";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $testPhone = $_POST['phone'];
    $testMessage = "✅ Test message from Ticketing System\nTime: " . date('Y-m-d H:i:s');
    
    $result = sendWhatsAppMessage($testPhone, $testMessage);
    if ($result) {
        echo "<p style='color:green'>✅ Text message sent to $testPhone</p>";
    } else {
        echo "<p style='color:red'>❌ Failed to send text message</p>";
    }
}

// Test 4: Send a test QR code
echo "<h3>4. Send Test QR Code:</h3>";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_qr'])) {
    $testPhone = $_POST['phone'];
    $testQrUrl = "https://quickchart.io/qr?text=TEST-QR-CODE&size=200&margin=2";
    $caption = "🎫 Test QR Code\nFor: " . $testPhone;
    
    $result = sendWhatsAppImage($testPhone, $testQrUrl, $caption);
    if ($result) {
        echo "<p style='color:green'>✅ QR code sent to $testPhone</p>";
    } else {
        echo "<p style='color:red'>❌ Failed to send QR code</p>";
    }
}
?>
<form method="POST">
    <label>Your Phone Number (Jordan):</label>
    <input type="text" name="phone" placeholder="79XXXXXXX" required>
    <button type="submit">Send Test Text</button>
    <button type="submit" name="send_qr" value="1">Send Test QR Code</button>
</form>
<p>Enter your number without country code (e.g., 797314111)</p>