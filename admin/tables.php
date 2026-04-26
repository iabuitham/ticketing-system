<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();
$message = '';
$messageType = '';

// Handle Add Table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_table'])) {
    $table_number = strtoupper(sanitizeInput($_POST['table_number']));
    $section = sanitizeInput($_POST['section']);
    $status = sanitizeInput($_POST['status']);
    
    // Check if table already exists
    $check = $conn->prepare("SELECT id FROM tables WHERE table_number = ?");
    $check->bind_param("s", $table_number);
    $check->execute();
    $check->store_result();
    
    if ($check->num_rows > 0) {
        $message = "Table number already exists!";
        $messageType = "error";
    } else {
        $stmt = $conn->prepare("INSERT INTO tables (table_number, section, status) VALUES (?, ?, ?)");
        $stmt->bind_param("sss", $table_number, $section, $status);
        
        if ($stmt->execute()) {
            $message = "Table added successfully!";
            $messageType = "success";
        } else {
            $message = "Error adding table: " . $conn->error;
            $messageType = "error";
        }
        $stmt->close();
    }
    $check->close();
}

// Handle Edit Table
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_table'])) {
    $table_id = intval($_POST['table_id']);
    $table_number = strtoupper(sanitizeInput($_POST['table_number']));
    $section = sanitizeInput($_POST['section']);
    $status = sanitizeInput($_POST['status']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $stmt = $conn->prepare("UPDATE tables SET table_number = ?, section = ?, status = ?, is_active = ? WHERE id = ?");
    $stmt->bind_param("sssii", $table_number, $section, $status, $is_active, $table_id);
    
    if ($stmt->execute()) {
        $message = "Table updated successfully!";
        $messageType = "success";
    } else {
        $message = "Error updating table: " . $conn->error;
        $messageType = "error";
    }
    $stmt->close();
}

// Handle Delete Table
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $table_id = intval($_GET['delete']);
    
    // Check if table is used in any reservation
    $check = $conn->prepare("SELECT COUNT(*) as count FROM reservations WHERE table_id = (SELECT table_number FROM tables WHERE id = ?)");
    $check->bind_param("i", $table_id);
    $check->execute();
    $result = $check->get_result();
    $used = $result->fetch_assoc()['count'];
    $check->close();
    
    if ($used > 0) {
        $message = "Cannot delete table - it is used in existing reservations!";
        $messageType = "error";
    } else {
        $stmt = $conn->prepare("DELETE FROM tables WHERE id = ?");
        $stmt->bind_param("i", $table_id);
        
        if ($stmt->execute()) {
            $message = "Table deleted successfully!";
            $messageType = "success";
        } else {
            $message = "Error deleting table: " . $conn->error;
            $messageType = "error";
        }
        $stmt->close();
    }
}

// Get all tables
$tables = $conn->query("SELECT * FROM tables ORDER BY table_number")->fetch_all(MYSQLI_ASSOC);

// Get table statistics
$stats = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) as available,
    SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) as reserved,
    SUM(CASE WHEN status = 'occupied' THEN 1 ELSE 0 END) as occupied,
    SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active
FROM tables")->fetch_assoc();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Table Management - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 15px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 15px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
        }
        
        .stat-label {
            font-size: 12px;
            color: #64748b;
            margin-top: 5px;
        }
        
        .card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
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
        .btn-primary:hover { background: #4338ca; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #334155;
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
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
        
        th {
            background: #f8fafc;
            font-weight: 600;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-available { background: #d1fae5; color: #065f46; }
        .badge-reserved { background: #fef3c7; color: #92400e; }
        .badge-occupied { background: #fee2e2; color: #991b1b; }
        .badge-maintenance { background: #f1f5f9; color: #475569; }
        
        .alert {
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success { background: #d1fae5; color: #065f46; }
        .alert-error { background: #fee2e2; color: #991b1b; }
        
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
        
        .modal-overlay.active { display: flex; }
        
        .modal-container {
            background: white;
            border-radius: 24px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 20px 24px;
            background: #4f46e5;
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
        
        .modal-body { padding: 24px; }
        .modal-buttons { padding: 0 24px 24px 24px; display: flex; justify-content: flex-end; gap: 10px; }
        
        .table-container { overflow-x: auto; }
        
        @media (max-width: 1024px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); }
        }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .form-row { grid-template-columns: 1fr; }
            .navbar { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="navbar">
            <h1><i class="bi bi-grid-3x3-gap-fill"></i> Table Management</h1>
            <div>
                <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
                <a href="logout.php" class="btn btn-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="bi bi-<?php echo $messageType == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Tables</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #10b981;"><?php echo $stats['available']; ?></div>
                <div class="stat-label">Available</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #f59e0b;"><?php echo $stats['reserved']; ?></div>
                <div class="stat-label">Reserved</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #ef4444;"><?php echo $stats['occupied']; ?></div>
                <div class="stat-label">Occupied</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #64748b;"><?php echo $stats['maintenance']; ?></div>
                <div class="stat-label">Maintenance</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $stats['active']; ?></div>
                <div class="stat-label">Active Tables</div>
            </div>
        </div>
        
        <!-- Add Table Button -->
        <div class="card">
            <div class="card-header">
                <h2><i class="bi bi-plus-circle"></i> Add New Table</h2>
                <button onclick="openAddModal()" class="btn btn-primary">
                    <i class="bi bi-plus-lg"></i> Add Table
                </button>
            </div>
        </div>
        
        <!-- Tables List -->
        <div class="card">
            <div class="card-header">
                <h2><i class="bi bi-list-ul"></i> All Tables</h2>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Table Number</th>
                            <th>Section</th>
                            <th>Status</th>
                            <th>Active</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tables as $table): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($table['table_number']); ?></strong></td>
                                <td><?php echo htmlspecialchars($table['section'] ?? '-'); ?></d>
                                <td>
                                    <span class="badge badge-<?php echo $table['status']; ?>">
                                        <?php echo ucfirst($table['status']); ?>
                                    </span>
                                </d>
                                <td>
                                    <?php if ($table['is_active']): ?>
                                        <span class="badge" style="background: #d1fae5; color: #065f46;">Active</span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #fee2e2; color: #991b1b;">Inactive</span>
                                    <?php endif; ?>
                                </d>
                                <td>
                                    <button onclick="editTable(<?php echo $table['id']; ?>)" class="btn btn-sm btn-warning">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button onclick="deleteTable(<?php echo $table['id']; ?>)" class="btn btn-sm btn-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </d>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($tables)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 40px;">
                                    <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.3;"></i>
                                    <p>No tables found. Click "Add Table" to create one.</p>
                                </d>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Modal -->
    <div id="tableModal" class="modal-overlay">
        <div class="modal-container">
            <form method="POST" id="tableForm">
                <div class="modal-header">
                    <h3 id="modalTitle"><i class="bi bi-plus-circle"></i> Add New Table</h3>
                    <button type="button" class="modal-close" onclick="closeModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="table_id" id="tableId" value="0">
                    <div class="form-group">
                        <label>Table Number *</label>
                        <input type="text" name="table_number" id="tableNumber" required placeholder="e.g., A1, B2, VIP1">
                    </div>
                    <div class="form-group">
                        <label>Section</label>
                        <select name="section" id="section">
                            <option value="">Select Section</option>
                            <option value="Main Hall">Main Hall</option>
                            <option value="VIP Section">VIP Section</option>
                            <option value="Balcony">Balcony</option>
                            <option value="Outdoor">Outdoor</option>
                            <option value="Private Room">Private Room</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="status">
                            <option value="available">Available</option>
                            <option value="reserved">Reserved</option>
                            <option value="occupied">Occupied</option>
                            <option value="maintenance">Maintenance</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>
                            <input type="checkbox" name="is_active" id="isActive" value="1" checked>
                            Active (Show in dropdown)
                        </label>
                    </div>
                </div>
                <div class="modal-buttons">
                    <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" name="add_table" id="submitBtn" class="btn btn-primary">Add Table</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openAddModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="bi bi-plus-circle"></i> Add New Table';
            document.getElementById('tableId').value = '0';
            document.getElementById('tableNumber').value = '';
            document.getElementById('section').value = '';
            document.getElementById('status').value = 'available';
            document.getElementById('isActive').checked = true;
            document.getElementById('submitBtn').name = 'add_table';
            document.getElementById('tableModal').classList.add('active');
        }
        
        function editTable(tableId) {
            fetch(`get_table.php?id=${tableId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil"></i> Edit Table';
                        document.getElementById('tableId').value = data.table.id;
                        document.getElementById('tableNumber').value = data.table.table_number;
                        document.getElementById('section').value = data.table.section;
                        document.getElementById('status').value = data.table.status;
                        document.getElementById('isActive').checked = data.table.is_active == 1;
                        document.getElementById('submitBtn').name = 'edit_table';
                        document.getElementById('tableModal').classList.add('active');
                    }
                })
                .catch(error => {
                    alert('Error loading table data');
                });
        }
        
        function deleteTable(tableId) {
            if (confirm('Are you sure you want to delete this table? This cannot be undone and the table must not be used in any reservation.')) {
                window.location.href = `tables.php?delete=${tableId}&id=${tableId}`;
            }
        }
        
        function closeModal() {
            document.getElementById('tableModal').classList.remove('active');
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('tableModal');
            if (event.target === modal) {
                closeModal();
            }
        }
    </script>
</body>
</html>