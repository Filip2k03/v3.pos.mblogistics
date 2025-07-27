<?php
// includes/functions.php - Common helper functions

// Starts session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}



// Function to check if a staff/admin user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Function to check if a customer is logged in
function is_customer_logged_in() {
    return isset($_SESSION['customer_id']);
}

// Function to set a flash message for staff/admin portal
function flash_message($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

// Function to set a flash message for customer portal
function customer_flash_message($type, $message) {
    $_SESSION['customer_flash'] = ['type' => $type, 'message' => $message];
}

// Function to redirect for staff/admin portal
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// Function to redirect for customer portal
function customer_redirect($url) {
    header("Location: " . $url);
    exit();
}

// Function to check if the logged-in user is an admin
function is_admin() {
    return isset($_SESSION['user_type']) && defined('USER_TYPE_ADMIN') && $_SESSION['user_type'] === USER_TYPE_ADMIN;
}

// Function to include template parts (header/footer)
function include_template($template_name, $data = []) {
    extract($data);

    $header_path = TPL_PATH . 'header.php';
    $footer_path = TPL_PATH . 'footer.php';
    $customer_header_path = TPL_PATH . 'customer_header.php'; // NEW customer header
    $customer_footer_path = TPL_PATH . 'customer_footer.php'; // NEW customer footer

    if ($template_name === 'header') { // For staff/admin portal
        if (file_exists($header_path)) {
            require_once $header_path;
        } else {
            error_log("Template file not found: " . $header_path);
            echo "<!-- Header template missing! -->";
        }
    } elseif ($template_name === 'footer') { // For staff/admin portal
        if (file_exists($footer_path)) {
            require_once $footer_path;
        } else {
            error_log("Template file not found: " . $footer_path);
            echo "<!-- Footer template missing! -->";
        }
    } elseif ($template_name === 'customer_header') { // For customer portal
        if (file_exists($customer_header_path)) {
            require_once $customer_header_path;
        } else {
            error_log("Template file not found: " . $customer_header_path);
            echo "<!-- Customer Header template missing! -->";
        }
    } elseif ($template_name === 'customer_footer') { // For customer portal
        if (file_exists($customer_footer_path)) {
            require_once $customer_footer_path;
        } else {
            error_log("Template file not found: " . $customer_footer_path);
            echo "<!-- Customer Footer template missing! -->";
        }
    }
}

// Function to generate a new unique voucher code
function generate_voucher_code($prefix, $sequence) {
    return $prefix . str_pad($sequence, VOUCHER_CODE_LENGTH, '0', STR_PAD_LEFT);
}

// Function to generate a new unique consignment code.
function generate_consignment_code() {
    global $connection;

    $date_prefix = date('Ymd');
    $stmt_max_seq = mysqli_prepare($connection, "SELECT MAX(SUBSTRING(consignment_code, -" . CONSIGNMENT_CODE_LENGTH . ")) AS max_seq FROM consignments WHERE consignment_code LIKE ?");
    if($stmt_max_seq) {
        $search_pattern = CONSIGNMENT_CODE_PREFIX . '-' . $date_prefix . '-%';
        mysqli_stmt_bind_param($stmt_max_seq, 's', $search_pattern);
        mysqli_stmt_execute($stmt_max_seq);
        $result = mysqli_stmt_get_result($stmt_max_seq);
        $row = mysqli_fetch_assoc($result);
        $next_sequence = ($row['max_seq'] ? (int)$row['max_seq'] : 0) + 1;
        mysqli_stmt_close($stmt_max_seq);
    } else {
        error_log("Failed to get max consignment sequence: " . mysqli_error($connection));
        $next_sequence = 1;
    }

    return CONSIGNMENT_CODE_PREFIX . '-' . $date_prefix . '-' . str_pad($next_sequence, CONSIGNMENT_CODE_LENGTH, '0', STR_PAD_LEFT);
}


// Function to log voucher status changes
function log_voucher_status_change($voucher_id, $old_status, $new_status, $notes, $user_id) {
    global $connection;

    $username = 'System';
    $stmt_user = mysqli_prepare($connection, "SELECT username FROM users WHERE id = ?");
    if ($stmt_user) {
        mysqli_stmt_bind_param($stmt_user, 'i', $user_id);
        mysqli_stmt_execute($stmt_user);
        $result_user = mysqli_stmt_get_result($stmt_user);
        if ($row = mysqli_fetch_assoc($result_user)) {
            $username = $row['username'];
        }
        mysqli_stmt_close($stmt_user);
    }

    $stmt = mysqli_prepare($connection, "INSERT INTO voucher_status_log (voucher_id, old_status, new_status, notes, changed_by_user_id) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sssii', $voucher_id, $old_status, $new_status, $notes, $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        error_log("Failed to prepare statement for logging voucher status change: " . mysqli_error($connection));
    }
}

// Function to log consignment status changes.
function log_consignment_status_change($consignment_id, $old_status, $new_status, $notes, $user_id) {
    global $connection;

    $username = 'System';
    $stmt_user = mysqli_prepare($connection, "SELECT username FROM users WHERE id = ?");
    if ($stmt_user) {
        mysqli_stmt_bind_param($stmt_user, 'i', $user_id);
        mysqli_stmt_execute($stmt_user);
        $result_user = mysqli_stmt_get_result($stmt_user);
        if ($row = mysqli_fetch_assoc($result_user)) {
            $username = $row['username'];
        }
        mysqli_stmt_close($stmt_user);
    }

    $stmt = mysqli_prepare($connection, "INSERT INTO consignment_status_log (consignment_id, old_status, new_status, notes, changed_by_user_id) VALUES (?, ?, ?, ?, ?)");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'sssii', $consignment_id, $old_status, $new_status, $notes, $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        error_log("Failed to prepare statement for logging consignment status change: " . mysqli_error($connection));
    }
}

// Generates a QR code URL using QuickChart.io.
function generate_qr_code_url($data, $size = 200) {
    $encoded_data = urlencode($data);
    return 'https://quickchart.io/qr?text=' . $encoded_data . '&size=' . $size;
}

// Handles file uploads for Proof of Delivery.
function handle_pod_file_upload($file_data, $target_directory, $voucher_code) {
    if (!isset($file_data) || $file_data['error'] !== UPLOAD_ERR_OK) {
        error_log("POD Upload Error: " . ($file_data['error'] ?? 'No file data'));
        return false;
    }

    if (!is_dir($target_directory) && !mkdir($target_directory, 0755, true)) {
        error_log("POD Upload Error: Target directory does not exist or is not writable: " . $target_directory);
        return false;
    }

    $file_extension = pathinfo($file_data['name'], PATHINFO_EXTENSION);
    $unique_filename = $voucher_code . '_' . uniqid() . '.' . $file_extension;
    $target_file = $target_directory . $unique_filename;

    $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array(strtolower($file_extension), $allowed_extensions)) {
        error_log("POD Upload Error: Disallowed file type: " . $file_extension);
        return false;
    }
    if ($file_data['size'] > 5 * 1024 * 1024) { // 5MB limit
        error_log("POD Upload Error: File size too large: " . $file_data['size']);
        return false;
    }

    if (move_uploaded_file($file_data['tmp_name'], $target_file)) {
        return 'assets/pod_images/' . $unique_filename; // Path relative to project root
    } else {
        error_log("POD Upload Error: Failed to move uploaded file for voucher " . $voucher_code);
        return false;
    }
}

// Generic function to fetch master data from a specified table.
function get_master_data($table_name, $active_only = true) {
    global $connection;
    $data = [];

    $allowed_tables = ['payment_methods', 'delivery_types', 'item_types', 'currencies', 'regions']; // Added regions
    if (!in_array($table_name, $allowed_tables)) {
        error_log("Attempted to fetch master data from disallowed table: " . $table_name);
        return [];
    }

    $query = "SELECT id, name FROM `" . $table_name . "`";
    if ($table_name !== 'regions' && $active_only) { // Regions don't have is_active, fetch all
        $query .= " WHERE is_active = 1";
    }
    $query .= " ORDER BY name ASC"; // Order by name for consistency

    $result = mysqli_query($connection, $query);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $data[] = $row;
        }
        mysqli_free_result($result);
    } else {
        error_log("Error fetching master data from {$table_name}: " . mysqli_error($connection));
    }
    return $data;
}

// Function to check if a driver is assigned to a consignment (used in authorization)
function is_driver_assigned_to_consignment($consignment_id, $driver_id) {
    global $connection;
    $stmt = mysqli_prepare($connection, "SELECT COUNT(*) FROM consignments WHERE id = ? AND driver_id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $consignment_id, $driver_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $count);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
        return $count > 0;
    }
    return false;
}

// NEW: Check if customer is authorized to view a specific voucher
function is_customer_authorized_for_voucher($voucher_id, $customer_id) {
    global $connection;
    $stmt = mysqli_prepare($connection, "SELECT COUNT(*) FROM vouchers WHERE id = ? AND (customer_id = ? OR sender_phone = (SELECT phone_number FROM customers WHERE id = ?) OR receiver_phone = (SELECT phone_number FROM customers WHERE id = ?))");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'iiii', $voucher_id, $customer_id, $customer_id, $customer_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $count);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
        return $count > 0;
    }
    return false;
}

// NEW: Function to check if a customer is logged in and redirect if not
function customer_login_check() {
    if (!is_customer_logged_in()) {
        customer_flash_message('error', 'Please log in to access the customer portal.');
        customer_redirect('index.php?page=customer_login');
    }
}