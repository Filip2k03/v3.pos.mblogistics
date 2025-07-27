<?php
// dashboard.php - Provides a quick overview of key system statistics.

// This file is loaded by index.php, so config.php, db_connect.php, and functions.php are already loaded.
// Session is already started.

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
$total_expenses_mmk = 0.00;
$total_consignments = 0; // NEW
$consignments_in_transit = 0; // NEW
$consignments_completed = 0; // NEW
$total_customers = 0; // NEW
$total_staff = 0; // NEW

// Array to store any specific errors during data fetching
$dashboard_errors = [];

try {
    // --- Voucher Statistics ---
    $query_total_vouchers = "SELECT COUNT(*) AS total FROM vouchers";
    $result_total_vouchers = mysqli_query($connection, $query_total_vouchers);
    if ($result_total_vouchers) {
        $data = mysqli_fetch_assoc($result_total_vouchers);
        $total_vouchers = $data['total'];
        mysqli_free_result($result_total_vouchers);
    } else {
        $dashboard_errors[] = 'Error fetching total vouchers: ' . mysqli_error($connection);
    }

    $query_pending_vouchers = "SELECT COUNT(*) AS total FROM vouchers WHERE status = 'Pending'";
    $result_pending_vouchers = mysqli_query($connection, $query_pending_vouchers);
    if ($result_pending_vouchers) {
        $data = mysqli_fetch_assoc($result_pending_vouchers);
        $pending_vouchers = $data['total'];
        mysqli_free_result($result_pending_vouchers);
    } else {
        $dashboard_errors[] = 'Error fetching pending vouchers: ' . mysqli_error($connection);
    }

    $query_delivered_vouchers = "SELECT COUNT(*) AS total FROM vouchers WHERE status = 'Delivered'";
    $result_delivered_vouchers = mysqli_query($connection, $query_delivered_vouchers);
    if ($result_delivered_vouchers) {
        $data = mysqli_fetch_assoc($result_delivered_vouchers);
        $delivered_vouchers = $data['total'];
        mysqli_free_result($result_delivered_vouchers);
    } else {
        $dashboard_errors[] = 'Error fetching delivered vouchers: ' . mysqli_error($connection);
    }

    // --- Consignment Statistics (NEW) ---
    $query_total_consignments = "SELECT COUNT(*) AS total FROM consignments";
    $result_total_consignments = mysqli_query($connection, $query_total_consignments);
    if ($result_total_consignments) {
        $data = mysqli_fetch_assoc($result_total_consignments);
        $total_consignments = $data['total'];
        mysqli_free_result($result_total_consignments);
    } else {
        $dashboard_errors[] = 'Error fetching total consignments: ' . mysqli_error($connection);
    }

    $query_consignments_in_transit = "SELECT COUNT(*) AS total FROM consignments WHERE status IN ('Departed', 'In Transit', 'Out for Delivery')";
    $result_consignments_in_transit = mysqli_query($connection, $query_consignments_in_transit);
    if ($result_consignments_in_transit) {
        $data = mysqli_fetch_assoc($result_consignments_in_transit);
        $consignments_in_transit = $data['total'];
        mysqli_free_result($result_consignments_in_transit);
    } else {
        $dashboard_errors[] = 'Error fetching in-transit consignments: ' . mysqli_error($connection);
    }

    $query_consignments_completed = "SELECT COUNT(*) AS total FROM consignments WHERE status = 'Completed'";
    $result_consignments_completed = mysqli_query($connection, $query_consignments_completed);
    if ($result_consignments_completed) {
        $data = mysqli_fetch_assoc($result_consignments_completed);
        $consignments_completed = $data['total'];
        mysqli_free_result($result_consignments_completed);
    } else {
        $dashboard_errors[] = 'Error fetching completed consignments: ' . mysqli_error($connection);
    }

    // --- Expense Statistics ---
    $query_expenses_mmk = "SELECT SUM(amount) AS total FROM expenses WHERE currency = ?";
    $stmt_expenses_mmk = mysqli_prepare($connection, $query_expenses_mmk);
    if ($stmt_expenses_mmk) {
        $currency_to_sum = 'MMK';
        mysqli_stmt_bind_param($stmt_expenses_mmk, 's', $currency_to_sum);
        mysqli_stmt_execute($stmt_expenses_mmk);
        $result_expenses_mmk = mysqli_stmt_get_result($stmt_expenses_mmk);
        if ($result_expenses_mmk) {
            $data = mysqli_fetch_assoc($result_expenses_mmk);
            $total_expenses_mmk = number_format((float)($data['total'] ?? 0), 2);
            mysqli_free_result($result_expenses_mmk);
        } else {
            $dashboard_errors[] = 'Error fetching expenses: ' . mysqli_stmt_error($stmt_expenses_mmk);
        }
        mysqli_stmt_close($stmt_expenses_mmk);
    } else {
        $dashboard_errors[] = 'Database statement preparation error for expenses: ' . mysqli_error($connection);
    }

    // --- Customer Statistics (NEW) ---
    $query_total_customers = "SELECT COUNT(*) AS total FROM customers";
    $result_total_customers = mysqli_query($connection, $query_total_customers);
    if ($result_total_customers) {
        $data = mysqli_fetch_assoc($result_total_customers);
        $total_customers = $data['total'];
        mysqli_free_result($result_total_customers);
    } else {
        $dashboard_errors[] = 'Error fetching total customers: ' . mysqli_error($connection);
    }

    // --- Staff Statistics (NEW) ---
    $query_total_staff = "SELECT COUNT(*) AS total FROM users WHERE user_type != 'ADMIN' AND is_active = 1"; // Exclude Admins and inactive
    $result_total_staff = mysqli_query($connection, $query_total_staff);
    if ($result_total_staff) {
        $data = mysqli_fetch_assoc($result_total_staff);
        $total_staff = $data['total'];
        mysqli_free_result($result_total_staff);
    } else {
        $dashboard_errors[] = 'Error fetching total staff: ' . mysqli_error($connection);
    }


} catch (Exception $e) {
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

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 mb-8">
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

        <div class="bg-indigo-500 text-white p-6 rounded-lg shadow-lg flex flex-col items-center justify-center">
            <h3 class="text-xl font-bold mb-2">Total Consignments</h3>
            <p class="text-4xl font-extrabold"><?php echo htmlspecialchars($total_consignments); ?></p>
        </div>
        <div class="bg-blue-600 text-white p-6 rounded-lg shadow-lg flex flex-col items-center justify-center">
            <h3 class="text-xl font-bold mb-2">Consignments In Transit</h3>
            <p class="text-4xl font-extrabold"><?php echo htmlspecialchars($consignments_in_transit); ?></p>
        </div>
        <div class="bg-teal-500 text-white p-6 rounded-lg shadow-lg flex flex-col items-center justify-center">
            <h3 class="text-xl font-bold mb-2">Completed Consignments</h3>
            <p class="text-4xl font-extrabold"><?php echo htmlspecialchars($consignments_completed); ?></p>
        </div>

        <div class="bg-purple-500 text-white p-6 rounded-lg shadow-lg flex flex-col items-center justify-center">
            <h3 class="text-xl font-bold mb-2">Expenses (MMK)</h3>
            <p class="text-4xl font-extrabold"><?php echo htmlspecialchars($total_expenses_mmk); ?></p>
        </div>

        <div class="bg-pink-500 text-white p-6 rounded-lg shadow-lg flex flex-col items-center justify-center">
            <h3 class="text-xl font-bold mb-2">Total Customers</h3>
            <p class="text-4xl font-extrabold"><?php echo htmlspecialchars($total_customers); ?></p>
        </div>

        <div class="bg-gray-700 text-white p-6 rounded-lg shadow-lg flex flex-col items-center justify-center">
            <h3 class="text-xl font-bold mb-2">Active Staff</h3>
            <p class="text-4xl font-extrabold"><?php echo htmlspecialchars($total_staff); ?></p>
        </div>
    </div>

    <div class="text-center p-4">
        <p class="text-gray-700 text-lg">Welcome back, <strong><?php echo htmlspecialchars($_SESSION['username'] ?? 'User'); ?></strong> (<?php echo htmlspecialchars($_SESSION['user_type'] ?? 'Guest'); ?>)!</p>
        <p class="text-gray-600">This is your central hub for MBLOGISTICS POS operations.</p>
    </div>

</div>

<?php include_template('footer'); ?>