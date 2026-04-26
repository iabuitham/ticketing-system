<?php
/**
 * Ticketing System - Core Functions
 * All settings are now dynamic and fetched from database
 */

// Include db.php for database connection
require_once __DIR__ . '/db.php';

/**
 * Get system setting value by key
 * This function now caches settings for better performance
 */
function getSetting($key, $default = null) {
    static $settings = null;
    static $settingsCache = null;
    
    // Special handling for reservation_prefix - always return 'RES' for the new pattern
    if ($key == 'reservation_prefix') {
        return 'RES';
    }
    
    // If we already loaded settings in this request, use cache
    if ($settingsCache !== null && isset($settingsCache[$key])) {
        return $settingsCache[$key];
    }
    
    // Load all settings if not loaded yet
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
    
    // Cache for this request
    if ($settingsCache === null) {
        $settingsCache = $settings;
    }
    
    return isset($settings[$key]) ? $settings[$key] : $default;
}

/**
 * Clear settings cache (call after updating settings)
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
    
    // Apply early bird discount if applicable
    if ($isEarlyBird) {
        $earlyBirdDiscount = getSetting('early_bird_discount', 0);
        if ($earlyBirdDiscount > 0) {
            $total = $total * (1 - $earlyBirdDiscount / 100);
        }
    }
    
    // Apply group discount if applicable
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
 * Send WhatsApp notification (if enabled)
 */
function sendWhatsAppMessage($to, $message) {
    $enabled = getSetting('enable_whatsapp', '1') == '1';
    if (!$enabled) return false;
    
    $apiKey = getSetting('whatsapp_api_key', '');
    $businessNumber = getSetting('whatsapp_number', '');
    
    if (empty($apiKey) || empty($businessNumber)) return false;
    
    // Implement your WhatsApp API call here
    // This is a placeholder - integrate with your WhatsApp provider
    return true;
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
    
    // Implement your email sending logic here
    // This is a placeholder - integrate with PHPMailer or similar
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
 * Get reservation by ID with payment info
 */
function getReservationWithPayments($reservation_id) {
    $conn = getConnection();
    
    $query = "SELECT r.*, 
              COALESCE((SELECT SUM(amount) FROM split_payments WHERE reservation_id = r.reservation_id), 0) as total_paid
              FROM reservations r 
              WHERE r.reservation_id = ?";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $reservation_id);
    $stmt->execute();
    $reservation = $stmt->get_result()->fetch_assoc();
    
    if ($reservation) {
        // Get payment splits
        $stmt2 = $conn->prepare("SELECT * FROM split_payments WHERE reservation_id = ? ORDER BY payment_date DESC");
        $stmt2->bind_param("s", $reservation_id);
        $stmt2->execute();
        $reservation['payments'] = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt2->close();
    }
    
    $stmt->close();
    $conn->close();
    
    return $reservation;
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
 * Get next sequential number for reservation (4 digits with leading zeros)
 */
function getNextSequentialNumber() {
    $conn = getConnection();
    $result = $conn->query("SELECT COALESCE(MAX(sequential_number), 0) + 1 as next_num FROM reservations");
    $next = $result->fetch_assoc()['next_num'];
    $conn->close();
    return $next;
}

/**
 * Generate Reservation ID with pattern: RES0001-15G12A3T0K-A2DD3
 * Where:
 * - RES is the fixed prefix (not from settings)
 * - 0001 is sequential number (4 digits with leading zeros)
 * - 15G is total guest count
 * - 12A is adults, 3T is teens, 0K is kids
 * - A2DD3 is random 5-character string
 */
function generateReservationId($adults, $teens, $kids) {
    // Fixed prefix - always RES (ignore settings)
    $prefix = 'RES';
    
    // Get next sequential number (4 digits with leading zeros)
    $sequential = getNextSequentialNumber();
    $sequentialFormatted = str_pad($sequential, 4, '0', STR_PAD_LEFT);
    
    // Calculate total guests
    $totalGuests = $adults + $teens + $kids;
    
    // Create breakdown part: {total}G{adults}A{teens}T{kids}K
    $breakdown = $totalGuests . 'G' . $adults . 'A' . $teens . 'T' . $kids . 'K';
    
    // Generate random suffix (5 characters)
    $randomSuffix = generateRandomString(5);
    
    // Combine all parts: RES + sequential + breakdown + random
    $reservationId = $prefix . $sequentialFormatted . '-' . $breakdown . '-' . $randomSuffix;
    
    return $reservationId;
}

/**
 * Generate Ticket ID for each attendee
 * Pattern: RES0001-15G12A3T0K-A2DD3-A001
 * Where:
 * - First part: Full reservation ID
 * - A001: Type code (A=Adult, T=Teen, K=Kid) + 3-digit number
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
    
    // Format number as 3 digits (001, 002, etc.)
    $numberFormatted = str_pad($attendeeNumber, 3, '0', STR_PAD_LEFT);
    
    // Combine: reservationId + typeCode + number
    $ticketId = $reservationId . '-' . $typeCode . $numberFormatted;
    
    return $ticketId;
}

/**
 * Decode Reservation ID to get original data
 */
function decodeReservationId($reservationId) {
    $pattern = '/^RES(\d{4})-(\d+)G(\d+)A(\d+)T(\d+)K-([A-Z0-9]{5})$/';
    
    if (preg_match($pattern, $reservationId, $matches)) {
        return [
            'sequential' => intval($matches[1]),
            'total_guests' => intval($matches[2]),
            'adults' => intval($matches[3]),
            'teens' => intval($matches[4]),
            'kids' => intval($matches[5]),
            'random_suffix' => $matches[6]
        ];
    }
    
    return null;
}

/**
 * Decode Ticket ID
 */
function decodeTicketId($ticketId) {
    // Ticket ID pattern: RES0001-15G12A3T0K-A2DD3-A001
    $pattern = '/^(RES\d{4}-\d+G\d+A\d+T\d+K-[A-Z0-9]{5})-([ATK])(\d{3})$/';
    
    if (preg_match($pattern, $ticketId, $matches)) {
        $typeMap = [
            'A' => 'adult',
            'T' => 'teen',
            'K' => 'kid'
        ];
        
        return [
            'reservation_id' => $matches[1],
            'attendee_type' => $typeMap[$matches[2]],
            'attendee_number' => intval($matches[3])
        ];
    }
    
    return null;
}

/**
 * Override the old generateReservationId function to use the new pattern
 * This replaces any old function that might be using the settings prefix
 */
function generateReservationIdOld($adults, $teens, $kids) {
    // This is the old function - now calling the new one
    return generateReservationId($adults, $teens, $kids);
}
?>