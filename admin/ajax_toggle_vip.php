<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$id = isset($input['id']) ? intval($input['id']) : 0;
$is_vip = isset($input['is_vip']) ? intval($input['is_vip']) : 0;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid customer ID']);
    exit();
}

$conn = getConnection();

$stmt = $conn->prepare("UPDATE customers SET is_vip = ? WHERE id = ?");
$stmt->bind_param("ii", $is_vip, $id);

if ($stmt->execute()) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'error' => $conn->error]);
}

$stmt->close();
$conn->close();
?>