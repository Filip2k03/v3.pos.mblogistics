<?php
// includes/functions.php - Common helper functions

// Starts session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Function to check if a user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Function to set a flash message
function flash_message($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

// Function to redirect to another page
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// Function to check if the logged-in user is an admin
function is_admin() {
    // Relies on USER_TYPE_ADMIN constant defined in config.php
    return isset($_SESSION['user_type']) && defined('USER_TYPE_ADMIN') && $_SESSION['user_type'] === USER_TYPE_ADMIN;
}

// Function to include template parts (header/footer)
function include_template($template_name, $data = []) {
    // Extract data array to create variables in the template scope
    extract($data);

    // Paths to templates (defined in config.php)
    $header_path = TPL_PATH . 'header.php';
    $footer_path = TPL_PATH . 'footer.php';

    if ($template_name === 'header') {
        if (file_exists($header_path)) {
            require_once $header_path;
        } else {
            error_log("Template file not found: " . $header_path);
            echo "";
        }
    } elseif ($template_name === 'footer') {
        if (file_exists($footer_path)) {
            require_once $footer_path;
        } else {
            error_log("Template file not found: " . $footer_path);
            echo "";
        }
    }
}

// Function to generate a new unique voucher code
// (Requires $connection and region prefix/current_sequence table structure)
function generate_voucher_code($prefix, $sequence) {
    // VOUCHER_CODE_LENGTH defined in config.php
    return $prefix . str_pad($sequence, VOUCHER_CODE_LENGTH, '0', STR_PAD_LEFT);
}

// Function to log voucher status changes
function log_voucher_status_change($voucher_id, $old_status, $new_status, $notes, $user_id) {
    global $connection;

    // Fetch username for logging (optional, but good for context in log)
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

/**
 * Generates a QR code URL using QuickChart.io.
 *
 * @param string $data The data to encode in the QR code (e.g., a URL).
 * @param int $size The desired size of the QR code image (e.g., 300 for 300x300px).
 * @return string The URL to the generated QR code image.
 */
function generate_qr_code_url($data, $size = 200) {
    // The data to be encoded in the QR code needs to be URL-encoded.
    $encoded_data = urlencode($data);
    // Construct the QuickChart.io QR code URL
    return 'https://quickchart.io/qr?text=' . $encoded_data . '&size=' . $size;
}
?>