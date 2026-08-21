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
$input = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? $input['action'] ?? '';

if ($action == 'get_points' && isset($_GET['phone'])) {
    $phone = sanitizeInput($_GET['phone']);
    $stmt = $conn->prepare("SELECT available_points, name FROM loyalty_customers WHERE phone = ?");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $customer = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($customer) {
        echo json_encode(['success' => true, 'available_points' => $customer['available_points'], 'name' => $customer['name']]);
    } else {
        echo json_encode(['success' => true, 'available_points' => 0, 'name' => null]);
    }
    
} elseif ($action == 'redeem' && isset($input['phone']) && isset($input['reward_id'])) {
    $password = $input['password'] ?? '';
    if ($password !== 'AdminDelete2026') {
        echo json_encode(['success' => false, 'error' => 'Invalid password']);
        exit();
    }
    
    $phone = sanitizeInput($input['phone']);
    $reward_id = intval($input['reward_id']);
    
    // Get reward details
    $rewardStmt = $conn->prepare("SELECT * FROM loyalty_rewards WHERE id = ? AND is_active = 1");
    $rewardStmt->bind_param("i", $reward_id);
    $rewardStmt->execute();
    $reward = $rewardStmt->get_result()->fetch_assoc();
    $rewardStmt->close();
    
    if (!$reward) {
        echo json_encode(['success' => false, 'error' => 'Invalid reward']);
        exit();
    }
    
    // Get customer points
    $custStmt = $conn->prepare("SELECT available_points, name FROM loyalty_customers WHERE phone = ?");
    $custStmt->bind_param("s", $phone);
    $custStmt->execute();
    $customer = $custStmt->get_result()->fetch_assoc();
    $custStmt->close();
    
    if (!$customer || $customer['available_points'] < $reward['points_required']) {
        echo json_encode(['success' => false, 'error' => 'Insufficient points']);
        exit();
    }
    
    // Generate redemption code
    $redemption_code = strtoupper(substr(uniqid(), -8));
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Add to redeemed table
        $redeemStmt = $conn->prepare("INSERT INTO loyalty_redeemed (phone, reward_id, points_spent, redemption_code, status) VALUES (?, ?, ?, ?, 'approved')");
        $redeemStmt->bind_param("sids", $phone, $reward_id, $reward['points_required'], $redemption_code);
        $redeemStmt->execute();
        $redeemStmt->close();
        
        // Add transaction record
        $transStmt = $conn->prepare("INSERT INTO loyalty_points_transactions (phone, points_redeemed, transaction_type, description, processed_by) VALUES (?, ?, 'redeem', ?, ?)");
        $description = "Redeemed: " . $reward['reward_name'];
        $transStmt->bind_param("sdss", $phone, $reward['points_required'], $description, $_SESSION['admin_username']);
        $transStmt->execute();
        $transStmt->close();
        
        // Update customer points
        $updateStmt = $conn->prepare("UPDATE loyalty_customers SET available_points = available_points - ? WHERE phone = ?");
        $updateStmt->bind_param("ds", $reward['points_required'], $phone);
        $updateStmt->execute();
        $updateStmt->close();
        
        $conn->commit();
        
        // Send WhatsApp message
        $message = "🎁 *REWARD REDEEMED!* 🎁\n\n";
        $message .= "Dear {$customer['name']},\n\n";
        $message .= "You have successfully redeemed:\n";
        $message .= "✨ *" . $reward['reward_name'] . "*\n";
        $message .= "🔢 *Points spent:* " . number_format($reward['points_required'], 1) . " points\n";
        $message .= "🎟️ *Redemption Code:* `{$redemption_code}`\n\n";
        $message .= "Please show this code to our staff to claim your reward.\n\n";
        $message .= "Thank you for being a loyal customer! 🎉";
        
        sendWhatsAppMessage($phone, $message);
        
        echo json_encode(['success' => true, 'redemption_code' => $redemption_code]);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

$conn->close();
?>