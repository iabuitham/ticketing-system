<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$message = '';
$messageType = '';
$scannedTicket = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ticket_code'])) {
    $ticketCode = sanitizeInput($_POST['ticket_code']);
    
    $conn = getConnection();
    
    // Check if ticket exists
    $query = "SELECT tc.*, r.name, r.table_id, r.reservation_id, r.status as reservation_status
              FROM ticket_codes tc 
              JOIN reservations r ON tc.reservation_id = r.reservation_id 
              WHERE tc.ticket_code = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $ticketCode);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $ticket = $result->fetch_assoc();
        
        if ($ticket['reservation_status'] != 'paid') {
            $message = "❌ Reservation not paid. Please complete payment first.";
            $messageType = 'error';
        } elseif ($ticket['is_scanned']) {
            $message = "⚠️ Ticket already scanned on " . date('Y-m-d H:i:s', strtotime($ticket['scanned_at']));
            $messageType = 'warning';
        } else {
            // Mark as scanned
            $update = $conn->prepare("UPDATE ticket_codes SET is_scanned = TRUE, scanned_at = NOW() WHERE id = ?");
            $update->bind_param("i", $ticket['id']);
            if ($update->execute()) {
                $message = "✅ Ticket VALID! Welcome " . htmlspecialchars($ticket['name']) . "! (Table: " . $ticket['table_id'] . ")";
                $messageType = 'success';
                $scannedTicket = $ticket;
            } else {
                $message = "❌ Error scanning ticket";
                $messageType = 'error';
            }
            $update->close();
        }
    } else {
        $message = "❌ Invalid ticket code!";
        $messageType = 'error';
    }
    
    $stmt->close();
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Scan Ticket - Ticketing System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 600px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 { margin-bottom: 10px; color: #333; }
        .subtitle { color: #666; margin-bottom: 30px; }
        input {
            width: 100%;
            padding: 15px;
            font-size: 16px;
            font-family: monospace;
            border: 2px solid #ddd;
            border-radius: 12px;
            margin-bottom: 15px;
            text-align: center;
        }
        button {
            width: 100%;
            padding: 15px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            cursor: pointer;
        }
        .message {
            padding: 15px;
            border-radius: 12px;
            margin-top: 20px;
        }
        .message.success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message.warning { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }
        .btn-back {
            display: inline-block;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
        }
        .ticket-info {
            margin-top: 20px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        @media (max-width: 600px) {
            .card { padding: 20px; }
            input { font-size: 14px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>🎫 Ticket Scanner</h1>
            <p class="subtitle">Scan QR code or enter ticket code to validate entry</p>
            
            <?php if ($message): ?>
                <div class="message <?php echo $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST">
                <input type="text" name="ticket_code" placeholder="Enter or scan ticket code..." autofocus>
                <button type="submit">Validate Ticket</button>
            </form>
            
            <?php if ($scannedTicket): ?>
                <div class="ticket-info">
                    <div class="info-row">
                        <span>Ticket Code:</span>
                        <strong><?php echo htmlspecialchars($scannedTicket['ticket_code']); ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Customer:</span>
                        <strong><?php echo htmlspecialchars($scannedTicket['name']); ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Table:</span>
                        <strong><?php echo htmlspecialchars($scannedTicket['table_id']); ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Check-in Time:</span>
                        <strong><?php echo date('H:i:s'); ?></strong>
                    </div>
                </div>
            <?php endif; ?>
            
            <div style="text-align: center;">
                <a href="dashboard.php" class="btn-back">← Back to Dashboard</a>
            </div>
        </div>
    </div>
    
    <script>
        // Auto-focus on input
        document.querySelector('input')?.focus();
        
        // Clear input after 3 seconds for continuous scanning
        let timeout;
        document.querySelector('form')?.addEventListener('submit', function() {
            timeout = setTimeout(() => {
                document.querySelector('input').value = '';
                document.querySelector('input').focus();
            }, 3000);
        });
    </script>
</body>
</html>