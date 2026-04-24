<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['reservation_id'];
    $name = $_POST['name'];
    $phone = $_POST['phone'];
    $new_adults = intval($_POST['adults']);
    $new_teens = intval($_POST['teens']);
    $new_kids = intval($_POST['kids']);
    $table_id = $_POST['table_id'];
    $notes = $_POST['notes'];
    $status = $_POST['status'];
    
    // Get current total amount
    $result = $conn->query("SELECT total_amount FROM reservations WHERE reservation_id = '$id'");
    $old = $result->fetch_assoc();
    
    // Get prices
    $adultPrice = 10;
    $teenPrice = 10;
    $priceResult = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_price_adult'");
    if ($row = $priceResult->fetch_assoc()) $adultPrice = floatval($row['setting_value']);
    
    $priceResult = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'ticket_price_teen'");
    if ($row = $priceResult->fetch_assoc()) $teenPrice = floatval($row['setting_value']);
    
    $new_total = ($new_adults * $adultPrice) + ($new_teens * $teenPrice);
    $additional_due = $new_total - $old['total_amount'];
    if ($additional_due < 0) $additional_due = 0;
    
    // Format phone
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 1) == '0') $phone = substr($phone, 1);
    if (substr($phone, 0, 3) != '962') $phone = '962' . $phone;
    $phone = '+' . $phone;
    
    // Update reservation
    $sql = "UPDATE reservations SET 
        name = '$name',
        phone = '$phone',
        adults = $new_adults,
        teens = $new_teens,
        kids = $new_kids,
        table_id = '$table_id',
        notes = '$notes',
        status = '$status',
        total_amount = $new_total,
        additional_amount_due = $additional_due
        WHERE reservation_id = '$id'";
    
    if ($conn->query($sql)) {
        // Regenerate tickets
        $conn->query("DELETE FROM ticket_codes WHERE reservation_id = '$id'");
        
        $counter = 1;
        for ($i = 1; $i <= $new_adults; $i++) {
            $code = $id . '-A' . str_pad($i, 3, '0', STR_PAD_LEFT);
            $conn->query("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES ('$id', '$code', 'adult', $counter)");
            $counter++;
        }
        for ($i = 1; $i <= $new_teens; $i++) {
            $code = $id . '-T' . str_pad($i, 3, '0', STR_PAD_LEFT);
            $conn->query("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES ('$id', '$code', 'teen', $counter)");
            $counter++;
        }
        for ($i = 1; $i <= $new_kids; $i++) {
            $code = $id . '-K' . str_pad($i, 3, '0', STR_PAD_LEFT);
            $conn->query("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES ('$id', '$code', 'kid', $counter)");
            $counter++;
        }
        
        $_SESSION['success'] = "Reservation updated successfully!";
        if ($additional_due > 0) {
            $_SESSION['warning'] = "⚠️ Additional payment of " . number_format($additional_due, 2) . " JOD required.";
        }
    } else {
        $_SESSION['error'] = "Error: " . $conn->error;
    }
    
    $conn->close();
    header('Location: view_reservation.php?id=' . urlencode($id));
    exit();
}
?>