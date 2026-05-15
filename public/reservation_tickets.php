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
        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', sans-serif;
            background: #0A0A0A;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container { 
            max-width: 900px; 
            margin: 0 auto; 
        }
        
        /* Header */
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h1 { 
            font-size: 32px; 
            margin-bottom: 10px;
            color: #C8A96B;
            font-weight: 600;
        }
        
        .header p { 
            color: #D88A3D;
            opacity: 0.9;
        }
        
        /* Reservation Card */
        .reservation-card {
            background: #1a1a1a;
            border-radius: 24px;
            padding: 25px;
            margin-bottom: 30px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            border: 1px solid #5B4633;
        }
        
        .reservation-title {
            font-size: 20px;
            font-weight: bold;
            color: #C8A96B;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #5B4633;
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
            font-size: 11px;
            color: #A86B2A;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 600;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 600;
            color: #F2ECE2;
            margin-top: 5px;
        }
        
        /* Ticket Grid */
        .ticket-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(380px, 1fr));
            gap: 25px;
            margin-top: 20px;
        }
        
        .ticket-card {
            background: #1a1a1a;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            break-inside: avoid;
            page-break-inside: avoid;
            border: 1px solid #5B4633;
        }
        
        .ticket-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 40px rgba(200, 169, 107, 0.15);
            border-color: #C8A96B;
        }
        
        .ticket-card.deactivated {
            opacity: 0.6;
            filter: grayscale(0.3);
        }
        
        .ticket-card.deactivated .ticket-header {
            background: linear-gradient(135deg, #4A1F24, #2a1a1c);
        }
        
        .ticket-header {
            background: linear-gradient(135deg, #5B4633, #3d2d1f);
            color: #F2ECE2;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .ticket-header h3 {
            font-size: 18px;
            font-weight: 600;
        }
        
        .ticket-number {
            background: rgba(200, 169, 107, 0.2);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 14px;
            color: #C8A96B;
        }
        
        .ticket-body {
            padding: 20px;
        }
        
        .qr-container {
            text-align: center;
            margin: 15px 0;
            padding: 15px;
            background: #0f0f0f;
            border-radius: 16px;
            position: relative;
        }
        
        .qr-code {
            display: inline-block;
            background: white;
            padding: 10px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        
        .qr-code img {
            width: 180px;
            height: 180px;
            display: block;
        }
        
        /* Deactivated QR code overlay */
        .deactivated .qr-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
        }
        
        .deactivated .qr-overlay span {
            background: #4A1F24;
            color: #D88A3D;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: bold;
        }
        
        .ticket-code {
            background: #0f0f0f;
            padding: 12px;
            border-radius: 10px;
            font-family: monospace;
            font-size: 12px;
            text-align: center;
            word-break: break-all;
            margin: 15px 0;
            color: #A86B2A;
            border: 1px solid #2a2a2a;
        }
        
        .ticket-code strong {
            color: #C8A96B;
        }
        
        .ticket-detail {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #2a2a2a;
        }
        
        .ticket-detail:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: #A86B2A;
            font-size: 13px;
        }
        
        .detail-value {
            font-weight: 600;
            color: #F2ECE2;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-valid {
            background: #1a3a2a;
            color: #C8A96B;
            border: 1px solid #C8A96B;
        }
        
        .status-used {
            background: #4A1F24;
            color: #D88A3D;
            border: 1px solid #D88A3D;
        }
        
        .status-inactive {
            background: #3a1a1a;
            color: #A86B2A;
            border: 1px solid #A86B2A;
        }
        
        /* Warning Box */
        .warning-box {
            background: #4A1F24;
            border-left: 4px solid #D88A3D;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            color: #F2ECE2;
        }
        
        .warning-box i {
            color: #D88A3D;
        }
        
        /* Buttons */
        .btn-download {
            display: block;
            width: 100%;
            padding: 12px;
            background: #5B4633;
            color: #F2ECE2;
            text-align: center;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            margin-top: 15px;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .btn-download:hover {
            background: #C8A96B;
            color: #0A0A0A;
            transform: translateY(-2px);
        }
        
        .btn-disabled {
            background: #2a2a2a;
            cursor: not-allowed;
            pointer-events: none;
            color: #666;
        }
        
        .btn-whatsapp {
            background: #25D366;
            color: white;
            margin-top: 10px;
        }
        
        .btn-whatsapp:hover {
            background: #128C7E;
            color: white;
        }
        
        /* Footer */
        .footer {
            text-align: center;
            margin-top: 40px;
            color: #5B4633;
            font-size: 12px;
        }
        
        .footer p {
            margin-top: 10px;
        }
        
        /* Print Styles */
        @media print {
            body {
                background: white;
                padding: 0;
                margin: 0;
            }
            
            .header, .footer, .btn-download, .btn-whatsapp, .reservation-card, .warning-box {
                display: none !important;
            }
            
            .ticket-grid {
                display: block;
                margin: 0;
                padding: 0;
            }
            
            .ticket-card {
                break-after: page;
                page-break-after: always;
                break-inside: avoid;
                page-break-inside: avoid;
                box-shadow: none;
                border: 1px solid #ddd;
                margin: 0;
                border-radius: 0;
                position: relative;
                background: white;
            }
            
            .ticket-header {
                background: #1a1a1a;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .qr-code img {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .status-badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .ticket-body {
                padding: 20px;
            }
            
            .ticket-card::after {
                content: "Ticket " counter(ticket) " of <?php echo count($tickets); ?>";
                counter-increment: ticket;
                position: absolute;
                bottom: 10px;
                right: 20px;
                font-size: 10px;
                color: #999;
            }
        }
        
        .ticket-grid {
            counter-reset: ticket;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .ticket-grid {
                grid-template-columns: 1fr;
            }
            .info-grid {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .header h1 { 
                font-size: 24px; 
            }
            .qr-code img { 
                width: 140px; 
                height: 140px; 
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="bi bi-ticket-perforated"></i> Your Tickets</h1>
            <p><?php echo htmlspecialchars($eventName); ?></p>
        </div>
        
        <!-- Warning if any tickets are deactivated -->
        <?php 
        $hasDeactivated = false;
        foreach ($tickets as $ticket) {
            if ($ticket['is_active'] == 0) {
                $hasDeactivated = true;
                break;
            }
        }
        ?>
        <?php if ($hasDeactivated): ?>
        <div class="warning-box">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <strong>Notice:</strong> Some of your tickets have been deactivated. Please contact support for assistance.
        </div>
        <?php endif; ?>
        
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
            <?php
            $ticketCounter = 0;
            foreach ($tickets as $ticket): 
                $typeLabel = $typeLabels[$ticket['guest_type']];
                $ticketNumber = str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT);
                $qrCodeUrl = "https://quickchart.io/qr?text=" . urlencode($ticket['ticket_code']) . "&size=180&margin=2";
                $isUsed = $ticket['is_scanned'] == 1;
                $isActive = $ticket['is_active'] == 1;
                $isValid = $isActive && !$isUsed;
                $cardClass = !$isActive ? 'deactivated' : '';
                $ticketCounter++;
                $printPageBreak = ($ticketCounter > 1) ? 'page-break-before: always;' : '';
            ?>
            <div class="ticket-card <?php echo $cardClass; ?>" style="<?php echo $printPageBreak; ?>">
                <div class="ticket-header">
                    <h3><i class="bi bi-ticket-perforated"></i> <?php echo $typeLabel; ?> Ticket</h3>
                    <span class="ticket-number">#<?php echo $ticketNumber; ?></span>
                </div>
                <div class="ticket-body">
                    <div class="qr-container" style="position: relative;">
                        <div class="qr-code">
                            <img src="<?php echo $qrCodeUrl; ?>" alt="QR Code" loading="lazy">
                        </div>
                        <?php if (!$isActive): ?>
                        <div class="qr-overlay">
                            <span>DEACTIVATED</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="ticket-code">
                        <strong>Ticket ID:</strong><br>
                        <?php echo htmlspecialchars($ticket['ticket_code']); ?>
                    </div>
                    
                    <div class="ticket-detail">
                        <span class="detail-label">Status</span>
                        <span class="detail-value">
                            <?php if ($isUsed): ?>
                                <span class="status-badge status-used">Used</span>
                            <?php elseif (!$isActive): ?>
                                <span class="status-badge status-inactive">Deactivated</span>
                            <?php else: ?>
                                <span class="status-badge status-valid">Valid</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    
                    <div class="ticket-detail">
                        <span class="detail-label">Table</span>
                        <span class="detail-value">Table <?php echo htmlspecialchars($reservation['table_id']); ?></span>
                    </div>
                    
                    <div class="ticket-detail">
                        <span class="detail-label">Ticket Holder</span>
                        <span class="detail-value"><?php echo htmlspecialchars($reservation['name']); ?></span>
                    </div>
                    
                    <div class="ticket-detail">
                        <span class="detail-label">Event Date</span>
                        <span class="detail-value"><?php echo date('F j, Y', strtotime($reservation['created_at'])); ?></span>
                    </div>
                    
                    <?php if ($isValid): ?>
                        <button onclick="window.print()" class="btn-download no-print">
                            <i class="bi bi-printer"></i> Print This Ticket
                        </button>
                    <?php else: ?>
                        <button class="btn-download btn-disabled no-print" disabled>
                            <i class="bi bi-x-circle"></i> <?php echo $isUsed ? 'Ticket Already Used' : 'Ticket Deactivated'; ?>
                        </button>
                    <?php endif; ?>
                    
                    <a href="https://wa.me/962795410115?text=I%20need%20help%20with%20my%20ticket%20<?php echo urlencode($ticket['ticket_code']); ?>" target="_blank" class="btn-download btn-whatsapp no-print">
                        <i class="bi bi-whatsapp"></i> Contact Support
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="footer no-print">
            <p><i class="bi bi-info-circle"></i> Each ticket has a unique QR code. Only valid (golden) tickets will be accepted at the entrance.</p>
            <p>© <?php echo date('Y'); ?> <?php echo htmlspecialchars($eventName); ?></p>
            <p style="margin-top: 10px;">
                <button onclick="window.print()" class="btn-download" style="display: inline-block; width: auto; padding: 10px 20px;">
                    <i class="bi bi-printer"></i> Print All Tickets (Each on separate page)
                </button>
            </p>
        </div>
    </div>
</body>
</html>