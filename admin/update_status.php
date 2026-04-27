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
$new_status = isset($_GET['status']) ? sanitizeInput($_GET['status']) : '';
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'dashboard';

if (empty($reservation_id) || empty($new_status)) {
    header('Location: dashboard.php?error=Invalid request');
    exit();
}

// Get current reservation
$stmt = $conn->prepare("SELECT * FROM reservations WHERE reservation_id = ?");
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reservation) {
    $_SESSION['error'] = "Reservation not found";
    header('Location: dashboard.php');
    exit();
}

$old_status = $reservation['status'];

// Update status
$stmt = $conn->prepare("UPDATE reservations SET status = ? WHERE reservation_id = ?");
$stmt->bind_param("ss", $new_status, $reservation_id);

if ($stmt->execute()) {
    // Update table availability when cancelling or completing
    if ($new_status == 'cancelled' || $new_status == 'paid') {
        updateTableAvailability();
    }
    
    // If marking as paid, generate ticket codes and send WhatsApp
    if ($new_status == 'paid' && $old_status != 'paid') {
        // Generate ticket codes if not exist
        $checkTickets = $conn->query("SELECT COUNT(*) as count FROM ticket_codes WHERE reservation_id = '$reservation_id'");
        $ticketCount = $checkTickets->fetch_assoc()['count'];
        
        if ($ticketCount == 0) {
            // Generate ticket codes
            $counter = 1;
            for ($i = 1; $i <= $reservation['adults']; $i++) {
                $code = generateTicketId($reservation_id, 'adult', $i);
                $conn->query("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES ('$reservation_id', '$code', 'adult', $counter++)");
            }
            for ($i = 1; $i <= $reservation['teens']; $i++) {
                $code = generateTicketId($reservation_id, 'teen', $i);
                $conn->query("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES ('$reservation_id', '$code', 'teen', $counter++)");
            }
            for ($i = 1; $i <= $reservation['kids']; $i++) {
                $code = generateTicketId($reservation_id, 'kid', $i);
                $conn->query("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES ('$reservation_id', '$code', 'kid', $counter++)");
            }
        }
        
        // Send WhatsApp message with ticket link
        sendTicketLinkViaWhatsApp($reservation);
        
        $_SESSION['success'] = "Reservation marked as paid! Ticket link sent via WhatsApp.";
    } else {
        $_SESSION['success'] = "Status updated to " . ucfirst($new_status) . " successfully!";
    }
} else {
    $_SESSION['error'] = "Error updating status: " . $conn->error;
}

$stmt->close();
$conn->close();

if ($redirect == 'edit') {
    header('Location: edit_reservation.php?id=' . urlencode($reservation_id));
} else {
    header('Location: dashboard.php');
}
exit();

// Helper function to generate ticket ID
function generateTicketId($reservationId, $type, $number) {
    $typeCode = '';
    switch($type) {
        case 'adult': $typeCode = 'A'; break;
        case 'teen': $typeCode = 'T'; break;
        case 'kid': $typeCode = 'K'; break;
    }
    return $reservationId . '-' . $typeCode . str_pad($number, 4, '0', STR_PAD_LEFT);
}

// Function to send ticket link via WhatsApp
function sendTicketLinkViaWhatsApp($reservation) {
    $conn = getConnection();
    
    // Get ticket codes
    $tickets = $conn->query("SELECT * FROM ticket_codes WHERE reservation_id = '{$reservation['reservation_id']}' ORDER BY guest_type, guest_number")->fetch_all(MYSQLI_ASSOC);
    $conn->close();
    
    $baseUrl = getBaseUrl();
    $ticketLink = $baseUrl . "admin/print_ticket.php?reservation_id=" . urlencode($reservation['reservation_id']);
    $totalGuests = $reservation['adults'] + $reservation['teens'] + $reservation['kids'];
    $currency = getSetting('currency', 'JOD');
    
    $message = "✅ *TICKET READY!* ✅\n\n";
    $message .= "Dear {$reservation['name']},\n\n";
    $message .= "Your payment has been confirmed and your tickets are now ready!\n\n";
    
    $message .= "📋 *Reservation Details:*\n";
    $message .= "• Reservation ID: {$reservation['reservation_id']}\n";
    $message .= "• Table: {$reservation['table_id']}\n";
    $message .= "• Guests: {$totalGuests} ({$reservation['adults']} Adults, {$reservation['teens']} Teens, {$reservation['kids']} Kids)\n\n";
    
    $message .= "🎫 *Your Ticket Codes:*\n";
    foreach ($tickets as $ticket) {
        $typeIcon = $ticket['guest_type'] == 'adult' ? '👤' : ($ticket['guest_type'] == 'teen' ? '🧑' : '👶');
        $message .= "{$typeIcon} {$ticket['ticket_code']}\n";
    }
    
    $message .= "\n📎 *Download Your E-Ticket:*\n";
    $message .= $ticketLink . "\n\n";
    
    $message .= "📱 *Instructions:*\n";
    $message .= "• Click the link above to view your ticket\n";
    $message .= "• You can print or save as PDF\n";
    $message .= "• Present the ticket (digital or printed) at the entrance\n";
    $message .= "• Each ticket has a unique QR code for scanning\n\n";
    
    $message .= "🎉 Thank you for choosing our event! We look forward to welcoming you! 🎉";
    
    // Format phone number
    $phone = $reservation['phone'];
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 1) == '0') $phone = substr($phone, 1);
    if (substr($phone, 0, 3) != '962') $phone = '962' . $phone;
    
    // Send WhatsApp message
    sendWhatsAppMessage($phone, $message);
}
?>