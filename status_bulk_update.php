<?php
// status_bulk_update.php - Allows bulk updating of voucher statuses.

// This file is loaded by index.php, so config.php, db_connect.php, and functions.php are already loaded.
// sesscion start.


if (!is_logged_in()) {
    flash_message('error', 'Please log in to access this page.');
    redirect('index.php?page=login');
}

global $connection;

// Fetch current user's details
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? null;
$user_region_id = null;
$is_admin = is_admin();
$current_username = 'System';

if ($user_id) {
    $stmt_user_info = mysqli_prepare($connection, "SELECT username, region_id FROM users WHERE id = ?");
    if ($stmt_user_info) {
        mysqli_stmt_bind_param($stmt_user_info, 'i', $user_id);
        mysqli_stmt_execute($stmt_user_info);
        $result_user_info = mysqli_stmt_get_result($stmt_user_info);
        if ($user_info = mysqli_fetch_assoc($result_user_info)) {
            $user_region_id = $user_info['region_id'];
            $current_username = $user_info['username'];
        }
        mysqli_free_result($result_user_info);
        mysqli_stmt_close($stmt_user_info);
    } else {
        error_log('Error fetching user info for bulk update: ' . mysqli_error($connection));
    }
}

// Define possible voucher statuses for dropdown and updates
$possible_statuses = ['Pending', 'In Transit', 'Delivered', 'Received', 'Cancelled', 'Returned'];
// Define possible search columns
$allowed_search_columns = ['voucher_code', 'sender_name', 'receiver_name', 'receiver_phone'];


// Get filter parameters from GET request
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$filter_region_id = $_GET['region_id'] ?? 'All'; // Filter by region (origin or destination)
$filter_status = $_GET['status'] ?? 'All';
$search_term = trim($_GET['search'] ?? '');
$search_column = $_GET['search_column'] ?? 'voucher_code'; // Default search column


$errors = [];
$vouchers = [];


// --- Handle POST request for bulk status update ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_voucher_ids = $_POST['selected_vouchers'] ?? [];
    $new_status = trim($_POST['new_status'] ?? '');
    // $bulk_notes = trim($_POST['bulk_notes'] ?? ''); // Notes removed as per previous request

    if (empty($selected_voucher_ids)) {
        $errors[] = 'No vouchers selected for update.';
    }
    if (!in_array($new_status, $possible_statuses)) {
        $errors[] = 'Invalid status selected for bulk update.';
    }

    if (empty($errors)) {
        mysqli_begin_transaction($connection);
        $updated_count = 0;
        try {
            // Prepare the update statement
            $update_sql = "UPDATE vouchers SET status = ? WHERE id = ?";
            $stmt_update = mysqli_prepare($connection, $update_sql);

            if (!$stmt_update) {
                throw new Exception("Failed to prepare update statement: " . mysqli_error($connection));
            }

            foreach ($selected_voucher_ids as $voucher_id) {
                $voucher_id = intval($voucher_id);
                if ($voucher_id > 0) {
                    // Fetch current voucher data for permission and old status
                    $stmt_fetch_voucher = mysqli_prepare($connection, "SELECT status, region_id, destination_region_id, created_by_user_id, notes FROM vouchers WHERE id = ?");
                    if (!$stmt_fetch_voucher) {
                        throw new Exception("Failed to prepare fetch voucher for permission check: " . mysqli_error($connection));
                    }
                    mysqli_stmt_bind_param($stmt_fetch_voucher, 'i', $voucher_id);
                    mysqli_stmt_execute($stmt_fetch_voucher);
                    $result_fetch_voucher = mysqli_stmt_get_result($stmt_fetch_voucher);
                    $voucher_current_data = mysqli_fetch_assoc($result_fetch_voucher);
                    mysqli_stmt_close($stmt_fetch_voucher);

                    if (!$voucher_current_data) {
                        error_log("Attempt to update non-existent voucher ID: {$voucher_id} by user {$user_id}");
                        continue; // Skip if voucher not found
                    }

                    // --- Permission Check for EACH Voucher ---
                    $can_update_single_voucher = false;
                    if ($is_admin) {
                        $can_update_single_voucher = true;
                    } elseif (($user_type === USER_TYPE_MYANMAR || $user_type === USER_TYPE_MALAY) && $user_region_id !== null) {
                        // Myanmar/Malay users can update if their region is the origin for 'Pending'
                        // Or if their region is origin/destination for other statuses.
                        // For 'Delivered', they can update if their region is destination.
                        if ($voucher_current_data['status'] === 'Pending' && $user_region_id == $voucher_current_data['region_id']) {
                            $can_update_single_voucher = true;
                        } elseif ($voucher_current_data['status'] === 'Delivered' && $user_region_id == $voucher_current_data['destination_region_id']) {
                            $can_update_single_voucher = true;
                        } elseif ($voucher_current_data['status'] !== 'Pending' && $voucher_current_data['status'] !== 'Delivered' && ($user_region_id == $voucher_current_data['region_id'] || $user_region_id == $voucher_current_data['destination_region_id'])) {
                             $can_update_single_voucher = true;
                        }
                    }

                    if (!$can_update_single_voucher) {
                        error_log("Attempt to update unauthorized voucher ID: {$voucher_id} by user {$user_id} (Permission Denied)");
                        continue; // Skip this voucher and proceed with others
                    }

                    // --- Perform Update ---
                    mysqli_stmt_bind_param($stmt_update, 'si', $new_status, $voucher_id);
                    if (!mysqli_stmt_execute($stmt_update)) {
                        throw new Exception("Failed to update voucher ID {$voucher_id}: " . mysqli_stmt_error($stmt_update));
                    }
                    $updated_count++;

                    // --- Log Status Change ---
                    $log_notes = "Bulk update to '{$new_status}'."; // Default note for bulk update
                    $old_status = $voucher_current_data['status'];
                    if ($old_status !== $new_status) { // Only log if status actually changed
                        log_voucher_status_change($voucher_id, $old_status, $new_status, $log_notes, $user_id);

                        // Also append to the voucher's notes column for history
                        $timestamp = date('Y-m-d H:i:s');
                        $log_entry = "--- {$timestamp} by {$current_username} ---\nStatus changed to '{$new_status}' (Bulk Update).\n";
                        $updated_voucher_notes = $voucher_current_data['notes'] . (empty($voucher_current_data['notes']) ? '' : "\n") . $log_entry;

                        $stmt_update_notes = mysqli_prepare($connection, "UPDATE vouchers SET notes = ? WHERE id = ?");
                        if ($stmt_update_notes) {
                            mysqli_stmt_bind_param($stmt_update_notes, 'si', $updated_voucher_notes, $voucher_id);
                            mysqli_stmt_execute($stmt_update_notes);
                            mysqli_stmt_close($stmt_update_notes);
                        } else {
                            error_log("Failed to update notes for voucher ID {$voucher_id} during bulk update: " . mysqli_error($connection));
                        }
                    }
                }
            }
            mysqli_stmt_close($stmt_update);
            mysqli_commit($connection);
            flash_message('success', $updated_count . ' vouchers updated successfully to "' . htmlspecialchars($new_status) . '".');
            redirect('index.php?page=status_bulk_update&' . http_build_query($_GET));
            exit();

        } catch (Exception $e) {
            mysqli_rollback($connection);
            flash_message('error', 'Bulk update failed: ' . $e->getMessage());
        }
    } else {
        flash_message('error', implode('<br>', $errors));
    }
}

// --- Fetch Vouchers based on filters (for initial load and after POST) ---
$where_clauses = [];
$bind_params = '';
$bind_values = [];

// Apply user-specific filter for Myanmar/Malay users, UNLESS it's an admin
if (!$is_admin && ($user_type === USER_TYPE_MYANMAR || $user_type === USER_TYPE_MALAY) && $user_region_id) {
    $regional_user_status_clause = [];
    $regional_user_param_types = '';
    $regional_user_param_values = [];

    // Rule 1: Pending vouchers are only visible if origin region matches user's region
    $regional_user_status_clause[] = "(v.status = ? AND v.region_id = ?)";
    $regional_user_param_types .= 'si';
    $regional_user_param_values[] = 'Pending';
    $regional_user_param_values[] = $user_region_id;

    // Rule 2: Delivered vouchers are visible if destination region matches user's region
    $regional_user_status_clause[] = "(v.status = ? AND v.destination_region_id = ?)";
    $regional_user_param_types .= 'si';
    $regional_user_param_values[] = 'Delivered';
    $regional_user_param_values[] = $user_region_id;

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
if ($filter_region_id !== 'All' && is_numeric($filter_region_id)) {
    // This part has to be careful not to override the complex regional filter.
    // Assuming this $filter_region_id is the simple dropdown in the form,
    // it will just add an additional AND condition.
    $where_clauses[] = "(v.region_id = ? OR v.destination_region_id = ?)";
    $bind_params .= 'ii';
    $bind_values[] = intval($filter_region_id);
    $bind_values[] = intval($filter_region_id);
}


// Search term filter
if (!empty($search_term)) {
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

$query = "SELECT v.id, v.voucher_code, v.sender_name, v.receiver_name, v.receiver_phone, v.status, v.created_at,
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
    flash_message('error', 'Error fetching vouchers: ' . mysqli_error($connection));
}

include_template('header', ['page' => 'status_bulk_update']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Voucher Status Bulk Update</h2>

    <form action="index.php" method="GET" class="mb-6 bg-blue-100 p-4 rounded-lg shadow-inner flex flex-wrap items-end gap-4">
        <input type="hidden" name="page" value="status_bulk_update">

        <div>
            <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date:</label>
            <input type="date" id="start_date" name="start_date" class="form-input mt-1" value="<?php echo htmlspecialchars($start_date); ?>">
        </div>
        <div>
            <label for="end_date" class="block text-sm font-medium text-gray-700">End Date:</label>
            <input type="date" id="end_date" name="end_date" class="form-input mt-1" value="<?php echo htmlspecialchars($end_date); ?>">
        </div>

        <div>
            <label for="filter_region_id" class="block text-sm font-medium text-gray-700">Filter by Region (Origin/Dest):</label>
            <select id="filter_region_id" name="region_id" class="form-select mt-1">
                <option value="All">All Regions</option>
                <?php foreach ($regions as $region_option): ?>
                    <option value="<?php echo htmlspecialchars($region_option['id']); ?>" <?php echo (strval($filter_region_id) === strval($region_option['id'])) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($region_option['region_name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div>
            <label for="filter_status" class="block text-sm font-medium text-gray-700">Filter by Status:</label>
            <select id="filter_status" name="status" class="form-select mt-1">
                <option value="All">All Statuses</option>
                <?php foreach ($possible_statuses as $status_option): ?>
                    <option value="<?php echo htmlspecialchars($status_option); ?>" <?php echo ($filter_status === $status_option) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($status_option); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="flex-grow">
            <label for="search_term" class="block text-sm font-medium text-gray-700">Search:</label>
            <div class="flex mt-1">
                <select name="search_column" class="form-select rounded-r-none border-r-0">
                    <option value="voucher_code" <?php echo ($search_column === 'voucher_code') ? 'selected' : ''; ?>>Voucher Code</option>
                    <option value="sender_name" <?php echo ($search_column === 'sender_name') ? 'selected' : ''; ?>>Sender Name</option>
                    <option value="receiver_name" <?php echo ($search_column === 'receiver_name') ? 'selected' : ''; ?>>Receiver Name</option>
                    <option value="receiver_phone" <?php echo ($search_column === 'receiver_phone') ? 'selected' : ''; ?>>Receiver Phone</option>
                </select>
                <input type="text" id="search_term" name="search" placeholder="Enter search term..."
                        class="form-input flex-grow rounded-l-none" value="<?php echo htmlspecialchars($search_term); ?>">
                <button type="submit" class="btn btn-indigo ml-2 px-4 py-2 rounded-md">Filter / Search</button>
            </div>
        </div>
    </form>

    <?php if (empty($vouchers)): ?>
        <p class="text-center text-gray-600">No vouchers found matching your criteria.</p>
    <?php else: ?>
        <form action="index.php?page=status_bulk_update&<?php echo http_build_query($_GET); ?>" method="POST" id="bulk_update_form">
            <div class="mb-4 flex flex-wrap items-center gap-4 p-4 bg-yellow-50 rounded-lg shadow-inner">
                <div>
                    <label for="new_status" class="block text-sm font-medium text-gray-700">Set selected to Status:</label>
                    <select id="new_status" name="new_status" class="form-select mt-1" required>
                        <option value="">Select New Status</option>
                        <?php foreach ($possible_statuses as $status_option): ?>
                            <option value="<?php echo htmlspecialchars($status_option); ?>"><?php echo htmlspecialchars($status_option); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-green px-6 py-2 rounded-md mt-4 md:mt-0">Update Selected Vouchers</button>
                </div>
            </div>

            <div class="bg-lime-500 overflow-x-auto">
                <table class="min-w-full bg-white border border-gray-200 shadow-sm rounded-lg">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider rounded-tl-lg">
                                <input type="checkbox" id="select_all_vouchers" class="form-checkbox h-4 w-4 text-indigo-600">
                            </th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">Voucher Code</th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">Sender</th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">Receiver</th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">Origin Region</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">Destination Region</th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">Current Status</th>
                            <th class="py-3 px-4 text-left text-xs font-medium text-gray-600 uppercase tracking-wider">Created At</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($vouchers as $voucher): ?>
                            <tr>
                                <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-800">
                                    <input type="checkbox" name="selected_vouchers[]" value="<?php echo htmlspecialchars($voucher['id']); ?>" class="form-checkbox h-4 w-4 text-indigo-600 voucher-checkbox">
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-800"><?php echo htmlspecialchars($voucher['voucher_code']); ?></td>
                                <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-800"><?php echo htmlspecialchars($voucher['sender_name']); ?></td>
                                <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-800">
                                    <?php echo htmlspecialchars($voucher['receiver_name']); ?><br>
                                    <span class="text-gray-500 text-xs"><?php echo htmlspecialchars($voucher['receiver_phone']); ?></span>
                                </td>
                                <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-800"><?php echo htmlspecialchars($voucher['origin_region_name']); ?></td>
                                <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-800"><?php echo htmlspecialchars($voucher['destination_region_name']); ?></td>
                                <td class="py-3 px-4 whitespace-nowrap text-sm">
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
                                <td class="py-3 px-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('Y-m-d H:i', strtotime($voucher['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAllCheckbox = document.getElementById('select_all_vouchers');
    const voucherCheckboxes = document.querySelectorAll('.voucher-checkbox');

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            voucherCheckboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });
        });
    }

    // Optional: If you want to uncheck "Select All" if any individual checkbox is unchecked
    voucherCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            if (!this.checked && selectAllCheckbox) {
                selectAllCheckbox.checked = false;
            } else if (selectAllCheckbox) {
                // If all are checked, check selectAll
                const allChecked = Array.from(voucherCheckboxes).every(cb => cb.checked);
                selectAllCheckbox.checked = allChecked;
            }
        });
    });
});
</script>

<?php include_template('footer'); ?>