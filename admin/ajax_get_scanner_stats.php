<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$conn = getConnection();

$totalTickets = $conn->query("SELECT COUNT(*) as count FROM ticket_codes WHERE is_active = 1")->fetch_assoc()['count'];
$totalScanned = $conn->query("SELECT COUNT(*) as count FROM ticket_codes WHERE is_scanned = 1")->fetch_assoc()['count'];
$scannedToday = $conn->query("SELECT COUNT(*) as count FROM scan_logs WHERE DATE(scanned_at) = CURDATE()")->fetch_assoc()['count'];

$conn->close();

echo json_encode([
    'success' => true,
    'total_tickets' => $totalTickets,
    'remaining' => $totalTickets - $totalScanned,
    'total_scanned' => $totalScanned,
    'scanned_today' => $scannedToday
]);
?>