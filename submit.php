<?php
include 'db.php';

$name   = trim($_POST['name']);
$phone  = trim($_POST['phone']);
$guests = intval($_POST['guests']);

// cleaning
$phone = preg_replace('/\s+/', '', $phone); // remove spaces

// ensure it starts with 0
if (substr($phone, 0, 1) === "0") {
    $phone = substr($phone, 1);
}

// add Jordan country code
$phone = "+00962" . $phone;

// validation
if ($name === "" || $phone === "+00962" || $guests <= 0) {
    die("Invalid input");
}

// insert
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