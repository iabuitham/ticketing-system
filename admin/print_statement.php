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
$event_name = $_SESSION['selected_event_name'] ?? 'N/A';

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
$totalGuestsCount = 0;

foreach ($reservations as $res) {
    if ($res['status'] != 'cancelled') {
        $totalRevenue += floatval($res['total_amount']);
        $totalPaid += floatval($res['total_paid']);
        $totalDue += max(0, floatval($res['total_amount']) - floatval($res['total_paid']));
        $totalGuestsCount += $res['total_guests'];
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
        /* Print Styles - Perfect Margins */
        @media print {
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                margin: 0;
                padding: 0;
                background: white;
            }
            .no-print {
                display: none !important;
            }
            .statement-container {
                margin: 0;
                padding: 15px;
                max-width: 100%;
                box-shadow: none;
                background: white;
            }
            .financial-summary {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .stats-bar {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .table-container {
                break-inside: avoid;
                overflow-x: visible;
            }
            table {
                break-inside: avoid;
                page-break-inside: avoid;
                width: 100%;
            }
            thead {
                display: table-header-group;
            }
            tfoot {
                display: table-footer-group;
            }
            tr {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            @page {
                size: A4;
                margin: 0.5in;
            }
            body {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
        
        /* Light Theme Styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .statement-container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        /* Header */
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 3px solid #4f46e5;
        }
        .header h1 {
            font-size: 28px;
            color: #1a1a2e;
            margin-bottom: 5px;
        }
        .header h1 span {
            color: #4f46e5;
        }
        .header .subtitle {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
        
        /* Financial Summary */
        .financial-summary {
            background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%);
            color: white;
            padding: 25px;
            border-radius: 16px;
            margin-bottom: 25px;
        }
        .financial-summary h3 {
            margin-bottom: 15px;
            font-size: 18px;
        }
        .financial-grid {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 20px;
        }
        .financial-item {
            text-align: center;
            flex: 1;
            min-width: 100px;
        }
        .financial-amount {
            font-size: 24px;
            font-weight: bold;
        }
        .financial-label {
            font-size: 12px;
            opacity: 0.9;
            margin-top: 5px;
        }
        
        /* Stats Bar */
        .stats-bar {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 16px;
        }
        .stat-item {
            text-align: center;
            flex: 1;
        }
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #4f46e5;
        }
        .stat-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        /* Filter Info */
        .filter-info {
            background: #e0e7ff;
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #3730a3;
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            background: #f8fafc;
            padding: 14px 12px;
            text-align: left;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
            font-size: 13px;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
        }
        tr:hover {
            background: #f8fafc;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        .status-registered {
            background: #dbeafe;
            color: #1e40af;
        }
        .status-paid {
            background: #d1fae5;
            color: #065f46;
        }
        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }
        
        /* Footer */
        .footer {
            margin-top: 30px;
            padding-top: 20px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            font-size: 11px;
            color: #94a3b8;
        }
        
        /* Buttons */
        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 5px;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #4f46e5;
            color: white;
        }
        .btn-primary:hover {
            background: #4338ca;
            transform: translateY(-1px);
        }
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        .btn-secondary:hover {
            background: #475569;
        }
        .btn-success {
            background: #10b981;
            color: white;
        }
        .btn-success:hover {
            background: #059669;
        }
        
        /* Action Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
        }
        .filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .filters select,
        .filters input {
            padding: 8px 16px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            background: white;
        }
        .filters select:focus,
        .filters input:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.1);
        }
        
        /* Tfoot styling */
        tfoot tr {
            background: #f8fafc;
            font-weight: bold;
        }
        tfoot td {
            border-top: 2px solid #e2e8f0;
        }
        
        @media (max-width: 768px) {
            .statement-container {
                padding: 15px;
            }
            .financial-grid {
                flex-direction: column;
                align-items: center;
            }
            .stats-bar {
                flex-direction: column;
                align-items: center;
            }
            .action-bar {
                flex-direction: column;
            }
            .filters {
                width: 100%;
                flex-direction: column;
            }
            .filters select,
            .filters input,
            .filters button {
                width: 100%;
            }
            th, td {
                padding: 8px 10px;
                font-size: 11px;
            }
        }
    </style>
</head>
<body>
<div class="statement-container">
    <!-- Header -->
    <div class="header">
        <h1>📋 <span>Reservation Statement</span></h1>
        <p class="subtitle">Generated on: <?php echo date('F j, Y g:i A'); ?></p>
        <p class="subtitle">Event: <?php echo htmlspecialchars($event_name); ?></p>
    </div>

    <!-- Financial Summary -->
    <div class="financial-summary">
        <h3><i class="bi bi-calculator"></i> Financial Summary</h3>
        <div class="financial-grid">
            <div class="financial-item">
                <div class="financial-amount"><?php echo $currencySymbol; ?> <?php echo number_format($totalRevenue, 2); ?></div>
                <div class="financial-label">Total Revenue (Booked)</div>
            </div>
            <div class="financial-item">
                <div class="financial-amount"><?php echo $currencySymbol; ?> <?php echo number_format($totalPaid, 2); ?></div>
                <div class="financial-label">Total Paid</div>
            </div>
            <div class="financial-item">
                <div class="financial-amount"><?php echo $currencySymbol; ?> <?php echo number_format($totalDue, 2); ?></div>
                <div class="financial-label">Total Outstanding</div>
            </div>
            <div class="financial-item">
                <div class="financial-amount"><?php echo number_format(count($reservations)); ?></div>
                <div class="financial-label">Total Reservations</div>
            </div>
            <div class="financial-item">
                <div class="financial-amount"><?php echo $totalGuestsCount; ?></div>
                <div class="financial-label">Total Guests</div>
            </div>
        </div>
    </div>

    <!-- Statistics Bar -->
    <div class="stats-bar">
        <div class="stat-item">
            <div class="stat-number"><?php echo $statusCounts['pending']; ?></div>
            <div class="stat-label">⏳ Pending</div>
        </div>
        <div class="stat-item">
            <div class="stat-number"><?php echo $statusCounts['registered']; ?></div>
            <div class="stat-label">📌 Registered</div>
        </div>
        <div class="stat-item">
            <div class="stat-number"><?php echo $statusCounts['paid']; ?></div>
            <div class="stat-label">✅ Paid</div>
        </div>
        <div class="stat-item">
            <div class="stat-number"><?php echo $statusCounts['cancelled']; ?></div>
            <div class="stat-label">❌ Cancelled</div>
        </div>
    </div>

    <!-- Action Bar (Screen only) -->
    <div class="action-bar no-print">
        <div class="filters">
            <select id="statusFilter">
                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="registered" <?php echo $status_filter == 'registered' ? 'selected' : ''; ?>>Registered</option>
                <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>>Paid</option>
                <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
            <input type="text" id="searchInput" placeholder="Search by name, ID, phone..." value="<?php echo htmlspecialchars($search); ?>">
            <button onclick="applyFilters()" class="btn btn-primary">Apply Filters</button>
            <button onclick="resetFilters()" class="btn btn-secondary">Reset</button>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-success">🖨️ Print Statement</button>
            <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
        </div>
    </div>

    <!-- Filter Info -->
    <?php if ($status_filter && $status_filter != 'all'): ?>
    <div class="filter-info">
        📌 Filtered by: Status = <?php echo ucfirst($status_filter); ?>
        <?php if ($search): ?> | Search: "<?php echo htmlspecialchars($search); ?>"<?php endif; ?>
    </div>
    <?php elseif ($search): ?>
    <div class="filter-info">
        📌 Filtered by: Search = "<?php echo htmlspecialchars($search); ?>"
    </div>
    <?php endif; ?>

    <!-- Reservations Table -->
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
                    <th>Total Amount</th>
                    <th>Paid</th>
                    <th>Due</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reservations)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; padding: 60px;">
                        <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.5;"></i>
                        <p style="margin-top: 15px;">No reservations found matching the criteria.</p>
                    </d>
                </tr>
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
                        <td><?php echo $guestText; ?></d>
                        <td><span class="status-badge status-<?php echo $res['status']; ?>"><?php echo ucfirst($res['status']); ?></span></td>
                        <td><?php echo $currencySymbol; ?> <?php echo number_format($res['total_amount'], 2); ?></d>
                        <td><?php echo $currencySymbol; ?> <?php echo number_format($res['total_paid'], 2); ?></d>
                        <td style="color: <?php echo $dueAmount > 0 ? '#f59e0b' : '#10b981'; ?>; font-weight: bold;">
                            <?php echo $currencySymbol; ?> <?php echo number_format($dueAmount, 2); ?>
                        </d>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="6" style="text-align: right;"><strong>Totals:</strong></td>
                    <td><strong><?php echo $currencySymbol; ?> <?php echo number_format($totalRevenue, 2); ?></strong></d>
                    <td><strong><?php echo $currencySymbol; ?> <?php echo number_format($totalPaid, 2); ?></strong></d>
                    <td><strong><?php echo $currencySymbol; ?> <?php echo number_format($totalDue, 2); ?></strong></d>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Footer -->
    <div class="footer">
        <p>This is a system-generated statement. For any discrepancies, please contact the administrator.</p>
        <p>Generated by Ticketing System | <?php echo date('Y-m-d H:i:s'); ?></p>
    </div>
</div>

<script>
    function applyFilters() {
        const status = document.getElementById('statusFilter').value;
        const search = document.getElementById('searchInput').value;
        window.location.href = `print_statement.php?status=${encodeURIComponent(status)}&search=${encodeURIComponent(search)}`;
    }
    
    function resetFilters() {
        window.location.href = 'print_statement.php';
    }
    
    document.getElementById('searchInput')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') applyFilters();
    });
</script>
</body>
</html>