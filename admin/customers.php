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

// Get filters
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';
$vip_filter = isset($_GET['vip']) ? $_GET['vip'] : '';

// Build query
$query = "SELECT * FROM customers WHERE 1=1";
$params = [];
$types = "";

if ($search) {
    $query .= " AND (name LIKE ? OR phone LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ss";
}

if ($vip_filter == 'vip') {
    $query .= " AND is_vip = 1";
} elseif ($vip_filter == 'regular') {
    $query .= " AND is_vip = 0";
}

$query .= " ORDER BY total_spent DESC, total_visits DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get statistics with null handling
$statsResult = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_vip = 1 THEN 1 ELSE 0 END) as vip_count,
        SUM(COALESCE(total_spent, 0)) as total_revenue,
        AVG(COALESCE(total_spent, 0)) as avg_spent,
        SUM(COALESCE(total_visits, 0)) as total_visits
    FROM customers
");
$stats = $statsResult->fetch_assoc();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Management - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        body.dark-mode { background: #0f172a; color: #e2e8f0; }
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
        body.dark-mode .navbar { background: #1e293b; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
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
        body.dark-mode .stat-card { background: #1e293b; }
        .stat-number { font-size: 28px; font-weight: bold; color: #4f46e5; }
        .stat-label { font-size: 13px; color: #64748b; margin-top: 5px; }
        .card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
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
        .btn-primary:hover { background: #4338ca; transform: translateY(-1px); }
        .btn-success { background: #10b981; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; }
        body.dark-mode th { background: #0f172a; color: #94a3b8; }
        body.dark-mode td { border-bottom-color: #334155; }
        .badge-vip {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
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
        body.dark-mode .filters-bar { background: #1e293b; }
        .search-box {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .search-box input, .search-box select {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 40px;
            font-size: 14px;
        }
        body.dark-mode .search-box input, body.dark-mode .search-box select {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
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
        body.dark-mode .modal-container { background: #1e293b; }
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
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #334155;
        }
        body.dark-mode .form-group label { color: #cbd5e1; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-size: 14px;
        }
        body.dark-mode .form-group input, body.dark-mode .form-group select, body.dark-mode .form-group textarea {
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
        @media (max-width: 1024px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .navbar { flex-direction: column; text-align: center; }
            .filters-bar { flex-direction: column; }
            .search-box { width: 100%; }
            .search-box input, .search-box select { flex: 1; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="navbar">
        <h1><i class="bi bi-people-fill"></i> Customer Management</h1>
        <div>
            <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
            <a href="logout.php" class="btn btn-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($stats['total'] ?? 0); ?></div>
            <div class="stat-label">Total Customers</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($stats['vip_count'] ?? 0); ?></div>
            <div class="stat-label">VIP Customers</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($stats['total_visits'] ?? 0); ?></div>
            <div class="stat-label">Total Visits</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($stats['total_revenue'] ?? 0, 2); ?> JD</div>
            <div class="stat-label">Total Revenue</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo number_format($stats['avg_spent'] ?? 0, 2); ?> JD</div>
            <div class="stat-label">Avg per Customer</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-bar">
        <div class="search-box">
            <input type="text" id="search" placeholder="Search by name or phone..." value="<?php echo htmlspecialchars($search); ?>">
            <select id="vipFilter">
                <option value="all" <?php echo $vip_filter == 'all' ? 'selected' : ''; ?>>All Customers</option>
                <option value="vip" <?php echo $vip_filter == 'vip' ? 'selected' : ''; ?>>VIP Only</option>
                <option value="regular" <?php echo $vip_filter == 'regular' ? 'selected' : ''; ?>>Regular Only</option>
            </select>
            <button onclick="applyFilters()" class="btn btn-primary">Filter</button>
            <a href="customers.php" class="btn btn-secondary">Reset</a>
        </div>
        <div>
            <button onclick="exportCustomers()" class="btn btn-success"><i class="bi bi-file-excel"></i> Export CSV</button>
        </div>
    </div>

    <!-- Customers Table -->
    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-list-ul"></i> All Customers</h2>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Phone</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Visits</th>
                        <th>Total Spent</th>
                        <th>Last Visit</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($customer['phone']); ?></strong></td>
                        <td><?php echo htmlspecialchars($customer['name']); ?></td>
                        <td><?php echo htmlspecialchars($customer['email'] ?: '-'); ?></td>
                        <td><?php echo intval($customer['total_visits']); ?> visits</td>
                        <td><strong><?php echo number_format(floatval($customer['total_spent'] ?? 0), 2); ?> JD</strong></td>
                        <td><?php echo $customer['last_visit_date'] ? date('M d, Y', strtotime($customer['last_visit_date'])) : '-'; ?></td>
                        <td>
                            <?php if ($customer['is_vip']): ?>
                                <span class="badge-vip"><i class="bi bi-star-fill"></i> VIP</span>
                            <?php else: ?>
                                <span class="badge" style="background: #e2e8f0; padding: 4px 10px; border-radius: 20px;">Regular</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button onclick="viewCustomer(<?php echo $customer['id']; ?>, '<?php echo htmlspecialchars($customer['name']); ?>', '<?php echo htmlspecialchars($customer['phone']); ?>')" class="btn btn-sm btn-primary">
                                <i class="bi bi-eye"></i>
                            </button>
                            <button onclick="toggleVip(<?php echo $customer['id']; ?>, <?php echo $customer['is_vip']; ?>)" class="btn btn-sm btn-warning">
                                <i class="bi bi-star"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 60px;">
                            <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.5;"></i>
                            <p>No customers found</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Customer View Modal -->
<div id="customerModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-person-circle"></i> Customer Details</h3>
            <button type="button" class="modal-close" onclick="closeCustomerModal()">&times;</button>
        </div>
        <div class="modal-body" id="customerDetails">
            <!-- Loaded via AJAX -->
        </div>
    </div>
</div>

<script>
    function applyFilters() {
        const search = document.getElementById('search').value;
        const vip = document.getElementById('vipFilter').value;
        window.location.href = `customers.php?search=${encodeURIComponent(search)}&vip=${vip}`;
    }
    
    document.getElementById('search')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') applyFilters();
    });
    
    function viewCustomer(id, name, phone) {
        const modal = document.getElementById('customerModal');
        const detailsDiv = document.getElementById('customerDetails');
        
        detailsDiv.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="bi bi-hourglass-split"></i> Loading...</div>';
        modal.classList.add('active');
        
        fetch(`ajax_customer_details.php?id=${id}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    detailsDiv.innerHTML = `
                        <div style="margin-bottom: 15px;">
                            <strong><i class="bi bi-person"></i> Name:</strong> ${escapeHtml(data.customer.name)}<br>
                            <strong><i class="bi bi-telephone"></i> Phone:</strong> ${escapeHtml(data.customer.phone)}<br>
                            <strong><i class="bi bi-envelope"></i> Email:</strong> ${escapeHtml(data.customer.email || 'Not provided')}<br>
                            <strong><i class="bi bi-calendar"></i> First Visit:</strong> ${data.customer.first_visit_date || '-'}<br>
                            <strong><i class="bi bi-calendar-check"></i> Last Visit:</strong> ${data.customer.last_visit_date || '-'}<br>
                            <strong><i class="bi bi-graph-up"></i> Total Visits:</strong> ${data.customer.total_visits || 0}<br>
                            <strong><i class="bi bi-currency-dollar"></i> Total Spent:</strong> ${parseFloat(data.customer.total_spent || 0).toFixed(2)} JD<br>
                            <strong><i class="bi bi-star"></i> VIP Status:</strong> ${data.customer.is_vip ? '✅ VIP Member' : 'Regular Customer'}
                        </div>
                        <hr>
                        <h4 style="margin: 15px 0 10px 0;">Recent Reservations</h4>
                        <div style="max-height: 300px; overflow-y: auto;">
                            ${data.reservations.map(r => `
                                <div style="padding: 10px; border-bottom: 1px solid #e2e8f0;">
                                    <strong>${escapeHtml(r.reservation_id)}</strong><br>
                                    Date: ${r.created_at}<br>
                                    Amount: ${parseFloat(r.total_amount || 0).toFixed(2)} JD<br>
                                    Status: ${r.status}
                                </div>
                            `).join('') || '<p>No reservations found</p>'}
                        </div>
                    `;
                } else {
                    detailsDiv.innerHTML = '<div class="alert alert-error">Error loading customer details</div>';
                }
            })
            .catch(error => {
                detailsDiv.innerHTML = '<div class="alert alert-error">Error: ' + error.message + '</div>';
            });
    }
    
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }
    
    function closeCustomerModal() {
        document.getElementById('customerModal').classList.remove('active');
    }
    
    function toggleVip(id, currentStatus) {
        const newStatus = currentStatus ? 0 : 1;
        const action = newStatus ? 'make VIP' : 'remove VIP';
        
        if (confirm(`Are you sure you want to ${action} this customer?`)) {
            fetch('ajax_toggle_vip.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id, is_vip: newStatus })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => alert('Error: ' + error.message));
        }
    }
    
    function exportCustomers() {
        window.location.href = 'export_customers.php';
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById('customerModal');
        if (event.target === modal) closeCustomerModal();
    }
</script>
</body>
</html>