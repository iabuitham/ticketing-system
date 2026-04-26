<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$event_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($event_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid event ID']);
    exit();
}

$conn = getConnection();
$stmt = $conn->prepare("SELECT * FROM event_settings WHERE id = ?");
$stmt->bind_param("i", $event_id);
$stmt->execute();
$event = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if ($event) {
    // Set default values for any missing fields
    $event['description'] = $event['description'] ?? '';
    $event['capacity'] = $event['capacity'] ?? 0;
    $event['tickets_sold'] = $event['tickets_sold'] ?? 0;
    $event['status'] = $event['status'] ?? 'upcoming';
    $event['image_url'] = $event['image_url'] ?? null;
    $event['ticket_price_adult'] = $event['ticket_price_adult'] ?? 10;
    $event['ticket_price_teen'] = $event['ticket_price_teen'] ?? 10;
    $event['ticket_price_kid'] = $event['ticket_price_kid'] ?? 0;
    
    echo json_encode(['success' => true, 'event' => $event]);
} else {
    echo json_encode(['success' => false, 'error' => 'Event not found']);
}
?>