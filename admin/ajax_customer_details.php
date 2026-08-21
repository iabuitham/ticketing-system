<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Invalid customer ID']);
    exit();
}

$conn = getConnection();

// Get customer details
$stmt = $conn->prepare("SELECT * FROM customers WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer) {
    echo json_encode(['success' => false, 'error' => 'Customer not found']);
    $conn->close();
    exit();
}

// Get customer reservations
$resStmt = $conn->prepare("
    SELECT reservation_id, total_amount, status, created_at 
    FROM reservations 
    WHERE phone = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$resStmt->bind_param("s", $customer['phone']);
$resStmt->execute();
$reservations = $resStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$resStmt->close();

$conn->close();

// Ensure numeric values are not null
$customer['total_visits'] = intval($customer['total_visits'] ?? 0);
$customer['total_spent'] = floatval($customer['total_spent'] ?? 0);
$customer['is_vip'] = intval($customer['is_vip'] ?? 0);

echo json_encode([
    'success' => true,
    'customer' => $customer,
    'reservations' => $reservations
]);
?>