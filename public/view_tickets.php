<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

$token = isset($_GET['token']) ? $_GET['token'] : '';
$reservation_id = base64_decode($token);

if (empty($reservation_id)) {
    die('Invalid ticket link');
}

$conn = getConnection();

// Get reservation details
$stmt = $conn->prepare("SELECT * FROM reservations WHERE reservation_id = ?");
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reservation) {
    die('Reservation not found');
}

// Get all tickets
$stmt = $conn->prepare("SELECT * FROM ticket_codes WHERE reservation_id = ? ORDER BY guest_type, guest_number");
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();

$typeLabels = [
    'adult' => 'Adult',
    'teen' => 'Teen',
    'kid' => 'Kid'
];

$currencySymbol = getCurrencySymbol();
$eventName = getSetting('site_name', 'Event');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Your Tickets - <?php echo htmlspecialchars($eventName); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 800px; margin: 0 auto; }
        
        .ticket-container {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            margin-bottom: 20px;
        }
        
        .event-header {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .event-header h1 { font-size: 28px; margin-bottom: 10px; }
        .event-header p { opacity: 0.8; }
        
        .reservation-info {
            background: #f8fafc;
            padding: 20px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            flex-wrap: wrap;
        }
        
        .info-label { font-weight: 600; color: #64748b; }
        .info-value { color: #1e293b; font-weight: 500; }
        
        .ticket-card {
            background: white;
            margin: 20px;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            overflow: hidden;
            transition: transform 0.2s;
        }
        
        .ticket-card:hover { transform: translateY(-5px); }
        
        .ticket-header {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .ticket-body { padding: 20px; }
        
        .qr-code {
            text-align: center;
            margin: 15px 0;
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
        }
        
        .qr-code img {
            max-width: 150px;
            height: auto;
        }
        
        .ticket-code {
            background: #f1f5f9;
            padding: 10px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 12px;
            text-align: center;
            word-break: break-all;
            margin: 15px 0;
        }
        
        .btn-download {
            display: block;
            width: 100%;
            padding: 12px;
            background: #10b981;
            color: white;
            text-align: center;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 15px;
        }
        
        .btn-download:hover { background: #059669; }
        
        .footer {
            text-align: center;
            padding: 20px;
            color: #94a3b8;
            font-size: 12px;
        }
        
        @media print {
            body { background: white; padding: 0; }
            .no-print { display: none; }
            .ticket-card { break-inside: avoid; page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="ticket-container">
            <div class="event-header">
                <h1><i class="bi bi-ticket-perforated"></i> <?php echo htmlspecialchars($eventName); ?></h1>
                <p>Your Event Tickets</p>
            </div>
            
            <div class="reservation-info">
                <div class="info-row">
                    <span class="info-label">Reservation ID:</span>
                    <span class="info-value"><?php echo htmlspecialchars($reservation['reservation_id']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Customer Name:</span>
                    <span class="info-value"><?php echo htmlspecialchars($reservation['name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Phone:</span>
                    <span class="info-value"><?php echo htmlspecialchars($reservation['phone']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Table Number:</span>
                    <span class="info-value">Table <?php echo htmlspecialchars($reservation['table_id']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Total Guests:</span>
                    <span class="info-value"><?php echo $reservation['adults'] + $reservation['teens'] + $reservation['kids']; ?> 
                        (<?php echo $reservation['adults']; ?> Adults, <?php echo $reservation['teens']; ?> Teens, <?php echo $reservation['kids']; ?> Kids)</span>
                </div>
            </div>
            
            <?php foreach ($tickets as $ticket): ?>
            <div class="ticket-card">
                <div class="ticket-header">
                    <span><i class="bi bi-ticket-perforated"></i> <?php echo $typeLabels[$ticket['guest_type']]; ?> Ticket</span>
                    <span>#<?php echo str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT); ?></span>
                </div>
                <div class="ticket-body">
                    <div class="qr-code">
                        <img src="https://quickchart.io/qr?text=<?php echo urlencode($ticket['ticket_code']); ?>&size=200&margin=2" alt="QR Code">
                    </div>
                    <div class="ticket-code">
                        <strong>Ticket ID:</strong><br>
                        <?php echo htmlspecialchars($ticket['ticket_code']); ?>
                    </div>
                    <div class="info-row" style="border-top: 1px solid #e2e8f0; padding-top: 10px;">
                        <span class="info-label">Status:</span>
                        <span class="info-value">
                            <?php if ($ticket['is_scanned']): ?>
                                <span style="color: #ef4444;">✗ Used</span>
                            <?php else: ?>
                                <span style="color: #10b981;">✓ Valid</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <a href="print_ticket.php?ticket_code=<?php echo urlencode($ticket['ticket_code']); ?>" target="_blank" class="btn-download no-print">
                        <i class="bi bi-printer"></i> Print or Save Ticket
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div class="footer no-print">
                <p><i class="bi bi-info-circle"></i> Show this page at the entrance</p>
                <p>Each QR code can be scanned once for entry</p>
                <p>© <?php echo date('Y'); ?> <?php echo htmlspecialchars($eventName); ?></p>
            </div>
        </div>
    </div>
</body>
</html>