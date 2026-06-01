<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/language.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();
$selected_event_id = $_SESSION['selected_event_id'] ?? 0;

// Get filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query with financial data
$query = "SELECT 
    r.reservation_id, 
    r.name, 
    r.phone, 
    r.table_id,
    r.adults,
    r.teens,
    r.kids,
    (r.adults + r.teens + r.kids) as total_guests,
    r.status,
    r.total_amount,
    r.price_tier,
    COALESCE((SELECT SUM(amount) FROM split_payments WHERE reservation_id = r.reservation_id), 0) as total_paid,
    r.created_at
FROM reservations r
WHERE r.event_id = ?";

$params = [$selected_event_id];
$types = "i";

if ($status_filter && $status_filter != 'all') {
    $query .= " AND r.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($search) {
    $query .= " AND (r.name LIKE ? OR r.reservation_id LIKE ? OR r.phone LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$query .= " ORDER BY r.created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$reservations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate financial totals
$totalRevenue = 0;
$totalPaid = 0;
$totalDue = 0;
$regularRevenue = 0;
$loyaltyRevenue = 0;

foreach ($reservations as $res) {
    if ($res['status'] != 'cancelled') {
        $totalRevenue += floatval($res['total_amount']);
        $totalPaid += floatval($res['total_paid']);
        $totalDue += max(0, floatval($res['total_amount']) - floatval($res['total_paid']));
        
        if ($res['price_tier'] == 'regular') {
            $regularRevenue += floatval($res['total_amount']);
        } elseif ($res['price_tier'] == 'loyalty') {
            $loyaltyRevenue += floatval($res['total_amount']);
        }
    }
}

// Get status counts
$statusCounts = ['pending' => 0, 'registered' => 0, 'paid' => 0, 'cancelled' => 0];
foreach ($reservations as $res) {
    if (isset($statusCounts[$res['status']])) $statusCounts[$res['status']]++;
}

$conn->close();
$currencySymbol = getCurrencySymbol();
?>
<!DOCTYPE html>
<html lang="<?php echo getCurrentLanguage(); ?>" dir="<?php echo getDirection(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Statement - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        @media print {
            body { margin: 0; padding: 0; }
            .no-print { display: none; }
            .statement-container { margin: 0; padding: 20px; }
            .page-break { page-break-before: always; }
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .statement-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #667eea;
        }
        .header h1 { font-size: 28px; color: #1a1a2e; margin-bottom: 5px; }
        .header h1 span { color: #667eea; }
        .header .subtitle { color: #666; font-size: 14px; margin-top: 5px; }
        .financial-summary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 25px;
        }
        .financial-summary h3 { margin-bottom: 15px; }
        .financial-grid {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        .financial-item { text-align: center; }
        .financial-amount { font-size: 24px; font-weight: bold; }
        .financial-label { font-size: 12px; opacity: 0.9; margin-top: 5px; }
        .stats-bar {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
        }
        .stat-item { text-align: center; }
        .stat-number { font-size: 24px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 12px; color: #666; }
        .filter-info {
            background: #e0e7ff;
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #3730a3;
        }
        .table-container { overflow-x: auto; margin-top: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: 12px 15px; text-align: left; font-weight: 600; color: #475569; border-bottom: 2px solid #e2e8f0; }
        td { padding: 10px 15px; border-bottom: 1px solid #e2e8f0; color: #334155; }
        tr:hover { background: #f8fafc; }
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-registered { background: #dbeafe; color: #1e40af; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            font-size: 12px;
            color: #94a3b8;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-success { background: #10b981; color: white; }
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        .filters { display: flex; gap: 10px; flex-wrap: wrap; }
        .filters select, .filters input { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        @media (max-width: 768px) {
            .statement-container { padding: 15px; }
            .financial-grid { flex-direction: column; align-items: center; }
            .stats-bar { flex-direction: column; align-items: center; }
            .action-bar { flex-direction: column; }
            .filters { width: 100%; flex-direction: column; }
            .filters select, .filters input, .filters button { width: 100%; }
            th, td { padding: 8px 10px; font-size: 12px; }
        }
    </style>
</head>
<body>
<div class="statement-container">
    <div class="header">
        <h1>📋 <span>Reservation Statement</span></h1>
        <p class="subtitle">Generated on: <?php echo date('F j, Y g:i A'); ?></p>
        <p class="subtitle">Event: <?php echo htmlspecialchars($_SESSION['selected_event_name'] ?? 'N/A'); ?></p>
    </div>

    <!-- Financial Summary -->
    <div class="financial-summary">
        <h3><i class="bi bi-calculator"></i> Financial Summary</h3>
        <div class="financial-grid">
            <div class="financial-item"><div class="financial-amount"><?php echo $currencySymbol; ?> <?php echo number_format($totalRevenue, 2); ?></div><div class="financial-label">Total Revenue (Booked)</div></div>
            <div class="financial-item"><div class="financial-amount"><?php echo $currencySymbol; ?> <?php echo number_format($totalPaid, 2); ?></div><div class="financial-label">Total Paid</div></div>
            <div class="financial-item"><div class="financial-amount"><?php echo $currencySymbol; ?> <?php echo number_format($totalDue, 2); ?></div><div class="financial-label">Total Outstanding</div></div>
            <div class="financial-item"><div class="financial-amount"><?php echo $currencySymbol; ?> <?php echo number_format($regularRevenue, 2); ?></div><div class="financial-label">Regular Price Revenue</div></div>
            <div class="financial-item"><div class="financial-amount"><?php echo $currencySymbol; ?> <?php echo number_format($loyaltyRevenue, 2); ?></div><div class="financial-label">Loyalty Price Revenue</div></div>
        </div>
    </div>

    <div class="stats-bar">
        <div class="stat-item"><div class="stat-number"><?php echo count($reservations); ?></div><div class="stat-label">Total Reservations</div></div>
        <div class="stat-item"><div class="stat-number"><?php echo $statusCounts['pending']; ?></div><div class="stat-label">⏳ Pending</div></div>
        <div class="stat-item"><div class="stat-number"><?php echo $statusCounts['registered']; ?></div><div class="stat-label">📌 Registered</div></div>
        <div class="stat-item"><div class="stat-number"><?php echo $statusCounts['paid']; ?></div><div class="stat-label">✅ Paid</div></div>
        <div class="stat-item"><div class="stat-number"><?php echo $statusCounts['cancelled']; ?></div><div class="stat-label">❌ Cancelled</div></div>
    </div>

    <div class="action-bar no-print">
        <div class="filters">
            <select id="statusFilter">
                <option value="all">All Status</option>
                <option value="pending">Pending</option>
                <option value="registered">Registered</option>
                <option value="paid">Paid</option>
                <option value="cancelled">Cancelled</option>
            </select>
            <input type="text" id="searchInput" placeholder="Search by name, ID, phone...">
            <button onclick="applyFilters()" class="btn btn-primary">Apply Filters</button>
            <button onclick="resetFilters()" class="btn btn-secondary">Reset</button>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-success">🖨️ Print Statement</button>
            <a href="dashboard.php" class="btn btn-secondary">← Back</a>
        </div>
    </div>

    <?php if ($status_filter && $status_filter != 'all'): ?>
    <div class="filter-info">📌 Filtered by: Status = <?php echo ucfirst($status_filter); ?> <?php if ($search): ?> | Search: "<?php echo htmlspecialchars($search); ?>"<?php endif; ?></div>
    <?php elseif ($search): ?>
    <div class="filter-info">📌 Filtered by: Search = "<?php echo htmlspecialchars($search); ?>"</div>
    <?php endif; ?>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Reservation ID</th>
                    <th>Customer</th>
                    <th>Phone</th>
                    <th>Table</th>
                    <th>Guests</th>
                    <th>Status</th>
                    <th>Price Tier</th>
                    <th>Total Amount</th>
                    <th>Paid</th>
                    <th>Due</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reservations)): ?>
                <tr><td colspan="10" style="text-align: center; padding: 40px;">No reservations found matching the criteria.</td></tr>
                <?php else: ?>
                    <?php foreach ($reservations as $res): 
                        $totalGuests = $res['total_guests'];
                        $guestText = $totalGuests . ' (' . $res['adults'] . 'A, ' . $res['teens'] . 'T, ' . $res['kids'] . 'K)';
                        $dueAmount = max(0, floatval($res['total_amount']) - floatval($res['total_paid']));
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($res['reservation_id']); ?></strong></td>
                        <td><?php echo htmlspecialchars($res['name']); ?></td>
                        <td><?php echo htmlspecialchars($res['phone']); ?></td>
                        <td><?php echo htmlspecialchars($res['table_id']) ?: '—'; ?></td>
                        <td><?php echo $guestText; ?></td>
                        <td><span class="status-badge status-<?php echo $res['status']; ?>"><?php echo ucfirst($res['status']); ?></span></td>
                        <td><?php echo ucfirst($res['price_tier'] ?? 'regular'); ?></td>
                        <td><?php echo $currencySymbol; ?> <?php echo number_format($res['total_amount'], 2); ?></td>
                        <td><?php echo $currencySymbol; ?> <?php echo number_format($res['total_paid'], 2); ?></td>
                        <td style="color: <?php echo $dueAmount > 0 ? '#f59e0b' : '#10b981'; ?>; font-weight: bold;"><?php echo $currencySymbol; ?> <?php echo number_format($dueAmount, 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background: #f8fafc; font-weight: bold;">
                    <td colspan="7" style="text-align: right;">Totals:</td>
                    <td><?php echo $currencySymbol; ?> <?php echo number_format($totalRevenue, 2); ?></td>
                    <td><?php echo $currencySymbol; ?> <?php echo number_format($totalPaid, 2); ?></td>
                    <td><?php echo $currencySymbol; ?> <?php echo number_format($totalDue, 2); ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="footer">
        <p>This is a system-generated statement. For any discrepancies, please contact the administrator.</p>
        <p>Page 1 of 1 | Generated by Ticketing System</p>
    </div>
</div>

<script>
function applyFilters() {
    const status = document.getElementById('statusFilter').value;
    const search = document.getElementById('searchInput').value;
    window.location.href = `print_statement.php?status=${encodeURIComponent(status)}&search=${encodeURIComponent(search)}`;
}
function resetFilters() { window.location.href = 'print_statement.php'; }
document.getElementById('searchInput')?.addEventListener('keypress', function(e) { if (e.key === 'Enter') applyFilters(); });
</script>
</body>
</html>