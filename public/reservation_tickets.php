<?php
require_once '../includes/db.php';
require_once '../includes/functions.php';

$reservation_id = isset($_GET['id']) ? urldecode($_GET['id']) : '';

if (empty($reservation_id)) {
    die('No reservation ID provided. Please check your ticket link.');
}

$conn = getConnection();

// Get reservation details
$stmt = $conn->prepare("SELECT * FROM reservations WHERE reservation_id = ?");
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reservation) {
    die('Reservation not found. Please contact support.');
}

// Get all tickets for this reservation
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

$eventName = getSetting('site_name', 'Event');
$baseUrl = getSetting('base_url', 'https://restorandticketingsystem.unaux.com/');
$totalGuests = $reservation['adults'] + $reservation['teens'] + $reservation['kids'];
$currencySymbol = getCurrencySymbol();

// If no tickets found, show error
if (empty($tickets)) {
    die('No tickets found for this reservation. Please contact support.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Your Tickets - <?php echo htmlspecialchars($reservation['name']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 900px; margin: 0 auto; }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            color: white;
        }
        .header h1 { font-size: 32px; margin-bottom: 10px; }
        .header p { opacity: 0.9; }
        
        .reservation-card {
            background: white;
            border-radius: 24px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        
        .reservation-title {
            font-size: 20px;
            font-weight: bold;
            color: #1e293b;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
        }
        
        .info-label {
            font-size: 12px;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #1e293b;
            margin-top: 5px;
        }
        
        .ticket-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }
        
        .ticket-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        
        .ticket-card:hover {
            transform: translateY(-5px);
        }
        
        .ticket-header {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: white;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .ticket-header h3 {
            font-size: 18px;
        }
        
        .ticket-number {
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 14px;
        }
        
        .ticket-body {
            padding: 20px;
        }
        
        .ticket-image {
            text-align: center;
            margin-bottom: 15px;
        }
        
        .ticket-image img {
            max-width: 100%;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .ticket-code {
            background: #f1f5f9;
            padding: 12px;
            border-radius: 10px;
            font-family: monospace;
            font-size: 12px;
            text-align: center;
            word-break: break-all;
            margin: 15px 0;
        }
        
        .ticket-detail {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .ticket-detail:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #64748b;
            font-size: 13px;
        }
        
        .detail-value {
            font-weight: 600;
            color: #1e293b;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-valid {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-used {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .btn-download {
            display: block;
            width: 100%;
            padding: 12px;
            background: #10b981;
            color: white;
            text-align: center;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            margin-top: 15px;
            transition: background 0.2s;
        }
        
        .btn-download:hover {
            background: #059669;
        }
        
        .btn-whatsapp {
            background: #25D366;
            margin-top: 10px;
        }
        
        .btn-whatsapp:hover {
            background: #128C7E;
        }
        
        .footer {
            text-align: center;
            margin-top: 40px;
            color: rgba(255,255,255,0.7);
            font-size: 12px;
        }
        
        @media (max-width: 768px) {
            .ticket-grid {
                grid-template-columns: 1fr;
            }
            .info-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .header h1 { font-size: 24px; }
        }
        
        @media print {
            body { background: white; padding: 0; }
            .header, .footer, .btn-download { display: none; }
            .ticket-card { break-inside: avoid; page-break-inside: avoid; box-shadow: none; border: 1px solid #ddd; }
            .ticket-image img { max-width: 150px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="bi bi-ticket-perforated"></i> Your Tickets</h1>
            <p><?php echo htmlspecialchars($eventName); ?></p>
        </div>
        
        <!-- Reservation Details -->
        <div class="reservation-card">
            <div class="reservation-title">
                <i class="bi bi-receipt"></i> Reservation Details
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Reservation ID</span>
                    <span class="info-value"><?php echo htmlspecialchars($reservation['reservation_id']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Customer Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($reservation['name']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Phone Number</span>
                    <span class="info-value"><?php echo htmlspecialchars($reservation['phone']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Table Number</span>
                    <span class="info-value">Table <?php echo htmlspecialchars($reservation['table_id']); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Total Guests</span>
                    <span class="info-value"><?php echo $totalGuests; ?> (<?php echo $reservation['adults']; ?> Adults, <?php echo $reservation['teens']; ?> Teens, <?php echo $reservation['kids']; ?> Kids)</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Total Amount</span>
                    <span class="info-value"><?php echo $currencySymbol; ?> <?php echo number_format($reservation['total_amount'], 2); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Tickets Grid -->
        <div class="ticket-grid">
            <?php foreach ($tickets as $ticket): 
                $typeLabel = $typeLabels[$ticket['guest_type']];
                $ticketNumber = str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT);
                $ticketImageUrl = $baseUrl . "public/generate_ticket_image.php?ticket_code=" . urlencode($ticket['ticket_code']);            ?>
            <div class="ticket-card">
                <div class="ticket-header">
                    <h3><i class="bi bi-ticket-perforated"></i> <?php echo $typeLabel; ?> Ticket</h3>
                    <span class="ticket-number">#<?php echo $ticketNumber; ?></span>
                </div>
                <div class="ticket-body">
                    <div class="ticket-image">
                        <img src="<?php echo $ticketImageUrl; ?>" alt="Ticket QR Code">
                    </div>
                    
                    <div class="ticket-code">
                        <strong>Ticket ID:</strong><br>
                        <?php echo htmlspecialchars($ticket['ticket_code']); ?>
                    </div>
                    
                    <div class="ticket-detail">
                        <span class="detail-label">Status</span>
                        <span class="detail-value">
                            <span class="status-badge <?php echo $ticket['is_scanned'] ? 'status-used' : 'status-valid'; ?>">
                                <?php echo $ticket['is_scanned'] ? 'Used' : 'Valid'; ?>
                            </span>
                        </span>
                    </div>
                    
                    <div class="ticket-detail">
                        <span class="detail-label">Table</span>
                        <span class="detail-value"><?php echo htmlspecialchars($reservation['table_id']); ?></span>
                    </div>
                    
                    <a href="<?php echo $ticketImageUrl; ?>" download class="btn-download">
                        <i class="bi bi-download"></i> Download Ticket
                    </a>
                    
                    <a href="https://wa.me/+962795410115?text=I%20need%20help%20with%20my%20ticket%20<?php echo urlencode($ticket['ticket_code']); ?>" target="_blank" class="btn-download btn-whatsapp">
                        <i class="bi bi-whatsapp"></i> Contact Support
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="footer">
            <p><i class="bi bi-info-circle"></i> Each ticket has a unique QR code. Present this page or downloaded tickets at the entrance.</p>
            <p>© <?php echo date('Y'); ?> <?php echo htmlspecialchars($eventName); ?></p>
        </div>
    </div>
</body>
</html>