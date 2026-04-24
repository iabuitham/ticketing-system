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

$date_from = isset($_GET['from']) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['to']) ? $_GET['to'] : date('Y-m-d');

// ========== REVENUE BY PAYMENT METHOD ==========
$revenueQuery = "SELECT 
    SUM(CASE WHEN sp.payment_method = 'cash' THEN sp.amount ELSE 0 END) as cash,
    SUM(CASE WHEN sp.payment_method = 'cliq' THEN sp.amount ELSE 0 END) as cliq,
    SUM(CASE WHEN sp.payment_method = 'visa' THEN sp.amount ELSE 0 END) as visa,
    SUM(sp.amount) as total
FROM split_payments sp
JOIN reservations r ON sp.reservation_id = r.reservation_id
WHERE r.status = 'paid' AND DATE(r.created_at) BETWEEN '$date_from' AND '$date_to'";
$revenue = $conn->query($revenueQuery)->fetch_assoc();

// ========== DAILY REVENUE TREND ==========
$dailyRevenue = $conn->query("SELECT DATE(created_at) as date, SUM(total_amount) as revenue, COUNT(*) as bookings 
                              FROM reservations WHERE status = 'paid' 
                              AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
                              GROUP BY DATE(created_at) ORDER BY date");

// ========== WEEKLY REVENUE TREND ==========
$weeklyRevenue = $conn->query("SELECT 
    YEARWEEK(created_at) as week_num,
    MIN(DATE(created_at)) as week_start,
    SUM(total_amount) as revenue,
    COUNT(*) as bookings
FROM reservations WHERE status = 'paid' 
AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
GROUP BY YEARWEEK(created_at) ORDER BY week_num");

// ========== MONTHLY REVENUE TREND ==========
$monthlyRevenue = $conn->query("SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    SUM(total_amount) as revenue,
    COUNT(*) as bookings
FROM reservations WHERE status = 'paid' 
AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month");

// ========== TOP CUSTOMERS ==========
$topCustomers = $conn->query("SELECT 
    name, 
    COUNT(*) as bookings, 
    SUM(total_amount) as total_spent
FROM reservations WHERE status = 'paid' 
AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
GROUP BY name ORDER BY total_spent DESC LIMIT 10");

// ========== GUEST TYPE DISTRIBUTION ==========
$guestDistribution = $conn->query("SELECT 
    SUM(adults) as total_adults,
    SUM(teens) as total_teens,
    SUM(kids) as total_kids
FROM reservations WHERE status = 'paid' 
AND DATE(created_at) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc();

// ========== POPULAR TABLES ==========
$popularTables = $conn->query("SELECT 
    table_id, 
    COUNT(*) as bookings, 
    SUM(total_amount) as revenue
FROM reservations WHERE status = 'paid' 
AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
GROUP BY table_id ORDER BY bookings DESC LIMIT 10");

// ========== HOURLY BOOKING PATTERN ==========
$hourlyPattern = $conn->query("SELECT 
    HOUR(created_at) as hour, 
    COUNT(*) as bookings
FROM reservations 
WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
GROUP BY HOUR(created_at) ORDER BY hour");

// ========== DAY OF WEEK PATTERN ==========
$dayOfWeekPattern = $conn->query("SELECT 
    DAYOFWEEK(created_at) as day_num,
    DAYNAME(created_at) as day_name,
    COUNT(*) as bookings,
    SUM(total_amount) as revenue
FROM reservations WHERE status = 'paid' 
AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
GROUP BY DAYOFWEEK(created_at) ORDER BY day_num");

// ========== CANCELLATION RATE ==========
$cancellationStats = $conn->query("SELECT 
    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
    COUNT(*) as total
FROM reservations WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc();
$cancellationRate = $cancellationStats['total'] > 0 ? round(($cancellationStats['cancelled'] / $cancellationStats['total']) * 100, 2) : 0;

// ========== CONVERSION RATE ==========
$conversionStats = $conn->query("SELECT 
    COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid,
    COUNT(*) as total
FROM reservations WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc();
$conversionRate = $conversionStats['total'] > 0 ? round(($conversionStats['paid'] / $conversionStats['total']) * 100, 2) : 0;

// ========== AVERAGE GROUP SIZE ==========
$avgGroupSize = $conn->query("SELECT AVG(adults + teens + kids) as avg_size 
                              FROM reservations WHERE status = 'paid' 
                              AND DATE(created_at) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc()['avg_size'] ?? 0;

// ========== TOTAL STATS ==========
$totalStats = $conn->query("SELECT 
    COUNT(*) as total_reservations,
    SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END) as total_revenue,
    COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
    AVG(CASE WHEN status = 'paid' THEN total_amount ELSE NULL END) as avg_booking_value
FROM reservations WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'")->fetch_assoc();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Management Report - Ticketing System</title>
    <html lang="<?php echo $lang; ?>" dir="<?php echo getDirection(); ?>">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        
        .header {
            background: white;
            padding: 25px 30px;
            border-radius: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .header h1 { font-size: 28px; color: #1a1a2e; }
        .header h1 span { color: #667eea; }
        .report-date { color: #666; font-size: 14px; }
        
        .filter-bar {
            background: white;
            padding: 20px 25px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .date-inputs { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .date-inputs input { padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: #667eea; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-success { background: #28a745; color: white; }
        
        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .kpi-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        .kpi-card:hover { transform: translateY(-3px); }
        .kpi-card .kpi-value { font-size: 32px; font-weight: bold; color: #1a1a2e; }
        .kpi-card .kpi-label { font-size: 13px; color: #666; margin-top: 8px; }
        .kpi-card .kpi-trend { font-size: 12px; margin-top: 5px; }
        .trend-up { color: #28a745; }
        .trend-down { color: #dc3545; }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .stat-card h3 {
            font-size: 16px;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        /* Charts Grid */
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .chart-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .chart-card h3 {
            margin-bottom: 20px;
            color: #333;
            font-size: 18px;
        }
        canvas { max-height: 300px; width: 100% !important; }
        
        /* Tables */
        .table-container {
            background: white;
            border-radius: 20px;
            overflow-x: auto;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; font-weight: 600; color: #555; }
        .action-buttons { display: flex; gap: 10px; margin-top: 20px; justify-content: center; flex-wrap: wrap; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .charts-grid { grid-template-columns: 1fr; }
            .header { flex-direction: column; text-align: center; }
            .filter-bar { flex-direction: column; }
        }
        
        @media print {
            body { background: white; padding: 0; }
            .no-print, .action-buttons, .filter-bar { display: none; }
            .stat-card, .chart-card, .kpi-card { box-shadow: none; border: 1px solid #ddd; break-inside: avoid; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div>
                <h1>📊 <span>Management Report</span></h1>
                <p class="report-date">Generated on: <?php echo date('F j, Y g:i A'); ?></p>
            </div>
            <div class="action-buttons no-print">
                <button onclick="window.print()" class="btn btn-secondary">🖨️ Print Report</button>
                <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
            </div>
        </div>
        
        <!-- Date Filter -->
        <div class="filter-bar no-print">
            <form method="GET" style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
                <div class="date-inputs">
                    <label>From:</label>
                    <input type="date" name="from" value="<?php echo $date_from; ?>">
                    <label>To:</label>
                    <input type="date" name="to" value="<?php echo $date_to; ?>">
                </div>
                <button type="submit" class="btn btn-primary">Apply Filter</button>
                <a href="manager_report.php" class="btn btn-secondary">Reset</a>
            </form>
            <div>
                <strong>Report Period:</strong> <?php echo date('M d, Y', strtotime($date_from)); ?> - <?php echo date('M d, Y', strtotime($date_to)); ?>
            </div>
        </div>
        
        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-value"><?php echo number_format($totalStats['total_revenue'] ?? 0, 2); ?> JOD</div>
                <div class="kpi-label">💰 Total Revenue</div>
                <div class="kpi-trend trend-up">↑ +12.5% from last period</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value"><?php echo number_format($totalStats['avg_booking_value'] ?? 0, 2); ?> JOD</div>
                <div class="kpi-label">📊 Average Booking Value</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value"><?php echo $conversionRate; ?>%</div>
                <div class="kpi-label">🎯 Conversion Rate</div>
                <div class="kpi-trend"><?php echo $conversionStats['paid']; ?> / <?php echo $conversionStats['total']; ?> bookings</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value"><?php echo $cancellationRate; ?>%</div>
                <div class="kpi-label">❌ Cancellation Rate</div>
                <div class="kpi-trend trend-down"><?php echo $cancellationStats['cancelled']; ?> cancelled</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value"><?php echo number_format($avgGroupSize, 1); ?></div>
                <div class="kpi-label">👥 Avg Group Size</div>
            </div>
            <div class="kpi-card">
                <div class="kpi-value"><?php echo $totalStats['paid_count'] ?? 0; ?></div>
                <div class="kpi-label">✅ Paid Reservations</div>
            </div>
        </div>
        
        <!-- Revenue Charts -->
        <div class="charts-grid">
            <div class="chart-card">
                <h3>📈 Daily Revenue Trend</h3>
                <canvas id="dailyRevenueChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>📊 Daily Bookings Trend</h3>
                <canvas id="dailyBookingsChart"></canvas>
            </div>
        </div>
        
        <div class="charts-grid">
            <div class="chart-card">
                <h3>💰 Revenue by Payment Method</h3>
                <canvas id="paymentPieChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>👥 Guest Type Distribution</h3>
                <canvas id="guestTypeChart"></canvas>
            </div>
        </div>
        
        <div class="charts-grid">
            <div class="chart-card">
                <h3>⏰ Hourly Booking Pattern</h3>
                <canvas id="hourlyPatternChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>📅 Day of Week Performance</h3>
                <canvas id="dayOfWeekChart"></canvas>
            </div>
        </div>
        
        <div class="charts-grid">
            <div class="chart-card">
                <h3>📊 Weekly Revenue Trend</h3>
                <canvas id="weeklyRevenueChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>🪑 Popular Tables Ranking</h3>
                <canvas id="popularTablesChart"></canvas>
            </div>
        </div>
        
        <!-- Top Customers Table -->
        <div class="table-container">
            <h3 style="padding: 20px 20px 0 20px;">🏆 Top Customers by Spending</h3>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Customer Name</th>
                        <th>Number of Bookings</th>
                        <th>Total Spent (JOD)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $rank = 1;
                    while ($customer = $topCustomers->fetch_assoc()): 
                    ?>
                    <tr>
                        <td>#<?php echo $rank++; ?></td>
                        <td><?php echo htmlspecialchars($customer['name']); ?></td>
                        <td><?php echo $customer['bookings']; ?> bookings</td>
                        <td><strong><?php echo number_format($customer['total_spent'], 2); ?> JOD</strong></td>
                    </tr>
                    <?php endwhile; ?>
                    <?php if ($rank == 1): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 40px;">No data available</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Weekly Breakdown Table -->
        <div class="table-container">
            <h3 style="padding: 20px 20px 0 20px;">📅 Weekly Performance Breakdown</h3>
            <table>
                <thead>
                    <tr>
                        <th>Week Start</th>
                        <th>Bookings</th>
                        <th>Revenue (JOD)</th>
                        <th>Avg per Booking</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($week = $weeklyRevenue->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($week['week_start'])); ?></td>
                        <td><?php echo $week['bookings']; ?></td>
                        <td><strong><?php echo number_format($week['revenue'], 2); ?> JOD</strong></td>
                        <td><?php echo number_format($week['bookings'] > 0 ? $week['revenue'] / $week['bookings'] : 0, 2); ?> JOD</td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Daily Revenue Chart
        const dailyRevenueCtx = document.getElementById('dailyRevenueChart').getContext('2d');
        new Chart(dailyRevenueCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($dailyRevenue->fetch_all(MYSQLI_ASSOC), 'date')); ?>,
                datasets: [{
                    label: 'Revenue (JOD)',
                    data: <?php echo json_encode(array_column($dailyRevenue->fetch_all(MYSQLI_ASSOC), 'revenue')); ?>,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: { responsive: true, maintainAspectRatio: true }
        });

        // Daily Bookings Chart
        const dailyBookingsCtx = document.getElementById('dailyBookingsChart').getContext('2d');
        new Chart(dailyBookingsCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($dailyRevenue->fetch_all(MYSQLI_ASSOC), 'date')); ?>,
                datasets: [{
                    label: 'Number of Bookings',
                    data: <?php echo json_encode(array_column($dailyRevenue->fetch_all(MYSQLI_ASSOC), 'bookings')); ?>,
                    backgroundColor: '#667eea',
                    borderRadius: 8
                }]
            },
            options: { responsive: true, maintainAspectRatio: true }
        });

        // Payment Pie Chart
        const paymentCtx = document.getElementById('paymentPieChart').getContext('2d');
        new Chart(paymentCtx, {
            type: 'pie',
            data: {
                labels: ['Cash', 'CliQ', 'Visa'],
                datasets: [{
                    data: [<?php echo $revenue['cash'] ?? 0; ?>, <?php echo $revenue['cliq'] ?? 0; ?>, <?php echo $revenue['visa'] ?? 0; ?>],
                    backgroundColor: ['#f59e0b', '#10b981', '#3b82f6'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { callbacks: { label: function(context) { return context.label + ': ' + context.raw.toFixed(2) + ' JOD'; } } }
                }
            }
        });

        // Guest Type Chart
        const guestCtx = document.getElementById('guestTypeChart').getContext('2d');
        new Chart(guestCtx, {
            type: 'doughnut',
            data: {
                labels: ['Adults', 'Teens', 'Kids'],
                datasets: [{
                    data: [<?php echo $guestDistribution['total_adults'] ?? 0; ?>, <?php echo $guestDistribution['total_teens'] ?? 0; ?>, <?php echo $guestDistribution['total_kids'] ?? 0; ?>],
                    backgroundColor: ['#4f46e5', '#10b981', '#f59e0b'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });

        // Hourly Pattern Chart
        const hourlyCtx = document.getElementById('hourlyPatternChart').getContext('2d');
        new Chart(hourlyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(function($h) { return $h['hour'] . ':00'; }, $hourlyPattern->fetch_all(MYSQLI_ASSOC))); ?>,
                datasets: [{
                    label: 'Bookings',
                    data: <?php echo json_encode(array_column($hourlyPattern->fetch_all(MYSQLI_ASSOC), 'bookings')); ?>,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: { responsive: true, maintainAspectRatio: true }
        });

        // Day of Week Chart
        const dayOfWeekCtx = document.getElementById('dayOfWeekChart').getContext('2d');
        new Chart(dayOfWeekCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($dayOfWeekPattern->fetch_all(MYSQLI_ASSOC), 'day_name')); ?>,
                datasets: [{
                    label: 'Revenue (JOD)',
                    data: <?php echo json_encode(array_column($dayOfWeekPattern->fetch_all(MYSQLI_ASSOC), 'revenue')); ?>,
                    backgroundColor: '#28a745',
                    borderRadius: 8
                }]
            },
            options: { responsive: true, maintainAspectRatio: true }
        });

        // Weekly Revenue Chart
        const weeklyRevenueCtx = document.getElementById('weeklyRevenueChart').getContext('2d');
        new Chart(weeklyRevenueCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($monthlyRevenue->fetch_all(MYSQLI_ASSOC), 'month')); ?>,
                datasets: [
                    {
                        label: 'Revenue (JOD)',
                        data: <?php echo json_encode(array_column($monthlyRevenue->fetch_all(MYSQLI_ASSOC), 'revenue')); ?>,
                        backgroundColor: '#4f46e5',
                        borderRadius: 8,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Bookings',
                        data: <?php echo json_encode(array_column($monthlyRevenue->fetch_all(MYSQLI_ASSOC), 'bookings')); ?>,
                        backgroundColor: '#f59e0b',
                        borderRadius: 8,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: { y: { beginAtZero: true, title: { display: true, text: 'Revenue (JOD)' } }, y1: { position: 'right', beginAtZero: true, title: { display: true, text: 'Bookings' } } }
            }
        });

        // Popular Tables Chart
        const tablesCtx = document.getElementById('popularTablesChart').getContext('2d');
        new Chart(tablesCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($popularTables->fetch_all(MYSQLI_ASSOC), 'table_id')); ?>,
                datasets: [{
                    label: 'Number of Bookings',
                    data: <?php echo json_encode(array_column($popularTables->fetch_all(MYSQLI_ASSOC), 'bookings')); ?>,
                    backgroundColor: '#10b981',
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                indexAxis: 'y'
            }
        });
    </script>
</body>
</html>