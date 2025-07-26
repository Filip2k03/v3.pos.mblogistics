<?php
// dashboard.php
// This file is loaded by index.php, so config.php, db_connect.php, and functions.php are already loaded.
// Session start
// session_start();

// Basic authentication check: ensures user is logged in
if (!is_logged_in()) {
    // This redirect should ideally be handled by index.php for all protected pages,
    // but having it here acts as a failsafe.
    flash_message('error', 'Please log in to view the dashboard.');
    redirect('index.php?page=login');
}

global $connection; // Access the global database connection

// Initialize statistics variables
$total_vouchers = 0;
$pending_vouchers = 0;
$delivered_vouchers = 0;
$total_expenses_mmk = 0.00; // Initialize as float for calculations

// Array to store any specific errors during data fetching
$dashboard_errors = [];

try {
    // --- Fetch Total Vouchers ---
    $query_total_vouchers = "SELECT COUNT(*) AS total FROM vouchers";
    $result_total_vouchers = mysqli_query($connection, $query_total_vouchers);
    if ($result_total_vouchers) {
        $data = mysqli_fetch_assoc($result_total_vouchers);
        $total_vouchers = $data['total'];
        mysqli_free_result($result_total_vouchers);
    } else {
        $dashboard_errors[] = 'Error fetching total vouchers: ' . mysqli_error($connection);
    }

    // --- Fetch Pending Vouchers ---
    $query_pending_vouchers = "SELECT COUNT(*) AS total FROM vouchers WHERE status = 'Pending'";
    $result_pending_vouchers = mysqli_query($connection, $query_pending_vouchers);
    if ($result_pending_vouchers) {
        $data = mysqli_fetch_assoc($result_pending_vouchers);
        $pending_vouchers = $data['total'];
        mysqli_free_result($result_pending_vouchers);
    } else {
        $dashboard_errors[] = 'Error fetching pending vouchers: ' . mysqli_error($connection);
    }

    // --- Fetch Delivered Vouchers ---
    $query_delivered_vouchers = "SELECT COUNT(*) AS total FROM vouchers WHERE status = 'Delivered'";
    $result_delivered_vouchers = mysqli_query($connection, $query_delivered_vouchers);
    if ($result_delivered_vouchers) {
        $data = mysqli_fetch_assoc($result_delivered_vouchers);
        $delivered_vouchers = $data['total'];
        mysqli_free_result($result_delivered_vouchers);
    } else {
        $dashboard_errors[] = 'Error fetching delivered vouchers: ' . mysqli_error($connection);
    }

    // --- Fetch Total Expenses in MMK ---
    // Using prepared statement for robustness even without params if needed, or if currency becomes dynamic.
    $query_expenses_mmk = "SELECT SUM(amount) AS total FROM expenses WHERE currency = ?";
    $stmt_expenses_mmk = mysqli_prepare($connection, $query_expenses_mmk);
    if ($stmt_expenses_mmk) {
        $currency_to_sum = 'MMK';
        mysqli_stmt_bind_param($stmt_expenses_mmk, 's', $currency_to_sum);
        mysqli_stmt_execute($stmt_expenses_mmk);
        $result_expenses_mmk = mysqli_stmt_get_result($stmt_expenses_mmk);
        if ($result_expenses_mmk) {
            $data = mysqli_fetch_assoc($result_expenses_mmk);
            // Use null coalescing to handle potential null sum if no expenses exist
            $total_expenses_mmk = number_format((float)($data['total'] ?? 0), 2);
            mysqli_free_result($result_expenses_mmk);
        } else {
            $dashboard_errors[] = 'Error fetching expenses: ' . mysqli_stmt_error($stmt_expenses_mmk);
        }
        mysqli_stmt_close($stmt_expenses_mmk);
    } else {
        $dashboard_errors[] = 'Database statement preparation error for expenses: ' . mysqli_error($connection);
    }

} catch (Exception $e) {
    // Catch any unexpected exceptions during the process
    error_log("Dashboard data fetch unexpected error: " . $e->getMessage());
    $dashboard_errors[] = 'An unexpected error occurred while loading dashboard data.';
}

// Display any accumulated dashboard-specific errors as flash messages
if (!empty($dashboard_errors)) {
    flash_message('error', 'Dashboard data loading issues: <br>' . implode('<br>', $dashboard_errors));
}

// Include the header template
include_template('header', ['page' => 'dashboard']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Dashboard Overview</h2>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <div class="bg-blue-500 text-white p-6 rounded-lg shadow-lg flex flex-col items-center justify-center">
            <h3 class="text-xl font-bold mb-2">Total Vouchers</h3>
            <p class="text-4xl font-extrabold"><?php echo htmlspecialchars($total_vouchers); ?></p>
        </div>
        <div class="bg-yellow-500 text-white p-6 rounded-lg shadow-lg flex flex-col items-center justify-center">
            <h3 class="text-xl font-bold mb-2">Pending Vouchers</h3>
            <p class="text-4xl font-extrabold"><?php echo htmlspecialchars($pending_vouchers); ?></p>
        </div>
        <div class="bg-green-500 text-white p-6 rounded-lg shadow-lg flex flex-col items-center justify-center">
            <h3 class="text-xl font-bold mb-2">Delivered Vouchers</h3>
            <p class="text-4xl font-extrabold"><?php echo htmlspecialchars($delivered_vouchers); ?></p>
        </div>
        <div class="bg-purple-500 text-white p-6 rounded-lg shadow-lg flex flex-col items-center justify-center">
            <h3 class="text-xl font-bold mb-2">Expenses (MMK)</h3>
            <p class="text-4xl font-extrabold"><?php echo htmlspecialchars($total_expenses_mmk); ?></p>
        </div>
    </div>

    <div class="text-center p-4">
        <p class="text-gray-700 text-lg">Welcome back, <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></strong> (<?php echo htmlspecialchars($_SESSION['user_type'] ?? 'Guest'); ?>)!</p>
        <p class="text-gray-600">This is your central hub for MBLOGISTICS POS operations.</p>
    </div>

    </div>

<?php include_template('footer'); ?>