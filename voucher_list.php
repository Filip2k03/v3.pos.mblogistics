<?php
// voucher_list.php

// This file is loaded by index.php, so config.php, db_connect.php, and functions.php are already loaded.
// Session is already started.

if (!is_logged_in()) {
    flash_message('error', 'Please log in to view vouchers.');
    redirect('index.php?page=login');
}

global $connection;

// Fetch user's region and type
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
$user_region_id = null;
$is_admin = is_admin();

if ($user_id) {
    $stmt_user_info = mysqli_prepare($connection, "SELECT region_id FROM users WHERE id = ?");
    if ($stmt_user_info) {
        mysqli_stmt_bind_param($stmt_user_info, 'i', $user_id);
        mysqli_stmt_execute($stmt_user_info);
        $result_user_info = mysqli_stmt_get_result($stmt_user_info);
        if ($user_info = mysqli_fetch_assoc($result_user_info)) {
            $user_region_id = $user_info['region_id'];
        }
        mysqli_free_result($result_user_info);
        mysqli_stmt_close($stmt_user_info);
    } else {
        error_log('Error fetching user region for voucher_list: ' . mysqli_error($connection));
    }
}


// Define possible voucher statuses for filtering
$possible_statuses = ['All', 'Pending', 'In Transit', 'Delivered', 'Received', 'Cancelled', 'Returned'];

// Get filter parameters from GET request
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$filter_origin_region_id = $_GET['origin_region_id'] ?? 'All'; // Filter by origin region
$filter_destination_region_id = $_GET['destination_region_id'] ?? 'All'; // Filter by destination region
$filter_consignment_id = $_GET['consignment_id'] ?? 'All'; // NEW: Filter by Consignment
$filter_status = $_GET['status'] ?? 'All';
$search_term = trim($_GET['search'] ?? '');
$search_column = $_GET['search_column'] ?? 'voucher_code'; // Default search column

$vouchers = [];
$errors = [];

// Fetch all regions for the filter dropdowns
$regions = [];
$stmt_regions = mysqli_query($connection, "SELECT id, region_name, prefix FROM regions ORDER BY region_name");
if ($stmt_regions) {
    while ($row = mysqli_fetch_assoc($stmt_regions)) {
        $regions[] = $row;
    }
    mysqli_free_result($stmt_regions);
} else {
    flash_message('error', 'Error loading regions: ' . mysqli_error($connection));
}

// Fetch consignments for filter dropdown (NEW)
$consignments_for_filter = [];
$stmt_consignments_filter = mysqli_query($connection, "SELECT id, consignment_code, name FROM consignments ORDER BY consignment_code DESC");
if ($stmt_consignments_filter) {
    while ($row = mysqli_fetch_assoc($stmt_consignments_filter)) {
        $consignments_for_filter[] = $row;
    }
    mysqli_free_result($stmt_consignments_filter);
} else {
    flash_message('error', 'Error loading consignments for filter: ' . mysqli_error($connection));
}


// --- Build the SQL query with filters ---
$where_clauses = [];
$bind_params = '';
$bind_values = [];

// Apply user-specific filter for Myanmar/Malay users, UNLESS it's an admin
if (!$is_admin && ($user_type === USER_TYPE_MYANMAR || $user_type === USER_TYPE_MALAY) && $user_region_id) {
    // Build a sub-clause for regional users based on status
    $regional_user_status_clause = [];
    $regional_user_param_types = '';
    $regional_user_param_values = [];

    // Rule 1: Pending vouchers are only visible if origin region matches user's region
    $regional_user_status_clause[] = "(v.status = ? AND v.region_id = ?)";
    $regional_user_param_types .= 'si';
    $regional_user_param_values[] = 'Pending';
    $regional_user_param_values[] = $user_region_id;

    // Rule 2: Delivered vouchers are visible regardless of region for Myanmar/Malay users
    $regional_user_status_clause[] = "(v.status = ?)";
    $regional_user_param_types .= 's';
    $regional_user_param_values[] = 'Delivered';


    // Rule 3: Other statuses (In Transit, Cancelled, Returned) are visible if user's region is origin OR destination
    $regional_user_status_clause[] = "(v.status IN (?,?,?) AND (v.region_id = ? OR v.destination_region_id = ?))";
    $regional_user_param_types .= 'sssi';
    $regional_user_param_values[] = 'In Transit';
    $regional_user_param_values[] = 'Cancelled';
    $regional_user_param_values[] = 'Returned';
    $regional_user_param_values[] = $user_region_id;
    $regional_user_param_values[] = $user_region_id;


    $where_clauses[] = "(" . implode(" OR ", $regional_user_status_clause) . ")";
    $bind_params .= $regional_user_param_types;
    $bind_values = array_merge($bind_values, $regional_user_param_values);

    // If a filter_status is applied, we need to make sure it aligns with the combined logic
    if ($filter_status !== 'All') {
        $where_clauses[] = "v.status = ?";
        $bind_params .= 's';
        $bind_values[] = $filter_status;
    }

} else {
    // For Admins or other non-Myanmar/Malay user types, apply general status filter
    if ($filter_status !== 'All') {
        $where_clauses[] = "v.status = ?";
        $bind_params .= 's';
        $bind_values[] = $filter_status;
    }
}


// Date range filter
if (!empty($start_date)) {
    $where_clauses[] = "DATE(v.created_at) >= ?";
    $bind_params .= 's';
    $bind_values[] = $start_date;
}
if (!empty($end_date)) {
    $where_clauses[] = "DATE(v.created_at) <= ?";
    $bind_params .= 's';
    $bind_values[] = $end_date;
}

// Region filters (applied on top of user-specific filter if present, or independently for admin)
if ($filter_origin_region_id !== 'All' && is_numeric($filter_origin_region_id)) {
    $where_clauses[] = "v.region_id = ?";
    $bind_params .= 'i';
    $bind_values[] = intval($filter_origin_region_id);
}

if ($filter_destination_region_id !== 'All' && is_numeric($filter_destination_region_id)) {
    $where_clauses[] = "v.destination_region_id = ?";
    $bind_params .= 'i';
    $bind_values[] = intval($filter_destination_region_id);
}

// Consignment Filter (NEW)
if ($filter_consignment_id !== 'All' && is_numeric($filter_consignment_id)) {
    $where_clauses[] = "v.consignment_id = ?";
    $bind_params .= 'i';
    $bind_values[] = intval($filter_consignment_id);
} elseif ($filter_consignment_id === 'None') { // Allow filtering for vouchers not assigned to any consignment
    $where_clauses[] = "v.consignment_id IS NULL";
}


// Search term filter
if (!empty($search_term)) {
    $allowed_search_columns = ['voucher_code', 'sender_name', 'receiver_name', 'receiver_phone'];
    if (in_array($search_column, $allowed_search_columns)) {
        $where_clauses[] = "v.$search_column LIKE ?";
        $bind_params .= 's';
        $bind_values[] = '%' . $search_term . '%';
    } else {
        $where_clauses[] = "v.voucher_code LIKE ?";
        $bind_params .= 's';
        $bind_values[] = '%' . $search_term . '%';
    }
}

$query = "SELECT v.*,
                     r_origin.region_name AS origin_region_name,
                     r_dest.region_name AS destination_region_name,
                     u.username AS created_by_username,
                     c.consignment_code -- NEW: Consignment Code
            FROM vouchers v
            JOIN regions r_origin ON v.region_id = r_origin.id
            JOIN regions r_dest ON v.destination_region_id = r_dest.id
            JOIN users u ON v.created_by_user_id = u.id
            LEFT JOIN consignments c ON v.consignment_id = c.id -- NEW: Join consignments
            ";

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
    flash_message('error', 'Error fetching vouchers: ' . mysqli_error($connection));
}

// Display any accumulated errors
if (!empty($errors)) {
    flash_message('error', implode('<br>', $errors));
}

include_template('header', ['page' => 'voucher_list']);
?>

<div class="bg-sky-50 text-gray-800 font-semibold py-2 px-4 rounded-lg shadow-md transition duration-300">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Voucher List</h2>

    <form action="index.php" method="GET" class="bg-sky-100 mb-6 p-4 rounded-lg shadow-inner flex flex-wrap items-end gap-4">
        <input type="hidden" name="page" value="voucher_list">

        <div>
            <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date:</label>
            <input type="date" id="start_date" name="start_date" class="form-input" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>
        <div>
            <label for="end_date" class="block text-sm font-medium text-gray-700">End Date:</label>
            <input type="date" id="end_date" name="end_date" class="form-input" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>

        <div>
            <label for="filter_origin_region_id" class="block text-sm font-medium text-gray-700">Filter by Origin Region:</label>
            <select id="filter_origin_region_id" name="origin_region_id" class="form-select">
                <option value="All">All Origins</option>
                <?php foreach ($regions as $region_option): ?>
                    <option value="<?php echo htmlspecialchars($region_option['id']); ?>" <?php echo (strval($filter_origin_region_id) === strval($region_option['id'])) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($region_option['region_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="filter_destination_region_id" class="block text-sm font-medium text-gray-700">Filter by Destination Region:</label>
            <select id="filter_destination_region_id" name="destination_region_id" class="form-select">
                <option value="All">All Destinations</option>
                <?php foreach ($regions as $region_option): ?>
                    <option value="<?php echo htmlspecialchars($region_option['id']); ?>" <?php echo (strval($filter_destination_region_id) === strval($region_option['id'])) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($region_option['region_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="filter_consignment_id" class="block text-sm font-medium text-gray-700">Filter by Consignment:</label>
            <select id="filter_consignment_id" name="consignment_id" class="form-select">
                <option value="All">All Consignments</option>
                <option value="None" <?php echo ($filter_consignment_id === 'None') ? 'selected' : ''; ?>>Not Assigned</option>
                <?php foreach ($consignments_for_filter as $cons_option): ?>
                    <option value="<?php echo htmlspecialchars($cons_option['id']); ?>" <?php echo (strval($filter_consignment_id) === strval($cons_option['id'])) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cons_option['consignment_code']); ?> (<?php echo htmlspecialchars($cons_option['name'] ?: 'No Name'); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="filter_status" class="block text-sm font-medium text-gray-700">Filter by Status:</label>
            <select id="filter_status" name="status" class="form-select">
                <option value="All">All Statuses</option>
                <?php foreach ($possible_statuses as $status_option): ?>
                    <option value="<?php echo htmlspecialchars($status_option); ?>" <?php echo ($filter_status === $status_option) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($status_option); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex-grow flex items-end">
            <div class="flex flex-col w-full">
                <label for="search_term" class="block text-sm font-medium text-gray-700">Search:</label>
                <div class="flex w-full">
                    <select name="search_column" class="form-select rounded-r-none border-r-0 max-w-[150px]">
                        <option value="voucher_code" <?php echo ($search_column === 'voucher_code') ? 'selected' : ''; ?>>Voucher Code</option>
                        <option value="sender_name" <?php echo ($search_column === 'sender_name') ? 'selected' : ''; ?>>Sender Name</option>
                        <option value="receiver_name" <?php echo ($search_column === 'receiver_name') ? 'selected' : ''; ?>>Receiver Name</option>
                        <option value="receiver_phone" <?php echo ($search_column === 'receiver_phone') ? 'selected' : ''; ?>>Receiver Phone</option>
                    </select>
                    <input type="text" id="search_term" name="search" placeholder="Enter search term..."
                                class="form-input flex-grow rounded-l-none" value="<?php echo htmlspecialchars($search_term); ?>">
                </div>
            </div>
            <button type="submit" class="btn btn-indigo ml-2 px-4 py-2 rounded-md">Filter / Search</button>
        </div>
    </form>

    <div class="flex justify-end mb-4">
        <a href="export_vouchers.php?<?php echo http_build_query($_GET); ?>" class="btn btn-purple px-6 py-2 rounded-md">Export Vouchers</a>
    </div>

    <?php if (empty($vouchers)): ?>
        <div class="text-center py-10">
            <p class="text-gray-600 text-lg">No vouchers found matching your criteria.
            <?php if (!$is_admin): ?>
                <a href="index.php?page=voucher_create" class="text-indigo-600 hover:text-indigo-800 font-semibold">Create one now!</a>
            <?php endif; ?>
            </p>
        </div>
    <?php else: ?>
        <div class="bg-Lime-500 p-6 rounded-lg shadow-inner overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-100">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tl-lg">Voucher Code</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sender</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Receiver</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Route</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Consignment</th> <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Weight (kg)</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total Amount</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created At</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider rounded-tr-lg">Actions</th>
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
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"> <?php if (!empty($voucher['consignment_code'])): ?>
                                    <a href="index.php?page=consignment_view&id=<?php echo htmlspecialchars($voucher['consignment_id']); ?>" class="text-blue-600 hover:text-blue-800 font-medium">
                                        <?php echo htmlspecialchars($voucher['consignment_code']); ?>
                                    </a>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                                <?php
                                if ((float)$voucher['weight_kg'] === 0.00) {
                                    echo '';
                                } else {
                                    echo htmlspecialchars(number_format((float)$voucher['weight_kg'], 2));
                                }
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars(number_format((float)$voucher['total_amount'], 2) . ' ' . $voucher['currency']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php
                                            switch ($voucher['status']) {
                                                case 'Pending': echo 'bg-yellow-100 text-yellow-800'; break;
                                                case 'In Transit': echo 'bg-blue-100 text-blue-800'; break;
                                                case 'Delivered': echo 'bg-green-100 text-green-800'; break;
                                                case 'Cancelled': echo 'bg-red-100 text-red-800'; break;
                                                case 'Returned': echo 'bg-purple-100 text-purple-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800'; break;
                                            }
                                        ?>">
                                        <?php echo htmlspecialchars($voucher['status']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('Y-m-d H:i', strtotime($voucher['created_at'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <?php
                                $view_href = 'index.php?page=voucher_view&id=' . htmlspecialchars($voucher['id']);
                                $view_class = 'btn btn-blue py-2 px-4 rounded-lg shadow-md';
                                $view_onclick = '';
                                $button_label = 'View';

                                $is_myanmar_or_malay_user = ($user_type === USER_TYPE_MYANMAR || $user_type === USER_TYPE_MALAY);

                                $should_disable_view = false;

                                if ($is_admin) {
                                    $should_disable_view = false;
                                } elseif ($is_myanmar_or_malay_user) {
                                    if ($voucher['status'] === 'Pending') {
                                        if ($user_region_id !== null && $user_region_id == $voucher['region_id']) {
                                            $should_disable_view = false;
                                        } else {
                                            $should_disable_view = true;
                                        }
                                    } elseif ($voucher['status'] === 'Delivered') {
                                        $should_disable_view = false;
                                    } else {
                                        if ($user_region_id !== null && ($user_region_id == $voucher['region_id'] || $user_region_id == $voucher['destination_region_id'])) {
                                            $should_disable_view = false;
                                        } else {
                                            $should_disable_view = true;
                                        }
                                    }
                                } else {
                                    $should_disable_view = false;
                                }

                                if ($should_disable_view) {
                                    $view_href = '#';
                                    $view_class = 'bg-gray-300 text-gray-600 cursor-not-allowed font-semibold py-2 px-4 rounded-lg shadow-md';
                                    $view_onclick = 'event.preventDefault(); alert(\'You do not have permission to view this voucher.\');';
                                    $button_label = 'No View';
                                }
                                ?>
                                <a href="<?php echo $view_href; ?>" class="<?php echo $view_class; ?>" <?php echo $view_onclick ? 'onclick="' . $view_onclick . '"' : ''; ?>>
                                    <?php echo $button_label; ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include_template('footer'); ?>