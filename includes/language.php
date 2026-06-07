<?php
/**
 * Language handling file
 */

// Only start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Set language based on session or GET parameter
if (isset($_GET['lang'])) {
    $_SESSION['lang'] = $_GET['lang'];
}

$lang = isset($_SESSION['lang']) ? $_SESSION['lang'] : 'en';

// Complete translations array
$translations = [
    'en' => [
        // General
        'ticketing_system' => 'Ticketing System',
        'dashboard' => 'Dashboard',
        'welcome' => 'Welcome',
        'logout' => 'Logout',
        'back_to_dashboard' => 'Back to Dashboard',
        'loading' => 'Loading...',
        'processing' => 'Processing...',
        'save' => 'Save',
        'cancel' => 'Cancel',
        'delete' => 'Delete',
        'edit' => 'Edit',
        'view' => 'View',
        'search' => 'Search',
        'filter' => 'Filter',
        'reset' => 'Reset',
        'apply' => 'Apply',
        'actions' => 'Actions',
        'status' => 'Status',
        'created' => 'Created',
        'updated' => 'Updated',
        'yes' => 'Yes',
        'no' => 'No',
        'all' => 'All',
        'none' => 'None',
        'submit' => 'Submit',
        'confirm' => 'Confirm',
        'warning' => 'Warning',
        'error' => 'Error',
        'success' => 'Success',
        'info' => 'Information',
        
        // Navigation
        'new_reservation' => 'New Reservation',
        'bulk_whatsapp' => 'Bulk WhatsApp',
        'export_csv' => 'Export CSV',
        'print_statement' => 'Print Statement',
        'analytics' => 'Analytics',
        'tables' => 'Tables',
        'ticket_dashboard' => 'Ticket Dashboard',
        'floor_plan' => 'Floor Plan',
        'customers' => 'Customers',
        'ticket_transfer' => 'Ticket Transfer',
        'settings' => 'Settings',
        'admin_management' => 'Admin Management',
        
        // Statistics
        'total_booked' => 'Total Booked',
        'total_attendees' => 'Total Attendees',
        'pending_attendees' => 'Pending Attendees',
        'fully_paid' => 'Fully Paid',
        'net_revenue' => 'Net Revenue',
        'amount_due' => 'Amount Due',
        'adults' => 'Adults',
        'teens' => 'Teens',
        'kids' => 'Kids',
        'cash' => 'Cash',
        'cliq' => 'CliQ',
        'visa' => 'Visa',
        'refunds' => 'Refunds',
        'cancelled' => 'Cancelled',
        'regular_price' => 'Regular Price',
        'loyalty_price' => 'Loyalty Price',
        'new_pending' => 'New (Never Paid)',
        'old_pending' => 'Old (Additional Due)',
        
        // Reservation fields
        'reservation_id' => 'Reservation ID',
        'customer_name' => 'Customer Name',
        'phone_number' => 'Phone Number',
        'table_id' => 'Table',
        'guests' => 'Guests',
        'total_amount' => 'Total Amount',
        'paid_amount' => 'Paid Amount',
        'remaining_amount' => 'Remaining Amount',
        'notes' => 'Notes',
        'created_at' => 'Created Date',
        'updated_at' => 'Updated Date',
        'price_tier' => 'Price Tier',
        
        // Status
        'status_pending' => 'Pending',
        'status_registered' => 'Registered',
        'status_paid' => 'Paid',
        'status_cancelled' => 'Cancelled',
        'status_completed' => 'Completed',
        'status_upcoming' => 'Upcoming',
        'status_ongoing' => 'Ongoing',
        
        // Table status
        'table_available' => 'Available',
        'table_reserved' => 'Reserved',
        'table_occupied' => 'Occupied',
        'table_maintenance' => 'Maintenance',
        
        // Payment methods
        'payment_method' => 'Payment Method',
        'payment_amount' => 'Amount',
        'payment_date' => 'Payment Date',
        'receipt_id' => 'Receipt ID',
        'received_by' => 'Received By',
        'proof_evidence' => 'Reference Evidence',
        
        // Tickets
        'ticket_code' => 'Ticket Code',
        'ticket_type' => 'Ticket Type',
        'ticket_status' => 'Ticket Status',
        'scan_ticket' => 'Scan Ticket',
        'validate_ticket' => 'Validate Ticket',
        'ticket_valid' => 'Ticket Valid',
        'ticket_invalid' => 'Invalid Ticket',
        'ticket_already_used' => 'Ticket Already Used',
        'entry_granted' => 'Entry Granted',
        'entry_denied' => 'Entry Denied',
        
        // Reports
        'management_report' => 'Management Report',
        'report_period' => 'Report Period',
        'from_date' => 'From Date',
        'to_date' => 'To Date',
        'generate_report' => 'Generate Report',
        'print_report' => 'Print Report',
        'export_pdf' => 'Export PDF',
        'daily_revenue' => 'Daily Revenue',
        'weekly_performance' => 'Weekly Performance',
        'monthly_revenue' => 'Monthly Revenue',
        'top_customers' => 'Top Customers',
        'popular_tables' => 'Popular Tables',
        'cancellation_rate' => 'Cancellation Rate',
        'conversion_rate' => 'Conversion Rate',
        'average_group_size' => 'Average Group Size',
        
        // Modals & Dialogs
        'close' => 'Close',
        'confirm_delete' => 'Are you sure you want to delete?',
        'confirm_cancel' => 'Are you sure you want to cancel?',
        'enter_password' => 'Enter Password',
        'invalid_password' => 'Invalid Password',
        'operation_successful' => 'Operation Successful',
        'operation_failed' => 'Operation Failed',
        
        // Customer page
        'customer_management' => 'Customer Management',
        'total_customers' => 'Total Customers',
        'vip_customers' => 'VIP Customers',
        'total_visits' => 'Total Visits',
        'total_revenue' => 'Total Revenue',
        'avg_per_customer' => 'Avg per Customer',
        'last_visit' => 'Last Visit',
        'first_visit' => 'First Visit',
        'total_spent' => 'Total Spent',
        'make_vip' => 'Make VIP',
        'remove_vip' => 'Remove VIP',
        'customer_details' => 'Customer Details',
        'recent_reservations' => 'Recent Reservations',
        
        // Floor Plan
        'floor_plan_management' => 'Floor Plan Management',
        'upload_floor_plan' => 'Upload Floor Plan',
        'current_floor_plan' => 'Current Floor Plan',
        'print_floor_plan' => 'Print Floor Plan',
        'delete_floor_plan' => 'Delete Floor Plan',
        
        // Ticket Transfer
        'ticket_transfer_system' => 'Ticket Transfer System',
        'request_transfer' => 'Request Transfer',
        'transfer_code' => 'Transfer Code',
        'recipient_name' => 'Recipient Name',
        'recipient_phone' => 'Recipient Phone',
        'transfer_status' => 'Transfer Status',
        'pending' => 'Pending',
        'approved' => 'Approved',
        'expired' => 'Expired',
        'approve' => 'Approve',
        'reject' => 'Reject',
        
        // WhatsApp
        'whatsapp_messaging' => 'WhatsApp Messaging',
        'select_recipients' => 'Select Recipients',
        'message_template' => 'Message Template',
        'custom_message' => 'Custom Message',
        'include_ticket_link' => 'Include ticket download link',
        'payment_link' => 'Payment Link',
        'message_preview' => 'Message Preview',
        'send_messages' => 'Send Messages',
        'messages_sent' => 'Messages Sent',
        'messages_failed' => 'Messages Failed',
        
        // Event
        'event_name' => 'Event Name',
        'event_date' => 'Event Date',
        'event_time' => 'Event Time',
        'venue' => 'Venue',
        'close_event' => 'Close Event',
        'switch_event' => 'Switch Event',
        'archive_event' => 'Archive Event',
        
        // Table Assignment
        'assign_table' => 'Assign Table',
        'select_table' => 'Select Table',
        'table_assigned' => 'Table Assigned',
        'send_floor_plan' => 'Send Floor Plan',
        
        // Errors
        'unauthorized' => 'Unauthorized Access',
        'not_found' => 'Not Found',
        'invalid_request' => 'Invalid Request',
        'try_again' => 'Please try again',
        'contact_support' => 'Contact support if problem persists',
    ],
    
    'ar' => [
        // General
        'ticketing_system' => 'نظام التذاكر',
        'dashboard' => 'لوحة التحكم',
        'welcome' => 'مرحباً',
        'logout' => 'تسجيل الخروج',
        'back_to_dashboard' => 'العودة للوحة التحكم',
        'loading' => 'جاري التحميل...',
        'processing' => 'جاري المعالجة...',
        'save' => 'حفظ',
        'cancel' => 'إلغاء',
        'delete' => 'حذف',
        'edit' => 'تعديل',
        'view' => 'عرض',
        'search' => 'بحث',
        'filter' => 'تصفية',
        'reset' => 'إعادة تعيين',
        'apply' => 'تطبيق',
        'actions' => 'إجراءات',
        'status' => 'الحالة',
        'created' => 'تاريخ الإنشاء',
        'updated' => 'تاريخ التحديث',
        'yes' => 'نعم',
        'no' => 'لا',
        'all' => 'الكل',
        'none' => 'لا شيء',
        'submit' => 'إرسال',
        'confirm' => 'تأكيد',
        'warning' => 'تحذير',
        'error' => 'خطأ',
        'success' => 'نجاح',
        'info' => 'معلومات',
        
        // Navigation
        'new_reservation' => 'حجز جديد',
        'bulk_whatsapp' => 'واتساب جماعي',
        'export_csv' => 'تصدير CSV',
        'print_statement' => 'طباعة كشف',
        'analytics' => 'تحليلات',
        'tables' => 'الطاولات',
        'ticket_dashboard' => 'لوحة التذاكر',
        'floor_plan' => 'مخطط الطاولات',
        'customers' => 'العملاء',
        'ticket_transfer' => 'نقل التذكرة',
        'settings' => 'الإعدادات',
        'admin_management' => 'إدارة المشرفين',
        
        // Statistics
        'total_booked' => 'إجمالي المحجوزين',
        'total_attendees' => 'إجمالي الحضور',
        'pending_attendees' => 'الحضور المعلق',
        'fully_paid' => 'مدفوع بالكامل',
        'net_revenue' => 'صافي الإيرادات',
        'amount_due' => 'المبلغ المستحق',
        'adults' => 'بالغين',
        'teens' => 'مراهقين',
        'kids' => 'أطفال',
        'cash' => 'كاش',
        'cliq' => 'كليك',
        'visa' => 'فيزا',
        'refunds' => 'المبالغ المستردة',
        'cancelled' => 'ملغي',
        'regular_price' => 'السعر العادي',
        'loyalty_price' => 'سعر الولاء',
        'new_pending' => 'جديد (لم يدفع)',
        'old_pending' => 'قديم (دفعة إضافية)',
        
        // Reservation fields
        'reservation_id' => 'رقم الحجز',
        'customer_name' => 'اسم العميل',
        'phone_number' => 'رقم الهاتف',
        'table_id' => 'الطاولة',
        'guests' => 'الضيوف',
        'total_amount' => 'المبلغ الإجمالي',
        'paid_amount' => 'المبلغ المدفوع',
        'remaining_amount' => 'المبلغ المتبقي',
        'notes' => 'ملاحظات',
        'created_at' => 'تاريخ الإنشاء',
        'updated_at' => 'تاريخ التحديث',
        'price_tier' => 'فئة السعر',
        
        // Status
        'status_pending' => 'قيد الانتظار',
        'status_registered' => 'مسجل',
        'status_paid' => 'مدفوع',
        'status_cancelled' => 'ملغي',
        'status_completed' => 'مكتمل',
        'status_upcoming' => 'قادم',
        'status_ongoing' => 'جاري',
        
        // Table status
        'table_available' => 'متاحة',
        'table_reserved' => 'محجوزة',
        'table_occupied' => 'مشغولة',
        'table_maintenance' => 'صيانة',
        
        // Payment methods
        'payment_method' => 'طريقة الدفع',
        'payment_amount' => 'المبلغ',
        'payment_date' => 'تاريخ الدفع',
        'receipt_id' => 'رقم الإيصال',
        'received_by' => 'استلم بواسطة',
        'proof_evidence' => 'إثبات مرجعي',
        
        // Tickets
        'ticket_code' => 'رمز التذكرة',
        'ticket_type' => 'نوع التذكرة',
        'ticket_status' => 'حالة التذكرة',
        'scan_ticket' => 'مسح التذكرة',
        'validate_ticket' => 'التحقق من التذكرة',
        'ticket_valid' => 'تذكرة صالحة',
        'ticket_invalid' => 'تذكرة غير صالحة',
        'ticket_already_used' => 'التذكرة مستخدمة مسبقاً',
        'entry_granted' => 'تم السماح بالدخول',
        'entry_denied' => 'تم رفض الدخول',
        
        // Reports
        'management_report' => 'تقرير الإدارة',
        'report_period' => 'فترة التقرير',
        'from_date' => 'من تاريخ',
        'to_date' => 'إلى تاريخ',
        'generate_report' => 'إنشاء التقرير',
        'print_report' => 'طباعة التقرير',
        'export_pdf' => 'تصدير PDF',
        'daily_revenue' => 'الإيرادات اليومية',
        'weekly_performance' => 'الأداء الأسبوعي',
        'monthly_revenue' => 'الإيرادات الشهرية',
        'top_customers' => 'أفضل العملاء',
        'popular_tables' => 'الطاولات الأكثر حجزاً',
        'cancellation_rate' => 'نسبة الإلغاء',
        'conversion_rate' => 'نسبة التحويل',
        'average_group_size' => 'متوسط حجم المجموعة',
        
        // Modals & Dialogs
        'close' => 'إغلاق',
        'confirm_delete' => 'هل أنت متأكد من الحذف؟',
        'confirm_cancel' => 'هل أنت متأكد من الإلغاء؟',
        'enter_password' => 'أدخل كلمة المرور',
        'invalid_password' => 'كلمة مرور غير صحيحة',
        'operation_successful' => 'العملية ناجحة',
        'operation_failed' => 'العملية فشلت',
        
        // Customer page
        'customer_management' => 'إدارة العملاء',
        'total_customers' => 'إجمالي العملاء',
        'vip_customers' => 'عملاء VIP',
        'total_visits' => 'إجمالي الزيارات',
        'total_revenue' => 'إجمالي الإيرادات',
        'avg_per_customer' => 'متوسط لكل عميل',
        'last_visit' => 'آخر زيارة',
        'first_visit' => 'أول زيارة',
        'total_spent' => 'إجمالي الإنفاق',
        'make_vip' => 'جعله VIP',
        'remove_vip' => 'إزالة VIP',
        'customer_details' => 'تفاصيل العميل',
        'recent_reservations' => 'الحجوزات الأخيرة',
        
        // Floor Plan
        'floor_plan_management' => 'إدارة مخطط الطاولات',
        'upload_floor_plan' => 'رفع مخطط الطاولات',
        'current_floor_plan' => 'مخطط الطاولات الحالي',
        'print_floor_plan' => 'طباعة المخطط',
        'delete_floor_plan' => 'حذف المخطط',
        
        // Ticket Transfer
        'ticket_transfer_system' => 'نظام نقل التذاكر',
        'request_transfer' => 'طلب نقل',
        'transfer_code' => 'رمز النقل',
        'recipient_name' => 'اسم المستلم',
        'recipient_phone' => 'هاتف المستلم',
        'transfer_status' => 'حالة النقل',
        'pending' => 'قيد الانتظار',
        'approved' => 'موافق',
        'expired' => 'منتهي الصلاحية',
        'approve' => 'موافقة',
        'reject' => 'رفض',
        
        // WhatsApp
        'whatsapp_messaging' => 'مراسلة واتساب',
        'select_recipients' => 'اختيار المستلمين',
        'message_template' => 'قالب الرسالة',
        'custom_message' => 'رسالة مخصصة',
        'include_ticket_link' => 'تضمين رابط تحميل التذكرة',
        'payment_link' => 'رابط الدفع',
        'message_preview' => 'معاينة الرسالة',
        'send_messages' => 'إرسال الرسائل',
        'messages_sent' => 'الرسائل المرسلة',
        'messages_failed' => 'الرسائل الفاشلة',
        
        // Event
        'event_name' => 'اسم الحدث',
        'event_date' => 'تاريخ الحدث',
        'event_time' => 'وقت الحدث',
        'venue' => 'المكان',
        'close_event' => 'إغلاق الحدث',
        'switch_event' => 'تبديل الحدث',
        'archive_event' => 'أرشفة الحدث',
        
        // Table Assignment
        'assign_table' => 'تخصيص طاولة',
        'select_table' => 'اختيار طاولة',
        'table_assigned' => 'تم تخصيص الطاولة',
        'send_floor_plan' => 'إرسال المخطط',
        
        // Errors
        'unauthorized' => 'دخول غير مصرح به',
        'not_found' => 'غير موجود',
        'invalid_request' => 'طلب غير صالح',
        'try_again' => 'يرجى المحاولة مرة أخرى',
        'contact_support' => 'اتصل بالدعم إذا استمرت المشكلة',
    ]
];

// Translation function
if (!function_exists('t')) {
    function t($key) {
        global $lang, $translations;
        
        if (isset($translations[$lang][$key])) {
            return $translations[$lang][$key];
        }
        if (isset($translations['en'][$key])) {
            return $translations['en'][$key];
        }
        return $key;
    }
}

function getDirection() {
    global $lang;
    return $lang === 'ar' ? 'rtl' : 'ltr';
}

function getCurrentLanguage() {
    global $lang;
    return $lang;
}

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