<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$table_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($table_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid table ID']);
    exit();
}

$conn = getConnection();
$stmt = $conn->prepare("SELECT * FROM tables WHERE id = ?");
$stmt->bind_param("i", $table_id);
$stmt->execute();
$table = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if ($table) {
    echo json_encode(['success' => true, 'table' => $table]);
} else {
    echo json_encode(['success' => false, 'error' => 'Table not found']);
}
?>