<?php
// config.php
// Set error reporting for development (turn off in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
$hostname = "localhost";
$username = "stephan"; // Your DB username
$password = "stephan2k03";     // Your DB password
$database = "pos_mblogistics_v3"; // Your database name

// $hostname = "localhost";
// $username = "zpxcdpsz_filip";
// $password = "T6#N1Hyezr#n.fSi";
// $database = "zpxcdpsz_v3_pos";

// Application Configuration
define('APP_NAME', 'MBLOGISTICS POS');
define('VOUCHER_CODE_LENGTH', 7); // e.g., 0000001
define('DEFAULT_TIMEZONE', 'Asia/Yangon'); // GMT +6:30 for Myanmar

// User type constants (used for role-based access control)
define('USER_TYPE_ADMIN', 'ADMIN');
define('USER_TYPE_MYANMAR', 'Myanmar');
define('USER_TYPE_MALAY', 'Malay');
define('USER_TYPE_STAFF', 'Staff');
define('USER_TYPE_DRIVER', 'Driver');

// Paths (adjust if your includes folder is elsewhere)
define('INC_PATH', __DIR__ . '/includes/');
define('TPL_PATH', __DIR__ . '/templates/');

// Set default timezone for all date/time functions
date_default_timezone_set(DEFAULT_TIMEZONE);