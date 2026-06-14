<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Handle file upload
$uploadedMedia = null;
$mediaType = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $recipient_group = $_POST['recipient_group'];
    $message_template = $_POST['message_template'];
    $custom_subject = isset($_POST['custom_subject']) ? trim($_POST['custom_subject']) : '';
    $custom_message = isset($_POST['custom_message']) ? trim($_POST['custom_message']) : '';
    $include_ticket_link = isset($_POST['include_ticket_link']) ? true : false;
    $payment_link = isset($_POST['payment_link']) ? trim($_POST['payment_link']) : '';
    $include_media = isset($_POST['include_media']) ? true : false;
    
    // Handle media file upload
    if ($include_media && isset($_FILES['media_file']) && $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['media_file'];
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'mp4', 'mov', 'mp3', 'wav'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $uploadDir = '../uploads/bulk_media/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $fileName = 'bulk_' . time() . '_' . uniqid() . '.' . $ext;
            $uploadPath = $uploadDir . $fileName;
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                $uploadedMedia = $uploadPath;
                // Determine media type
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $mediaType = 'image';
                } elseif ($ext === 'pdf') {
                    $mediaType = 'document';
                } elseif (in_array($ext, ['mp4', 'mov'])) {
                    $mediaType = 'video';
                } elseif (in_array($ext, ['mp3', 'wav'])) {
                    $mediaType = 'audio';
                }
            }
        }
    }
    
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
        
        // Send message - with optional media
        if ($include_media && $uploadedMedia && $mediaType) {
            // Send text message first
            $result_send = sendWhatsAppMessage($recipient['phone'], $msg);
            
            if ($result_send === true) {
                // Then send media based on type
                if ($mediaType === 'image') {
                    $mediaSent = sendWhatsAppImage($recipient['phone'], $baseUrl . $uploadedMedia, '');
                } elseif ($mediaType === 'document') {
                    // Check if function exists
                    if (function_exists('sendWhatsAppDocument')) {
                        $mediaSent = sendWhatsAppDocument($recipient['phone'], $baseUrl . $uploadedMedia, '');
                    } else {
                        $mediaSent = true; // Skip if function doesn't exist
                    }
                } else {
                    $mediaSent = true;
                }
                
                if ($mediaSent !== false) {
                    $sentCount++;
                } else {
                    $failedCount++;
                    $errors[] = $recipient['name'] . ': Media failed to send';
                }
            } else {
                $failedCount++;
                $errors[] = $recipient['name'] . ': Failed to send';
            }
        } else {
            // Send only text message
            $result_send = sendWhatsAppMessage($recipient['phone'], $msg);
            
            if ($result_send === true) {
                $sentCount++;
            } else {
                $failedCount++;
                $errors[] = $recipient['name'] . ': Failed to send';
            }
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

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Bulk WhatsApp - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        body.dark-mode { background: #0f172a; }
        .container { max-width: 900px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        body.dark-mode .card { background: #1e293b; color: #e2e8f0; }
        h1 { margin-bottom: 10px; color: #333; }
        body.dark-mode h1 { color: #e2e8f0; }
        .subtitle { color: #666; margin-bottom: 30px; }
        body.dark-mode .subtitle { color: #94a3b8; }
        
        .event-info {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 16px;
            margin-bottom: 25px;
        }
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
        body.dark-mode .stat-card { background: #0f172a; }
        .stat-number { font-size: 28px; font-weight: bold; color: #667eea; }
        .stat-label { font-size: 12px; color: #666; margin-top: 5px; }
        body.dark-mode .stat-label { color: #94a3b8; }
        
        .form-group { margin-bottom: 20px; }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #555;
        }
        body.dark-mode label { color: #cbd5e1; }
        select, textarea, input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
        }
        body.dark-mode select, body.dark-mode textarea, body.dark-mode input {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }
        textarea { resize: vertical; min-height: 200px; font-family: monospace; }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 15px 0;
        }
        .checkbox-group input { width: auto; margin: 0; }
        
        .media-upload-area {
            border: 2px dashed #cbd5e1;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            margin: 15px 0;
            cursor: pointer;
            transition: all 0.2s;
        }
        .media-upload-area:hover {
            border-color: #667eea;
            background: #f8fafc;
        }
        .media-preview {
            margin-top: 15px;
            display: none;
        }
        .media-preview.active { display: block; }
        .media-preview img, .media-preview video {
            max-width: 200px;
            max-height: 200px;
            border-radius: 12px;
            margin-top: 10px;
        }
        .remove-media {
            background: #ef4444;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 12px;
            margin-top: 10px;
        }
        
        .message-preview {
            background: #f0fdf4;
            border: 1px solid #d1fae5;
            border-radius: 16px;
            padding: 20px;
            margin: 20px 0;
        }
        body.dark-mode .message-preview { background: #064e3b; border-color: #065f46; }
        .message-preview h4 { color: #065f46; margin-bottom: 10px; }
        body.dark-mode .message-preview h4 { color: #6ee7b7; }
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
        body.dark-mode .message-preview .preview-content { background: #0f172a; color: #e2e8f0; }
        
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
        .btn-primary:hover { background: #128C7E; transform: translateY(-2px); }
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
        body.dark-mode .info-box { background: #1e293b; border-left-color: #667eea; }
        .actions { display: flex; gap: 10px; margin-top: 20px; }
        
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
            
            <?php if ($currentEvent): ?>
            <div class="event-info">
                <h3>🎪 Current Event: <?php echo htmlspecialchars($currentEvent['event_name']); ?></h3>
                <p>📅 Date: <?php echo date('F j, Y', strtotime($currentEvent['event_date'])); ?> at <?php echo date('g:i A', strtotime($currentEvent['event_time'])); ?></p>
                <p>📍 Venue: <?php echo htmlspecialchars($currentEvent['venue']); ?></p>
            </div>
            <?php else: ?>
            <div class="event-info" style="background: #f59e0b;">
                <h3>⚠️ No Events Found</h3>
                <p>Please create an event in Settings first.</p>
            </div>
            <?php endif; ?>
            
            <div class="stats-grid">
                <div class="stat-card"><div class="stat-number"><?php echo $totalCustomers; ?></div><div class="stat-label">Total Customers</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $pendingCustomers; ?></div><div class="stat-label">Pending Payment</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $paidCustomers; ?></div><div class="stat-label">Paid Customers</div></div>
                <div class="stat-card"><div class="stat-number"><?php echo $cancelledCustomers; ?></div><div class="stat-label">Cancelled</div></div>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
            <?php endif; ?>
            
            <div class="info-box">
                <strong>📝 Available Placeholders:</strong><br>
                <code>{name}</code> - Customer name<br>
                <code>{reservation_id}</code> - Reservation ID<br>
                <code>{amount}</code> - Total amount<br>
                <code>{event_name}</code> - Event name<br>
                <code>{event_date}</code> - Event date<br>
                <code>{event_time}</code> - Event time<br>
                <code>{venue}</code> - Venue<br>
                <code>{ticket_link}</code> - Ticket link<br>
                <code>{payment_link}</code> - Payment link
            </div>
            
            <form method="POST" id="bulkForm" enctype="multipart/form-data">
                <div class="form-group">
                    <label>Select Recipients</label>
                    <select name="recipient_group" required>
                        <option value="all">📋 All Customers (<?php echo $totalCustomers; ?>)</option>
                        <option value="pending">⏳ Pending Payment (<?php echo $pendingCustomers; ?>)</option>
                        <option value="paid">✅ Paid Customers (<?php echo $paidCustomers; ?>)</option>
                        <option value="cancelled">❌ Cancelled (<?php echo $cancelledCustomers; ?>)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Message Template</label>
                    <select name="message_template" id="messageTemplate" onchange="updatePreview()" required>
                        <option value="event_reminder">📅 Event Reminder</option>
                        <option value="payment_reminder">💰 Payment Reminder</option>
                        <option value="thank_you">🙏 Thank You</option>
                        <option value="ticket_reminder">🎫 Ticket Reminder</option>
                        <option value="special_offer">🎁 Special Offer</option>
                        <option value="event_update">📢 Event Update</option>
                        <option value="custom">✏️ Custom Message</option>
                    </select>
                </div>
                
                <div class="form-group" id="customMessageGroup" style="display: none;">
                    <label>Custom Message</label>
                    <textarea name="custom_message" id="customMessage" rows="5" placeholder="Type your custom message here..."></textarea>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="include_ticket_link" id="includeTicketLink">
                    <label>Include ticket download link</label>
                </div>
                
                <div class="checkbox-group">
                    <input type="checkbox" name="include_media" id="includeMedia" onchange="toggleMediaUpload()">
                    <label>📎 Attach media/file to message</label>
                </div>
                
                <div id="mediaUploadGroup" style="display: none;">
                    <div class="media-upload-area" onclick="document.getElementById('mediaFile').click()">
                        <i class="bi bi-cloud-upload" style="font-size: 32px; color: #667eea;"></i>
                        <p>Click to upload image, PDF, video, or audio</p>
                        <small>Supported: JPG, PNG, GIF, PDF, MP4, MP3 (Max 10MB)</small>
                    </div>
                    <input type="file" name="media_file" id="mediaFile" style="display: none;" accept="image/*,application/pdf,video/*,audio/*" onchange="previewMedia(this)">
                    <div id="mediaPreview" class="media-preview">
                        <div id="previewContent"></div>
                        <button type="button" class="remove-media" onclick="removeMedia()">Remove File</button>
                    </div>
                </div>
                
                <div class="message-preview">
                    <h4>📄 Message Preview</h4>
                    <div class="preview-content" id="messagePreview">Select a template to preview...</div>
                </div>
                
                <button type="submit" class="btn btn-primary" onclick="return confirm('⚠️ Send messages to ALL selected recipients?')">
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
            venue: '<?php echo addslashes($currentEvent['venue'] ?? 'TBA'); ?>'
        };
        
        const sampleCustomer = {
            name: 'John Doe',
            reservation_id: 'RES0001-15G10A3T2K-A3F4R5',
            amount: '230.00'
        };
        
        function toggleMediaUpload() {
            const includeMedia = document.getElementById('includeMedia');
            const mediaGroup = document.getElementById('mediaUploadGroup');
            mediaGroup.style.display = includeMedia.checked ? 'block' : 'none';
            if (!includeMedia.checked) removeMedia();
        }
        
        function previewMedia(input) {
            const previewDiv = document.getElementById('mediaPreview');
            const previewContent = document.getElementById('previewContent');
            
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const fileType = file.type;
                const fileName = file.name;
                
                if (fileType.startsWith('image/')) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        previewContent.innerHTML = `<img src="${e.target.result}" alt="Preview"><div class="file-name">${fileName}</div>`;
                        previewDiv.classList.add('active');
                    };
                    reader.readAsDataURL(file);
                } else if (fileType === 'application/pdf') {
                    previewContent.innerHTML = `<i class="bi bi-file-pdf" style="font-size: 48px; color: #ef4444;"></i><div class="file-name">${fileName}</div>`;
                    previewDiv.classList.add('active');
                } else {
                    previewContent.innerHTML = `<i class="bi bi-file-earmark" style="font-size: 48px; color: #667eea;"></i><div class="file-name">${fileName}</div>`;
                    previewDiv.classList.add('active');
                }
            }
        }
        
        function removeMedia() {
            document.getElementById('mediaFile').value = '';
            document.getElementById('mediaPreview').classList.remove('active');
            document.getElementById('previewContent').innerHTML = '';
        }
        
        function updatePreview() {
            const template = document.getElementById('messageTemplate').value;
            const includeTicketLink = document.getElementById('includeTicketLink').checked;
            const customMessage = document.getElementById('customMessage').value;
            
            let preview = '';
            
            if (template === 'custom') {
                preview = customMessage || 'Enter your custom message above...';
            } else {
                preview = getTemplatePreview(template);
            }
            
            preview = preview.replace(/{name}/g, sampleCustomer.name);
            preview = preview.replace(/{reservation_id}/g, sampleCustomer.reservation_id);
            preview = preview.replace(/{amount}/g, sampleCustomer.amount);
            preview = preview.replace(/{event_name}/g, eventData.name);
            preview = preview.replace(/{event_date}/g, eventData.date);
            preview = preview.replace(/{event_time}/g, eventData.time);
            preview = preview.replace(/{venue}/g, eventData.venue);
            preview = preview.replace(/{ticket_link}/g, includeTicketLink ? 'https://yourdomain.com/public/reservation_tickets.php?id=' + sampleCustomer.reservation_id : '[Not included]');
            
            document.getElementById('messagePreview').innerHTML = preview.replace(/\n/g, '<br>');
        }
        
        function getTemplatePreview(template) {
            const previews = {
                event_reminder: `🎪 *EVENT REMINDER*\n\nDear {name},\n\nReminder about {event_name} on {event_date} at {event_time}.\n\nVenue: {venue}\nReservation: {reservation_id}`,
                payment_reminder: `💰 *PAYMENT REMINDER*\n\nDear {name},\n\nPayment of {amount} JOD is pending for reservation {reservation_id}.`,
                thank_you: `🙏 *THANK YOU*\n\nDear {name},\n\nThank you for choosing {event_name}!`,
                ticket_reminder: `🎫 *YOUR TICKETS*\n\nDear {name},\n\nYour tickets for {event_name} are ready!\nReservation: {reservation_id}`,
                special_offer: `🎁 *SPECIAL OFFER*\n\nDear {name},\n\nGet 15% off on your next booking! Use code: WELCOME15`,
                event_update: `📢 *EVENT UPDATE*\n\nDear {name},\n\nImportant update regarding {event_name}.`
            };
            return previews[template] || previews.event_reminder;
        }
        
        function toggleFields() {
            const template = document.getElementById('messageTemplate').value;
            const customMessageGroup = document.getElementById('customMessageGroup');
            const includeTicketLink = document.getElementById('includeTicketLink');
            
            customMessageGroup.style.display = template === 'custom' ? 'block' : 'none';
            includeTicketLink.disabled = template !== 'ticket_reminder';
            if (template !== 'ticket_reminder') includeTicketLink.checked = false;
        }
        
        document.getElementById('messageTemplate').addEventListener('change', function() { toggleFields(); updatePreview(); });
        document.getElementById('includeTicketLink').addEventListener('change', updatePreview);
        document.getElementById('customMessage').addEventListener('input', updatePreview);
        
        // Dark mode
        const darkModeToggle = document.createElement('button');
        darkModeToggle.innerHTML = '🌙';
        darkModeToggle.style.cssText = 'position:fixed; bottom:20px; right:20px; background:#667eea; color:white; border:none; border-radius:50%; width:50px; height:50px; cursor:pointer; z-index:1000;';
        darkModeToggle.onclick = () => document.body.classList.toggle('dark-mode');
        if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark-mode');
        document.body.appendChild(darkModeToggle);
        
        toggleFields();
        updatePreview();
    </script>
</body>
</html>