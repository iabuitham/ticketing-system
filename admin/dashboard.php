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

// Get event-specific ticket prices
$event_ticket_prices = $_SESSION['event_ticket_prices'] ?? null;

if (!$event_ticket_prices && $selected_event_id > 0) {
 $stmt = $conn->prepare("SELECT ticket_price_adult, ticket_price_teen, ticket_price_kid FROM event_settings WHERE id = ?");
 $stmt->bind_param("i", $selected_event_id);
 $stmt->execute();
 $event_prices = $stmt->get_result()->fetch_assoc();
 $stmt->close();

 if ($event_prices) {
  $event_ticket_prices = [
   'adult' => $event_prices['ticket_price_adult'],
   'teen' => $event_prices['ticket_price_teen'],
   'kid' => $event_prices['ticket_price_kid']
  ];
  $_SESSION['event_ticket_prices'] = $event_ticket_prices;
 }
}

// Use event-specific prices or fall back to system settings
$adultPrice = $event_ticket_prices['adult'] ?? getSetting('ticket_price_adult', 10);
$teenPrice = $event_ticket_prices['teen'] ?? getSetting('ticket_price_teen', 10);
$kidPrice = $event_ticket_prices['kid'] ?? getSetting('ticket_price_kid', 0);

// Get filters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? sanitizeInput($_GET['search']) : '';

// Build query with total paid from split_payments
$query = "SELECT r.*, 
  COALESCE((SELECT SUM(amount) FROM split_payments WHERE reservation_id = r.reservation_id), 0) as total_paid
  FROM reservations r WHERE 1=1";
$params = [];
$types = "";

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

// Calculate actual amount due for each reservation
foreach ($reservations as &$res) {
 // Get total paid from split_payments
 $stmt_paid = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_paid FROM split_payments WHERE reservation_id = ?");
 $stmt_paid->bind_param("s", $res['reservation_id']);
 $stmt_paid->execute();
 $paidResult = $stmt_paid->get_result()->fetch_assoc();
 $stmt_paid->close();

 $totalPaid = floatval($paidResult['total_paid']);
 $totalAmount = floatval($res['total_amount']);
 $res['actual_amount_due'] = max(0, $totalAmount - $totalPaid);
 $res['total_paid'] = $totalPaid;
}
unset($res);

// Get statistics
$statsResult = $conn->query("SELECT 
 COUNT(*) as total,
 SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
 SUM(CASE WHEN status = 'registered' THEN 1 ELSE 0 END) as registered,
 SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid,
 SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
 SUM(additional_amount_due) as total_additional_due
FROM reservations");

$stats = $statsResult->fetch_assoc();
$stats = [
 'total' => $stats['total'] ?? 0,
 'pending' => $stats['pending'] ?? 0,
 'registered' => $stats['registered'] ?? 0,
 'paid' => $stats['paid'] ?? 0,
 'cancelled' => $stats['cancelled'] ?? 0,
 'total_additional_due' => $stats['total_additional_due'] ?? 0
];

// Get attendee stats
$attendeeResult = $conn->query("SELECT 
 SUM(CASE WHEN status = 'paid' THEN adults ELSE 0 END) as total_adults,
 SUM(CASE WHEN status = 'paid' THEN teens ELSE 0 END) as total_teens,
 SUM(CASE WHEN status = 'paid' THEN kids ELSE 0 END) as total_kids,
 SUM(CASE WHEN status = 'paid' THEN adults + teens + kids ELSE 0 END) as total_attendees,
 SUM(CASE WHEN status IN ('pending', 'registered') THEN adults + teens + kids ELSE 0 END) as pending_attendees
FROM reservations");

$attendeeStats = $attendeeResult->fetch_assoc();
$attendeeStats = [
 'total_adults' => $attendeeStats['total_adults'] ?? 0,
 'total_teens' => $attendeeStats['total_teens'] ?? 0,
 'total_kids' => $attendeeStats['total_kids'] ?? 0,
 'total_attendees' => $attendeeStats['total_attendees'] ?? 0,
 'pending_attendees' => $attendeeStats['pending_attendees'] ?? 0
];

// Get revenue by payment method from split_payments
$revenueResult = $conn->query("SELECT 
    SUM(CASE WHEN payment_method = 'cash' THEN amount ELSE 0 END) as cash,
    SUM(CASE WHEN payment_method = 'cliq' THEN amount ELSE 0 END) as cliq,
    SUM(CASE WHEN payment_method = 'visa' THEN amount ELSE 0 END) as visa,
    SUM(amount) as total
FROM split_payments");

$revenue = $revenueResult->fetch_assoc();
$revenue = [
    'cash' => $revenue['cash'] ?? 0,
    'cliq' => $revenue['cliq'] ?? 0,
    'visa' => $revenue['visa'] ?? 0,
    'total' => $revenue['total'] ?? 0
];

// Get cancelled revenue
$cancelledResult = $conn->query("SELECT SUM(total_amount) as total FROM reservations WHERE status = 'cancelled' AND total_amount > 0");
$cancelledRow = $cancelledResult->fetch_assoc();
$cancelledRevenue = $cancelledRow['total'] ?? 0;

// Get refunded/credited amounts from credit_notes that have been processed
$refundResult = $conn->query("SELECT SUM(amount) as total FROM credit_notes WHERE status = 'processed'");
$refundRow = $refundResult->fetch_assoc();
$totalRefunded = $refundRow['total'] ?? 0;

// Calculate net revenue (total payments minus refunds)
$netRevenue = max(0, $revenue['total'] - $totalRefunded);

$currency = getSetting('currency', 'JOD');
$currencySymbol = getCurrencySymbol();
$siteName = getSetting('site_name', 'Ticketing System');
$themeColor = getSetting('theme_color', '#4f46e5');

// Get today's stats
$todayResult = $conn->query("SELECT COUNT(*) as count FROM reservations WHERE DATE(created_at) = CURDATE()");
$todayCount = $todayResult->fetch_assoc()['count'] ?? 0;

// Check for switch error message
$switch_error = $_SESSION['switch_error'] ?? '';
$switch_error_type = $_SESSION['switch_error_type'] ?? '';
unset($_SESSION['switch_error']);
unset($_SESSION['switch_error_type']);

// Get event count for switch button
$conn_count = getConnection();
$eventCountResult = $conn_count->query("SELECT COUNT(*) as count FROM event_settings WHERE status != 'completed'");
$activeEventCount = $eventCountResult->fetch_assoc()['count'];
$conn_count->close();

$conn->close();
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>" dir="<?php echo getDirection(); ?>">
<head>
 <meta charset="UTF-8">
 <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
 <title><?php echo t('dashboard'); ?> - <?php echo htmlspecialchars($siteName); ?></title>
 <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
 <style>
  * {
   margin: 0;
   padding: 0;
   box-sizing: border-box;
  }

  body {
   font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
   background: #f0f2f5;
   padding: 20px;
   transition: background 0.3s ease;
  }

  body.dark-mode {
   background: #0f172a;
   color: #e2e8f0;
  }

  .container {
   max-width: 1400px;
   margin: 0 auto;
  }

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
   box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  }

  body.dark-mode .navbar {
   background: #1e293b;
  }

  .navbar h1 {
   font-size: 1.5rem;
   display: flex;
   align-items: center;
   gap: 8px;
  }

  .event-info {
   display: flex;
   align-items: center;
   gap: 10px;
   background: #f1f5f9;
   padding: 8px 16px;
   border-radius: 40px;
  }

  body.dark-mode .event-info {
   background: #0f172a;
  }

  .nav-links {
   display: flex;
   gap: 20px;
   align-items: center;
   flex-wrap: wrap;
  }

  .header-controls {
   display: flex;
   align-items: center;
   gap: 15px;
   flex-wrap: wrap;
  }

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

  .dark-mode-toggle:hover {
   background: rgba(0, 0, 0, 0.1);
  }

  body.dark-mode .dark-mode-toggle:hover {
   background: rgba(255, 255, 255, 0.1);
  }

  .language-switcher {
   display: flex;
   gap: 5px;
   background: #f1f5f9;
   padding: 4px;
   border-radius: 40px;
  }

  body.dark-mode .language-switcher {
   background: #334155;
  }

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

  body.dark-mode .language-switcher button {
   color: #94a3b8;
  }

  .language-switcher button.active {
   background: <?php echo $themeColor; ?>;
   color: white;
  }

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

  .btn-logout:hover {
   background: #dc2626;
  }

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
   box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
   transition: all 0.3s ease;
  }

  body.dark-mode .stat-card {
   background: #1e293b;
  }

  .stat-card.primary {
   background: linear-gradient(135deg, <?php echo $themeColor; ?>, <?php echo $themeColor; ?>cc);
   color: white;
  }

  .stat-card.success {
   background: linear-gradient(135deg, #10b981, #059669);
   color: white;
  }

  .stat-card.warning {
   background: linear-gradient(135deg, #f59e0b, #d97706);
   color: white;
  }

  .stat-card.info {
   background: linear-gradient(135deg, #0ea5e9, #0284c7);
   color: white;
  }

  .stat-number {
   font-size: 32px;
   font-weight: bold;
   margin-bottom: 8px;
  }

  .stat-label {
   font-size: 14px;
   opacity: 0.9;
   margin-bottom: 12px;
   display: flex;
   align-items: center;
   gap: 6px;
  }

  .stat-details {
   margin-top: 12px;
   padding-top: 12px;
   border-top: 1px solid rgba(255, 255, 255, 0.2);
   display: flex;
   flex-direction: column;
   gap: 8px;
  }

  .detail-item {
   display: flex;
   justify-content: space-between;
   align-items: center;
   font-size: 13px;
  }

  .detail-item i {
   margin-right: 6px;
  }

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
   box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  }

  body.dark-mode .filters-bar {
   background: #1e293b;
  }

  .search-box {
   display: flex;
   gap: 10px;
   flex-wrap: wrap;
   position: relative;
  }

  .search-box input,
  .search-box select {
   padding: 8px 16px;
   border: 1px solid #e2e8f0;
   border-radius: 40px;
   font-size: 14px;
   background: white;
  }

  .search-box input {
   padding-left: 35px;
  }

  .search-icon {
   position: absolute;
   left: 12px;
   top: 50%;
   transform: translateY(-50%);
   color: #94a3b8;
   pointer-events: none;
  }

  body.dark-mode .search-box input,
  body.dark-mode .search-box select {
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

  .btn-primary {
   background: <?php echo $themeColor; ?>;
   color: white;
  }

  .btn-primary:hover {
   opacity: 0.9;
   transform: translateY(-1px);
  }

  .btn-success {
   background: #10b981;
   color: white;
  }

  .btn-success:hover {
   background: #059669;
  }

  .btn-secondary {
   background: #64748b;
   color: white;
  }

  .btn-secondary:hover {
   background: #475569;
  }

  .btn-warning {
   background: #f59e0b;
   color: white;
  }

  .btn-info {
   background: #0ea5e9;
   color: white;
  }

  .btn-danger {
   background: #ef4444;
   color: white;
  }

  .btn-danger:hover {
   background: #dc2626;
  }

  .btn-sm {
   padding: 6px 12px;
   font-size: 12px;
  }

  .table-container {
   background: white;
   border-radius: 20px;
   overflow-x: auto;
   box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
  }

  body.dark-mode .table-container {
   background: #1e293b;
  }

  table {
   width: 100%;
   border-collapse: collapse;
   min-width: 900px;
  }

  th,
  td {
   padding: 14px 16px;
   text-align: left;
   border-bottom: 1px solid #e2e8f0;
   vertical-align: middle;
  }

  th {
   background: #f8fafc;
   font-weight: 600;
  }

  body.dark-mode th {
   background: #0f172a;
   color: #94a3b8;
   border-color: #334155;
  }

  body.dark-mode td {
   border-color: #334155;
   color: #cbd5e1;
  }

  .status-badge {
   display: inline-flex;
   align-items: center;
   gap: 6px;
   padding: 4px 12px;
   border-radius: 20px;
   font-size: 12px;
   font-weight: 600;
  }

  .status-pending {
   background: #fef3c7;
   color: #92400e;
  }

  .status-registered {
   background: #dbeafe;
   color: #1e40af;
  }

  .status-paid {
   background: #d1fae5;
   color: #065f46;
  }

  .status-cancelled {
   background: #fee2e2;
   color: #991b1b;
  }

  body.dark-mode .status-pending {
   background: #451a03;
   color: #fde68a;
  }

  body.dark-mode .status-registered {
   background: #1e3a5f;
   color: #93c5fd;
  }

  body.dark-mode .status-paid {
   background: #064e3b;
   color: #6ee7b7;
  }

  body.dark-mode .status-cancelled {
   background: #7f1d1d;
   color: #fca5a5;
  }

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
  }

  body.dark-mode .badge-table {
   background: #334155;
   color: #e2e8f0;
  }

  .guest-badge {
   font-weight: 600;
  }

  .guest-badge small {
   font-size: 11px;
   font-weight: normal;
   color: #64748b;
  }

  body.dark-mode .guest-badge small {
   color: #94a3b8;
  }

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

  body.dark-mode .amount-due-badge {
   background: #451a03;
   color: #fde68a;
  }

  .text-muted {
   color: #94a3b8;
  }

  .btn-group {
   display: flex;
   gap: 6px;
   flex-wrap: wrap;
  }

  .actions {
   white-space: nowrap;
  }

  .loading-overlay {
   position: fixed;
   top: 0;
   left: 0;
   width: 100%;
   height: 100%;
   background: rgba(0, 0, 0, 0.6);
   backdrop-filter: blur(3px);
   display: none;
   justify-content: center;
   align-items: center;
   z-index: 99999;
  }

  .loading-overlay.active {
   display: flex;
  }

  .loading-spinner {
   width: 60px;
   height: 60px;
   border: 4px solid rgba(255, 255, 255, 0.3);
   border-top: 4px solid <?php echo $themeColor; ?>;
   border-radius: 50%;
   animation: spin 1s linear infinite;
  }

  @keyframes spin {
   0% {
     transform: rotate(0deg);
   }

   100% {
     transform: rotate(360deg);
   }
  }

  .loading-text {
   color: white;
   margin-top: 15px;
   font-size: 14px;
   text-align: center;
  }

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
   box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
  }

  @keyframes slideInRight {
   from {
     opacity: 0;
     transform: translateX(100%);
   }

   to {
     opacity: 1;
     transform: translateX(0);
   }
  }

  .sound-notification.fade-out {
   animation: fadeOut 0.5s ease forwards;
  }

  @keyframes fadeOut {
   to {
     opacity: 0;
     transform: translateX(100%);
   }
  }

  .modal-overlay {
   display: none;
   position: fixed;
   top: 0;
   left: 0;
   width: 100%;
   height: 100%;
   background: rgba(0, 0, 0, 0.5);
   z-index: 10000;
   align-items: center;
   justify-content: center;
  }

  body.dark-mode .modal-overlay {
   background: rgba(0, 0, 0, 0.7);
  }

  .modal-overlay.active {
   display: flex;
  }

  .modal-container {
   background: white;
   border-radius: 24px;
   box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
   max-width: 600px;
   width: 90%;
   max-height: 90vh;
   overflow-y: auto;
   animation: modalSlideIn 0.3s ease;
  }

  @keyframes modalSlideIn {
   from {
     opacity: 0;
     transform: translateY(-50px);
   }

   to {
     opacity: 1;
     transform: translateY(0);
   }
  }

  body.dark-mode .modal-container {
   background: #1e293b;
  }

  .modal-header {
   padding: 20px 24px;
   background: <?php echo $themeColor; ?>;
   color: white;
   border-radius: 24px 24px 0 0;
   display: flex;
   justify-content: space-between;
   align-items: center;
  }

  .modal-header h3 {
   margin: 0;
   font-size: 1.25rem;
   display: flex;
   align-items: center;
   gap: 8px;
  }

  .modal-close {
   background: none;
   border: none;
   font-size: 28px;
   cursor: pointer;
   color: white;
   line-height: 1;
   transition: transform 0.2s;
  }

  .modal-close:hover {
   transform: scale(1.1);
  }

  .modal-body {
   padding: 24px;
  }

  .form-group {
   margin-bottom: 15px;
  }

  .form-group label {
   display: block;
   margin-bottom: 8px;
   font-weight: 600;
   color: #334155;
   font-size: 14px;
  }

  body.dark-mode .form-group label {
   color: #cbd5e1;
  }

  .form-group input,
  .form-group select {
   width: 100%;
   padding: 10px;
   border: 1px solid #cbd5e1;
   border-radius: 8px;
   font-size: 14px;
  }

  body.dark-mode .form-group input,
  body.dark-mode .form-group select {
   background: #0f172a;
   border-color: #334155;
   color: #e2e8f0;
  }

  .amount-due-display {
   background: #f1f5f9;
   padding: 15px;
   border-radius: 12px;
   text-align: center;
   margin-bottom: 20px;
  }

  body.dark-mode .amount-due-display {
   background: #0f172a;
  }

  .amount-due-display .label {
   font-size: 14px;
   color: #64748b;
   margin-bottom: 5px;
  }

  .amount-due-display .amount {
   font-size: 28px;
   font-weight: bold;
   color: <?php echo $themeColor; ?>;
  }

  .payment-split-item {
   background: #f8fafc;
   padding: 15px;
   border-radius: 12px;
   margin-bottom: 15px;
   border: 1px solid #e2e8f0;
  }

  body.dark-mode .payment-split-item {
   background: #0f172a;
   border-color: #334155;
  }

  .cliq-preview {
   margin-top: 10px;
  }

  .cliq-preview img {
   max-width: 100px;
   max-height: 100px;
   border-radius: 8px;
  }

  .modal-buttons {
   display: flex;
   justify-content: flex-end;
   gap: 12px;
   margin-top: 20px;
   padding-top: 20px;
   border-top: 1px solid #e2e8f0;
  }

  body.dark-mode .modal-buttons {
   border-top-color: #334155;
  }

  [dir="rtl"] {
   text-align: right;
  }

  [dir="rtl"] .actions,
  [dir="rtl"] .search-box {
   direction: rtl;
  }

  [dir="rtl"] .detail-item {
   flex-direction: row-reverse;
  }

  [dir="rtl"] .btn i {
   margin-left: 6px;
   margin-right: 0;
  }

  [dir="rtl"] .search-icon {
   left: auto;
   right: 12px;
  }

  [dir="rtl"] .search-box input {
   padding-left: 16px;
   padding-right: 35px;
  }

  @media (max-width: 1024px) {
   .stats-grid {
     grid-template-columns: repeat(2, 1fr);
   }
  }

  @media (max-width: 768px) {
   .stats-grid {
     grid-template-columns: 1fr;
   }

   .filters-bar {
     flex-direction: column;
   }

   .search-box {
     width: 100%;
   }

   .search-box input,
   .search-box select {
     flex: 1;
   }

   .navbar {
     flex-direction: column;
     text-align: center;
   }

   .nav-links {
     justify-content: center;
   }

   .header-controls {
     justify-content: center;
   }

   .table-container {
     overflow-x: auto;
   }

   table {
     min-width: 850px;
   }

   .btn-group {
     flex-wrap: nowrap;
   }
  }
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
             <a href="logout.php?switch_event=1" class="btn btn-sm btn-secondary" style="padding: 4px 8px;" title="Switch to another event">
                     <i class="bi bi-arrow-repeat"></i> Switch
             </a>
        <?php else: ?>
             <button class="btn btn-sm btn-secondary" style="padding: 4px 8px; opacity: 0.5; cursor: not-allowed;" disabled title="Only one event available">
                     <i class="bi bi-arrow-repeat"></i> Switch
             </button>
        <?php endif; ?>
     </div>
     <div class="header-controls">
        <button id="darkModeToggle" class="dark-mode-toggle"><i class="bi bi-moon-fill"></i></button>
        <div class="language-switcher">
             <button onclick="setLanguage('en')" class="<?php echo $lang == 'en' ? 'active' : ''; ?>">EN</button>
             <button onclick="setLanguage('ar')" class="<?php echo $lang == 'ar' ? 'active' : ''; ?>">AR</button>
        </div>
        <button id="soundToggle" onclick="toggleSound()" class="btn btn-secondary" style="background: #10b981;"><i class="bi bi-volume-up-fill"></i> Sound On</button>
        <span><i class="bi bi-person-circle"></i> <?php echo t('welcome'); ?>, <?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
        <a href="logout.php" class="btn-logout"><i class="bi bi-box-arrow-right"></i> <?php echo t('logout'); ?></a>
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
     <div class="stat-number"><?php echo number_format(floatval($attendeeStats['total_attendees'])); ?></div>
     <div class="stat-label"><i class="bi bi-people-fill"></i> <?php echo t('total_attendees'); ?></div>
     <div class="stat-details">
        <div class="detail-item">
             <span><i class="bi bi-gender-male"></i> <?php echo t('adults'); ?></span>
             <span><?php echo number_format(floatval($attendeeStats['total_adults'])); ?></span>
        </div>
        <div class="detail-item">
             <span><i class="bi bi-gender-female"></i> <?php echo t('teens'); ?></span>
             <span><?php echo number_format(floatval($attendeeStats['total_teens'])); ?></span>
        </div>
        <div class="detail-item">
             <span><i class="bi bi-egg-fried"></i> <?php echo t('kids'); ?></span>
             <span><?php echo number_format(floatval($attendeeStats['total_kids'])); ?></span>
        </div>
     </div>
   </div>

   <div class="stat-card warning">
     <div class="stat-number"><?php echo number_format(floatval($attendeeStats['pending_attendees'])); ?></div>
     <div class="stat-label"><i class="bi bi-hourglass-split"></i> <?php echo t('pending_attendees'); ?></div>
     <div class="stat-details">
        <div class="detail-item">
             <span><i class="bi bi-currency-dollar"></i> <?php echo t('amount_due'); ?></span>
             <span><?php echo number_format(floatval($stats['total_additional_due']), 2); ?> <?php echo $currencySymbol; ?></span>
        </div>
        <div class="detail-item">
             <span><i class="bi bi-clock-history"></i> <?php echo t('pending'); ?></span>
             <span><?php echo intval($stats['pending']) + intval($stats['registered']); ?> <?php echo t('reservations'); ?></span>
        </div>
     </div>
   </div>

<div class="stat-card success">
    <div class="stat-number"><?php echo number_format(floatval($netRevenue), 2); ?> <?php echo $currencySymbol; ?></div>
    <div class="stat-label"><i class="bi bi-graph-up"></i> <?php echo t('net_revenue'); ?></div>
    <div class="stat-details">
        <div class="detail-item">
            <span><i class="bi bi-cash-stack"></i> <?php echo t('cash'); ?></span>
            <span><?php echo number_format(floatval($revenue['cash']), 2); ?> <?php echo $currencySymbol; ?></span>
        </div>
        <div class="detail-item">
            <span><i class="bi bi-phone"></i> <?php echo t('cliq'); ?></span>
            <span><?php echo number_format(floatval($revenue['cliq']), 2); ?> <?php echo $currencySymbol; ?></span>
        </div>
        <div class="detail-item">
            <span><i class="bi bi-credit-card"></i> <?php echo t('visa'); ?></span>
            <span><?php echo number_format(floatval($revenue['visa']), 2); ?> <?php echo $currencySymbol; ?></span>
        </div>
        <div class="detail-item">
            <span><i class="bi bi-receipt"></i> <?php echo t('total_payments'); ?></span>
            <span><?php echo number_format(floatval($revenue['total']), 2); ?> <?php echo $currencySymbol; ?></span>
        </div>
        <div class="detail-item">
            <span><i class="bi bi-arrow-return-left"></i> <?php echo t('refunds'); ?></span>
            <span class="detail-value" style="color: #f59e0b;">- <?php echo number_format(floatval($totalRefunded), 2); ?> <?php echo $currencySymbol; ?></span>
        </div>
        <div class="detail-item" style="border-top: 1px solid rgba(255,255,255,0.2); margin-top: 5px; padding-top: 8px;">
            <span><strong><i class="bi bi-calculator"></i> <?php echo t('net_revenue'); ?></strong></span>
            <span><strong><?php echo number_format(floatval($netRevenue), 2); ?> <?php echo $currencySymbol; ?></strong></span>
        </div>
        <div class="detail-item">
            <span><i class="bi bi-x-circle"></i> <?php echo t('cancelled'); ?></span>
            <span class="detail-value" style="color: #fecaca;">- <?php echo number_format(floatval($cancelledRevenue), 2); ?> <?php echo $currencySymbol; ?></span>
        </div>
    </div>
</div>

   <div class="stat-card info">
     <div class="stat-number"><?php echo intval($stats['total']); ?></div>
     <div class="stat-label"><i class="bi bi-calendar-check"></i> <?php echo t('total_reservations'); ?></div>
     <div class="stat-details">
        <div class="detail-item">
             <span><i class="bi bi-hourglass-top"></i> <?php echo t('pending'); ?></span>
             <span><?php echo intval($stats['pending']); ?></span>
        </div>
        <div class="detail-item">
             <span><i class="bi bi-check-circle"></i> <?php echo t('registered'); ?></span>
             <span><?php echo intval($stats['registered']); ?></span>
        </div>
        <div class="detail-item">
             <span><i class="bi bi-check-circle-fill"></i> <?php echo t('paid'); ?></span>
             <span><?php echo intval($stats['paid']); ?></span>
        </div>
        <div class="detail-item">
             <span><i class="bi bi-slash-circle"></i> <?php echo t('cancelled'); ?></span>
             <span><?php echo intval($stats['cancelled']); ?></span>
        </div>
     </div>
   </div>
  </div>

  <div class="filters-bar">
   <div class="search-box">
     <i class="bi bi-search search-icon"></i>
     <input type="text" id="search" placeholder="<?php echo t('search'); ?>" value="<?php echo htmlspecialchars($search); ?>">
     <select id="statusFilter">
        <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>><?php echo t('all'); ?></option>
        <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>><?php echo t('pending'); ?></option>
        <option value="registered" <?php echo $status_filter == 'registered' ? 'selected' : ''; ?>><?php echo t('registered'); ?></option>
        <option value="paid" <?php echo $status_filter == 'paid' ? 'selected' : ''; ?>><?php echo t('paid'); ?></option>
        <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>><?php echo t('cancelled'); ?></option>
     </select>
     <button onclick="applyFilters()" class="btn btn-primary"><i class="bi bi-funnel"></i> <?php echo t('apply'); ?></button>
     <a href="dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-repeat"></i> <?php echo t('reset'); ?></a>
   </div>
   <div>
     <a href="create_reservation.php" class="btn btn-primary"><i class="bi bi-plus-circle"></i> <?php echo t('new_reservation'); ?></a>
     <a href="bulk_whatsapp.php" class="btn btn-success"><i class="bi bi-whatsapp"></i> <?php echo t('bulk_whatsapp'); ?></a>
     <button onclick="openExportModal()" class="btn btn-info"><i class="bi bi-filetype-csv"></i> <?php echo t('export_csv'); ?></button>
     <a href="print_statement.php" class="btn btn-secondary"><i class="bi bi-printer"></i> <?php echo t('print_statement'); ?></a>
     <a href="manager_report.php" class="btn btn-secondary"><i class="bi bi-bar-chart-steps"></i> <?php echo t('analytics'); ?></a>
     <a href="tables.php" class="btn btn-secondary"><i class="bi bi-grid-3x3-gap-fill"></i> Tables</a>
     <a href="settings.php" class="btn btn-secondary"><i class="bi bi-gear"></i> <?php echo t('system_settings'); ?></a>
     <a href="tickets_dashboard.php" class="btn btn-info"><i class="bi bi-ticket-perforated"></i> Ticket Dashboard</a>
     <?php if ($selected_event_id > 0): ?>
    <a href="close_event.php" class="btn btn-danger"><i class="bi bi-lock"></i> Close Event</a>
    <?php endif; ?>
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
             <th style="min-width: 100px;"><i class="bi bi-currency-dollar"></i> <?php echo t('amount_due'); ?></th>
             <th style="min-width: 120px;"><i class="bi bi-calendar3"></i> <?php echo t('created'); ?></th>
             <th style="min-width: 220px;"><i class="bi bi-gear"></i> <?php echo t('actions'); ?></th>
        </tr>
     </thead>
     <tbody>
        <?php foreach ($reservations as $res):
             $totalGuests = ($res['adults'] ?? 0) + ($res['teens'] ?? 0) + ($res['kids'] ?? 0);
             // Use actual_amount_due that we calculated earlier
             $amountDue = isset($res['actual_amount_due']) ? floatval($res['actual_amount_due']) : 0;

             // Fallback calculation if not set
             if ($amountDue == 0 && isset($res['total_amount']) && isset($res['total_paid'])) {
                     $amountDue = max(0, floatval($res['total_amount']) - floatval($res['total_paid']));
             }
        ?>
             <tr>
                     <td><strong><?php echo htmlspecialchars($res['reservation_id']); ?></strong></td>
                     <td><?php echo htmlspecialchars($res['name']); ?></td>
                     <td><?php echo htmlspecialchars($res['phone']); ?></td>
                     <td style="text-align: center;"><span class="badge-table"><?php echo htmlspecialchars($res['table_id']); ?></span></td>
                     <td>
                                  <span class="guest-badge">
                                                       <?php echo $totalGuests; ?>
                                                       <small>(<?php echo intval($res['adults'] ?? 0); ?>A, <?php echo intval($res['teens'] ?? 0); ?>T, <?php echo intval($res['kids'] ?? 0); ?>K)</small>
                                  </span>
                     </td>
                     <td>
                                  <span class="status-badge status-<?php echo $res['status']; ?>">
                                                       <i class="bi <?php echo $res['status'] == 'paid' ? 'bi-check-circle-fill' : ($res['status'] == 'pending' ? 'bi-hourglass-split' : ($res['status'] == 'registered' ? 'bi-check-circle' : 'bi-slash-circle')); ?>"></i>
                                                       <?php echo ucfirst($res['status']); ?>
                                  </span>
                     </td>
                     <td>
                                  <?php if ($amountDue > 0): ?>
                                                       <span class="amount-due-badge"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo number_format($amountDue, 2); ?> <?php echo $currencySymbol; ?></span>
                                  <?php else: ?>
                                                       <span class="text-muted">-</span>
                                  <?php endif; ?>
                     </td>
                     <td><?php echo date('M d, H:i', strtotime($res['created_at'])); ?></td>
                     <td class="actions">
                                  <div class="btn-group">
                                                       <a href="view_reservation.php?id=<?php echo urlencode($res['reservation_id']); ?>" class="btn btn-sm btn-secondary" title="View">
                                                                                         <i class="bi bi-eye"></i>
                                                       </a>
                                                       <a href="edit_reservation.php?id=<?php echo urlencode($res['reservation_id']); ?>" class="btn btn-sm btn-warning" title="Edit">
                                                                                         <i class="bi bi-pencil"></i>
                                                       </a>
                                                       <a href="/public/reservation_tickets.php?id=<?php echo urlencode($res['reservation_id']); ?>" class="btn btn-sm btn-info" title="View Tickets">
                                                                                         <i class="bi bi-ticket-perforated"></i>
                                                       </a>

                                                       <!-- PAYMENT BUTTON - FIXED -->
                                                       <?php if ($res['status'] != 'cancelled' && $amountDue > 0): ?>
                                                                                         <button onclick="openPaymentModal('<?php echo $res['reservation_id']; ?>', <?php echo floatval($res['total_amount'] ?? 0); ?>, <?php echo $amountDue; ?>)" class="btn btn-sm btn-success" title="Pay">
                                                                                                                                                <i class="bi bi-credit-card"></i> Pay <?php echo number_format($amountDue, 2); ?>
                                                                                         </button>
                                                       <?php endif; ?>

                                                       <button onclick="deleteReservation('<?php echo $res['reservation_id']; ?>', this)" class="btn btn-sm btn-danger" title="Delete">
                                                                                         <i class="bi bi-trash3"></i>
                                                       </button>
                                  </div>
                     </td>
             </tr>
        <?php endforeach; ?>

        <?php if (empty($reservations)): ?>
             <tr>
                     <td colspan="9" style="text-align: center; padding: 60px;">
                                  <i class="bi bi-inbox" style="font-size: 48px; opacity: 0.5;"></i>
                                  <p style="margin-top: 10px;"><?php echo t('no_reservations'); ?></p>
                     </td>
             </tr>
        <?php endif; ?>
     </tbody>
   </table>
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
     <h3><i class="bi bi-filetype-csv"></i> <?php echo t('export_csv'); ?></h3>
     <button onclick="closeExportModal()" class="modal-close">&times;</button>
   </div>
   <div class="modal-body">
     <div style="background: #f1f5f9; border-radius: 16px; padding: 16px; margin-bottom: 20px;">
        <p style="margin: 0; color: #334155;"><i class="bi bi-info-circle"></i> <?php echo t('select_export_options'); ?></p>
     </div>

     <form id="exportForm" method="GET" action="export_csv.php">
        <div style="margin-bottom: 20px;">
             <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #334155; font-size: 14px;"><i class="bi bi-funnel"></i> <?php echo t('filter_by_status'); ?></label>
             <select name="status" style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px; font-size: 14px;">
                     <option value="all"><?php echo t('all'); ?></option>
                     <option value="pending"><?php echo t('pending'); ?></option>
                     <option value="registered"><?php echo t('registered'); ?></option>
                     <option value="paid"><?php echo t('paid'); ?></option>
                     <option value="cancelled"><?php echo t('cancelled'); ?></option>
             </select>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
             <div>
                     <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #334155; font-size: 14px;"><i class="bi bi-calendar"></i> <?php echo t('from_date'); ?></label>
                     <input type="date" name="from" style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px; font-size: 14px;">
             </div>
             <div>
                     <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #334155; font-size: 14px;"><i class="bi bi-calendar"></i> <?php echo t('to_date'); ?></label>
                     <input type="date" name="to" style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px; font-size: 14px;">
             </div>
        </div>

        <div style="background: #e0e7ff; border-radius: 12px; padding: 12px; margin-bottom: 20px;">
             <small style="color: #3730a3;"><i class="bi bi-info-square"></i> 📌 <?php echo t('export_note'); ?></small>
        </div>

        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0;">
             <button type="button" onclick="closeExportModal()" style="padding: 10px 20px; background: #64748b; color: white; border: none; border-radius: 10px; cursor: pointer; font-weight: 500;"><i class="bi bi-x-lg"></i> <?php echo t('cancel'); ?></button>
             <button type="submit" style="padding: 10px 20px; background: <?php echo $themeColor; ?>; color: white; border: none; border-radius: 10px; cursor: pointer; font-weight: 500;"><i class="bi bi-download"></i> <?php echo t('export_csv'); ?></button>
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

  // Payment Modal Variables
  let currentReservationId = '';
  let currentAmountDue = 0;
  let currentTotalAmount = 0;
  let paymentSplitCount = 0;

  function openPaymentModal(reservationId, totalAmount, amountDue) {
   console.log("Opening payment modal for:", reservationId);
   console.log("Total Amount:", totalAmount);
   console.log("Amount Due:", amountDue);

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
        <div class="form-group">
             <label>Payment Method</label>
             <select class="payment-method" onchange="togglePaymentFields(this, ${splitIndex})">
                     <option value="">Select</option>
                     <option value="cash">Cash</option>
                     <option value="cliq">CliQ</option>
                     <option value="visa">Visa</option>
             </select>
        </div>
        <div class="form-group">
             <label>Amount (<?php echo $currencySymbol; ?>)</label>
             <input type="number" class="payment-amount" step="0.01" placeholder="0.00" onkeyup="updateRemainingAmount()">
        </div>
        <div class="form-group">
             <label>&nbsp;</label>
             <button type="button" class="btn btn-danger btn-sm" onclick="removePaymentSplit(this)">Remove</button>
        </div>
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
     paymentFields.innerHTML = `
        <div class="form-group">
             <label><i class="bi bi-person"></i> Received By (Staff Name)</label>
             <input type="text" class="received-by" placeholder="Enter staff name" required>
        </div>
     `;
     paymentFields.style.display = 'block';
   } else if (method === 'cliq') {
     paymentFields.innerHTML = `
        <div class="form-group">
             <label><i class="bi bi-image"></i> Upload Screenshot Evidence</label>
             <input type="file" class="proof-file" accept="image/*" onchange="previewImage(this)">
             <div class="cliq-preview"></div>
             <small style="color: #64748b;">Please upload a screenshot of the CliQ payment confirmation</small>
        </div>
     `;
     paymentFields.style.display = 'block';
   } else if (method === 'visa') {
     paymentFields.innerHTML = `
        <div class="form-group">
             <label><i class="bi bi-receipt"></i> Receipt ID / Transaction ID</label>
             <input type="text" class="receipt-id" placeholder="Enter Visa receipt ID" required>
             <small style="color: #64748b;">Enter the receipt number from the Visa transaction</small>
        </div>
     `;
     paymentFields.style.display = 'block';
   } else {
     paymentFields.style.display = 'none';
     paymentFields.innerHTML = '';
   }

   updateRemainingAmount();
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
   amounts.forEach(amount => {
     const val = parseFloat(amount.value);
     if (!isNaN(val)) totalPaid += val;
   });

   // Round to 2 decimal places
   totalPaid = Math.round(totalPaid * 100) / 100;
   const remaining = Math.round((currentAmountDue - totalPaid) * 100) / 100;

   const remainingElement = document.getElementById('remainingAmount');
   if (remainingElement) {
     remainingElement.textContent = remaining.toFixed(2);
     if (remaining < -0.01) {
        remainingElement.style.color = '#ef4444';
        // Show warning if overpaying
        document.getElementById('remainingAmountDisplay').style.background = '#fee2e2';
     } else if (remaining === 0) {
        remainingElement.style.color = '#10b981';
        document.getElementById('remainingAmountDisplay').style.background = '#d1fae5';
     } else {
        remainingElement.style.color = '#f59e0b';
        document.getElementById('remainingAmountDisplay').style.background = '#fef3c7';
     }

     // Disable process button if overpaying
     const processBtn = document.querySelector('.modal-buttons .btn-success');
     if (processBtn) {
        if (remaining < -0.01) {
             processBtn.disabled = true;
             processBtn.style.opacity = '0.5';
             processBtn.title = 'Cannot pay more than amount due';
        } else {
             processBtn.disabled = false;
             processBtn.style.opacity = '1';
        }
     }
   }
  }

  function addPaymentSplit() {
   const container = document.getElementById('paymentSplits');
   const splitIndex = paymentSplitCount;

   const splitDiv = document.createElement('div');
   splitDiv.className = 'payment-split-item';
   splitDiv.setAttribute('data-split-index', splitIndex);
   splitDiv.innerHTML = `
  <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; margin-bottom: 10px; align-items: end;">
   <div class="form-group">
     <label>Payment Method</label>
     <select class="payment-method" onchange="togglePaymentFields(this, ${splitIndex})">
        <option value="">Select</option>
        <option value="cash">Cash</option>
        <option value="cliq">CliQ</option>
        <option value="visa">Visa</option>
     </select>
   </div>
   <div class="form-group">
     <label>Amount (<?php echo $currencySymbol; ?>)</label>
     <input type="number" class="payment-amount" step="0.01" placeholder="0.00" 
        onkeyup="updateRemainingAmount()" 
        onchange="validateSplitAmount(this)">
   </div>
   <div class="form-group">
     <label>&nbsp;</label>
     <button type="button" class="btn btn-danger btn-sm" onclick="removePaymentSplit(this)">Remove</button>
   </div>
  </div>
  <div class="payment-fields" style="display: none;"></div>
 `;

   container.appendChild(splitDiv);
   paymentSplitCount++;
  }

  function validateSplitAmount(input) {
   let value = parseFloat(input.value);
   if (isNaN(value)) value = 0;

   // Calculate current total of other splits
   let otherTotal = 0;
   const allAmounts = document.querySelectorAll('.payment-amount');
   allAmounts.forEach(amount => {
     if (amount !== input) {
        otherTotal += parseFloat(amount.value) || 0;
     }
   });

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

   for (let item of splitItems) {
     const method = item.querySelector('.payment-method').value;
     const amount = parseFloat(item.querySelector('.payment-amount').value);

     if (!method) {
        alert('Please select a payment method for all splits');
        return;
     }

     if (isNaN(amount) || amount <= 0) {
        alert('Please enter valid amount for all splits');
        return;
     }

     totalAmount += amount;

     const splitData = {
        method: method,
        amount: amount
     };

     if (method === 'cash') {
        const receivedBy = item.querySelector('.received-by')?.value;
        if (!receivedBy) {
             alert('Please enter who received the cash payment');
             return;
        }
        splitData.received_by = receivedBy;
     } else if (method === 'cliq') {
        const fileInput = item.querySelector('.proof-file');
        if (!fileInput.files[0]) {
             alert('Please upload a screenshot for CliQ payment');
             return;
        }
        splitData.hasFile = true;
     } else if (method === 'visa') {
        const receiptId = item.querySelector('.receipt-id')?.value;
        if (!receiptId) {
             alert('Please enter receipt ID for Visa payment');
             return;
        }
        splitData.receipt_id = receiptId;
     }

     splits.push(splitData);
   }

   // Round to 2 decimal places
   totalAmount = Math.round(totalAmount * 100) / 100;
   const amountDue = Math.round(currentAmountDue * 100) / 100;

   console.log("Total Amount to Pay:", totalAmount);
   console.log("Amount Due:", amountDue);

   // Check if total matches the amount due (allow 0.01 tolerance)
   if (Math.abs(totalAmount - amountDue) > 0.02) {
     if (totalAmount < amountDue) {
        alert(`Total payment (${totalAmount.toFixed(2)}) is less than amount due (${amountDue.toFixed(2)}). Please add more.`);
     } else {
        alert(`Total payment (${totalAmount.toFixed(2)}) exceeds amount due (${amountDue.toFixed(2)}). Please reduce the amount.`);
     }
     return;
   }

   // Use the exact amount due (not the total from inputs)
   const exactAmount = amountDue;

   // Adjust the last split to match exact amount if needed
   if (Math.abs(totalAmount - exactAmount) > 0.01) {
     const lastSplit = splits[splits.length - 1];
     lastSplit.amount = exactAmount - (totalAmount - lastSplit.amount);
   }

   showLoading('Processing payments...');

   const formData = new FormData();
   formData.append('reservation_id', currentReservationId);
   formData.append('splits', JSON.stringify(splits));

   try {
     const response = await fetch('process_split_payment.php', {
        method: 'POST',
        body: formData
     });

     const text = await response.text();
     console.log("Raw response:", text);

     let data;
     try {
        data = JSON.parse(text);
     } catch (e) {
        console.error("Failed to parse JSON:", text);
        alert("Server error. Check logs.");
        hideLoading();
        return;
     }

     hideLoading();

     if (data.success) {
        alert('✓ Payment processed successfully!');
        closePaymentModal();
        location.reload();
     } else {
        alert('Error: ' + (data.error || 'Payment failed'));
     }
   } catch (error) {
     hideLoading();
     alert('Error: ' + error.message);
     console.error(error);
   }
  }

  function deleteReservation(reservationId, element) {
   const password = prompt('⚠️ SECURITY VERIFICATION REQUIRED\n\nEnter admin password to delete this reservation:\n(Default: AdminDelete2026)');

   if (password === null) return;

   if (password !== 'AdminDelete2026') {
     showNotification('Invalid password!', 'error');
     return;
   }

   showLoading('Deleting reservation...');

   fetch('delete_reservation.php', {
        method: 'POST',
        headers: {
             'Content-Type': 'application/json'
        },
        body: JSON.stringify({
             reservation_id: reservationId,
             password: password
        })
     })
     .then(response => response.json())
     .then(data => {
        hideLoading();
        if (data.success) {
             const row = element.closest('tr');
             row.style.transition = 'all 0.3s ease';
             row.style.opacity = '0';
             row.style.transform = 'translateX(-20px)';
             setTimeout(() => {
                     row.remove();
                     showNotification('Reservation deleted successfully!', 'success');
                     setTimeout(() => location.reload(), 1000);
             }, 300);
        } else {
             showNotification(data.error, 'error');
        }
     })
     .catch(error => {
        hideLoading();
        showNotification('Error: ' + error.message, 'error');
     });
  }

  let soundEnabled = localStorage.getItem('soundEnabled') === 'true';

  function playNotificationSound() {
   if (!soundEnabled) return;
   try {
     const audioContext = new(window.AudioContext || window.webkitAudioContext)();
     const oscillator = audioContext.createOscillator();
     const gainNode = audioContext.createGain();
     oscillator.connect(gainNode);
     gainNode.connect(audioContext.destination);
     oscillator.frequency.value = 880;
     gainNode.gain.value = 0.3;
     oscillator.start();
     gainNode.gain.exponentialRampToValueAtTime(0.00001, audioContext.currentTime + 0.5);
     oscillator.stop(audioContext.currentTime + 0.5);
     if (audioContext.state === 'suspended') audioContext.resume();
   } catch (e) {}
  }

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

  function toggleSound() {
   soundEnabled = !soundEnabled;
   localStorage.setItem('soundEnabled', soundEnabled);
   const soundBtn = document.getElementById('soundToggle');
   if (soundBtn) {
     soundBtn.innerHTML = soundEnabled ? '<i class="bi bi-volume-up-fill"></i> Sound On' : '<i class="bi bi-volume-mute-fill"></i> Sound Off';
     soundBtn.style.background = soundEnabled ? '#10b981' : '#64748b';
   }
   showNotification(`Sound notifications ${soundEnabled ? 'enabled' : 'disabled'}`, 'info');
  }

  function checkNewReservations() {
   fetch('check_new_reservations.php')
     .then(response => response.json())
     .then(data => {
        if (data.new_count > 0) {
             playNotificationSound();
             showNotification(`${data.new_count} new reservation(s) just arrived!`, 'success');
        }
     })
     .catch(error => console.log('Error checking new reservations:', error));
  }

  function applyFilters() {
   const search = document.getElementById('search').value;
   const status = document.getElementById('statusFilter').value;
   let url = `dashboard.php?search=${encodeURIComponent(search)}&status=${status}&lang=<?php echo $lang; ?>`;
   window.location.href = url;
  }

  function setLanguage(lang) {
   const url = new URL(window.location.href);
   url.searchParams.set('lang', lang);
   window.location.href = url.toString();
  }

  function openExportModal() {
   document.getElementById('exportModal').style.display = 'flex';
  }

  function closeExportModal() {
   document.getElementById('exportModal').style.display = 'none';
  }

  const darkModeToggle = document.getElementById('darkModeToggle');
  const isDarkMode = localStorage.getItem('darkMode') === 'true';
  if (isDarkMode) {
   document.body.classList.add('dark-mode');
   darkModeToggle.innerHTML = '<i class="bi bi-sun-fill"></i>';
  }
  darkModeToggle.addEventListener('click', () => {
   document.body.classList.toggle('dark-mode');
   const isDark = document.body.classList.contains('dark-mode');
   localStorage.setItem('darkMode', isDark);
   darkModeToggle.innerHTML = isDark ? '<i class="bi bi-sun-fill"></i>' : '<i class="bi bi-moon-fill"></i>';
  });

  document.addEventListener('DOMContentLoaded', function() {
   const soundBtn = document.getElementById('soundToggle');
   if (soundBtn) {
     soundBtn.innerHTML = soundEnabled ? '<i class="bi bi-volume-up-fill"></i> Sound On' : '<i class="bi bi-volume-mute-fill"></i> Sound Off';
     soundBtn.style.background = soundEnabled ? '#10b981' : '#64748b';
   }
   setInterval(checkNewReservations, 30000);
  });

  document.getElementById('search')?.addEventListener('keypress', function(e) {
   if (e.key === 'Enter') applyFilters();
  });

  window.onclick = function(event) {
   const exportModal = document.getElementById('exportModal');
   const paymentModal = document.getElementById('paymentModal');
   if (event.target === exportModal) closeExportModal();
   if (event.target === paymentModal) closePaymentModal();
  }
 </script>
</body>

</html>