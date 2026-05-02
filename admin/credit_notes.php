<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();
$message = '';
$messageType = '';

// Process credit note (approve/process)
if (isset($_GET['action']) && isset($_GET['id'])) {
    $credit_id = intval($_GET['id']);
    $action = $_GET['action'];
    
    if ($action == 'approve') {
        $stmt = $conn->prepare("UPDATE credit_notes SET status = 'approved', processed_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $credit_id);
        $stmt->execute();
        $stmt->close();
        $message = "Credit note approved!";
        $messageType = "success";
    } elseif ($action == 'process') {
        $stmt = $conn->prepare("UPDATE credit_notes SET status = 'processed', processed_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $credit_id);
        $stmt->execute();
        $stmt->close();
        $message = "Credit note marked as processed!";
        $messageType = "success";
    } elseif ($action == 'cancel') {
        $stmt = $conn->prepare("UPDATE credit_notes SET status = 'cancelled' WHERE id = ?");
        $stmt->bind_param("i", $credit_id);
        $stmt->execute();
        $stmt->close();
        $message = "Credit note cancelled!";
        $messageType = "success";
    }
    header("Location: credit_notes.php");
    exit();
}

// Get all credit notes
$credits = $conn->query("SELECT * FROM credit_notes ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);

// Get customer info for each credit note
foreach ($credits as &$credit) {
    $stmt = $conn->prepare("SELECT name, phone FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $credit['reservation_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $credit['customer_name'] = $row['name'];
        $credit['customer_phone'] = $row['phone'];
    } else {
        $credit['customer_name'] = 'Unknown';
        $credit['customer_phone'] = 'Unknown';
    }
    $stmt->close();
}

// Get statistics with default values to prevent null
$statsResult = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) as processed,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
    SUM(CASE WHEN status IN ('pending', 'approved') THEN amount ELSE 0 END) as total_pending_amount,
    SUM(CASE WHEN status = 'processed' THEN amount ELSE 0 END) as total_processed_amount
FROM credit_notes");

$stats = $statsResult->fetch_assoc();

// Set defaults to 0 if null
$stats['total'] = $stats['total'] ?? 0;
$stats['pending'] = $stats['pending'] ?? 0;
$stats['approved'] = $stats['approved'] ?? 0;
$stats['processed'] = $stats['processed'] ?? 0;
$stats['cancelled'] = $stats['cancelled'] ?? 0;
$stats['total_pending_amount'] = $stats['total_pending_amount'] ?? 0;
$stats['total_processed_amount'] = $stats['total_processed_amount'] ?? 0;

$conn->close();
$currencySymbol = getCurrencySymbol();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Credit Notes - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .navbar {
            background: white;
            border-radius: 24px;
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stat-number { font-size: 28px; font-weight: bold; }
        .stat-label { font-size: 13px; color: #64748b; margin-top: 5px; }
        .card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        .btn {
            padding: 8px 16px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
            font-size: 14px;
        }
        .btn-primary { background: #4f46e5; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-success { background: #10b981; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #dbeafe; color: #1e40af; }
        .badge-processed { background: #d1fae5; color: #065f46; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }
        .alert {
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #d1fae5; color: #065f46; }
        .table-container { overflow-x: auto; }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="navbar">
            <h1><i class="bi bi-receipt"></i> Credit Notes</h1>
            <div>
                <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
                <a href="logout.php" class="btn btn-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="bi bi-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo intval($stats['total']); ?></div>
                <div class="stat-label">Total Credit Notes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #f59e0b;"><?php echo intval($stats['pending']); ?></div>
                <div class="stat-label">Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #10b981;"><?php echo number_format(floatval($stats['total_processed_amount']), 2); ?> <?php echo $currencySymbol; ?></div>
                <div class="stat-label">Processed Amount</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #f59e0b;"><?php echo number_format(floatval($stats['total_pending_amount']), 2); ?> <?php echo $currencySymbol; ?></div>
                <div class="stat-label">Pending Amount</div>
            </div>
        </div>
        
        <!-- Credit Notes Table -->
        <div class="card">
            <div class="card-header">
                <h2><i class="bi bi-list-ul"></i> Credit Notes List</h2>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Reservation ID</th>
                            <th>Customer</th>
                            <th>Amount</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($credits as $credit): ?>
                        <tr>
                            <td><?php echo $credit['id']; ?></d>
                            <td><a href="edit_reservation.php?id=<?php echo urlencode($credit['reservation_id']); ?>"><?php echo htmlspecialchars($credit['reservation_id']); ?></a></d>
                            <td>
                                <strong><?php echo htmlspecialchars($credit['customer_name'] ?? 'Unknown'); ?></strong><br>
                                <small><?php echo htmlspecialchars($credit['customer_phone'] ?? 'N/A'); ?></small>
                            </d>
                            <td> style="color: #10b981; font-weight: bold;"><?php echo number_format(floatval($credit['amount']), 2); ?> <?php echo $currencySymbol; ?></d>
                            <td><?php echo htmlspecialchars($credit['reason'] ?? 'Guest count decreased'); ?></d>
                            <td>
                                <span class="badge badge-<?php echo $credit['status']; ?>">
                                    <?php echo ucfirst($credit['status']); ?>
                                </span>
                            </d>
                            <td><?php echo date('M d, Y H:i', strtotime($credit['created_at'])); ?></d>
                            <td>
                                <?php if ($credit['status'] == 'pending'): ?>
                                    <a href="?action=approve&id=<?php echo $credit['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Approve this credit note?')">Approve</a>
                                <?php endif; ?>
                                <?php if ($credit['status'] == 'approved'): ?>
                                    <a href="?action=process&id=<?php echo $credit['id']; ?>" class="btn btn-sm btn-primary" onclick="return confirm('Mark as processed?')">Process Refund</a>
                                <?php endif; ?>
                                <?php if ($credit['status'] != 'cancelled' && $credit['status'] != 'processed'): ?>
                                    <a href="?action=cancel&id=<?php echo $credit['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Cancel this credit note?')">Cancel</a>
                                <?php endif; ?>
                            </d>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($credits)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 60px;">
                                    <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.5;"></i>
                                    <p>No credit notes found</p>
                                </d>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>