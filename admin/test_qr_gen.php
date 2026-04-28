<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

echo "<h2>QR Code Generator Test</h2>";

$test_ticket = 'TEST-TICKET-001';
$qrUrl = "https://quickchart.io/qr?text=" . urlencode($test_ticket) . "&size=250&margin=2";

echo "<p>QR URL: <a href='$qrUrl' target='_blank'>$qrUrl</a></p>";
echo "<img src='$qrUrl'><br>";

// Try to download the QR code
$qrData = @file_get_contents($qrUrl);
if ($qrData) {
    echo "<p style='color:green'>✅ QR code downloaded successfully! (" . strlen($qrData) . " bytes)</p>";
    
    // Save to file
    $tempDir = '../uploads/temp_tickets/';
    if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);
    $tempFile = $tempDir . 'test.png';
    file_put_contents($tempFile, $qrData);
    echo "<p>✅ Saved to: " . realpath($tempFile) . "</p>";
} else {
    echo "<p style='color:red'>❌ Failed to download QR code. Check internet connection.</p>";
}

// Check Ultramsg credentials
$instanceId = getSetting('ultramsg_instance_id', '');
$token = getSetting('ultramsg_token', '');
$enabled = getSetting('enable_whatsapp', '0');

echo "<h3>Ultramsg Settings:</h3>";
echo "<p>Instance ID: " . (!empty($instanceId) ? substr($instanceId, 0, 10) . "..." : '❌ NOT SET') . "</p>";
echo "<p>Token: " . (!empty($token) ? substr($token, 0, 10) . "..." : '❌ NOT SET') . "</p>";
echo "<p>WhatsApp Enabled: " . ($enabled == '1' ? '✅ YES' : '❌ NO') . "</p>";
?>