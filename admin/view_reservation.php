<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/language.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$reservation_id = isset($_GET['id']) ? sanitizeInput($_GET['id']) : '';

if (empty($reservation_id)) {
    header('Location: dashboard.php');
    exit();
}

$conn = getConnection();

// Get reservation details with total paid from split payments
$query = "SELECT r.*, 
 COALESCE((SELECT SUM(amount) FROM split_payments WHERE reservation_id = r.reservation_id), 0) as total_paid
 FROM reservations r 
 WHERE r.reservation_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reservation) {
    header('Location: dashboard.php');
    exit();
}

$total_paid = floatval($reservation['total_paid']);
$total_amount = floatval($reservation['total_amount']);
$amount_due = $total_amount - $total_paid;
$currency = getSetting('currency', 'JOD');

// Get payment splits for this reservation
$stmt = $conn->prepare("SELECT * FROM split_payments WHERE reservation_id = ? ORDER BY payment_date DESC");
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo getDirection(); ?>">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('view_reservation'); ?> - <?php echo t('ticketing_system'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

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

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Navigation */
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
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        body.dark-mode .navbar {
            background: #1e293b;
        }

        .navbar h1 {
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 8px;
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

        .btn-primary {
            background: #4f46e5;
            color: white;
        }

        .btn-primary:hover {
            background: #4338ca;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: #64748b;
            color: white;
        }

        .btn-secondary:hover {
            background: #475569;
        }

        .btn-success {
            background: #10b981;
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        .btn-warning {
            background: #f59e0b;
            color: white;
        }

        .btn-danger {
            background: #ef4444;
            color: white;
        }

        .btn-info {
            background: #0ea5e9;
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-logout {
            background: #ef4444;
            color: white;
            padding: 8px 20px;
            border-radius: 40px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-logout:hover {
            background: #dc2626;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 20px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
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

        /* Info Grid */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .info-item {
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
        }

        body.dark-mode .info-item {
            background: #0f172a;
        }

        .info-label {
            font-size: 12px;
            color: #64748b;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .info-value {
            font-size: 18px;
            font-weight: 600;
            color: #1e293b;
        }

        body.dark-mode .info-value {
            color: #e2e8f0;
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-registered {
            background: #dbeafe;
            color: #1e40af;
        }

        .status-paid {
            background: #d1fae5;
            color: #065f46;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        /* Payment Summary */
        .payment-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }

        .summary-card {
            padding: 15px;
            border-radius: 12px;
            text-align: center;
        }

        .summary-card .label {
            font-size: 12px;
            margin-bottom: 8px;
        }

        .summary-card .amount {
            font-size: 24px;
            font-weight: bold;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
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

        /* Button Group */
        .button-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }

        body.dark-mode .button-group {
            border-top-color: #334155;
        }

        /* Loading Overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(3px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 99999;
        }

        .loading-overlay.active {
            display: flex;
        }

        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* RTL Support */
        [dir="rtl"] {
            text-align: right;
        }

        [dir="rtl"] .button-group {
            flex-direction: row-reverse;
        }

        @media (max-width: 768px) {
            .payment-summary {
                grid-template-columns: 1fr;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .button-group {
                flex-direction: column;
            }

            .button-group .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <div class="loading-overlay">
        <div style="text-align: center;">
            <div class="loading-spinner"></div>
            <div class="loading-text">Processing...</div>
        </div>
    </div>

    <div class="container">
        <!-- Navigation -->
        <div class="navbar">
            <h1><i class="bi bi-ticket-perforated"></i> <?php echo t('ticketing_system'); ?></h1>
            <div>
                <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> <?php echo t('Back To Dashboard'); ?></a>
                <a href="logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> <?php echo t('logout'); ?></a>
            </div>
        </div>

        <!-- Reservation Details -->
        <div class="card">
            <div class="card-header">
                <h2><i class="bi bi-receipt"></i> <?php echo t('Reservation Details'); ?></h2>
                <span class="status-badge status-<?php echo $reservation['status']; ?>">
                    <i class="bi <?php echo $reservation['status'] == 'paid' ? 'bi-check-circle-fill' : ($reservation['status'] == 'pending' ? 'bi-hourglass-split' : ($reservation['status'] == 'registered' ? 'bi-check-circle' : 'bi-slash-circle')); ?>"></i>
                    <?php echo ucfirst($reservation['status']); ?>
                </span>
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label"><i class="bi bi-upc-scan"></i> <?php echo t('reservation_id'); ?></div>
                    <div class="info-value"><?php echo htmlspecialchars($reservation['reservation_id']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="bi bi-person"></i> <?php echo t('customer_name'); ?></div>
                    <div class="info-value"><?php echo htmlspecialchars($reservation['name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="bi bi-telephone"></i> <?php echo t('phone_number'); ?></div>
                    <div class="info-value"><?php echo htmlspecialchars($reservation['phone']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="bi bi-grid-3x3-gap-fill"></i> <?php echo t('table_id'); ?></div>
                    <div class="info-value"><?php echo htmlspecialchars($reservation['table_id']); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="bi bi-calendar3"></i> <?php echo t('Created At'); ?></div>
                    <div class="info-value"><?php echo date('F d, Y H:i:s', strtotime($reservation['created_at'])); ?></div>
                </div>
                <div class="info-item">
                    <div class="info-label"><i class="bi bi-people"></i> <?php echo t('Guests Breakdown'); ?></div>
                    <div class="info-value">
                        <?php
                        $totalGuests = $reservation['adults'] + $reservation['teens'] + $reservation['kids'];
                        ?>
                        <strong><?php echo $totalGuests; ?></strong> total guests<br>
                        <small>Adults: <?php echo $reservation['adults']; ?> | Teens: <?php echo $reservation['teens']; ?> | Kids: <?php echo $reservation['kids']; ?></small>
                    </div>
                </div>
                <?php if (!empty($reservation['notes'])): ?>
                    <div class="info-item">
                        <div class="info-label"><i class="bi bi-chat"></i> <?php echo t('notes'); ?></div>
                        <div class="info-value"><?php echo nl2br(htmlspecialchars($reservation['notes'])); ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Payment Transactions Section -->
        <div class="card">
            <div class="card-header">
                <h2><i class="bi bi-credit-card"></i> <?php echo t('Payment Transactions'); ?></h2>
                <?php if ($reservation['status'] != 'paid' && $reservation['status'] != 'cancelled' && $amount_due > 0): ?>
                    <a href="dashboard.php" class="btn btn-success btn-sm">
                        <i class="bi bi-plus-circle"></i> <?php echo t('add_payment'); ?>
                    </a>
                <?php endif; ?>
            </div>

            <!-- Payment Summary Cards -->
            <div class="payment-summary">
                <div class="summary-card" style="background: #f1f5f9;">
                    <div class="label" style="color: #64748b;">Total Amount</div>
                    <div class="amount" style="color: #1e293b;">
                        <?php echo number_format($total_amount, 2); ?> <?php echo $currency; ?>
                    </div>
                </div>
                <div class="summary-card" style="background: #d1fae5;">
                    <div class="label" style="color: #065f46;">Total Paid</div>
                    <div class="amount" style="color: #065f46;">
                        <?php echo number_format($total_paid, 2); ?> <?php echo $currency; ?>
                    </div>
                </div>
                <div class="summary-card" style="background: <?php echo $amount_due > 0 ? '#fef3c7' : '#d1fae5'; ?>;">
                    <div class="label" style="color: <?php echo $amount_due > 0 ? '#92400e' : '#065f46'; ?>;">Remaining Due</div>
                    <div class="amount" style="color: <?php echo $amount_due > 0 ? '#f59e0b' : '#10b981'; ?>;">
                        <?php echo number_format($amount_due, 2); ?> <?php echo $currency; ?>
                    </div>
                </div>
            </div>

            <?php if (empty($payments)): ?>
                <div style="text-align: center; padding: 60px 20px;">
                    <i class="bi bi-inbox" style="font-size: 64px; opacity: 0.3; display: block; margin-bottom: 15px;"></i>
                    <p style="color: #64748b;"><?php echo t('no_payment_transactions'); ?></p>
                    <?php if ($reservation['status'] != 'paid' && $reservation['status'] != 'cancelled' && $amount_due > 0): ?>
                        <a href="dashboard.php" class="btn btn-success" style="margin-top: 15px;">
                            <i class="bi bi-plus-circle"></i> <?php echo t('record_first_payment'); ?>
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th><i class="bi bi-clock"></i> <?php echo t('date_time'); ?></th>
                                <th><i class="bi bi-wallet2"></i> <?php echo t('payment_method'); ?></th>
                                <th><i class="bi bi-cash"></i> <?php echo t('Amount'); ?></th>
                                <th><i class="bi bi-info-circle"></i> <?php echo t('reference_evidence'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td>
                                        <?php echo date('M d, Y H:i:s', strtotime($payment['payment_date'])); ?>
                                    </td>
                                    <td>
                                        <?php
                                        $method_icon = [
                                            'cash' => 'bi-cash-stack',
                                            'cliq' => 'bi-phone',
                                            'visa' => 'bi-credit-card'
                                        ];
                                        $method_color = [
                                            'cash' => '#10b981',
                                            'cliq' => '#8b5cf6',
                                            'visa' => '#ef4444'
                                        ];
                                        ?>
                                        <i class="bi <?php echo $method_icon[$payment['payment_method']]; ?>" style="color: <?php echo $method_color[$payment['payment_method']]; ?>;"></i>
                                        <strong><?php echo ucfirst($payment['payment_method']); ?></strong>
                                    </td>
                                    <td style="font-weight: bold; color: #10b981;">
                                        + <?php echo number_format($payment['amount'], 2); ?> <?php echo $currency; ?>
                                    </td>
                                    <td>
                                        <?php if ($payment['payment_method'] == 'cash'): ?>
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <i class="bi bi-person-badge"></i>
                                                <span><strong>Received by:</strong> <?php echo htmlspecialchars($payment['received_by']); ?></span>
                                            </div>
                                        <?php elseif ($payment['payment_method'] == 'cliq' && $payment['proof_path']): ?>
                                            <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                                                <i class="bi bi-image"></i>
                                                <a href="../<?php echo $payment['proof_path']; ?>" target="_blank" class="btn btn-sm btn-info">
                                                    <i class="bi bi-eye"></i> View Screenshot
                                                </a>
                                                <a href="../<?php echo $payment['proof_path']; ?>" download class="btn btn-sm btn-secondary">
                                                    <i class="bi bi-download"></i> Download
                                                </a>
                                            </div>
                                        <?php elseif ($payment['payment_method'] == 'visa'): ?>
                                            <div style="display: flex; align-items: center; gap: 5px;">
                                                <i class="bi bi-receipt"></i>
                                                <span><strong>Receipt ID:</strong> <?php echo htmlspecialchars($payment['receipt_id']); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <div style="font-size: 11px; color: #64748b; margin-top: 5px;">
                                            <i class="bi bi-clock"></i> Recorded: <?php echo date('M d, H:i', strtotime($payment['created_at'])); ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr style="background: #f8fafc; font-weight: bold;">
                                <td colspan="2" style="text-align: right;">Total Paid:</td>
                                <td colspan="2"><?php echo number_format($total_paid, 2); ?> <?php echo $currency; ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Action Buttons -->
<div class="button-group">
    <a href="edit_reservation.php?id=<?php echo urlencode($reservation_id); ?>" class="btn btn-warning">
        <i class="bi bi-pencil"></i> <?php echo t('edit_reservation'); ?>
    </a>
    <!-- NEW: View Tickets Button -->
    <a href="/public/reservation_tickets.php?id=<?php echo urlencode($reservation_id); ?>" class="btn btn-info">
        <i class="bi bi-ticket-perforated"></i> View Tickets
    </a>
    <a href="print_statement.php?id=<?php echo urlencode($reservation_id); ?>" class="btn btn-info" target="_blank">
        <i class="bi bi-printer"></i> <?php echo t('print_statement'); ?>
    </a>
    <?php if ($reservation['status'] != 'cancelled' && $reservation['status'] != 'paid'): ?>
        <button onclick="cancelReservation('<?php echo $reservation_id; ?>')" class="btn btn-danger">
            <i class="bi bi-x-circle"></i> <?php echo t('cancel_reservation'); ?>
        </button>
    <?php endif; ?>
    <button onclick="deleteReservation('<?php echo $reservation_id; ?>')" class="btn btn-danger">
        <i class="bi bi-trash"></i> <?php echo t('delete_reservation'); ?>
    </button>
    <a href="dashboard.php" class="btn btn-secondary">
        <i class="bi bi-arrow-left"></i> <?php echo t('back_to_dashboard'); ?>
    </a>
</div>
        </div>
    </div>

    <script>
        function showLoading(message = 'Processing...') {
            const overlay = document.querySelector('.loading-overlay');
            const textEl = overlay?.querySelector('.loading-text');
            if (textEl) textEl.innerText = message;
            if (overlay) overlay.classList.add('active');
        }

        function hideLoading() {
            const overlay = document.querySelector('.loading-overlay');
            if (overlay) overlay.classList.remove('active');
        }

        function showNotification(message, type = 'info') {
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: ${type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#3b82f6')};
                color: white;
                padding: 12px 20px;
                border-radius: 12px;
                display: flex;
                align-items: center;
                gap: 10px;
                z-index: 10001;
                animation: slideInRight 0.3s ease;
                box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            `;
            notification.innerHTML = `<span>${type === 'success' ? '✓' : (type === 'error' ? '✗' : 'ℹ')}</span><span>${message}</span>`;
            document.body.appendChild(notification);

            setTimeout(() => {
                notification.style.animation = 'fadeOut 0.5s ease forwards';
                setTimeout(() => notification.remove(), 500);
            }, 3000);
        }

        function cancelReservation(reservationId) {
            const password = prompt('⚠️ SECURITY VERIFICATION REQUIRED\n\nEnter admin password to cancel this reservation:\n(Default: AdminDelete2026)');

            if (password === null) return;

            if (password !== 'AdminDelete2026') {
                showNotification('Invalid password!', 'error');
                return;
            }

            if (confirm('Are you sure you want to cancel this reservation? This action cannot be undone.')) {
                showLoading('Cancelling reservation...');

                fetch('cancel_reservation.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            reservation_id: reservationId,
                            password: password
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        hideLoading();
                        if (data.success) {
                            showNotification('Reservation cancelled successfully!', 'success');
                            setTimeout(() => {
                                window.location.href = 'dashboard.php';
                            }, 1500);
                        } else {
                            showNotification(data.error, 'error');
                        }
                    })
                    .catch(error => {
                        hideLoading();
                        showNotification('Error: ' + error.message, 'error');
                    });
            }
        }

        function deleteReservation(reservationId) {
            const password = prompt('⚠️ SECURITY VERIFICATION REQUIRED\n\nEnter admin password to delete this reservation:\n(Default: AdminDelete2026)\n\nWARNING: This will permanently delete all data including payment records!');

            if (password === null) return;

            if (password !== 'AdminDelete2026') {
                showNotification('Invalid password!', 'error');
                return;
            }

            if (confirm('⚠️ WARNING: This will permanently delete this reservation and ALL associated payment records. This action CANNOT be undone!\n\nAre you absolutely sure?')) {
                showLoading('Deleting reservation...');

                fetch('delete_reservation.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            reservation_id: reservationId,
                            password: password
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        hideLoading();
                        if (data.success) {
                            showNotification('Reservation deleted successfully!', 'success');
                            setTimeout(() => {
                                window.location.href = 'dashboard.php';
                            }, 1500);
                        } else {
                            showNotification(data.error, 'error');
                        }
                    })
                    .catch(error => {
                        hideLoading();
                        showNotification('Error: ' + error.message, 'error');
                    });
            }
        }

        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
 @keyframes slideInRight {
 from {
  opacity: 0;
  transform: translateX(100%);
 }
 to {
  opacity: 1;
  transform: translateX(0);
 }
 }
 
 @keyframes fadeOut {
 to {
  opacity: 0;
  transform: translateX(100%);
 }
 }
 `;
        document.head.appendChild(style);
    </script>
</body>

</html>