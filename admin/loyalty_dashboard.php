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
$eventStmt = $conn->prepare("SELECT event_name, points_multiplier_tickets, points_multiplier_fnb, points_enabled FROM event_settings WHERE id = ?");
$eventStmt->bind_param("i", $selected_event_id);
$eventStmt->execute();
$event = $eventStmt->get_result()->fetch_assoc();
$eventStmt->close();

$pointsMultiplierTickets = $event['points_multiplier_tickets'] ?? 1.3;
$pointsMultiplierFnb = $event['points_multiplier_fnb'] ?? 1.0;
$pointsEnabled = $event['points_enabled'] ?? 1;

// Handle adding points manually
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $password = $_POST['password'] ?? '';
    if ($password !== 'AdminDelete2026') {
        $message = "Invalid password!";
        $messageType = "error";
    } elseif ($_POST['action'] == 'add_points') {
        $phone = sanitizeInput($_POST['phone']);
        $points = floatval($_POST['points']);
        $type = sanitizeInput($_POST['type']);
        $description = sanitizeInput($_POST['description']);
        
        // Get or create customer
        $checkStmt = $conn->prepare("SELECT id, name FROM loyalty_customers WHERE phone = ?");
        $checkStmt->bind_param("s", $phone);
        $checkStmt->execute();
        $customer = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        
        if (!$customer) {
            $name = sanitizeInput($_POST['name'] ?? 'Unknown');
            $insertStmt = $conn->prepare("INSERT INTO loyalty_customers (phone, name) VALUES (?, ?)");
            $insertStmt->bind_param("ss", $phone, $name);
            $insertStmt->execute();
            $insertStmt->close();
        }
        
        // Add points transaction
        $stmt = $conn->prepare("INSERT INTO loyalty_points_transactions (phone, points_earned, transaction_type, description, processed_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sdsss", $phone, $points, $type, $description, $_SESSION['admin_username']);
        $stmt->execute();
        $stmt->close();
        
        // Update customer total points
        $updateStmt = $conn->prepare("UPDATE loyalty_customers SET total_points = total_points + ?, available_points = available_points + ? WHERE phone = ?");
        $updateStmt->bind_param("dds", $points, $points, $phone);
        $updateStmt->execute();
        $updateStmt->close();
        
        $message = "Added $points points to customer!";
        $messageType = "success";
        
    } elseif ($_POST['action'] == 'process_event_points') {
        // Process points for all completed reservations in this event
        $event_id = $selected_event_id;
        
        // Get all paid reservations for this event that haven't had points awarded
        $reservations = $conn->prepare("
            SELECT r.reservation_id, r.name, r.phone, r.total_amount, r.price_tier, 
                   COALESCE(SUM(sp.amount), 0) as total_paid,
                   COALESCE(SUM(CASE WHEN sp.is_fnb = 1 THEN sp.fnb_amount ELSE 0 END), 0) as fnb_paid
            FROM reservations r
            LEFT JOIN split_payments sp ON r.reservation_id = sp.reservation_id
            WHERE r.event_id = ? AND r.status = 'paid'
            AND NOT EXISTS (
                SELECT 1 FROM loyalty_points_transactions lpt 
                WHERE lpt.reservation_id = r.reservation_id AND lpt.transaction_type IN ('tickets', 'fnb')
            )
            GROUP BY r.reservation_id
        ");
        $reservations->bind_param("i", $event_id);
        $reservations->execute();
        $result = $reservations->get_result();
        
        $processed = 0;
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
                    $stmt->bind_param("ssidss", $phone, $res['reservation_id'], $event_id, $ticket_points, $tickets_paid, $_SESSION['admin_username']);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Add points transaction for F&B
                if ($fnb_points > 0) {
                    $stmt = $conn->prepare("INSERT INTO loyalty_points_transactions (phone, reservation_id, event_id, points_earned, transaction_type, amount_spent, description, processed_by) VALUES (?, ?, ?, ?, 'fnb', ?, 'F&B purchase points', ?)");
                    $stmt->bind_param("ssidss", $phone, $res['reservation_id'], $event_id, $fnb_points, $fnb_paid, $_SESSION['admin_username']);
                    $stmt->execute();
                    $stmt->close();
                }
                
                // Update customer total points
                $updateStmt = $conn->prepare("UPDATE loyalty_customers SET total_points = total_points + ?, available_points = available_points + ?, updated_at = NOW() WHERE phone = ?");
                $updateStmt->bind_param("dds", $total_points, $total_points, $phone);
                $updateStmt->execute();
                $updateStmt->close();
                
                // Update tier based on total points
                updateCustomerTier($conn, $phone);
                
                $processed++;
            }
        }
        
        $message = "Processed $processed customers with total points awarded!";
        $messageType = "success";
    }
}

// Get all loyalty customers
$customers = $conn->query("
    SELECT *, 
           CASE 
               WHEN total_points >= 600 THEN 'diamond'
               WHEN total_points >= 400 THEN 'platinum'
               WHEN total_points >= 200 THEN 'gold'
               WHEN total_points >= 100 THEN 'silver'
               ELSE 'bronze'
           END as computed_tier
    FROM loyalty_customers 
    ORDER BY total_points DESC
")->fetch_all(MYSQLI_ASSOC);

// Get recent transactions
$transactions = $conn->query("
    SELECT lpt.*, lc.name 
    FROM loyalty_points_transactions lpt
    JOIN loyalty_customers lc ON lpt.phone = lc.phone
    ORDER BY lpt.created_at DESC 
    LIMIT 50
")->fetch_all(MYSQLI_ASSOC);

// Get rewards
$rewards = $conn->query("SELECT * FROM loyalty_rewards WHERE is_active = 1 ORDER BY points_required")->fetch_all(MYSQLI_ASSOC);

$conn->close();

function updateCustomerTier($conn, $phone) {
    $stmt = $conn->prepare("
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
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loyalty Points System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        body.dark-mode { background: #0f172a; color: #e2e8f0; }
        .container { max-width: 1400px; margin: 0 auto; }
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
        body.dark-mode .navbar { background: #1e293b; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
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
        body.dark-mode .stat-card { background: #1e293b; }
        .stat-number { font-size: 28px; font-weight: bold; }
        .stat-label { font-size: 13px; color: #64748b; margin-top: 5px; }
        .card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        body.dark-mode .card { background: #1e293b; }
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        body.dark-mode .card-header { border-bottom-color: #334155; }
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
            transition: all 0.2s;
        }
        .btn-primary { background: #4f46e5; color: white; }
        .btn-primary:hover { background: #4338ca; transform: translateY(-1px); }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; }
        body.dark-mode th { background: #0f172a; color: #94a3b8; }
        body.dark-mode td { border-bottom-color: #334155; }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-bronze { background: #cd7f32; color: white; }
        .badge-silver { background: #c0c0c0; color: #1e293b; }
        .badge-gold { background: #ffd700; color: #1e293b; }
        .badge-platinum { background: #e5e4e2; color: #1e293b; }
        .badge-diamond { background: #b9f2ff; color: #1e293b; }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-container {
            background: white;
            border-radius: 24px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        body.dark-mode .modal-container { background: #1e293b; }
        .modal-header {
            padding: 20px 24px;
            background: #4f46e5;
            color: white;
            border-radius: 24px 24px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: white;
        }
        .modal-body { padding: 24px; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #334155;
        }
        body.dark-mode .form-group label { color: #cbd5e1; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }
        body.dark-mode .form-group input, body.dark-mode .form-group select, body.dark-mode .form-group textarea {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }
        .alert {
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        @media (max-width: 1024px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .navbar { flex-direction: column; text-align: center; }
            .card-header { flex-direction: column; gap: 10px; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="navbar">
        <h1><i class="bi bi-star-fill"></i> Loyalty Points System</h1>
        <div>
            <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
            <a href="logout.php" class="btn btn-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <i class="bi bi-<?php echo $messageType == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo count($customers); ?></div>
            <div class="stat-label">Total Customers</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo array_sum(array_column($customers, 'total_points')); ?></div>
            <div class="stat-label">Total Points Earned</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo array_sum(array_column($customers, 'available_points')); ?></div>
            <div class="stat-label">Available Points</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($pointsMultiplierTickets, 1); ?>x</div>
            <div class="stat-label">Ticket Points Multiplier</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($pointsMultiplierFnb, 1); ?>x</div>
            <div class="stat-label">F&B Points Multiplier</div>
        </div>
    </div>

    <!-- Actions -->
    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-gear"></i> Actions</h2>
            <div>
                <button onclick="openAddPointsModal()" class="btn btn-success"><i class="bi bi-plus-circle"></i> Add Points Manually</button>
                <button onclick="processEventPoints()" class="btn btn-primary"><i class="bi bi-calculator"></i> Process Event Points</button>
                <button onclick="openRedeemModal()" class="btn btn-warning"><i class="bi bi-gift"></i> Redeem Reward</button>
            </div>
        </div>
        <div class="alert alert-success" style="background: #e0e7ff; color: #3730a3; border-left-color: #4f46e5;">
            <i class="bi bi-info-circle"></i>
            <strong>Points Calculation:</strong> Tickets: <?php echo $pointsMultiplierTickets; ?> points per 1 JOD | 
            F&B: <?php echo $pointsMultiplierFnb; ?> points per 1 JOD
        </div>
    </div>

    <!-- Rewards Catalog -->
    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-gift-fill"></i> Rewards Catalog</h2>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>Reward</th><th>Points Required</th><th>Description</th><th>Type</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($rewards as $reward): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($reward['reward_name']); ?></strong></d>
                        <td><span class="badge" style="background: #f59e0b; color: white;"><?php echo $reward['points_required']; ?> pts</span></d>
                        <td><?php echo htmlspecialchars($reward['description']); ?></d>
                        <td><?php echo ucfirst($reward['reward_type']); ?></d>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Customers List -->
    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-people"></i> Loyalty Customers</h2>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>Phone</th><th>Name</th><th>Total Points</th><th>Available Points</th><th>Tier</th><th>Ticket Spent</th><th>F&B Spent</th><th>Actions</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($customer['phone']); ?></d>
                        <td><?php echo htmlspecialchars($customer['name']); ?></d>
                        <td><strong><?php echo number_format($customer['total_points'], 1); ?></strong></d>
                        <td><?php echo number_format($customer['available_points'], 1); ?></d>
                        <td><span class="badge badge-<?php echo $customer['computed_tier']; ?>"><?php echo ucfirst($customer['computed_tier']); ?></span></d>
                        <td><?php echo number_format($customer['lifetime_spent_tickets'], 2); ?> JOD</d>
                        <td><?php echo number_format($customer['lifetime_spent_fnb'], 2); ?> JOD</d>
                        <td><button onclick="viewCustomer('<?php echo $customer['phone']; ?>', '<?php echo htmlspecialchars($customer['name']); ?>')" class="btn btn-sm btn-primary"><i class="bi bi-eye"></i> View</button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-clock-history"></i> Recent Transactions</h2>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>Customer</th><th>Type</th><th>Points</th><th>Amount</th><th>Description</th><th>Date</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $trans): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($trans['name']); ?></d>
                        <td><span class="badge" style="background: <?php echo $trans['points_earned'] > 0 ? '#10b981' : '#ef4444'; ?>; color: white;"><?php echo ucfirst($trans['transaction_type']); ?></span></d>
                        <td><strong><?php echo $trans['points_earned'] > 0 ? '+' . number_format($trans['points_earned'], 1) : '-' . number_format($trans['points_redeemed'], 1); ?></strong></d>
                        <td><?php echo $trans['amount_spent'] > 0 ? number_format($trans['amount_spent'], 2) . ' JOD' : '-'; ?></d>
                        <td><?php echo htmlspecialchars($trans['description']); ?></d>
                        <td><?php echo date('M d, H:i', strtotime($trans['created_at'])); ?></d>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Points Modal -->
<div id="addPointsModal" class="modal-overlay">
    <div class="modal-container">
        <form method="POST">
            <input type="hidden" name="action" value="add_points">
            <div class="modal-header">
                <h3><i class="bi bi-plus-circle"></i> Add Points Manually</h3>
                <button type="button" class="modal-close" onclick="closeAddPointsModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Phone Number *</label>
                    <input type="tel" name="phone" required placeholder="9627XXXXXXXX">
                </div>
                <div class="form-group">
                    <label>Customer Name (if new)</label>
                    <input type="text" name="name" placeholder="Customer name">
                </div>
                <div class="form-group">
                    <label>Points to Add *</label>
                    <input type="number" name="points" step="0.1" required placeholder="Points amount">
                </div>
                <div class="form-group">
                    <label>Transaction Type</label>
                    <select name="type">
                        <option value="bonus">Bonus Points</option>
                        <option value="adjustment">Adjustment</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="2" placeholder="Reason for adding points..."></textarea>
                </div>
                <div class="form-group">
                    <label>Admin Password *</label>
                    <input type="password" name="password" required>
                </div>
            </div>
            <div class="modal-buttons" style="padding: 0 24px 24px 24px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeAddPointsModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-success">Add Points</button>
            </div>
        </form>
    </div>
</div>

<!-- Redeem Modal -->
<div id="redeemModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-gift"></i> Redeem Reward</h3>
            <button type="button" class="modal-close" onclick="closeRedeemModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Customer Phone</label>
                <input type="tel" id="redeemPhone" placeholder="9627XXXXXXXX">
            </div>
            <div class="form-group">
                <label>Available Points</label>
                <input type="text" id="availablePointsDisplay" readonly disabled style="background: #f1f5f9;">
            </div>
            <div class="form-group">
                <label>Select Reward</label>
                <select id="rewardSelect" onchange="checkPoints()">
                    <option value="">-- Select a reward --</option>
                    <?php foreach ($rewards as $reward): ?>
                        <option value="<?php echo $reward['id']; ?>" data-points="<?php echo $reward['points_required']; ?>" data-name="<?php echo htmlspecialchars($reward['reward_name']); ?>">
                            <?php echo $reward['reward_name']; ?> (<?php echo $reward['points_required']; ?> pts)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="redeemMessage" class="alert" style="display: none;"></div>
            <div class="modal-buttons" style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" onclick="closeRedeemModal()" class="btn btn-secondary">Cancel</button>
                <button type="button" onclick="processRedeem()" class="btn btn-warning">Redeem</button>
            </div>
        </div>
    </div>
</div>

<script>
function openAddPointsModal() {
    document.getElementById('addPointsModal').classList.add('active');
}
function closeAddPointsModal() {
    document.getElementById('addPointsModal').classList.remove('active');
}
function openRedeemModal() {
    document.getElementById('redeemModal').classList.add('active');
    document.getElementById('redeemPhone').value = '';
    document.getElementById('availablePointsDisplay').value = '';
    document.getElementById('rewardSelect').value = '';
    document.getElementById('redeemMessage').style.display = 'none';
}
function closeRedeemModal() {
    document.getElementById('redeemModal').classList.remove('active');
}
function processEventPoints() {
    if (confirm('Process points for all completed reservations in this event? This will award points to customers.')) {
        var form = document.createElement('form');
        form.method = 'POST';
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'action';
        input.value = 'process_event_points';
        form.appendChild(input);
        var password = prompt('Enter admin password:');
        if (password) {
            var passInput = document.createElement('input');
            passInput.type = 'hidden';
            passInput.name = 'password';
            passInput.value = password;
            form.appendChild(passInput);
            document.body.appendChild(form);
            form.submit();
        }
    }
}
function viewCustomer(phone, name) {
    alert('View customer: ' + name + '\nPhone: ' + phone + '\n\nThis will show full transaction history.');
    // You can expand this to a modal or new page
}
function checkPoints() {
    var phone = document.getElementById('redeemPhone').value;
    if (phone.length < 10) return;
    
    fetch('loyalty_api.php?action=get_points&phone=' + encodeURIComponent(phone))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('availablePointsDisplay').value = data.available_points.toFixed(1) + ' points';
                var select = document.getElementById('rewardSelect');
                var selectedOption = select.options[select.selectedIndex];
                var requiredPoints = selectedOption ? parseFloat(selectedOption.dataset.points) : 0;
                var msgDiv = document.getElementById('redeemMessage');
                
                if (data.available_points >= requiredPoints && requiredPoints > 0) {
                    msgDiv.style.display = 'block';
                    msgDiv.className = 'alert alert-success';
                    msgDiv.innerHTML = '<i class="bi bi-check-circle"></i> Customer has enough points! (' + data.available_points.toFixed(1) + ' available)';
                } else if (requiredPoints > 0) {
                    msgDiv.style.display = 'block';
                    msgDiv.className = 'alert alert-error';
                    msgDiv.innerHTML = '<i class="bi bi-exclamation-circle"></i> Insufficient points. Needs ' + requiredPoints + ' points, has ' + data.available_points.toFixed(1);
                }
            }
        });
}
function processRedeem() {
    var phone = document.getElementById('redeemPhone').value;
    var rewardId = document.getElementById('rewardSelect').value;
    var rewardName = document.getElementById('rewardSelect').options[document.getElementById('rewardSelect').selectedIndex]?.text;
    var password = prompt('Enter admin password to confirm redemption:');
    
    if (!phone || !rewardId || !password) return;
    
    fetch('loyalty_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            action: 'redeem',
            phone: phone,
            reward_id: rewardId,
            password: password
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Reward redeemed successfully!\n\nRedemption Code: ' + data.redemption_code + '\n\nGive this code to the customer.');
            closeRedeemModal();
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    });
}
window.onclick = function(event) {
    if (event.target.classList.contains('modal-overlay')) {
        event.target.classList.remove('active');
    }
}
</script>
</body>
</html>