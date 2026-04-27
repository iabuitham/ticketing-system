<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

$ticket_code = isset($_GET['ticket_code']) ? $_GET['ticket_code'] : '';

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

$eventName = getSetting('site_name', 'Event');
$typeLabel = ucfirst($ticket['guest_type']);
$ticketNumber = str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT);
$qrCodeUrl = "https://quickchart.io/qr?text=" . urlencode($ticket['ticket_code']) . "&size=150&margin=2";

// Create HTML content for the ticket
$htmlContent = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: white;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .ticket {
            width: 450px;
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            border: 2px solid #4f46e5;
        }
        .ticket-header {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: white;
            padding: 25px;
            text-align: center;
        }
        .ticket-header h1 { font-size: 28px; margin-bottom: 5px; }
        .ticket-body { padding: 25px; }
        .info-row {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .label { font-size: 11px; color: #64748b; text-transform: uppercase; margin-bottom: 5px; }
        .value { font-size: 16px; font-weight: bold; color: #1e293b; }
        .qr-section {
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            background: #f8fafc;
            border-radius: 16px;
        }
        .footer {
            background: #f8fafc;
            padding: 15px;
            text-align: center;
            font-size: 11px;
            color: #64748b;
        }
        .status { color: #10b981; font-weight: bold; }
    </style>
</head>
<body>
    <div class="ticket">
        <div class="ticket-header">
            <h1>🎟️ ENTRY TICKET</h1>
            <p>' . $typeLabel . ' Ticket</p>
        </div>
        <div class="ticket-body">
            <div class="info-row">
                <div class="label">Event</div>
                <div class="value">' . htmlspecialchars($eventName) . '</div>
            </div>
            <div class="info-row">
                <div class="label">Customer</div>
                <div class="value">' . htmlspecialchars($ticket['name']) . '</div>
            </div>
            <div class="info-row">
                <div class="label">Table</div>
                <div class="value">Table ' . htmlspecialchars($ticket['table_id']) . '</div>
            </div>
            <div class="qr-section">
                <img src="' . $qrCodeUrl . '" alt="QR Code">
                <div style="margin-top: 10px; font-family: monospace; font-size: 12px;">
                    <strong>Ticket ID:</strong><br>' . htmlspecialchars($ticket['ticket_code']) . '
                </div>
            </div>
            <div class="info-row">
                <div class="label">Ticket Number</div>
                <div class="value">#' . $ticketNumber . '</div>
            </div>
            <div class="info-row">
                <div class="label">Status</div>
                <div class="value"><span class="status">✓ VALID</span></div>
            </div>
        </div>
        <div class="footer">
            <p>Show this ticket at the entrance • Scan QR code</p>
            <p>Valid for one-time entry only</p>
        </div>
    </div>
</body>
</html>';

// Save HTML to a temp file
$tempHtmlFile = tempnam(sys_get_temp_dir(), 'ticket_html_');
file_put_contents($tempHtmlFile, $htmlContent);

// Output the HTML file directly
header('Content-Type: text/html');
readfile($tempHtmlFile);
unlink($tempHtmlFile);
?>