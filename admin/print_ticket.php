<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$ticket_id = isset($_GET['ticket_id']) ? sanitizeInput($_GET['ticket_id']) : '';
$reservation_id = isset($_GET['reservation_id']) ? sanitizeInput($_GET['reservation_id']) : '';

$conn = getConnection();

if ($ticket_id) {
    // Get single ticket
    $stmt = $conn->prepare("SELECT t.*, r.name, r.phone, r.table_id, r.total_amount 
                            FROM ticket_codes t 
                            JOIN reservations r ON t.reservation_id = r.reservation_id 
                            WHERE t.ticket_id = ?");
    $stmt->bind_param("s", $ticket_id);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$ticket) {
        header('Location: dashboard.php');
        exit();
    }
    
    $typeLabel = ucfirst($ticket['attendee_type']);
    
} elseif ($reservation_id) {
    // Get first ticket from reservation
    $stmt = $conn->prepare("SELECT t.*, r.name, r.phone, r.table_id, r.total_amount 
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
    
    $typeLabel = ucfirst($ticket['attendee_type']);
    
} else {
    header('Location: dashboard.php');
    exit();
}

$conn->close();
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
            background: #f0f2f5;
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
        }
        .ticket-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }
        .ticket-header h1 { font-size: 28px; margin-bottom: 5px; }
        .ticket-body { padding: 30px; }
        .info-row {
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #e2e8f0;
        }
        .label { font-size: 12px; color: #64748b; margin-bottom: 5px; }
        .value { font-size: 16px; font-weight: 600; color: #1e293b; word-break: break-all; }
        .ticket-id {
            background: #f1f5f9;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            margin: 20px 0;
            font-family: monospace;
            font-size: 12px;
        }
        .footer {
            background: #f8fafc;
            padding: 15px;
            text-align: center;
            font-size: 12px;
            color: #94a3b8;
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
        }
        @media print {
            .btn-print { display: none; }
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
                <div class="label">Event Name</div>
                <div class="value"><?php echo htmlspecialchars($_SESSION['selected_event_name'] ?? 'Annual Event'); ?></div>
            </div>
            <div class="info-row">
                <div class="label">Customer Name</div>
                <div class="value"><?php echo htmlspecialchars($ticket['name']); ?></div>
            </div>
            <div class="info-row">
                <div class="label">Phone Number</div>
                <div class="value"><?php echo htmlspecialchars($ticket['phone']); ?></div>
            </div>
            <div class="info-row">
                <div class="label">Table Number</div>
                <div class="value">Table <?php echo htmlspecialchars($ticket['table_id']); ?></div>
            </div>
            <div class="ticket-id">
                <strong>Ticket ID:</strong><br>
                <?php echo htmlspecialchars($ticket['ticket_id']); ?>
            </div>
            <div class="info-row">
                <div class="label">Ticket Type</div>
                <div class="value"><?php echo $typeLabel; ?> Ticket #<?php echo str_pad($ticket['attendee_number'], 3, '0', STR_PAD_LEFT); ?></div>
            </div>
        </div>
        <div class="footer">
            <p>Please present this ticket at the entrance</p>
            <p>Valid for one-time entry only</p>
        </div>
        <button class="btn-print" onclick="window.print()">🖨️ Print Ticket</button>
    </div>
</body>
</html>