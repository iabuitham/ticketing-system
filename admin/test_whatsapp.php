<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$test_result = '';
$test_phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $test_phone = sanitizeInput($_POST['phone']);
    $test_message = "🧪 *Test Message from Ticketing System* 🧪\n\n";
    $test_message .= "This is a test message to verify WhatsApp integration.\n";
    $test_message .= "If you receive this, your WhatsApp configuration is working correctly! ✅\n\n";
    $test_message .= "Time: " . date('Y-m-d H:i:s');
    
    $result = sendWhatsAppMessage($test_phone, $test_message);
    
    if ($result) {
        $test_result = '<div class="alert alert-success">✅ Test message sent successfully to ' . htmlspecialchars($test_phone) . '!</div>';
    } else {
        $test_result = '<div class="alert alert-error">❌ Failed to send test message. Check error logs.</div>';
    }
}

// Get current settings
$enabled = getSetting('enable_whatsapp', '0');
$instanceId = getSetting('ultramsg_instance_id', '');
$token = getSetting('ultramsg_token', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test WhatsApp</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .container { max-width: 600px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 { margin-bottom: 20px; color: #333; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 12px;
            font-size: 14px;
        }
        .btn {
            padding: 12px 24px;
            background: #25D366;
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
        }
        .btn:hover { background: #128C7E; }
        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .settings-info {
            background: #f8fafc;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .settings-info h3 { margin-bottom: 10px; color: #4f46e5; }
        .status-enabled { color: #10b981; font-weight: bold; }
        .status-disabled { color: #ef4444; font-weight: bold; }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #4f46e5;
            text-decoration: none;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="card">
        <h1>📱 Test WhatsApp Integration</h1>
        
        <div class="settings-info">
            <h3>Current Settings:</h3>
            <p>📡 WhatsApp Enabled: <span class="<?php echo $enabled == '1' ? 'status-enabled' : 'status-disabled'; ?>"><?php echo $enabled == '1' ? '✅ YES' : '❌ NO'; ?></span></p>
            <p>🔑 Instance ID: <?php echo $instanceId ? '✅ Set (' . substr($instanceId, 0, 10) . '...)' : '❌ Not set'; ?></p>
            <p>🎫 Token: <?php echo $token ? '✅ Set (' . substr($token, 0, 10) . '...)' : '❌ Not set'; ?></p>
        </div>
        
        <?php if ($enabled != '1'): ?>
            <div class="alert alert-error">
                ⚠️ WhatsApp is disabled in System Settings. Please enable it first.
            </div>
        <?php elseif (empty($instanceId) || empty($token)): ?>
            <div class="alert alert-error">
                ⚠️ Ultramsg credentials are missing. Please configure them in System Settings.
            </div>
        <?php endif; ?>
        
        <?php echo $test_result; ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Phone Number (with country code, e.g., 9627XXXXXXXX)</label>
                <input type="tel" name="phone" placeholder="9627XXXXXXXX" value="<?php echo htmlspecialchars($test_phone); ?>" required>
            </div>
            <button type="submit" class="btn">📤 Send Test Message</button>
        </form>
        
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
    </div>
</div>
</body>
</html>