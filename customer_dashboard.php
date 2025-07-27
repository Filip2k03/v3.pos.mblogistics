<?php
// customer_dashboard.php - Customer portal dashboard.

require_once 'config.php';
require_once 'db_connect.php';
require_once INC_PATH . 'functions.php';

global $connection;

// Check customer login
customer_login_check();

$customer_id = $_SESSION['customer_id'];
$customer_name = $_SESSION['customer_name'] ?? 'Customer';

$total_active_shipments = 0;
$total_delivered_shipments = 0;
$customer_errors = [];

try {
    // Fetch total active shipments for this customer (Pending, In Transit, etc.)
    $query_active = "SELECT COUNT(*) AS total FROM vouchers WHERE (customer_id = ? OR sender_phone = (SELECT phone_number FROM customers WHERE id = ?) OR receiver_phone = (SELECT phone_number FROM customers WHERE id = ?)) AND status NOT IN ('Delivered', 'Completed', 'Cancelled', 'Returned')";
    $stmt_active = mysqli_prepare($connection, $query_active);
    if ($stmt_active) {
        mysqli_stmt_bind_param($stmt_active, 'iii', $customer_id, $customer_id, $customer_id);
        mysqli_stmt_execute($stmt_active);
        $result_active = mysqli_stmt_get_result($stmt_active);
        $data = mysqli_fetch_assoc($result_active);
        $total_active_shipments = $data['total'];
        mysqli_free_result($result_active);
        mysqli_stmt_close($stmt_active);
    } else {
        $customer_errors[] = 'Error fetching active shipments: ' . mysqli_error($connection);
    }

    // Fetch total delivered shipments for this customer
    $query_delivered = "SELECT COUNT(*) AS total FROM vouchers WHERE (customer_id = ? OR sender_phone = (SELECT phone_number FROM customers WHERE id = ?) OR receiver_phone = (SELECT phone_number FROM customers WHERE id = ?)) AND status = 'Delivered'";
    $stmt_delivered = mysqli_prepare($connection, $query_delivered);
    if ($stmt_delivered) {
        mysqli_stmt_bind_param($stmt_delivered, 'iii', $customer_id, $customer_id, $customer_id);
        mysqli_stmt_execute($stmt_delivered);
        $result_delivered = mysqli_stmt_get_result($stmt_delivered);
        $data = mysqli_fetch_assoc($result_delivered);
        $total_delivered_shipments = $data['total'];
        mysqli_free_result($result_delivered);
        mysqli_stmt_close($stmt_delivered);
    } else {
        $customer_errors[] = 'Error fetching delivered shipments: ' . mysqli_error($connection);
    }

} catch (Exception $e) {
    error_log("Customer Dashboard Error: " . $e->getMessage());
    $customer_errors[] = 'An unexpected error occurred while loading dashboard data.';
}

if (!empty($customer_errors)) {
    customer_flash_message('error', implode('<br>', $customer_errors));
}

include_template('customer_header', ['page' => 'customer_dashboard']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Your Dashboard</h2>
    <p class="text-lg text-gray-700 mb-8 text-center">Welcome, <strong><?php echo htmlspecialchars($customer_name); ?></strong>! Here's a summary of your shipments.</p>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        <div class="bg-blue-500 text-white p-6 rounded-lg shadow-lg flex flex-col items-center justify-center">
            <h3 class="text-xl font-bold mb-2">Active Shipments</h3>
            <p class="text-4xl font-extrabold"><?php echo htmlspecialchars($total_active_shipments); ?></p>
            <a href="index.php?page=customer_shipment_history&status=active" class="mt-4 text-sm underline hover:text-blue-200">View Active</a>
        </div>
        <div class="bg-green-500 text-white p-6 rounded-lg shadow-lg flex flex-col items-center justify-center">
            <h3 class="text-xl font-bold mb-2">Delivered Shipments</h3>
            <p class="text-4xl font-extrabold"><?php echo htmlspecialchars($total_delivered_shipments); ?></p>
            <a href="index.php?page=customer_shipment_history&status=delivered" class="mt-4 text-sm underline hover:text-green-200">View Delivered</a>
        </div>
    </div>

    <div class="text-center p-4">
        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Quick Actions</h3>
        <a href="index.php?page=customer_shipment_history" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300">View All Shipments</a>
        <!-- Add link to financial statement if implemented -->
        <!-- <a href="index.php?page=customer_financial_statement" class="ml-4 bg-purple-600 hover:bg-purple-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300">My Statements</a> -->
    </div>
</div>

<?php include_template('customer_footer'); ?>