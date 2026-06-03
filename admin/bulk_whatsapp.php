<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();

// Get current event details from database
$eventResult = $conn->query("SELECT event_name, event_date, event_time, venue, description FROM event_settings WHERE status = 'upcoming' ORDER BY event_date ASC LIMIT 1");
$currentEvent = $eventResult->fetch_assoc();

// If no upcoming event, get the latest event
if (!$currentEvent) {
    $eventResult = $conn->query("SELECT event_name, event_date, event_time, venue, description FROM event_settings ORDER BY event_date DESC LIMIT 1");
    $currentEvent = $eventResult->fetch_assoc();
}

// Get counts for statistics (filter by current event)
$selected_event_id = $_SESSION['selected_event_id'] ?? 0;
$totalCustomers = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE event_id = $selected_event_id")->fetch_assoc()['count'];
$pendingCustomers = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE (status = 'pending' OR status = 'registered') AND event_id = $selected_event_id")->fetch_assoc()['count'];
$paidCustomers = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'paid' AND event_id = $selected_event_id")->fetch_assoc()['count'];
$cancelledCustomers = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE status = 'cancelled' AND event_id = $selected_event_id")->fetch_assoc()['count'];

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
    $query = "SELECT name, phone, reservation_id, status, total_amount FROM reservations WHERE event_id = $selected_event_id";
    
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
            $msg = getTemplateMessage($message_template, $recipient, $custom_subject, $baseUrl, $include_ticket_link, $payment_link, $currentEvent);
        }
        
        // Personalize the message
        $msg = str_replace('{name}', $recipient['name'], $msg);
        $msg = str_replace('{reservation_id}', $recipient['reservation_id'], $msg);
        $msg = str_replace('{amount}', number_format($recipient['total_amount'], 2), $msg);
        $msg = str_replace('{ticket_link}', $baseUrl . "public/reservation_tickets.php?id=" . urlencode($recipient['reservation_id']), $msg);
        $msg = str_replace('{payment_link}', $payment_link ?: $baseUrl . "admin/dashboard.php", $msg);
        
        // Add event details placeholders
        if ($currentEvent) {
            $msg = str_replace('{event_name}', $currentEvent['event_name'], $msg);
            $msg = str_replace('{event_date}', date('F j, Y', strtotime($currentEvent['event_date'])), $msg);
            $msg = str_replace('{event_time}', date('g:i A', strtotime($currentEvent['event_time'])), $msg);
            $msg = str_replace('{venue}', $currentEvent['venue'], $msg);
            $msg = str_replace('{event_description}', $currentEvent['description'] ?? 'Join us for an amazing experience!', $msg);
        }
        
        // Send message - FIXED: sendWhatsAppMessage returns boolean, not array
        $result_send = sendWhatsAppMessage($recipient['phone'], $msg);
        
        if ($result_send === true) {
            $sentCount++;
        } else {
            $failedCount++;
            $errors[] = $recipient['name'] . ': Failed to send';
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

function getTemplateMessage($template, $customer, $custom_subject, $baseUrl, $include_ticket_link, $payment_link, $event) {
    $subject = $custom_subject ?: getDefaultSubject($template);
    $eventName = $event['event_name'] ?? 'our event';
    $eventDate = $event['event_date'] ? date('F j, Y', strtotime($event['event_date'])) : 'TBA';
    $eventTime = $event['event_time'] ? date('g:i A', strtotime($event['event_time'])) : 'TBA';
    $eventVenue = $event['venue'] ?? 'TBA';
    $eventDescription = $event['description'] ?? 'Join us for an amazing experience!';
    
    switch($template) {
        case 'event_reminder':
            $body = "🎪 *EVENT REMINDER | تذكير بالحدث* 🎪\n\n";
            $body .= "Dear {name} | عزيزنا {name},\n\n";
            $body .= "This is a friendly reminder about our upcoming event!\n";
            $body .= "هذا تذكير لحدثنا القادم!\n\n";
            $body .= "🎪 *Event | الحدث:* {event_name}\n";
            $body .= "📅 *Date | التاريخ:* {event_date}\n";
            $body .= "⏰ *Time | الوقت:* {event_time}\n";
            $body .= "📍 *Venue | المكان:* {venue}\n\n";
            $body .= "📋 *Reservation ID | رقم الحجز:* {reservation_id}\n\n";
            $body .= "{event_description}\n\n";
            $body .= "We look forward to seeing you there! 🎉\n";
            $body .= "نتطلع لرؤيتكم هناك! 🎉";
            break;
            
        case 'payment_reminder':
            $body = "💰 *PAYMENT REMINDER | تذكير بالدفع* 💰\n\n";
            $body .= "Dear {name} | عزيزنا {name},\n\n";
            $body .= "We noticed that your payment for reservation #{reservation_id} is still pending.\n";
            $body .= "نحن نلاحظ أن دفعتك للحجز رقم {reservation_id} لا تزال معلقة.\n\n";
            $body .= "💰 *Amount Due | المبلغ المستحق:* {amount} JOD\n\n";
            $body .= "Please complete your payment to secure your reservation.\n";
            $body .= "يرجى إكمال الدفع لتأمين حجزك.\n\n";
            if ($payment_link) {
                $body .= "🔗 Payment Link | رابط الدفع: {payment_link}\n\n";
            }
            $body .= "Event | الحدث: {event_name} on {event_date}\n\n";
            $body .= "If you've already made the payment, please disregard this message.\n";
            $body .= "إذا كنت قد قمت بالدفع بالفعل، يرجى تجاهل هذه الرسالة.\n\n";
            $body .= "Thank you | شكراً لك";
            break;
            
        case 'thank_you':
            $body = "🙏 *THANK YOU | شكراً لك* 🙏\n\n";
            $body .= "Dear {name} | عزيزنا {name},\n\n";
            $body .= "Thank you for choosing {event_name}!\n";
            $body .= "شكراً لاختيارك {event_name}!\n\n";
            $body .= "We truly appreciate your support.\n";
            $body .= "نحن نقدر دعمك حقاً.\n\n";
            $body .= "Event Date | تاريخ الحدث: {event_date}\n";
            $body .= "Venue | المكان: {venue}\n\n";
            $body .= "Best regards, | مع أطيب التحيات،\nEvent Team | فريق الحدث";
            break;
            
        case 'ticket_reminder':
            $body = "🎫 *YOUR TICKETS ARE READY | تذاكرك جاهزة* 🎫\n\n";
            $body .= "Dear {name} | عزيزنا {name},\n\n";
            $body .= "Your tickets for {event_name} are ready!\n";
            $body .= "تذاكرك لحدث {event_name} جاهزة!\n\n";
            $body .= "🎫 *Reservation ID | رقم الحجز:* {reservation_id}\n";
            $body .= "📅 *Event Date | تاريخ الحدث:* {event_date}\n";
            $body .= "📍 *Venue | المكان:* {venue}\n\n";
            if ($include_ticket_link) {
                $body .= "📎 *Download your tickets here | حمل تذاكرك من هنا:*\n";
                $body .= "{ticket_link}\n\n";
            }
            $body .= "Please remember to bring your ticket (digital or printed) to the event.\n";
            $body .= "يرجى تذكر إحضار تذكرتك (رقمية أو مطبوعة) إلى الحدث.\n\n";
            $body .= "We can't wait to welcome you! 🎉\n";
            $body .= "لا يمكننا الانتظار لاستقبالك! 🎉";
            break;
            
        case 'special_offer':
            $body = "🎁 *SPECIAL OFFER | عرض خاص* 🎁\n\n";
            $body .= "Dear {name} | عزيزنا {name},\n\n";
            $body .= "🎉 *EXCLUSIVE OFFER FOR {event_name}!* 🎉\n";
            $body .= "🎉 *عرض حصري لحدث {event_name}!* 🎉\n\n";
            $body .= "As a valued customer, we're offering you a 15% discount on your next booking!\n";
            $body .= "كعميل مميز، نقدم لك خصم 15% على حجزك القادم!\n\n";
            $body .= "Use code | استخدم الرمز: WELCOME15\n\n";
            $body .= "Book now and save! | احجز الآن ووفر!";
            break;
            
        case 'event_update':
            $body = "📢 *EVENT UPDATE | تحديث الحدث* 📢\n\n";
            $body .= "Dear {name} | عزيزنا {name},\n\n";
            $body .= "*Important Update Regarding {event_name}*\n";
            $body .= "*تحديث مهم بخصوص {event_name}*\n\n";
            $body .= "We have some exciting updates about the upcoming event!\n";
            $body .= "لدينا بعض التحديثات المثيرة حول الحدث القادم!\n\n";
            $body .= "📅 *Date | التاريخ:* {event_date}\n";
            $body .= "📍 *Venue | المكان:* {venue}\n\n";
            $body .= "Your reservation #{reservation_id} remains confirmed.\n";
            $body .= "حجزك رقم {reservation_id} لا يزال مؤكداً.\n\n";
            $body .= "Best regards, | مع أطيب التحيات،\nEvent Team | فريق الحدث";
            break;
            
        default:
            $body = "📋 *MESSAGE FROM EVENT TEAM | رسالة من فريق الحدث* 📋\n\n";
            $body .= "Dear {name} | عزيزنا {name},\n\n";
            $body .= "This is a message from {event_name} regarding your reservation #{reservation_id}.\n";
            $body .= "هذه رسالة من {event_name} بخصوص حجزك رقم {reservation_id}.\n\n";
            $body .= "Event Date | تاريخ الحدث: {event_date}\n";
            $body .= "Venue | المكان: {venue}\n\n";
            $body .= "Best regards, | مع أطيب التحيات،\nEvent Team | فريق الحدث";
    }
    
    return $body;
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
        
        .event-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 16px;
            margin-bottom: 25px;
        }
        .event-info h3 { margin-bottom: 10px; }
        .event-info p { font-size: 14px; opacity: 0.9; margin: 5px 0; }
        
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
            
            <!-- Current Event Info -->
            <?php if ($currentEvent): ?>
            <div class="event-info">
                <h3>🎪 Current Event: <?php echo htmlspecialchars($currentEvent['event_name']); ?></h3>
                <p>📅 Date: <?php echo date('F j, Y', strtotime($currentEvent['event_date'])); ?> at <?php echo date('g:i A', strtotime($currentEvent['event_time'])); ?></p>
                <p>📍 Venue: <?php echo htmlspecialchars($currentEvent['venue']); ?></p>
                <p>📝 <?php echo htmlspecialchars($currentEvent['description'] ?? 'Join us for an amazing experience!'); ?></p>
            </div>
            <?php else: ?>
            <div class="event-info" style="background: #f59e0b;">
                <h3>⚠️ No Events Found</h3>
                <p>Please create an event in Settings first.</p>
            </div>
            <?php endif; ?>
            
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
                <strong>📝 Available Placeholders:</strong><br>
                <code>{name}</code> - Customer name<br>
                <code>{reservation_id}</code> - Reservation ID<br>
                <code>{amount}</code> - Total amount<br>
                <code>{event_name}</code> - Current event name<br>
                <code>{event_date}</code> - Event date<br>
                <code>{event_time}</code> - Event time<br>
                <code>{venue}</code> - Event venue<br>
                <code>{event_description}</code> - Event description<br>
                <code>{ticket_link}</code> - Ticket download link<br>
                <code>{payment_link}</code> - Payment link
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
                    <textarea name="custom_message" id="customMessage" placeholder="Type your custom message here..."></textarea>
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
        const eventData = {
            name: '<?php echo addslashes($currentEvent['event_name'] ?? 'Annual Event'); ?>',
            date: '<?php echo $currentEvent ? date('F j, Y', strtotime($currentEvent['event_date'])) : 'TBA'; ?>',
            time: '<?php echo $currentEvent ? date('g:i A', strtotime($currentEvent['event_time'])) : 'TBA'; ?>',
            venue: '<?php echo addslashes($currentEvent['venue'] ?? 'TBA'); ?>',
            description: '<?php echo addslashes($currentEvent['description'] ?? 'Join us for an amazing experience!'); ?>'
        };
        
        const sampleCustomer = {
            name: 'John Doe',
            reservation_id: 'RES0001-15G10A3T2K-A3F4R5',
            amount: '230.00'
        };
        
        function updatePreview() {
            const template = document.getElementById('messageTemplate').value;
            const includeTicketLink = document.getElementById('includeTicketLink').checked;
            const paymentLink = document.querySelector('input[name="payment_link"]')?.value || '';
            const customMessage = document.getElementById('customMessage').value;
            
            let preview = '';
            
            if (template === 'custom') {
                preview = customMessage || 'Enter your custom message above...';
            } else {
                // Get template from PHP (simplified for preview)
                preview = getTemplatePreview(template);
            }
            
            // Replace placeholders
            preview = preview.replace(/{name}/g, sampleCustomer.name);
            preview = preview.replace(/{reservation_id}/g, sampleCustomer.reservation_id);
            preview = preview.replace(/{amount}/g, sampleCustomer.amount);
            preview = preview.replace(/{event_name}/g, eventData.name);
            preview = preview.replace(/{event_date}/g, eventData.date);
            preview = preview.replace(/{event_time}/g, eventData.time);
            preview = preview.replace(/{venue}/g, eventData.venue);
            preview = preview.replace(/{event_description}/g, eventData.description);
            preview = preview.replace(/{ticket_link}/g, includeTicketLink ? 'http://ticketing.local/public/reservation_tickets.php?id=' + sampleCustomer.reservation_id : '[Ticket link not included]');
            preview = preview.replace(/{payment_link}/g, paymentLink || 'http://ticketing.local/admin/dashboard.php');
            
            document.getElementById('messagePreview').innerHTML = preview.replace(/\n/g, '<br>');
        }
        
        function getTemplatePreview(template) {
            const previews = {
                event_reminder: `🎪 *EVENT REMINDER | تذكير بالحدث* 🎪

Dear {name} | عزيزنا {name},

This is a friendly reminder about our upcoming event!
هذا تذكير لحدثنا القادم!

🎪 *Event | الحدث:* {event_name}
📅 *Date | التاريخ:* {event_date}
⏰ *Time | الوقت:* {event_time}
📍 *Venue | المكان:* {venue}

📋 *Reservation ID | رقم الحجز:* {reservation_id}

{event_description}

We look forward to seeing you there! 🎉
نتطلع لرؤيتكم هناك! 🎉`,
                payment_reminder: `💰 *PAYMENT REMINDER | تذكير بالدفع* 💰

Dear {name} | عزيزنا {name},

We noticed that your payment for reservation #{reservation_id} is still pending.
نحن نلاحظ أن دفعتك للحجز رقم {reservation_id} لا تزال معلقة.

💰 *Amount Due | المبلغ المستحق:* {amount} JOD

Please complete your payment to secure your reservation.
يرجى إكمال الدفع لتأمين حجزك.

Thank you | شكراً لك`,
                thank_you: `🙏 *THANK YOU | شكراً لك* 🙏

Dear {name} | عزيزنا {name},

Thank you for choosing {event_name}!
شكراً لاختيارك {event_name}!

Best regards | مع أطيب التحيات`,
                ticket_reminder: `🎫 *YOUR TICKETS ARE READY | تذاكرك جاهزة* 🎫

Dear {name} | عزيزنا {name},

Your tickets for {event_name} are ready!
تذاكرك لحدث {event_name} جاهزة!

🎫 *Reservation ID | رقم الحجز:* {reservation_id}`,
                special_offer: `🎁 *SPECIAL OFFER | عرض خاص* 🎁

Dear {name} | عزيزنا {name},

🎉 *EXCLUSIVE OFFER!* 🎉
🎉 *عرض حصري!* 🎉

Use code | استخدم الرمز: WELCOME15`,
                event_update: `📢 *EVENT UPDATE | تحديث الحدث* 📢

Dear {name} | عزيزنا {name},

*Important Update Regarding {event_name}*
*تحديث مهم بخصوص {event_name}*

Best regards | مع أطيب التحيات`
            };
            return previews[template] || previews.event_reminder;
        }
        
        function toggleFields() {
            const template = document.getElementById('messageTemplate').value;
            const customMessageGroup = document.getElementById('customMessageGroup');
            const paymentLinkGroup = document.getElementById('paymentLinkGroup');
            const includeTicketLink = document.getElementById('includeTicketLink');
            
            customMessageGroup.style.display = template === 'custom' ? 'block' : 'none';
            paymentLinkGroup.style.display = template === 'payment_reminder' ? 'block' : 'none';
            
            if (template === 'ticket_reminder') {
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
        document.querySelector('input[name="payment_link"]')?.addEventListener('input', updatePreview);
        document.getElementById('customMessage').addEventListener('input', updatePreview);
        
        // Initial setup
        toggleFields();
        updatePreview();
    </script>
</body>
</html>