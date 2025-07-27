<?php
// index.php (Main Router)

session_start(); // THIS MUST BE THE ABSOLUTE FIRST EXECUTABLE LINE

require_once 'config.php'; // Defines DB details, BASE_URL, user type constants etc.
require_once 'db_connect.php'; // Establishes $connection using details from config.php
require_once INC_PATH . 'functions.php'; // Helper functions like is_logged_in, flash_message, redirect etc.

// --- Global Authentication State ---
$is_staff_logged_in = is_logged_in(); // Checks if staff/admin is logged in
$is_customer_logged_in = is_customer_logged_in(); // Checks if customer is logged in

// --- Current Page Determination ---
$page = $_GET['page'] ?? 'dashboard'; // Default page is 'dashboard'

// --- Define Allowed Pages and Their Corresponding Files ---
$allowed_pages = [
    // --- Staff/Admin Portal Pages (Requires staff/admin login) ---
    'dashboard'         => 'dashboard.php',
    'voucher_create'    => 'voucher_create.php',
    'voucher_list'      => 'voucher_list.php',
    'voucher_view'      => 'voucher_view.php',
    'expenses'          => 'expenses.php',
    'customer_management' => 'customer_management.php',
    'staff_management'  => 'staff_management.php',
    'status_bulk_update' => 'status_bulk_update.php',
    'profit_loss_report' => 'profit_loss_report.php',
    'consignment_management' => 'consignment_management.php',
    'consignment_view'  => 'consignment_view.php',
    'master_data_management' => 'master_data_management.php',

    // --- Driver Interface Pages (Requires driver login, or admin) ---
    'driver_dashboard' => 'driver_dashboard.php',
    'driver_voucher_detail' => 'driver_voucher_detail.php',

    // --- Public Pages (No login required) ---
    'login'             => 'login.php', // Staff/Admin login form
    'register'          => 'register.php', // Staff registration form
    'customer_view_voucher' => 'customer_view_voucher.php', // Public QR scan voucher view

    // --- Customer Portal Pages (Requires customer login) ---
    'customer_login' => 'customer_login.php', // Customer login form
    'customer_dashboard' => 'customer_dashboard.php',
    'customer_shipment_history' => 'customer_shipment_history.php',
    'customer_voucher_details' => 'customer_voucher_details.php',
    'customer_financial_statement' => 'customer_financial_statement.php',
    'customer_logout' => 'customer_logout.php', // Customer logout script
];

// --- Pages that do NOT require ANY login (accessible by anyone) ---
$public_access_pages = ['login', 'register', 'customer_view_voucher', 'customer_login'];


// --- Main Authentication and Routing Logic ---

// 1. Check if the requested page is valid in our allowed list
if (!array_key_exists($page, $allowed_pages)) {
    // Page requested is not defined
    if ($is_staff_logged_in || $is_customer_logged_in) {
        // If any user is logged in, flash an error and redirect to their appropriate dashboard
        $redirect_page = $is_customer_logged_in ? 'customer_dashboard' : 'dashboard';
        $flash_func = $is_customer_logged_in ? 'customer_flash_message' : 'flash_message';
        $redirect_func = $is_customer_logged_in ? 'customer_redirect' : 'redirect';

        $flash_func('error', 'The page you requested was not found.');
        $redirect_func('index.php?page=' . $redirect_page);
    } else {
        // Not logged in and requested page not found -> redirect to staff login
        flash_message('error', 'The page you requested was not found. Please log in.');
        redirect('index.php?page=login');
    }
}

// 2. Determine which header/footer template to use based on page type
$template_type = 'header'; // Default for staff/admin portal
if (in_array($page, $public_access_pages) && $page !== 'customer_view_voucher') {
    // Login/Register pages for staff/admin, customer login, public voucher view
    // These typically use a minimal header or the main header adapting for non-logged-in state
    // 'customer_view_voucher' has its own unique inline styling in its file.
    if ($page === 'customer_login') {
        $template_type = 'customer_header'; // Use customer template for customer login
    }
} elseif ($is_customer_logged_in || strpos($page, 'customer_') === 0) { // All other customer portal pages
    $template_type = 'customer_header'; // Use customer template
}


// 3. Enforce Authentication for Restricted Pages
if (!in_array($page, $public_access_pages)) { // If the page is NOT publicly accessible
    if ($is_staff_logged_in && !$is_customer_logged_in) {
        // User is logged into staff/admin portal. Allow access.
        // (Individual page files might have further role-based restrictions)
        $flash_func = 'flash_message';
        $redirect_func = 'redirect';
    } elseif ($is_customer_logged_in && !$is_staff_logged_in) {
        // User is logged into customer portal.
        // Check if they are trying to access a staff/admin page, which is forbidden.
        if (!($is_customer_portal_page || strpos($page, 'driver_') === 0)) { // Assuming drivers might access driver portal, but not full admin
            customer_flash_message('error', 'You do not have permission to access staff/admin pages.');
            customer_redirect('index.php?page=customer_dashboard');
        }
        $flash_func = 'customer_flash_message';
        $redirect_func = 'customer_redirect';
    } else {
        // Not logged in or mixed state, but page is not public. Force login.
        flash_message('error', 'You must be logged in to access this page.');
        redirect('index.php?page=login');
    }
}

// --- Debugging Helpers (Uncomment to see flow in your PHP error log) ---
// error_log("--- INDEX.PHP Debug ---");
// error_log("Requested Page: " . $page);
// error_log("Is Staff Logged In: " . ($is_staff_logged_in ? 'true' : 'false'));
// error_log("Is Customer Logged In: " . ($is_customer_logged_in ? 'true' : 'false'));
// error_log("Template Type: " . $template_type);
// error_log("--- End Debug ---");


// --- Include the Page Content ---
$page_file = $allowed_pages[$page]; // Get the actual file path

// The actual page content is included here.
// This page's content will then call `include_template('header', ...)` and `include_template('footer', ...)`
// using the determined $template_type.
if (file_exists($page_file)) {
    require_once $page_file;
} else {
    // This should ideally not be reached if allowed_pages array is correct and file_exists check works
    error_log('Critical System Error: Page file [' . $page_file . '] missing from server for page [' . $page . ']');
    if ($is_customer_logged_in) {
        customer_flash_message('error', 'A critical system file is missing. Please contact support.');
        customer_redirect('index.php?page=customer_dashboard');
    } elseif ($is_staff_logged_in) {
        flash_message('error', 'A critical system file is missing. Please contact support.');
        redirect('index.php?page=dashboard');
    } else {
        flash_message('error', 'A critical system file is missing. Please contact support.');
        redirect('index.php?page=login');
    }
}