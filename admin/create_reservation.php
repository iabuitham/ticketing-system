<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();

// Get prices
$adultPrice = getSetting('ticket_price_adult', 10);
$teenPrice = getSetting('ticket_price_teen', 10);
$kidPrice = getSetting('ticket_price_kid', 0);
$currency = getSetting('currency', 'JOD');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitizeInput($_POST['name']);
    $phone = sanitizeInput($_POST['phone']);
    $adults = intval($_POST['adults']);
    $teens = intval($_POST['teens']);
    $kids = intval($_POST['kids']);
    $table_id = sanitizeInput($_POST['table_id']);
    $notes = sanitizeInput($_POST['notes']);
    $status = sanitizeInput($_POST['status']);
    
    // Validate unique name
    $check = $conn->prepare("SELECT COUNT(*) as count FROM reservations WHERE name = ?");
    $check->bind_param("s", $name);
    $check->execute();
    if ($check->get_result()->fetch_assoc()['count'] > 0) {
        $_SESSION['error'] = "Name '$name' already exists.";
        header('Location: create_reservation.php');
        exit();
    }
    $check->close();
    
    // Validate unique table
    $check = $conn->prepare("SELECT COUNT(*) as count FROM reservations WHERE table_id = ? AND status != 'cancelled'");
    $check->bind_param("s", $table_id);
    $check->execute();
    if ($check->get_result()->fetch_assoc()['count'] > 0) {
        $_SESSION['error'] = "Table '$table_id' is already assigned.";
        header('Location: create_reservation.php');
        exit();
    }
    $check->close();
    
    // Calculate total
    $total = ($adults * $adultPrice) + ($teens * $teenPrice) + ($kids * $kidPrice);
    
    // Generate new reservation ID
    $reservation_id = generateReservationId($adults, $teens, $kids);
    $phone = formatPhoneNumber($phone);
    
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("INSERT INTO reservations (reservation_id, name, phone, adults, teens, kids, table_id, notes, status, total_amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssiissssd", $reservation_id, $name, $phone, $adults, $teens, $kids, $table_id, $notes, $status, $total);
        $stmt->execute();
        $stmt->close();
        
        // Generate and insert ticket codes
        $tickets = generateTicketCodes($reservation_id, $adults, $teens, $kids);
        $ticketStmt = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, ?, ?)");
        foreach ($tickets as $t) {
            $ticketStmt->bind_param("sssi", $reservation_id, $t['code'], $t['type'], $t['num']);
            $ticketStmt->execute();
        }
        $ticketStmt->close();
        
        $conn->commit();
        
        // ========== SEND WHATSAPP PAYMENT INSTRUCTIONS ==========
        sendPaymentInstructions($phone, $name, $reservation_id, $total, $adults, $teens, $kids, $currency);
        
        $_SESSION['success'] = "Reservation created! ID: $reservation_id | Total: " . number_format($total, 2) . " $currency<br>Payment instructions sent via WhatsApp.";
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    $conn->close();
    header('Location: dashboard.php');
    exit();
}

// Function to send WhatsApp payment instructions
function sendPaymentInstructions($phone, $name, $reservationId, $total, $adults, $teens, $kids, $currency) {
    $baseUrl = getBaseUrl();
    $ticketLink = $baseUrl . "admin/print_ticket.php?reservation_id=" . urlencode($reservationId);
    
    $message = "🎟️ *RESERVATION CONFIRMATION* 🎟️\n\n";
    $message .= "Dear $name,\n\n";
    $message .= "Thank you for choosing our event! Your reservation has been created successfully.\n\n";
    $message .= "📋 *Reservation Details:*\n";
    $message .= "• Reservation ID: $reservationId\n";
    $message .= "• Guests: " . ($adults + $teens + $kids) . " ($adults Adults, $teens Teens, $kids Kids)\n";
    $message .= "• Total Amount: " . number_format($total, 2) . " $currency\n\n";
    
    $message .= "💰 *Payment Instructions:*\n";
    $message .= "To complete your booking, please send the payment to:\n";
    $message .= "🏦 Bank: XYZ Bank\n";
    $message .= "📊 Account Number: 1234-5678-9012\n";
    $message .= "👤 Account Name: Event Company\n";
    $message .= "🔢 Reference: $reservationId\n\n";
    
    $message .= "📱 *Payment Methods:*\n";
    $message .= "• Cash: Pay at our office\n";
    $message .= "• CliQ: Screenshot required\n";
    $message .= "• Visa: Receipt ID required\n\n";
    
    $message .= "✅ *After Payment:*\n";
    $message .= "1. Send payment proof via WhatsApp\n";
    $message .= "2. We will verify and send your e-ticket\n";
    $message .= "3. Your tickets will be available at the link below:\n";
    $message .= "$ticketLink\n\n";
    
    $message .= "⚠️ *Important:*\n";
    $message .= "• Tickets are only valid after payment confirmation\n";
    $message .= "• Please keep this message for reference\n";
    $message .= "• Contact us for any questions\n\n";
    
    $message .= "🎉 Thank you for choosing us! We look forward to seeing you at the event! 🎉";
    
    sendWhatsAppMessage($phone, $message);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Reservation</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 600px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        h1 { margin-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; }
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        .price-breakdown { background: #f0f4ff; padding: 15px; border-radius: 8px; margin: 15px 0; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; font-weight: 600; }
        .btn-primary { background: #667eea; color: white; width: 100%; }
        .btn-secondary { background: #6c757d; color: white; text-decoration: none; display: inline-block; }
        .actions { display: flex; gap: 10px; margin-top: 20px; }
        .alert-error { background: #f8d7da; color: #721c24; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; gap: 0; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>➕ Create Reservation</h1>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert-error">⚠️ <?php echo $_SESSION['error']; unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group"><label>Customer Name *</label><input type="text" name="name" required></div>
                <div class="form-group"><label>Phone Number *</label><input type="tel" name="phone" placeholder="0791234567" required></div>
                <div class="form-row">
                    <div class="form-group"><label>Adults (<?php echo $adultPrice; ?> <?php echo $currency; ?>)</label><input type="number" name="adults" id="adults" min="0" value="0" onchange="updatePrice()"></div>
                    <div class="form-group"><label>Teens (<?php echo $teenPrice; ?> <?php echo $currency; ?>)</label><input type="number" name="teens" id="teens" min="0" value="0" onchange="updatePrice()"></div>
                    <div class="form-group"><label>Kids (<?php echo $kidPrice; ?> <?php echo $currency; ?>)</label><input type="number" name="kids" id="kids" min="0" value="0" onchange="updatePrice()"></div>
                </div>
                <div class="price-breakdown" id="priceBreakdown"></div>
                <div class="form-group"><label>Table ID *</label><input type="text" name="table_id" placeholder="A1" required></div>
                <div class="form-group"><label>Notes</label><textarea name="notes" rows="3"></textarea></div>
                <div class="form-group"><label>Status</label><select name="status"><option value="pending">Pending</option><option value="registered">Registered</option></select></div>
                <div class="actions"><a href="dashboard.php" class="btn-secondary">Cancel</a><button type="submit" class="btn-primary">Create</button></div>
            </form>
        </div>
    </div>
    <script>
        const adultPrice = <?php echo $adultPrice; ?>, teenPrice = <?php echo $teenPrice; ?>, kidPrice = <?php echo $kidPrice; ?>, currency = '<?php echo $currency; ?>';
        function updatePrice() {
            let a = parseInt(document.getElementById('adults').value) || 0;
            let t = parseInt(document.getElementById('teens').value) || 0;
            let k = parseInt(document.getElementById('kids').value) || 0;
            let total = (a * adultPrice) + (t * teenPrice) + (k * kidPrice);
            document.getElementById('priceBreakdown').innerHTML = `<strong>💰 Price Breakdown:</strong><br>Adults: ${a} × ${adultPrice} = ${a * adultPrice} ${currency}<br>Teens: ${t} × ${teenPrice} = ${t * teenPrice} ${currency}<br>Kids: ${k} × ${kidPrice} = ${k * kidPrice} ${currency}<br><hr><strong>Total: ${total.toFixed(2)} ${currency}</strong>`;
        }
        updatePrice();
    </script>
</body>
</html>