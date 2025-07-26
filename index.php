<?php
// index.php (Main Router)

// THIS MUST BE THE VERY FIRST EXECUTABLE LINE
require_once 'config.php'; // Defines DB details like $hostname, $username, $database
require_once 'db_connect.php'; // ESTABLISHES $connection using details from config.php
require_once INC_PATH . 'functions.php'; // Helper functions

// Default page
$page = $_GET['page'] ?? 'dashboard';

// Define allowed pages and their corresponding files
$allowed_pages = [
    'dashboard'         => 'dashboard.php',
    'voucher_create'    => 'voucher_create.php',
    'voucher_list'      => 'voucher_list.php',
    'voucher_view'      => 'voucher_view.php',
    'expenses'          => 'expenses.php',
    'customer_management' => 'customer_management.php',
    'staff_management'  => 'staff_management.php',
    'status_bulk_update' => 'status_bulk_update.php',
    'login'             => 'login.php',
    'register'          => 'register.php',
    'customer_view_voucher' => 'customer_view_voucher.php', // NEW: Public voucher view
    'logout'=> 'logout.php',// is handled directly by logout.php
    'profit_loss_report' => 'profit_loss_report.php',
    'voucher_print'     => 'voucher_print.php',
    'consignment_management' => 'consignment_management.php',
    'consignment_view'  => 'consignment_view.php',
];

// Pages that do NOT require login
$public_pages = ['login', 'register', 'customer_view_voucher'];

// If not logged in and trying to access a restricted page, redirect to login
if (!is_logged_in() && !in_array($page, $public_pages)) {
    flash_message('error', 'You must be logged in to access the system.');
    redirect('index.php?page=login');
}

// Check if the requested page is valid and allowed
if (!array_key_exists($page, $allowed_pages)) {
    flash_message('error', 'Page not found or access denied.');
    $page = 'dashboard'; // Fallback to dashboard
    // If fallback to dashboard is also restricted, this might loop.
    // For public users, if page not found, maybe redirect to login or a public home.
    if (!is_logged_in()) {
        redirect('index.php?page=login');
    }
}

// Include the page content
$page_file = $allowed_pages[$page];
if (file_exists($page_file)) {
    require_once $page_file;
} else {
    error_log('System error: Page file not found on server: ' . $page_file);
    flash_message('error', 'System error: Page file not found on server.');
    // If page file is missing, and user is logged in, show dashboard. Else, login.
    if (is_logged_in()) {
        require_once 'dashboard.php';
    } else {
        require_once 'login.php';
    }
}
?>