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

// Handle bulk action - Deactivate tickets
if (isset($_POST['bulk_deactivate']) && isset($_POST['ticket_ids'])) {
    $ticket_ids = $_POST['ticket_ids'];
    $count = 0;
    
    foreach ($ticket_ids as $ticket_id) {
        $stmt = $conn->prepare("UPDATE ticket_codes SET is_active = 0, deactivated_at = NOW(), deactivated_by = ? WHERE id = ? AND is_scanned = 0");
        $stmt->bind_param("si", $_SESSION['admin_username'], $ticket_id);
        $stmt->execute();
        $count += $stmt->affected_rows;
        $stmt->close();
    }
    
    $message = "$count ticket(s) deactivated successfully!";
    $messageType = "success";
}

// Handle bulk action - Activate tickets
if (isset($_POST['bulk_activate']) && isset($_POST['ticket_ids'])) {
    $ticket_ids = $_POST['ticket_ids'];
    $count = 0;
    
    foreach ($ticket_ids as $ticket_id) {
        $stmt = $conn->prepare("UPDATE ticket_codes SET is_active = 1, deactivated_at = NULL, deactivated_by = NULL WHERE id = ?");
        $stmt->bind_param("i", $ticket_id);
        $stmt->execute();
        $count += $stmt->affected_rows;
        $stmt->close();
    }
    
    $message = "$count ticket(s) activated successfully!";
    $messageType = "success";
}

// Handle single ticket toggle
if (isset($_GET['toggle']) && isset($_GET['id'])) {
    $ticket_id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT is_active FROM ticket_codes WHERE id = ?");
    $stmt->bind_param("i", $ticket_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result) {
        $new_status = $result['is_active'] ? 0 : 1;
        $stmt = $conn->prepare("UPDATE ticket_codes SET is_active = ?, deactivated_at = ?, deactivated_by = ? WHERE id = ?");
        $deactivated_at = $new_status == 0 ? date('Y-m-d H:i:s') : null;
        $deactivated_by = $new_status == 0 ? $_SESSION['admin_username'] : null;
        $stmt->bind_param("issi", $new_status, $deactivated_at, $deactivated_by, $ticket_id);
        $stmt->execute();
        $stmt->close();
        
        $message = "Ticket " . ($new_status ? "activated" : "deactivated") . " successfully!";
        $messageType = "success";
    }
    header("Location: tickets_dashboard.php");
    exit();
}

// Get filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$reservation_filter = isset($_GET['reservation_id']) ? sanitizeInput($_GET['reservation_id']) : '';

// Build query
$query = "SELECT t.*, r.name, r.phone, r.table_id, r.reservation_id 
          FROM ticket_codes t 
          JOIN reservations r ON t.reservation_id = r.reservation_id 
          WHERE 1=1";
$params = [];
$types = "";

if ($status_filter == 'active') {
    $query .= " AND t.is_active = 1";
} elseif ($status_filter == 'inactive') {
    $query .= " AND t.is_active = 0";
} elseif ($status_filter == 'used') {
    $query .= " AND t.is_scanned = 1";
} elseif ($status_filter == 'unused') {
    $query .= " AND t.is_scanned = 0 AND t.is_active = 1";
}

if ($search) {
    $query .= " AND (t.ticket_code LIKE ? OR r.name LIKE ? OR r.phone LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($reservation_filter) {
    $query .= " AND r.reservation_id LIKE ?";
    $params[] = "%{$reservation_filter}%";
    $types .= "s";
}

$query .= " ORDER BY t.created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$tickets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics
$stats = $conn->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN is_active = 1 AND is_scanned = 0 THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
    SUM(CASE WHEN is_scanned = 1 THEN 1 ELSE 0 END) as used
FROM ticket_codes")->fetch_assoc();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Management Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
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
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-number { font-size: 32px; font-weight: bold; }
        .stat-label { font-size: 14px; color: #64748b; margin-top: 5px; }
        
        .filters-bar {
            background: white;
            border-radius: 20px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .search-box { display: flex; gap: 10px; flex-wrap: wrap; }
        .search-box input, .search-box select {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 40px;
            font-size: 14px;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .btn-primary { background: #4f46e5; color: white; }
        .btn-primary:hover { background: #4338ca; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }
        .badge-used { background: #fef3c7; color: #92400e; }
        
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
        
        .bulk-actions {
            background: #f8fafc;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
            display: none;
        }
        .bulk-actions.active { display: flex; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .filters-bar { flex-direction: column; }
            .search-box { width: 100%; }
            .search-box input, .search-box select { flex: 1; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="navbar">
            <h1><i class="bi bi-ticket-perforated"></i> Ticket Management</h1>
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
                <div class="stat-number" style="color: #4f46e5;"><?php echo $stats['total']; ?></div>
                <div class="stat-label">Total Tickets</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #10b981;"><?php echo $stats['active']; ?></div>
                <div class="stat-label">Active Tickets</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #f59e0b;"><?php echo $stats['used']; ?></div>
                <div class="stat-label">Used Tickets</div>
            </div>
            <div class="stat-card">
                <div class="stat-number" style="color: #ef4444;"><?php echo $stats['inactive']; ?></div>
                <div class="stat-label">Deactivated</div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="filters-bar">
            <div class="search-box">
                <input type="text" id="search" placeholder="Search ticket code, customer..." value="<?php echo htmlspecialchars($search); ?>">
                <select id="statusFilter">
                    <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Tickets</option>
                    <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active Only</option>
                    <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Deactivated</option>
                    <option value="used" <?php echo $status_filter == 'used' ? 'selected' : ''; ?>>Used</option>
                    <option value="unused" <?php echo $status_filter == 'unused' ? 'selected' : ''; ?>>Unused</option>
                </select>
                <input type="text" id="reservationFilter" placeholder="Reservation ID" value="<?php echo htmlspecialchars($reservation_filter); ?>">
                <button onclick="applyFilters()" class="btn btn-primary">Filter</button>
                <a href="tickets_dashboard.php" class="btn btn-secondary">Reset</a>
            </div>
            <div>
                <button onclick="selectAll()" class="btn btn-secondary btn-sm">Select All</button>
                <button onclick="clearSelection()" class="btn btn-secondary btn-sm">Clear</button>
            </div>
        </div>
        
        <!-- Bulk Actions -->
        <div id="bulkActions" class="bulk-actions">
            <span id="selectedCount">0</span> tickets selected
            <button onclick="bulkDeactivate()" class="btn btn-danger btn-sm">Deactivate Selected</button>
            <button onclick="bulkActivate()" class="btn btn-success btn-sm">Activate Selected</button>
            <button onclick="clearSelection()" class="btn btn-secondary btn-sm">Cancel</button>
        </div>
        
        <!-- Tickets Table -->
        <div class="table-container">
            <form method="POST" id="bulkForm">
                <input type="hidden" name="ticket_ids" id="selectedTicketsInput">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 40px;"><input type="checkbox" id="selectAllCheckbox" onclick="toggleSelectAll()"></th>
                            <th>Ticket Code</th>
                            <th>Customer</th>
                            <th>Type</th>
                            <th>Reservation ID</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </td>
                    </thead>
                    <tbody>
                        <?php foreach ($tickets as $ticket): ?>
                        <tr>
                            <td><input type="checkbox" class="ticket-checkbox" value="<?php echo $ticket['id']; ?>"></td>
                            <td><code><?php echo htmlspecialchars($ticket['ticket_code']); ?></code></td>
                            <td>
                                <strong><?php echo htmlspecialchars($ticket['name']); ?></strong><br>
                                <small><?php echo htmlspecialchars($ticket['phone']); ?></small>
                            </td>
                            <td><?php echo ucfirst($ticket['guest_type']); ?> #<?php echo str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT); ?> </d>
                            <td><?php echo htmlspecialchars($ticket['reservation_id']); ?> </d>
                            <td>
                                <?php if ($ticket['is_scanned']): ?>
                                    <span class="badge badge-used">Used</span>
                                <?php elseif ($ticket['is_active']): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Deactivated</span>
                                <?php endif; ?>
                             </d>
                            <td><?php echo date('M d, H:i', strtotime($ticket['created_at'])); ?> </d>
                            <td>
                                <div class="btn-group" style="display: flex; gap: 5px;">
                                    <a href="generate_ticket_image.php?ticket_code=<?php echo urlencode($ticket['ticket_code']); ?>" target="_blank" class="btn btn-sm btn-primary">
                                        <i class="bi bi-image"></i>
                                    </a>
                                    <a href="print_ticket.php?ticket_code=<?php echo urlencode($ticket['ticket_code']); ?>" target="_blank" class="btn btn-sm btn-secondary">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <?php if (!$ticket['is_scanned']): ?>
                                        <?php if ($ticket['is_active']): ?>
                                            <a href="?toggle=1&id=<?php echo $ticket['id']; ?>" class="btn btn-sm btn-warning" onclick="return confirm('Deactivate this ticket?')">
                                                <i class="bi bi-ban"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="?toggle=1&id=<?php echo $ticket['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Activate this ticket?')">
                                                <i class="bi bi-check-circle"></i>
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                             </d>
                         </tr>
                        <?php endforeach; ?>
                        <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 60px;">
                                <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.5;"></i>
                                <p>No tickets found</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </form>
        </div>
    </div>
    
    <script>
        let selectedTickets = new Set();
        
        function toggleSelectAll() {
            const checkboxes = document.querySelectorAll('.ticket-checkbox');
            const selectAll = document.getElementById('selectAllCheckbox');
            
            checkboxes.forEach(cb => {
                cb.checked = selectAll.checked;
                if (selectAll.checked) {
                    selectedTickets.add(cb.value);
                } else {
                    selectedTickets.delete(cb.value);
                }
            });
            updateBulkActions();
        }
        
        function selectAll() {
            const checkboxes = document.querySelectorAll('.ticket-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = true;
                selectedTickets.add(cb.value);
            });
            document.getElementById('selectAllCheckbox').checked = true;
            updateBulkActions();
        }
        
        function clearSelection() {
            const checkboxes = document.querySelectorAll('.ticket-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = false;
            });
            selectedTickets.clear();
            document.getElementById('selectAllCheckbox').checked = false;
            updateBulkActions();
        }
        
        function updateBulkActions() {
            const count = selectedTickets.size;
            document.getElementById('selectedCount').innerText = count;
            document.getElementById('bulkActions').classList.toggle('active', count > 0);
        }
        
        function bulkDeactivate() {
            if (selectedTickets.size === 0) {
                alert('No tickets selected');
                return;
            }
            if (confirm(`Deactivate ${selectedTickets.size} ticket(s)?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ticket_ids';
                input.value = JSON.stringify(Array.from(selectedTickets));
                form.appendChild(input);
                
                const btn = document.createElement('input');
                btn.type = 'hidden';
                btn.name = 'bulk_deactivate';
                btn.value = '1';
                form.appendChild(btn);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function bulkActivate() {
            if (selectedTickets.size === 0) {
                alert('No tickets selected');
                return;
            }
            if (confirm(`Activate ${selectedTickets.size} ticket(s)?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ticket_ids';
                input.value = JSON.stringify(Array.from(selectedTickets));
                form.appendChild(input);
                
                const btn = document.createElement('input');
                btn.type = 'hidden';
                btn.name = 'bulk_activate';
                btn.value = '1';
                form.appendChild(btn);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function applyFilters() {
            const search = document.getElementById('search').value;
            const status = document.getElementById('statusFilter').value;
            const reservation = document.getElementById('reservationFilter').value;
            let url = `tickets_dashboard.php?search=${encodeURIComponent(search)}&status=${status}&reservation_id=${encodeURIComponent(reservation)}`;
            window.location.href = url;
        }
        
        document.querySelectorAll('.ticket-checkbox').forEach(cb => {
            cb.addEventListener('change', function() {
                if (this.checked) {
                    selectedTickets.add(this.value);
                } else {
                    selectedTickets.delete(this.value);
                }
                updateBulkActions();
            });
        });
        
        document.getElementById('search')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyFilters();
        });
        document.getElementById('reservationFilter')?.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') applyFilters();
        });
    </script>
</body>
</html>