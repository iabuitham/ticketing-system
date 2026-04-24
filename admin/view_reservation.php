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
$reservation_id = isset($_GET['id']) ? sanitizeInput($_GET['id']) : '';

if (empty($reservation_id)) {
    header('Location: dashboard.php');
    exit();
}

$stmt = $conn->prepare("SELECT * FROM reservations WHERE reservation_id = ?");
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reservation) {
    header('Location: dashboard.php?error=Not found');
    exit();
}

// Get all payments (initial + additional)
$payments = $conn->query("SELECT * FROM split_payments WHERE reservation_id = '$reservation_id' ORDER BY created_at ASC");
$allPayments = $payments->fetch_all(MYSQLI_ASSOC);

// Get ticket codes
$tickets = $conn->query("SELECT * FROM ticket_codes WHERE reservation_id = '$reservation_id' ORDER BY guest_type, guest_number")->fetch_all(MYSQLI_ASSOC);

// Get event settings
$event = $conn->query("SELECT * FROM event_settings LIMIT 1")->fetch_assoc();
if (!$event) {
    $event = [
        'event_name' => 'Annual Tech Conference 2024',
        'event_date' => date('Y-m-d', strtotime('+30 days')),
        'event_time' => '18:00:00',
        'venue' => 'Grand Hall, Amman'
    ];
}

$totalGuests = $reservation['adults'] + $reservation['teens'] + $reservation['kids'];
$paidGuests = ($reservation['paid_adults'] ?? 0) + ($reservation['paid_teens'] ?? 0) + ($reservation['paid_kids'] ?? 0);
$totalDue = $reservation['total_amount'];
$additionalDue = $reservation['additional_amount_due'] ?? 0;
$currency = getSetting('currency', 'JOD');

$conn->close();

function getStatusClass($status) {
    switch ($status) {
        case 'pending': return 'status-pending';
        case 'registered': return 'status-registered';
        case 'paid': return 'status-paid';
        case 'cancelled': return 'status-cancelled';
        default: return 'status-pending';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <html lang="<?php echo $lang; ?>" dir="<?php echo getDirection(); ?>">
    <link rel="stylesheet" href="../assets/css/dark-mode.css">
    <title>View Reservation - Ticketing System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        .header {
            background: white;
            padding: 20px;
            border-radius: 16px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .card {
            background: white;
            border-radius: 16px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        .card-header {
            background: #f8fafc;
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
            font-size: 16px;
        }
        .card-body { padding: 20px; }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 12px;
        }
        .info-label { font-weight: 600; color: #64748b; font-size: 13px; }
        .info-value { color: #1e293b; font-size: 14px; }
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-registered { background: #dbeafe; color: #1e40af; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        
        .alert-warning {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 15px;
        }
        .payment-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
            flex-wrap: wrap;
            gap: 10px;
        }
        .payment-row:last-child { border-bottom: none; }
        .payment-method {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 120px;
        }
        .payment-amount {
            font-weight: 700;
            min-width: 100px;
        }
        .payment-details {
            flex: 1;
            display: flex;
            gap: 10px;
            align-items: center;
            justify-content: flex-end;
            flex-wrap: wrap;
        }
        .payment-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-cash { background: #fef3c7; color: #92400e; }
        .badge-cliq { background: #d1fae5; color: #065f46; }
        .badge-visa { background: #dbeafe; color: #1e40af; }
        .badge-additional { background: #e0e7ff; color: #3730a3; }
        
        .proof-link {
            background: #3b82f6;
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            text-decoration: none;
            display: inline-block;
            cursor: pointer;
        }
        .receipt-badge {
            background: #f1f5f9;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            color: #475569;
        }
        .payment-total-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            margin-top: 5px;
            border-top: 2px solid #cbd5e1;
            font-weight: 700;
        }
        .split-badge {
            margin-top: 15px;
            padding: 8px;
            background: #e0e7ff;
            border-radius: 8px;
            text-align: center;
            font-size: 13px;
        }
        
        .proof-gallery {
            margin-top: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
        }
        .proof-item {
            background: #f8fafc;
            border-radius: 12px;
            padding: 10px;
            text-align: center;
            border: 1px solid #e2e8f0;
            width: 150px;
        }
        .proof-item img {
            width: 100%;
            height: 100px;
            object-fit: cover;
            border-radius: 8px;
            cursor: pointer;
        }
        .proof-view-btn {
            margin-top: 8px;
            background: #6366f1;
            color: white;
            border: none;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 10px;
            cursor: pointer;
        }
        
        .ticket-card {
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px;
            border-left: 4px solid #10b981;
            margin-bottom: 10px;
        }
        .ticket-unpaid { border-left-color: #f59e0b; }
        .ticket-code {
            font-family: monospace;
            font-size: 12px;
            background: white;
            padding: 8px;
            border-radius: 8px;
            text-align: center;
            word-break: break-all;
        }
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            font-weight: 500;
        }
        .btn-primary { background: #6366f1; color: white; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-sm { padding: 5px 12px; font-size: 12px; }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .modal.active { display: flex; }
        .modal img {
            max-width: 90%;
            max-height: 90%;
            border-radius: 8px;
        }
        @media (max-width: 768px) {
            .container { padding: 10px; }
            .info-grid { grid-template-columns: 1fr; gap: 8px; }
            .header { flex-direction: column; text-align: center; }
            .payment-row { flex-direction: column; align-items: flex-start; }
            .payment-details { justify-content: flex-start; }
            .proof-item { width: 120px; }
            .proof-item img { height: 80px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎫 Reservation Details</h1>
            <a href="dashboard.php" class="btn btn-secondary">← Back to Dashboard</a>
        </div>

        <!-- Reservation Information Card -->
        <div class="card">
            <div class="card-header">📋 Reservation Information</div>
            <div class="card-body">
                <div class="info-grid">
                    <div class="info-label">Reservation ID:</div>
                    <div class="info-value"><strong><?php echo htmlspecialchars($reservation['reservation_id']); ?></strong></div>
                    <div class="info-label">Status:</div>
                    <div class="info-value"><span class="status-badge <?php echo getStatusClass($reservation['status']); ?>"><?php echo ucfirst($reservation['status']); ?></span></div>
                    <div class="info-label">Customer Name:</div>
                    <div class="info-value"><?php echo htmlspecialchars($reservation['name']); ?></div>
                    <div class="info-label">Phone Number:</div>
                    <div class="info-value"><?php echo htmlspecialchars($reservation['phone']); ?></div>
                    <div class="info-label">Table ID:</div>
                    <div class="info-value"><?php echo htmlspecialchars($reservation['table_id']); ?></div>
                    <div class="info-label">Guests:</div>
                    <div class="info-value"><?php echo $totalGuests; ?> total (<?php echo $reservation['adults']; ?> Adults, <?php echo $reservation['teens']; ?> Teens, <?php echo $reservation['kids']; ?> Kids)</div>
                    <div class="info-label">Paid Guests:</div>
                    <div class="info-value"><?php echo $paidGuests; ?> guests</div>
                    <div class="info-label">Total Amount:</div>
                    <div class="info-value"><strong><?php echo number_format($totalDue, 2); ?> <?php echo $currency; ?></strong></div>
                    <div class="info-label">Notes:</div>
                    <div class="info-value"><?php echo !empty($reservation['notes']) ? nl2br(htmlspecialchars($reservation['notes'])) : '<em>No notes</em>'; ?></div>
                    <div class="info-label">Created:</div>
                    <div class="info-value"><?php echo date('F j, Y g:i A', strtotime($reservation['created_at'])); ?></div>
                </div>
            </div>
        </div>

        <!-- Payment Information Card -->
        <div class="card">
            <div class="card-header">💰 Payment Information</div>
            <div class="card-body">
                <?php if ($additionalDue > 0 && $reservation['status'] == 'paid'): ?>
                <div class="alert-warning">
                    <div style="font-weight: 600; margin-bottom: 5px;">⚠️ Additional Payment Required</div>
                    <div style="font-size: 14px;">
                        Amount due: <strong><?php echo number_format($additionalDue, 2); ?> <?php echo $currency; ?></strong>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($reservation['status'] == 'paid'): ?>
                    <div style="font-weight: 600; margin-bottom: 15px;">💸 Payment History:</div>
                    
                    <?php if (empty($allPayments)): ?>
                        <div style="text-align: center; padding: 20px; color: #999;">No payments recorded</div>
                    <?php else: ?>
                        <?php 
                        $totalPaid = 0;
                        $allProofs = [];
                        foreach ($allPayments as $payment): 
                            $isAdditional = $payment['payment_type'] == 'additional';
                            $badgeClass = $payment['payment_method'] == 'cash' ? 'badge-cash' : ($payment['payment_method'] == 'cliq' ? 'badge-cliq' : 'badge-visa');
                            $badgeText = $payment['payment_method'] == 'cash' ? 'Cash' : ($payment['payment_method'] == 'cliq' ? 'CliQ' : 'Visa');
                            if ($isAdditional) $badgeText .= ' (Additional)';
                            $totalPaid += $payment['amount'];
                            
                            // Collect proofs for gallery
                            if ($payment['proof_path'] && !empty($payment['proof_path'])) {
                                $allProofs[] = [
                                    'path' => $payment['proof_path'],
                                    'method' => $payment['payment_method'],
                                    'date' => $payment['created_at']
                                ];
                            }
                        ?>
                        <div class="payment-row">
                            <div class="payment-method">
                                <span class="payment-badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
                            </div>
                            <div class="payment-amount">
                                <strong><?php echo number_format($payment['amount'], 2); ?> <?php echo $currency; ?></strong>
                            </div>
                            <div class="payment-details">
                                <?php if ($payment['receipt_id']): ?>
                                    <span class="receipt-badge">🧾 Receipt: <?php echo htmlspecialchars($payment['receipt_id']); ?></span>
                                <?php endif; ?>
                                <span style="font-size: 11px; color: #666;"><?php echo date('M d, H:i', strtotime($payment['created_at'])); ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="payment-total-row">
                            <span>Total Paid</span>
                            <span><strong><?php echo number_format($totalPaid, 2); ?> <?php echo $currency; ?></strong></span>
                        </div>
                        
                        <?php if (count($allPayments) > 1): ?>
                        <div class="split-badge">
                            🔀 Split Payment - Paid using multiple methods
                        </div>
                        <?php endif; ?>
                        
                        <!-- Proof Images Gallery -->
                        <?php if (!empty($allProofs)): ?>
                        <div style="margin-top: 20px;">
                            <div style="font-weight: 600; margin-bottom: 10px;">📸 Payment Screenshots:</div>
                            <div class="proof-gallery">
                                <?php foreach ($allProofs as $proof): ?>
                                <div class="proof-item">
                                    <img src="../<?php echo $proof['path']; ?>" alt="Payment Proof" onclick="viewProof('../<?php echo $proof['path']; ?>')">
                                    <div class="proof-label"><?php echo ucfirst($proof['method']); ?> Payment</div>
                                    <button class="proof-view-btn" onclick="viewProof('../<?php echo $proof['path']; ?>')">View Full Size</button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                    <?php endif; ?>
                    
                <?php else: ?>
                    <div style="text-align: center; padding: 40px; background: #f8fafc; border-radius: 12px;">
                        <p>📷 No payment information available</p>
                        <p style="font-size: 13px; margin-top: 5px;">Payment required to complete reservation</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tickets Card -->
        <div class="card">
            <div class="card-header">🎫 Tickets (<?php echo count($tickets); ?> tickets)</div>
            <div class="card-body">
                <?php if (!empty($tickets)): ?>
                    <?php foreach ($tickets as $ticket): 
                        $isPaid = false;
                        if ($ticket['guest_type'] == 'adult' && $ticket['guest_number'] <= ($reservation['paid_adults'] ?? 0)) $isPaid = true;
                        if ($ticket['guest_type'] == 'teen' && $ticket['guest_number'] <= ($reservation['paid_teens'] ?? 0)) $isPaid = true;
                        if ($ticket['guest_type'] == 'kid') $isPaid = true;
                        $icon = $ticket['guest_type'] == 'adult' ? '👤' : ($ticket['guest_type'] == 'teen' ? '🧑' : '👶');
                    ?>
                    <div class="ticket-card <?php echo !$isPaid ? 'ticket-unpaid' : ''; ?>">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span><?php echo $icon; ?> <?php echo ucfirst($ticket['guest_type']); ?> #<?php echo $ticket['guest_number']; ?></span>
                            <span style="color: <?php echo $isPaid ? '#10b981' : '#f59e0b'; ?>;"><?php echo $isPaid ? '✓ Paid' : '○ Unpaid'; ?></span>
                        </div>
                        <div class="ticket-code"><?php echo htmlspecialchars($ticket['ticket_code']); ?></div>
                        <div style="margin-top: 10px;">
                            <a href="print_ticket.php?code=<?php echo urlencode($ticket['ticket_code']); ?>" target="_blank" class="btn btn-sm btn-primary">🖨️ Print</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <div class="ticket-link" style="margin-top: 20px; text-align: center;">
                        <a href="print_ticket.php?reservation_id=<?php echo urlencode($reservation['reservation_id']); ?>" class="btn btn-primary" target="_blank">
                            🖨️ Print All Tickets
                        </a>
                    </div>
                <?php else: ?>
                    <div style="text-align: center; padding: 40px;">
                        <p>🎫 No tickets generated yet.</p>
                        <?php if ($reservation['status'] == 'paid'): ?>
                            <button onclick="alert('Regenerate tickets from edit page')" class="btn btn-warning">Regenerate Tickets</button>
                        <?php else: ?>
                            <p style="margin-top: 10px;">Tickets will be generated when payment is confirmed.</p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions Card -->
        <div class="card">
            <div class="card-header">⚡ Actions</div>
            <div class="card-body">
                <div class="actions" style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="edit_reservation.php?id=<?php echo urlencode($reservation['reservation_id']); ?>" class="btn btn-warning">✏️ Edit Reservation</a>
                    <button onclick="window.print()" class="btn btn-secondary">🖨️ Print Page</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Image Modal for Full Size Proof Viewing -->
    <div id="imageModal" class="modal" onclick="closeImageModal()">
        <img id="modalImage" src="" alt="Payment Proof">
    </div>

    <script>
        function viewProof(imageUrl) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            modal.classList.add('active');
            modalImg.src = imageUrl;
        }
        
        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.classList.remove('active');
        }
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeImageModal();
            }
        });
    </script>
</body>
</html>