<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';
require_once '../includes/language.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();
$message = '';
$messageType = '';

// Get selected event info
$selected_event_id = $_SESSION['selected_event_id'] ?? 0;
$selected_event_name = $_SESSION['selected_event_name'] ?? 'No Event Selected';

// Get event-specific ticket prices
$event_ticket_prices = $_SESSION['event_ticket_prices'] ?? null;

if (!$event_ticket_prices && $selected_event_id > 0) {
    $stmt = $conn->prepare("SELECT ticket_price_adult, ticket_price_teen, ticket_price_kid, event_name FROM event_settings WHERE id = ?");
    $stmt->bind_param("i", $selected_event_id);
    $stmt->execute();
    $event_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($event_data) {
        $event_ticket_prices = [
            'adult' => $event_data['ticket_price_adult'],
            'teen' => $event_data['ticket_price_teen'],
            'kid' => $event_data['ticket_price_kid']
        ];
        $_SESSION['event_ticket_prices'] = $event_ticket_prices;
        $_SESSION['selected_event_name'] = $event_data['event_name'];
    }
}

// Use event-specific prices or fall back to system settings
$adultPrice = $event_ticket_prices['adult'] ?? getSetting('ticket_price_adult', 10);
$teenPrice = $event_ticket_prices['teen'] ?? getSetting('ticket_price_teen', 10);
$kidPrice = $event_ticket_prices['kid'] ?? getSetting('ticket_price_kid', 0);

$currencySymbol = getCurrencySymbol();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitizeInput($_POST['name']);
    $phone = sanitizeInput($_POST['phone']);
    $table_id = sanitizeInput($_POST['table_id']);
    $adults = intval($_POST['adults']);
    $teens = intval($_POST['teens']);
    $kids = intval($_POST['kids']);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    // Calculate total amount
    $total_amount = ($adults * $adultPrice) + ($teens * $teenPrice) + ($kids * $kidPrice);
    
    // Get the NEXT sequential number correctly
    $seq_result = $conn->query("SELECT MAX(sequential_number) as max_seq FROM reservations");
    $max_seq_row = $seq_result->fetch_assoc();
    $current_max = intval($max_seq_row['max_seq']);
    $next_seq = $current_max + 1;
    if ($next_seq <= 0) $next_seq = 1;
    
    // Generate new Reservation ID using the sequential number
    $reservation_id = generateReservationIdWithSeq($adults, $teens, $kids, $next_seq);
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Insert reservation with explicit sequential_number
        $stmt = $conn->prepare("INSERT INTO reservations (reservation_id, sequential_number, name, phone, table_id, adults, teens, kids, total_amount, additional_amount_due, notes, status, created_at) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())");
        $additional_amount_due = $total_amount;
        $stmt->bind_param("sisssiiidds", $reservation_id, $next_seq, $name, $phone, $table_id, $adults, $teens, $kids, $total_amount, $additional_amount_due, $notes);
        
        if (!$stmt->execute()) {
            throw new Exception($conn->error);
        }
        $stmt->close();
        
        // Generate ticket codes for each attendee
        $adultCount = 0;
        $teenCount = 0;
        $kidCount = 0;
        
        // Check if ticket_codes table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'ticket_codes'");
        if ($table_check && $table_check->num_rows > 0) {
            // Generate tickets for adults
            for ($i = 1; $i <= $adults; $i++) {
                $ticketCode = generateTicketId($reservation_id, 'adult', $i);
                $stmt_ticket = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'adult', ?)");
                $stmt_ticket->bind_param("ssi", $reservation_id, $ticketCode, $i);
                $stmt_ticket->execute();
                $stmt_ticket->close();
                $adultCount++;
            }
            
            // Generate tickets for teens
            for ($i = 1; $i <= $teens; $i++) {
                $ticketCode = generateTicketId($reservation_id, 'teen', $i);
                $stmt_ticket = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'teen', ?)");
                $stmt_ticket->bind_param("ssi", $reservation_id, $ticketCode, $i);
                $stmt_ticket->execute();
                $stmt_ticket->close();
                $teenCount++;
            }
            
            // Generate tickets for kids
            for ($i = 1; $i <= $kids; $i++) {
                $ticketCode = generateTicketId($reservation_id, 'kid', $i);
                $stmt_ticket = $conn->prepare("INSERT INTO ticket_codes (reservation_id, ticket_code, guest_type, guest_number) VALUES (?, ?, 'kid', ?)");
                $stmt_ticket->bind_param("ssi", $reservation_id, $ticketCode, $i);
                $stmt_ticket->execute();
                $stmt_ticket->close();
                $kidCount++;
            }
        }
        
        $conn->commit();
        
        $message = "Reservation created successfully!<br>";
        $message .= "Reservation ID: <strong>" . $reservation_id . "</strong><br>";
        $message .= "Tickets generated: " . $adultCount . " Adult, " . $teenCount . " Teen, " . $kidCount . " Kid";
        $messageType = "success";
        
        // Clear form
        $_POST = array();
        
    } catch (Exception $e) {
        $conn->rollback();
        $message = "Error creating reservation: " . $e->getMessage();
        $messageType = "error";
    }
}

// Get available tables for dropdown
$tables = [];
$result = $conn->query("SELECT DISTINCT table_id FROM reservations WHERE status != 'cancelled' ORDER BY table_id");
while ($row = $result->fetch_assoc()) {
    $tables[] = $row['table_id'];
}
// Add some default tables if none exist
if (empty($tables)) {
    $tables = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2', 'D1', 'D2'];
}

$conn->close();

// Helper function to generate reservation ID with specific sequential number
function generateReservationIdWithSeq($adults, $teens, $kids, $sequential) {
    $prefix = 'RES';
    $sequentialFormatted = str_pad($sequential, 4, '0', STR_PAD_LEFT);
    $totalGuests = $adults + $teens + $kids;
    $breakdown = $totalGuests . 'G' . $adults . 'A' . $teens . 'T' . $kids . 'K';
    $randomSuffix = generateRandomString(5);
    return $prefix . $sequentialFormatted . '-' . $breakdown . '-' . $randomSuffix;
}
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo getDirection(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo t('new_reservation'); ?> - <?php echo t('ticketing_system'); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
        }
        
        body.dark-mode {
            background: #0f172a;
            color: #e2e8f0;
        }
        
        .container { max-width: 800px; margin: 0 auto; }
        
        .navbar {
            background: white;
            border-radius: 24px;
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        body.dark-mode .navbar {
            background: #1e293b;
        }
        
        .card {
            background: white;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        body.dark-mode .card {
            background: #1e293b;
        }
        
        .card-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        body.dark-mode .card-header {
            border-bottom-color: #334155;
        }
        
        .event-badge {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
            color: white;
            padding: 8px 16px;
            border-radius: 40px;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            font-size: 14px;
            color: #334155;
        }
        
        body.dark-mode .form-group label {
            color: #cbd5e1;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        body.dark-mode .form-group input,
        body.dark-mode .form-group select,
        body.dark-mode .form-group textarea {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        
        .price-display {
            background: #f1f5f9;
            padding: 15px;
            border-radius: 12px;
            margin-top: 10px;
        }
        
        body.dark-mode .price-display {
            background: #0f172a;
        }
        
        .total-amount {
            font-size: 24px;
            font-weight: bold;
            color: #4f46e5;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .btn-primary { background: #4f46e5; color: white; }
        .btn-primary:hover { background: #4338ca; transform: translateY(-1px); }
        .btn-secondary { background: #64748b; color: white; }
        .btn-secondary:hover { background: #475569; }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        
        body.dark-mode .form-actions {
            border-top-color: #334155;
        }
        
        .alert {
            padding: 12px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
        }
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            .navbar {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="navbar">
            <h1><i class="bi bi-plus-circle"></i> <?php echo t('new_reservation'); ?></h1>
            <div>
                <div class="event-badge">
                    <i class="bi bi-calendar-event"></i>
                    <?php echo htmlspecialchars($selected_event_name); ?>
                </div>
                <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left"></i> <?php echo t('back_to_dashboard'); ?></a>
            </div>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <i class="bi bi-<?php echo $messageType == 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <h2><i class="bi bi-person-plus"></i> Customer Information</h2>
            </div>
            
            <form method="POST" onsubmit="return validateForm()">
                <div class="form-group">
                    <label><i class="bi bi-person"></i> Full Name *</label>
                    <input type="text" name="name" required value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" placeholder="Enter customer name">
                </div>
                
                <div class="form-group">
                    <label><i class="bi bi-telephone"></i> Phone Number *</label>
                    <input type="tel" name="phone" required value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>" placeholder="+962XXXXXXXXX">
                </div>
                
                <div class="form-group">
                    <label><i class="bi bi-grid-3x3-gap-fill"></i> Table Number *</label>
                    <select name="table_id" required>
                        <option value="">Select a table</option>
                        <?php foreach ($tables as $table): ?>
                            <option value="<?php echo htmlspecialchars($table); ?>" <?php echo (($_POST['table_id'] ?? '') == $table) ? 'selected' : ''; ?>>
                                Table <?php echo htmlspecialchars($table); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="card-header" style="margin-top: 20px;">
                    <h2><i class="bi bi-people"></i> Guest Information</h2>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="bi bi-gender-male"></i> Adults (<?php echo $currencySymbol; ?> <?php echo number_format($adultPrice, 2); ?> each)</label>
                        <input type="number" name="adults" id="adults" min="0" value="<?php echo $_POST['adults'] ?? 0; ?>" onchange="calculateTotal()">
                    </div>
                    <div class="form-group">
                        <label><i class="bi bi-gender-female"></i> Teens (<?php echo $currencySymbol; ?> <?php echo number_format($teenPrice, 2); ?> each)</label>
                        <input type="number" name="teens" id="teens" min="0" value="<?php echo $_POST['teens'] ?? 0; ?>" onchange="calculateTotal()">
                    </div>
                    <div class="form-group">
                        <label><i class="bi bi-egg-fried"></i> Kids (<?php echo $currencySymbol; ?> <?php echo number_format($kidPrice, 2); ?> each)</label>
                        <input type="number" name="kids" id="kids" min="0" value="<?php echo $_POST['kids'] ?? 0; ?>" onchange="calculateTotal()">
                    </div>
                </div>
                
                <div class="price-display">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span><strong>Total Amount:</strong></span>
                        <span class="total-amount" id="totalAmount">0.00 <?php echo $currencySymbol; ?></span>
                    </div>
                    <div style="font-size: 12px; color: #64748b; margin-top: 5px;">
                        * This amount will be due upon arrival
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="bi bi-chat"></i> Special Notes</label>
                    <textarea name="notes" rows="3" placeholder="Any special requests or notes..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" onclick="window.location.href='dashboard.php'" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i> Create Reservation
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        const adultPrice = <?php echo $adultPrice; ?>;
        const teenPrice = <?php echo $teenPrice; ?>;
        const kidPrice = <?php echo $kidPrice; ?>;
        const currencySymbol = '<?php echo $currencySymbol; ?>';
        
        function calculateTotal() {
            const adults = parseInt(document.getElementById('adults').value) || 0;
            const teens = parseInt(document.getElementById('teens').value) || 0;
            const kids = parseInt(document.getElementById('kids').value) || 0;
            
            const total = (adults * adultPrice) + (teens * teenPrice) + (kids * kidPrice);
            document.getElementById('totalAmount').innerHTML = total.toFixed(2) + ' ' + currencySymbol;
        }
        
        function validateForm() {
            const name = document.querySelector('input[name="name"]').value.trim();
            const phone = document.querySelector('input[name="phone"]').value.trim();
            const table = document.querySelector('select[name="table_id"]').value;
            const adults = parseInt(document.getElementById('adults').value) || 0;
            const teens = parseInt(document.getElementById('teens').value) || 0;
            const kids = parseInt(document.getElementById('kids').value) || 0;
            
            if (!name) {
                alert('Please enter customer name');
                return false;
            }
            
            if (!phone) {
                alert('Please enter phone number');
                return false;
            }
            
            if (!table) {
                alert('Please select a table');
                return false;
            }
            
            if (adults === 0 && teens === 0 && kids === 0) {
                alert('Please add at least one guest');
                return false;
            }
            
            return true;
        }
        
        // Calculate total on page load
        document.addEventListener('DOMContentLoaded', function() {
            calculateTotal();
        });
    </script>
</body>
</html>