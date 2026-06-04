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
$transfer_result = '';

// Handle transfer request submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_transfer'])) {
    $ticket_code = sanitizeInput($_POST['ticket_code']);
    $to_name = sanitizeInput($_POST['to_name']);
    $to_phone = sanitizeInput($_POST['to_phone']);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    // Verify ticket exists and is not used
    $stmt = $conn->prepare("
        SELECT t.*, r.name, r.phone, r.reservation_id 
        FROM ticket_codes t
        JOIN reservations r ON t.reservation_id = r.reservation_id
        WHERE t.ticket_code = ? AND t.is_scanned = 0 AND t.is_active = 1
    ");
    $stmt->bind_param("s", $ticket_code);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$ticket) {
        $message = "❌ Invalid ticket or ticket already used!";
        $messageType = "error";
    } else {
        // Check if there's already a pending transfer for this ticket
        $checkStmt = $conn->prepare("
            SELECT id FROM ticket_transfers 
            WHERE ticket_code = ? AND status IN ('pending', 'approved')
        ");
        $checkStmt->bind_param("s", $ticket_code);
        $checkStmt->execute();
        $existing = $checkStmt->get_result()->fetch_assoc();
        $checkStmt->close();
        
        if ($existing) {
            $message = "⚠️ There's already a pending transfer request for this ticket!";
            $messageType = "error";
        } else {
            // Generate unique transfer code
            $transfer_code = strtoupper(substr(uniqid(), -8));
            $expires_at = date('Y-m-d H:i:s', strtotime('+7 days'));
            
            $insertStmt = $conn->prepare("
                INSERT INTO ticket_transfers (
                    ticket_code, from_reservation_id, from_customer_name, from_customer_phone,
                    to_customer_name, to_customer_phone, transfer_code, expires_at, notes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->bind_param(
                "sssssssss",
                $ticket_code,
                $ticket['reservation_id'],
                $ticket['name'],
                $ticket['phone'],
                $to_name,
                $to_phone,
                $transfer_code,
                $expires_at,
                $notes
            );
            $insertStmt->execute();
            $insertStmt->close();
            
            // Send WhatsApp to original customer
            $msgToOriginal = "🎫 *TICKET TRANSFER REQUESTED* 🎫\n\n";
            $msgToOriginal .= "Dear {$ticket['name']},\n\n";
            $msgToOriginal .= "You have requested to transfer your ticket to:\n";
            $msgToOriginal .= "👤 *Name:* {$to_name}\n";
            $msgToOriginal .= "📱 *Phone:* {$to_phone}\n\n";
            $msgToOriginal .= "📋 *Ticket Code:* `{$ticket_code}`\n";
            $msgToOriginal .= "🔑 *Transfer Code:* `{$transfer_code}`\n\n";
            $msgToOriginal .= "The recipient will receive a confirmation message.\n";
            $msgToOriginal .= "The transfer will expire on " . date('M d, Y', strtotime($expires_at)) . ".\n\n";
            $msgToOriginal .= "Thank you!";
            
            sendWhatsAppMessage($ticket['phone'], $msgToOriginal);
            
            // Send WhatsApp to new customer
            $msgToNew = "🎫 *TICKET TRANSFER - ACTION REQUIRED* 🎫\n\n";
            $msgToNew .= "Dear {$to_name},\n\n";
            $msgToNew .= "{$ticket['name']} has transferred a ticket to you!\n\n";
            $msgToNew .= "📋 *Ticket Code:* `{$ticket_code}`\n";
            $msgToNew .= "🔑 *Transfer Code:* `{$transfer_code}`\n\n";
            $msgToNew .= "⚠️ *To accept this transfer, please reply with:*\n";
            $msgToNew .= "`ACCEPT {$transfer_code}`\n\n";
            $msgToNew .= "❌ *To reject this transfer, reply with:*\n";
            $msgToNew .= "`REJECT {$transfer_code}`\n\n";
            $msgToNew .= "This transfer expires on " . date('M d, Y', strtotime($expires_at)) . ".\n\n";
            $msgToNew .= "Thank you!";
            
            sendWhatsAppMessage($to_phone, $msgToNew);
            
            $message = "✅ Transfer request submitted! Both parties have been notified.";
            $messageType = "success";
        }
    }
}

// Handle transfer action (accept/reject via GET for admin panel)
if (isset($_GET['action']) && isset($_GET['transfer_id'])) {
    $transfer_id = intval($_GET['transfer_id']);
    $action = $_GET['action'];
    
    $stmt = $conn->prepare("
        SELECT tt.*, t.is_scanned, t.id as ticket_db_id
        FROM ticket_transfers tt
        JOIN ticket_codes t ON tt.ticket_code = t.ticket_code
        WHERE tt.id = ?
    ");
    $stmt->bind_param("i", $transfer_id);
    $stmt->execute();
    $transfer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$transfer) {
        $message = "Transfer request not found!";
        $messageType = "error";
    } elseif ($transfer['status'] != 'pending') {
        $message = "This transfer has already been " . $transfer['status'];
        $messageType = "error";
    } else {
        if ($action == 'approve') {
            // Update ticket ownership (update ticket_codes? Actually tickets belong to reservation)
            // For tickets, we update the reservation's name and phone for this specific ticket?
            // Alternative: Create a new ticket for the new person and deactivate old one
            
            $conn->begin_transaction();
            
            try {
                // Deactivate old ticket
                $deactivateStmt = $conn->prepare("
                    UPDATE ticket_codes 
                    SET is_active = 0, deactivated_at = NOW(), deactivated_by = ? 
                    WHERE ticket_code = ?
                ");
                $deactivateStmt->bind_param("ss", $_SESSION['admin_username'], $transfer['ticket_code']);
                $deactivateStmt->execute();
                $deactivateStmt->close();
                
                // Get original reservation info for the new ticket
                $resStmt = $conn->prepare("
                    SELECT reservation_id, adults, teens, kids, event_id 
                    FROM reservations 
                    WHERE reservation_id = ?
                ");
                $resStmt->bind_param("s", $transfer['from_reservation_id']);
                $resStmt->execute();
                $reservation = $resStmt->get_result()->fetch_assoc();
                $resStmt->close();
                
                // Create new ticket for the new person
                $new_ticket_code = generateTransferTicketId($transfer['ticket_code'], $transfer['to_customer_name']);
                $insertTicketStmt = $conn->prepare("
                    INSERT INTO ticket_codes (
                        reservation_id, ticket_code, guest_type, guest_number, 
                        is_active, is_scanned, created_at
                    ) VALUES (?, ?, 'transferred', 0, 1, 0, NOW())
                ");
                $insertTicketStmt->bind_param("ss", $transfer['from_reservation_id'], $new_ticket_code);
                $insertTicketStmt->execute();
                $insertTicketStmt->close();
                
                // Update transfer status
                $updateStmt = $conn->prepare("
                    UPDATE ticket_transfers 
                    SET status = 'approved', approved_at = NOW(), processed_by = ? 
                    WHERE id = ?
                ");
                $updateStmt->bind_param("si", $_SESSION['admin_username'], $transfer_id);
                $updateStmt->execute();
                $updateStmt->close();
                
                $conn->commit();
                
                // Send confirmation WhatsApp to new owner
                $confirmMsg = "✅ *TICKET TRANSFER COMPLETED!* ✅\n\n";
                $confirmMsg .= "Dear {$transfer['to_customer_name']},\n\n";
                $confirmMsg .= "The ticket has been successfully transferred to you!\n\n";
                $confirmMsg .= "🎫 *Your New Ticket Code:* `{$new_ticket_code}`\n";
                $confirmMsg .= "📋 *Original Ticket:* {$transfer['ticket_code']} (deactivated)\n\n";
                $confirmMsg .= "Please save this new ticket code. The old ticket is no longer valid.\n\n";
                $confirmMsg .= "We look forward to seeing you at the event! 🎉";
                
                sendWhatsAppMessage($transfer['to_customer_phone'], $confirmMsg);
                
                // Notify original owner
                $ownerMsg = "✅ *TICKET TRANSFER COMPLETE* ✅\n\n";
                $ownerMsg .= "Dear {$transfer['from_customer_name']},\n\n";
                $ownerMsg .= "Your ticket has been successfully transferred to:\n";
                $ownerMsg .= "👤 {$transfer['to_customer_name']}\n";
                $ownerMsg .= "📱 {$transfer['to_customer_phone']}\n\n";
                $ownerMsg .= "The original ticket is no longer valid.\n\n";
                $ownerMsg .= "Thank you for using our transfer service!";
                
                sendWhatsAppMessage($transfer['from_customer_phone'], $ownerMsg);
                
                $message = "✅ Transfer approved and completed successfully!";
                $messageType = "success";
                
            } catch (Exception $e) {
                $conn->rollback();
                $message = "Error: " . $e->getMessage();
                $messageType = "error";
            }
            
        } elseif ($action == 'reject') {
            $updateStmt = $conn->prepare("
                UPDATE ticket_transfers 
                SET status = 'cancelled', processed_by = ? 
                WHERE id = ?
            ");
            $updateStmt->bind_param("si", $_SESSION['admin_username'], $transfer_id);
            $updateStmt->execute();
            $updateStmt->close();
            
            // Notify both parties
            $rejectMsg = "❌ *TICKET TRANSFER REJECTED* ❌\n\n";
            $rejectMsg .= "Dear {$transfer['from_customer_name']},\n\n";
            $rejectMsg .= "Your transfer request to {$transfer['to_customer_name']} has been rejected.\n\n";
            $rejectMsg .= "Your ticket remains valid.\n\n";
            $rejectMsg .= "You can try transferring to another person if needed.";
            
            sendWhatsAppMessage($transfer['from_customer_phone'], $rejectMsg);
            
            $message = "Transfer request rejected.";
            $messageType = "success";
        }
    }
}

// Get all transfer requests
$transfers = $conn->query("
    SELECT tt.*, 
           CASE 
               WHEN tt.expires_at < NOW() AND tt.status = 'pending' THEN 'expired'
               ELSE tt.status
           END as current_status
    FROM ticket_transfers tt
    ORDER BY tt.requested_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Get statistics
$stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' AND expires_at > NOW() THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
        SUM(CASE WHEN expires_at < NOW() AND status = 'pending' THEN 1 ELSE 0 END) as expired
    FROM ticket_transfers
")->fetch_assoc();

$conn->close();

function generateTransferTicketId($old_code, $new_name) {
    $prefix = substr($old_code, 0, 10);
    $suffix = strtoupper(substr(md5($new_name . time()), 0, 6));
    return $prefix . '-TRF-' . $suffix;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket Transfer - Ticketing System</title>
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
            flex-wrap: wrap;
            gap: 15px;
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
        .btn-success:hover { background: #059669; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-approved { background: #d1fae5; color: #065f46; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }
        .badge-expired { background: #e2e8f0; color: #475569; }
        .table-container { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #f8fafc; font-weight: 600; }
        body.dark-mode th { background: #0f172a; color: #94a3b8; }
        body.dark-mode td { border-bottom-color: #334155; }
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
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
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
        .alert-info { background: #dbeafe; color: #1e40af; border-left: 4px solid #3b82f6; }
        .transfer-info {
            background: #e0e7ff;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #4f46e5;
        }
        @media (max-width: 1024px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .navbar { flex-direction: column; text-align: center; }
            .card-header { flex-direction: column; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="navbar">
        <h1><i class="bi bi-arrow-left-right"></i> Ticket Transfer System</h1>
        <div>
            <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
            <a href="logout.php" class="btn btn-danger"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <i class="bi bi-<?php echo $messageType == 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill'; ?>"></i>
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo $stats['total'] ?? 0; ?></div>
            <div class="stat-label">Total Transfers</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color: #f59e0b;"><?php echo $stats['pending'] ?? 0; ?></div>
            <div class="stat-label">Pending</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color: #10b981;"><?php echo $stats['approved'] ?? 0; ?></div>
            <div class="stat-label">Approved</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color: #ef4444;"><?php echo $stats['cancelled'] ?? 0; ?></div>
            <div class="stat-label">Cancelled</div>
        </div>
        <div class="stat-card">
            <div class="stat-number" style="color: #64748b;"><?php echo $stats['expired'] ?? 0; ?></div>
            <div class="stat-label">Expired</div>
        </div>
    </div>

    <!-- Request Transfer Form -->
    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-send-plus"></i> Request Ticket Transfer</h2>
        </div>
        <div class="transfer-info">
            <i class="bi bi-info-circle"></i> 
            <strong>How it works:</strong> Enter the ticket code and the new person's details. 
            Both parties will receive WhatsApp notifications. The recipient must accept the transfer via WhatsApp or admin approval.
        </div>
        <form method="POST">
            <div class="form-group">
                <label><i class="bi bi-ticket-perforated"></i> Ticket Code *</label>
                <input type="text" name="ticket_code" required placeholder="Enter the ticket code to transfer">
            </div>
            <div class="form-group">
                <label><i class="bi bi-person"></i> Recipient Name *</label>
                <input type="text" name="to_name" required placeholder="Full name of the person receiving the ticket">
            </div>
            <div class="form-group">
                <label><i class="bi bi-telephone"></i> Recipient Phone *</label>
                <input type="tel" name="to_phone" required placeholder="+962XXXXXXXXX">
                <small style="color: #64748b;">Must be a valid WhatsApp number</small>
            </div>
            <div class="form-group">
                <label><i class="bi bi-chat"></i> Notes (Optional)</label>
                <textarea name="notes" rows="2" placeholder="Any additional information..."></textarea>
            </div>
            <button type="submit" name="request_transfer" class="btn btn-primary">
                <i class="bi bi-send"></i> Request Transfer
            </button>
        </form>
    </div>

    <!-- Transfer Requests List -->
    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-list-ul"></i> Transfer Requests</h2>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Transfer Code</th>
                        <th>Ticket Code</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th>Expires</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transfers as $transfer): ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($transfer['transfer_code']); ?></code></td>
                        <td><code><?php echo htmlspecialchars($transfer['ticket_code']); ?></code></td>
                        <td>
                            <strong><?php echo htmlspecialchars($transfer['from_customer_name']); ?></strong><br>
                            <small><?php echo htmlspecialchars($transfer['from_customer_phone']); ?></small>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($transfer['to_customer_name']); ?></strong><br>
                            <small><?php echo htmlspecialchars($transfer['to_customer_phone']); ?></small>
                        </td>
                        <td>
                            <?php
                            $status_class = '';
                            $status_text = '';
                            switch($transfer['current_status']) {
                                case 'pending':
                                    $status_class = 'badge-pending';
                                    $status_text = '⏳ Pending';
                                    break;
                                case 'approved':
                                    $status_class = 'badge-approved';
                                    $status_text = '✅ Approved';
                                    break;
                                case 'cancelled':
                                    $status_class = 'badge-cancelled';
                                    $status_text = '❌ Cancelled';
                                    break;
                                case 'expired':
                                    $status_class = 'badge-expired';
                                    $status_text = '⌛ Expired';
                                    break;
                            }
                            ?>
                            <span class="badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                        </td>
                        <td><?php echo date('M d, H:i', strtotime($transfer['requested_at'])); ?></td>
                        <td><?php echo date('M d, H:i', strtotime($transfer['expires_at'])); ?></td>
                        <td>
                            <?php if ($transfer['current_status'] == 'pending'): ?>
                                <a href="?action=approve&transfer_id=<?php echo $transfer['id']; ?>" 
                                   class="btn btn-sm btn-success" 
                                   onclick="return confirm('Approve this transfer? This will generate a new ticket for the recipient.')">
                                    <i class="bi bi-check-lg"></i> Approve
                                </a>
                                <a href="?action=reject&transfer_id=<?php echo $transfer['id']; ?>" 
                                   class="btn btn-sm btn-danger" 
                                   onclick="return confirm('Reject this transfer request?')">
                                    <i class="bi bi-x-lg"></i> Reject
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($transfers)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 60px;">
                            <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.5;"></i>
                            <p>No transfer requests yet</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // Dark mode toggle
    const darkModeToggle = document.createElement('button');
    darkModeToggle.innerHTML = '🌙';
    darkModeToggle.style.cssText = 'position:fixed; bottom:20px; right:20px; background:#4f46e5; color:white; border:none; border-radius:50%; width:50px; height:50px; cursor:pointer; z-index:1000;';
    darkModeToggle.onclick = () => document.body.classList.toggle('dark-mode');
    if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark-mode');
    document.body.appendChild(darkModeToggle);
</script>
</body>
</html>