<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Refresh table availability
updateTableAvailability();

$message = "Table availability has been refreshed!";

// Get current table status
$conn = getConnection();
$tables = $conn->query("SELECT table_number, section, status, is_active, is_used FROM tables ORDER BY table_number")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refresh Tables - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { font-family: Arial, sans-serif; background: #f0f2f5; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; }
        .card { background: white; border-radius: 16px; padding: 24px; margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #ddd; }
        .available { color: #10b981; font-weight: bold; }
        .used { color: #ef4444; font-weight: bold; }
        .btn { padding: 10px 20px; background: #4f46e5; color: white; text-decoration: none; border-radius: 8px; display: inline-block; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1><i class="bi bi-arrow-repeat"></i> Table Availability</h1>
            <p><?php echo $message; ?></p>
            
            <h2>Current Table Status</h2>
            <table>
                <thead>
                    <tr>
                        <th>Table</th>
                        <th>Section</th>
                        <th>Status</th>
                        <th>Active</th>
                        <th>Currently Used</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tables as $table): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($table['table_number']); ?></d>
                            <td><?php echo htmlspecialchars($table['section'] ?? '-'); ?></d>
                            <td><?php echo ucfirst($table['status']); ?></d>
                            <td><?php echo $table['is_active'] ? 'Yes' : 'No'; ?></d>
                            <td><?php if ($table['is_used']): ?>
                                <span class="used">🔴 In Use</span>
                            <?php else: ?>
                                <span class="available">🟢 Available</span>
                            <?php endif; ?>
                        </d>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <br>
            <a href="create_reservation.php" class="btn">Create Reservation</a>
            <a href="dashboard.php" class="btn" style="background: #64748b;">Back to Dashboard</a>
        </div>
    </div>
</body>
</html>