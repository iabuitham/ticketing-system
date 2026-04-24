<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();

$reservation_id = isset($_GET['reservation_id']) ? sanitizeInput($_GET['reservation_id']) : '';
$ticket_code = isset($_GET['code']) ? sanitizeInput($_GET['code']) : '';

if ($ticket_code) {
    $stmt = $conn->prepare("SELECT tc.*, r.name, r.table_id, r.reservation_id, e.event_name, e.event_date, e.event_time, e.venue 
                            FROM ticket_codes tc 
                            JOIN reservations r ON tc.reservation_id = r.reservation_id 
                            CROSS JOIN event_settings e 
                            WHERE tc.ticket_code = ?");
    $stmt->bind_param("s", $ticket_code);
    $stmt->execute();
    $tickets = [$stmt->get_result()->fetch_assoc()];
    $stmt->close();
} else {
    $stmt = $conn->prepare("SELECT tc.*, r.name, r.table_id, r.reservation_id, e.event_name, e.event_date, e.event_time, e.venue 
                            FROM ticket_codes tc 
                            JOIN reservations r ON tc.reservation_id = r.reservation_id 
                            CROSS JOIN event_settings e 
                            WHERE r.reservation_id = ? 
                            ORDER BY tc.guest_type, tc.guest_number");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$conn->close();

// Function to generate QR code URL with ticket code
function getQRCodeUrl($ticketCode) {
    // The QR code will contain the ticket code for scanning
    $qrData = urlencode($ticketCode);
    return "https://quickchart.io/qr?text={$qrData}&size=200&margin=2";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Print Ticket</title>
    <style>
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none; }
            .ticket { page-break-after: always; break-inside: avoid; }
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #e0e0e0;
            padding: 20px;
        }
        .container { max-width: 800px; margin: 0 auto; }
        .ticket {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 20px;
            break-inside: avoid;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        .header h1 { font-size: 20px; margin-bottom: 5px; }
        .header p { font-size: 11px; opacity: 0.9; }
        .content { padding: 20px; }
        .info-row {
            margin-bottom: 12px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f0f0f0;
        }
        .label { font-size: 10px; color: #999; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 3px; }
        .value { font-size: 14px; font-weight: 600; color: #333; }
        .ticket-code {
            font-family: monospace;
            background: #f5f5f5;
            padding: 10px;
            text-align: center;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
            word-break: break-all;
        }
        .guest-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .guest-adult { background: #667eea; color: white; }
        .guest-teen { background: #28a745; color: white; }
        .guest-kid { background: #ffc107; color: #333; }
        .qr-code {
            text-align: center;
            margin: 15px 0;
        }
        .qr-code img {
            width: 140px;
            height: 140px;
            border: 2px solid #667eea;
            border-radius: 15px;
            padding: 8px;
        }
        .qr-code .scan-text {
            font-size: 10px;
            color: #999;
            margin-top: 8px;
        }
        .footer {
            background: #f8f9fa;
            padding: 12px;
            text-align: center;
            font-size: 9px;
            color: #999;
        }
        .btn-print {
            background: #667eea;
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            margin-top: 20px;
        }
        @media (max-width: 600px) {
            body { padding: 10px; }
            .ticket { margin-bottom: 15px; }
            .content { padding: 15px; }
            .qr-code img { width: 100px; height: 100px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php foreach ($tickets as $ticket): 
            $guestTypeIcon = $ticket['guest_type'] == 'adult' ? '👤' : ($ticket['guest_type'] == 'teen' ? '🧑' : '👶');
            $eventDate = date('F j, Y', strtotime($ticket['event_date']));
            $qrCodeUrl = getQRCodeUrl($ticket['ticket_code']);
        ?>
        <div class="ticket">
            <div class="header">
                <h1><?php echo htmlspecialchars($ticket['event_name']); ?></h1>
                <p>E-TICKET • ENTRY PASS</p>
            </div>
            <div class="content">
                <div class="info-row">
                    <div class="label">Ticket Code</div>
                    <div class="ticket-code"><?php echo htmlspecialchars($ticket['ticket_code']); ?></div>
                </div>
                <div class="info-row">
                    <div class="label">Ticket Type</div>
                    <div class="value">
                        <span class="guest-badge guest-<?php echo $ticket['guest_type']; ?>">
                            <?php echo $guestTypeIcon; ?> <?php echo ucfirst($ticket['guest_type']); ?> Ticket #<?php echo $ticket['guest_number']; ?>
                        </span>
                    </div>
                </div>
                <div class="info-row">
                    <div class="label">Customer Name</div>
                    <div class="value"><?php echo htmlspecialchars($ticket['name']); ?></div>
                </div>
                <div class="info-row">
                    <div class="label">Table Number</div>
                    <div class="value"><?php echo htmlspecialchars($ticket['table_id']); ?></div>
                </div>
                <div class="info-row">
                    <div class="label">Event Details</div>
                    <div class="value">
                        📅 <?php echo $eventDate; ?><br>
                        ⏰ <?php echo $ticket['event_time']; ?><br>
                        📍 <?php echo htmlspecialchars($ticket['venue']); ?>
                    </div>
                </div>
                <div class="qr-code">
                    <img src="<?php echo $qrCodeUrl; ?>" alt="QR Code">
                    <div class="scan-text">Scan this QR code for entry verification</div>
                </div>
            </div>
            <div class="footer">
                Reservation: <?php echo htmlspecialchars($ticket['reservation_id']); ?><br>
                This ticket is non-transferable. Please present at entrance.
            </div>
        </div>
        <?php endforeach; ?>
        
        <div class="no-print" style="text-align: center;">
            <button onclick="window.print()" class="btn-print">🖨️ Print / Save as PDF</button>
            <div style="margin-top: 10px;">
                <a href="dashboard.php" style="color: #666;">← Back to Dashboard</a>
            </div>
        </div>
    </div>
</body>
</html>