<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();
$event_id = $_SESSION['selected_event_id'] ?? 0;
$event_name = $_SESSION['selected_event_name'] ?? 'Event';

$message = '';
$messageType = '';

// Get event details
$stmt = $conn->prepare("SELECT * FROM event_settings WHERE id = ?");
$stmt->bind_param("i", $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$event) {
    header('Location: dashboard.php?error=Event not found');
    exit();
}

// Handle event closure
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_close'])) {
    $final_report = [];
    
    // Get all reservations for this event (assuming all reservations belong to current event)
    $reservations = $conn->query("SELECT * FROM reservations ORDER BY created_at");
    $all_reservations = $reservations->fetch_all(MYSQLI_ASSOC);
    
    // Calculate statistics
    $total_reservations = count($all_reservations);
    $total_guests = 0;
    $total_paid = 0;
    $total_due = 0;
    $total_cancelled = 0;
    $total_paid_count = 0;
    $cancelled_count = 0;
    $pending_count = 0;
    
    foreach ($all_reservations as $res) {
        $total_guests += $res['adults'] + $res['teens'] + $res['kids'];
        $total_due += floatval($res['total_amount']);
        
        // Get paid amount from split_payments
        $paid_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as paid FROM split_payments WHERE reservation_id = ?");
        $paid_stmt->bind_param("s", $res['reservation_id']);
        $paid_stmt->execute();
        $paid = $paid_stmt->get_result()->fetch_assoc()['paid'];
        $paid_stmt->close();
        
        $total_paid += $paid;
        
        if ($res['status'] == 'cancelled') {
            $cancelled_count++;
            $total_cancelled += floatval($res['total_amount']);
        } elseif ($res['status'] == 'paid') {
            $total_paid_count++;
        } elseif ($res['status'] == 'pending' || $res['status'] == 'registered') {
            $pending_count++;
        }
    }
    
    // Get payment method breakdown
    $payment_methods = $conn->query("SELECT payment_method, SUM(amount) as total FROM split_payments GROUP BY payment_method")->fetch_all(MYSQLI_ASSOC);
    
    // Get daily ticket sales
    $daily_sales = $conn->query("SELECT DATE(created_at) as date, COUNT(*) as count, SUM(total_amount) as total FROM reservations GROUP BY DATE(created_at) ORDER BY date")->fetch_all(MYSQLI_ASSOC);
    
    // Build final report
    $final_report = [
        'event_name' => $event['event_name'],
        'event_date' => $event['event_date'],
        'event_venue' => $event['venue'],
        'closed_by' => $_SESSION['admin_username'],
        'closed_at' => date('Y-m-d H:i:s'),
        'summary' => [
            'total_reservations' => $total_reservations,
            'total_guests' => $total_guests,
            'total_revenue' => $total_paid,
            'total_due' => $total_due,
            'total_cancelled' => $total_cancelled,
            'paid_reservations' => $total_paid_count,
            'cancelled_reservations' => $cancelled_count,
            'pending_reservations' => $pending_count,
            'collection_rate' => $total_paid > 0 ? round(($total_paid / $total_due) * 100, 2) : 0
        ],
        'payment_breakdown' => $payment_methods,
        'daily_sales' => $daily_sales,
        'reservations' => $all_reservations
    ];
    
    $report_json = json_encode($final_report, JSON_PRETTY_PRINT);
    
    // Update event as closed
    $update = $conn->prepare("UPDATE event_settings SET status = 'completed', is_closed = 1, closed_at = NOW(), final_report = ? WHERE id = ?");
    $update->bind_param("si", $report_json, $event_id);
    
    if ($update->execute()) {
        $message = "Event has been closed successfully! Final report generated.";
        $messageType = "success";
        
        // Optionally send notification to admins
        // sendEventClosedNotification($event['event_name'], $final_report['summary']);
        
        // Store report in session for display
        $_SESSION['final_report'] = $final_report;
        
        header("Location: close_event.php?report=1");
        exit();
    } else {
        $message = "Error closing event: " . $conn->error;
        $messageType = "error";
    }
    $update->close();
}

// Display report after closure
$show_report = isset($_GET['report']) && isset($_SESSION['final_report']);
$report = $show_report ? $_SESSION['final_report'] : null;

$currencySymbol = getCurrencySymbol();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Close Event - <?php echo htmlspecialchars($event['event_name']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        .header {
            background: linear-gradient(135deg, #1e293b, #0f172a);
            color: white;
            padding: 30px;
            border-radius: 24px;
            margin-bottom: 24px;
        }
        .card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #f8fafc;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
        }
        .stat-label {
            color: #64748b;
            font-size: 13px;
            margin-top: 5px;
        }
        .warning-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-primary { background: #4f46e5; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-success { background: #10b981; color: white; }
        .actions { text-align: center; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; }
        .print-only { display: none; }
        @media print {
            .no-print { display: none; }
            .print-only { display: block; }
            body { background: white; padding: 0; }
            .card { break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="bi bi-calendar-check"></i> Close Event</h1>
            <p><?php echo htmlspecialchars($event['event_name']); ?> | <?php echo date('F j, Y', strtotime($event['event_date'])); ?></p>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>" style="background: #d1fae5; padding: 12px; border-radius: 12px; margin-bottom: 20px; color: #065f46;">
                <i class="bi bi-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($show_report && $report): ?>
            <!-- Final Report -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="bi bi-file-text"></i> Event Final Report</h2>
                    <button onclick="window.print()" class="btn btn-secondary btn-sm no-print"><i class="bi bi-printer"></i> Print Report</button>
                </div>
                
                <!-- Summary Statistics -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $report['summary']['total_reservations']; ?></div>
                        <div class="stat-label">Total Reservations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $report['summary']['total_guests']; ?></div>
                        <div class="stat-label">Total Guests</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo number_format($report['summary']['total_revenue'], 2); ?> <?php echo $currencySymbol; ?></div>
                        <div class="stat-label">Total Revenue</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $report['summary']['collection_rate']; ?>%</div>
                        <div class="stat-label">Collection Rate</div>
                    </div>
                </div>
                
                <!-- Payment Breakdown -->
                <h3><i class="bi bi-credit-card"></i> Payment Methods</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Method</th>
                            <th>Amount</th>
                        </d>
                    </thead>
                    <tbody>
                        <?php foreach ($report['payment_breakdown'] as $method): ?>
                        <tr>
                            <td><?php echo ucfirst($method['payment_method']); ?></d>
                            <td><?php echo number_format($method['total'], 2); ?> <?php echo $currencySymbol; ?></d>
                        </d>
                        <?php endforeach; ?>
                        <tr style="font-weight: bold; background: #f8fafc;">
                            <td>Total</td>
                            <td><?php echo number_format($report['summary']['total_revenue'], 2); ?> <?php echo $currencySymbol; ?></td>
                        </d>
                    </tbody>
                </table>
                
                <!-- Status Breakdown -->
                <h3 style="margin-top: 20px;"><i class="bi bi-pie-chart"></i> Reservation Status</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Status</th>
                            <th>Count</th>
                        </d>
                    </thead>
                    <tbody>
                        <tr><td>✅ Paid</td><td><?php echo $report['summary']['paid_reservations']; ?></td></tr>
                        <tr><td>⏳ Pending/Registered</td><td><?php echo $report['summary']['pending_reservations']; ?></td></tr>
                        <tr><td>❌ Cancelled</td><td><?php echo $report['summary']['cancelled_reservations']; ?></td></tr>
                    </tbody>
                </table>
                
                <div class="print-only" style="margin-top: 30px; text-align: center;">
                    <p>Report generated on <?php echo date('F j, Y g:i A'); ?></p>
                    <p>Closed by: <?php echo htmlspecialchars($report['closed_by']); ?></p>
                </div>
            </div>
            
            <div class="actions no-print">
                <a href="dashboard.php" class="btn btn-primary"><i class="bi bi-house"></i> Return to Dashboard</a>
            </div>
            
        <?php elseif ($event['is_closed'] == 1): ?>
            <div class="warning-box">
                <i class="bi bi-info-circle"></i>
                <strong>This event is already closed.</strong>
                <p>The event has been marked as completed. You can view the final report above.</p>
            </div>
            
        <?php else: ?>
            <!-- Pre-closure warning and stats preview -->
            <div class="card">
                <h3><i class="bi bi-bar-chart"></i> Current Event Statistics</h3>
                <?php
                // Preview statistics before closing
                $total_res = $conn->query("SELECT COUNT(*) as count FROM reservations")->fetch_assoc()['count'];
                $total_paid = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM split_payments")->fetch_assoc()['total'];
                $total_guests = $conn->query("SELECT COALESCE(SUM(adults + teens + kids), 0) as total FROM reservations")->fetch_assoc()['total'];
                ?>
                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-number"><?php echo $total_res; ?></div><div class="stat-label">Reservations</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo $total_guests; ?></div><div class="stat-label">Guests</div></div>
                    <div class="stat-card"><div class="stat-number"><?php echo number_format($total_paid, 2); ?> <?php echo $currencySymbol; ?></div><div class="stat-label">Revenue</div></div>
                </div>
            </div>
            
            <div class="warning-box">
                <i class="bi bi-exclamation-triangle-fill" style="color: #f59e0b; font-size: 24px;"></i>
                <strong>⚠️ WARNING: This action will close the event permanently!</strong><br><br>
                <ul style="margin-left: 20px;">
                    <li>No more reservations can be created or modified</li>
                    <li>A final report will be generated</li>
                    <li>The event status will change to "Completed"</li>
                    <li>This action cannot be undone</li>
                </ul>
            </div>
            
            <div class="card">
                <h3><i class="bi bi-clipboard-check"></i> Confirmation</h3>
                <p>Please confirm that you want to close this event and generate the final report.</p>
                
                <form method="POST">
                    <div style="margin: 20px 0;">
                        <label>
                            <input type="checkbox" id="confirmCheckbox" required>
                            I confirm that the event has ended and I want to generate the final report.
                        </label>
                    </div>
                    <div class="actions">
                        <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" name="confirm_close" value="1" class="btn btn-danger" id="closeBtn" disabled>
                            <i class="bi bi-lock"></i> Close Event & Generate Report
                        </button>
                    </div>
                </form>
            </div>
            
            <script>
                document.getElementById('confirmCheckbox').addEventListener('change', function() {
                    document.getElementById('closeBtn').disabled = !this.checked;
                });
            </script>
        <?php endif; ?>
    </div>
</body>
</html>