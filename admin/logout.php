<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (isset($_GET['switch_event'])) {
    // Check how many events are available
    $conn = getConnection();
    $result = $conn->query("SELECT COUNT(*) as count FROM event_settings WHERE status != 'completed'");
    $eventCount = $result->fetch_assoc()['count'];
    $conn->close();
    
    if ($eventCount <= 1) {
        // Only one event available, store message in session and redirect back
        $_SESSION['switch_error'] = "Cannot switch events. There is only " . ($eventCount == 0 ? "no active event" : "one event") . " available.";
        $_SESSION['switch_error_type'] = 'warning';
        header('Location: dashboard.php');
        exit();
    } else {
        // Clear the event selection, keep logged in
        unset($_SESSION['selected_event_id']);
        unset($_SESSION['selected_event_name']);
        unset($_SESSION['selected_event_date']);
        unset($_SESSION['event_ticket_prices']);
        header('Location: login.php');
        exit();
    }
} else {
    // Full logout
    session_destroy();
    header('Location: login.php');
    exit();
}
?>