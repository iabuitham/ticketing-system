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
$event_name = $_SESSION['selected_event_name'] ?? 'Current Event';
$event_date = $_SESSION['selected_event_date'] ?? date('Y-m-d');

// Get package settings
$people_per_set = intval(getSetting('package_people_per_set', 5));
$plates_per_set = intval(getSetting('package_plates_per_set', 10));
$package_price = floatval(getSetting('package_price', 13));

// Get all paid reservations for current event
$query = "
    SELECT 
        r.reservation_id,
        r.name,
        r.table_id,
        r.adults,
        r.teens,
        r.kids,
        (r.adults + r.teens + r.kids) as total_guests,
        r.price_tier
    FROM reservations r
    WHERE r.event_id = ? AND r.status = 'paid'
    ORDER BY r.table_id
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $selected_event_id);
$stmt->execute();
$reservations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate totals
$total_guests = 0;
$total_sets = 0;
$total_plates = 0;
$table_data = [];

foreach ($reservations as $res) {
    $guests = $res['total_guests'];
    $sets = ceil($guests / $people_per_set);
    $plates = $sets * $plates_per_set;
    
    $total_guests += $guests;
    $total_sets += $sets;
    $total_plates += $plates;
    
    $table_id = $res['table_id'] ?: 'Unassigned';
    
    if (!isset($table_data[$table_id])) {
        $table_data[$table_id] = [
            'table_id' => $table_id,
            'guests' => 0,
            'sets' => 0,
            'plates' => 0
        ];
    }
    
    $table_data[$table_id]['guests'] += $guests;
    $table_data[$table_id]['sets'] += $sets;
    $table_data[$table_id]['plates'] += $plates;
}

// Sort by table number
uksort($table_data, function($a, $b) {
    $num_a = intval(preg_replace('/[^0-9]/', '', $a));
    $num_b = intval(preg_replace('/[^0-9]/', '', $b));
    return $num_a - $num_b;
});

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kitchen Report - <?php echo htmlspecialchars($event_name); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        body.dark-mode { background: #0f172a; color: #e2e8f0; }
        .container { max-width: 1200px; margin: 0 auto; }
        
        .header {
            background: white;
            border-radius: 24px;
            padding: 25px 30px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        body.dark-mode .header { background: #1e293b; }
        .header h1 { font-size: 28px; color: #1a1a2e; }
        body.dark-mode .header h1 { color: #e2e8f0; }
        .header h1 span { color: #ef4444; }
        
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
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        body.dark-mode .stat-card { background: #1e293b; }
        .stat-number { font-size: 32px; font-weight: bold; color: #ef4444; }
        .stat-label { font-size: 13px; color: #64748b; margin-top: 5px; }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
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
            padding: 10px 20px;
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
        .btn-primary { background: #ef4444; color: white; }
        .btn-primary:hover { background: #dc2626; transform: translateY(-1px); }
        .btn-secondary { background: #64748b; color: white; }
        
        .kitchen-header {
            background: linear-gradient(135deg, #ef4444, #dc2626);
            color: white;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .summary-box {
            background: #f8fafc;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
        }
        body.dark-mode .summary-box { background: #0f172a; }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }
        body.dark-mode .summary-row { border-bottom-color: #334155; }
        .summary-row:last-child { border-bottom: none; }
        .summary-label { font-weight: 600; font-size: 16px; }
        .summary-value { font-size: 24px; font-weight: bold; color: #ef4444; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        body.dark-mode th, body.dark-mode td { border-bottom-color: #334155; }
        th { background: #f8fafc; font-weight: 600; }
        body.dark-mode th { background: #0f172a; color: #94a3b8; }
        tfoot tr { background: #f8fafc; font-weight: bold; }
        body.dark-mode tfoot tr { background: #0f172a; }
        
        .info-box {
            background: #fef3c7;
            padding: 15px;
            border-radius: 12px;
            margin-top: 20px;
            border-left: 4px solid #f59e0b;
        }
        body.dark-mode .info-box { background: #451a03; color: #fde68a; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .header { flex-direction: column; text-align: center; }
        }
        @media print {
            body { background: white; padding: 0; }
            .no-print { display: none; }
            .stat-card, .card { box-shadow: none; break-inside: avoid; }
            .stats-grid { break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header no-print">
        <div>
            <h1>🍳 <span>Kitchen Report</span></h1>
            <p><?php echo htmlspecialchars($event_name); ?> - <?php echo date('F j, Y', strtotime($event_date)); ?></p>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print Report</button>
            <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        </div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo $people_per_set; ?></div>
            <div class="stat-label">People per Set</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $plates_per_set; ?></div>
            <div class="stat-label">Plates per Set</div>
        </div>
    </div>
    
    <div class="card">
        <div class="kitchen-header">
            <h2><i class="bi bi-egg-fried"></i> Kitchen Production Summary</h2>
            <p style="margin-top: 5px;">Total food to prepare for the event</p>
        </div>
        
        <div class="summary-box">
            <div class="summary-row">
                <span class="summary-label">👥 Total Guests:</span>
                <span class="summary-value"><?php echo $total_guests; ?> people</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">📦 Total Sets Required:</span>
                <span class="summary-value"><?php echo $total_sets; ?> sets</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">🍽️ Total Plates Required:</span>
                <span class="summary-value"><?php echo $total_plates; ?> plates</span>
            </div>
            <div class="summary-row">
                <span class="summary-label">📐 Calculation Formula:</span>
                <span class="summary-value">⌈Guests ÷ <?php echo $people_per_set; ?>⌉ × <?php echo $plates_per_set; ?> plates</span>
            </div>
        </div>
        
        <h3 style="margin: 20px 0 15px 0;"><i class="bi bi-table"></i> Breakdown by Table</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Table Number</th>
                        <th>Guests</th>
                        <th>Sets</th>
                        <th>Plates</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table_data as $table): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($table['table_id']); ?></strong></td>
                        <td><?php echo $table['guests']; ?></td>
                        <td><?php echo $table['sets']; ?></td>
                        <td><?php echo $table['plates']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($table_data)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 40px;">
                            <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.5;"></i>
                            <p>No paid reservations found</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td><strong>TOTAL</strong></td>
                        <td><strong><?php echo $total_guests; ?></strong></td>
                        <td><strong><?php echo $total_sets; ?></strong></td>
                        <td><strong><?php echo $total_plates; ?></strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        
        <div class="info-box">
            <i class="bi bi-info-circle"></i>
            <strong>Note to Kitchen:</strong> 
            Each set contains <?php echo $plates_per_set; ?> appetizer plates for <?php echo $people_per_set; ?> people.
            Sets are rounded up (e.g., 6 people = 2 sets = 20 plates).
        </div>
    </div>
</div>

<script>
    const darkModeToggle = document.createElement('button');
    darkModeToggle.innerHTML = '🌙';
    darkModeToggle.style.cssText = 'position:fixed; bottom:20px; right:20px; background:#4f46e5; color:white; border:none; border-radius:50%; width:50px; height:50px; cursor:pointer; z-index:1000;';
    darkModeToggle.onclick = () => document.body.classList.toggle('dark-mode');
    if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark-mode');
    document.body.appendChild(darkModeToggle);
</script>
</body>
</html>