<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();
$scan_result = '';
$scan_status = '';

// Handle scanned QR code
if (isset($_POST['scanned_ticket'])) {
    $ticket_code = sanitizeInput($_POST['ticket_code']);
    
    // Find ticket
    $stmt = $conn->prepare("SELECT t.*, r.name, r.phone, r.table_id, r.reservation_id 
                            FROM ticket_codes t 
                            JOIN reservations r ON t.reservation_id = r.reservation_id 
                            WHERE t.ticket_code = ?");
    $stmt->bind_param("s", $ticket_code);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($ticket) {
        if ($ticket['is_scanned'] == 1) {
            $scan_status = 'error';
            $scan_result = "❌ Ticket already used!<br>
                           Used at: " . date('M d, Y H:i:s', strtotime($ticket['scanned_at'])) . "<br>
                           Customer: " . htmlspecialchars($ticket['name']);
        } else {
            // Mark as scanned
            $update = $conn->prepare("UPDATE ticket_codes SET is_scanned = 1, scanned_at = NOW() WHERE id = ?");
            $update->bind_param("i", $ticket['id']);
            $update->execute();
            $update->close();
            
            $scan_status = 'success';
            $scan_result = "✅ Ticket Valid!<br>
                           Ticket Type: " . ucfirst($ticket['guest_type']) . "<br>
                           Customer: " . htmlspecialchars($ticket['name']) . "<br>
                           Table: " . htmlspecialchars($ticket['table_id']) . "<br>
                           Reservation: " . htmlspecialchars($ticket['reservation_id']);
        }
    } else {
        $scan_status = 'error';
        $scan_result = "❌ Invalid Ticket ID!";
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code Scanner - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 { color: #4f46e5; margin-bottom: 10px; }
        .scan-area {
            text-align: center;
            padding: 40px;
            background: #f8fafc;
            border-radius: 16px;
            margin-bottom: 20px;
        }
        .scan-icon { font-size: 80px; color: #4f46e5; margin-bottom: 20px; }
        input {
            width: 100%;
            padding: 15px;
            font-size: 18px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            text-align: center;
            font-family: monospace;
        }
        input:focus {
            outline: none;
            border-color: #4f46e5;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            margin-top: 15px;
        }
        .btn-primary { background: #4f46e5; color: white; width: 100%; }
        .result {
            margin-top: 20px;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }
        .result.success { background: #d1fae5; color: #065f46; }
        .result.error { background: #fee2e2; color: #991b1b; }
        .btn-back {
            display: inline-block;
            margin-top: 20px;
            text-decoration: none;
            color: #4f46e5;
        }
        .demo-note {
            background: #e0e7ff;
            padding: 12px;
            border-radius: 12px;
            margin-top: 20px;
            font-size: 12px;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1><i class="bi bi-upc-scan"></i> QR Code Scanner</h1>
                <p>Scan or enter ticket ID to validate entry</p>
            </div>
            
            <form method="POST">
                <div class="scan-area">
                    <div class="scan-icon">
                        <i class="bi bi-upc-scan"></i>
                    </div>
                    <input type="text" name="ticket_code" id="ticket_code" placeholder="Enter or scan ticket ID" autofocus>
                    <button type="submit" name="scanned_ticket" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Validate Ticket
                    </button>
                </div>
            </form>
            
            <?php if ($scan_result): ?>
                <div class="result <?php echo $scan_status; ?>">
                    <?php echo $scan_result; ?>
                </div>
            <?php endif; ?>
            
            <div class="demo-note">
                <i class="bi bi-info-circle"></i> 
                You can use a USB barcode scanner or enter the ticket ID manually.
                <br>Once scanned, the ticket will be marked as used.
            </div>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="dashboard.php" class="btn-back"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>
    </div>
    
    <script>
        document.getElementById('ticket_code').focus();
        
        // Optional: Clear input after submission for continuous scanning
        <?php if ($scan_result): ?>
        document.getElementById('ticket_code').value = '';
        document.getElementById('ticket_code').focus();
        <?php endif; ?>
    </script>
</body>
</html>