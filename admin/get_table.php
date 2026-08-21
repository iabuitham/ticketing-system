<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'No table ID provided']);
    exit();
}

$table_id = intval($_GET['id']);
$conn = getConnection();

$stmt = $conn->prepare("SELECT * FROM tables WHERE id = ?");
$stmt->bind_param("i", $table_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'error' => 'Table not found']);
    $stmt->close();
    $conn->close();
    exit();
}

$table = $result->fetch_assoc();
$stmt->close();
$conn->close();

echo json_encode(['success' => true, 'table' => $table]);
?>