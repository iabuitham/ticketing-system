<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$ticket_code = isset($_GET['ticket_code']) ? sanitizeInput($_GET['ticket_code']) : '';

if (empty($ticket_code)) {
    die('No ticket code provided');
}

$conn = getConnection();

// Get ticket details
$stmt = $conn->prepare("SELECT t.*, r.name, r.phone, r.table_id, r.reservation_id 
                        FROM ticket_codes t 
                        JOIN reservations r ON t.reservation_id = r.reservation_id 
                        WHERE t.ticket_code = ?");
$stmt->bind_param("s", $ticket_code);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$ticket) {
    die('Ticket not found');
}

$eventName = getSetting('site_name', 'Event Ticket');
$typeLabel = ucfirst($ticket['guest_type']);
$currencySymbol = getCurrencySymbol();
$baseUrl = getSetting('base_url', 'http://localhost/ticketing-system/');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Ticket - <?php echo htmlspecialchars($ticket['ticket_code']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #e2e8f0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .ticket {
            max-width: 500px;
            width: 100%;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            position: relative;
        }
        .ticket::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 8px;
            background: linear-gradient(90deg, #4f46e5, #10b981, #f59e0b, #ef4444);
        }
        .header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 { font-size: 28px; margin-bottom: 5px; letter-spacing: 2px; }
        .header p { opacity: 0.8; font-size: 14px; }
        .body { padding: 30px; }
        .info-row {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px dashed #e2e8f0;
        }
        .label {
            font-size: 11px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
        }
        .value {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            word-break: break-all;
        }
        .qr-section {
            text-align: center;
            margin: 20px 0;
            padding: 20px;
            background: #f8fafc;
            border-radius: 16px;
        }
        .qr-code {
            font-family: monospace;
            font-size: 14px;
            background: white;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            word-break: break-all;
        }
        .footer {
            background: #f8fafc;
            padding: 20px;
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #fee2e2; color: #991b1b; }
        @media print {
            body { background: white; padding: 0; }
            .ticket { box-shadow: none; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="header">
            <h1>🎟️ ENTRY PASS</h1>
            <p><?php echo $typeLabel; ?> Ticket</p>
        </div>
        <div class="body">
            <div class="info-row">
                <div class="label">Event</div>
                <div class="value"><?php echo htmlspecialchars($eventName); ?></div>
            </div>
            <div class="info-row">
                <div class="label">Customer</div>
                <div class="value"><?php echo htmlspecialchars($ticket['name']); ?></div>
            </div>
            <div class="info-row">
                <div class="label">Table / Seat</div>
                <div class="value">Table <?php echo htmlspecialchars($ticket['table_id']); ?></div>
            </div>
            
            <div class="qr-section">
                <div class="qr-code">
                    <strong>Ticket ID:</strong><br>
                    <?php echo htmlspecialchars($ticket['ticket_code']); ?>
                </div>
                <div style="margin-top: 15px;">
                    <img src="https://quickchart.io/qr?text=<?php echo urlencode($ticket['ticket_code']); ?>&size=180&margin=2" alt="QR Code">
                </div>
            </div>
            
            <div class="info-row">
                <div class="label">Ticket Type</div>
                <div class="value"><?php echo $typeLabel; ?> Ticket #<?php echo str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT); ?></div>
            </div>
            <div class="info-row">
                <div class="label">Status</div>
                <div class="value">
                    <?php if ($ticket['is_scanned']): ?>
                        <span class="status-badge status-inactive">✗ USED</span>
                    <?php elseif ($ticket['is_active']): ?>
                        <span class="status-badge status-active">✓ ACTIVE</span>
                    <?php else: ?>
                        <span class="status-badge status-inactive">✗ INACTIVE</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="footer">
            <p>Please present this ticket at the entrance</p>
            <p>QR code will be scanned for entry • One time use only</p>
            <p>© <?php echo date('Y'); ?> <?php echo htmlspecialchars($eventName); ?></p>
        </div>
    </div>
    <div class="no-print" style="position: fixed; bottom: 20px; right: 20px;">
        <button onclick="window.print()" style="padding: 12px 20px; background: #4f46e5; color: white; border: none; border-radius: 12px; cursor: pointer;">
            🖨️ Print / Save as PDF
        </button>
    </div>
</body>
</html>