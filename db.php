<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "ticketing-system";

$conn = new mysqli($host, $user, $pass, $db);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>