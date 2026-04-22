<?php
include 'db.php';

// Basic validation
$name   = trim($_POST['name']);
$phone  = trim($_POST['phone']);
$guests = intval($_POST['guests']);

if ($name === "" || $phone === "" || $guests <= 0) {
    die("Invalid input");
}

// Insert
$sql = "INSERT INTO reservations (name, phone, guests) VALUES (?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ssi", $name, $phone, $guests);

if ($stmt->execute()) {
    echo "Reservation submitted!";
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>