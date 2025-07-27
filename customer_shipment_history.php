<?php
// customer_shipment_history.php - Lists all vouchers for the logged-in customer.

// session_start();
require_once 'config.php';
require_once 'db_connect.php';
require_once INC_PATH . 'functions.php';

global $connection;

// Check customer login
customer_login_check();

$customer_id = $_SESSION['customer_id'];
$customer_phone = ''; // Will fetch customer's phone to match sender/receiver phone

// Fetch customer's phone number
$stmt_customer_phone = mysqli_prepare($connection, "SELECT phone_number FROM customers WHERE id = ?");
if ($stmt_customer_phone) {
    mysqli_stmt_bind_param($stmt_customer_phone, 'i', $customer_id);
    mysqli_stmt_execute($stmt_customer_phone);
    mysqli_stmt_bind_result($stmt_customer_phone, $phone_result);
    mysqli_stmt_fetch($stmt_customer_phone);
    $customer_phone = $phone_result;
    mysqli_stmt_close($stmt_customer_phone);
}

$vouchers = [];
$errors = [];

// Get filter parameters
$filter_status = $_GET['status'] ?? 'All'; // 'All', 'active', 'delivered'
$search_term = trim($_GET['search'] ?? '');

// Define possible voucher statuses for filtering (for display in dropdown)
$possible_statuses = ['All', 'Pending', 'In Transit', 'Delivered', 'Received', 'Cancelled', 'Returned'];


// --- Build the SQL query ---
$where_clauses = [];
$bind_params = '';
$bind_values = [];

// Primary filter: Link to customer_id OR sender/receiver phone
$where_clauses[] = "(v.customer_id = ? OR v.sender_phone = ? OR v.receiver_phone = ?)";
$bind_params .= 'iss';
$bind_values[] = $customer_id;
$bind_values[] = $customer_phone;
$bind_values[] = $customer_phone;

// Status filter
if ($filter_status === 'active') {
    $where_clauses[] = "v.status NOT IN (?, ?, ?, ?)";
    $bind_params .= 'ssss';
    $bind_values[] = 'Delivered';
    $bind_values[] = 'Completed'; // Assuming 'Completed' is a final status for consignments
    $bind_values[] = 'Cancelled';
    $bind_values[] = 'Returned';
} elseif ($filter_status === 'delivered') {
    $where_clauses[] = "v.status = ?";
    $bind_params .= 's';
    $bind_values[] = 'Delivered';
} elseif ($filter_status !== 'All') {
    // For specific statuses like 'Pending', 'In Transit', etc.
    if (in_array($filter_status, $possible_statuses) && $filter_status !== 'All') {
        $where_clauses[] = "v.status = ?";
        $bind_params .= 's';
        $bind_values[] = $filter_status;
    }
}

// Search term filter
if (!empty($search_term)) {
    $where_clauses[] = "(v.voucher_code LIKE ? OR v.sender_name LIKE ? OR v.receiver_name LIKE ?)";
    $bind_params .= 'sss';
    $bind_values[] = '%' . $search_term . '%';
    $bind_values[] = '%' . $search_term . '%';
    $bind_values[] = '%' . $search_term . '%';
}


$query = "SELECT v.id, v.voucher_code, v.sender_name, v.receiver_name, v.status, v.created_at,
                 r_origin.region_name AS origin_region_name,
                 r_dest.region_name AS destination_region_name
          FROM vouchers v
          JOIN regions r_origin ON v.region_id = r_origin.id
          JOIN regions r_dest ON v.destination_region_id = r_dest.id";

if (!empty($where_clauses)) {
    $query .= " WHERE " . implode(" AND ", $where_clauses);
}
$query .= " ORDER BY v.created_at DESC";

$stmt = mysqli_prepare($connection, $query);
if ($stmt) {
    if (!empty($bind_params)) {
        mysqli_stmt_bind_param($stmt, $bind_params, ...$bind_values);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $vouchers[] = $row;
    }
    mysqli_free_result($result);
    mysqli_stmt_close($stmt);
} else {
    $errors[] = 'Error fetching vouchers: ' . mysqli_error($connection);
}

if (!empty($errors)) {
    customer_flash_message('error', implode('<br>', $errors));
}

include_template('customer_header', ['page' => 'customer_shipment_history']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">My Shipment History</h2>

    <!-- Filter Form -->
    <form action="index.php" method="GET" class="bg-gray-100 mb-6 p-4 rounded-lg shadow-inner flex flex-wrap items-end gap-4">
        <input type="hidden" name="page" value="customer_shipment_history">

        <div>
            <label for="filter_status" class="block text-sm font-medium text-gray-700">Filter by Status:</label>
            <select id="filter_status" name="status" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                <option value="All" <?php echo ($filter_status === 'All') ? 'selected' : ''; ?>>All Shipments</option>
                <option value="active" <?php echo ($filter_status === 'active') ? 'selected' : ''; ?>>Active Shipments</option>
                <option value="delivered" <?php echo ($filter_status === 'delivered') ? 'selected' : ''; ?>>Delivered Shipments</option>
                <?php foreach ($possible_statuses as $status_option): ?>
                    <?php if (!in_array($status_option, ['All', 'active', 'delivered'])): ?>
                        <option value="<?php echo htmlspecialchars($status_option); ?>" <?php echo ($filter_status === $status_option) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($status_option); ?>
                        </option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex-grow">
            <label for="search_term" class="block text-sm font-medium text-gray-700">Search (Voucher Code, Name):</label>
            <input type="text" id="search_term" name="search" placeholder="Enter voucher code, sender/receiver name"
                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" value="<?php echo htmlspecialchars($search_term); ?>">
        </div>

        <div>
            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300">Filter / Search</button>
        </div>
    </form>

    <?php if (empty($vouchers)): ?>
        <div class="text-center py-10">
            <p class="text-gray-600 text-lg">No shipments found matching your criteria.</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto rounded-lg shadow-md border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Voucher Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sender</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Receiver</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Route</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created At</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($vouchers as $voucher): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($voucher['voucher_code']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($voucher['sender_name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($voucher['receiver_name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                <?php echo htmlspecialchars($voucher['origin_region_name'] . ' to ' . $voucher['destination_region_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    <?php
                                        switch ($voucher['status']) {
                                            case 'Pending': echo 'status-badge-pending'; break;
                                            case 'In Transit': echo 'status-badge-in-transit'; break;
                                            case 'Delivered': echo 'status-badge-delivered'; break;
                                            case 'Received': echo 'status-badge-received'; break;
                                            case 'Cancelled': echo 'status-badge-cancelled'; break;
                                            case 'Returned': echo 'status-badge-returned'; break;
                                            default: echo 'status-badge-default'; break;
                                        }
                                    ?>">
                                    <?php echo htmlspecialchars($voucher['status']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('Y-m-d H:i', strtotime($voucher['created_at'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="index.php?page=customer_voucher_details&id=<?php echo htmlspecialchars($voucher['id']); ?>" class="text-blue-600 hover:text-blue-800">View Details</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include_template('customer_footer'); ?>