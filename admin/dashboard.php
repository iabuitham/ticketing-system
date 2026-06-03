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

// Get selected event info
$selected_event_id = $_SESSION['selected_event_id'] ?? 0;
$selected_event_name = $_SESSION['selected_event_name'] ?? 'No Event Selected';
$selected_event_date = $_SESSION['selected_event_date'] ?? '';

// Check if event is closed
$eventStatusCheck = $conn->prepare("SELECT status, is_closed FROM event_settings WHERE id = ?");
$eventStatusCheck->bind_param("i", $selected_event_id);
$eventStatusCheck->execute();
$eventData = $eventStatusCheck->get_result()->fetch_assoc();
$eventStatusCheck->close();
$isEventClosed = ($eventData && ($eventData['status'] == 'completed' || $eventData['is_closed'] == 1));

// Handle Close Event
if (isset($_POST['close_event']) && $selected_event_id > 0) {
    $password = $_POST['password'] ?? '';
    if ($password === 'AdminDelete2026') {
        $updateStmt = $conn->prepare("UPDATE event_settings SET status = 'completed', is_closed = 1, closed_at = NOW() WHERE id = ?");
        $updateStmt->bind_param("i", $selected_event_id);
        $updateStmt->execute();
        $updateStmt->close();
        $_SESSION['switch_error'] = "Event has been closed successfully!";
        $_SESSION['switch_error_type'] = "success";
        header('Location: dashboard.php');
        exit();
    } else {
        $_SESSION['switch_error'] = "Invalid password! Event not closed.";
        $_SESSION['switch_error_type'] = "error";
    }
}

// Get event-specific ticket prices
$event_ticket_prices = $_SESSION['event_ticket_prices'] ?? null;
if (!$event_ticket_prices && $selected_event_id > 0) {
    $stmt = $conn->prepare("SELECT ticket_price_adult, ticket_price_teen, ticket_price_kid, loyalty_price_adult, loyalty_price_teen, loyalty_price_kid FROM event_settings WHERE id = ?");
    $stmt->bind_param("i", $selected_event_id);
    $stmt->execute();
    $event_prices = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($event_prices) {
        $event_ticket_prices = [
            'adult' => $event_prices['ticket_price_adult'],
            'teen' => $event_prices['ticket_price_teen'],
            'kid' => $event_prices['ticket_price_kid'],
            'loyalty_adult' => $event_prices['loyalty_price_adult'] ?? $event_prices['ticket_price_adult'] * 0.8,
            'loyalty_teen' => $event_prices['loyalty_price_teen'] ?? $event_prices['ticket_price_teen'] * 0.8,
            'loyalty_kid' => $event_prices['loyalty_price_kid'] ?? $event_prices['ticket_price_kid']
        ];
        $_SESSION['event_ticket_prices'] = $event_ticket_prices;
    }
}

$adultPrice = $event_ticket_prices['adult'] ?? getSetting('ticket_price_adult', 8);
$teenPrice = $event_ticket_prices['teen'] ?? getSetting('ticket_price_teen', 8);
$kidPrice = $event_ticket_prices['kid'] ?? getSetting('ticket_price_kid', 0);
$loyaltyAdultPrice = $event_ticket_prices['loyalty_adult'] ?? $adultPrice * 0.8;
$loyaltyTeenPrice = $event_ticket_prices['loyalty_teen'] ?? $teenPrice * 0.8;
$loyaltyKidPrice = $event_ticket_prices['loyalty_kid'] ?? $kidPrice;

// Get filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

$query = "SELECT r.*, 
          COALESCE(SUM(sp.amount), 0) as total_paid,
          CASE 
              WHEN r.status = 'cancelled' THEN 0
              ELSE (r.total_amount - COALESCE(SUM(sp.amount), 0))
          END as actual_amount_due
          FROM reservations r
          LEFT JOIN split_payments sp ON r.reservation_id = sp.reservation_id
          WHERE r.event_id = ?
          GROUP BY r.reservation_id";
$params = [$selected_event_id];
$types = "i";

if ($status_filter && $status_filter != 'all') {
    $query .= " AND r.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($search) {
    $query .= " AND (r.name LIKE ? OR r.reservation_id LIKE ? OR r.phone LIKE ?)";
    $search_param = "%{$search}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$query .= " ORDER BY r.created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$reservations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ========== CORRECTED STATISTICS QUERIES ==========

// 1. RESERVATION COUNTS (by event)
$statsResult = $conn->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'registered' THEN 1 ELSE 0 END) as registered,
        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
    FROM reservations
    WHERE event_id = ?
");
$statsResult->bind_param("i", $selected_event_id);
$statsResult->execute();
$stats = $statsResult->get_result()->fetch_assoc();
$statsResult->close();

// 2. AMOUNT DUE (Only from pending/registered)
$amountDueResult = $conn->prepare("
    SELECT COALESCE(SUM(additional_amount_due), 0) as total
    FROM reservations
    WHERE event_id = ? AND status IN ('pending', 'registered')
");
$amountDueResult->bind_param("i", $selected_event_id);
$amountDueResult->execute();
$totalAmountDue = $amountDueResult->get_result()->fetch_assoc()['total'];
$amountDueResult->close();

// 3. ATTENDEE STATS
// 3. ATTENDEE STATS - CORRECTED
// New pending = never paid (total_paid = 0 AND additional_amount_due = total_amount)
// Old pending = had payments but increased guests (total_paid > 0 AND additional_amount_due > 0)
// 3. ATTENDEE STATS - CORRECTED
$attendeeResult = $conn->prepare("
    SELECT 
        -- FULLY PAID ATTENDEES (status = 'paid' AND additional_amount_due = 0)
        SUM(CASE WHEN status = 'paid' AND additional_amount_due = 0 THEN adults ELSE 0 END) as fully_paid_adults,
        SUM(CASE WHEN status = 'paid' AND additional_amount_due = 0 THEN teens ELSE 0 END) as fully_paid_teens,
        SUM(CASE WHEN status = 'paid' AND additional_amount_due = 0 THEN kids ELSE 0 END) as fully_paid_kids,
        SUM(CASE WHEN status = 'paid' AND additional_amount_due = 0 THEN adults + teens + kids ELSE 0 END) as total_attendees_paid,
        
        -- TOTAL BOOKED (all non-cancelled - for reference)
        SUM(CASE WHEN status != 'cancelled' THEN adults ELSE 0 END) as total_adults,
        SUM(CASE WHEN status != 'cancelled' THEN teens ELSE 0 END) as total_teens,
        SUM(CASE WHEN status != 'cancelled' THEN kids ELSE 0 END) as total_kids,
        SUM(CASE WHEN status != 'cancelled' THEN adults + teens + kids ELSE 0 END) as total_attendees,
        
        -- NEW PENDING ATTENDEES (never paid)
        SUM(CASE WHEN (status IN ('pending', 'registered') AND additional_amount_due = total_amount)
                  AND status != 'cancelled'
             THEN adults + teens + kids ELSE 0 END) as new_pending_attendees,
             
        -- OLD PENDING ATTENDEES (had payments before, but increased guests)
        SUM(CASE WHEN (status IN ('pending', 'registered') AND additional_amount_due > 0 AND additional_amount_due < total_amount)
                  AND status != 'cancelled'
             THEN adults + teens + kids ELSE 0 END) as old_pending_attendees,
             
        -- Total pending
        SUM(CASE WHEN (status IN ('pending', 'registered') OR additional_amount_due > 0) AND status != 'cancelled'
             THEN adults + teens + kids ELSE 0 END) as total_pending_attendees
    FROM reservations
    WHERE event_id = ?
");
$attendeeResult->bind_param("i", $selected_event_id);
$attendeeResult->execute();
$attendeeStats = $attendeeResult->get_result()->fetch_assoc();
$attendeeResult->close();

// 4. REVENUE FROM ALL PAYMENTS (by event)
$allPaymentsResult = $conn->prepare("
    SELECT 
        SUM(CASE WHEN sp.payment_method = 'cash' THEN sp.amount ELSE 0 END) as cash,
        SUM(CASE WHEN sp.payment_method = 'cliq' THEN sp.amount ELSE 0 END) as cliq,
        SUM(CASE WHEN sp.payment_method = 'visa' THEN sp.amount ELSE 0 END) as visa,
        SUM(sp.amount) as total,
        SUM(CASE WHEN r.price_tier = 'regular' THEN sp.amount ELSE 0 END) as regular_revenue,
        SUM(CASE WHEN r.price_tier = 'loyalty' THEN sp.amount ELSE 0 END) as loyalty_revenue
    FROM split_payments sp
    INNER JOIN reservations r ON sp.reservation_id = r.reservation_id
    WHERE r.event_id = ? AND r.status != 'cancelled'
");
$allPaymentsResult->bind_param("i", $selected_event_id);
$allPaymentsResult->execute();
$allPayments = $allPaymentsResult->get_result()->fetch_assoc();
$allPaymentsResult->close();

// 5. REFUNDS
$refundResult = $conn->prepare("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM credit_notes cn
    INNER JOIN reservations r ON cn.reservation_id = r.reservation_id
    WHERE cn.status = 'processed' AND r.event_id = ?
");
$refundResult->bind_param("i", $selected_event_id);
$refundResult->execute();
$totalRefunded = $refundResult->get_result()->fetch_assoc()['total'] ?? 0;
$refundResult->close();

// 6. NET REVENUE
$netRevenue = max(0, floatval($allPayments['total']) - $totalRefunded);
$regularNetRevenue = max(0, floatval($allPayments['regular_revenue']) - $totalRefunded * (floatval($allPayments['regular_revenue']) / max(1, floatval($allPayments['total']))));
$loyaltyNetRevenue = max(0, floatval($allPayments['loyalty_revenue']) - $totalRefunded * (floatval($allPayments['loyalty_revenue']) / max(1, floatval($allPayments['total']))));

// 7. CANCELLED STATS
$cancelledResult = $conn->prepare("
    SELECT 
        COUNT(*) as count,
        COALESCE(SUM(sp.amount), 0) as total_paid
    FROM reservations r
    LEFT JOIN split_payments sp ON r.reservation_id = sp.reservation_id
    WHERE r.event_id = ? AND r.status = 'cancelled'
");
$cancelledResult->bind_param("i", $selected_event_id);
$cancelledResult->execute();
$cancelledData = $cancelledResult->get_result()->fetch_assoc();
$cancelledResult->close();
$cancelledCount = $cancelledData['count'] ?? 0;
$cancelledRevenue = $cancelledData['total_paid'] ?? 0;

// ========== SYSTEM SETTINGS ==========
$currency = getSetting('currency', 'JOD');
$currencySymbol = getCurrencySymbol();
$siteName = getSetting('site_name', 'Ticketing System');
$themeColor = getSetting('theme_color', '#4f46e5');

// ========== SWITCH ERROR MESSAGES ==========
$switch_error = $_SESSION['switch_error'] ?? '';
$switch_error_type = $_SESSION['switch_error_type'] ?? '';
unset($_SESSION['switch_error']);
unset($_SESSION['switch_error_type']);

// ========== EVENT COUNT FOR SWITCH BUTTON ==========
$conn_count = getConnection();
$eventCountResult = $conn_count->prepare("SELECT COUNT(*) as count FROM event_settings WHERE status != 'completed'");
$eventCountResult->execute();
$activeEventCount = $eventCountResult->get_result()->fetch_assoc()['count'];
$conn_count->close();

// Get floor plan image (with error handling for missing column)
$floorPlanImage = '';
if ($selected_event_id > 0) {
    // Check if column exists first
    $checkColumn = $conn->query("SHOW COLUMNS FROM event_settings LIKE 'floor_plan_image'");
    if ($checkColumn && $checkColumn->num_rows > 0) {
        $floorPlanResult = $conn->prepare("SELECT floor_plan_image FROM event_settings WHERE id = ?");
        $floorPlanResult->bind_param("i", $selected_event_id);
        $floorPlanResult->execute();
        $floorPlanData = $floorPlanResult->get_result()->fetch_assoc();
        $floorPlanResult->close();
        $floorPlanImage = $floorPlanData['floor_plan_image'] ?? '';
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="<?php echo getCurrentLanguage(); ?>" dir="<?php echo getDirection(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title><?php echo t('Dashboard'); ?> - <?php echo htmlspecialchars($siteName); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            padding: 20px;
            transition: background 0.3s ease;
        }
        body.dark-mode { background: #0f172a; color: #e2e8f0; }
        .container { max-width: 1400px; margin: 0 auto; }
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
        body.dark-mode .navbar { background: #1e293b; }
        .navbar h1 { font-size: 1.5rem; display: flex; align-items: center; gap: 8px; }
        .event-info {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f1f5f9;
            padding: 8px 16px;
            border-radius: 40px;
        }
        body.dark-mode .event-info { background: #0f172a; }
        .nav-links { display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
        .header-controls { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .dark-mode-toggle {
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            padding: 8px;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .language-switcher {
            display: flex;
            gap: 5px;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 40px;
        }
        body.dark-mode .language-switcher { background: #334155; }
        .language-switcher button {
            background: none;
            border: none;
            padding: 6px 12px;
            border-radius: 30px;
            cursor: pointer;
            font-weight: 600;
            font-size: 12px;
            transition: all 0.2s;
            color: #475569;
        }
        body.dark-mode .language-switcher button { color: #94a3b8; }
        .language-switcher button.active { background: <?php echo $themeColor; ?>; color: white; }
        .btn-logout {
            background: #ef4444;
            color: white;
            padding: 8px 20px;
            border-radius: 40px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-logout:hover { background: #dc2626; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        body.dark-mode .stat-card { background: #1e293b; }
        .stat-card.primary { background: linear-gradient(135deg, <?php echo $themeColor; ?>, <?php echo $themeColor; ?>cc); color: white; }
        .stat-card.success { background: linear-gradient(135deg, #10b981, #059669); color: white; }
        .stat-card.warning { background: linear-gradient(135deg, #f59e0b, #d97706); color: white; }
        .stat-card.info { background: linear-gradient(135deg, #0ea5e9, #0284c7); color: white; }
        .stat-number { font-size: 32px; font-weight: bold; margin-bottom: 8px; }
        .stat-label { font-size: 14px; opacity: 0.9; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
        .stat-details {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid rgba(255,255,255,0.2);
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .detail-item { display: flex; justify-content: space-between; align-items: center; font-size: 13px; }
        .detail-item i { margin-right: 6px; }
        .filters-bar {
            background: white;
            border-radius: 20px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        body.dark-mode .filters-bar { background: #1e293b; }
        .search-box {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            position: relative;
        }
        .search-box input, .search-box select {
            padding: 8px 16px;
            border: 1px solid #e2e8f0;
            border-radius: 40px;
            font-size: 14px;
            background: white;
        }
        .search-box input { padding-left: 35px; }
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            pointer-events: none;
        }
        body.dark-mode .search-box input, body.dark-mode .search-box select {
            background: #0f172a;
            border-color: #334155;
            color: #e2e8f0;
        }
        .btn {
            padding: 8px 16px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
            font-size: 14px;
            transition: all 0.2s;
        }
        .btn-primary { background: <?php echo $themeColor; ?>; color: white; }
        .btn-primary:hover { opacity: 0.9; transform: translateY(-1px); }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { background: #059669; }
        .btn-secondary { background: #64748b; color: white; }
        .btn-secondary:hover { background: #475569; }
        .btn-warning { background: #f59e0b; color: white; }
        .btn-info { background: #0ea5e9; color: white; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .table-container {
            background: white;
            border-radius: 20px;
            overflow-x: auto;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        body.dark-mode .table-container { background: #1e293b; }
        table { width: 100%; border-collapse: collapse; min-width: 900px; }
        th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #e2e8f0; vertical-align: middle; }
        th { background: #f8fafc; font-weight: 600; }
        body.dark-mode th { background: #0f172a; color: #94a3b8; border-color: #334155; }
        body.dark-mode td { border-color: #334155; color: #cbd5e1; }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-registered { background: #dbeafe; color: #1e40af; }
        .status-paid { background: #d1fae5; color: #065f46; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        body.dark-mode .status-pending { background: #451a03; color: #fde68a; }
        body.dark-mode .status-registered { background: #1e3a5f; color: #93c5fd; }
        body.dark-mode .status-paid { background: #064e3b; color: #6ee7b7; }
        body.dark-mode .status-cancelled { background: #7f1d1d; color: #fca5a5; }
        .badge-table {
            display: inline-block;
            background: #e2e8f0;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: #1e293b;
            text-align: center;
            min-width: 50px;
            cursor: pointer;
        }
        body.dark-mode .badge-table { background: #334155; color: #e2e8f0; }
        .badge-table:hover { background: #4f46e5; color: white; }
        .guest-badge { font-weight: 600; }
        .guest-badge small { font-size: 11px; font-weight: normal; color: #64748b; }
        body.dark-mode .guest-badge small { color: #94a3b8; }
        .amount-due-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            background: #fef3c7;
            color: #92400e;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        body.dark-mode .amount-due-badge { background: #451a03; color: #fde68a; }
        .text-muted { color: #94a3b8; }
        .btn-group { display: flex; gap: 6px; flex-wrap: wrap; }
        .actions { white-space: nowrap; }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(3px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 99999;
        }
        .loading-overlay.active { display: flex; }
        .loading-spinner {
            width: 60px;
            height: 60px;
            border: 4px solid rgba(255,255,255,0.3);
            border-top: 4px solid <?php echo $themeColor; ?>;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .sound-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #10b981;
            color: white;
            padding: 12px 20px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 10001;
            animation: slideInRight 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        @keyframes slideInRight {
            from { opacity: 0; transform: translateX(100%); }
            to { opacity: 1; transform: translateX(0); }
        }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            animation: modalSlideIn 0.3s ease;
        }
        @keyframes modalSlideIn {
            from { opacity: 0; transform: translateY(-50px); }
            to { opacity: 1; transform: translateY(0); }
        }
        body.dark-mode .modal-container { background: #1e293b; }
        .modal-header {
            padding: 20px 24px;
            background: <?php echo $themeColor; ?>;
            color: white;
            border-radius: 24px 24px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 { margin: 0; font-size: 1.25rem; display: flex; align-items: center; gap: 8px; }
        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: white;
            line-height: 1;
            transition: transform 0.2s;
        }
        .modal-close:hover { transform: scale(1.1); }
        .modal-body { padding: 24px; }
        .form-group { margin-bottom: 15px; }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #334155;
            font-size: 14px;
        }
        body.dark-mode .form-group label { color: #cbd5e1; }
        .form-group select { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
        body.dark-mode .form-group select { background: #0f172a; border-color: #334155; color: #e2e8f0; }
        .modal-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
        }
        body.dark-mode .modal-buttons { border-top-color: #334155; }
        [dir="rtl"] { text-align: right; }
        [dir="rtl"] .actions, [dir="rtl"] .search-box { direction: rtl; }
        [dir="rtl"] .detail-item { flex-direction: row-reverse; }
        [dir="rtl"] .btn i { margin-left: 6px; margin-right: 0; }
        [dir="rtl"] .search-icon { left: auto; right: 12px; }
        [dir="rtl"] .search-box input { padding-left: 16px; padding-right: 35px; }
        @media (max-width: 1024px) { .stats-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .filters-bar { flex-direction: column; }
            .search-box { width: 100%; }
            .search-box input, .search-box select { flex: 1; }
            .navbar { flex-direction: column; text-align: center; }
            .nav-links { justify-content: center; }
            .header-controls { justify-content: center; }
            .table-container { overflow-x: auto; }
            table { min-width: 850px; }
            .btn-group { flex-wrap: nowrap; }
        }
        .alert { padding: 12px 20px; border-radius: 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .alert-warning { background: #fef3c7; color: #92400e; border-left: 4px solid #f59e0b; }
        .amount-due-display { background: #f1f5f9; padding: 15px; border-radius: 12px; text-align: center; margin-bottom: 20px; }
        body.dark-mode .amount-due-display { background: #0f172a; }
        .amount-due-display .label { font-size: 14px; color: #64748b; margin-bottom: 5px; }
        .amount-due-display .amount { font-size: 28px; font-weight: bold; color: <?php echo $themeColor; ?>; }
        .payment-split-item { background: #f8fafc; padding: 15px; border-radius: 12px; margin-bottom: 15px; border: 1px solid #e2e8f0; }
        body.dark-mode .payment-split-item { background: #0f172a; border-color: #334155; }
        .cliq-preview { margin-top: 10px; }
        .cliq-preview img { max-width: 100px; max-height: 100px; border-radius: 8px; }
    </style>
</head>
<body>
<div class="loading-overlay">
    <div style="text-align: center;">
        <div class="loading-spinner"></div>
        <div class="loading-text">Processing...</div>
    </div>
</div>

<div class="container">
    <div class="navbar">
        <h1><i class="bi bi-ticket-perforated"></i> <?php echo htmlspecialchars($siteName); ?></h1>
        <div class="nav-links">
            <div class="event-info">
                <i class="bi bi-calendar-event"></i>
                <div>
                    <strong><?php echo htmlspecialchars($selected_event_name); ?></strong>
                    <br>
                    <small><?php echo $selected_event_date ? date('M d, Y', strtotime($selected_event_date)) : ''; ?></small>
                </div>
                <?php if ($activeEventCount > 1): ?>
                    <a href="switch_event.php" class="btn btn-sm btn-secondary" style="padding: 4px 12px;">
                        <i class="bi bi-arrow-repeat"></i> Switch
                    </a>
                <?php else: ?>
                    <button class="btn btn-sm btn-secondary" style="padding: 4px 12px; opacity: 0.5;" disabled title="Only one event available">
                        <i class="bi bi-arrow-repeat"></i> Switch
                    </button>
                <?php endif; ?>
                <?php if (!$isEventClosed && $selected_event_id > 0): ?>
                    <button onclick="openCloseEventModal()" class="btn btn-sm btn-danger" style="padding: 4px 12px;">
                        <i class="bi bi-lock-fill"></i> Close Event
                    </button>
                <?php endif; ?>
                <?php if ($floorPlanImage): ?>
                    <a href="floor_plan.php" class="btn btn-sm btn-info" style="padding: 4px 12px;">
                        <i class="bi bi-map"></i> Floor Plan
                    </a>
                <?php endif; ?>
            </div>
            <div class="header-controls">
                <button id="darkModeToggle" class="dark-mode-toggle"><i class="bi bi-moon-fill"></i></button>
                <div class="language-switcher">
                    <button onclick="setLanguage('en')" class="<?php echo getCurrentLanguage() == 'en' ? 'active' : ''; ?>">EN</button>
                    <button onclick="setLanguage('ar')" class="<?php echo getCurrentLanguage() == 'ar' ? 'active' : ''; ?>">AR</button>
                </div>
                <button id="soundToggle" onclick="toggleSound()" class="btn btn-secondary" style="background: #10b981;"><i class="bi bi-volume-up-fill"></i> Sound On</button>
                <span><i class="bi bi-person-circle"></i> <?php echo t('Welcome'); ?>, <?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                <a href="logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> <?php echo t('Logout'); ?></a>
            </div>
        </div>
    </div>

    <?php if ($switch_error): ?>
        <div class="alert alert-<?php echo $switch_error_type; ?>">
            <i class="bi bi-exclamation-triangle-fill"></i>
            <?php echo htmlspecialchars($switch_error); ?>
        </div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card primary">
    <div class="stat-number"><?php echo number_format(floatval($attendeeStats['total_attendees_paid'] ?? 0)); ?></div>
    <div class="stat-label"><i class="bi bi-people-fill"></i> <?php echo t('Total Booked (Paid)'); ?></div>
    <div class="stat-details">
        <div class="detail-item"><span><i class="bi bi-gender-male"></i> <?php echo t('Adults'); ?></span><span><?php echo number_format(floatval($attendeeStats['fully_paid_adults'] ?? 0)); ?></span></div>
        <div class="detail-item"><span><i class="bi bi-gender-female"></i> <?php echo t('Teens'); ?></span><span><?php echo number_format(floatval($attendeeStats['fully_paid_teens'] ?? 0)); ?></span></div>
        <div class="detail-item"><span><i class="bi bi-egg-fried"></i> <?php echo t('Kids'); ?></span><span><?php echo number_format(floatval($attendeeStats['fully_paid_kids'] ?? 0)); ?></span></div>
    </div>
</div>

<div class="stat-card warning">
    <div class="stat-number"><?php echo number_format(floatval($attendeeStats['total_pending_attendees'] ?? 0)); ?></div>
    <div class="stat-label"><i class="bi bi-hourglass-split"></i> <?php echo t('Pending Attendees'); ?></div>
    <div class="stat-details">
        <div class="detail-item">
            <span><i class="bi bi-plus-circle"></i> New (Never Paid)</span>
            <span><?php echo number_format(floatval($attendeeStats['new_pending_attendees'] ?? 0)); ?></span>
        </div>
        <div class="detail-item">
            <span><i class="bi bi-arrow-repeat"></i> Old (Additional Due)</span>
            <span><?php echo number_format(floatval($attendeeStats['old_pending_attendees'] ?? 0)); ?></span>
        </div>
        <div class="detail-item">
            <span><i class="bi bi-currency-dollar"></i> <?php echo t('Amount Due'); ?></span>
            <span><?php echo number_format(floatval($totalAmountDue), 2); ?> <?php echo $currencySymbol; ?></span>
        </div>
    </div>
</div>

        <div class="stat-card success">
            <div class="stat-number"><?php echo number_format(floatval($attendeeStats['total_attendees_paid'] ?? 0)); ?></div>
            <div class="stat-label"><i class="bi bi-check-circle-fill"></i> <?php echo t('Total Attendees (Fully Paid)'); ?></div>
            <div class="stat-details">
                <div class="detail-item"><span><i class="bi bi-cash-stack"></i> <?php echo t('Cash'); ?></span><span><?php echo number_format(floatval($allPayments['cash'] ?? 0), 2); ?> <?php echo $currencySymbol; ?></span></div>
                <div class="detail-item"><span><i class="bi bi-phone"></i> <?php echo t('Cliq'); ?></span><span><?php echo number_format(floatval($allPayments['cliq'] ?? 0), 2); ?> <?php echo $currencySymbol; ?></span></div>
                <div class="detail-item"><span><i class="bi bi-credit-card"></i> <?php echo t('Visa'); ?></span><span><?php echo number_format(floatval($allPayments['visa'] ?? 0), 2); ?> <?php echo $currencySymbol; ?></span></div>
            </div>
        </div>

        <div class="stat-card info">
            <div class="stat-number"><?php echo number_format(floatval($netRevenue), 2); ?> <?php echo $currencySymbol; ?></div>
            <div class="stat-label"><i class="bi bi-graph-up"></i> <?php echo t('Net Revenue'); ?></div>
            <div class="stat-details">
                <div class="detail-item"><span><i class="bi bi-tag"></i> Regular Price</span><span><?php echo number_format(floatval($regularNetRevenue), 2); ?> <?php echo $currencySymbol; ?></span></div>
                <div class="detail-item"><span><i class="bi bi-star"></i> Loyalty Price</span><span><?php echo number_format(floatval($loyaltyNetRevenue), 2); ?> <?php echo $currencySymbol; ?></span></div>
                <div class="detail-item"><span><i class="bi bi-arrow-return-left"></i> <?php echo t('Refunds'); ?></span><span class="detail-value" style="color: #f59e0b;">- <?php echo number_format(floatval($totalRefunded), 2); ?> <?php echo $currencySymbol; ?></span></div>
                <div class="detail-item"><span><i class="bi bi-x-circle"></i> <?php echo t('Cancelled'); ?></span><span class="detail-value" style="color: #fecaca;">- <?php echo number_format(floatval($cancelledRevenue), 2); ?> <?php echo $currencySymbol; ?></span></div>
            </div>
        </div>
    </div>

    <div class="filters-bar">
        <div class="search-box">
            <i class="bi bi-search search-icon"></i>
            <input type="text" id="search" placeholder="<?php echo t('Search'); ?>" value="<?php echo htmlspecialchars($search); ?>">
            <select id="statusFilter">
                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>><?php echo t('All'); ?></option>
                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>><?php echo t('Pending'); ?></option>
                <option value="registered" <?php echo $status_filter == 'registered' ? 'selected' : ''; ?>><?php echo t('Registered'); ?></option>
                <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>><?php echo t('paid'); ?></option>
                <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>><?php echo t('Cancelled'); ?></option>
            </select>
            <button onclick="applyFilters()" class="btn btn-primary"><i class="bi bi-funnel"></i> <?php echo t('Apply'); ?></button>
            <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-repeat"></i> <?php echo t('Reset'); ?></a>
        </div>
        <div>
            <a href="create_reservation.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> <?php echo t('New Reservation'); ?></a>
            <a href="bulk_whatsapp.php" class="btn btn-success"><i class="bi bi-whatsapp"></i> <?php echo t('Bulk Whatsapp'); ?></a>
            <button onclick="openExportModal()" class="btn btn-info"><i class="bi bi-filetype-csv"></i> <?php echo t('Export CSV'); ?></button>
            <a href="print_statement.php" class="btn btn-secondary"><i class="bi bi-printer"></i> <?php echo t('Print Statement'); ?></a>
            <a href="manager_report.php" class="btn btn-secondary"><i class="bi bi-bar-chart-steps"></i> <?php echo t('Analytics'); ?></a>
            <a href="tables.php" class="btn btn-secondary"><i class="bi bi-grid-3x3-gap-fill"></i> Tables</a>
            <a href="tickets_dashboard.php" class="btn btn-info"><i class="bi bi-ticket-perforated"></i> Ticket Dashboard</a>
            <a href="floor_plan.php" class="btn btn-secondary"><i class="bi bi-map"></i> Floor Plan</a>
            <a href="customers.php" class="btn btn-warning"><i class="bi bi-person"></i> Customers </a>
        </div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th style="min-width: 180px;"><i class="bi bi-upc-scan"></i> <?php echo t('reservation_id'); ?></th>
                    <th style="min-width: 150px;"><i class="bi bi-person"></i> <?php echo t('customer_name'); ?></th>
                    <th style="min-width: 150px;"><i class="bi bi-telephone"></i> <?php echo t('phone_number'); ?></th>
                    <th style="min-width: 80px;"><i class="bi bi-grid-3x3-gap-fill"></i> <?php echo t('table_id'); ?></th>
                    <th style="min-width: 120px;"><i class="bi bi-people"></i> <?php echo t('guests'); ?></th>
                    <th style="min-width: 100px;"><i class="bi bi-info-circle"></i> <?php echo t('status'); ?></th>
                    <th style="min-width: 100px;"><i class="bi bi-currency-dollar"></i> <?php echo t('Amount Due'); ?></th>
                    <th style="min-width: 120px;"><i class="bi bi-calendar3"></i> <?php echo t('created'); ?></th>
                    <th style="min-width: 280px;"><i class="bi bi-gear"></i> <?php echo t('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reservations as $res):
                    $totalGuests = ($res['adults'] ?? 0) + ($res['teens'] ?? 0) + ($res['kids'] ?? 0);
                    $amountDue = isset($res['actual_amount_due']) ? floatval($res['actual_amount_due']) : 0;
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($res['reservation_id']); ?></strong></td>
                    <td><?php echo htmlspecialchars($res['name']); ?></td>
                    <td><?php echo htmlspecialchars($res['phone']); ?></td>
                    <td style="text-align: center;">
                        <?php if (empty($res['table_id'])): ?>
                            <span class="badge-table" onclick="openTableModal('<?php echo $res['reservation_id']; ?>', '<?php echo htmlspecialchars($res['name']); ?>', '<?php echo htmlspecialchars($res['phone']); ?>')" style="background: #f59e0b; color: white;">
                                <i class="bi bi-plus-circle"></i> Assign
                            </span>
                        <?php else: ?>
                            <span class="badge-table" onclick="openTableModal('<?php echo $res['reservation_id']; ?>', '<?php echo htmlspecialchars($res['name']); ?>', '<?php echo htmlspecialchars($res['phone']); ?>')">
                                <?php echo htmlspecialchars($res['table_id']); ?> <i class="bi bi-pencil"></i>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><span class="guest-badge"><?php echo $totalGuests; ?> <small>(<?php echo intval($res['adults'] ?? 0); ?>A, <?php echo intval($res['teens'] ?? 0); ?>T, <?php echo intval($res['kids'] ?? 0); ?>K)</small></span></td>
                    <td><span class="status-badge status-<?php echo $res['status']; ?>"><i class="bi <?php echo $res['status'] == 'paid' ? 'bi-check-circle-fill' : ($res['status'] == 'pending' ? 'bi-hourglass-split' : ($res['status'] == 'registered' ? 'bi-check-circle' : 'bi-slash-circle')); ?>"></i><?php echo ucfirst($res['status']); ?></span></td>
                    <td><?php if ($amountDue > 0): ?><span class="amount-due-badge"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo number_format($amountDue, 2); ?> <?php echo $currencySymbol; ?></span><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                    <td><?php echo date('M d, H:i', strtotime($res['created_at'])); ?></td>
                    <td class="actions">
                        <div class="btn-group">
                            <a href="view_reservation.php?id=<?php echo urlencode($res['reservation_id']); ?>" class="btn btn-sm btn-secondary" title="View"><i class="bi bi-eye"></i></a>
                            <a href="edit_reservation.php?id=<?php echo urlencode($res['reservation_id']); ?>" class="btn btn-sm btn-warning" title="Edit"><i class="bi bi-pencil"></i></a>
                            <a href="/public/reservation_tickets.php?id=<?php echo urlencode($res['reservation_id']); ?>" class="btn btn-sm btn-info" title="View Tickets"><i class="bi bi-ticket-perforated"></i></a>
                            <?php if (empty($res['table_id'])): ?>
                                <button onclick="sendFloorPlan('<?php echo $res['reservation_id']; ?>', '<?php echo htmlspecialchars($res['phone']); ?>', '<?php echo htmlspecialchars($res['name']); ?>')" class="btn btn-sm btn-success" title="Send Floor Plan"><i class="bi bi-map"></i> Send Plan</button>
                            <?php endif; ?>
                            <?php if ($res['status'] != 'cancelled' && $amountDue > 0): ?>
                                <button onclick="openPaymentModal('<?php echo $res['reservation_id']; ?>', <?php echo floatval($res['total_amount'] ?? 0); ?>, <?php echo $amountDue; ?>)" class="btn btn-sm btn-success" title="Pay"><i class="bi bi-credit-card"></i> Pay <?php echo number_format($amountDue, 2); ?></button>
                            <?php endif; ?>
                            <button onclick="deleteReservation('<?php echo $res['reservation_id']; ?>', this)" class="btn btn-sm btn-danger" title="Delete"><i class="bi bi-trash3"></i></button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($reservations)): ?>
                <tr><td colspan="9" style="text-align: center; padding: 60px;"><i class="bi bi-inbox" style="font-size: 48px; opacity: 0.5;"></i><p style="margin-top: 10px;"><?php echo t('No Reservations'); ?></p></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Table Assignment Modal -->
<div id="tableModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-grid-3x3-gap-fill"></i> Assign Table Number</h3>
            <button onclick="closeTableModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="assignReservationId">
            <div id="assignCustomerInfo" style="background: #f1f5f9; padding: 10px; border-radius: 8px; margin-bottom: 15px;"></div>
            <div class="form-group">
                <label>Select Table Number</label>
                <select id="assignTableNumber" required>
                    <option value="">-- Select a table --</option>
                </select>
            </div>
            <div class="modal-buttons">
                <button type="button" onclick="closeTableModal()" class="btn btn-secondary">Cancel</button>
                <button type="button" onclick="assignTable()" class="btn btn-primary">Assign Table</button>
            </div>
        </div>
    </div>
</div>

<!-- Close Event Modal -->
<div id="closeEventModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-lock-fill"></i> Close Event</h3>
            <button onclick="closeCloseEventModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div style="background: #fee2e2; padding: 15px; border-radius: 12px; margin-bottom: 20px;">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <strong>Warning:</strong> Closing this event will prevent any further modifications to reservations. This action can be reversed by an administrator.
            </div>
            <form method="POST">
                <input type="hidden" name="close_event" value="1">
                <div class="form-group">
                    <label>Enter Admin Password to Confirm</label>
                    <input type="password" name="password" required>
                </div>
                <div class="modal-buttons">
                    <button type="button" onclick="closeCloseEventModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-danger">Close Event</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-credit-card"></i> Process Payment</h3>
            <button onclick="closePaymentModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <div class="amount-due-display">
                <div class="label">Outstanding Balance (Amount Due)</div>
                <div class="amount" id="modalTotalAmountDue">0.00 <?php echo $currencySymbol; ?></div>
            </div>
            <div id="remainingAmountDisplay" style="background: #fef3c7; padding: 10px; border-radius: 8px; margin-bottom: 15px; text-align: center;">
                <small>Remaining to pay: <strong id="remainingAmount">0.00</strong> <?php echo $currencySymbol; ?></small>
            </div>
            <div id="paymentSplits"></div>
            <button type="button" class="btn btn-secondary btn-sm" onclick="addPaymentSplit()" style="margin-bottom: 20px; width: 100%;">
                <i class="bi bi-plus-circle"></i> Add Another Payment Method
            </button>
            <input type="hidden" id="paymentReservationId">
            <input type="hidden" id="totalAmountDue">
            <div class="modal-buttons">
                <button type="button" onclick="closePaymentModal()" class="btn btn-secondary">Cancel</button>
                <button type="button" onclick="processSplitPayments()" class="btn btn-success">
                    <i class="bi bi-check-circle"></i> Process All Payments
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Export Modal -->
<div id="exportModal" class="modal-overlay">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="bi bi-filetype-csv"></i> <?php echo t('Export CSV'); ?></h3>
            <button onclick="closeExportModal()" class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form id="exportForm" method="GET" action="export_csv.php">
                <div style="margin-bottom: 20px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600;"><i class="bi bi-funnel"></i> <?php echo t('Filter By Status'); ?></label>
                    <select name="status" style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px;">
                        <option value="all"><?php echo t('All'); ?></option>
                        <option value="pending"><?php echo t('Pending'); ?></option>
                        <option value="registered"><?php echo t('Registered'); ?></option>
                        <option value="paid"><?php echo t('Paid'); ?></option>
                        <option value="cancelled"><?php echo t('Cancelled'); ?></option>
                    </select>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div><label style="display: block; margin-bottom: 8px; font-weight: 600;"><i class="bi bi-calendar"></i> <?php echo t('From Date'); ?></label><input type="date" name="from" style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px;"></div>
                    <div><label style="display: block; margin-bottom: 8px; font-weight: 600;"><i class="bi bi-calendar"></i> <?php echo t('To Date'); ?></label><input type="date" name="to" style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px;"></div>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
                    <button type="button" onclick="closeExportModal()" style="padding: 10px 20px; background: #64748b; color: white; border: none; border-radius: 10px; cursor: pointer;"><i class="bi bi-x-lg"></i> <?php echo t('cancel'); ?></button>
                    <button type="submit" style="padding: 10px 20px; background: <?php echo $themeColor; ?>; color: white; border: none; border-radius: 10px; cursor: pointer;"><i class="bi bi-download"></i> <?php echo t('Export CSV'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showLoading(message = 'Processing...') {
    const overlay = document.querySelector('.loading-overlay');
    const textEl = overlay?.querySelector('.loading-text');
    if (textEl) textEl.innerText = message;
    if (overlay) overlay.classList.add('active');
}
function hideLoading() {
    const overlay = document.querySelector('.loading-overlay');
    if (overlay) overlay.classList.remove('active');
}

// Table Assignment
function openTableModal(reservationId, customerName, customerPhone) {
    document.getElementById('assignReservationId').value = reservationId;
    document.getElementById('assignCustomerInfo').innerHTML = `
        <strong>Customer:</strong> ${customerName}<br>
        <strong>Phone:</strong> ${customerPhone}
    `;
    
    fetch('get_available_tables.php')
        .then(response => response.json())
        .then(data => {
            const select = document.getElementById('assignTableNumber');
            select.innerHTML = '<option value="">-- Select a table --</option>';
            if (data.success && data.tables) {
                data.tables.forEach(table => {
                    select.innerHTML += `<option value="${table.table_number}">Table ${table.table_number} ${table.section ? '(' + table.section + ')' : ''}</option>`;
                });
            } else {
                select.innerHTML += '<option value="" disabled>No available tables</option>';
            }
            document.getElementById('tableModal').classList.add('active');
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading tables');
        });
}

function closeTableModal() {
    document.getElementById('tableModal').classList.remove('active');
}

function assignTable() {
    const reservationId = document.getElementById('assignReservationId').value;
    const tableNumber = document.getElementById('assignTableNumber').value;
    
    if (!tableNumber) {
        alert('Please select a table');
        return;
    }
    
    showLoading('Assigning table...');
    
    fetch('assign_table.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reservation_id: reservationId, table_number: tableNumber })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification('Table assigned successfully!', 'success');
            closeTableModal();
            setTimeout(() => location.reload(), 1000);
        } else {
            showNotification(data.error, 'error');
        }
    })
    .catch(error => {
        hideLoading();
        showNotification('Error: ' + error.message, 'error');
    });
}

// Send Floor Plan
function sendFloorPlan(reservationId, phone, name) {
    if (!confirm(`Send floor plan to ${name} (${phone})?`)) return;
    
    showLoading('Sending floor plan...');
    
    fetch('send_floor_plan.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reservation_id: reservationId, phone: phone, name: name })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            showNotification('Floor plan sent successfully!', 'success');
        } else {
            showNotification(data.error, 'error');
        }
    })
    .catch(error => {
        hideLoading();
        showNotification('Error: ' + error.message, 'error');
    });
}

// Close Event
function openCloseEventModal() {
    document.getElementById('closeEventModal').classList.add('active');
}
function closeCloseEventModal() {
    document.getElementById('closeEventModal').classList.remove('active');
}

// Payment Modal Variables
let currentReservationId = '';
let currentAmountDue = 0;
let currentTotalAmount = 0;
let paymentSplitCount = 0;

function openPaymentModal(reservationId, totalAmount, amountDue) {
    currentReservationId = reservationId;
    currentTotalAmount = parseFloat(totalAmount);
    currentAmountDue = parseFloat(amountDue);
    if (isNaN(currentAmountDue) || currentAmountDue <= 0) {
        alert("No amount due for this reservation.");
        return;
    }
    document.getElementById('paymentReservationId').value = reservationId;
    document.getElementById('modalTotalAmountDue').innerHTML = currentAmountDue.toFixed(2) + ' <?php echo $currencySymbol; ?>';
    document.getElementById('totalAmountDue').value = currentAmountDue;
    document.getElementById('paymentSplits').innerHTML = '';
    paymentSplitCount = 0;
    addPaymentSplit();
    updateRemainingAmount();
    document.getElementById('paymentModal').style.display = 'flex';
}

function closePaymentModal() {
    document.getElementById('paymentModal').style.display = 'none';
}

function addPaymentSplit() {
    const container = document.getElementById('paymentSplits');
    const splitIndex = paymentSplitCount;
    const splitDiv = document.createElement('div');
    splitDiv.className = 'payment-split-item';
    splitDiv.setAttribute('data-split-index', splitIndex);
    splitDiv.innerHTML = `
        <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; margin-bottom: 10px; align-items: end;">
            <div class="form-group"><label>Payment Method</label><select class="payment-method" onchange="togglePaymentFields(this, ${splitIndex})"><option value="">Select</option><option value="cash">Cash</option><option value="cliq">CliQ</option><option value="visa">Visa</option></select></div>
            <div class="form-group"><label>Amount (<?php echo $currencySymbol; ?>)</label><input type="number" class="payment-amount" step="0.01" placeholder="0.00" onkeyup="updateRemainingAmount()" onchange="validateSplitAmount(this)"></div>
            <div class="form-group"><label>&nbsp;</label><button type="button" class="btn btn-danger btn-sm" onclick="removePaymentSplit(this)">Remove</button></div>
        </div>
        <div class="payment-fields" style="display: none;"></div>
    `;
    container.appendChild(splitDiv);
    paymentSplitCount++;
}

function removePaymentSplit(button) {
    const splitItem = button.closest('.payment-split-item');
    if (document.querySelectorAll('.payment-split-item').length > 1) {
        splitItem.remove();
        updateRemainingAmount();
    } else {
        alert('You need at least one payment method');
    }
}

function togglePaymentFields(selectElement, index) {
    const method = selectElement.value;
    const paymentFields = selectElement.closest('.payment-split-item').querySelector('.payment-fields');
    if (method === 'cash') {
        paymentFields.innerHTML = `<div class="form-group"><label><i class="bi bi-person"></i> Received By (Staff Name)</label><input type="text" class="received-by" placeholder="Enter staff name" required></div>`;
        paymentFields.style.display = 'block';
    } else if (method === 'cliq') {
        paymentFields.innerHTML = `<div class="form-group"><label><i class="bi bi-image"></i> Upload Screenshot (Optional)</label><input type="file" class="proof-file" accept="image/*" onchange="previewImage(this)"><div class="cliq-preview"></div></div><div class="form-group"><label><i class="bi bi-pencil"></i> Or Enter Reference Number / Transaction ID</label><input type="text" class="proof-text" placeholder="Enter CliQ reference number or transaction ID"><small style="color: #64748b;">You can either upload a screenshot OR enter the reference number</small></div>`;
        paymentFields.style.display = 'block';
    } else if (method === 'visa') {
        paymentFields.innerHTML = `<div class="form-group"><label><i class="bi bi-receipt"></i> Receipt ID / Transaction ID</label><input type="text" class="receipt-id" placeholder="Enter Visa receipt ID" required><small style="color: #64748b;">Enter the receipt number from the Visa transaction</small></div>`;
        paymentFields.style.display = 'block';
    } else {
        paymentFields.style.display = 'none';
        paymentFields.innerHTML = '';
    }
}

function previewImage(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        const previewDiv = input.parentElement.querySelector('.cliq-preview');
        reader.onload = function(e) {
            previewDiv.innerHTML = `<img src="${e.target.result}" alt="Preview" style="max-width: 100px; max-height: 100px; border-radius: 8px; margin-top: 10px;"><br><small>Preview loaded</small>`;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function updateRemainingAmount() {
    let totalPaid = 0;
    const amounts = document.querySelectorAll('.payment-amount');
    amounts.forEach(amount => { const val = parseFloat(amount.value); if (!isNaN(val)) totalPaid += val; });
    totalPaid = Math.round(totalPaid * 100) / 100;
    const remaining = Math.round((currentAmountDue - totalPaid) * 100) / 100;
    const remainingElement = document.getElementById('remainingAmount');
    if (remainingElement) {
        remainingElement.textContent = remaining.toFixed(2);
        if (remaining < -0.01) {
            remainingElement.style.color = '#ef4444';
            document.getElementById('remainingAmountDisplay').style.background = '#fee2e2';
        } else if (remaining === 0) {
            remainingElement.style.color = '#10b981';
            document.getElementById('remainingAmountDisplay').style.background = '#d1fae5';
        } else {
            remainingElement.style.color = '#f59e0b';
            document.getElementById('remainingAmountDisplay').style.background = '#fef3c7';
        }
        const processBtn = document.querySelector('.modal-buttons .btn-success');
        if (processBtn) {
            if (remaining < -0.01) {
                processBtn.disabled = true;
                processBtn.style.opacity = '0.5';
            } else {
                processBtn.disabled = false;
                processBtn.style.opacity = '1';
            }
        }
    }
}

function validateSplitAmount(input) {
    let value = parseFloat(input.value);
    if (isNaN(value)) value = 0;
    let otherTotal = 0;
    const allAmounts = document.querySelectorAll('.payment-amount');
    allAmounts.forEach(amount => { if (amount !== input) { otherTotal += parseFloat(amount.value) || 0; } });
    const maxAllowed = currentAmountDue - otherTotal;
    if (value > maxAllowed + 0.01) {
        alert(`Maximum allowed for this split is ${maxAllowed.toFixed(2)} (remaining amount due)`);
        input.value = maxAllowed.toFixed(2);
        updateRemainingAmount();
    }
}

async function processSplitPayments() {
    const splits = [];
    const splitItems = document.querySelectorAll('.payment-split-item');
    let totalAmount = 0;
    const formData = new FormData();
    formData.append('reservation_id', currentReservationId);
    for (let i = 0; i < splitItems.length; i++) {
        const item = splitItems[i];
        const method = item.querySelector('.payment-method').value;
        const amount = parseFloat(item.querySelector('.payment-amount').value);
        if (!method || isNaN(amount) || amount <= 0) { alert('Please fill all payment details'); return; }
        totalAmount += amount;
        const splitData = { method: method, amount: amount };
        if (method === 'cash') {
            const receivedBy = item.querySelector('.received-by')?.value;
            if (!receivedBy) { alert('Please enter who received the cash'); return; }
            splitData.received_by = receivedBy;
        } else if (method === 'cliq') {
            const fileInput = item.querySelector('.proof-file');
            if (fileInput && fileInput.files[0]) { formData.append('proof_' + i, fileInput.files[0]); }
            const proofText = item.querySelector('.proof-text')?.value;
            if (proofText) { splitData.proof_text = proofText; }
        } else if (method === 'visa') {
            const receiptId = item.querySelector('.receipt-id')?.value;
            if (!receiptId) { alert('Please enter receipt ID'); return; }
            splitData.receipt_id = receiptId;
        }
        splits.push(splitData);
    }
    totalAmount = Math.round(totalAmount * 100) / 100;
    if (Math.abs(totalAmount - currentAmountDue) > 0.02) {
        alert(`Total (${totalAmount.toFixed(2)}) does not match amount due (${currentAmountDue.toFixed(2)})`);
        return;
    }
    formData.append('splits', JSON.stringify(splits));
    showLoading('Processing payment...');
    try {
        const response = await fetch('process_split_payment.php', { method: 'POST', body: formData });
        const data = await response.json();
        hideLoading();
        if (data.success) {
            showNotification('Payment processed successfully!', 'success');
            closePaymentModal();
            setTimeout(() => location.reload(), 1500);
        } else {
            showNotification('Error: ' + (data.error || 'Payment failed'), 'error');
        }
    } catch (error) {
        hideLoading();
        showNotification('Error: ' + error.message, 'error');
    }
}

function deleteReservation(reservationId, element) {
    const password = prompt('⚠️ SECURITY VERIFICATION REQUIRED\n\nEnter admin password to delete this reservation:\n(Default: AdminDelete2026)');
    if (password === null) return;
    if (password !== 'AdminDelete2026') { showNotification('Invalid password!', 'error'); return; }
    showLoading('Deleting reservation...');
    fetch('delete_reservation.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reservation_id: reservationId, password: password })
    })
    .then(response => response.json())
    .then(data => {
        hideLoading();
        if (data.success) {
            const row = element.closest('tr');
            row.style.transition = 'all 0.3s ease';
            row.style.opacity = '0';
            row.style.transform = 'translateX(-20px)';
            setTimeout(() => { row.remove(); showNotification('Reservation deleted successfully!', 'success'); setTimeout(() => location.reload(), 1000); }, 300);
        } else {
            showNotification(data.error, 'error');
        }
    })
    .catch(error => { hideLoading(); showNotification('Error: ' + error.message, 'error'); });
}

let soundEnabled = localStorage.getItem('soundEnabled') === 'true';
let audioContext = null;
function initAudio() {
    if (audioContext) return;
    try { audioContext = new(window.AudioContext || window.webkitAudioContext)(); }
    catch (e) { console.log('Web Audio not supported'); }
}
function playNotificationSound() {
    if (!soundEnabled) return;
    initAudio();
    if (!audioContext) return;
    try {
        if (audioContext.state === 'suspended') audioContext.resume();
        const now = audioContext.currentTime;
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        oscillator.frequency.value = 880;
        gainNode.gain.value = 0.15;
        oscillator.start();
        gainNode.gain.exponentialRampToValueAtTime(0.00001, now + 0.3);
        oscillator.stop(now + 0.3);
    } catch (e) { console.log('Sound error:', e); }
}
document.addEventListener('click', function initAudioOnClick() {
    initAudio();
    if (audioContext && audioContext.state === 'suspended') audioContext.resume();
    document.removeEventListener('click', initAudioOnClick);
});
function updateSoundButton() {
    const soundBtn = document.getElementById('soundToggle');
    if (soundBtn) {
        soundBtn.innerHTML = soundEnabled ? '<i class="bi bi-volume-up-fill"></i> Sound On' : '<i class="bi bi-volume-mute-fill"></i> Sound Off';
        soundBtn.style.background = soundEnabled ? '#10b981' : '#64748b';
    }
}
function toggleSound() {
    soundEnabled = !soundEnabled;
    localStorage.setItem('soundEnabled', soundEnabled);
    updateSoundButton();
    showNotification(`Sound ${soundEnabled ? 'enabled' : 'disabled'}`, 'info');
}
document.addEventListener('DOMContentLoaded', function() { updateSoundButton(); });

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = 'sound-notification';
    notification.style.background = type === 'success' ? '#10b981' : (type === 'error' ? '#ef4444' : '#3b82f6');
    notification.innerHTML = `<span>${type === 'success' ? '✓' : (type === 'error' ? '✗' : 'ℹ')}</span><span>${message}</span>`;
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.style.animation = 'fadeOut 0.5s ease forwards';
        setTimeout(() => notification.remove(), 500);
    }, 3000);
}

function checkNewReservations() {
    fetch('check_new_reservations.php')
        .then(response => response.json())
        .then(data => { if (data.new_count > 0) { playNotificationSound(); showNotification(`${data.new_count} new reservation(s) just arrived!`, 'success'); } })
        .catch(error => console.log('Error checking new reservations:', error));
}

function applyFilters() {
    const search = document.getElementById('search').value;
    const status = document.getElementById('statusFilter').value;
    window.location.href = `dashboard.php?search=${encodeURIComponent(search)}&status=${status}&lang=<?php echo getCurrentLanguage(); ?>`;
}
function setLanguage(lang) {
    const url = new URL(window.location.href);
    url.searchParams.set('lang', lang);
    window.location.href = url.toString();
}
function openExportModal() { document.getElementById('exportModal').style.display = 'flex'; }
function closeExportModal() { document.getElementById('exportModal').style.display = 'none'; }
const darkModeToggle = document.getElementById('darkModeToggle');
const isDarkMode = localStorage.getItem('darkMode') === 'true';
if (isDarkMode) { document.body.classList.add('dark-mode'); darkModeToggle.innerHTML = '<i class="bi bi-sun-fill"></i>'; }
darkModeToggle.addEventListener('click', () => {
    document.body.classList.toggle('dark-mode');
    const isDark = document.body.classList.contains('dark-mode');
    localStorage.setItem('darkMode', isDark);
    darkModeToggle.innerHTML = isDark ? '<i class="bi bi-sun-fill"></i>' : '<i class="bi bi-moon-fill"></i>';
});
document.addEventListener('DOMContentLoaded', function() {
    const soundBtn = document.getElementById('soundToggle');
    if (soundBtn) { soundBtn.innerHTML = soundEnabled ? '<i class="bi bi-volume-up-fill"></i> Sound On' : '<i class="bi bi-volume-mute-fill"></i> Sound Off'; soundBtn.style.background = soundEnabled ? '#10b981' : '#64748b'; }
    setInterval(checkNewReservations, 30000);
});
document.getElementById('search')?.addEventListener('keypress', function(e) { if (e.key === 'Enter') applyFilters(); });
window.onclick = function(event) {
    const exportModal = document.getElementById('exportModal');
    const paymentModal = document.getElementById('paymentModal');
    const tableModal = document.getElementById('tableModal');
    const closeEventModal = document.getElementById('closeEventModal');
    if (event.target === exportModal) closeExportModal();
    if (event.target === paymentModal) closePaymentModal();
    if (event.target === tableModal) closeTableModal();
    if (event.target === closeEventModal) closeCloseEventModal();
}
</script>
</body>
</html>