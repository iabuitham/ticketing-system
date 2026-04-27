<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get form data
    $reservation_id = sanitizeInput($_POST['reservation_id']);
    $name = sanitizeInput($_POST['name']);
    $phone = sanitizeInput($_POST['phone']);
    $table_id = sanitizeInput($_POST['table_id']);
    $new_adults = intval($_POST['adults']);
    $new_teens = intval($_POST['teens']);
    $new_kids = intval($_POST['kids']);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $new_status = sanitizeInput($_POST['status']);
    
    // Get current reservation
    $stmt = $conn->prepare("SELECT * FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $reservation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$reservation) {
        $_SESSION['update_message'] = "Reservation not found!";
        $_SESSION['update_message_type'] = "error";
        header('Location: dashboard.php');
        exit();
    }
    
    // Check if guest counts changed
    $old_adults = intval($reservation['adults']);
    $old_teens = intval($reservation['teens']);
    $old_kids = intval($reservation['kids']);
    $guests_changed = ($new_adults != $old_adults || $new_teens != $old_teens || $new_kids != $old_kids);
    
    // Get total paid from split_payments
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM split_payments WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $paidResult = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $total_paid = floatval($paidResult['total_paid']);
    
    // Get ticket prices from event
    $selected_event_id = $_SESSION['selected_event_id'] ?? 0;
    $adultPrice = 10;
    $teenPrice = 10;
    $kidPrice = 0;
    
    if ($selected_event_id > 0) {
        $stmt = $conn->prepare("SELECT ticket_price_adult, ticket_price_teen, ticket_price_kid FROM event_settings WHERE id = ?");
        $stmt->bind_param("i", $selected_event_id);
        $stmt->execute();
        $event_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($event_data) {
            $adultPrice = floatval($event_data['ticket_price_adult']);
            $teenPrice = floatval($event_data['ticket_price_teen']);
            $kidPrice = floatval($event_data['ticket_price_kid']);
        }
    }
    
    // Calculate new values based on NEW guest counts
    $new_total_amount = ($new_adults * $adultPrice) + ($new_teens * $teenPrice) + ($new_kids * $kidPrice);
    $new_additional_due = max(0, $new_total_amount - $total_paid);
    
    // Determine status
    if ($new_status == 'cancelled') {
        $final_status = 'cancelled';
    } elseif ($new_additional_due <= 0) {
        $final_status = 'paid';
    } else {
        $final_status = $new_status;
    }
    
    // Generate NEW reservation ID if guests changed
    $new_reservation_id = $reservation_id;
    if ($guests_changed) {
        $new_reservation_id = regenerateReservationIdFromOld($reservation_id, $new_adults, $new_teens, $new_kids);
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // If reservation ID changed, update all related tables
        if ($new_reservation_id != $reservation_id) {
            // Disable foreign key checks
            $conn->query("SET FOREIGN_KEY_CHECKS = 0");
            
            // Update split_payments to new reservation_id
            $stmt = $conn->prepare("UPDATE split_payments SET reservation_id = ? WHERE reservation_id = ?");
            $stmt->bind_param("ss", $new_reservation_id, $reservation_id);
            $stmt->execute();
            $stmt->close();
            
            // Update ticket_codes to new reservation_id
            $stmt = $conn->prepare("UPDATE ticket_codes SET reservation_id = ? WHERE reservation_id = ?");
            $stmt->bind_param("ss", $new_reservation_id, $reservation_id);
            $stmt->execute();
            $stmt->close();
            
            // Update the reservation with new ID
            $stmt = $conn->prepare("UPDATE reservations SET 
                reservation_id = ?,
                name = ?, 
                phone = ?, 
                table_id = ?, 
                adults = ?, 
                teens = ?, 
                kids = ?, 
                total_amount = ?, 
                additional_amount_due = ?, 
                notes = ?, 
                status = ?, 
                updated_at = NOW() 
                WHERE id = ?");
            
            $stmt->bind_param(
                "ssssiiiddssi",
                $new_reservation_id, $name, $phone, $table_id,
                $new_adults, $new_teens, $new_kids,
                $new_total_amount, $new_additional_due,
                $notes, $final_status,
                $reservation['id']
            );
            $stmt->execute();
            $stmt->close();
            
            // Update ticket codes themselves (regenerate with new ID)
            $tickets = $conn->query("SELECT id, guest_type, guest_number FROM ticket_codes WHERE reservation_id = '$new_reservation_id'");
            while ($ticket = $tickets->fetch_assoc()) {
                $new_ticket_code = generateTicketId($new_reservation_id, $ticket['guest_type'], $ticket['guest_number']);
                $conn->query("UPDATE ticket_codes SET ticket_code = '$new_ticket_code' WHERE id = {$ticket['id']}");
            }
            
            $conn->query("SET FOREIGN_KEY_CHECKS = 1");
            
        } else {
            // Just update the reservation without changing ID
            $stmt = $conn->prepare("UPDATE reservations SET 
                name = ?, 
                phone = ?, 
                table_id = ?, 
                adults = ?, 
                teens = ?, 
                kids = ?, 
                total_amount = ?, 
                additional_amount_due = ?, 
                notes = ?, 
                status = ?, 
                updated_at = NOW() 
                WHERE reservation_id = ?");
            
            $stmt->bind_param(
                "sssiiiddsss",
                $name, $phone, $table_id,
                $new_adults, $new_teens, $new_kids,
                $new_total_amount, $new_additional_due,
                $notes, $final_status,
                $reservation_id
            );
            $stmt->execute();
            $stmt->close();
        }
        
        // Update tickets - regenerate if guests changed (for non-ID change case)
        if ($guests_changed && $new_reservation_id == $reservation_id) {
            $conn->query("DELETE FROM ticket_codes WHERE reservation_id = '$reservation_id'");
            
            // Generate new tickets
            for ($i = 1; $i <= $new_adults; $i++) {
                $ticketCode = generateTicketId($reservation_id, 'adult', $i);
                $stmt2 = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'adult', ?)");
                $stmt2->bind_param("ssi", $reservation_id, $ticketCode, $i);
                $stmt2->execute();
                $stmt2->close();
            }
            
            for ($i = 1; $i <= $new_teens; $i++) {
                $ticketCode = generateTicketId($reservation_id, 'teen', $i);
                $stmt2 = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'teen', ?)");
                $stmt2->bind_param("ssi", $reservation_id, $ticketCode, $i);
                $stmt2->execute();
                $stmt2->close();
            }
            
            for ($i = 1; $i <= $new_kids; $i++) {
                $ticketCode = generateTicketId($reservation_id, 'kid', $i);
                $stmt2 = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'kid', ?)");
                $stmt2->bind_param("ssi", $reservation_id, $ticketCode, $i);
                $stmt2->execute();
                $stmt2->close();
            }
        }
        
        // Update table availability
        updateTableAvailability();
        
        $conn->commit();
        
        $_SESSION['update_message'] = "Reservation updated successfully!";
        if ($new_reservation_id != $reservation_id) {
            $_SESSION['update_message'] .= " New Reservation ID: " . $new_reservation_id;
        }
        if ($new_additional_due > 0) {
            $_SESSION['update_message'] .= " | Additional payment due: " . number_format($new_additional_due, 2) . " " . getCurrencySymbol();
        }
        $_SESSION['update_message_type'] = "success";
        
        header("Location: edit_reservation.php?id=" . urlencode($new_reservation_id));
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['update_message'] = "Error updating reservation: " . $e->getMessage();
        $_SESSION['update_message_type'] = "error";
        header("Location: edit_reservation.php?id=" . urlencode($reservation_id));
        exit();
    }
}

header('Location: dashboard.php');
exit();
?>