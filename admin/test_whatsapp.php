<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$test_number = isset($_GET['phone']) ? $_GET['phone'] : '';
$result = false;
$message = '';

$instanceId = getSetting('ultramsg_instance_id', '');
$token = getSetting('ultramsg_token', '');

if ($test_number) {
    $test_message = "🎉 *Ultramsg Test Message*\n\n";
    $test_message .= "Your WhatsApp integration is working!\n\n";
    $test_message .= "✅ Ticketing System is connected\n";
    $test_message .= "📅 Time: " . date('Y-m-d H:i:s') . "\n\n";
    $test_message .= "You can now receive:\n";
    $test_message .= "• Reservation confirmations\n";
    $test_message .= "• Payment receipts\n";
    $test_message .= "• Event reminders\n";
    $test_message .= "• Digital tickets\n\n";
    $test_message .= "Thank you for using our system! 🎫";
    
    $result = sendWhatsAppMessage($test_number, $test_message);
    $message = $result ? "✓ Message sent successfully!" : "✗ Failed to send message";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test WhatsApp - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .card {
            background: white;
            border-radius: 24px;
            padding: 40px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 { color: #4f46e5; margin-bottom: 10px; }
        .status {
            background: #f1f5f9;
            padding: 15px;
            border-radius: 12px;
            margin: 20px 0;
        }
        .status-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-error { background: #fee2e2; color: #991b1b; }
        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-size: 16px;
            margin: 15px 0;
        }
        button {
            width: 100%;
            padding: 12px;
            background: #10b981;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { background: #059669; }
        .result { margin-top: 20px; padding: 15px; border-radius: 12px; text-align: center; }
        .result.success { background: #d1fae5; color: #065f46; }
        .result.error { background: #fee2e2; color: #991b1b; }
        a { display: block; text-align: center; margin-top: 20px; color: #64748b; }
    </style>
</head>
<body>
    <div class="card">
        <h1><i class="bi bi-whatsapp"></i> Test WhatsApp</h1>
        <p>Send a test message to verify your integration</p>
        
        <div class="status">
            <div class="status-item">
                <span>Instance ID:</span>
                <span class="<?php echo !empty($instanceId) ? 'badge-success' : 'badge-error'; ?> badge">
                    <?php echo !empty($instanceId) ? '✓ Configured' : '✗ Missing'; ?>
                </span>
            </div>
            <div class="status-item">
                <span>API Token:</span>
                <span class="<?php echo !empty($token) ? 'badge-success' : 'badge-error'; ?> badge">
                    <?php echo !empty($token) ? '✓ Configured' : '✗ Missing'; ?>
                </span>
            </div>
            <div class="status-item">
                <span>WhatsApp Enabled:</span>
                <span class="badge-success badge">✓ Enabled</span>
            </div>
        </div>
        
        <form method="GET">
            <input type="tel" name="phone" placeholder="Your phone number (e.g., 962797314111)" value="<?php echo htmlspecialchars($test_number); ?>" required>
            <button type="submit"><i class="bi bi-send"></i> Send Test Message</button>
        </form>
        
        <?php if ($message): ?>
            <div class="result <?php echo $result ? 'success' : 'error'; ?>">
                <i class="bi bi-<?php echo $result ? 'check-circle' : 'x-circle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <a href="settings.php"><i class="bi bi-gear"></i> Go to Settings</a>
    </div>
</body>
</html>