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
    $reservation_id = sanitizeInput($_POST['reservation_id']);
    $name = sanitizeInput($_POST['name']);
    $phone = sanitizeInput($_POST['phone']);
    $table_id = sanitizeInput($_POST['table_id']);
    $new_adults = intval($_POST['adults']);
    $new_teens = intval($_POST['teens']);
    $new_kids = intval($_POST['kids']);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $new_status = sanitizeInput($_POST['status']);
    
    // Get current reservation data
    $stmt = $conn->prepare("SELECT * FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $reservation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$reservation) {
        header('Location: dashboard.php?error=Reservation not found');
        exit();
    }
    
    // Check if guest counts changed
    $old_adults = intval($reservation['adults']);
    $old_teens = intval($reservation['teens']);
    $old_kids = intval($reservation['kids']);
    
    $guests_changed = ($new_adults != $old_adults || $new_teens != $old_teens || $new_kids != $old_kids);
    
    // Get event-specific ticket prices
    $selected_event_id = $_SESSION['selected_event_id'] ?? 0;
    $event_ticket_prices = $_SESSION['event_ticket_prices'] ?? null;
    
    if (!$event_ticket_prices && $selected_event_id > 0) {
        $stmt = $conn->prepare("SELECT ticket_price_adult, ticket_price_teen, ticket_price_kid FROM event_settings WHERE id = ?");
        $stmt->bind_param("i", $selected_event_id);
        $stmt->execute();
        $event_data = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($event_data) {
            $event_ticket_prices = $event_data;
            $_SESSION['event_ticket_prices'] = $event_ticket_prices;
        }
    }
    
    $adultPrice = $event_ticket_prices['ticket_price_adult'] ?? getSetting('ticket_price_adult', 10);
    $teenPrice = $event_ticket_prices['ticket_price_teen'] ?? getSetting('ticket_price_teen', 10);
    $kidPrice = $event_ticket_prices['ticket_price_kid'] ?? getSetting('ticket_price_kid', 0);
    
    // Calculate new total amount
    $new_total_amount = ($new_adults * $adultPrice) + ($new_teens * $teenPrice) + ($new_kids * $kidPrice);
    
    // Get total paid from split_payments
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM split_payments WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $paidResult = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $total_paid = floatval($paidResult['total_paid']);
    
    // Calculate new additional amount due
    $new_additional_due = max(0, $new_total_amount - $total_paid);
    
    // Generate NEW reservation ID if guests changed (using function from functions.php)
    $new_reservation_id = $reservation_id;
    if ($guests_changed) {
        $new_reservation_id = regenerateReservationIdFromOld($reservation_id, $new_adults, $new_teens, $new_kids);
    }
    
    // Determine new status
    if ($new_status == 'cancelled') {
        $final_status = 'cancelled';
    } elseif ($new_additional_due <= 0) {
        $final_status = 'paid';
    } elseif ($new_status == 'paid' && $new_additional_due > 0) {
        $final_status = 'registered';
    } else {
        $final_status = $new_status;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Disable foreign key checks temporarily
        $conn->query("SET FOREIGN_KEY_CHECKS = 0");
        
        // If reservation ID changed, update it in all related tables
        if ($new_reservation_id != $reservation_id) {
            // Insert new reservation with new ID
            $columns_result = $conn->query("SHOW COLUMNS FROM reservations");
            $columns = [];
            while ($col = $columns_result->fetch_assoc()) {
                if ($col['Field'] != 'id') {
                    $columns[] = $col['Field'];
                }
            }
            
            $column_list = implode(', ', $columns);
            $value_placeholders = implode(', ', array_fill(0, count($columns), '?'));
            
            $insert_sql = "INSERT INTO reservations ($column_list) VALUES ($value_placeholders)";
            $stmt = $conn->prepare($insert_sql);
            
            $params = [];
            foreach ($columns as $col) {
                if ($col == 'reservation_id') {
                    $params[] = $new_reservation_id;
                } elseif ($col == 'adults') {
                    $params[] = $new_adults;
                } elseif ($col == 'teens') {
                    $params[] = $new_teens;
                } elseif ($col == 'kids') {
                    $params[] = $new_kids;
                } elseif ($col == 'total_amount') {
                    $params[] = $new_total_amount;
                } elseif ($col == 'additional_amount_due') {
                    $params[] = $new_additional_due;
                } elseif ($col == 'status') {
                    $params[] = $final_status;
                } elseif ($col == 'name') {
                    $params[] = $name;
                } elseif ($col == 'phone') {
                    $params[] = $phone;
                } elseif ($col == 'table_id') {
                    $params[] = $table_id;
                } elseif ($col == 'notes') {
                    $params[] = $notes;
                } elseif ($col == 'updated_at') {
                    $params[] = date('Y-m-d H:i:s');
                } else {
                    $params[] = $reservation[$col];
                }
            }
            
            $types = str_repeat('s', count($params));
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $stmt->close();
            
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
            
            // Regenerate ticket codes
            $conn->query("DELETE FROM ticket_codes WHERE reservation_id = '$new_reservation_id'");
            
            // Generate new tickets
            for ($i = 1; $i <= $new_adults; $i++) {
                $ticketCode = generateTicketId($new_reservation_id, 'adult', $i);
                $stmt = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'adult', ?)");
                $stmt->bind_param("ssi", $new_reservation_id, $ticketCode, $i);
                $stmt->execute();
                $stmt->close();
            }
            
            for ($i = 1; $i <= $new_teens; $i++) {
                $ticketCode = generateTicketId($new_reservation_id, 'teen', $i);
                $stmt = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'teen', ?)");
                $stmt->bind_param("ssi", $new_reservation_id, $ticketCode, $i);
                $stmt->execute();
                $stmt->close();
            }
            
            for ($i = 1; $i <= $new_kids; $i++) {
                $ticketCode = generateTicketId($new_reservation_id, 'kid', $i);
                $stmt = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'kid', ?)");
                $stmt->bind_param("ssi", $new_reservation_id, $ticketCode, $i);
                $stmt->execute();
                $stmt->close();
            }
            
            // Delete old reservation
            $stmt = $conn->prepare("DELETE FROM reservations WHERE id = ?");
            $stmt->bind_param("i", $reservation['id']);
            $stmt->execute();
            $stmt->close();
            
        } else {
            // Just update reservation without changing ID
            $update = $conn->prepare("UPDATE reservations 
                                      SET name = ?, phone = ?, table_id = ?, 
                                          adults = ?, teens = ?, kids = ?, 
                                          total_amount = ?, additional_amount_due = ?, 
                                          notes = ?, status = ?, updated_at = NOW()
                                      WHERE reservation_id = ?");
            $update->bind_param("sssiiiddsss", $name, $phone, $table_id, 
                                $new_adults, $new_teens, $new_kids, 
                                $new_total_amount, $new_additional_due, 
                                $notes, $final_status, $reservation_id);
            $update->execute();
            $update->close();
            
            // Update tickets if guests changed
            if ($guests_changed) {
                $conn->query("DELETE FROM ticket_codes WHERE reservation_id = '$reservation_id'");
                
                for ($i = 1; $i <= $new_adults; $i++) {
                    $ticketCode = generateTicketId($reservation_id, 'adult', $i);
                    $stmt = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'adult', ?)");
                    $stmt->bind_param("ssi", $reservation_id, $ticketCode, $i);
                    $stmt->execute();
                    $stmt->close();
                }
                
                for ($i = 1; $i <= $new_teens; $i++) {
                    $ticketCode = generateTicketId($reservation_id, 'teen', $i);
                    $stmt = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'teen', ?)");
                    $stmt->bind_param("ssi", $reservation_id, $ticketCode, $i);
                    $stmt->execute();
                    $stmt->close();
                }
                
                for ($i = 1; $i <= $new_kids; $i++) {
                    $ticketCode = generateTicketId($reservation_id, 'kid', $i);
                    $stmt = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'kid', ?)");
                    $stmt->bind_param("ssi", $reservation_id, $ticketCode, $i);
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }
        
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->commit();
        
        $_SESSION['update_message'] = "Reservation updated successfully!";
        if ($new_reservation_id != $reservation_id) {
            $_SESSION['update_message'] .= " New Reservation ID: " . $new_reservation_id;
        }
        $_SESSION['update_message_type'] = "success";
        
        if ($new_additional_due > 0) {
            $_SESSION['update_message'] .= " | Additional payment due: " . number_format($new_additional_due, 2) . " " . getCurrencySymbol();
        }
        
        header("Location: edit_reservation.php?id=" . urlencode($new_reservation_id));
        exit();
        
    } catch (Exception $e) {
        $conn->query("SET FOREIGN_KEY_CHECKS = 1");
        $conn->rollback();
        $_SESSION['update_message'] = "Error updating reservation: " . $e->getMessage();
        $_SESSION['update_message_type'] = "error";
        header("Location: edit_reservation.php?id=" . urlencode($reservation_id));
        exit();
    }
}

header('Location: dashboard.php');
exit();

// DO NOT DECLARE regenerateReservationIdFromOld() HERE - It's already in functions.php
?>