<?php
/**
 * Early Bird Pricing Functions
 * File: includes/early_bird.php
 */

function isEarlyBirdActive($conn, $event_id) {
    if (!$event_id) return false;
    
    $stmt = $conn->prepare("
        SELECT early_bird_enabled, early_bird_deadline 
        FROM event_settings WHERE id = ?
    ");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($event && $event['early_bird_enabled'] && $event['early_bird_deadline']) {
        $today = date('Y-m-d');
        return $today <= $event['early_bird_deadline'];
    }
    return false;
}

function getEarlyBirdPrice($conn, $event_id, $type, $regular_price) {
    if (!$event_id) return $regular_price;
    
    $stmt = $conn->prepare("
        SELECT early_bird_enabled, early_bird_deadline, 
               early_bird_price_adult, early_bird_price_teen, early_bird_price_kid 
        FROM event_settings WHERE id = ?
    ");
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    $event = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($event && $event['early_bird_enabled'] && $event['early_bird_deadline']) {
        $today = date('Y-m-d');
        if ($today <= $event['early_bird_deadline']) {
            $price_col = "early_bird_price_" . $type;
            if (!empty($event[$price_col]) && $event[$price_col] > 0) {
                return $event[$price_col];
            }
        }
    }
    
    return $regular_price;
}

// Display early bird countdown in UI
function getEarlyBirdCountdown($deadline) {
    if (!$deadline) return '';
    $now = new DateTime();
    $end = new DateTime($deadline);
    if ($now > $end) return '<span class="early-bird-expired">Expired</span>';
    
    $diff = $now->diff($end);
    $days = $diff->days;
    
    if ($days > 0) {
        return "<span class='early-bird-active'>🎫 Early Bird: $days days left!</span>";
    } elseif ($diff->h > 0) {
        return "<span class='early-bird-active'>🎫 Early Bird: {$diff->h} hours left!</span>";
    } else {
        return "<span class='early-bird-urgent'>⚠️ Early Bird ends today!</span>";
    }
}
?>