<?php
/**
 * Ticketing System - Core Functions
 * All settings are now dynamic and fetched from database
 */

// Include db.php for database connection
require_once __DIR__ . '/db.php';

/**
 * Get system setting value by key
 */
function getSetting($key, $default = null) {
    static $settings = null;
    static $settingsCache = null;
    
    // Special handling for reservation_prefix - always return 'RES' for the new pattern
    if ($key == 'reservation_prefix') {
        return 'RES';
    }
    
    if ($settingsCache !== null && isset($settingsCache[$key])) {
        return $settingsCache[$key];
    }
    
    if ($settings === null) {
        $conn = getConnection();
        $settings = [];
        $result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }
        $conn->close();
    }
    
    if ($settingsCache === null) {
        $settingsCache = $settings;
    }
    
    return isset($settings[$key]) ? $settings[$key] : $default;
}

/**
 * Clear settings cache
 */
function clearSettingsCache() {
    global $settingsCache;
    $settingsCache = null;
    return true;
}

/**
 * Get all system settings at once
 */
function getAllSettings() {
    static $allSettings = null;
    
    if ($allSettings === null) {
        $conn = getConnection();
        $allSettings = [];
        $result = $conn->query("SELECT setting_key, setting_value, description FROM system_settings");
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $allSettings[$row['setting_key']] = [
                    'value' => $row['setting_value'],
                    'description' => $row['description']
                ];
            }
        }
        $conn->close();
    }
    
    return $allSettings;
}

/**
 * Update multiple settings at once
 */
function updateSettings($settings) {
    $conn = getConnection();
    $success = true;
    
    foreach ($settings as $key => $value) {
        $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) 
                                VALUES (?, ?) 
                                ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->bind_param("sss", $key, $value, $value);
        if (!$stmt->execute()) {
            $success = false;
        }
        $stmt->close();
    }
    
    $conn->close();
    clearSettingsCache();
    return $success;
}

/**
 * Get currency symbol
 */
function getCurrencySymbol() {
    $symbol = getSetting('currency_symbol', 'JD');
    return $symbol;
}

/**
 * Format price with currency
 */
function formatPrice($amount) {
    $symbol = getCurrencySymbol();
    $decimal = getSetting('decimal_places', 2);
    return $symbol . ' ' . number_format($amount, $decimal);
}

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Generate random string for the suffix (5 characters)
 */
function generateRandomString($length = 5) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[random_int(0, $charactersLength - 1)];
    }
    return $randomString;
}

/**
 * Get next sequential number for reservation (ensures never 0)
 */
function getNextSequentialNumber() {
    $conn = getConnection();
    
    // Get the maximum sequential number, handling NULLs
    $result = $conn->query("SELECT COALESCE(MAX(sequential_number), 0) as max_seq FROM reservations");
    $row = $result->fetch_assoc();
    $max_seq = intval($row['max_seq']);
    $conn->close();
    
    // If no records or max is 0, start from 1
    if ($max_seq <= 0) {
        return 1;
    }
    
    return $max_seq + 1;
}

/**
 * Generate Reservation ID with pattern: RES0001-15G12A3T0K-AT54G
 */
function generateReservationId($adults, $teens, $kids) {
    $prefix = 'RES';
    $sequential = getNextSequentialNumber();
    $sequentialFormatted = str_pad($sequential, 4, '0', STR_PAD_LEFT);
    $totalGuests = $adults + $teens + $kids;
    $breakdown = $totalGuests . 'G' . $adults . 'A' . $teens . 'T' . $kids . 'K';
    $randomSuffix = generateRandomString(5);
    
    return $prefix . $sequentialFormatted . '-' . $breakdown . '-' . $randomSuffix;
}

/**
 * Generate Ticket ID for each attendee
 */
function generateTicketId($reservationId, $attendeeType, $attendeeNumber) {
    $typeCode = '';
    switch ($attendeeType) {
        case 'adult':
            $typeCode = 'A';
            break;
        case 'teen':
            $typeCode = 'T';
            break;
        case 'kid':
            $typeCode = 'K';
            break;
    }
    
    $numberFormatted = str_pad($attendeeNumber, 3, '0', STR_PAD_LEFT);
    $ticketId = $reservationId . '-' . $typeCode . $numberFormatted;
    
    return $ticketId;
}

/**
 * Regenerate reservation ID keeping the same sequential number and random suffix
 */
function regenerateReservationIdFromOld($old_id, $new_adults, $new_teens, $new_kids) {
    // Extract sequential number and random suffix from old ID
    if (preg_match('/^RES(\d{4})-(\d+G\d+A\d+T\d+K)-([A-Z0-9]{5})$/', $old_id, $matches)) {
        $sequential = $matches[1];
        $randomSuffix = $matches[3];
    } else {
        // If pattern doesn't match, generate fresh
        return generateReservationId($new_adults, $new_teens, $new_kids);
    }
    
    $prefix = 'RES';
    $totalGuests = $new_adults + $new_teens + $new_kids;
    $breakdown = $totalGuests . 'G' . $new_adults . 'A' . $new_teens . 'T' . $new_kids . 'K';
    
    return $prefix . $sequential . '-' . $breakdown . '-' . $randomSuffix;
}

/**
 * Decode Reservation ID to get original data
 */
function decodeReservationId($reservationId) {
    $pattern = '/^RES(\d{4})-(\d+G\d+A\d+T\d+K)-([A-Z0-9]{5})$/';
    
    if (preg_match($pattern, $reservationId, $matches)) {
        // Parse the breakdown part
        $breakdown = $matches[2];
        preg_match('/(\d+)G(\d+)A(\d+)T(\d+)K/', $breakdown, $breakdown_matches);
        
        return [
            'sequential' => intval($matches[1]),
            'total_guests' => intval($breakdown_matches[1] ?? 0),
            'adults' => intval($breakdown_matches[2] ?? 0),
            'teens' => intval($breakdown_matches[3] ?? 0),
            'kids' => intval($breakdown_matches[4] ?? 0),
            'random_suffix' => $matches[3]
        ];
    }
    
    return null;
}

/**
 * Calculate ticket prices based on current settings
 */
function calculateTicketPrice($type, $quantity = 1, $isEarlyBird = false, $groupSize = 0) {
    $price = 0;
    
    switch ($type) {
        case 'adult':
            $price = getSetting('ticket_price_adult', 10);
            break;
        case 'teen':
            $price = getSetting('ticket_price_teen', 10);
            break;
        case 'kid':
            $price = getSetting('ticket_price_kid', 0);
            break;
        default:
            $price = 0;
    }
    
    $total = $price * $quantity;
    
    if ($isEarlyBird) {
        $earlyBirdDiscount = getSetting('early_bird_discount', 0);
        if ($earlyBirdDiscount > 0) {
            $total = $total * (1 - $earlyBirdDiscount / 100);
        }
    }
    
    $minGroupSize = getSetting('min_group_size', 10);
    if ($groupSize >= $minGroupSize) {
        $groupDiscount = getSetting('group_discount', 0);
        if ($groupDiscount > 0) {
            $total = $total * (1 - $groupDiscount / 100);
        }
    }
    
    return max(0, $total);
}

/**
 * Get total paid amount for a reservation
 */
function getTotalPaid($reservation_id) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM split_payments WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    return floatval($result['total_paid']);
}

/**
 * Get remaining amount due for a reservation
 */
function getRemainingDue($reservation_id) {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT total_amount FROM reservations WHERE reservation_id = ?");
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    $totalPaid = getTotalPaid($reservation_id);
    $conn->close();
    
    return max(0, floatval($result['total_amount']) - $totalPaid);
}

/**
 * Get current active event
 */
function getCurrentEvent() {
    $conn = getConnection();
    $result = $conn->query("SELECT * FROM event_settings WHERE status = 'upcoming' ORDER BY event_date ASC LIMIT 1");
    $event = $result->fetch_assoc();
    $conn->close();
    return $event;
}

/**
 * Get all upcoming events
 */
function getUpcomingEvents($limit = null) {
    $conn = getConnection();
    $query = "SELECT * FROM event_settings WHERE event_date >= CURDATE() AND status = 'upcoming' ORDER BY event_date ASC";
    if ($limit) {
        $query .= " LIMIT " . intval($limit);
    }
    $result = $conn->query($query);
    $events = $result->fetch_all(MYSQLI_ASSOC);
    $conn->close();
    return $events;
}

/**
 * Send email notification (if enabled)
 */
function sendEmail($to, $subject, $body) {
    $enabled = getSetting('enable_email', '1') == '1';
    if (!$enabled) return false;
    
    $smtpHost = getSetting('smtp_host', '');
    $smtpPort = getSetting('smtp_port', '587');
    $smtpUser = getSetting('smtp_user', '');
    $smtpPass = getSetting('smtp_pass', '');
    
    if (empty($smtpHost) || empty($smtpUser)) return false;
    
    return true;
}

/**
 * Check if maintenance mode is enabled
 */
function isMaintenanceMode() {
    $maintenance = getSetting('maintenance_mode', '0');
    return $maintenance == '1';
}

/**
 * Get theme color
 */
function getThemeColor() {
    return getSetting('theme_color', '#4f46e5');
}

/**
 * Check if dark mode is enabled
 */
function isDarkModeEnabled() {
    return getSetting('dark_mode_enabled', '1') == '1';
}

/**
 * Get cancellation policy text
 */
function getCancellationPolicy() {
    return getSetting('cancellation_policy', 'Tickets are non-refundable 24 hours before event');
}

/**
 * Get terms and conditions
 */
function getTermsConditions() {
    return getSetting('terms_conditions', 'Please read our terms and conditions carefully.');
}

/**
 * Check daily reservation limit
 */
function checkDailyLimit() {
    $maxPerDay = getSetting('max_reservations_per_day', 1000);
    $conn = getConnection();
    $today = date('Y-m-d');
    $result = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE DATE(created_at) = '$today'");
    $count = $result->fetch_assoc()['count'];
    $conn->close();
    return $count < $maxPerDay;
}

/**
 * Get ticket type labels
 */
function getTicketLabels() {
    return [
        'adult' => getSetting('label_adult', 'Adults'),
        'teen' => getSetting('label_teen', 'Teens'),
        'kid' => getSetting('label_kid', 'Kids')
    ];
}

/**
 * Get footer text
 */
function getFooterText() {
    return getSetting('footer_text', '© 2024 Ticketing System. All rights reserved.');
}

/**
 * Log user activity
 */
function logActivity($userId, $action, $details = null) {
    $conn = getConnection();
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $conn->prepare("INSERT INTO audit_log (user_id, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param("issss", $userId, $action, $details, $ip, $userAgent);
    $result = $stmt->execute();
    $stmt->close();
    $conn->close();
    return $result;
}

/**
 * Calculate days remaining until event
 */
function getDaysRemaining($event_date) {
    $today = new DateTime();
    $event = new DateTime($event_date);
    $interval = $today->diff($event);
    
    $days = $interval->days;
    
    if ($today > $event) {
        return -$days;
    }
    
    return $days;
}

/**
 * Get days remaining text with appropriate styling
 */
function getDaysRemainingText($event_date) {
    $days = getDaysRemaining($event_date);
    
    if ($days < 0) {
        $abs_days = abs($days);
        if ($abs_days == 1) {
            return "Event was yesterday";
        } else {
            return "Event passed " . $abs_days . " days ago";
        }
    } elseif ($days == 0) {
        return "🎉 TODAY IS THE EVENT DAY! 🎉";
    } elseif ($days == 1) {
        return "🔥 TOMORROW! 1 day remaining 🔥";
    } elseif ($days <= 7) {
        return "⚠️ Only " . $days . " days remaining! ⚠️";
    } elseif ($days <= 30) {
        return "📅 " . $days . " days remaining";
    } else {
        return "🗓️ " . $days . " days until the event";
    }
}

/**
 * Send WhatsApp message using Ultramsg
 */
/**
 * Send WhatsApp message using Ultramsg
 */
function sendWhatsAppMessage($to, $message) {
    // Check if enabled
    $enabled = getSetting('enable_whatsapp', '0') == '1';
    if (!$enabled) return false;
    
    // Get credentials
    $instanceId = getSetting('ultramsg_instance_id', '');
    $token = getSetting('ultramsg_token', '');
    
    if (empty($instanceId) || empty($token)) {
        error_log("Ultramsg: Missing credentials");
        return false;
    }
    
    // Clean phone number - remove ALL non-digits
    $to = preg_replace('/[^0-9]/', '', $to);
    
    // Remove leading zeros
    $to = ltrim($to, '0');
    
    // Remove country code if already there (962)
    if (substr($to, 0, 3) == '962') {
        $to = substr($to, 3);
    }
    
    // Now add the country code correctly
    $to = '962' . $to;
    
    // Final validation - number should be 12 digits (962 + 9 digits)
    if (strlen($to) != 12) {
        error_log("Ultramsg: Invalid phone number length: " . strlen($to) . " - Number: " . $to);
        return false;
    }
    
    // Prepare data
    $data = [
        'token' => $token,
        'to' => $to,
        'body' => $message,
        'priority' => 1
    ];
    
    // Send request
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.ultramsg.com/{$instanceId}/messages/chat");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Log result
    $responseData = json_decode($response, true);
    if ($httpCode == 200 && isset($responseData['sent']) && $responseData['sent']) {
        error_log("WhatsApp sent successfully to: {$to}");
        return true;
    } else {
        error_log("WhatsApp failed: " . $response);
        return false;
    }
}

/**
 * Send WhatsApp image (for tickets, receipts)
 */
function sendWhatsAppImage($to, $imageUrl, $caption = '') {
    $enabled = getSetting('enable_whatsapp', '0') == '1';
    if (!$enabled) return false;
    
    $instanceId = getSetting('ultramsg_instance_id', '');
    $token = getSetting('ultramsg_token', '');
    
    if (empty($instanceId) || empty($token)) return false;
    
    $to = preg_replace('/[^0-9]/', '', $to);
    if (substr($to, 0, 2) != '962') {
        $to = '962' . $to;
    }
    if (substr($to, 0, 1) == '0') {
        $to = '962' . substr($to, 1);
    }
    
    $data = [
        'token' => $token,
        'to' => $to,
        'image' => $imageUrl,
        'caption' => $caption
    ];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.ultramsg.com/{$instanceId}/messages/image");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return true;
}
?>