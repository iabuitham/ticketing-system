<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Get credentials directly from database
$conn = getConnection();
$result = $conn->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('ultramsg_instance_id', 'ultramsg_token', 'enable_whatsapp')");
$settings = [];
while ($row = $result->fetch_assoc()) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$conn->close();

echo "<h2>Direct API Test</h2>";

$instanceId = $settings['ultramsg_instance_id'] ?? '';
$token = $settings['ultramsg_token'] ?? '';
$enabled = $settings['enable_whatsapp'] ?? '0';

echo "<pre>";
echo "Instance ID: " . ($instanceId ? substr($instanceId, 0, 10) . "..." : "NOT SET") . "\n";
echo "Token: " . ($token ? substr($token, 0, 10) . "..." : "NOT SET") . "\n";
echo "Enabled: " . ($enabled == '1' ? "YES" : "NO") . "\n";
echo "</pre>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($instanceId) && !empty($token)) {
    $phone = $_POST['phone'];
    
    // Clean phone number
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 1) == '0') $phone = substr($phone, 1);
    if (substr($phone, 0, 3) != '962') $phone = '962' . $phone;
    
    $qrUrl = "https://quickchart.io/qr?text=DIRECT-TEST&size=200&margin=2";
    
    $data = [
        'token' => $token,
        'to' => $phone,
        'image' => $qrUrl,
        'caption' => "Direct API Test QR Code"
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.ultramsg.com/{$instanceId}/messages/image");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo "<h3>API Response:</h3>";
    echo "<pre>";
    echo "HTTP Code: $httpCode\n";
    echo "Response: " . print_r(json_decode($response, true), true);
    echo "</pre>";
    
    if ($httpCode == 200) {
        echo "<p style='color:green'>✅ Image sent successfully!</p>";
    } else {
        echo "<p style='color:red'>❌ Failed to send image.</p>";
    }
}
?>
<form method="POST">
    <label>Phone Number:</label>
    <input type="text" name="phone" placeholder="79XXXXXXX" required>
    <button type="submit">Send Direct Test</button>
</form>