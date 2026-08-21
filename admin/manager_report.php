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

// Sanitize date inputs
$date_from = isset($_GET['from']) ? mysqli_real_escape_string($conn, $_GET['from']) : date('Y-m-d', strtotime('-30 days'));
$date_to = isset($_GET['to']) ? mysqli_real_escape_string($conn, $_GET['to']) : date('Y-m-d');

// ============================================
// AUTO-INCLUDE ARCHIVED DATA BASED ON DATE
// Archived data is automatically included if it falls within the date range
// ============================================

// 1. Daily Revenue & Bookings (Active + Archived by date)
$dailyData = [];

// Active reservations
$dailyActive = $conn->query("
    SELECT DATE(created_at) as date, SUM(total_amount) as revenue, COUNT(*) as bookings 
    FROM reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY DATE(created_at)
");

while ($row = $dailyActive->fetch_assoc()) {
    $dailyData[$row['date']] = [
        'date' => $row['date'],
        'revenue' => floatval($row['revenue']),
        'bookings' => intval($row['bookings'])
    ];
}

// Archived reservations (automatically included if date matches)
$dailyArchived = $conn->query("
    SELECT DATE(created_at) as date, SUM(total_amount) as revenue, COUNT(*) as bookings 
    FROM archived_reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY DATE(created_at)
");

while ($row = $dailyArchived->fetch_assoc()) {
    if (isset($dailyData[$row['date']])) {
        $dailyData[$row['date']]['revenue'] += floatval($row['revenue']);
        $dailyData[$row['date']]['bookings'] += intval($row['bookings']);
    } else {
        $dailyData[$row['date']] = [
            'date' => $row['date'],
            'revenue' => floatval($row['revenue']),
            'bookings' => intval($row['bookings'])
        ];
    }
}

ksort($dailyData);
$dailyData = array_values($dailyData);

// 2. Weekly Revenue (Active + Archived by date)
$weeklyData = [];

// Active
$weeklyActive = $conn->query("
    SELECT 
        YEARWEEK(created_at) as week_num,
        MIN(DATE(created_at)) as week_start,
        SUM(total_amount) as revenue,
        COUNT(*) as bookings
    FROM reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY YEARWEEK(created_at)
");

while ($row = $weeklyActive->fetch_assoc()) {
    $weeklyData[$row['week_num']] = [
        'week_num' => $row['week_num'],
        'week_start' => $row['week_start'],
        'revenue' => floatval($row['revenue']),
        'bookings' => intval($row['bookings'])
    ];
}

// Archived
$weeklyArchived = $conn->query("
    SELECT 
        YEARWEEK(created_at) as week_num,
        MIN(DATE(created_at)) as week_start,
        SUM(total_amount) as revenue,
        COUNT(*) as bookings
    FROM archived_reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY YEARWEEK(created_at)
");

while ($row = $weeklyArchived->fetch_assoc()) {
    if (isset($weeklyData[$row['week_num']])) {
        $weeklyData[$row['week_num']]['revenue'] += floatval($row['revenue']);
        $weeklyData[$row['week_num']]['bookings'] += intval($row['bookings']);
    } else {
        $weeklyData[$row['week_num']] = [
            'week_num' => $row['week_num'],
            'week_start' => $row['week_start'],
            'revenue' => floatval($row['revenue']),
            'bookings' => intval($row['bookings'])
        ];
    }
}

ksort($weeklyData);
$weeklyData = array_values($weeklyData);

// 3. Monthly Revenue (Active + Archived by date)
$monthlyData = [];

// Active
$monthlyActive = $conn->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        SUM(total_amount) as revenue,
        COUNT(*) as bookings
    FROM reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
");

while ($row = $monthlyActive->fetch_assoc()) {
    $monthlyData[$row['month']] = [
        'month' => $row['month'],
        'revenue' => floatval($row['revenue']),
        'bookings' => intval($row['bookings'])
    ];
}

// Archived
$monthlyArchived = $conn->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        SUM(total_amount) as revenue,
        COUNT(*) as bookings
    FROM archived_reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
");

while ($row = $monthlyArchived->fetch_assoc()) {
    if (isset($monthlyData[$row['month']])) {
        $monthlyData[$row['month']]['revenue'] += floatval($row['revenue']);
        $monthlyData[$row['month']]['bookings'] += intval($row['bookings']);
    } else {
        $monthlyData[$row['month']] = [
            'month' => $row['month'],
            'revenue' => floatval($row['revenue']),
            'bookings' => intval($row['bookings'])
        ];
    }
}

ksort($monthlyData);
$monthlyData = array_values($monthlyData);

// 4. Revenue by Payment Method (Active + Archived by date)
$cash = 0;
$cliq = 0;
$visa = 0;
$total_payments = 0;

// Active payments
$paymentsActive = $conn->query("
    SELECT 
        SUM(CASE WHEN sp.payment_method = 'cash' THEN sp.amount ELSE 0 END) as cash,
        SUM(CASE WHEN sp.payment_method = 'cliq' THEN sp.amount ELSE 0 END) as cliq,
        SUM(CASE WHEN sp.payment_method = 'visa' THEN sp.amount ELSE 0 END) as visa,
        SUM(sp.amount) as total
    FROM split_payments sp
    JOIN reservations r ON sp.reservation_id = r.reservation_id
    WHERE r.status = 'paid' AND DATE(r.created_at) BETWEEN '$date_from' AND '$date_to'
");

$activePay = $paymentsActive->fetch_assoc();
$cash += floatval($activePay['cash']);
$cliq += floatval($activePay['cliq']);
$visa += floatval($activePay['visa']);
$total_payments += floatval($activePay['total']);

// Archived payments (automatically included if date matches)
$paymentsArchived = $conn->query("
    SELECT 
        SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash,
        SUM(CASE WHEN payment_method = 'cliq' THEN amount ELSE 0 END) as cliq,
        SUM(CASE WHEN payment_method = 'visa' THEN amount ELSE 0 END) as visa,
        SUM(amount) as total
    FROM archived_split_payments asp
    JOIN archived_reservations ar ON asp.archived_reservation_id = ar.reservation_id
    WHERE ar.status = 'paid' AND DATE(ar.created_at) BETWEEN '$date_from' AND '$date_to'
");

$archivedPay = $paymentsArchived->fetch_assoc();
$cash += floatval($archivedPay['cash']);
$cliq += floatval($archivedPay['cliq']);
$visa += floatval($archivedPay['visa']);
$total_payments += floatval($archivedPay['total']);

$revenue = [
    'cash' => $cash,
    'cliq' => $cliq,
    'visa' => $visa,
    'total' => $total_payments
];

// 5. Top Customers (Active + Archived by date)
$topCustomers = [];

// Active customers
$topActive = $conn->query("
    SELECT 
        name, 
        COUNT(*) as bookings, 
        SUM(total_amount) as total_spent
    FROM reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY name ORDER BY total_spent DESC LIMIT 10
");

while ($row = $topActive->fetch_assoc()) {
    $topCustomers[$row['name']] = [
        'name' => $row['name'],
        'bookings' => intval($row['bookings']),
        'total_spent' => floatval($row['total_spent'])
    ];
}

// Archived customers (automatically included if date matches)
$topArchived = $conn->query("
    SELECT 
        name, 
        COUNT(*) as bookings, 
        SUM(total_amount) as total_spent
    FROM archived_reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY name ORDER BY total_spent DESC LIMIT 10
");

while ($row = $topArchived->fetch_assoc()) {
    if (isset($topCustomers[$row['name']])) {
        $topCustomers[$row['name']]['bookings'] += intval($row['bookings']);
        $topCustomers[$row['name']]['total_spent'] += floatval($row['total_spent']);
    } else {
        $topCustomers[$row['name']] = [
            'name' => $row['name'],
            'bookings' => intval($row['bookings']),
            'total_spent' => floatval($row['total_spent'])
        ];
    }
}

// Sort by total_spent descending and take top 10
uasort($topCustomers, function($a, $b) {
    return $b['total_spent'] <=> $a['total_spent'];
});
$topCustomers = array_slice($topCustomers, 0, 10);
$topCustomers = array_values($topCustomers);

// 6. Guest Distribution (Active + Archived by date)
$total_adults = 0;
$total_teens = 0;
$total_kids = 0;

// Active
$guestActive = $conn->query("
    SELECT 
        SUM(adults) as adults,
        SUM(teens) as teens,
        SUM(kids) as kids
    FROM reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to'
");

$activeGuest = $guestActive->fetch_assoc();
$total_adults += intval($activeGuest['adults']);
$total_teens += intval($activeGuest['teens']);
$total_kids += intval($activeGuest['kids']);

// Archived
$guestArchived = $conn->query("
    SELECT 
        SUM(adults) as adults,
        SUM(teens) as teens,
        SUM(kids) as kids
    FROM archived_reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to'
");

$archivedGuest = $guestArchived->fetch_assoc();
$total_adults += intval($archivedGuest['adults']);
$total_teens += intval($archivedGuest['teens']);
$total_kids += intval($archivedGuest['kids']);

$guestDistribution = [
    'total_adults' => $total_adults,
    'total_teens' => $total_teens,
    'total_kids' => $total_kids
];

// 7. Popular Tables (Active only - archived tables may not be relevant)
$popularTables = [];
$tablesResult = $conn->query("
    SELECT 
        table_id, 
        COUNT(*) as bookings, 
        SUM(total_amount) as revenue
    FROM reservations WHERE status = 'paid' 
    AND table_id IS NOT NULL AND table_id != ''
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY table_id ORDER BY bookings DESC LIMIT 10
");
while ($row = $tablesResult->fetch_assoc()) {
    $popularTables[] = $row;
}

// 8. Hourly Pattern (Active + Archived by date)
$hourlyData = [];

// Active
$hourlyActive = $conn->query("
    SELECT HOUR(created_at) as hour, COUNT(*) as bookings
    FROM reservations 
    WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY HOUR(created_at)
");

while ($row = $hourlyActive->fetch_assoc()) {
    $hourlyData[$row['hour']] = intval($row['bookings']);
}

// Archived
$hourlyArchived = $conn->query("
    SELECT HOUR(created_at) as hour, COUNT(*) as bookings
    FROM archived_reservations 
    WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY HOUR(created_at)
");

while ($row = $hourlyArchived->fetch_assoc()) {
    $hourlyData[$row['hour']] = ($hourlyData[$row['hour']] ?? 0) + intval($row['bookings']);
}

ksort($hourlyData);
$hourlyFormatted = [];
foreach ($hourlyData as $hour => $bookings) {
    $hourlyFormatted[] = ['hour' => $hour, 'bookings' => $bookings];
}
$hourlyData = $hourlyFormatted;

// 9. Day of Week Pattern (Active + Archived by date)
$dayOfWeekData = [];

// Active
$dowActive = $conn->query("
    SELECT 
        DAYOFWEEK(created_at) as day_num,
        DAYNAME(created_at) as day_name,
        COUNT(*) as bookings,
        SUM(total_amount) as revenue
    FROM reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY DAYOFWEEK(created_at)
");

while ($row = $dowActive->fetch_assoc()) {
    $dayOfWeekData[$row['day_num']] = [
        'day_num' => $row['day_num'],
        'day_name' => $row['day_name'],
        'bookings' => intval($row['bookings']),
        'revenue' => floatval($row['revenue'])
    ];
}

// Archived
$dowArchived = $conn->query("
    SELECT 
        DAYOFWEEK(created_at) as day_num,
        DAYNAME(created_at) as day_name,
        COUNT(*) as bookings,
        SUM(total_amount) as revenue
    FROM archived_reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to' 
    GROUP BY DAYOFWEEK(created_at)
");

while ($row = $dowArchived->fetch_assoc()) {
    if (isset($dayOfWeekData[$row['day_num']])) {
        $dayOfWeekData[$row['day_num']]['bookings'] += intval($row['bookings']);
        $dayOfWeekData[$row['day_num']]['revenue'] += floatval($row['revenue']);
    } else {
        $dayOfWeekData[$row['day_num']] = [
            'day_num' => $row['day_num'],
            'day_name' => $row['day_name'],
            'bookings' => intval($row['bookings']),
            'revenue' => floatval($row['revenue'])
        ];
    }
}

ksort($dayOfWeekData);
$dayOfWeekData = array_values($dayOfWeekData);

// 10. Cancellation Rate (Active + Archived by date)
$cancelled_total = 0;
$total_reservations = 0;

// Active
$cancelActive = $conn->query("
    SELECT 
        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
        COUNT(*) as total
    FROM reservations WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
");
$activeCancel = $cancelActive->fetch_assoc();
$cancelled_total += intval($activeCancel['cancelled']);
$total_reservations += intval($activeCancel['total']);

// Archived
$cancelArchived = $conn->query("
    SELECT 
        COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled,
        COUNT(*) as total
    FROM archived_reservations WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
");
$archivedCancel = $cancelArchived->fetch_assoc();
$cancelled_total += intval($archivedCancel['cancelled']);
$total_reservations += intval($archivedCancel['total']);

$cancellationRate = $total_reservations > 0 ? round(($cancelled_total / $total_reservations) * 100, 2) : 0;
$cancellationStats = ['cancelled' => $cancelled_total, 'total' => $total_reservations];

// 11. Conversion Rate (Active + Archived by date)
$paid_total = 0;
$total_reservations_conv = 0;

// Active
$convActive = $conn->query("
    SELECT 
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid,
        COUNT(*) as total
    FROM reservations WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
");
$activeConv = $convActive->fetch_assoc();
$paid_total += intval($activeConv['paid']);
$total_reservations_conv += intval($activeConv['total']);

// Archived
$convArchived = $conn->query("
    SELECT 
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid,
        COUNT(*) as total
    FROM archived_reservations WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
");
$archivedConv = $convArchived->fetch_assoc();
$paid_total += intval($archivedConv['paid']);
$total_reservations_conv += intval($archivedConv['total']);

$conversionRate = $total_reservations_conv > 0 ? round(($paid_total / $total_reservations_conv) * 100, 2) : 0;
$conversionStats = ['paid' => $paid_total, 'total' => $total_reservations_conv];

// 12. Average Group Size (Active + Archived by date)
$total_guests = 0;
$paid_reservations_count = 0;

// Active
$avgActive = $conn->query("
    SELECT SUM(adults + teens + kids) as total_guests, COUNT(*) as count
    FROM reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to'
");
$activeAvg = $avgActive->fetch_assoc();
$total_guests += intval($activeAvg['total_guests']);
$paid_reservations_count += intval($activeAvg['count']);

// Archived
$avgArchived = $conn->query("
    SELECT SUM(adults + teens + kids) as total_guests, COUNT(*) as count
    FROM archived_reservations WHERE status = 'paid' 
    AND DATE(created_at) BETWEEN '$date_from' AND '$date_to'
");
$archivedAvg = $avgArchived->fetch_assoc();
$total_guests += intval($archivedAvg['total_guests']);
$paid_reservations_count += intval($archivedAvg['count']);

$avgGroupSize = $paid_reservations_count > 0 ? round($total_guests / $paid_reservations_count, 1) : 0;

// 13. Total Stats (Active + Archived by date)
$total_rev = 0;
$paid_count = 0;
$total_res = 0;
$total_amount_sum = 0;

// Active
$totalActive = $conn->query("
    SELECT 
        COUNT(*) as total_reservations,
        SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END) as total_revenue,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
        AVG(CASE WHEN status = 'paid' THEN total_amount ELSE NULL END) as avg_booking_value
    FROM reservations WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
");
$activeTotal = $totalActive->fetch_assoc();
$total_res += intval($activeTotal['total_reservations']);
$total_rev += floatval($activeTotal['total_revenue']);
$paid_count += intval($activeTotal['paid_count']);

// Archived
$totalArchived = $conn->query("
    SELECT 
        COUNT(*) as total_reservations,
        SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END) as total_revenue,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
        AVG(CASE WHEN status = 'paid' THEN total_amount ELSE NULL END) as avg_booking_value
    FROM archived_reservations WHERE DATE(created_at) BETWEEN '$date_from' AND '$date_to'
");
$archivedTotal = $totalArchived->fetch_assoc();
$total_res += intval($archivedTotal['total_reservations']);
$total_rev += floatval($archivedTotal['total_revenue']);
$paid_count += intval($archivedTotal['paid_count']);

$avg_booking = $paid_count > 0 ? ($total_rev / $paid_count) : 0;

$totalStats = [
    'total_reservations' => $total_res,
    'total_revenue' => $total_rev,
    'paid_count' => $paid_count,
    'avg_booking_value' => $avg_booking
];

$conn->close();

// Prepare data for JSON encoding
$dailyDates = json_encode(array_column($dailyData, 'date'));
$dailyRevenue = json_encode(array_column($dailyData, 'revenue'));
$dailyBookings = json_encode(array_column($dailyData, 'bookings'));

$weeklyLabels = json_encode(array_column($weeklyData, 'week_start'));
$weeklyRevenueData = json_encode(array_column($weeklyData, 'revenue'));

$monthlyLabels = json_encode(array_column($monthlyData, 'month'));
$monthlyRevenueData = json_encode(array_column($monthlyData, 'revenue'));
$monthlyBookingsData = json_encode(array_column($monthlyData, 'bookings'));

$hourlyLabels = json_encode(array_map(function($h) { return $h['hour'] . ':00'; }, $hourlyData));
$hourlyBookings = json_encode(array_column($hourlyData, 'bookings'));

$dayNames = json_encode(array_column($dayOfWeekData, 'day_name'));
$dayRevenue = json_encode(array_column($dayOfWeekData, 'revenue'));

$tableLabels = json_encode(array_column($popularTables, 'table_id'));
$tableBookings = json_encode(array_column($popularTables, 'bookings'));
?>
<!DOCTYPE html>
<html lang="<?php echo getCurrentLanguage(); ?>" dir="<?php echo getDirection(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Management Report - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        body.dark-mode {
            background: #0f172a;
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
        body.dark-mode .header {
            background: #1e293b;
            color: #e2e8f0;
        }
        .header h1 { font-size: 28px; color: #1a1a2e; }
        body.dark-mode .header h1 { color: #e2e8f0; }
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
        body.dark-mode .filter-bar {
            background: #1e293b;
        }
        .date-inputs { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .date-inputs input { padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; }
        body.dark-mode .date-inputs input {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }
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
        body.dark-mode .kpi-card {
            background: #1e293b;
        }
        .kpi-card:hover { transform: translateY(-3px); }
        .kpi-card .kpi-value { font-size: 32px; font-weight: bold; color: #1a1a2e; }
        body.dark-mode .kpi-card .kpi-value { color: #e2e8f0; }
        .kpi-card .kpi-label { font-size: 13px; color: #666; margin-top: 8px; }
        body.dark-mode .kpi-card .kpi-label { color: #94a3b8; }
        
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
        body.dark-mode .stat-card {
            background: #1e293b;
        }
        .stat-card h3 {
            font-size: 16px;
            color: #333;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
        }
        body.dark-mode .stat-card h3 {
            color: #e2e8f0;
            border-bottom-color: #334155;
        }
        
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
        body.dark-mode .chart-card {
            background: #1e293b;
        }
        .chart-card h3 {
            margin-bottom: 20px;
            color: #333;
            font-size: 18px;
        }
        body.dark-mode .chart-card h3 {
            color: #e2e8f0;
        }
        canvas { max-height: 300px; width: 100% !important; }
        
        .table-container {
            background: white;
            border-radius: 20px;
            overflow-x: auto;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 20px;
        }
        body.dark-mode .table-container {
            background: #1e293b;
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #eee; }
        body.dark-mode th, body.dark-mode td {
            border-bottom-color: #334155;
            color: #cbd5e1;
        }
        th { background: #f8f9fa; font-weight: 600; color: #555; }
        body.dark-mode th {
            background: #0f172a;
            color: #94a3b8;
        }
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
        <div class="header">
            <div>
                <h1>📊 <span>Management Report</span></h1>
                <p class="report-date">Generated on: <?php echo date('F j, Y g:i A'); ?></p>
                <p style="font-size: 12px; color: #667eea; margin-top: 5px;">
                    📦 Automatically includes archived events within the selected date range
                </p>
            </div>
            <div class="action-buttons no-print">
                <button onclick="window.print()" class="btn btn-secondary">🖨️ Print Report</button>
                <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
            </div>
        </div>
        
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
                <div class="kpi-trend"><?php echo $cancellationStats['cancelled']; ?> cancelled</div>
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
        
        <!-- Charts -->
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
                <h3>📊 Monthly Revenue & Bookings</h3>
                <canvas id="monthlyChart"></canvas>
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
                    foreach ($topCustomers as $customer): 
                    ?>
                    <tr>
                        <td>#<?php echo $rank++; ?></td>
                        <td><?php echo htmlspecialchars($customer['name']); ?></td>
                        <td><?php echo $customer['bookings']; ?> bookings</d>
                        <td><strong><?php echo number_format($customer['total_spent'], 2); ?> JOD</strong></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($topCustomers)): ?>
                    <tr><td colspan="4" style="text-align: center; padding: 40px;">No data available</d></tr>
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
                    <?php foreach ($weeklyData as $week): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($week['week_start'])); ?></td>
                        <td><?php echo $week['bookings']; ?></d>
                        <td><strong><?php echo number_format($week['revenue'], 2); ?> JOD</strong></d>
                        <td><?php echo number_format($week['bookings'] > 0 ? $week['revenue'] / $week['bookings'] : 0, 2); ?> JOD</d>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Daily Revenue Chart
        const dailyDates = <?php echo $dailyDates; ?>;
        const dailyRevenueData = <?php echo $dailyRevenue; ?>;
        
        if (dailyDates.length > 0) {
            new Chart(document.getElementById('dailyRevenueChart'), {
                type: 'line',
                data: {
                    labels: dailyDates,
                    datasets: [{
                        label: 'Revenue (JOD)',
                        data: dailyRevenueData,
                        borderColor: '#28a745',
                        backgroundColor: 'rgba(40, 167, 69, 0.1)',
                        tension: 0.4,
                        fill: true
                    }]
                },
                options: { responsive: true, maintainAspectRatio: true }
            });

            // Daily Bookings Chart
            new Chart(document.getElementById('dailyBookingsChart'), {
                type: 'bar',
                data: {
                    labels: dailyDates,
                    datasets: [{
                        label: 'Number of Bookings',
                        data: <?php echo $dailyBookings; ?>,
                        backgroundColor: '#667eea',
                        borderRadius: 8
                    }]
                },
                options: { responsive: true, maintainAspectRatio: true }
            });
        } else {
            document.getElementById('dailyRevenueChart').parentElement.innerHTML = '<p class="text-muted" style="text-align:center; padding:50px;">No data available for selected period</p>';
        }

        // Payment Pie Chart
        new Chart(document.getElementById('paymentPieChart'), {
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
        new Chart(document.getElementById('guestTypeChart'), {
            type: 'doughnut',
            data: {
                labels: ['Adults', 'Teens', 'Kids'],
                datasets: [{
                    data: [<?php echo $guestDistribution['total_adults'] ?? 0; ?>, <?php echo $guestDistribution['total_teens'] ?? 0; ?>, <?php echo $guestDistribution['total_kids'] ?? 0; ?>],
                    backgroundColor: ['#4f46e5', '#10b981', '#f59e0b'],
                    borderWidth: 0
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });

        // Hourly Pattern Chart
        const hourlyLabels = <?php echo $hourlyLabels; ?>;
        const hourlyData = <?php echo $hourlyBookings; ?>;
        
        if (hourlyLabels.length > 0) {
            new Chart(document.getElementById('hourlyPatternChart'), {
                type: 'line',
                data: {
                    labels: hourlyLabels,
                    datasets: [{
                        label: 'Bookings',
                        data: hourlyData,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }

        // Day of Week Chart
        const dayNames = <?php echo $dayNames; ?>;
        const dayRevenueData = <?php echo $dayRevenue; ?>;
        
        if (dayNames.length > 0) {
            new Chart(document.getElementById('dayOfWeekChart'), {
                type: 'bar',
                data: {
                    labels: dayNames,
                    datasets: [{
                        label: 'Revenue (JOD)',
                        data: dayRevenueData,
                        backgroundColor: '#28a745',
                        borderRadius: 8
                    }]
                },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }

        // Monthly Chart (Revenue + Bookings combined)
        const monthlyLabels = <?php echo $monthlyLabels; ?>;
        const monthlyRevenue = <?php echo $monthlyRevenueData; ?>;
        const monthlyBookings = <?php echo $monthlyBookingsData; ?>;
        
        if (monthlyLabels.length > 0) {
            new Chart(document.getElementById('monthlyChart'), {
                type: 'bar',
                data: {
                    labels: monthlyLabels,
                    datasets: [
                        {
                            label: 'Revenue (JOD)',
                            data: monthlyRevenue,
                            backgroundColor: '#4f46e5',
                            borderRadius: 8,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Bookings',
                            data: monthlyBookings,
                            backgroundColor: '#f59e0b',
                            borderRadius: 8,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: { 
                        y: { beginAtZero: true, title: { display: true, text: 'Revenue (JOD)' } }, 
                        y1: { position: 'right', beginAtZero: true, title: { display: true, text: 'Bookings' } } 
                    }
                }
            });
        }

        // Popular Tables Chart (Horizontal Bar)
        const tableLabels = <?php echo $tableLabels; ?>;
        const tableBookingsData = <?php echo $tableBookings; ?>;
        
        if (tableLabels.length > 0) {
            new Chart(document.getElementById('popularTablesChart'), {
                type: 'bar',
                data: {
                    labels: tableLabels,
                    datasets: [{
                        label: 'Number of Bookings',
                        data: tableBookingsData,
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
        }

        // Dark mode toggle
        const darkModeToggle = document.createElement('button');
        darkModeToggle.innerHTML = '🌙';
        darkModeToggle.style.cssText = 'position:fixed; bottom:20px; right:20px; background:#667eea; color:white; border:none; border-radius:50%; width:50px; height:50px; cursor:pointer; z-index:1000;';
        darkModeToggle.onclick = () => document.body.classList.toggle('dark-mode');
        if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark-mode');
        document.body.appendChild(darkModeToggle);
    </script>
</body>
</html>