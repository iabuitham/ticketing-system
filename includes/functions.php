<?php
require_once 'db.php';

function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    return $protocol . $host . '/';
}

function formatPhoneNumber($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    if (substr($phone, 0, 1) == '0') $phone = substr($phone, 1);
    if (substr($phone, 0, 3) != '962') $phone = '962' . $phone;
    return '+' . $phone;
}


// Generate random suffix (6 characters: letters and numbers)
function generateRandomSuffix() {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $suffix = '';
    for ($i = 0; $i < 6; $i++) {
        $suffix .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $suffix;
}

// Generate new Reservation ID pattern: RES{4-digit sequential}-{total}G{adults}A{teens}T{kids}K-{6-char suffix}
function generateReservationId($adults, $teens, $kids) {
    $conn = getConnection();
    $result = $conn->query("SELECT COUNT(*) as count FROM reservations");
    $nextNumber = $result->fetch_assoc()['count'] + 1;
    $sequential = str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
    $conn->close();
    
    $totalGuests = $adults + $teens + $kids;
    $suffix = generateRandomSuffix();
    
    return 'RES' . $sequential . '-' . $totalGuests . 'G' . $adults . 'A' . $teens . 'T' . $kids . 'K-' . $suffix;
}

// Generate Ticket ID based on reservation ID
function generateTicketId($reservationId, $type, $number) {
    $typeCode = '';
    switch($type) {
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
    return $reservationId . '-' . $typeCode . str_pad($number, 4, '0', STR_PAD_LEFT);
}

// Decode reservation ID to extract information
function decodeReservationId($reservationId) {
    preg_match('/RES(\d{4})-(\d+)G(\d+)A(\d+)T(\d+)K-([A-Z0-9]{6})/', $reservationId, $matches);
    
    if (count($matches) >= 7) {
        return [
            'sequential' => $matches[1],
            'total_guests' => intval($matches[2]),
            'adults' => intval($matches[3]),
            'teens' => intval($matches[4]),
            'kids' => intval($matches[5]),
            'suffix' => $matches[6],
            'full_id' => $reservationId
        ];
    }
    return null;
}

function generateTicketCodes($reservationId, $adults, $teens, $kids) {
    $tickets = [];
    $counter = 1;
    
    for ($i = 1; $i <= $adults; $i++) {
        $tickets[] = [
            'code' => generateTicketId($reservationId, 'adult', $i),
            'type' => 'adult',
            'num' => $counter++
        ];
    }
    for ($i = 1; $i <= $teens; $i++) {
        $tickets[] = [
            'code' => generateTicketId($reservationId, 'teen', $i),
            'type' => 'teen',
            'num' => $counter++
        ];
    }
    for ($i = 1; $i <= $kids; $i++) {
        $tickets[] = [
            'code' => generateTicketId($reservationId, 'kid', $i),
            'type' => 'kid',
            'num' => $counter++
        ];
    }
    return $tickets;
}

function sendWhatsAppMessage($phone, $message) {
    $whatsappToken = 'YOUR_WHATSAPP_TOKEN';
    $phoneNumberId = 'YOUR_PHONE_NUMBER_ID';
    
    if ($whatsappToken != 'YOUR_WHATSAPP_TOKEN') {
        $ch = curl_init("https://graph.facebook.com/v18.0/{$phoneNumberId}/messages");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'messaging_product' => 'whatsapp',
            'to' => $phone,
            'type' => 'text',
            'text' => ['body' => $message]
        ]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $whatsappToken,
            'Content-Type: application/json'
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }
    return ['success' => false, 'simulated' => true];
}

/**
 * Get system setting value by key
 */
function getSetting($key, $default = null) {
    global $conn;
    
    // Try to get from cache first
    static $settings = null;
    
    if ($settings === null) {
        $settings = [];
        $result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    
    return isset($settings[$key]) ? $settings[$key] : $default;
}

/**
 * Clear settings cache
 */
function clearSettingsCache() {
    // This function can be implemented if using file-based caching
    // For now, it's just a placeholder
    return true;
}

/**
 * Get all system settings
 */
function getAllSettings() {
    global $conn;
    $settings = [];
    $result = $conn->query("SELECT setting_key, setting_value, description FROM system_settings");
    while ($row = $result->fetch_assoc()) {
        $settings[$row['setting_key']] = [
            'value' => $row['setting_value'],
            'description' => $row['description']
        ];
    }
    return $settings;
}

/**
 * Update multiple settings at once
 */
function updateSettings($settings) {
    global $conn;
    
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
    
    clearSettingsCache();
    return $success;
}

/**
 * Get current event
 */
function getCurrentEvent() {
    global $conn;
    $result = $conn->query("SELECT * FROM event_settings WHERE status = 'upcoming' ORDER BY event_date ASC LIMIT 1");
    return $result->fetch_assoc();
}

/**
 * Get all upcoming events
 */
function getUpcomingEvents($limit = null) {
    global $conn;
    $query = "SELECT * FROM event_settings WHERE event_date >= CURDATE() AND status = 'upcoming' ORDER BY event_date ASC";
    if ($limit) {
        $query .= " LIMIT " . intval($limit);
    }
    return $conn->query($query)->fetch_all(MYSQLI_ASSOC);
}
?>