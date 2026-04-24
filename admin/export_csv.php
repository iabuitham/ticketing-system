<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

// Get filters from URL
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['from']) ? $_GET['from'] : '';
$date_to = isset($_GET['to']) ? $_GET['to'] : '';

// Build query
$query = "SELECT 
    r.reservation_id,
    r.name,
    r.phone,
    r.adults,
    r.teens,
    r.kids,
    (r.adults + r.teens + r.kids) as total_guests,
    r.table_id,
    r.status,
    r.total_amount,
    r.additional_amount_due,
    r.payment_method,
    r.payment_proof,
    r.created_at,
    r.updated_at,
    (SELECT COUNT(*) FROM ticket_codes WHERE reservation_id = r.reservation_id) as ticket_count,
    (SELECT SUM(amount) FROM split_payments WHERE reservation_id = r.reservation_id AND payment_type = 'initial') as initial_payment,
    (SELECT SUM(amount) FROM split_payments WHERE reservation_id = r.reservation_id AND payment_type = 'additional') as additional_payment
FROM reservations r
WHERE 1=1";

if ($status_filter && $status_filter != 'all') {
    $query .= " AND r.status = '$status_filter'";
}

if ($date_from && $date_to) {
    $query .= " AND DATE(r.created_at) BETWEEN '$date_from' AND '$date_to'";
} elseif ($date_from) {
    $query .= " AND DATE(r.created_at) >= '$date_from'";
} elseif ($date_to) {
    $query .= " AND DATE(r.created_at) <= '$date_to'";
}

$query .= " ORDER BY r.created_at DESC";

$conn = getConnection();
$result = $conn->query($query);
$reservations = $result->fetch_all(MYSQLI_ASSOC);
$conn->close();

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="reservations_export_' . date('Y-m-d_H-i-s') . '.csv"');
header('Pragma: no-cache');
header('Expires: 0');

// Create output stream
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for proper Arabic/Unicode support
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Add headers
fputcsv($output, [
    'Reservation ID',
    'Customer Name',
    'Phone Number',
    'Adults',
    'Teens',
    'Kids',
    'Total Guests',
    'Table ID',
    'Status',
    'Total Amount (JOD)',
    'Additional Amount Due (JOD)',
    'Payment Method',
    'Initial Payment (JOD)',
    'Additional Payment (JOD)',
    'Ticket Count',
    'Payment Proof',
    'Created Date',
    'Last Updated'
]);

// Add data rows
foreach ($reservations as $row) {
    fputcsv($output, [
        $row['reservation_id'],
        $row['name'],
        $row['phone'],
        $row['adults'],
        $row['teens'],
        $row['kids'],
        $row['total_guests'],
        $row['table_id'],
        $row['status'],
        number_format($row['total_amount'], 2),
        number_format($row['additional_amount_due'], 2),
        $row['payment_method'] ?: 'Not paid',
        number_format($row['initial_payment'] ?? 0, 2),
        number_format($row['additional_payment'] ?? 0, 2),
        $row['ticket_count'],
        $row['payment_proof'] ? 'Yes' : 'No',
        date('Y-m-d H:i:s', strtotime($row['created_at'])),
        date('Y-m-d H:i:s', strtotime($row['updated_at']))
    ]);
}

fclose($output);
exit();
?>