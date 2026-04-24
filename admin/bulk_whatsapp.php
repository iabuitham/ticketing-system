<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();

// Get counts for statistics
$totalCustomers = $conn->query("SELECT COUNT(*) as count FROM reservations")->fetch_assoc()['count'];
$pendingCustomers = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'pending' OR status = 'registered'")->fetch_assoc()['count'];
$paidCustomers = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'paid'")->fetch_assoc()['count'];
$cancelledCustomers = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'cancelled'")->fetch_assoc()['count'];

$message = '';
$messageType = '';
$sentCount = 0;
$failedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient_group = $_POST['recipient_group'];
    $message_template = $_POST['message_template'];
    $custom_subject = isset($_POST['custom_subject']) ? trim($_POST['custom_subject']) : '';
    $custom_message = isset($_POST['custom_message']) ? trim($_POST['custom_message']) : '';
    $include_ticket_link = isset($_POST['include_ticket_link']) ? true : false;
    $payment_link = isset($_POST['payment_link']) ? trim($_POST['payment_link']) : '';
    
    // Build query for recipients
    $query = "SELECT name, phone, reservation_id, status, total_amount FROM reservations WHERE 1=1";
    
    switch($recipient_group) {
        case 'pending':
            $query .= " AND status IN ('pending', 'registered')";
            break;
        case 'paid':
            $query .= " AND status = 'paid'";
            break;
        case 'cancelled':
            $query .= " AND status = 'cancelled'";
            break;
        case 'all':
        default:
            break;
    }
    
    $result = $conn->query($query);
    $recipients = $result->fetch_all(MYSQLI_ASSOC);
    
    $baseUrl = getBaseUrl();
    $sentCount = 0;
    $failedCount = 0;
    $errors = [];
    
    foreach ($recipients as $recipient) {
        // Build the message
        if ($message_template == 'custom') {
            $msg = $custom_message;
        } else {
            $msg = getTemplateMessage($message_template, $recipient, $custom_subject, $baseUrl, $include_ticket_link, $payment_link);
        }
        
        // Personalize the message
        $msg = str_replace('{name}', $recipient['name'], $msg);
        $msg = str_replace('{reservation_id}', $recipient['reservation_id'], $msg);
        $msg = str_replace('{amount}', number_format($recipient['total_amount'], 2), $msg);
        $msg = str_replace('{ticket_link}', $baseUrl . "admin/print_ticket.php?reservation_id=" . urlencode($recipient['reservation_id']), $msg);
        $msg = str_replace('{payment_link}', $payment_link ?: $baseUrl . "admin/dashboard.php", $msg);
        
        // Send message
        $result_send = sendWhatsAppMessage($recipient['phone'], $msg);
        if ($result_send['success'] || isset($result_send['simulated'])) {
            $sentCount++;
        } else {
            $failedCount++;
            $errors[] = $recipient['name'] . ': ' . ($result_send['response']['error']['message'] ?? 'Unknown error');
        }
        
        // Small delay to avoid rate limiting
        usleep(500000);
    }
    
    $message = "✅ Messages sent: $sentCount | ❌ Failed: $failedCount";
    if (!empty($errors) && $failedCount <= 5) {
        $message .= "<br><small>Errors: " . implode(', ', array_slice($errors, 0, 3)) . "</small>";
    }
    $messageType = $sentCount > 0 ? 'success' : 'error';
}

function getTemplateMessage($template, $customer, $custom_subject, $baseUrl, $include_ticket_link, $payment_link) {
    $subject = $custom_subject ?: getDefaultSubject($template);
    
    switch($template) {
        case 'event_reminder':
            $body = "Dear {name},\n\n";
            $body .= "This is a friendly reminder about our upcoming event!\n\n";
            $body .= "📅 Date: " . date('F j, Y', strtotime('+7 days')) . "\n";
            $body .= "📍 Venue: Grand Hall, Amman\n";
            $body .= "⏰ Time: 6:00 PM\n\n";
            $body .= "We look forward to seeing you there!\n\n";
            $body .= "Best regards,\nEvent Team";
            break;
            
        case 'payment_reminder':
            $body = "Dear {name},\n\n";
            $body .= "We noticed that your payment for reservation #{reservation_id} is still pending.\n\n";
            $body .= "💰 Amount Due: {amount} JOD\n\n";
            $body .= "Please complete your payment to secure your reservation.\n\n";
            if ($payment_link) {
                $body .= "🔗 Payment Link: {payment_link}\n\n";
            }
            $body .= "If you've already made the payment, please disregard this message.\n\n";
            $body .= "Thank you,\nEvent Team";
            break;
            
        case 'thank_you':
            $body = "Dear {name},\n\n";
            $body .= "Thank you for choosing our event!\n\n";
            $body .= "We truly appreciate your support and look forward to providing you with an unforgettable experience.\n\n";
            $body .= "If you have any questions, please don't hesitate to contact us.\n\n";
            $body .= "Best regards,\nEvent Team";
            break;
            
        case 'ticket_reminder':
            $body = "Dear {name},\n\n";
            $body .= "Your tickets for the upcoming event are ready!\n\n";
            $body .= "🎫 Reservation ID: {reservation_id}\n\n";
            if ($include_ticket_link) {
                $body .= "📎 Download your tickets here:\n";
                $body .= "{ticket_link}\n\n";
            }
            $body .= "Please remember to bring your ticket (digital or printed) to the event.\n\n";
            $body .= "We can't wait to welcome you!\n\n";
            $body .= "Best regards,\nEvent Team";
            break;
            
        case 'special_offer':
            $body = "Dear {name},\n\n";
            $body .= "🎉 EXCLUSIVE OFFER JUST FOR YOU! 🎉\n\n";
            $body .= "As a valued customer, we're offering you a 15% discount on your next booking!\n\n";
            $body .= "Use code: WELCOME15 at checkout.\n\n";
            $body .= "Book now and save!\n\n";
            $body .= "Best regards,\nEvent Team";
            break;
            
        case 'event_update':
            $body = "Dear {name},\n\n";
            $body .= "Important Update Regarding Your Reservation #{reservation_id}\n\n";
            $body .= "We have some exciting updates about the upcoming event!\n\n";
            $body .= "• New performers added\n";
            $body .= "• Extended hours\n";
            $body .= "• Special giveaways\n\n";
            $body .= "Check our website for more details.\n\n";
            $body .= "Best regards,\nEvent Team";
            break;
            
        default:
            $body = "Dear {name},\n\n";
            $body .= "This is a message from our event team regarding your reservation #{reservation_id}.\n\n";
            $body .= "For more information, please contact us.\n\n";
            $body .= "Best regards,\nEvent Team";
    }
    
    return "*" . strtoupper($subject) . "*\n\n" . $body;
}

function getDefaultSubject($template) {
    switch($template) {
        case 'event_reminder': return 'Event Reminder';
        case 'payment_reminder': return 'Payment Reminder';
        case 'thank_you': return 'Thank You';
        case 'ticket_reminder': return 'Your Tickets Are Ready';
        case 'special_offer': return 'Special Offer Just For You';
        case 'event_update': return 'Event Update';
        default: return 'Message from Event Team';
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#667eea">
    <title>Bulk WhatsApp - Ticketing System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 900px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 { margin-bottom: 10px; color: #333; }
        .subtitle { color: #666; margin-bottom: 30px; }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-bottom: 25px;
        }
        .stat-card {
            background: #f8fafc;
            border-radius: 16px;
            padding: 15px;
            text-align: center;
        }
        .stat-number { font-size: 28px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 12px; color: #666; margin-top: 5px; }
        
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        select, textarea, input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
        }
        textarea { resize: vertical; min-height: 200px; font-family: monospace; }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
        }
        .checkbox-group input {
            width: auto;
            margin: 0;
        }
        
        .message-preview {
            background: #f0fdf4;
            border: 1px solid #d1fae5;
            border-radius: 16px;
            padding: 20px;
            margin: 20px 0;
        }
        .message-preview h4 {
            color: #065f46;
            margin-bottom: 10px;
        }
        .message-preview .preview-content {
            background: white;
            padding: 15px;
            border-radius: 12px;
            font-family: monospace;
            white-space: pre-wrap;
            font-size: 13px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-primary {
            background: #25D366;
            color: white;
            width: 100%;
        }
        .btn-primary:hover {
            background: #128C7E;
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: #6c757d;
            color: white;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        .alert {
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .info-box {
            background: #e7f3ff;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        .actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .recipient-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        .badge-pending { background: #fef3c7; color: #92400e; }
        .badge-paid { background: #d1fae5; color: #065f46; }
        .badge-cancelled { background: #fee2e2; color: #991b1b; }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .card { padding: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>📱 Bulk WhatsApp Messaging</h1>
            <p class="subtitle">Send mass messages to your customers</p>
            
            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo $totalCustomers; ?></div>
                    <div class="stat-label">Total Customers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $pendingCustomers; ?></div>
                    <div class="stat-label">Pending Payment</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $paidCustomers; ?></div>
                    <div class="stat-label">Paid Customers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo $cancelledCustomers; ?></div>
                    <div class="stat-label">Cancelled</div>
                </div>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <div class="info-box">
                <strong>⚠️ Important Notes:</strong><br>
                • Bulk messages may take time to send (approx 2-3 seconds per message)<br>
                • Add delays between messages to avoid rate limiting<br>
                • Test with a small group first<br>
                • Ensure you have WhatsApp API credits<br>
                • Use {name}, {reservation_id}, {amount}, {ticket_link}, {payment_link} for personalization
            </div>
            
            <form method="POST" id="bulkForm">
                <div class="form-group">
                    <label>Select Recipients</label>
                    <select name="recipient_group" id="recipientGroup" required>
                        <option value="all">📋 All Customers (<?php echo $totalCustomers; ?> recipients)</option>
                        <option value="pending">⏳ Pending Payment (<?php echo $pendingCustomers; ?> recipients)</option>
                        <option value="paid">✅ Paid Customers (<?php echo $paidCustomers; ?> recipients)</option>
                        <option value="cancelled">❌ Cancelled (<?php echo $cancelledCustomers; ?> recipients)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Message Template</label>
                    <select name="message_template" id="messageTemplate" onchange="updatePreview()" required>
                        <option value="event_reminder">📅 Event Reminder</option>
                        <option value="payment_reminder">💰 Payment Reminder</option>
                        <option value="thank_you">🙏 Thank You Message</option>
                        <option value="ticket_reminder">🎫 Ticket Reminder</option>
                        <option value="special_offer">🎁 Special Offer</option>
                        <option value="event_update">📢 Event Update</option>
                        <option value="custom">✏️ Custom Message</option>
                    </select>
                </div>
                
                <div class="form-group" id="subjectGroup">
                    <label>Subject/Custom Title</label>
                    <input type="text" name="custom_subject" id="customSubject" placeholder="Enter message subject..." value="Message from Event Team">
                </div>
                
                <div class="form-group" id="customMessageGroup" style="display: none;">
                    <label>Custom Message</label>
                    <textarea name="custom_message" id="customMessage" placeholder="Type your custom message here...
                    
Available placeholders:
{name} - Customer name
{reservation_id} - Reservation ID
{amount} - Total amount
{ticket_link} - Ticket download link
{payment_link} - Payment link"></textarea>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="include_ticket_link" id="includeTicketLink">
                    <label for="includeTicketLink" style="margin: 0;">Include ticket download link in message</label>
                </div>
                
                <div class="form-group" id="paymentLinkGroup" style="display: none;">
                    <label>Payment Link (for payment reminder)</label>
                    <input type="text" name="payment_link" placeholder="https://yourdomain.com/payment">
                </div>
                
                <!-- Message Preview -->
                <div class="message-preview">
                    <h4>📄 Message Preview</h4>
                    <div class="preview-content" id="messagePreview">
                        Select a template to preview...
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary" onclick="return confirm('⚠️ WARNING: This will send WhatsApp messages to ALL selected recipients.\n\nAre you absolutely sure you want to proceed?')">
                    📱 Send Messages
                </button>
            </form>
            
            <div class="actions">
                <a href="dashboard.php" class="btn btn-secondary" style="width: 100%;">← Back to Dashboard</a>
            </div>
        </div>
    </div>
    
    <script>
        const sampleCustomer = {
            name: 'John Doe',
            reservation_id: 'RES0001-15G10A3T2K-A3F4R5',
            amount: '230.00',
            ticket_link: 'http://ticketing.local/admin/print_ticket.php?reservation_id=RES0001-15G10A3T2K-A3F4R5',
            payment_link: 'http://ticketing.local/admin/dashboard.php'
        };
        
        const templates = {
            event_reminder: `*EVENT REMINDER*

Dear {name},

This is a friendly reminder about our upcoming event!

📅 Date: <?php echo date('F j, Y', strtotime('+7 days')); ?>
📍 Venue: Grand Hall, Amman
⏰ Time: 6:00 PM

We look forward to seeing you there!

Best regards,
Event Team`,
            
            payment_reminder: `*PAYMENT REMINDER*

Dear {name},

We noticed that your payment for reservation #{reservation_id} is still pending.

💰 Amount Due: {amount} JOD

Please complete your payment to secure your reservation.

If you've already made the payment, please disregard this message.

Thank you,
Event Team`,
            
            thank_you: `*THANK YOU*

Dear {name},

Thank you for choosing our event!

We truly appreciate your support and look forward to providing you with an unforgettable experience.

If you have any questions, please don't hesitate to contact us.

Best regards,
Event Team`,
            
            ticket_reminder: `*YOUR TICKETS ARE READY*

Dear {name},

Your tickets for the upcoming event are ready!

🎫 Reservation ID: {reservation_id}

📎 Download your tickets here:
{ticket_link}

Please remember to bring your ticket (digital or printed) to the event.

We can't wait to welcome you!

Best regards,
Event Team`,
            
            special_offer: `*SPECIAL OFFER JUST FOR YOU*

Dear {name},

🎉 EXCLUSIVE OFFER JUST FOR YOU! 🎉

As a valued customer, we're offering you a 15% discount on your next booking!

Use code: WELCOME15 at checkout.

Book now and save!

Best regards,
Event Team`,
            
            event_update: `*EVENT UPDATE*

Dear {name},

Important Update Regarding Your Reservation #{reservation_id}

We have some exciting updates about the upcoming event!

• New performers added
• Extended hours
• Special giveaways

Check our website for more details.

Best regards,
Event Team`
        };
        
        function updatePreview() {
            const template = document.getElementById('messageTemplate').value;
            const customSubject = document.getElementById('customSubject').value;
            const includeTicketLink = document.getElementById('includeTicketLink').checked;
            const paymentLink = document.querySelector('input[name="payment_link"]').value;
            const customMessage = document.getElementById('customMessage').value;
            
            let preview = '';
            
            if (template === 'custom') {
                preview = customMessage || 'Enter your custom message above...';
            } else {
                preview = templates[template] || templates.event_reminder;
            }
            
            // Replace placeholders
            preview = preview.replace(/{name}/g, sampleCustomer.name);
            preview = preview.replace(/{reservation_id}/g, sampleCustomer.reservation_id);
            preview = preview.replace(/{amount}/g, sampleCustomer.amount);
            preview = preview.replace(/{ticket_link}/g, includeTicketLink ? sampleCustomer.ticket_link : '[Ticket link not included]');
            preview = preview.replace(/{payment_link}/g, paymentLink || sampleCustomer.payment_link);
            
            document.getElementById('messagePreview').innerHTML = preview.replace(/\n/g, '<br>');
        }
        
        function toggleFields() {
            const template = document.getElementById('messageTemplate').value;
            const customMessageGroup = document.getElementById('customMessageGroup');
            const paymentLinkGroup = document.getElementById('paymentLinkGroup');
            const includeTicketLink = document.getElementById('includeTicketLink');
            
            customMessageGroup.style.display = template === 'custom' ? 'block' : 'none';
            paymentLinkGroup.style.display = template === 'payment_reminder' ? 'block' : 'none';
            
            if (template === 'ticket_reminder') {
                includeTicketLink.checked = true;
                includeTicketLink.disabled = false;
            } else {
                includeTicketLink.disabled = true;
                includeTicketLink.checked = false;
            }
        }
        
        document.getElementById('messageTemplate').addEventListener('change', function() {
            toggleFields();
            updatePreview();
        });
        
        document.getElementById('customSubject').addEventListener('input', updatePreview);
        document.getElementById('includeTicketLink').addEventListener('change', updatePreview);
        document.querySelector('input[name="payment_link"]').addEventListener('input', updatePreview);
        document.getElementById('customMessage').addEventListener('input', updatePreview);
        
        // Initial setup
        toggleFields();
        updatePreview();
    </script>
</body>
</html>