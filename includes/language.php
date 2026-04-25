<?php
/**
 * Language handling file
 * This file handles all translation-related functions
 */

// Only set session settings if session is not already active
if (session_status() === PHP_SESSION_NONE) {
    // Set session settings BEFORE starting the session
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
    
    // Start session
    session_start();
}

// Set language based on session or GET parameter
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
}

$lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en';

// Translations array
$translations = [
    'en' => [
        'dashboard' => 'Dashboard',
        'ticketing_system' => 'Ticketing System',
        'welcome' => 'Welcome',
        'logout' => 'Logout',
        'total_attendees' => 'Total Attendees',
        'adults' => 'Adults',
        'teens' => 'Teens',
        'kids' => 'Kids',
        'pending_attendees' => 'Pending Attendees',
        'amount_due' => 'Amount Due',
        'pending' => 'Pending',
        'registered' => 'Registered',
        'paid' => 'Paid',
        'cancelled' => 'Cancelled',
        'total_revenue' => 'Total Revenue',
        'cash' => 'Cash',
        'cliq' => 'CliQ',
        'visa' => 'Visa',
        'total_reservations' => 'Total Reservations',
        'search' => 'Search...',
        'all' => 'All',
        'apply' => 'Apply',
        'reset' => 'Reset',
        'new_reservation' => 'New Reservation',
        'bulk_whatsapp' => 'Bulk WhatsApp',
        'export_csv' => 'Export CSV',
        'print_statement' => 'Print Statement',
        'analytics' => 'Analytics',
        'reservation_id' => 'Reservation ID',
        'customer_name' => 'Customer Name',
        'phone_number' => 'Phone Number',
        'table_id' => 'Table',
        'guests' => 'Guests',
        'status' => 'Status',
        'created' => 'Created',
        'actions' => 'Actions',
        'view' => 'View',
        'edit' => 'Edit',
        'pay' => 'Pay',
        'delete' => 'Delete',
        'ticket' => 'Ticket',
        'no_reservations' => 'No reservations found',
        'from_date' => 'From Date',
        'to_date' => 'To Date',
        'filter_by_status' => 'Filter by Status',
        'select_export_options' => 'Select export options',
        'export_note' => 'Export will include all reservations matching your criteria',
        'cancel' => 'Cancel',
        'system_settings' => 'System Settings',
        'back_to_dashboard' => 'Back to Dashboard',
        'save_settings' => 'Save Settings',
        'general_settings' => 'General Settings',
        'ticket_pricing' => 'Ticket Pricing',
        'notification_settings' => 'Notification Settings',
        'appearance_settings' => 'Appearance Settings',
        'event_settings' => 'Event Settings',
        'payment_transactions' => 'Payment Transactions',
        'add_payment' => 'Add Payment',
        'record_first_payment' => 'Record First Payment',
        'no_payment_transactions' => 'No payment transactions recorded yet.',
        'date_time' => 'Date & Time',
        'payment_method' => 'Payment Method',
        'reference_evidence' => 'Reference / Evidence',
        'edit_reservation' => 'Edit Reservation',
        'print_ticket' => 'Print Ticket',
        'cancel_reservation' => 'Cancel Reservation',
        'delete_reservation' => 'Delete Reservation',
        'reservation_details' => 'Reservation Details',
        'guests_breakdown' => 'Guests Breakdown',
        'ticket_prices' => 'Ticket Prices',
        'notes' => 'Notes',
        'created_at' => 'Created At',
        'email' => 'Email',
        'view_reservation' => 'View Reservation'
    ],
    'ar' => [
        'dashboard' => 'لوحة التحكم',
        'ticketing_system' => 'نظام التذاكر',
        'welcome' => 'مرحباً',
        'logout' => 'تسجيل الخروج',
        'total_attendees' => 'إجمالي الحضور',
        'adults' => 'بالغين',
        'teens' => 'مراهقين',
        'kids' => 'أطفال',
        'pending_attendees' => 'حضور معلق',
        'amount_due' => 'المبلغ المستحق',
        'pending' => 'معلق',
        'registered' => 'مسجل',
        'paid' => 'مدفوع',
        'cancelled' => 'ملغي',
        'total_revenue' => 'إجمالي الإيرادات',
        'cash' => 'نقدي',
        'cliq' => 'كليك',
        'visa' => 'فيزا',
        'total_reservations' => 'إجمالي الحجوزات',
        'search' => 'بحث...',
        'all' => 'الكل',
        'apply' => 'تطبيق',
        'reset' => 'إعادة تعيين',
        'new_reservation' => 'حجز جديد',
        'bulk_whatsapp' => 'واتساب جماعي',
        'export_csv' => 'تصدير CSV',
        'print_statement' => 'طباعة كشف',
        'analytics' => 'تحليلات',
        'reservation_id' => 'رقم الحجز',
        'customer_name' => 'اسم العميل',
        'phone_number' => 'رقم الهاتف',
        'table_id' => 'طاولة',
        'guests' => 'ضيوف',
        'status' => 'الحالة',
        'created' => 'تاريخ الإنشاء',
        'actions' => 'إجراءات',
        'view' => 'عرض',
        'edit' => 'تعديل',
        'pay' => 'دفع',
        'delete' => 'حذف',
        'ticket' => 'تذكرة',
        'no_reservations' => 'لا توجد حجوزات',
        'from_date' => 'من تاريخ',
        'to_date' => 'إلى تاريخ',
        'filter_by_status' => 'تصفية حسب الحالة',
        'select_export_options' => 'اختر خيارات التصدير',
        'export_note' => 'سيشمل التصدير جميع الحجوزات المطابقة لمعاييرك',
        'cancel' => 'إلغاء',
        'system_settings' => 'إعدادات النظام',
        'back_to_dashboard' => 'رجوع للوحة التحكم',
        'save_settings' => 'حفظ الإعدادات',
        'general_settings' => 'الإعدادات العامة',
        'ticket_pricing' => 'أسعار التذاكر',
        'notification_settings' => 'إعدادات الإشعارات',
        'appearance_settings' => 'إعدادات المظهر',
        'event_settings' => 'إعدادات الفعالية',
        'payment_transactions' => 'المدفوعات',
        'add_payment' => 'إضافة دفعة',
        'record_first_payment' => 'تسجيل أول دفعة',
        'no_payment_transactions' => 'لا توجد مدفوعات مسجلة حتى الآن',
        'date_time' => 'التاريخ والوقت',
        'payment_method' => 'طريقة الدفع',
        'reference_evidence' => 'المرجع / الإثبات',
        'edit_reservation' => 'تعديل الحجز',
        'print_ticket' => 'طباعة التذكرة',
        'cancel_reservation' => 'إلغاء الحجز',
        'delete_reservation' => 'حذف الحجز',
        'reservation_details' => 'تفاصيل الحجز',
        'guests_breakdown' => 'تفاصيل الضيوف',
        'ticket_prices' => 'أسعار التذاكر',
        'notes' => 'ملاحظات',
        'created_at' => 'تاريخ الإنشاء',
        'email' => 'البريد الإلكتروني',
        'view_reservation' => 'عرض الحجز'
    ]
];

/**
 * Get translation for a key
 */
function t($key) {
    global $translations, $lang;
    
    if (isset($translations[$lang][$key])) {
        return $translations[$lang][$key];
    }
    
    // Fallback to English
    if (isset($translations['en'][$key])) {
        return $translations['en'][$key];
    }
    
    return $key;
}

/**
 * Get text direction based on language
 */
function getDirection() {
    global $lang;
    return $lang == 'ar' ? 'rtl' : 'ltr';
}

/**
 * Get current language
 */
function getCurrentLanguage() {
    global $lang;
    return $lang;
}

/**
 * Set language
 */
function setLanguage($newLang) {
    global $lang;
    if (in_array($newLang, ['en', 'ar'])) {
        $_SESSION['lang'] = $newLang;
        $lang = $newLang;
        return true;
    }
    return false;
}
?>