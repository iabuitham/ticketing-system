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

// Get all paid reservations for current event
$query = "
    SELECT 
        r.reservation_id,
        r.name,
        r.phone,
        r.table_id,
        r.adults,
        r.teens,
        r.kids,
        (r.adults + r.teens + r.kids) as total_guests,
        r.price_tier,
        r.status
    FROM reservations r
    WHERE r.event_id = ? AND r.status = 'paid'
    ORDER BY r.table_id, r.name
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $selected_event_id);
$stmt->execute();
$reservations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate per table and per reservation
$total_sets = 0;
$total_plates = 0;
$total_guests = 0;
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
            'total_guests' => 0,
            'total_sets' => 0,
            'total_plates' => 0,
            'reservations' => []
        ];
    }
    
    $table_data[$table_id]['total_guests'] += $guests;
    $table_data[$table_id]['total_sets'] += $sets;
    $table_data[$table_id]['total_plates'] += $plates;
    $table_data[$table_id]['reservations'][] = [
        'name' => $res['name'],
        'guests' => $guests,
        'sets' => $sets,
        'plates' => $plates,
        'price_tier' => $res['price_tier']
    ];
}

// Sort tables by table number
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
    <title>Hall Captain Report - <?php echo htmlspecialchars($event_name); ?></title>
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
        .header h1 span { color: #10b981; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
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
        .stat-number { font-size: 32px; font-weight: bold; color: #10b981; }
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
        .btn-primary { background: #10b981; color: white; }
        .btn-primary:hover { background: #059669; transform: translateY(-1px); }
        .btn-secondary { background: #64748b; color: white; }
        
        .hall-header {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
        body.dark-mode th, body.dark-mode td { border-bottom-color: #334155; }
        th { background: #f8fafc; font-weight: 600; }
        body.dark-mode th { background: #0f172a; color: #94a3b8; }
        
        .table-group {
            margin-bottom: 30px;
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .table-group-title {
            background: #e2e8f0;
            padding: 10px 15px;
            border-radius: 12px;
            margin-bottom: 10px;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        body.dark-mode .table-group-title { background: #334155; }
        
        .sub-table {
            margin-left: 20px;
            margin-bottom: 15px;
        }
        .sub-table table {
            width: calc(100% - 20px);
            margin-left: 20px;
            font-size: 13px;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
            margin-left: 8px;
        }
        .badge-loyalty { background: #f59e0b; color: white; }
        .badge-regular { background: #4f46e5; color: white; }
        
        .total-row {
            background: #f8fafc;
            font-weight: bold;
        }
        body.dark-mode .total-row { background: #0f172a; }
        
        .footer-note {
            margin-top: 30px;
            padding: 15px;
            background: #d1fae5;
            border-radius: 12px;
            border-left: 4px solid #10b981;
            font-size: 13px;
        }
        body.dark-mode .footer-note { background: #064e3b; color: #6ee7b7; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .header { flex-direction: column; text-align: center; }
            .table-group-title { flex-direction: column; text-align: center; gap: 10px; }
        }
        @media print {
            body { background: white; padding: 0; }
            .no-print { display: none; }
            .card { box-shadow: none; break-inside: avoid; }
            .table-group { break-inside: avoid; page-break-inside: avoid; }
            .stats-grid { break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="header no-print">
        <div>
            <h1>👔 <span>Hall Captain Report</span></h1>
            <p><?php echo htmlspecialchars($event_name); ?> - <?php echo date('F j, Y', strtotime($event_date)); ?></p>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print Report</button>
            <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        </div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo count($table_data); ?></div>
            <div class="stat-label">Occupied Tables</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $total_sets; ?></div>
            <div class="stat-label">Total Sets</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $total_plates; ?></div>
            <div class="stat-label">Total Plates</div>
        </div>
    </div>
    
    <div class="card">
        <div class="hall-header">
            <h2><i class="bi bi-person-badge"></i> Hall Captain Distribution Report</h2>
            <p style="margin-top: 5px;">Sets and plates to be served at each table</p>
        </div>
        
        <?php foreach ($table_data as $table): ?>
        <div class="table-group">
            <div class="table-group-title">
                <span><i class="bi bi-grid-3x3-gap-fill"></i> <strong>Table <?php echo htmlspecialchars($table['table_id']); ?></strong></span>
                <span>
                    👥 <?php echo $table['total_guests']; ?> guests | 
                    📦 <?php echo $table['total_sets']; ?> sets | 
                    🍽️ <?php echo $table['total_plates']; ?> plates
                </span>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>Customer Name</th>
                        <th>Guests</th>
                        <th>Sets</th>
                        <th>Plates</th>
                        <th>Tier</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($table['reservations'] as $res): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($res['name']); ?></td>
                        <td><?php echo $res['guests']; ?></td>
                        <td><?php echo $res['sets']; ?></td>
                        <td><?php echo $res['plates']; ?></td>
                        <td>
                            <?php if ($res['price_tier'] == 'loyalty'): ?>
                                <span class="badge badge-loyalty">Loyalty</span>
                            <?php else: ?>
                                <span class="badge badge-regular">Regular</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="total-row">
                    <tr>
                        <td><strong>Table Total</strong></td>
                        <td><strong><?php echo $table['total_guests']; ?></strong></td>
                        <td><strong><?php echo $table['total_sets']; ?></strong></td>
                        <td><strong><?php echo $table['total_plates']; ?></strong></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <?php endforeach; ?>
        
        <?php if (empty($table_data)): ?>
        <div style="text-align: center; padding: 60px;">
            <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.5;"></i>
            <p style="margin-top: 15px;">No paid reservations found for this event</p>
        </div>
        <?php endif; ?>
        
        <div class="footer-note">
            <i class="bi bi-info-circle"></i>
            <strong>Instructions for Hall Captain:</strong><br>
            • Each set serves <?php echo $people_per_set; ?> people with <?php echo $plates_per_set; ?> plates<br>
            • Sets are calculated as: ⌈(Guests) ÷ <?php echo $people_per_set; ?>⌉ (rounded up)<br>
            • Example: 6 guests = 2 sets = <?php echo $plates_per_set * 2; ?> plates<br>
            • Ensure each table receives the correct number of sets listed above
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