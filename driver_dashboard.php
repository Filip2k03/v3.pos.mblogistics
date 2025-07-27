<?php
// driver_dashboard.php - Dashboard for drivers to view their assigned tasks.

session_start();
require_once 'config.php';
require_once 'db_connect.php';
require_once INC_PATH . 'functions.php';

if (!is_logged_in()) {
    flash_message('error', 'Please log in to access the driver dashboard.');
    redirect('index.php?page=login');
}

global $connection;
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? null;

// Authorization: Only drivers can access this page
if ($user_type !== USER_TYPE_DRIVER && !is_admin()) { // Admin can also view for testing
    flash_message('error', 'You do not have permission to access the driver dashboard.');
    redirect('index.php?page=dashboard'); // Redirect to main dashboard or login
}

$driver_name = $_SESSION['username'] ?? 'Driver'; // Default to username if full_name not in session
$driver_id = $user_id;

// Fetch driver's full name if available
$stmt_driver_name = mysqli_prepare($connection, "SELECT full_name FROM users WHERE id = ?");
if ($stmt_driver_name) {
    mysqli_stmt_bind_param($stmt_driver_name, 'i', $user_id);
    mysqli_stmt_execute($stmt_driver_name);
    $result_driver_name = mysqli_stmt_get_result($stmt_driver_name);
    if ($row_driver_name = mysqli_fetch_assoc($result_driver_name)) {
        $driver_name = htmlspecialchars($row_driver_name['full_name'] ?: $_SESSION['username']);
    }
    mysqli_stmt_close($stmt_driver_name);
}


$today = date('Y-m-d');
$assigned_vouchers = [];
$assigned_consignments = [];
$errors = [];

try {
    // Fetch individual vouchers assigned to this driver for today
    // Vouchers can be assigned directly or via a consignment
    $query_vouchers = "SELECT v.id, v.voucher_code, v.sender_name, v.receiver_name, v.receiver_address, v.receiver_phone,
                              v.status, v.consignment_id,
                              r_origin.region_name AS origin_region_name,
                              r_dest.region_name AS destination_region_name,
                              c.consignment_code, c.name AS consignment_name, c.status AS consignment_status
                       FROM vouchers v
                       LEFT JOIN consignments c ON v.consignment_id = c.id
                       JOIN regions r_origin ON v.region_id = r_origin.id
                       JOIN regions r_dest ON v.destination_region_id = r_dest.id
                       WHERE (v.assigned_driver_id = ? OR c.driver_id = ?) -- Assigned directly or via consignment
                         AND DATE(v.created_at) = ? -- For simplicity, show today's created vouchers. Adjust as needed.
                       ORDER BY v.consignment_id ASC, v.created_at ASC";

    $stmt_vouchers = mysqli_prepare($connection, $query_vouchers);
    if ($stmt_vouchers) {
        mysqli_stmt_bind_param($stmt_vouchers, 'iis', $driver_id, $driver_id, $today);
        mysqli_stmt_execute($stmt_vouchers);
        $result_vouchers = mysqli_stmt_get_result($stmt_vouchers);
        while ($row = mysqli_fetch_assoc($result_vouchers)) {
            $assigned_vouchers[] = $row;
        }
        mysqli_free_result($result_vouchers);
        mysqli_stmt_close($stmt_vouchers);
    } else {
        $errors[] = 'Error fetching assigned vouchers: ' . mysqli_error($connection);
    }

    // Fetch consignments assigned to this driver for today
    $query_consignments = "SELECT c.id, c.consignment_code, c.name, c.status, c.expected_delivery_date, c.route_details
                           FROM consignments c
                           WHERE c.driver_id = ? AND DATE(c.created_at) = ? -- Or based on expected_delivery_date
                           ORDER BY c.created_at ASC";
    $stmt_consignments = mysqli_prepare($connection, $query_consignments);
    if ($stmt_consignments) {
        mysqli_stmt_bind_param($stmt_consignments, 'is', $driver_id, $today);
        mysqli_stmt_execute($stmt_consignments);
        $result_consignments = mysqli_stmt_get_result($stmt_consignments);
        while ($row = mysqli_fetch_assoc($result_consignments)) {
            $assigned_consignments[] = $row;
        }
        mysqli_free_result($result_consignments);
        mysqli_stmt_close($stmt_consignments);
    } else {
        $errors[] = 'Error fetching assigned consignments: ' . mysqli_error($connection);
    }

} catch (Exception $e) {
    error_log("Driver Dashboard Error: " . $e->getMessage());
    $errors[] = 'An unexpected error occurred while loading your tasks.';
}

if (!empty($errors)) {
    flash_message('error', implode('<br>', $errors));
}

include_template('header', ['page' => 'driver_dashboard']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Driver Dashboard</h2>
    <p class="text-lg text-gray-700 mb-4 text-center">Welcome, <strong><?php echo $driver_name; ?></strong>! Here are your tasks for today (<?php echo date('Y-m-d'); ?>).</p>

    <!-- Consignments Section -->
    <div class="bg-blue-50 p-6 rounded-lg shadow-inner mb-8">
        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Assigned Consignments</h3>
        <?php if (empty($assigned_consignments)): ?>
            <p class="text-center text-gray-600">No consignments assigned for today.</p>
        <?php else: ?>
            <div class="overflow-x-auto rounded-lg shadow-md border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Expected Delivery</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($assigned_consignments as $consignment): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <a href="index.php?page=consignment_view&id=<?php echo htmlspecialchars($consignment['id']); ?>" class="text-blue-600 hover:text-blue-800">
                                        <?php echo htmlspecialchars($consignment['consignment_code']); ?>
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($consignment['name'] ?: 'N/A'); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php
                                            switch ($consignment['status']) {
                                                case 'Pending': echo 'bg-yellow-100 text-yellow-800'; break;
                                                case 'Departed': echo 'bg-indigo-100 text-indigo-800'; break;
                                                case 'In Transit': echo 'bg-blue-100 text-blue-800'; break;
                                                case 'Arrived at Hub': echo 'bg-green-100 text-green-800'; break;
                                                case 'Out for Delivery': echo 'bg-purple-100 text-purple-800'; break;
                                                case 'Completed': echo 'bg-teal-100 text-teal-800'; break;
                                                case 'Cancelled': echo 'bg-red-100 text-red-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800'; break;
                                            }
                                        ?>">
                                        <?php echo htmlspecialchars($consignment['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($consignment['expected_delivery_date'] ?: 'N/A'); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="index.php?page=consignment_view&id=<?php echo htmlspecialchars($consignment['id']); ?>" class="text-indigo-600 hover:text-indigo-900">View Details</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Individual Vouchers Section -->
    <div class="bg-green-50 p-6 rounded-lg shadow-inner mb-8">
        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Assigned Vouchers (Direct or within Consignments)</h3>
        <?php if (empty($assigned_vouchers)): ?>
            <p class="text-center text-gray-600">No individual vouchers assigned for today.</p>
        <?php else: ?>
            <div class="overflow-x-auto rounded-lg shadow-md border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Voucher Code</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sender</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Receiver</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Consignment</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($assigned_vouchers as $voucher): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($voucher['voucher_code']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($voucher['sender_name']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                    <?php echo htmlspecialchars($voucher['receiver_name']); ?><br>
                                    <span class="text-gray-500 text-xs"><?php echo htmlspecialchars($voucher['receiver_phone']); ?></span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php
                                            switch ($voucher['status']) {
                                                case 'Pending': echo 'bg-yellow-100 text-yellow-800'; break;
                                                case 'In Transit': echo 'bg-blue-100 text-blue-800'; break;
                                                case 'Delivered': echo 'bg-green-100 text-green-800'; break;
                                                case 'Received': echo 'bg-teal-100 text-teal-800'; break;
                                                case 'Cancelled': echo 'bg-red-100 text-red-800'; break;
                                                case 'Returned': echo 'bg-purple-100 text-purple-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800'; break;
                                            }
                                        ?>">
                                        <?php echo htmlspecialchars($voucher['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                    <?php if (!empty($voucher['consignment_code'])): ?>
                                        <a href="index.php?page=consignment_view&id=<?php echo htmlspecialchars($voucher['consignment_id']); ?>" class="text-blue-600 hover:text-blue-800">
                                            <?php echo htmlspecialchars($voucher['consignment_code']); ?>
                                        </a>
                                    <?php else: ?>
                                        N/A
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="index.php?page=driver_voucher_detail&id=<?php echo htmlspecialchars($voucher['id']); ?>" class="text-indigo-600 hover:text-indigo-900">View/Update</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include_template('footer'); ?>