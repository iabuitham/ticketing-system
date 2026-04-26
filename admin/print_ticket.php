<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$ticket_code = isset($_GET['ticket_code']) ? sanitizeInput($_GET['ticket_code']) : '';
$reservation_id = isset($_GET['reservation_id']) ? sanitizeInput($_GET['reservation_id']) : '';

$conn = getConnection();

if ($ticket_code) {
    // Get single ticket
    $stmt = $conn->prepare("SELECT t.*, r.name, r.phone, r.table_id, r.total_amount, r.reservation_id 
                            FROM ticket_codes t 
                            JOIN reservations r ON t.reservation_id = r.reservation_id 
                            WHERE t.ticket_code = ?");
    $stmt->bind_param("s", $ticket_code);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$ticket) {
        header('Location: dashboard.php');
        exit();
    }
    
    $typeLabel = ucfirst($ticket['guest_type']);
    
} elseif ($reservation_id) {
    // Get first ticket from reservation
    $stmt = $conn->prepare("SELECT t.*, r.name, r.phone, r.table_id, r.total_amount, r.reservation_id 
                            FROM ticket_codes t 
                            JOIN reservations r ON t.reservation_id = r.reservation_id 
                            WHERE t.reservation_id = ? 
                            LIMIT 1");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$ticket) {
        header('Location: dashboard.php');
        exit();
    }
    
    $typeLabel = ucfirst($ticket['guest_type']);
    
} else {
    header('Location: dashboard.php');
    exit();
}

$conn->close();

// QR code URL
$qr_url = "generate_qr.php?ticket_code=" . urlencode($ticket['ticket_code']) . "&size=150";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Ticket - Ticketing System</title>
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
            max-width: 400px;
            width: 100%;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            position: relative;
        }
        .ticket::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, #4f46e5, #10b981, #f59e0b, #ef4444);
        }
        .ticket-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            padding: 25px 20px;
            text-align: center;
        }
        .ticket-header h1 { 
            font-size: 28px; 
            margin-bottom: 5px;
            letter-spacing: 2px;
        }
        .ticket-header p { opacity: 0.8; font-size: 14px; }
        .ticket-body { padding: 25px; }
        .info-row {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #e2e8f0;
        }
        .label { 
            font-size: 11px; 
            color: #64748b; 
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .value { 
            font-size: 16px; 
            font-weight: 600; 
            color: #1e293b; 
            word-break: break-all;
        }
        .qr-code {
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
            border: 2px solid #e2e8f0;
        }
        .qr-code img {
            max-width: 150px;
            height: auto;
            margin: 0 auto;
        }
        .qr-code p {
            margin-top: 10px;
            font-size: 11px;
            color: #64748b;
        }
        .ticket-id {
            background: #f1f5f9;
            padding: 12px;
            border-radius: 8px;
            text-align: center;
            margin: 15px 0;
            font-family: monospace;
            font-size: 12px;
            word-break: break-all;
        }
        .footer {
            background: #f8fafc;
            padding: 15px;
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            border-top: 1px solid #e2e8f0;
        }
        .btn-print {
            display: block;
            width: calc(100% - 40px);
            margin: 0 20px 20px 20px;
            padding: 12px;
            background: #4f46e5;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-print:hover {
            background: #4338ca;
            transform: translateY(-2px);
        }
        .status-valid {
            display: inline-block;
            background: #d1fae5;
            color: #065f46;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-used {
            background: #fee2e2;
            color: #991b1b;
        }
        @media print {
            .btn-print { display: none; }
            body { background: white; padding: 0; margin: 0; }
            .ticket { 
                box-shadow: none; 
                border: 1px solid #e2e8f0;
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .status-valid { print-color-adjust: exact; }
            .qr-code img { print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="ticket-header">
            <h1>🎟️ ENTRY PASS</h1>
            <p><?php echo $typeLabel; ?> Ticket</p>
        </div>
        <div class="ticket-body">
            <div class="info-row">
                <div class="label">Event</div>
                <div class="value"><?php echo htmlspecialchars($_SESSION['selected_event_name'] ?? 'Annual Event'); ?></div>
            </div>
            <div class="info-row">
                <div class="label">Guest Name</div>
                <div class="value"><?php echo htmlspecialchars($ticket['name']); ?></div>
            </div>
            <div class="info-row">
                <div class="label">Contact</div>
                <div class="value"><?php echo htmlspecialchars($ticket['phone']); ?></div>
            </div>
            <div class="info-row">
                <div class="label">Table / Seat</div>
                <div class="value">Table <?php echo htmlspecialchars($ticket['table_id']); ?></div>
            </div>
            
            <!-- QR CODE - Automatically displayed -->
            <div class="qr-code">
                <img src="<?php echo $qr_url; ?>" alt="QR Code">
                <p>Scan for entry verification</p>
            </div>
            
            <div class="ticket-id">
                <strong>Ticket ID:</strong><br>
                <?php echo htmlspecialchars($ticket['ticket_code']); ?>
            </div>
            
            <div class="info-row">
                <div class="label">Ticket Type</div>
                <div class="value">
                    <?php echo $typeLabel; ?> Ticket #<?php echo str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT); ?>
                </div>
            </div>
            
            <div class="info-row">
                <div class="label">Status</div>
                <div class="value">
                    <?php if ($ticket['is_scanned'] == 1): ?>
                        <span class="status-valid status-used">✗ USED</span>
                    <?php else: ?>
                        <span class="status-valid">✓ VALID</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="footer">
            <p>Please present this ticket at the entrance</p>
            <p>QR code will be scanned for entry • One time use only</p>
            <p>© <?php echo date('Y'); ?> Ticketing System</p>
        </div>
        <button class="btn-print" onclick="window.print()">🖨️ Print Ticket</button>
    </div>
</body>
</html>