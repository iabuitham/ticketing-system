<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$conn = getConnection();

// Get all active events for dropdown
$events = [];
$result = $conn->query("SELECT id, event_name, event_date, venue, status FROM event_settings WHERE status != 'completed' ORDER BY event_date ASC");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $event_id = intval($_POST['event_id'] ?? 0);
    
    // Default admin credentials (you should move these to database)
    $valid_username = 'admin';
    $valid_password = 'admin123'; // Change this!
    
    if ($username === $valid_username && $password === $valid_password) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['selected_event_id'] = $event_id;
        
        // Get event details for session
        if ($event_id > 0) {
            $stmt = $conn->prepare("SELECT * FROM event_settings WHERE id = ?");
            $stmt->bind_param("i", $event_id);
            $stmt->execute();
            $event = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($event) {
                $_SESSION['selected_event_name'] = $event['event_name'];
                $_SESSION['selected_event_date'] = $event['event_date'];
                $_SESSION['event_ticket_prices'] = [
                    'adult' => $event['ticket_price_adult'] ?? 10,
                    'teen' => $event['ticket_price_teen'] ?? 10,
                    'kid' => $event['ticket_price_kid'] ?? 0
                ];
            }
        }
        
        header('Location: dashboard.php');
        exit();
    } else {
        $error = 'Invalid username or password!';
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            max-width: 450px;
            width: 100%;
        }
        
        .login-card {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .login-header {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .login-header h1 {
            font-size: 1.8rem;
            margin-bottom: 10px;
        }
        
        .login-header p {
            opacity: 0.9;
            font-size: 0.9rem;
        }
        
        .login-body {
            padding: 30px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #334155;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .btn-login {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
        }
        
        .error-message {
            background: #fee2e2;
            color: #991b1b;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .event-info {
            background: #f1f5f9;
            padding: 12px;
            border-radius: 12px;
            margin-top: 20px;
            font-size: 12px;
            color: #64748b;
        }
        
        .event-info i {
            margin-right: 5px;
        }
        
        @media (max-width: 768px) {
            .login-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <h1><i class="bi bi-ticket-perforated"></i> Ticketing System</h1>
                <p>Admin Login</p>
            </div>
            <div class="login-body">
                <?php if ($error): ?>
                    <div class="error-message">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <?php echo $error; ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label><i class="bi bi-building"></i> Select Event</label>
                        <select name="event_id" required>
                            <option value="">-- Select an event --</option>
                            <?php foreach ($events as $event): ?>
                                <option value="<?php echo $event['id']; ?>">
                                    <?php echo htmlspecialchars($event['event_name']); ?> - 
                                    <?php echo date('M d, Y', strtotime($event['event_date'])); ?> 
                                    (<?php echo ucfirst($event['status']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-person"></i> Username</label>
                        <input type="text" name="username" required placeholder="Enter username">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="bi bi-lock"></i> Password</label>
                        <input type="password" name="password" required placeholder="Enter password">
                    </div>
                    
                    <button type="submit" class="btn-login">
                        <i class="bi bi-box-arrow-in-right"></i> Login to Dashboard
                    </button>
                </form>
                
                <div class="event-info">
                    <i class="bi bi-info-circle"></i> Note: Select the event you want to manage. Ticket prices will be based on the selected event.
                </div>
            </div>
        </div>
    </div>
</body>
</html>