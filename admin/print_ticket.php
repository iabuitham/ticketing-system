<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

$ticket_code = isset($_GET['ticket_code']) ? sanitizeInput($_GET['ticket_code']) : '';

if (empty($ticket_code)) {
    die('No ticket code provided');
}

$conn = getConnection();

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

$typeLabel = ucfirst($ticket['guest_type']);
$eventName = getSetting('site_name', 'Event');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Ticket - <?php echo htmlspecialchars($eventName); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: #e2e8f0;
            padding: 20px;
        }
        .ticket {
            max-width: 400px;
            width: 100%;
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        .ticket-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            padding: 25px;
            text-align: center;
        }
        .ticket-header h1 { font-size: 24px; }
        .ticket-body { padding: 25px; }
        .info-row {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #e2e8f0;
        }
        .label { font-size: 11px; color: #64748b; margin-bottom: 5px; text-transform: uppercase; }
        .value { font-size: 16px; font-weight: 600; color: #1e293b; }
        .qr-code {
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
        }
        .qr-code img { max-width: 150px; }
        .footer {
            background: #f8fafc;
            padding: 15px;
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
        }
        @media print {
            body { background: white; padding: 0; }
            .ticket { box-shadow: none; }
        }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="ticket-header">
            <h1>🎟️ ENTRY TICKET</h1>
            <p><?php echo $typeLabel; ?> Ticket</p>
        </div>
        <div class="ticket-body">
            <div class="info-row">
                <div class="label">Event</div>
                <div class="value"><?php echo htmlspecialchars($eventName); ?></div>
            </div>
            <div class="info-row">
                <div class="label">Customer</div>
                <div class="value"><?php echo htmlspecialchars($ticket['name']); ?></div>
            </div>
            <div class="info-row">
                <div class="label">Table</div>
                <div class="value">Table <?php echo htmlspecialchars($ticket['table_id']); ?></div>
            </div>
            <div class="qr-code">
                <img src="https://quickchart.io/qr?text=<?php echo urlencode($ticket['ticket_code']); ?>&size=150&margin=2" alt="QR Code">
            </div>
            <div class="info-row">
                <div class="label">Ticket ID</div>
                <div class="value" style="font-family: monospace; font-size: 12px;"><?php echo htmlspecialchars($ticket['ticket_code']); ?></div>
            </div>
        </div>
        <div class="footer">
            <p>Show this ticket at the entrance • One time use only</p>
        </div>
    </div>
    <script>window.print();</script>
</body>
</html>