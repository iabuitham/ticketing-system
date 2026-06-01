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

// Get current floor plan
$stmt = $conn->prepare("SELECT floor_plan_image FROM event_settings WHERE id = ?");
$stmt->bind_param("i", $selected_event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();
$currentImage = $event['floor_plan_image'] ?? '';

// Handle upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['floor_plan'])) {
    $password = $_POST['password'] ?? '';
    if ($password !== 'AdminDelete2026') {
        $message = "Invalid password!";
        $messageType = "error";
    } else {
        $file = $_FILES['floor_plan'];
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($ext, $allowed)) {
            $message = "Invalid file type. Allowed: JPG, PNG, GIF, WEBP";
            $messageType = "error";
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $message = "Upload error: " . $file['error'];
            $messageType = "error";
        } else {
            $uploadDir = '../uploads/floor_plans/';
            if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $filename = 'floor_plan_' . $selected_event_id . '_' . time() . '.' . $ext;
            $uploadPath = $uploadDir . $filename;
            $dbPath = 'uploads/floor_plans/' . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                // Delete old image if exists
                if ($currentImage && file_exists('../' . $currentImage)) {
                    unlink('../' . $currentImage);
                }
                
                $updateStmt = $conn->prepare("UPDATE event_settings SET floor_plan_image = ? WHERE id = ?");
                $updateStmt->bind_param("si", $dbPath, $selected_event_id);
                $updateStmt->execute();
                $updateStmt->close();
                
                $message = "Floor plan uploaded successfully!";
                $messageType = "success";
                $currentImage = $dbPath;
            } else {
                $message = "Failed to save file";
                $messageType = "error";
            }
        }
    }
}

// Handle delete
if (isset($_GET['delete']) && $currentImage) {
    $password = $_GET['password'] ?? '';
    if ($password === 'AdminDelete2026') {
        if (file_exists('../' . $currentImage)) {
            unlink('../' . $currentImage);
        }
        $updateStmt = $conn->prepare("UPDATE event_settings SET floor_plan_image = NULL WHERE id = ?");
        $updateStmt->bind_param("i", $selected_event_id);
        $updateStmt->execute();
        $updateStmt->close();
        $currentImage = '';
        $message = "Floor plan deleted successfully!";
        $messageType = "success";
    } else {
        $message = "Invalid password!";
        $messageType = "error";
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Floor Plan Management</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        body.dark-mode { background: #0f172a; color: #e2e8f0; }
        .container { max-width: 1000px; margin: 0 auto; }
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
        .card {
            background: white;
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        body.dark-mode .card { background: #1e293b; }
        .card-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        body.dark-mode .card-header { border-bottom-color: #334155; }
        .floor-plan-preview {
            text-align: center;
            margin-bottom: 20px;
        }
        .floor-plan-preview img {
            max-width: 100%;
            max-height: 500px;
            border-radius: 16px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
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
        .btn-primary { background: #4f46e5; color: white; }
        .btn-primary:hover { background: #4338ca; transform: translateY(-1px); }
        .btn-secondary { background: #64748b; color: white; }
        .btn-secondary:hover { background: #475569; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #334155;
        }
        body.dark-mode .form-group label { color: #cbd5e1; }
        .form-group input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-size: 14px;
        }
        body.dark-mode .form-group input[type="file"] {
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
        .event-badge {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: white;
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        @media (max-width: 768px) {
            .navbar { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="navbar">
        <h1><i class="bi bi-map"></i> Floor Plan Management</h1>
        <div>
            <div class="event-badge">
                <i class="bi bi-calendar-event"></i>
                <?php echo htmlspecialchars($_SESSION['selected_event_name'] ?? 'No Event Selected'); ?>
            </div>
            <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <i class="bi bi-<?php echo $messageType == 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?>"></i>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-image"></i> Current Floor Plan</h2>
        </div>
        <?php if ($currentImage): ?>
            <div class="floor-plan-preview">
                <img src="../<?php echo $currentImage; ?>" alt="Floor Plan">
            </div>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <a href="floor_plan.php?delete=1&password=AdminDelete2026" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete the floor plan?')">
                    <i class="bi bi-trash"></i> Delete Floor Plan
                </a>
            </div>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; background: #f8fafc; border-radius: 16px;">
                <i class="bi bi-map" style="font-size: 64px; opacity: 0.3;"></i>
                <p style="margin-top: 15px;">No floor plan uploaded yet.</p>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-upload"></i> Upload New Floor Plan</h2>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label><i class="bi bi-image"></i> Floor Plan Image</label>
                <input type="file" name="floor_plan" accept="image/jpeg,image/png,image/gif,image/webp" required>
                <small style="color: #64748b; display: block; margin-top: 5px;">Supported formats: JPG, PNG, GIF, WEBP. Max size: 5MB</small>
            </div>
            <div class="form-group">
                <label><i class="bi bi-lock"></i> Admin Password</label>
                <input type="password" name="password" required placeholder="Enter admin password">
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-upload"></i> Upload Floor Plan</button>
        </form>
    </div>

    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-info-circle"></i> How to Use</h2>
        </div>
        <ul style="margin-left: 20px; color: #64748b; line-height: 1.8;">
            <li>Upload a clear image of your venue floor plan showing table numbers/locations.</li>
            <li>Customers without assigned tables will receive this floor plan via WhatsApp when you click "Send Floor Plan".</li>
            <li>You can assign tables to reservations from the dashboard table column.</li>
            <li>The floor plan will be visible to customers to help them locate available tables.</li>
        </ul>
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