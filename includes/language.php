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

// Define t() function if it doesn't exist
if (!function_exists('t')) {
    function t($key) {
        global $lang, $translations;
        $translations = [
            'en' => [
                'new_reservation' => 'New Reservation',
                'ticketing_system' => 'Ticketing System',
                'back_to_dashboard' => 'Back to Dashboard',
                'reservation_id' => 'Reservation ID',
                'customer_name' => 'Customer Name',
                'phone_number' => 'Phone Number',
                'table_id' => 'Table',
                'guests' => 'Guests',
                'status' => 'Status',
                'actions' => 'Actions',
                'view' => 'View',
                'edit' => 'Edit',
                'delete' => 'Delete',
                'created' => 'Created',
                'adults' => 'Adults',
                'teens' => 'Teens',
                'kids' => 'Kids',
                'total_amount' => 'Total Amount',
                'notes' => 'Notes',
                'create_reservation' => 'Create Reservation',
                'cancel' => 'Cancel'
            ],
            'ar' => [
                'new_reservation' => 'حجز جديد',
                'ticketing_system' => 'نظام التذاكر',
                'back_to_dashboard' => 'رجوع للوحة التحكم',
                'reservation_id' => 'رقم الحجز',
                'customer_name' => 'اسم العميل',
                'phone_number' => 'رقم الهاتف',
                'table_id' => 'الطاولة',
                'guests' => 'الضيوف',
                'status' => 'الحالة',
                'actions' => 'إجراءات',
                'view' => 'عرض',
                'edit' => 'تعديل',
                'delete' => 'حذف',
                'created' => 'تاريخ الإنشاء',
                'adults' => 'بالغين',
                'teens' => 'مراهقين',
                'kids' => 'أطفال',
                'total_amount' => 'المبلغ الإجمالي',
                'notes' => 'ملاحظات',
                'create_reservation' => 'إنشاء حجز',
                'cancel' => 'إلغاء'
            ]
        ];
        
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