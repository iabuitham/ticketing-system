<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

echo "<h2>Test WhatsApp Text</h2>";

// Show current settings
$instanceId = getSetting('ultramsg_instance_id', '');
$token = getSetting('ultramsg_token', '');
$enabled = getSetting('enable_whatsapp', '0');

echo "<div style='background:#f0f0f0; padding:10px; margin-bottom:20px;'>";
echo "<strong>Current Settings:</strong><br>";
echo "Instance ID: " . (!empty($instanceId) ? substr($instanceId, 0, 10) . "..." : 'NOT SET') . "<br>";
echo "Token: " . (!empty($token) ? substr($token, 0, 10) . "..." : 'NOT SET') . "<br>";
echo "WhatsApp Enabled: " . ($enabled == '1' ? 'YES' : 'NO') . "<br>";
echo "</div>";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = $_POST['phone'];
    
    $message = "✅ Test message from Ticketing System!\n";
    $message .= "Time: " . date('Y-m-d H:i:s') . "\n";
    $message .= "If you receive this, text messaging is working.";
    
    $result = sendWhatsAppMessage($phone, $message);
    
    if ($result) {
        echo "<p style='color:green'>✅ Text message sent successfully to $phone</p>";
    } else {
        echo "<p style='color:red'>❌ Failed to send text message.</p>";
        echo "<p>Check that your Ultramsg instance is connected and you have credits.</p>";
    }
}
?>
<form method="POST">
    <label>Phone Number (Jordan):</label>
    <input type="text" name="phone" placeholder="79XXXXXXX" required>
    <small>Enter without country code (e.g., 797314111)</small>
    <br><br>
    <button type="submit">Send Test Text</button>
</form>
<p>The system will automatically add the Jordan country code (962).</p>