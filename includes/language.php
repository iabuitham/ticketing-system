<?php

// Available languages
$available_languages = ['en', 'ar'];
$default_language = 'en';

// Set language from URL or session
if (isset($_GET['lang']) && in_array($_GET['lang'], $available_languages)) {
    $_SESSION['language'] = $_GET['lang'];
}

$lang = isset($_SESSION['language']) ? $_SESSION['language'] : $default_language;
$dir = $lang == 'ar' ? 'rtl' : 'ltr';

// Translations array
$translations = [
    // Navigation
    'dashboard' => ['en' => 'Dashboard', 'ar' => 'لوحة التحكم'],
    'ticketing_system' => ['en' => 'Ticketing System', 'ar' => 'نظام التذاكر'],
    'welcome' => ['en' => 'Welcome', 'ar' => 'مرحباً'],
    'logout' => ['en' => 'Logout', 'ar' => 'تسجيل خروج'],
    'back' => ['en' => 'Back', 'ar' => 'رجوع'],
    
    // Buttons
    'new_reservation' => ['en' => 'New Reservation', 'ar' => 'حجز جديد'],
    'bulk_whatsapp' => ['en' => 'Bulk WhatsApp', 'ar' => 'واتساب جماعي'],
    'print_statement' => ['en' => 'Print Statement', 'ar' => 'طباعة كشف'],
    'export_csv' => ['en' => 'Export CSV', 'ar' => 'تصدير CSV'],
    'analytics' => ['en' => 'Analytics', 'ar' => 'تحليلات'],
    'apply' => ['en' => 'Apply', 'ar' => 'تطبيق'],
    'reset' => ['en' => 'Reset', 'ar' => 'إعادة تعيين'],
    'cancel' => ['en' => 'Cancel', 'ar' => 'إلغاء'],
    
    // Table Headers
    'reservation_id' => ['en' => 'Reservation ID', 'ar' => 'رقم الحجز'],
    'customer_name' => ['en' => 'Customer Name', 'ar' => 'اسم العميل'],
    'phone_number' => ['en' => 'Phone Number', 'ar' => 'رقم الهاتف'],
    'table_id' => ['en' => 'Table ID', 'ar' => 'رقم الطاولة'],
    'guests' => ['en' => 'Guests', 'ar' => 'الضيوف'],
    'status' => ['en' => 'Status', 'ar' => 'الحالة'],
    'created' => ['en' => 'Created', 'ar' => 'تاريخ الإنشاء'],
    'actions' => ['en' => 'Actions', 'ar' => 'إجراءات'],
    'amount_due' => ['en' => 'Amount Due', 'ar' => 'المبلغ المستحق'],
    'reservations' => ['en' => 'Reservations', 'ar' => 'حجوزات'],
    
    // Status
    'pending' => ['en' => 'Pending', 'ar' => 'قيد الانتظار'],
    'registered' => ['en' => 'Registered', 'ar' => 'مسجل'],
    'paid' => ['en' => 'Paid', 'ar' => 'مدفوع'],
    'cancelled' => ['en' => 'Cancelled', 'ar' => 'ملغي'],
    'all' => ['en' => 'All', 'ar' => 'الكل'],
    
    // Actions
    'view' => ['en' => 'View', 'ar' => 'عرض'],
    'edit' => ['en' => 'Edit', 'ar' => 'تعديل'],
    'pay' => ['en' => 'Pay', 'ar' => 'دفع'],
    'ticket' => ['en' => 'Ticket', 'ar' => 'تذكرة'],
    'pay_due' => ['en' => 'Pay Due', 'ar' => 'دفع المستحق'],
    
    // Stats
    'total_attendees' => ['en' => 'Total Attendees', 'ar' => 'إجمالي الحضور'],
    'adults' => ['en' => 'Adults', 'ar' => 'بالغين'],
    'teens' => ['en' => 'Teens', 'ar' => 'مراهقين'],
    'kids' => ['en' => 'Kids', 'ar' => 'أطفال'],
    'pending_attendees' => ['en' => 'Pending Attendees', 'ar' => 'الحضور قيد الانتظار'],
    'total_revenue' => ['en' => 'Total Revenue', 'ar' => 'إجمالي الإيرادات'],
    'total_reservations' => ['en' => 'Total Reservations', 'ar' => 'إجمالي الحجوزات'],
    'cash' => ['en' => 'Cash', 'ar' => 'نقدي'],
    'cliq' => ['en' => 'CliQ', 'ar' => 'كليك'],
    'visa' => ['en' => 'Visa', 'ar' => 'فيزا'],
    
    // Search
    'search' => ['en' => 'Search...', 'ar' => 'بحث...'],
    
    // Export Modal
    'select_export_options' => ['en' => 'Select export options below', 'ar' => 'اختر خيارات التصدير أدناه'],
    'filter_by_status' => ['en' => 'Filter by Status', 'ar' => 'تصفية حسب الحالة'],
    'from_date' => ['en' => 'From Date', 'ar' => 'من تاريخ'],
    'to_date' => ['en' => 'To Date', 'ar' => 'إلى تاريخ'],
    'export_note' => ['en' => 'The export will include all reservations matching your filters. The file will be downloaded as CSV and can be opened in Excel.', 'ar' => 'سيتضمن التصدير جميع الحجوزات المطابقة لفلترتك. سيتم تنزيل الملف كـ CSV ويمكن فتحه في Excel.'],
];

function t($key) {
    global $translations, $lang;
    return isset($translations[$key][$lang]) ? $translations[$key][$lang] : $key;
}

function getDirection() {
    global $dir;
    return $dir;
}
?>