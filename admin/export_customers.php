<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();

$customers = $conn->query("
    SELECT phone, name, email, total_visits, total_spent, first_visit_date, last_visit_date, 
           CASE WHEN is_vip = 1 THEN 'VIP' ELSE 'Regular' END as status
    FROM customers 
    ORDER BY total_spent DESC
")->fetch_all(MYSQLI_ASSOC);

$conn->close();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="customers_export_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($output, ['Phone', 'Name', 'Email', 'Total Visits', 'Total Spent (JD)', 'First Visit', 'Last Visit', 'Status']);

foreach ($customers as $row) {
    fputcsv($output, [
        $row['phone'],
        $row['name'],
        $row['email'],
        $row['total_visits'],
        number_format($row['total_spent'], 2),
        $row['first_visit_date'],
        $row['last_visit_date'],
        $row['status']
    ]);
}

fclose($output);
exit();
?>