<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 300);
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/language.php';
require_once '../includes/early_bird.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();
$message = '';
$messageType = '';

// Get selected event info
$selected_event_id = $_SESSION['selected_event_id'] ?? 0;
$selected_event_name = $_SESSION['selected_event_name'] ?? 'No Event Selected';
$selected_event_date = $_SESSION['selected_event_date'] ?? '';

// Get current event details
$adultPrice = getSetting('ticket_price_adult', 10);
$teenPrice = getSetting('ticket_price_teen', 10);
$kidPrice = getSetting('ticket_price_kid', 0);
$loyaltyAdultPrice = getSetting('loyalty_price_adult', 8);
$loyaltyTeenPrice = getSetting('loyalty_price_teen', 8);
$loyaltyKidPrice = getSetting('loyalty_price_kid', 0);
$earlyBirdActive = false;
$earlyBirdDeadline = null;

if ($selected_event_id > 0) {
    $stmt = $conn->prepare("SELECT 
        ticket_price_adult, 
        ticket_price_teen, 
        ticket_price_kid, 
        event_name, 
        event_date,
        early_bird_enabled,
        early_bird_deadline,
        early_bird_price_adult,
        early_bird_price_teen,
        early_bird_price_kid,
        loyalty_price_adult,
        loyalty_price_teen,
        loyalty_price_kid
        FROM event_settings WHERE id = ?");
    $stmt->bind_param("i", $selected_event_id);
    $stmt->execute();
    $event_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($event_data) {
        $adultPrice = $event_data['ticket_price_adult'];
        $teenPrice = $event_data['ticket_price_teen'];
        $kidPrice = $event_data['ticket_price_kid'];
        $loyaltyAdultPrice = $event_data['loyalty_price_adult'] ?? $adultPrice * 0.8;
        $loyaltyTeenPrice = $event_data['loyalty_price_teen'] ?? $teenPrice * 0.8;
        $loyaltyKidPrice = $event_data['loyalty_price_kid'] ?? $kidPrice;
        
        $_SESSION['selected_event_name'] = $event_data['event_name'];
        $_SESSION['selected_event_date'] = $event_data['event_date'];
        
        if ($event_data['early_bird_enabled'] && !empty($event_data['early_bird_deadline'])) {
            $today = date('Y-m-d');
            if ($today <= $event_data['early_bird_deadline']) {
                $earlyBirdActive = true;
                $earlyBirdDeadline = $event_data['early_bird_deadline'];
                if (!empty($event_data['early_bird_price_adult']) && $event_data['early_bird_price_adult'] > 0) {
                    $adultPrice = $event_data['early_bird_price_adult'];
                }
                if (!empty($event_data['early_bird_price_teen']) && $event_data['early_bird_price_teen'] > 0) {
                    $teenPrice = $event_data['early_bird_price_teen'];
                }
                if (!empty($event_data['early_bird_price_kid']) && $event_data['early_bird_price_kid'] > 0) {
                    $kidPrice = $event_data['early_bird_price_kid'];
                }
            }
        }
    }
}

$currencySymbol = getCurrencySymbol();

// Function to save or update customer
function saveCustomer($conn, $name, $phone, $total_amount) {
    // Check if customer exists
    $checkStmt = $conn->prepare("SELECT id, total_visits, total_spent FROM customers WHERE phone = ?");
    $checkStmt->bind_param("s", $phone);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $existing = $result->fetch_assoc();
    $checkStmt->close();
    
    if ($existing) {
        // Update existing customer
        $new_visits = $existing['total_visits'] + 1;
        $new_spent = $existing['total_spent'] + $total_amount;
        $updateStmt = $conn->prepare("
            UPDATE customers 
            SET name = ?, 
                total_visits = ?, 
                total_spent = ?, 
                last_visit_date = NOW() 
            WHERE phone = ?
        ");
        $updateStmt->bind_param("sids", $name, $new_visits, $new_spent, $phone);
        $updateStmt->execute();
        $updateStmt->close();
        return $existing['id'];
    } else {
        // Insert new customer
        $insertStmt = $conn->prepare("
            INSERT INTO customers (name, phone, total_visits, total_spent, first_visit_date, last_visit_date) 
            VALUES (?, ?, 1, ?, NOW(), NOW())
        ");
        $insertStmt->bind_param("ssd", $name, $phone, $total_amount);
        $insertStmt->execute();
        $new_id = $insertStmt->insert_id;
        $insertStmt->close();
        
        // Send welcome message for new customers
        $welcomeMsg = "⭐ *WELCOME TO OUR CUSTOMER PROGRAM!* ⭐\n\n";
        $welcomeMsg .= "Dear {$name},\n\n";
        $welcomeMsg .= "Thank you for choosing us! You've been automatically enrolled in our customer program.\n\n";
        $welcomeMsg .= "🎁 Benefits:\n";
        $welcomeMsg .= "• Track your visits and spending\n";
        $welcomeMsg .= "• Special offers on future events\n";
        $welcomeMsg .= "• Priority support\n\n";
        $welcomeMsg .= "We look forward to serving you! 🎉";
        
        sendWhatsAppMessage($phone, $welcomeMsg);
        
        return $new_id;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitizeInput($_POST['name']);
    $phone = sanitizeInput($_POST['phone']);
    $adults = intval($_POST['adults']);
    $teens = intval($_POST['teens']);
    $kids = intval($_POST['kids']);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $price_tier = sanitizeInput($_POST['price_tier'] ?? 'regular');
    
    // Use appropriate prices based on tier
    if ($price_tier == 'loyalty') {
        $usedAdultPrice = $loyaltyAdultPrice;
        $usedTeenPrice = $loyaltyTeenPrice;
        $usedKidPrice = $loyaltyKidPrice;
    } else {
        $usedAdultPrice = $adultPrice;
        $usedTeenPrice = $teenPrice;
        $usedKidPrice = $kidPrice;
    }
    
    $total_amount = ($adults * $usedAdultPrice) + ($teens * $usedTeenPrice) + ($kids * $usedKidPrice);
    
    // Check for existing reservation with same phone number for this event
    $doubleBookingError = '';
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count, reservation_id, status 
        FROM reservations 
        WHERE phone = ?
        AND status NOT IN ('cancelled', 'expired', 'completed')
        AND event_id = ?
    ");
    $stmt->bind_param("si", $phone, $selected_event_id);
    $stmt->execute();
    $phoneCheck = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($phoneCheck['count'] > 0) {
        $doubleBookingError = "❌ This phone number already has an active reservation for this event (ID: {$phoneCheck['reservation_id']}, Status: {$phoneCheck['status']}).";
    }

    if (!empty($doubleBookingError)) {
        $message = $doubleBookingError;
        $messageType = "error";
    } else {
        $seq_result = $conn->query("SELECT MAX(sequential_number) as max_seq FROM reservations WHERE event_id = $selected_event_id");
        $max_seq_row = $seq_result->fetch_assoc();
        $current_max = intval($max_seq_row['max_seq']);
        $next_seq = $current_max + 1;
        if ($next_seq <= 0) $next_seq = 1;
        
        $reservation_id = generateReservationIdWithSeq($adults, $teens, $kids, $next_seq);
        
        $conn->begin_transaction();
        
        try {
            $status = 'pending';
            $additional_amount_due = $total_amount;
            
            $sql = "INSERT INTO reservations (
                reservation_id, 
                sequential_number, 
                name, 
                phone, 
                adults, 
                teens, 
                kids, 
                total_amount, 
                additional_amount_due, 
                notes, 
                status, 
                event_id, 
                price_tier,
                created_at
            ) VALUES (
                '" . $conn->real_escape_string($reservation_id) . "',
                " . intval($next_seq) . ",
                '" . $conn->real_escape_string($name) . "',
                '" . $conn->real_escape_string($phone) . "',
                " . intval($adults) . ",
                " . intval($teens) . ",
                " . intval($kids) . ",
                " . floatval($total_amount) . ",
                " . floatval($additional_amount_due) . ",
                '" . $conn->real_escape_string($notes) . "',
                '$status',
                " . intval($selected_event_id) . ",
                '" . $conn->real_escape_string($price_tier) . "',
                NOW()
            )";
            
            if (!$conn->query($sql)) {
                throw new Exception("Insert failed: " . $conn->error);
            }
            
            // Generate ticket codes
            $adultCount = 0;
            $teenCount = 0;
            $kidCount = 0;
            
            $table_check = $conn->query("SHOW TABLES LIKE 'ticket_codes'");
            if ($table_check && $table_check->num_rows > 0) {
                for ($i = 1; $i <= $adults; $i++) {
                    $ticketCode = generateTicketId($reservation_id, 'adult', $i);
                    $stmt_ticket = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'adult', ?)");
                    $stmt_ticket->bind_param("ssi", $reservation_id, $ticketCode, $i);
                    $stmt_ticket->execute();
                    $stmt_ticket->close();
                    $adultCount++;
                }
                for ($i = 1; $i <= $teens; $i++) {
                    $ticketCode = generateTicketId($reservation_id, 'teen', $i);
                    $stmt_ticket = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'teen', ?)");
                    $stmt_ticket->bind_param("ssi", $reservation_id, $ticketCode, $i);
                    $stmt_ticket->execute();
                    $stmt_ticket->close();
                    $teenCount++;
                }
                for ($i = 1; $i <= $kids; $i++) {
                    $ticketCode = generateTicketId($reservation_id, 'kid', $i);
                    $stmt_ticket = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'kid', ?)");
                    $stmt_ticket->bind_param("ssi", $reservation_id, $ticketCode, $i);
                    $stmt_ticket->execute();
                    $stmt_ticket->close();
                    $kidCount++;
                }
            }
            
            $conn->commit();
            
            // Save customer to customers table
            saveCustomer($conn, $name, $phone, $total_amount);
            
            $message = "✅ Reservation created successfully!<br>";
            $message .= "📋 Reservation ID: <strong>" . $reservation_id . "</strong><br>";
            $message .= "🎫 Tickets generated: " . $adultCount . " Adult, " . $teenCount . " Teen, " . $kidCount . " Kid<br>";
            $message .= "🏷️ Price Tier: " . ucfirst($price_tier);
            
            // Bilingual WhatsApp message
            $whatsappMessage = "🎫 *RESERVATION CONFIRMED | تم تأكيد الحجز* 🎫\n\n";
            $whatsappMessage .= "Dear {$name} | عزيزنا {$name},\n\n";
            $whatsappMessage .= "Your reservation has been confirmed successfully.\n";
            $whatsappMessage .= "تم تأكيد حجزك بنجاح.\n\n";
            $whatsappMessage .= "📋 *Reservation ID | رقم الحجز:* {$reservation_id}\n";
            $whatsappMessage .= "👥 *Guests | عدد الضيوف:* " . ($adults + $teens + $kids) . "\n";
            $whatsappMessage .= "💰 *Total Amount | المبلغ الإجمالي:* {$currencySymbol} " . number_format($total_amount, 2) . "\n";
            
            $whatsappMessage .= "❗ *Table number will be assigned later. We will notify you.*\n";
            $whatsappMessage .= "❗ *سيتم تخصيص رقم الطاولة لاحقًا. سنقوم بإعلامك.*\n\n";
            
            $whatsappMessage .= "To pay for your reservation, please follow the steps below:\n";
            $whatsappMessage .= "لدفع قيمة الحجز، يرجى اتباع الخطوات التالية:\n\n";
            $whatsappMessage .= "Transfer the amount {$currencySymbol}" . number_format($total_amount, 2) . "\n";
            $whatsappMessage .= "قم بتحويل المبلغ {$currencySymbol}" . number_format($total_amount, 2) . "\n\n";
            $whatsappMessage .= "Via CliQ transfer to:\n";
            $whatsappMessage .= "عبر خدمة CliQ إلى:\n";
            $whatsappMessage .= "*Number | الرقم: 00962795402462*\n";
            $whatsappMessage .= "*Bank | البنك: Arab Bank | البنك العربي*\n\n";
            $whatsappMessage .= "Then send a screenshot of the transfer to the number 0795410115.\n";
            $whatsappMessage .= "ثم قم بأرسال صورة إثبات التحويل إلى الرقم 0795410115.\n\n";
            $whatsappMessage .= "We look forward to serving you! 🎉\n";
            $whatsappMessage .= "نتطلع لخدمتك! 🎉\n\n";
            $whatsappMessage .= "_Thank you for choosing us | شكرًا لاختياركم لنا_";
            
            $whatsappSent = sendWhatsAppMessage($phone, $whatsappMessage);
            
            if ($whatsappSent) {
                $message .= "<br>📱 WhatsApp confirmation sent to customer!";
            } else {
                $message .= "<br>⚠️ WhatsApp could not be sent. Please check WhatsApp settings.";
            }
            
            $messageType = "success";
            $_POST = array();
            
        } catch (Exception $e) {
            $conn->rollback();
            $message = "❌ Error creating reservation: " . $e->getMessage();
            $messageType = "error";
        }
    }
}

$conn->close();

function generateReservationIdWithSeq($adults, $teens, $kids, $sequential)
{
    $prefix = 'RES';
    $sequentialFormatted = str_pad($sequential, 4, '0', STR_PAD_LEFT);
    $totalGuests = $adults + $teens + $kids;
    $breakdown = $totalGuests . 'G' . $adults . 'A' . $teens . 'T' . $kids . 'K';
    $randomSuffix = generateRandomString(5);
    return $prefix . $sequentialFormatted . '-' . $breakdown . '-' . $randomSuffix;
}
?>
<!DOCTYPE html>
<html lang="<?php echo getCurrentLanguage(); ?>" dir="<?php echo getDirection(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('new_reservation'); ?> - <?php echo t('ticketing_system'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        body.dark-mode { background: #0f172a; color: #e2e8f0; }
        .container { max-width: 800px; margin: 0 auto; }
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
        body.dark-mode .navbar { background: #1e293b; }
        .card {
            background: white;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        body.dark-mode .card { background: #1e293b; }
        .card-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        body.dark-mode .card-header { border-bottom-color: #334155; }
        .event-badge {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: white;
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
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
            transition: all 0.2s;
        }
        body.dark-mode .form-group input, body.dark-mode .form-group select, body.dark-mode .form-group textarea {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        .price-display {
            background: #f1f5f9;
            padding: 15px;
            border-radius: 12px;
            margin-top: 10px;
        }
        body.dark-mode .price-display { background: #0f172a; }
        .total-amount { font-size: 24px; font-weight: bold; color: #4f46e5; }
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
        .btn-primary:hover { background: #4338ca; transform: translateY(-1px); }
        .btn-secondary { background: #64748b; color: white; }
        .btn-secondary:hover { background: #475569; }
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        body.dark-mode .form-actions { border-top-color: #334155; }
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .alert-warning { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }
        .alert i { font-size: 20px; }
        .alert .close-btn {
            margin-left: auto;
            cursor: pointer;
            background: none;
            border: none;
            font-size: 18px;
            opacity: 0.7;
        }
        .price-tier-group {
            background: #e0e7ff;
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .price-tier-option {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 10px;
        }
        .price-tier-option input { width: auto; margin-right: 8px; }
        .price-tier-option label { margin: 0; cursor: pointer; }
        .price-details {
            font-size: 12px;
            color: #4f46e5;
            margin-left: 24px;
        }
        @media (max-width: 768px) {
            .form-row { grid-template-columns: 1fr; }
            .navbar { flex-direction: column; text-align: center; }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="navbar">
        <h1><i class="bi bi-plus-circle"></i> <?php echo t('new_reservation'); ?></h1>
        <div>
            <div class="event-badge">
                <i class="bi bi-calendar-event"></i>
                <?php echo htmlspecialchars($selected_event_name); ?>
            </div>
            <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> <?php echo t('back_to_dashboard'); ?></a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $messageType; ?>">
            <i class="bi bi-<?php echo $messageType == 'success' ? 'check-circle-fill' : ($messageType == 'error' ? 'exclamation-triangle-fill' : 'info-circle-fill'); ?>"></i>
            <?php echo $message; ?>
            <button class="close-btn" onclick="this.parentElement.style.display='none'">×</button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h2><i class="bi bi-person-plus"></i> Customer Information</h2>
        </div>

        <form method="POST" onsubmit="return validateForm()">
            <div class="form-group">
                <label><i class="bi bi-person"></i> Full Name *</label>
                <input type="text" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" placeholder="Enter customer name">
            </div>

            <div class="form-group">
                <label><i class="bi bi-telephone"></i> Phone Number *</label>
                <input type="tel" name="phone" required value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" placeholder="+962XXXXXXXXX">
            </div>

            <!-- Price Tier Selection -->
            <div class="price-tier-group">
                <label style="margin-bottom: 10px;"><i class="bi bi-tags"></i> <strong>Price Tier | فئة السعر</strong></label>
                <div class="price-tier-option">
                    <input type="radio" name="price_tier" id="price_regular" value="regular" checked onchange="updatePrices()">
                    <label for="price_regular">🏷️ Regular Price | السعر العادي</label>
                </div>
                <div class="price-details" id="regularPriceDetails">
                    Adult: <?php echo $currencySymbol; ?> <?php echo number_format($adultPrice, 2); ?> | 
                    Teen: <?php echo $currencySymbol; ?> <?php echo number_format($teenPrice, 2); ?> | 
                    Kid: <?php echo $currencySymbol; ?> <?php echo number_format($kidPrice, 2); ?>
                </div>
                <div class="price-tier-option" style="margin-top: 10px;">
                    <input type="radio" name="price_tier" id="price_loyalty" value="loyalty" onchange="updatePrices()">
                    <label for="price_loyalty">⭐ Loyalty Price (For returning customers) | سعر الولاء (للعملاء الدائمين)</label>
                </div>
                <div class="price-details" id="loyaltyPriceDetails" style="display: none;">
                    Adult: <?php echo $currencySymbol; ?> <?php echo number_format($loyaltyAdultPrice, 2); ?> | 
                    Teen: <?php echo $currencySymbol; ?> <?php echo number_format($loyaltyTeenPrice, 2); ?> | 
                    Kid: <?php echo $currencySymbol; ?> <?php echo number_format($loyaltyKidPrice, 2); ?>
                </div>
            </div>

            <?php if ($earlyBirdActive && $earlyBirdDeadline): ?>
            <div class="alert alert-success" style="background: #d1fae5; color: #065f46; margin-bottom: 15px;">
                <i class="bi bi-gift-fill"></i> 
                <strong>Early Bird Discount Active!</strong> 
                You're getting discounted prices for this event.
            </div>
            <?php endif; ?>

            <div class="card-header" style="margin-top: 20px;">
                <h2><i class="bi bi-people"></i> Guest Information</h2>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label><i class="bi bi-gender-male"></i> Adults (<span id="adultPriceDisplay"><?php echo $currencySymbol; ?> <?php echo number_format($adultPrice, 2); ?></span> each)</label>
                    <input type="number" name="adults" id="adults" min="0" value="<?php echo $_POST['adults'] ?? 0; ?>" onchange="calculateTotal()">
                </div>
                <div class="form-group">
                    <label><i class="bi bi-gender-female"></i> Teens (<span id="teenPriceDisplay"><?php echo $currencySymbol; ?> <?php echo number_format($teenPrice, 2); ?></span> each)</label>
                    <input type="number" name="teens" id="teens" min="0" value="<?php echo $_POST['teens'] ?? 0; ?>" onchange="calculateTotal()">
                </div>
                <div class="form-group">
                    <label><i class="bi bi-egg-fried"></i> Kids (<span id="kidPriceDisplay"><?php echo $currencySymbol; ?> <?php echo number_format($kidPrice, 2); ?></span> each)</label>
                    <input type="number" name="kids" id="kids" min="0" value="<?php echo $_POST['kids'] ?? 0; ?>" onchange="calculateTotal()">
                </div>
            </div>

            <div class="price-display">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span><strong>Total Amount:</strong></span>
                    <span class="total-amount" id="totalAmount">0.00 <?php echo $currencySymbol; ?></span>
                </div>
                <div style="font-size: 12px; color: #64748b; margin-top: 5px;">
                    * Table number will be assigned later
                </div>
            </div>

            <div class="form-group">
                <label><i class="bi bi-chat"></i> Special Notes</label>
                <textarea name="notes" rows="3" placeholder="Any special requests or notes..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
            </div>

            <div class="form-actions">
                <button type="button" onclick="window.location.href='dashboard.php'" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle"></i> Create Reservation
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const regularPrices = {
        adult: <?php echo $adultPrice; ?>,
        teen: <?php echo $teenPrice; ?>,
        kid: <?php echo $kidPrice; ?>
    };
    const loyaltyPrices = {
        adult: <?php echo $loyaltyAdultPrice; ?>,
        teen: <?php echo $loyaltyTeenPrice; ?>,
        kid: <?php echo $loyaltyKidPrice; ?>
    };
    let currentAdultPrice = regularPrices.adult;
    let currentTeenPrice = regularPrices.teen;
    let currentKidPrice = regularPrices.kid;
    const currencySymbol = '<?php echo $currencySymbol; ?>';

    function updatePrices() {
        const isLoyalty = document.getElementById('price_loyalty').checked;
        
        if (isLoyalty) {
            currentAdultPrice = loyaltyPrices.adult;
            currentTeenPrice = loyaltyPrices.teen;
            currentKidPrice = loyaltyPrices.kid;
            document.getElementById('adultPriceDisplay').innerHTML = currencySymbol + ' ' + loyaltyPrices.adult.toFixed(2);
            document.getElementById('teenPriceDisplay').innerHTML = currencySymbol + ' ' + loyaltyPrices.teen.toFixed(2);
            document.getElementById('kidPriceDisplay').innerHTML = currencySymbol + ' ' + loyaltyPrices.kid.toFixed(2);
            document.getElementById('loyaltyPriceDetails').style.display = 'block';
            document.getElementById('regularPriceDetails').style.display = 'none';
        } else {
            currentAdultPrice = regularPrices.adult;
            currentTeenPrice = regularPrices.teen;
            currentKidPrice = regularPrices.kid;
            document.getElementById('adultPriceDisplay').innerHTML = currencySymbol + ' ' + regularPrices.adult.toFixed(2);
            document.getElementById('teenPriceDisplay').innerHTML = currencySymbol + ' ' + regularPrices.teen.toFixed(2);
            document.getElementById('kidPriceDisplay').innerHTML = currencySymbol + ' ' + regularPrices.kid.toFixed(2);
            document.getElementById('loyaltyPriceDetails').style.display = 'none';
            document.getElementById('regularPriceDetails').style.display = 'block';
        }
        calculateTotal();
    }

    function calculateTotal() {
        const adults = parseInt(document.getElementById('adults').value) || 0;
        const teens = parseInt(document.getElementById('teens').value) || 0;
        const kids = parseInt(document.getElementById('kids').value) || 0;

        const total = (adults * currentAdultPrice) + (teens * currentTeenPrice) + (kids * currentKidPrice);
        document.getElementById('totalAmount').innerHTML = total.toFixed(2) + ' ' + currencySymbol;
    }

    function validateForm() {
        const name = document.querySelector('input[name="name"]').value.trim();
        const phone = document.querySelector('input[name="phone"]').value.trim();
        const adults = parseInt(document.getElementById('adults').value) || 0;
        const teens = parseInt(document.getElementById('teens').value) || 0;
        const kids = parseInt(document.getElementById('kids').value) || 0;

        if (!name) {
            alert('Please enter customer name');
            return false;
        }
        if (!phone) {
            alert('Please enter phone number');
            return false;
        }
        if (adults === 0 && teens === 0 && kids === 0) {
            alert('Please add at least one guest');
            return false;
        }
        return true;
    }

    document.addEventListener('DOMContentLoaded', function() {
        calculateTotal();
    });
</script>
</body>
</html>