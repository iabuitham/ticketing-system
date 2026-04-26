<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$ticket_code = isset($_GET['ticket_code']) ? sanitizeInput($_GET['ticket_code']) : '';
$size = isset($_GET['size']) ? intval($_GET['size']) : 150;

if (empty($ticket_code)) {
    die('No ticket code provided');
}

// Use QuickChart.io API (free, no library needed)
$qr_url = "https://quickchart.io/qr?text=" . urlencode($ticket_code) . "&size={$size}&margin=2";

// Redirect to the QR code image
header('Location: ' . $qr_url);
?>