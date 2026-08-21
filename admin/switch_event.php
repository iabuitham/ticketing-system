<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();
$error = '';

// Get all active events
$events = $conn->query("
    SELECT id, event_name, event_date, ticket_price_adult, ticket_price_teen, ticket_price_kid,
           early_bird_enabled, early_bird_deadline
    FROM event_settings 
    WHERE status != 'completed' 
    ORDER BY event_date DESC
");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['event_id'])) {
    $event_id = intval($_POST['event_id']);
    
    // Get complete event details
    $stmt = $conn->prepare("
        SELECT event_name, event_date, ticket_price_adult, ticket_price_teen, ticket_price_kid,
               early_bird_enabled, early_bird_deadline, early_bird_price_adult, early_bird_price_teen, early_bird_price_kid
        FROM event_settings WHERE id = ?
    ");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($event) {
        // Update all session variables
        $_SESSION['selected_event_id'] = $event_id;
        $_SESSION['selected_event_name'] = $event['event_name'];
        $_SESSION['selected_event_date'] = $event['event_date'];
        $_SESSION['event_ticket_prices'] = [
            'adult' => $event['ticket_price_adult'],
            'teen' => $event['ticket_price_teen'],
            'kid' => $event['ticket_price_kid']
        ];
        
        // Also store early bird info if needed
        if ($event['early_bird_enabled']) {
            $_SESSION['early_bird_active'] = true;
            $_SESSION['early_bird_deadline'] = $event['early_bird_deadline'];
        }
        
        // Redirect back to dashboard
        header('Location: dashboard.php?switched=1');
        exit();
    } else {
        $error = "Event not found";
    }
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Switch Event - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 600px; margin: 50px auto; }
        .card {
            background: white;
            border-radius: 24px;
            padding: 32px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }
        h2 { color: #1e293b; margin-bottom: 8px; }
        .subtitle { color: #64748b; margin-bottom: 24px; font-size: 14px; }
        .event-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 24px;
            max-height: 400px;
            overflow-y: auto;
        }
        .event-item {
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            padding: 16px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .event-item:hover {
            border-color: #4f46e5;
            background: #f8fafc;
        }
        .event-item.selected {
            border-color: #4f46e5;
            background: #e0e7ff;
        }
        .event-name {
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 4px;
        }
        .event-date {
            font-size: 12px;
            color: #64748b;
        }
        .event-prices {
            font-size: 12px;
            color: #4f46e5;
            margin-top: 8px;
        }
        .btn-switch {
            width: 100%;
            padding: 14px;
            background: #4f46e5;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-switch:hover { background: #4338ca; }
        .btn-cancel {
            width: 100%;
            padding: 12px;
            background: #64748b;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            cursor: pointer;
            margin-top: 12px;
            text-align: center;
            display: block;
            text-decoration: none;
        }
        .error {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .current-event {
            background: #f1f5f9;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2><i class="bi bi-calendar-event"></i> Switch Event</h2>
            <p class="subtitle">Select an event to manage</p>
            
            <?php if ($error): ?>
                <div class="error"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['selected_event_name'])): ?>
                <div class="current-event">
                    <strong>Current Event:</strong><br>
                    <?php echo htmlspecialchars($_SESSION['selected_event_name']); ?>
                    <?php if (isset($_SESSION['selected_event_date'])): ?>
                        <br><small><?php echo date('F j, Y', strtotime($_SESSION['selected_event_date'])); ?></small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="switchForm">
                <div class="event-list">
                    <?php while ($event = $events->fetch_assoc()): ?>
                        <div class="event-item" onclick="selectEvent(<?php echo $event['id']; ?>)">
                            <div class="event-name"><?php echo htmlspecialchars($event['event_name']); ?></div>
                            <div class="event-date">
                                <i class="bi bi-calendar"></i> 
                                <?php echo date('l, F j, Y', strtotime($event['event_date'])); ?>
                            </div>
                            <div class="event-prices">
                                Adults: <?php echo $event['ticket_price_adult']; ?> | 
                                Teens: <?php echo $event['ticket_price_teen']; ?> | 
                                Kids: <?php echo $event['ticket_price_kid']; ?>
                            </div>
                            <?php if ($event['early_bird_enabled'] && $event['early_bird_deadline'] >= date('Y-m-d')): ?>
                                <div class="event-prices" style="color: #10b981;">
                                    <i class="bi bi-gift"></i> Early Bird Active until <?php echo date('M d', strtotime($event['early_bird_deadline'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <input type="radio" name="event_id" id="event_<?php echo $event['id']; ?>" value="<?php echo $event['id']; ?>" style="display: none;">
                    <?php endwhile; ?>
                </div>
                
                <button type="submit" class="btn-switch"><i class="bi bi-arrow-repeat"></i> Switch to Selected Event</button>
                <a href="dashboard.php" class="btn-cancel"><i class="bi bi-x-circle"></i> Cancel</a>
            </form>
        </div>
    </div>
    
    <script>
        function selectEvent(eventId) {
            // Remove selected class from all
            document.querySelectorAll('.event-item').forEach(item => {
                item.classList.remove('selected');
            });
            // Add selected class to clicked
            event.currentTarget.classList.add('selected');
            // Check the radio button
            document.getElementById('event_' + eventId).checked = true;
        }
    </script>
</body>
</html>