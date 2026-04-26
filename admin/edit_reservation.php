<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();
$reservation_id = isset($_GET['id']) ? sanitizeInput($_GET['id']) : '';

if (empty($reservation_id)) {
    header('Location: dashboard.php?error=No reservation ID provided');
    exit();
}

// Fetch reservation
$stmt = $conn->prepare("SELECT * FROM reservations WHERE reservation_id = ?");
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: dashboard.php?error=Reservation not found');
    exit();
}

$reservation = $result->fetch_assoc();
$stmt->close();

// Get selected event info for ticket prices
$selected_event_id = $_SESSION['selected_event_id'] ?? 0;
$event_ticket_prices = $_SESSION['event_ticket_prices'] ?? null;

if (!$event_ticket_prices && $selected_event_id > 0) {
    $stmt = $conn->prepare("SELECT ticket_price_adult, ticket_price_teen, ticket_price_kid FROM event_settings WHERE id = ?");
    $stmt->bind_param("i", $selected_event_id);
    $stmt->execute();
    $event_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($event_data) {
        $event_ticket_prices = [
            'adult' => $event_data['ticket_price_adult'],
            'teen' => $event_data['ticket_price_teen'],
            'kid' => $event_data['ticket_price_kid']
        ];
        $_SESSION['event_ticket_prices'] = $event_ticket_prices;
    }
}

// Use event-specific prices or fall back to system settings
$adultPrice = $event_ticket_prices['adult'] ?? getSetting('ticket_price_adult', 10);
$teenPrice = $event_ticket_prices['teen'] ?? getSetting('ticket_price_teen', 10);
$kidPrice = $event_ticket_prices['kid'] ?? getSetting('ticket_price_kid', 0);
$currency = getSetting('currency', 'JOD');
$currencySymbol = getCurrencySymbol();

// Check if reservation is already cancelled
$isCancelled = ($reservation['status'] == 'cancelled');

// Calculate variables
$totalGuests = ($reservation['adults'] ?? 0) + ($reservation['teens'] ?? 0) + ($reservation['kids'] ?? 0);
$currentTotal = floatval($reservation['total_amount'] ?? 0);
$additionalDue = floatval($reservation['additional_amount_due'] ?? 0);
$cancelledClass = $isCancelled ? 'cancelled-text' : '';

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#667eea">
    <title>Edit Reservation - Ticketing System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 800px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .header {
            background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            color: white;
            padding: 25px 30px;
        }
        .header h1 { font-size: 24px; margin-bottom: 5px; }
        .header p { opacity: 0.9; font-size: 14px; }
        .content { padding: 30px; }
        
        /* Cancelled text styling - strikethrough */
        .cancelled-text {
            text-decoration: line-through;
            text-decoration-thickness: 2px;
            text-decoration-color: #dc2626;
            opacity: 0.7;
        }
        .cancelled-badge {
            display: inline-block;
            background: #dc2626;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-left: 10px;
        }
        
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #334155;
            font-size: 14px;
        }
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
        }
        input:disabled, select:disabled, textarea:disabled {
            background: #f1f5f9;
            cursor: not-allowed;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        .info-box {
            background: #f1f5f9;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 25px;
            border-left: 4px solid #6366f1;
        }
        .info-box p { margin: 5px 0; font-size: 13px; color: #475569; }
        .warning-box {
            background: #fef3c7;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 25px;
            border-left: 4px solid #f59e0b;
        }
        .warning-box p { margin: 5px 0; font-size: 13px; color: #92400e; }
        .reservation-id {
            font-family: monospace;
            font-size: 14px;
            background: #e2e8f0;
            padding: 8px 12px;
            border-radius: 8px;
            display: inline-block;
            word-break: break-all;
        }
        .price-breakdown {
            background: #e0e7ff;
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
            font-size: 13px;
            line-height: 1.6;
        }
        .total-amount {
            font-size: 18px;
            font-weight: bold;
            color: #10b981;
        }
        .actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #6366f1;
            color: white;
        }
        .btn-primary:hover {
            background: #4f46e5;
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        .btn-secondary:hover {
            background: #475569;
        }
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-2px);
        }
        .btn-warning {
            background: #f59e0b;
            color: white;
        }
        .btn-warning:hover {
            background: #d97706;
        }
        small {
            display: block;
            margin-top: 5px;
            font-size: 11px;
            color: #94a3b8;
        }
        @media (max-width: 600px) {
            .content { padding: 20px; }
            .form-row { grid-template-columns: 1fr; gap: 0; }
            .actions { flex-direction: column; }
            .btn { text-align: center; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1>✏️ Edit Reservation</h1>
                <p>Update reservation details below</p>
            </div>
            <div class="content">
                <div class="info-box <?php echo $cancelledClass; ?>">
                    <p><strong>📋 Reservation ID:</strong> <span class="reservation-id <?php echo $cancelledClass; ?>"><?php echo htmlspecialchars($reservation['reservation_id']); ?></span>
                    <?php if ($isCancelled): ?>
                        <span class="cancelled-badge">CANCELLED</span>
                    <?php endif; ?>
                    </p>
                    <p class="<?php echo $cancelledClass; ?>"><strong>📅 Created:</strong> <?php echo date('F j, Y g:i A', strtotime($reservation['created_at'])); ?></p>
                    <p class="<?php echo $cancelledClass; ?>"><strong>👥 Current Guests:</strong> <?php echo $totalGuests; ?> total (<?php echo $reservation['adults']; ?> Adults, <?php echo $reservation['teens']; ?> Teens, <?php echo $reservation['kids']; ?> Kids)</p>
                    <p class="<?php echo $cancelledClass; ?>"><strong>💰 Total Amount:</strong> <span class="total-amount <?php echo $cancelledClass; ?>"><?php echo number_format($currentTotal, 2); ?> <?php echo $currencySymbol; ?></span></p>
                    <?php if ($additionalDue > 0): ?>
                    <p style="color: #d97706;" class="<?php echo $cancelledClass; ?>"><strong>⚠️ Additional Amount Due:</strong> <?php echo number_format($additionalDue, 2); ?> <?php echo $currencySymbol; ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($additionalDue > 0 && !$isCancelled): ?>
                <div class="warning-box">
                    <p><strong>⚠️ Warning: Additional Payment Required</strong></p>
                    <p>This reservation has unpaid guests. If you increase guest count further, additional payment will be required.</p>
                </div>
                <?php endif; ?>

                <?php if ($isCancelled): ?>
                <div class="warning-box" style="background: #fee2e2; border-left-color: #dc2626;">
                    <p><strong>❌ This reservation has been CANCELLED</strong></p>
                    <p>No further changes can be made. You can restore it by changing the status to Pending or Registered.</p>
                </div>
                <?php endif; ?>

                <form action="update_reservation.php" method="POST">
                    <input type="hidden" name="reservation_id" value="<?php echo htmlspecialchars($reservation['reservation_id']); ?>">

                    <div class="form-group">
                        <label>Customer Name *</label>
                        <input type="text" name="name" value="<?php echo htmlspecialchars($reservation['name']); ?>" required <?php echo $isCancelled ? 'disabled' : ''; ?>>
                    </div>

                    <div class="form-group">
                        <label>Phone Number *</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($reservation['phone']); ?>" required <?php echo $isCancelled ? 'disabled' : ''; ?>>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Adults (<?php echo $currencySymbol; ?> <?php echo number_format($adultPrice, 2); ?>)</label>
                            <input type="number" name="adults" id="adults" value="<?php echo $reservation['adults']; ?>" min="0" onchange="updatePrice()" <?php echo $isCancelled ? 'disabled' : ''; ?>>
                        </div>
                        <div class="form-group">
                            <label>Teens (<?php echo $currencySymbol; ?> <?php echo number_format($teenPrice, 2); ?>)</label>
                            <input type="number" name="teens" id="teens" value="<?php echo $reservation['teens']; ?>" min="0" onchange="updatePrice()" <?php echo $isCancelled ? 'disabled' : ''; ?>>
                        </div>
                        <div class="form-group">
                            <label>Kids (<?php echo $currencySymbol; ?> <?php echo number_format($kidPrice, 2); ?>)</label>
                            <input type="number" name="kids" id="kids" value="<?php echo $reservation['kids']; ?>" min="0" onchange="updatePrice()" <?php echo $isCancelled ? 'disabled' : ''; ?>>
                        </div>
                    </div>

                    <div class="price-breakdown" id="priceBreakdown"></div>

                    <div class="form-group">
                        <label>Table ID *</label>
                        <input type="text" name="table_id" value="<?php echo htmlspecialchars($reservation['table_id']); ?>" required <?php echo $isCancelled ? 'disabled' : ''; ?>>
                    </div>

                    <div class="form-group">
                        <label>Notes</label>
                        <textarea name="notes" rows="4" <?php echo $isCancelled ? 'disabled' : ''; ?>><?php echo htmlspecialchars($reservation['notes']); ?></textarea>
                    </div>

                    <div class="form-group">
                        <label>Status *</label>
                        <select name="status" required <?php echo $isCancelled ? 'disabled' : ''; ?>>
                            <option value="pending" <?php echo $reservation['status'] == 'pending' ? 'selected' : ''; ?>>⏳ Pending</option>
                            <option value="registered" <?php echo $reservation['status'] == 'registered' ? 'selected' : ''; ?>>📌 Registered</option>
                            <option value="paid" <?php echo $reservation['status'] == 'paid' ? 'selected' : ''; ?>>✅ Paid</option>
                            <option value="cancelled" <?php echo $reservation['status'] == 'cancelled' ? 'selected' : ''; ?>>❌ Cancelled</option>
                        </select>
                    </div>

                    <div class="actions">
                        <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                        <?php if (!$isCancelled): ?>
                            <button type="submit" class="btn btn-primary">Save Changes</button>
                            <button type="button" class="btn btn-danger" onclick="confirmCancel('<?php echo $reservation['reservation_id']; ?>')">❌ Cancel Reservation</button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-primary">Restore Reservation</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const adultPrice = <?php echo $adultPrice; ?>;
        const teenPrice = <?php echo $teenPrice; ?>;
        const kidPrice = <?php echo $kidPrice; ?>;
        const currencySymbol = '<?php echo $currencySymbol; ?>';
        const currentPaid = <?php echo $currentTotal; ?>;
        const currentAdditionalDue = <?php echo $additionalDue; ?>;

        function updatePrice() {
            let adults = parseInt(document.getElementById('adults').value) || 0;
            let teens = parseInt(document.getElementById('teens').value) || 0;
            let kids = parseInt(document.getElementById('kids').value) || 0;

            let newSubtotal = (adults * adultPrice) + (teens * teenPrice) + (kids * kidPrice);
            let newTotal = newSubtotal;
            let additionalDue = newTotal - currentPaid;
            if (additionalDue < 0) additionalDue = 0;

            let warningHtml = '';
            if (additionalDue > 0) {
                warningHtml = `<div style="background: #fef3c7; padding: 10px; border-radius: 8px; margin-top: 10px; color: #92400e;">
                    ⚠️ Additional payment required: ${additionalDue.toFixed(2)} ${currencySymbol}
                </div>`;
            }

            document.getElementById('priceBreakdown').innerHTML = `
                <strong>💰 Price Breakdown:</strong><br>
                Adults: ${adults} × ${adultPrice.toFixed(2)} = ${(adults * adultPrice).toFixed(2)} ${currencySymbol}<br>
                Teens: ${teens} × ${teenPrice.toFixed(2)} = ${(teens * teenPrice).toFixed(2)} ${currencySymbol}<br>
                Kids: ${kids} × ${kidPrice.toFixed(2)} = ${(kids * kidPrice).toFixed(2)} ${currencySymbol}<br>
                <hr>
                <strong>New Total: ${newTotal.toFixed(2)} ${currencySymbol}</strong><br>
                <strong>Already Paid: ${currentPaid.toFixed(2)} ${currencySymbol}</strong>
                ${warningHtml}
            `;
        }

        function confirmCancel(reservationId) {
            if (confirm('⚠️ WARNING: Cancelling this reservation will mark it as cancelled.\n\nThis action can be undone by changing the status back to Pending/Registered.\n\nAre you sure you want to cancel this reservation?')) {
                window.location.href = `update_status.php?id=${reservationId}&status=cancelled&redirect=edit`;
            }
        }

        document.addEventListener('DOMContentLoaded', function() {
            updatePrice();
        });
    </script>
</body>
</html>