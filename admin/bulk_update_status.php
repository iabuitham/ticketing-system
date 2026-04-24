<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$ids = isset($data['ids']) ? $data['ids'] : [];
$status = isset($data['status']) ? trim($data['status']) : 'paid';
$password = isset($data['password']) ? $data['password'] : '';

// Verify password
if ($password !== 'AdminDelete2026') {
    echo json_encode(['success' => false, 'error' => 'Invalid password']);
    exit();
}

if (empty($ids)) {
    echo json_encode(['success' => false, 'error' => 'No reservations selected']);
    exit();
}

$conn = getConnection();
$updated = 0;

foreach ($ids as $id) {
    $id = $conn->real_escape_string($id);
    $conn->query("UPDATE reservations SET status = '$status' WHERE reservation_id = '$id'");
    if ($conn->affected_rows > 0) {
        $updated++;
    }
}

$conn->close();

echo json_encode(['success' => true, 'updated' => $updated]);
?>  