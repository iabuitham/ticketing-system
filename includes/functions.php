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
 * Get base URL for the system
 */
function getBaseUrl() {
    $url = getSetting('base_url', '');
    if (!empty($url)) {
        return rtrim($url, '/') . '/';
    }
    
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $scriptName = dirname($_SERVER['SCRIPT_NAME']);
    $basePath = str_replace('/admin', '', $scriptName);
    $basePath = str_replace('/public', '', $basePath);
    $basePath = rtrim($basePath, '/');
    
    return $protocol . '://' . $host . $basePath . '/';
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
 * Get next sequential number for reservation
 */
function getNextSequentialNumber() {
    $conn = getConnection();
    $result = $conn->query("SELECT COALESCE(MAX(sequential_number), 0) + 1 as next_num FROM reservations");
    $next = $result->fetch_assoc()['next_num'];
    $conn->close();
    if ($next <= 0) $next = 1;
    return $next;
}

/**
 * Generate Reservation ID
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
 * Generate Ticket ID
 */
function generateTicketId($reservationId, $attendeeType, $attendeeNumber) {
    $typeCode = '';
    switch ($attendeeType) {
        case 'adult': $typeCode = 'A'; break;
        case 'teen': $typeCode = 'T'; break;
        case 'kid': $typeCode = 'K'; break;
    }
    $numberFormatted = str_pad($attendeeNumber, 3, '0', STR_PAD_LEFT);
    return $reservationId . '-' . $typeCode . $numberFormatted;
}

/**
 * Regenerate reservation ID keeping same sequential number and random suffix
 */
function regenerateReservationIdFromOld($old_id, $new_adults, $new_teens, $new_kids) {
    if (preg_match('/^RES(\d{4})-(\d+G\d+A\d+T\d+K)-([A-Z0-9]{5})$/', $old_id, $matches)) {
        $sequential = $matches[1];
        $randomSuffix = $matches[3];
    } else {
        return generateReservationId($new_adults, $new_teens, $new_kids);
    }
    $totalGuests = $new_adults + $new_teens + $new_kids;
    $breakdown = $totalGuests . 'G' . $new_adults . 'A' . $new_teens . 'T' . $new_kids . 'K';
    return 'RES' . $sequential . '-' . $breakdown . '-' . $randomSuffix;
}

/**
 * Send WhatsApp text message
 */
function sendWhatsAppMessage($to, $message) {
    $enabled = getSetting('enable_whatsapp', '0') == '1';
    if (!$enabled) return false;
    
    $instanceId = getSetting('ultramsg_instance_id', '');
    $token = getSetting('ultramsg_token', '');
    
    if (empty($instanceId) || empty($token)) return false;
    
    $to = preg_replace('/[^0-9]/', '', $to);
    $to = ltrim($to, '0');
    if (substr($to, 0, 3) == '962') $to = substr($to, 3);
    $to = '962' . $to;
    
    $data = ['token' => $token, 'to' => $to, 'body' => $message, 'priority' => 1];
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.ultramsg.com/{$instanceId}/messages/chat");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $responseData = json_decode($response, true);
    return ($httpCode == 200 && isset($responseData['sent']) && $responseData['sent']);
}
/**
 * Send WhatsApp image using Ultramsg
 */
function sendWhatsAppImage($to, $imageUrl, $caption = '') {
    error_log("sendWhatsAppImage called - To: $to, URL: $imageUrl");
    
    $enabled = getSetting('enable_whatsapp', '0') == '1';
    if (!$enabled) {
        error_log("WhatsApp is disabled");
        return false;
    }
    
    $instanceId = getSetting('ultramsg_instance_id', '');
    $token = getSetting('ultramsg_token', '');
    
    if (empty($instanceId) || empty($token)) {
        error_log("Missing Ultramsg credentials");
        return false;
    }
    
    // Format phone number correctly
    $to = preg_replace('/[^0-9]/', '', $to);
    if (substr($to, 0, 1) == '0') $to = substr($to, 1);
    if (substr($to, 0, 3) != '962') $to = '962' . $to;
    
    // Prepare the request
    $data = [
        'token' => $token,
        'to' => $to,
        'image' => $imageUrl,
        'caption' => $caption
    ];
    
    $url = "https://api.ultramsg.com/{$instanceId}/messages/image";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    error_log("Ultramsg Response - HTTP: $httpCode");
    error_log("Response body: $response");
    
    // Ultramsg returns HTTP 200 on success, even if response says 'sent' => true
    // Some versions return 'sent' => 1 instead of true
    if ($httpCode == 200) {
        $responseData = json_decode($response, true);
        // Check for both 'sent' => true and 'sent' => 1
        if (isset($responseData['sent']) && ($responseData['sent'] === true || $responseData['sent'] === 1 || $responseData['sent'] === '1')) {
            error_log("Image sent successfully to: $to");
            return true;
        }
        // Also accept if there's no error and message was sent
        if (!isset($responseData['error'])) {
            error_log("Image likely sent successfully to: $to");
            return true;
        }
    }
    
    error_log("Failed to send image to: $to - Response: $response");
    return false;
}

/**
 * Send all tickets for a reservation as QR code images
 * Uses URL method (quickchart.io) which works reliably
 */
function sendAllTicketsAsImages($reservation_id, $customerPhone, $customerName) {
    $conn = getConnection();
    
    // Get all tickets
    $ticketsStmt = $conn->prepare("SELECT * FROM ticket_codes WHERE reservation_id = ? AND is_active = 1 ORDER BY guest_type, guest_number");
    $ticketsStmt->bind_param("s", $reservation_id);
    $ticketsStmt->execute();
    $tickets = $ticketsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $ticketsStmt->close();
    
    // Get event details
    $eventStmt = $conn->prepare("SELECT event_name, event_date, event_time, venue FROM event_settings WHERE id = ?");
    $eventStmt->bind_param("i", $_SESSION['selected_event_id'] ?? 0);
    $eventStmt->execute();
    $event = $eventStmt->get_result()->fetch_assoc();
    $eventStmt->close();
    $conn->close();
    
    if (empty($tickets)) {
        return false;
    }
    
    $eventDetails = [
        'name' => $event['event_name'] ?? getSetting('site_name', 'Event'),
        'date' => $event['event_date'] ?? '',
        'time' => $event['event_time'] ?? '',
        'venue' => $event['venue'] ?? ''
    ];
    
    // Send header message
    $headerMessage = "🎟️ *YOUR TICKETS ARE READY!* 🎟️\n\n";
    $headerMessage .= "Dear {$customerName},\n\n";
    $headerMessage .= "Thank you for your payment! Here are your tickets.\n\n";
    $headerMessage .= "📋 *Reservation ID:* {$reservation_id}\n";
    $headerMessage .= "🎪 *Event:* {$eventDetails['name']}\n";
    $headerMessage .= "📱 *Total Tickets:* " . count($tickets) . "\n\n";
    $headerMessage .= "*Your tickets are attached below as images.*\n";
    $headerMessage .= "Save each image to your phone.\n";
    $headerMessage .= "Show them at the entrance.\n\n";
    $headerMessage .= "We look forward to seeing you! 🎉";
    
    sendWhatsAppMessage($customerPhone, $headerMessage);
    
    // Send each ticket as QR code image using URL method
    $sentCount = 0;
    foreach ($tickets as $ticket) {
        $typeLabel = ucfirst($ticket['guest_type']);
        $ticketNumber = str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT);
        
        $caption = "🎫 *{$typeLabel} Ticket #{$ticketNumber}*\n";
        $caption .= "ID: {$ticket['ticket_code']}\n";
        $caption .= "Customer: {$customerName}\n";
        $caption .= "Valid for one-time entry\n\n";
        $caption .= "Show this QR code at the entrance";
        
        // Generate QR code URL using quickchart.io (works reliably)
        $qrUrl = "https://quickchart.io/qr?text=" . urlencode($ticket['ticket_code']) . "&size=250&margin=2";
        
        // Send the image using URL method
        $result = sendWhatsAppImage($customerPhone, $qrUrl, $caption);
        
        if ($result) {
            $sentCount++;
        } else {
            error_log("Failed to send ticket: {$ticket['ticket_code']}");
        }
        
        usleep(500000); // 0.5 second delay between messages
    }
    
    // Send closing message
    if ($sentCount > 0) {
        $closingMessage = "✅ *All {$sentCount} ticket(s) sent!*\n\n";
        $closingMessage .= "📸 Each ticket has been sent as a QR code image.\n";
        $closingMessage .= "💾 Press and hold on each image to save to your phone.\n";
        $closingMessage .= "📱 Show the saved images at the entrance for scanning.\n\n";
        $closingMessage .= "Thank you for choosing us! 🎉";
        
        sendWhatsAppMessage($customerPhone, $closingMessage);
    }
    
    return $sentCount;
}

/**
 * Decode Reservation ID
 */
function decodeReservationId($reservationId) {
    $pattern = '/^RES(\d{4})-(\d+G\d+A\d+T\d+K)-([A-Z0-9]{5})$/';
    if (preg_match($pattern, $reservationId, $matches)) {
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
 * Update table availability
 */
function updateTableAvailability() {
    $conn = getConnection();
    $conn->query("UPDATE `tables` SET `is_used` = 0");
    $result = $conn->query("SELECT DISTINCT table_id FROM reservations WHERE status NOT IN ('cancelled', 'paid')");
    while ($row = $result->fetch_assoc()) {
        $tableId = $row['table_id'];
        if (!empty($tableId)) {
            $conn->query("UPDATE `tables` SET `is_used` = 1 WHERE table_number = '$tableId'");
        }
    }
    $conn->close();
}

/**
 * Get current event
 */
function getCurrentEvent() {
    $conn = getConnection();
    $result = $conn->query("SELECT * FROM event_settings WHERE status = 'upcoming' ORDER BY event_date ASC LIMIT 1");
    $event = $result->fetch_assoc();
    $conn->close();
    return $event;
}

/**
 * Get days remaining until event
 */
function getDaysRemaining($event_date) {
    $today = new DateTime();
    $event = new DateTime($event_date);
    $interval = $today->diff($event);
    $days = $interval->days;
    return $today > $event ? -$days : $days;
}

/**
 * Get days remaining text
 */
function getDaysRemainingText($event_date) {
    $days = getDaysRemaining($event_date);
    if ($days < 0) {
        $abs_days = abs($days);
        return $abs_days == 1 ? "Event was yesterday" : "Event passed " . $abs_days . " days ago";
    } elseif ($days == 0) return "🎉 TODAY IS THE EVENT DAY! 🎉";
    elseif ($days == 1) return "🔥 TOMORROW! 1 day remaining 🔥";
    elseif ($days <= 7) return "⚠️ Only " . $days . " days remaining! ⚠️";
    elseif ($days <= 30) return "📅 " . $days . " days remaining";
    else return "🗓️ " . $days . " days until the event";
}
?>