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
$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_system_settings'])) {
        // Save system settings
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'setting_') === 0) {
                $setting_key = substr($key, 8);
                $setting_value = sanitizeInput($value);
                
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) 
                                        VALUES (?, ?) 
                                        ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->bind_param("sss", $setting_key, $setting_value, $setting_value);
                $stmt->execute();
                $stmt->close();
            }
        }
        
        // Handle checkboxes (they don't send value when unchecked)
        $checkboxes = ['enable_whatsapp', 'enable_email', 'enable_sms', 'enable_captcha', 'dark_mode_enabled', 'maintenance_mode'];
        foreach ($checkboxes as $checkbox) {
            $value = isset($_POST["setting_$checkbox"]) ? '1' : '0';
            $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->bind_param("sss", $checkbox, $value, $value);
            $stmt->execute();
            $stmt->close();
        }
        
        clearSettingsCache();
        $message = "System settings saved successfully!";
        $messageType = "success";
        
    } elseif (isset($_POST['save_event'])) {
        // Add/Update event
        $event_id = $_POST['event_id'] ?? 0;
        $event_name = sanitizeInput($_POST['event_name']);
        $event_date = sanitizeInput($_POST['event_date']);
        $event_time = sanitizeInput($_POST['event_time']);
        $venue = sanitizeInput($_POST['venue']);
        $description = sanitizeInput($_POST['description']);
        $capacity = !empty($_POST['capacity']) ? intval($_POST['capacity']) : null;
        $status = sanitizeInput($_POST['status']);
        $ticket_price_adult = floatval($_POST['ticket_price_adult'] ?? 10);
        $ticket_price_teen = floatval($_POST['ticket_price_teen'] ?? 10);
        $ticket_price_kid = floatval($_POST['ticket_price_kid'] ?? 0);
        
        if ($event_id > 0) {
            // Update existing event
            $stmt = $conn->prepare("UPDATE event_settings 
                                    SET event_name = ?, event_date = ?, event_time = ?, 
                                        venue = ?, description = ?, capacity = ?, status = ?,
                                        ticket_price_adult = ?, ticket_price_teen = ?, ticket_price_kid = ?
                                    WHERE id = ?");
            $stmt->bind_param("sssssisdddi", $event_name, $event_date, $event_time, 
                              $venue, $description, $capacity, $status,
                              $ticket_price_adult, $ticket_price_teen, $ticket_price_kid, $event_id);
            $stmt->execute();
            $stmt->close();
            $message = "Event updated successfully!";
        } else {
            // Add new event
            $stmt = $conn->prepare("INSERT INTO event_settings 
                                    (event_name, event_date, event_time, venue, description, capacity, status,
                                     ticket_price_adult, ticket_price_teen, ticket_price_kid) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssisddd", $event_name, $event_date, $event_time, 
                              $venue, $description, $capacity, $status,
                              $ticket_price_adult, $ticket_price_teen, $ticket_price_kid);
            $stmt->execute();
            $stmt->close();
            $message = "Event added successfully!";
        }
        $messageType = "success";
        
    } elseif (isset($_POST['delete_event'])) {
        $event_id = intval($_POST['delete_event']);
        $stmt = $conn->prepare("DELETE FROM event_settings WHERE id = ?");
        $stmt->bind_param("i", $event_id);
        $stmt->execute();
        $stmt->close();
        $message = "Event deleted successfully!";
        $messageType = "success";
    }
}

// Get all system settings
$systemSettings = [];
$result = $conn->query("SELECT * FROM system_settings ORDER BY setting_key");
while ($row = $result->fetch_assoc()) {
    $systemSettings[$row['setting_key']] = $row;
}

// Get all events with null handling
$events = [];
$result = $conn->query("SELECT * FROM event_settings ORDER BY event_date DESC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        // Set default values for null fields
        $row['capacity'] = $row['capacity'] ?? 0;
        $row['tickets_sold'] = $row['tickets_sold'] ?? 0;
        $row['status'] = $row['status'] ?? 'upcoming';
        $row['ticket_price_adult'] = $row['ticket_price_adult'] ?? 10;
        $row['ticket_price_teen'] = $row['ticket_price_teen'] ?? 10;
        $row['ticket_price_kid'] = $row['ticket_price_kid'] ?? 0;
        $events[] = $row;
    }
}

$currency = $systemSettings['currency']['setting_value'] ?? 'JOD';
$currencySymbol = $systemSettings['currency_symbol']['setting_value'] ?? 'JD';
$themeColor = $systemSettings['theme_color']['setting_value'] ?? '#4f46e5';
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo getDirection(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('system_settings'); ?> - <?php echo t('ticketing_system'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
            transition: background 0.3s ease;
        }
        
        body.dark-mode {
            background: #0f172a;
            color: #e2e8f0;
        }
        
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
        
        body.dark-mode .navbar {
            background: #1e293b;
        }
        
        .navbar h1 { font-size: 1.5rem; display: flex; align-items: center; gap: 8px; }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .tab-btn {
            padding: 12px 24px;
            background: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        body.dark-mode .tab-btn {
            background: #1e293b;
            color: #94a3b8;
        }
        
        .tab-btn.active {
            background: <?php echo $themeColor; ?>;
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        body.dark-mode .card {
            background: #1e293b;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        body.dark-mode .card-header {
            border-bottom-color: #334155;
        }
        
        .card-header h2 {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.3rem;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #334155;
        }
        
        body.dark-mode .form-group label {
            color: #cbd5e1;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        body.dark-mode .form-group input,
        body.dark-mode .form-group select,
        body.dark-mode .form-group textarea {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: <?php echo $themeColor; ?>;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
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
        
        .btn-primary { background: <?php echo $themeColor; ?>; color: white; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-secondary:hover { background: #475569; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .form-actions {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        body.dark-mode .form-actions {
            border-top-color: #334155;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        body.dark-mode th,
        body.dark-mode td {
            border-bottom-color: #334155;
        }
        
        th {
            background: #f8fafc;
            font-weight: 600;
        }
        
        body.dark-mode th {
            background: #0f172a;
            color: #94a3b8;
        }
        
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-container {
            background: white;
            border-radius: 24px;
            max-width: 700px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        body.dark-mode .modal-container {
            background: #1e293b;
        }
        
        .modal-header {
            padding: 20px 24px;
            background: <?php echo $themeColor; ?>;
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
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-buttons {
            padding: 0 24px 24px 24px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        
        .alert {
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .text-muted {
            color: #64748b;
            font-size: 12px;
            margin-top: 5px;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-upcoming { background: #dbeafe; color: #1e40af; }
        .badge-ongoing { background: #fef3c7; color: #92400e; }
        .badge-completed { background: #d1fae5; color: #065f46; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
            .tabs {
                flex-direction: column;
            }
            .tab-btn {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="navbar">
            <h1><i class="bi bi-gear"></i> <?php echo t('system_settings'); ?></h1>
            <div>
                <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> <?php echo t('back_to_dashboard'); ?></a>
                <a href="logout.php" class="btn btn-danger"><i class="bi bi-box-arrow-right"></i> <?php echo t('logout'); ?></a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="bi bi-<?php echo $messageType == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="tabs">
            <button class="tab-btn active" onclick="switchTab('system')">
                <i class="bi bi-sliders2"></i> General Settings
            </button>
            <button class="tab-btn" onclick="switchTab('pricing')">
                <i class="bi bi-ticket-perforated"></i> Global Pricing
            </button>
            <button class="tab-btn" onclick="switchTab('events')">
                <i class="bi bi-calendar-event"></i> Events
            </button>
            <button class="tab-btn" onclick="switchTab('notifications')">
                <i class="bi bi-bell"></i> Notifications
            </button>
            <button class="tab-btn" onclick="switchTab('appearance')">
                <i class="bi bi-palette"></i> Appearance
            </button>
        </div>
        
        <!-- General Settings Tab -->
        <div id="systemTab" class="tab-content active">
            <form method="POST">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="bi bi-building"></i> General Settings</h2>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label><i class="bi bi-globe"></i> Site Name</label>
                            <input type="text" name="setting_site_name" value="<?php echo htmlspecialchars($systemSettings['site_name']['setting_value'] ?? 'Ticketing System'); ?>">
                            <div class="text-muted">Name displayed throughout the system</div>
                        </div>
                        <div class="form-group">
                            <label><i class="bi bi-tag"></i> Reservation ID Prefix</label>
                            <input type="text" name="setting_reservation_prefix" value="<?php echo htmlspecialchars($systemSettings['reservation_prefix']['setting_value'] ?? 'TKT'); ?>">
                            <div class="text-muted">Example: TKT-20240315-ABC123</div>
                        </div>
                        <div class="form-group">
                            <label><i class="bi bi-currency-exchange"></i> Currency Code</label>
                            <select name="setting_currency">
                                <option value="JOD" <?php echo ($systemSettings['currency']['setting_value'] ?? '') == 'JOD' ? 'selected' : ''; ?>>JOD - Jordanian Dinar</option>
                                <option value="USD" <?php echo ($systemSettings['currency']['setting_value'] ?? '') == 'USD' ? 'selected' : ''; ?>>USD - US Dollar</option>
                                <option value="EUR" <?php echo ($systemSettings['currency']['setting_value'] ?? '') == 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                                <option value="GBP" <?php echo ($systemSettings['currency']['setting_value'] ?? '') == 'GBP' ? 'selected' : ''; ?>>GBP - British Pound</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="bi bi-cash"></i> Currency Symbol</label>
                            <input type="text" name="setting_currency_symbol" value="<?php echo htmlspecialchars($systemSettings['currency_symbol']['setting_value'] ?? 'JD'); ?>">
                        </div>
                        <div class="form-group">
                            <label><i class="bi bi-clock"></i> Timezone</label>
                            <select name="setting_timezone">
                                <option value="Asia/Amman" <?php echo ($systemSettings['timezone']['setting_value'] ?? '') == 'Asia/Amman' ? 'selected' : ''; ?>>Asia/Amman</option>
                                <option value="Asia/Dubai" <?php echo ($systemSettings['timezone']['setting_value'] ?? '') == 'Asia/Dubai' ? 'selected' : ''; ?>>Asia/Dubai</option>
                                <option value="Asia/Riyadh" <?php echo ($systemSettings['timezone']['setting_value'] ?? '') == 'Asia/Riyadh' ? 'selected' : ''; ?>>Asia/Riyadh</option>
                                <option value="UTC" <?php echo ($systemSettings['timezone']['setting_value'] ?? '') == 'UTC' ? 'selected' : ''; ?>>UTC</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label><i class="bi bi-shield-lock"></i> Max Reservations Per Day</label>
                            <input type="number" name="setting_max_reservations_per_day" value="<?php echo $systemSettings['max_reservations_per_day']['setting_value'] ?? 1000; ?>">
                            <div class="text-muted">Set 0 for unlimited</div>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="setting_enable_captcha" value="1" <?php echo ($systemSettings['enable_captcha']['setting_value'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                Enable CAPTCHA on booking form
                            </label>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="setting_maintenance_mode" value="1" <?php echo ($systemSettings['maintenance_mode']['setting_value'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                Maintenance Mode (Only admins can access)
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="save_system_settings" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save Settings
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Global Pricing Tab (Fallback when no event selected) -->
        <div id="pricingTab" class="tab-content">
            <form method="POST">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="bi bi-ticket-perforated"></i> Global Ticket Prices (Fallback)</h2>
                        <div class="text-muted">These are used when no event is selected</div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="bi bi-gender-male"></i> Adult Ticket Price</label>
                            <div style="display: flex; align-items: center;">
                                <span style="background: #e2e8f0; padding: 10px; border-radius: 8px 0 0 8px;"><?php echo $currencySymbol; ?></span>
                                <input type="number" name="setting_ticket_price_adult" step="0.01" value="<?php echo $systemSettings['ticket_price_adult']['setting_value'] ?? 10; ?>" style="border-radius: 0 8px 8px 0; flex: 1;">
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="bi bi-gender-female"></i> Teen Ticket Price</label>
                            <div style="display: flex; align-items: center;">
                                <span style="background: #e2e8f0; padding: 10px; border-radius: 8px 0 0 8px;"><?php echo $currencySymbol; ?></span>
                                <input type="number" name="setting_ticket_price_teen" step="0.01" value="<?php echo $systemSettings['ticket_price_teen']['setting_value'] ?? 10; ?>" style="border-radius: 0 8px 8px 0; flex: 1;">
                            </div>
                        </div>
                        <div class="form-group">
                            <label><i class="bi bi-egg-fried"></i> Kid Ticket Price</label>
                            <div style="display: flex; align-items: center;">
                                <span style="background: #e2e8f0; padding: 10px; border-radius: 8px 0 0 8px;"><?php echo $currencySymbol; ?></span>
                                <input type="number" name="setting_ticket_price_kid" step="0.01" value="<?php echo $systemSettings['ticket_price_kid']['setting_value'] ?? 0; ?>" style="border-radius: 0 8px 8px 0; flex: 1;">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h2><i class="bi bi-percent"></i> Discount Settings</h2>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Early Bird Discount (%)</label>
                            <input type="number" name="setting_early_bird_discount" step="1" value="<?php echo $systemSettings['early_bird_discount']['setting_value'] ?? 0; ?>">
                            <div class="text-muted">Percentage discount for early bookings</div>
                        </div>
                        <div class="form-group">
                            <label>Group Discount (%)</label>
                            <input type="number" name="setting_group_discount" step="1" value="<?php echo $systemSettings['group_discount']['setting_value'] ?? 0; ?>">
                        </div>
                        <div class="form-group">
                            <label>Minimum Group Size</label>
                            <input type="number" name="setting_min_group_size" value="<?php echo $systemSettings['min_group_size']['setting_value'] ?? 10; ?>">
                            <div class="text-muted">Minimum people for group discount</div>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="save_system_settings" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save Global Pricing
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Events Tab -->
        <div id="eventsTab" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h2><i class="bi bi-calendar-plus"></i> Manage Events</h2>
                    <button onclick="openEventModal()" class="btn btn-success btn-sm">
                        <i class="bi bi-plus-circle"></i> Add New Event
                    </button>
                </div>
                
                <?php if (empty($events)): ?>
                    <div style="text-align: center; padding: 60px 20px;">
                        <i class="bi bi-calendar-x" style="font-size: 64px; opacity: 0.3;"></i>
                        <p style="margin-top: 15px; color: #64748b;">No events found. Click "Add New Event" to create one.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Event Name</th>
                                    <th>Date & Time</th>
                                    <th>Venue</th>
                                    <th>Ticket Prices</th>
                                    <th>Capacity</th>
                                    <th>Tickets Sold</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($events as $event): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($event['event_name']); ?></strong></td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($event['event_date'])); ?><br>
                                            <small><?php echo date('h:i A', strtotime($event['event_time'])); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($event['venue']); ?></td>
                                        <td style="font-size: 12px;">
                                            Adult: <?php echo $currencySymbol; ?> <?php echo number_format($event['ticket_price_adult'], 2); ?><br>
                                            Teen: <?php echo $currencySymbol; ?> <?php echo number_format($event['ticket_price_teen'], 2); ?><br>
                                            Kid: <?php echo $currencySymbol; ?> <?php echo number_format($event['ticket_price_kid'], 2); ?>
                                        </td>
                                        <td><?php echo number_format(floatval($event['capacity'])); ?></td>
                                        <td><?php echo number_format(floatval($event['tickets_sold'])); ?></td>
                                        <td>
                                            <span class="badge badge-<?php echo htmlspecialchars($event['status']); ?>">
                                                <?php echo ucfirst(htmlspecialchars($event['status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button onclick="editEvent(<?php echo $event['id']; ?>)" class="btn btn-warning btn-sm">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button onclick="deleteEvent(<?php echo $event['id']; ?>)" class="btn btn-danger btn-sm">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Notifications Tab -->
        <div id="notificationsTab" class="tab-content">
            <form method="POST">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="bi bi-whatsapp"></i> WhatsApp Settings</h2>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="setting_enable_whatsapp" value="1" <?php echo ($systemSettings['enable_whatsapp']['setting_value'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                Enable WhatsApp Notifications
                            </label>
                        </div>
                        <div class="form-group">
                            <label>WhatsApp Business Number</label>
                            <input type="text" name="setting_whatsapp_number" value="<?php echo htmlspecialchars($systemSettings['whatsapp_number']['setting_value'] ?? ''); ?>" placeholder="+962XXXXXXXXX">
                            <div class="text-muted">Include country code</div>
                        </div>
                        <div class="form-group">
                            <label>WhatsApp API Key</label>
                            <input type="password" name="setting_whatsapp_api_key" value="<?php echo htmlspecialchars($systemSettings['whatsapp_api_key']['setting_value'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h2><i class="bi bi-envelope"></i> Email Settings</h2>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="setting_enable_email" value="1" <?php echo ($systemSettings['enable_email']['setting_value'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                Enable Email Notifications
                            </label>
                        </div>
                        <div class="form-group">
                            <label>Admin Email</label>
                            <input type="email" name="setting_admin_email" value="<?php echo htmlspecialchars($systemSettings['admin_email']['setting_value'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Host</label>
                            <input type="text" name="setting_smtp_host" value="<?php echo htmlspecialchars($systemSettings['smtp_host']['setting_value'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Port</label>
                            <input type="text" name="setting_smtp_port" value="<?php echo htmlspecialchars($systemSettings['smtp_port']['setting_value'] ?? '587'); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Username</label>
                            <input type="text" name="setting_smtp_user" value="<?php echo htmlspecialchars($systemSettings['smtp_user']['setting_value'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>SMTP Password</label>
                            <input type="password" name="setting_smtp_pass" value="<?php echo htmlspecialchars($systemSettings['smtp_pass']['setting_value'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="save_system_settings" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save Notification Settings
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Appearance Tab -->
        <div id="appearanceTab" class="tab-content">
            <form method="POST">
                <div class="card">
                    <div class="card-header">
                        <h2><i class="bi bi-palette"></i> Theme Settings</h2>
                    </div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Primary Theme Color</label>
                            <input type="color" name="setting_theme_color" value="<?php echo $systemSettings['theme_color']['setting_value'] ?? '#4f46e5'; ?>">
                            <div class="text-muted">Used for buttons, links, and accents</div>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="setting_dark_mode_enabled" value="1" <?php echo ($systemSettings['dark_mode_enabled']['setting_value'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                Enable Dark Mode Option
                            </label>
                        </div>
                        <div class="form-group">
                            <label>Site Logo URL</label>
                            <input type="text" name="setting_site_logo" value="<?php echo htmlspecialchars($systemSettings['site_logo']['setting_value'] ?? ''); ?>" placeholder="https://example.com/logo.png">
                        </div>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-header">
                        <h2><i class="bi bi-file-text"></i> Content Settings</h2>
                    </div>
                    <div class="form-group">
                        <label>Cancellation Policy</label>
                        <textarea name="setting_cancellation_policy" rows="3" class="form-control"><?php echo htmlspecialchars($systemSettings['cancellation_policy']['setting_value'] ?? 'Tickets are non-refundable 24 hours before event'); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Terms & Conditions</label>
                        <textarea name="setting_terms_conditions" rows="5" class="form-control"><?php echo htmlspecialchars($systemSettings['terms_conditions']['setting_value'] ?? 'Please read our terms and conditions carefully.'); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Footer Text</label>
                        <input type="text" name="setting_footer_text" value="<?php echo htmlspecialchars($systemSettings['footer_text']['setting_value'] ?? '© 2024 Ticketing System. All rights reserved.'); ?>" class="form-control">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" name="save_system_settings" class="btn btn-primary">
                        <i class="bi bi-save"></i> Save Appearance Settings
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Event Modal -->
    <div id="eventModal" class="modal-overlay">
        <div class="modal-container">
            <form method="POST" id="eventForm">
                <div class="modal-header">
                    <h3 id="modalTitle"><i class="bi bi-calendar-plus"></i> Add New Event</h3>
                    <button type="button" class="modal-close" onclick="closeEventModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="event_id" id="eventId" value="0">
                    <div class="form-group">
                        <label>Event Name *</label>
                        <input type="text" name="event_name" id="eventName" required class="form-control">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Event Date *</label>
                            <input type="date" name="event_date" id="eventDate" required class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Event Time *</label>
                            <input type="time" name="event_time" id="eventTime" required class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Venue *</label>
                        <input type="text" name="venue" id="venue" required class="form-control">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" id="description" rows="3" class="form-control"></textarea>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Adult Ticket Price (<?php echo $currencySymbol; ?>)</label>
                            <input type="number" name="ticket_price_adult" id="ticketPriceAdult" step="0.01" class="form-control" value="10.00">
                        </div>
                        <div class="form-group">
                            <label>Teen Ticket Price (<?php echo $currencySymbol; ?>)</label>
                            <input type="number" name="ticket_price_teen" id="ticketPriceTeen" step="0.01" class="form-control" value="10.00">
                        </div>
                        <div class="form-group">
                            <label>Kid Ticket Price (<?php echo $currencySymbol; ?>)</label>
                            <input type="number" name="ticket_price_kid" id="ticketPriceKid" step="0.01" class="form-control" value="0.00">
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Capacity</label>
                            <input type="number" name="capacity" id="capacity" class="form-control" value="0">
                            <div class="text-muted">Maximum number of tickets available (0 = unlimited)</div>
                        </div>
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="upcoming">Upcoming</option>
                                <option value="ongoing">Ongoing</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-buttons">
                    <button type="button" onclick="closeEventModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="save_event" class="btn btn-primary">Save Event</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function switchTab(tab) {
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            if (tab === 'system') {
                document.getElementById('systemTab').classList.add('active');
                document.querySelector('.tab-btn').classList.add('active');
            } else if (tab === 'pricing') {
                document.getElementById('pricingTab').classList.add('active');
                document.querySelectorAll('.tab-btn')[1].classList.add('active');
            } else if (tab === 'events') {
                document.getElementById('eventsTab').classList.add('active');
                document.querySelectorAll('.tab-btn')[2].classList.add('active');
            } else if (tab === 'notifications') {
                document.getElementById('notificationsTab').classList.add('active');
                document.querySelectorAll('.tab-btn')[3].classList.add('active');
            } else if (tab === 'appearance') {
                document.getElementById('appearanceTab').classList.add('active');
                document.querySelectorAll('.tab-btn')[4].classList.add('active');
            }
        }
        
        function openEventModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="bi bi-calendar-plus"></i> Add New Event';
            document.getElementById('eventId').value = '0';
            document.getElementById('eventName').value = '';
            document.getElementById('eventDate').value = '';
            document.getElementById('eventTime').value = '';
            document.getElementById('venue').value = '';
            document.getElementById('description').value = '';
            document.getElementById('capacity').value = '0';
            document.getElementById('status').value = 'upcoming';
            document.getElementById('ticketPriceAdult').value = '10.00';
            document.getElementById('ticketPriceTeen').value = '10.00';
            document.getElementById('ticketPriceKid').value = '0.00';
            document.getElementById('eventModal').classList.add('active');
        }
        
        function editEvent(eventId) {
            fetch(`get_event.php?id=${eventId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil"></i> Edit Event';
                        document.getElementById('eventId').value = data.event.id;
                        document.getElementById('eventName').value = data.event.event_name || '';
                        document.getElementById('eventDate').value = data.event.event_date || '';
                        document.getElementById('eventTime').value = data.event.event_time || '';
                        document.getElementById('venue').value = data.event.venue || '';
                        document.getElementById('description').value = data.event.description || '';
                        document.getElementById('capacity').value = data.event.capacity || 0;
                        document.getElementById('status').value = data.event.status || 'upcoming';
                        document.getElementById('ticketPriceAdult').value = data.event.ticket_price_adult || 10;
                        document.getElementById('ticketPriceTeen').value = data.event.ticket_price_teen || 10;
                        document.getElementById('ticketPriceKid').value = data.event.ticket_price_kid || 0;
                        document.getElementById('eventModal').classList.add('active');
                    }
                })
                .catch(error => {
                    alert('Error loading event data');
                });
        }
        
        function deleteEvent(eventId) {
            if (confirm('Are you sure you want to delete this event? This action cannot be undone.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="delete_event" value="${eventId}">`;
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function closeEventModal() {
            document.getElementById('eventModal').classList.remove('active');
        }
        
        // Dark Mode
        const isDarkMode = localStorage.getItem('darkMode') === 'true';
        if (isDarkMode) {
            document.body.classList.add('dark-mode');
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('eventModal');
            if (event.target === modal) {
                closeEventModal();
            }
        }
    </script>
</body>
</html>