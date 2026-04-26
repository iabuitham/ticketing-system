<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$reservation_id = isset($_GET['id']) ? sanitizeInput($_GET['id']) : '';

$conn = getConnection();

if (empty($reservation_id)) {
    // Show all tickets from all reservations
    $query = "SELECT t.*, r.name, r.phone, r.table_id, r.status as reservation_status 
              FROM ticket_codes t 
              JOIN reservations r ON t.reservation_id = r.reservation_id 
              ORDER BY t.created_at DESC";
    $result = $conn->query($query);
    $tickets = $result->fetch_all(MYSQLI_ASSOC);
    $title = "All Tickets";
    $reservation = null;
} else {
    // Get reservation details
    $stmt = $conn->prepare("SELECT * FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $reservation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$reservation) {
        header('Location: dashboard.php');
        exit();
    }
    
    // Get all tickets for this reservation
    $stmt = $conn->prepare("SELECT * FROM ticket_codes WHERE reservation_id = ? ORDER BY guest_type, guest_number");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $title = "Tickets for " . htmlspecialchars($reservation['name']);
}

$conn->close();

$typeLabels = [
    'adult' => 'Adult',
    'teen' => 'Teen',
    'kid' => 'Kid'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Tickets - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .reservation-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 20px;
        }
        .ticket-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .ticket-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .ticket-card:hover {
            transform: translateY(-5px);
        }
        .ticket-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            text-align: center;
        }
        .ticket-body {
            padding: 20px;
        }
        .ticket-code {
            font-family: monospace;
            font-size: 14px;
            background: #f1f5f9;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            margin-bottom: 15px;
            word-break: break-all;
        }
        .ticket-detail {
            margin: 10px 0;
            display: flex;
            justify-content: space-between;
            padding: 5px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-scanned { background: #fee2e2; color: #991b1b; }
        .status-unscanned { background: #d1fae5; color: #065f46; }
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
        }
        .btn-primary { background: #4f46e5; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-print { background: #10b981; color: white; }
        @media print {
            .no-print { display: none; }
            .ticket-card { break-inside: avoid; page-break-inside: avoid; }
            body { background: white; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header no-print">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h1><i class="bi bi-ticket-perforated"></i> <?php echo $title; ?></h1>
                <div>
                    <button onclick="window.print()" class="btn btn-print"><i class="bi bi-printer"></i> Print All</button>
                    <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
                </div>
            </div>
        </div>
        
        <?php if ($reservation): ?>
        <div class="reservation-info">
            <h2><i class="bi bi-receipt"></i> <?php echo htmlspecialchars($reservation['name']); ?></h2>
            <p><strong>Reservation ID:</strong> <?php echo htmlspecialchars($reservation['reservation_id']); ?></p>
            <p><strong>Phone:</strong> <?php echo htmlspecialchars($reservation['phone']); ?></p>
            <p><strong>Table:</strong> <?php echo htmlspecialchars($reservation['table_id']); ?></p>
            <p><strong>Guests:</strong> <?php echo $reservation['adults'] + $reservation['teens'] + $reservation['kids']; ?> 
               (<?php echo $reservation['adults']; ?> Adults, <?php echo $reservation['teens']; ?> Teens, <?php echo $reservation['kids']; ?> Kids)</p>
        </div>
        <?php endif; ?>
        
        <div class="ticket-grid">
            <?php foreach ($tickets as $ticket): ?>
            <div class="ticket-card">
                <div class="ticket-header">
                    <i class="bi bi-ticket-perforated" style="font-size: 24px;"></i>
                    <h3><?php echo $typeLabels[$ticket['guest_type']]; ?> Ticket</h3>
                </div>
                <div class="ticket-body">
                    <div class="ticket-code">
                        <strong>Ticket Code:</strong><br>
                        <?php echo htmlspecialchars($ticket['ticket_code']); ?>
                    </div>
                    <div class="ticket-detail">
                        <span>Guest Number:</span>
                        <strong>#<?php echo str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT); ?></strong>
                    </div>
                    <div class="ticket-detail">
                        <span>Status:</span>
                        <span class="status-badge status-<?php echo $ticket['is_scanned'] ? 'scanned' : 'unscanned'; ?>">
                            <?php echo $ticket['is_scanned'] ? 'Scanned/Used' : 'Available'; ?>
                        </span>
                    </div>
                    <?php if ($ticket['scanned_at']): ?>
                    <div class="ticket-detail">
                        <span>Scanned at:</span>
                        <span><?php echo date('M d, Y H:i', strtotime($ticket['scanned_at'])); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>