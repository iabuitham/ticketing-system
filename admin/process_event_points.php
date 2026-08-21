<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();
$selected_event_id = $_SESSION['selected_event_id'] ?? 0;
$message = '';
$messageType = '';

// Get event info
$eventStmt = $conn->prepare("SELECT event_name, points_multiplier_tickets, points_multiplier_fnb FROM event_settings WHERE id = ?");
$eventStmt->bind_param("i", $selected_event_id);
$eventStmt->execute();
$event = $eventStmt->get_result()->fetch_assoc();
$eventStmt->close();

$pointsMultiplierTickets = $event['points_multiplier_tickets'] ?? 1.3;
$pointsMultiplierFnb = $event['points_multiplier_fnb'] ?? 1.0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    if ($password !== 'AdminDelete2026') {
        $message = "Invalid password!";
        $messageType = "error";
    } else {
        // Get all paid reservations for this event
        $reservations = $conn->prepare("
            SELECT r.reservation_id, r.name, r.phone, r.total_amount, 
                   COALESCE(SUM(sp.amount), 0) as total_paid,
                   COALESCE(SUM(CASE WHEN sp.is_fnb = 1 THEN sp.fnb_amount ELSE 0 END), 0) as fnb_paid
            FROM reservations r
            LEFT JOIN split_payments sp ON r.reservation_id = sp.reservation_id
            WHERE r.event_id = ? AND r.status = 'paid'
            AND NOT EXISTS (
                SELECT 1 FROM loyalty_points_transactions lpt 
                WHERE lpt.reservation_id = r.reservation_id 
                AND lpt.transaction_type IN ('tickets', 'fnb')
                AND lpt.event_id = r.event_id
            )
            GROUP BY r.reservation_id
        ");
        $reservations->bind_param("i", $selected_event_id);
        $reservations->execute();
        $result = $reservations->get_result();
        
        $processed = 0;
        $totalPointsAwarded = 0;
        
        while ($res = $result->fetch_assoc()) {
            $phone = $res['phone'];
            $name = $res['name'];
            $total_paid = floatval($res['total_paid']);
            $fnb_paid = floatval($res['fnb_paid']);
            $tickets_paid = $total_paid - $fnb_paid;
            
            // Calculate points
            $ticket_points = $tickets_paid * $pointsMultiplierTickets;
            $fnb_points = $fnb_paid * $pointsMultiplierFnb;
            $total_points = $ticket_points + $fnb_points;
            
            if ($total_points > 0) {
                // Get or create customer
                $checkStmt = $conn->prepare("SELECT id FROM loyalty_customers WHERE phone = ?");
                $checkStmt->bind_param("s", $phone);
                $checkStmt->execute();
                $exists = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();
                
                if (!$exists) {
                    $insertStmt = $conn->prepare("INSERT INTO loyalty_customers (phone, name, lifetime_spent_tickets, lifetime_spent_fnb) VALUES (?, ?, ?, ?)");
                    $insertStmt->bind_param("ssdd", $phone, $name, $tickets_paid, $fnb_paid);
                    $insertStmt->execute();
                    $insertStmt->close();
                } else {
                    $updateSpentStmt = $conn->prepare("UPDATE loyalty_customers SET lifetime_spent_tickets = lifetime_spent_tickets + ?, lifetime_spent_fnb = lifetime_spent_fnb + ? WHERE phone = ?");
                    $updateSpentStmt->bind_param("dds", $tickets_paid, $fnb_paid, $phone);
                    $updateSpentStmt->execute();
                    $updateSpentStmt->close();
                }
                
                // Add points transaction for tickets
                if ($ticket_points > 0) {
                    $stmt = $conn->prepare("INSERT INTO loyalty_points_transactions (phone, reservation_id, event_id, points_earned, transaction_type, amount_spent, description, processed_by) VALUES (?, ?, ?, ?, 'tickets', ?, 'Ticket purchase points', ?)");
                    $stmt->bind_param("ssidss", $phone, $res['reservation_id'], $selected_event_id, $ticket_points, $tickets_paid, $_SESSION['admin_username']);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Add points transaction for F&B
                if ($fnb_points > 0) {
                    $stmt = $conn->prepare("INSERT INTO loyalty_points_transactions (phone, reservation_id, event_id, points_earned, transaction_type, amount_spent, description, processed_by) VALUES (?, ?, ?, ?, 'fnb', ?, 'F&B purchase points', ?)");
                    $stmt->bind_param("ssidss", $phone, $res['reservation_id'], $selected_event_id, $fnb_points, $fnb_paid, $_SESSION['admin_username']);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Update customer total points
                $updateStmt = $conn->prepare("UPDATE loyalty_customers SET total_points = total_points + ?, available_points = available_points + ? WHERE phone = ?");
                $updateStmt->bind_param("dds", $total_points, $total_points, $phone);
                $updateStmt->execute();
                $updateStmt->close();
                
                // Update tier
                $tierStmt = $conn->prepare("
                    UPDATE loyalty_customers 
                    SET tier = CASE 
                        WHEN total_points >= 600 THEN 'diamond'
                        WHEN total_points >= 400 THEN 'platinum'
                        WHEN total_points >= 200 THEN 'gold'
                        WHEN total_points >= 100 THEN 'silver'
                        ELSE 'bronze'
                    END
                    WHERE phone = ?
                ");
                $tierStmt->bind_param("s", $phone);
                $tierStmt->execute();
                $tierStmt->close();
                
                // Send WhatsApp message
                $pointsMessage = "⭐ *POINTS EARNED!* ⭐\n\n";
                $pointsMessage .= "Dear {$name},\n\n";
                $pointsMessage .= "You've earned points from {$event['event_name']}:\n";
                $pointsMessage .= "🎫 Tickets: +" . number_format($ticket_points, 1) . " points\n";
                if ($fnb_points > 0) $pointsMessage .= "🍽️ F&B: +" . number_format($fnb_points, 1) . " points\n";
                $pointsMessage .= "━━━━━━━━━━━━━━━━\n";
                $pointsMessage .= "⭐ *Total earned:* " . number_format($total_points, 1) . " points\n\n";
                $pointsMessage .= "Visit our loyalty dashboard to see your rewards!\n\n";
                $pointsMessage .= "Thank you for choosing us! 🎉";
                
                sendWhatsAppMessage($phone, $pointsMessage);
                
                $processed++;
                $totalPointsAwarded += $total_points;
            }
        }
        
        $message = "✅ Processed $processed customers! Total points awarded: " . number_format($totalPointsAwarded, 1);
        $messageType = "success";
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Event Points</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.1);
        }
        h1 { color: #4f46e5; margin-bottom: 20px; }
        .info-box {
            background: #e0e7ff;
            padding: 20px;
            border-radius: 16px;
            margin: 20px 0;
            text-align: left;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            margin: 10px;
        }
        .btn-primary { background: #4f46e5; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 12px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
<div class="card">
    <h1><i class="bi bi-calculator"></i> Process Event Points</h1>
    <p><strong>Event:</strong> <?php echo htmlspecialchars($event['event_name'] ?? 'Current Event'); ?></p>
    
    <div class="info-box">
        <strong><i class="bi bi-info-circle"></i> Points Multipliers:</strong><br>
        🎫 Tickets: <?php echo $pointsMultiplierTickets; ?> points per 1 JOD<br>
        🍽️ F&B: <?php echo $pointsMultiplierFnb; ?> points per 1 JOD
    </div>
    
    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>
    
    <?php if ($messageType != 'success'): ?>
    <form method="POST">
        <input type="password" name="password" placeholder="Enter admin password" required>
        <button type="submit" class="btn btn-primary">Process Points Now</button>
        <a href="loyalty_dashboard.php" class="btn btn-secondary">Cancel</a>
    </form>
    <?php else: ?>
        <a href="loyalty_dashboard.php" class="btn btn-primary">Go to Loyalty Dashboard</a>
    <?php endif; ?>
</div>
</body>
</html>