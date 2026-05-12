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

// Get current reservation with table info
$stmt = $conn->prepare("
    SELECT r.*, t.id as table_db_id 
    FROM reservations r
    LEFT JOIN tables t ON r.table_id = t.table_number
    WHERE r.reservation_id = ?
");
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
$table_db_id = $reservation['table_db_id'];

// Start transaction
$conn->begin_transaction();

try {
    // Update reservation status
    $stmt = $conn->prepare("UPDATE reservations SET status = ? WHERE reservation_id = ?");
    $stmt->bind_param("ss", $new_status, $reservation_id);
    
    if (!$stmt->execute()) {
        throw new Exception("Error updating status: " . $conn->error);
    }
    $stmt->close();
    
    // Handle table availability based on status change
    if ($new_status == 'cancelled' || $new_status == 'expired') {
        // Release the table back to available
        if ($table_db_id) {
            $updateTable = $conn->prepare("
                UPDATE tables 
                SET status = 'available', 
                    current_reservation_id = NULL,
                    reserved_until = NULL
                WHERE id = ?
            ");
            $updateTable->bind_param("i", $table_db_id);
            $updateTable->execute();
            $updateTable->close();
        }
        
        $_SESSION['success'] = "Reservation " . ucfirst($new_status) . " and table has been released.";
        
    } elseif ($new_status == 'checked_in') {
        // Mark table as occupied (customer has arrived)
        if ($table_db_id) {
            $updateTable = $conn->prepare("
                UPDATE tables 
                SET status = 'occupied',
                    current_reservation_id = ?,
                    reserved_until = NULL
                WHERE id = ?
            ");
            $updateTable->bind_param("si", $reservation_id, $table_db_id);
            $updateTable->execute();
            $updateTable->close();
        }
        
        $_SESSION['success'] = "Customer checked in. Table marked as occupied.";
        
    } elseif ($new_status == 'completed') {
        // Event completed, release table
        if ($table_db_id) {
            $updateTable = $conn->prepare("
                UPDATE tables 
                SET status = 'available',
                    current_reservation_id = NULL,
                    reserved_until = NULL
                WHERE id = ? AND current_reservation_id = ?
            ");
            $updateTable->bind_param("is", $table_db_id, $reservation_id);
            $updateTable->execute();
            $updateTable->close();
        }
        
        $_SESSION['success'] = "Event completed. Table released.";
        
    } elseif ($new_status == 'paid') {
        // When paid, keep table as reserved with 2 hour hold
        if ($old_status != 'paid' && $table_db_id) {
            $reserved_until = date('Y-m-d H:i:s', strtotime('+2 hours'));
            $updateTable = $conn->prepare("
                UPDATE tables 
                SET status = 'reserved',
                    current_reservation_id = ?,
                    reserved_until = ?
                WHERE id = ?
            ");
            $updateTable->bind_param("ssi", $reservation_id, $reserved_until, $table_db_id);
            $updateTable->execute();
            $updateTable->close();
        }
        
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
        
        // Send tickets as QR code images
        sendTicketsAsQRCodeImages($reservation_id, $reservation['phone'], $reservation['name']);
        
        $_SESSION['success'] = "Reservation marked as paid! Tickets sent via WhatsApp. Table reserved for 2 hours.";
        
    } else {
        $_SESSION['success'] = "Status updated to " . ucfirst($new_status) . " successfully!";
    }
    
    // Commit transaction
    $conn->commit();
    
} catch (Exception $e) {
    $conn->rollback();
    $_SESSION['error'] = $e->getMessage();
}

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

// Function to send tickets as QR code images
function sendTicketsAsQRCodeImages($reservation_id, $customerPhone, $customerName) {
    $conn = getConnection();
    
    // Get all tickets
    $ticketsStmt = $conn->prepare("SELECT * FROM ticket_codes WHERE reservation_id = ? AND is_active = 1 ORDER BY guest_type, guest_number");
    $ticketsStmt->bind_param("s", $reservation_id);
    $ticketsStmt->execute();
    $tickets = $ticketsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ticketsStmt->close();
    
    $eventName = isset($_SESSION['selected_event_name']) ? $_SESSION['selected_event_name'] : 'Event';
    $conn->close();
    
    if (empty($tickets)) {
        error_log("No tickets found for reservation: $reservation_id");
        return false;
    }
    
    // Clean phone number
    $cleanPhone = preg_replace('/[^0-9]/', '', $customerPhone);
    if (substr($cleanPhone, 0, 1) == '0') $cleanPhone = substr($cleanPhone, 1);
    if (substr($cleanPhone, 0, 3) != '962') $cleanPhone = '962' . $cleanPhone;
    
    // Send header message
    $headerMessage = "🎟️ *YOUR TICKETS ARE READY!* 🎟️\n\n";
    $headerMessage .= "Dear {$customerName},\n\n";
    $headerMessage .= "Thank you for your payment! Here are your tickets.\n\n";
    $headerMessage .= "📋 *Reservation ID:* {$reservation_id}\n";
    $headerMessage .= "🎪 *Event:* {$eventName}\n";
    $headerMessage .= "📱 *Total Tickets:* " . count($tickets) . "\n\n";
    $headerMessage .= "⬇️ *Your tickets are attached below as images.* ⬇️\n";
    $headerMessage .= "Press and hold on each image to save to your phone.\n";
    $headerMessage .= "Show the saved images at the entrance.\n\n";
    $headerMessage .= "We look forward to seeing you! 🎉";
    
    sendWhatsAppMessage($cleanPhone, $headerMessage);
    
    // Send each ticket as QR code image
    $ticketCount = 0;
    foreach ($tickets as $ticket) {
        $ticketCount++;
        $typeLabel = ucfirst($ticket['guest_type']);
        $ticketNumber = str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT);
        
        $qrUrl = "https://quickchart.io/qr?text=" . urlencode($ticket['ticket_code']) . "&size=250&margin=2";
        
        $caption = "🎫 *{$typeLabel} Ticket #{$ticketNumber}*\n";
        $caption .= "ID: {$ticket['ticket_code']}\n";
        $caption .= "Valid for one-time entry\n";
        $caption .= "Show this QR code at the entrance";
        
        sendWhatsAppImage($cleanPhone, $qrUrl, $caption);
        usleep(500000); // 0.5 sec delay
    }
    
    // Send closing message
    if ($ticketCount > 0) {
        $closingMessage = "✅ *All {$ticketCount} ticket(s) sent!*\n\n";
        $closingMessage .= "📸 Each ticket has been sent as a QR code image.\n";
        $closingMessage .= "💾 Press and hold on each image to save to your phone gallery.\n";
        $closingMessage .= "📱 Show the saved images at the entrance for scanning.\n\n";
        $closingMessage .= "Thank you for choosing us! 🎉";
        
        sendWhatsAppMessage($cleanPhone, $closingMessage);
    }
    
    return $ticketCount;
}
?>