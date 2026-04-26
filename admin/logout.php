<?php
session_start();
if (isset($_GET['switch_event'])) {
    // Just clear the event selection, keep logged in
    unset($_SESSION['selected_event_id']);
    unset($_SESSION['selected_event_name']);
    unset($_SESSION['selected_event_date']);
    unset($_SESSION['event_ticket_prices']);
    header('Location: login.php');
} else {
    // Full logout
    session_destroy();
    header('Location: login.php');
}
exit();
?>