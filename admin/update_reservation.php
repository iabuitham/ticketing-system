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
    
    // Update the reservation
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
    
    if ($stmt->execute()) {
        // Update tickets - delete old and create new
        $conn->query("DELETE FROM ticket_codes WHERE reservation_id = '$reservation_id'");
        
        // Generate new tickets for adults
        for ($i = 1; $i <= $new_adults; $i++) {
            $ticketCode = generateTicketId($reservation_id, 'adult', $i);
            $stmt2 = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'adult', ?)");
            $stmt2->bind_param("ssi", $reservation_id, $ticketCode, $i);
            $stmt2->execute();
            $stmt2->close();
        }
        
        // Generate new tickets for teens
        for ($i = 1; $i <= $new_teens; $i++) {
            $ticketCode = generateTicketId($reservation_id, 'teen', $i);
            $stmt2 = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'teen', ?)");
            $stmt2->bind_param("ssi", $reservation_id, $ticketCode, $i);
            $stmt2->execute();
            $stmt2->close();
        }
        
        // Generate new tickets for kids
        for ($i = 1; $i <= $new_kids; $i++) {
            $ticketCode = generateTicketId($reservation_id, 'kid', $i);
            $stmt2 = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'kid', ?)");
            $stmt2->bind_param("ssi", $reservation_id, $ticketCode, $i);
            $stmt2->execute();
            $stmt2->close();
        }
        
        $_SESSION['update_message'] = "Reservation updated successfully!";
        if ($new_additional_due > 0) {
            $_SESSION['update_message'] .= " | Additional payment due: " . number_format($new_additional_due, 2) . " " . getCurrencySymbol();
        }
        $_SESSION['update_message_type'] = "success";
        
        header("Location: edit_reservation.php?id=" . urlencode($reservation_id));
        exit();
    } else {
        $_SESSION['update_message'] = "Error updating reservation: " . $conn->error;
        $_SESSION['update_message_type'] = "error";
        header("Location: edit_reservation.php?id=" . urlencode($reservation_id));
        exit();
    }
    $stmt->close();
}

header('Location: dashboard.php');
exit();
?>