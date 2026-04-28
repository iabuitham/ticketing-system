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

echo "<h1>Force Send Tickets</h1>";

// Get a specific reservation - change this to your reservation ID
$reservation_id = 'RES0001-20G20A0T0K-8SL6X'; // CHANGE THIS

$conn = getConnection();
$stmt = $conn->prepare("SELECT name, phone FROM reservations WHERE reservation_id = ?");
$stmt->bind_param("s", $reservation_id);
$stmt->execute();
$reservation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$reservation) {
    echo "Reservation not found!";
    exit();
}

echo "Sending tickets to: " . $reservation['name'] . " (" . $reservation['phone'] . ")<br>";

// Manually call the function
$result = sendAllTicketsAsImages($reservation_id, $reservation['phone'], $reservation['name']);

if ($result) {
    echo "<p style='color:green'>✅ Tickets sent successfully!</p>";
} else {
    echo "<p style='color:red'>❌ Failed to send tickets.</p>";
}

$conn->close();
?>