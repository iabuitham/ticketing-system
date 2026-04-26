<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

echo "<h2>POST Data Received:</h2>";
echo "<pre>";
print_r($_POST);
echo "</pre>";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reservation_id'])) {
    $reservation_id = $_POST['reservation_id'];
    $new_adults = intval($_POST['adults']);
    $new_teens = intval($_POST['teens']);
    $new_kids = intval($_POST['kids']);
    
    echo "<h3>Parsed Values:</h3>";
    echo "Reservation ID: $reservation_id<br>";
    echo "Adults: $new_adults<br>";
    echo "Teens: $new_teens<br>";
    echo "Kids: $new_kids<br>";
    
    // Get current reservation
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT * FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $reservation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    echo "<h3>Current Database Values:</h3>";
    echo "Adults: " . $reservation['adults'] . "<br>";
    echo "Teens: " . $reservation['teens'] . "<br>";
    echo "Kids: " . $reservation['kids'] . "<br>";
    echo "Total Amount: " . $reservation['total_amount'] . "<br>";
    
    $conn->close();
}
?>
<a href="edit_reservation.php?id=<?php echo $_POST['reservation_id'] ?? ''; ?>">Go back to edit</a>