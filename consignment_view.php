<?php
// consignment_view.php - Displays details of a specific consignment.

// This file is loaded by index.php, so config.php, db_connect.php, and functions.php are already loaded.
// Session is already started.

if (!is_logged_in()) {
    flash_message('error', 'Please log in to view consignments.');
    redirect('index.php?page=login');
}

global $connection;
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
$is_admin = is_admin();

// Fetch current user's region (for potential authorization logic)
$user_region_id = null;
if (!$is_admin) {
    $stmt_user_region = mysqli_prepare($connection, "SELECT region_id FROM users WHERE id = ?");
    if ($stmt_user_region) {
        mysqli_stmt_bind_param($stmt_user_region, 'i', $user_id);
        mysqli_stmt_execute($stmt_user_region);
        mysqli_stmt_bind_result($stmt_user_region, $region_id_result);
        mysqli_stmt_fetch($stmt_user_region);
        $user_region_id = $region_id_result;
        mysqli_stmt_close($stmt_user_region);
    }
}

$consignment_id = intval($_GET['id'] ?? 0);
if ($consignment_id <= 0) {
    flash_message('error', 'Invalid consignment ID provided.');
    redirect('index.php?page=consignment_management');
}

$consignment_data = null;
$associated_vouchers = [];
$consignment_status_log = [];
$error_message = '';

// Allowed statuses for consignments (for update form)
$consignment_statuses = ['Pending', 'Departed', 'In Transit', 'Arrived at Hub', 'Out for Delivery', 'Completed', 'Cancelled'];

// --- Handle POST request for status update ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_consignment_status') {
    $new_status = trim($_POST['status'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    $errors = [];
    if (!in_array($new_status, $consignment_statuses)) {
        $errors[] = 'Invalid status selected.';
    }

    // Authorization for update: Admin, or potentially a Driver assigned to this consignment, or regional staff
    $can_update_consignment = $is_admin;
    // Fetch consignment data to check driver_id
    $stmt_check_consignment = mysqli_prepare($connection, "SELECT driver_id, status FROM consignments WHERE id = ?");
    if ($stmt_check_consignment) {
        mysqli_stmt_bind_param($stmt_check_consignment, 'i', $consignment_id);
        mysqli_stmt_execute($stmt_check_consignment);
        $result_check_consignment = mysqli_stmt_get_result($stmt_check_consignment);
        $current_consignment_data = mysqli_fetch_assoc($result_check_consignment);
        mysqli_stmt_close($stmt_check_consignment);

        if (!$current_consignment_data) {
            $errors[] = 'Consignment not found for update.';
        } elseif ($user_type === USER_TYPE_DRIVER && $current_consignment_data['driver_id'] == $user_id) {
            $can_update_consignment = true; // Assigned driver can update
        } elseif (($user_type === USER_TYPE_MYANMAR || $user_type === USER_TYPE_MALAY) && $user_region_id !== null) {
            // For regional staff, they might be able to update if consignment is related to their region
            // This would require more complex logic (e.g. check vouchers' origins/destinations)
            // For simplicity, let's say only admin/assigned driver can update status on this page.
        }
    } else {
        $errors[] = 'Database error checking consignment for update: ' . mysqli_error($connection);
    }

    if (!$can_update_consignment) {
        $errors[] = 'You do not have permission to update this consignment.';
    }

    if (empty($errors)) {
        mysqli_begin_transaction($connection);
        try {
            $old_status = $current_consignment_data['status']; // Get old status for logging

            $stmt_update = mysqli_prepare($connection, "UPDATE consignments SET status = ?, notes = CONCAT(IFNULL(notes, ''), '\n--- " . date('Y-m-d H:i:s') . " by " . ($_SESSION['username'] ?? 'System') . " ---\nStatus changed to {$new_status}: {$notes}\n') WHERE id = ?"); // Appending notes to consignment (if you have a notes column)
            if (!$stmt_update) throw new Exception("Failed to prepare update statement: " . mysqli_error($connection));
            mysqli_stmt_bind_param($stmt_update, 'si', $new_status, $consignment_id);
            if (!mysqli_stmt_execute($stmt_update)) throw new Exception("Failed to update consignment: " . mysqli_stmt_error($stmt_update));
            mysqli_stmt_close($stmt_update);

            // Log consignment status change
            log_consignment_status_change($consignment_id, $old_status, $new_status, $notes, $user_id);

            // OPTIONAL: Bulk update associated vouchers' statuses
            // This is a major decision: Do you want ALL vouchers in a consignment to change status when consignment status changes?
            // E.g., if consignment becomes 'Departed', all its vouchers become 'In Transit'
            // if ($old_status !== $new_status && $new_status === 'Departed') {
            //     $stmt_update_vouchers = mysqli_prepare($connection, "UPDATE vouchers SET status = 'In Transit' WHERE consignment_id = ? AND status IN ('Pending')");
            //     mysqli_stmt_bind_param($stmt_update_vouchers, 'i', $consignment_id);
            //     mysqli_stmt_execute($stmt_update_vouchers);
            //     mysqli_stmt_close($stmt_update_vouchers);
            // }

            flash_message('success', "Consignment status updated to '{$new_status}'!");
            mysqli_commit($connection);
        } catch (Exception $e) {
            mysqli_rollback($connection);
            flash_message('error', 'Failed to update consignment: ' . $e->getMessage());
        }
    } else {
        flash_message('error', implode('<br>', $errors));
    }
    redirect('index.php?page=consignment_view&id=' . $consignment_id);
}


// --- Fetch Consignment Data ---
try {
    $query_consignment = "SELECT c.*, u.full_name AS driver_name, u.phone AS driver_phone, u2.username AS created_by_username
                          FROM consignments c
                          LEFT JOIN users u ON c.driver_id = u.id
                          JOIN users u2 ON c.created_by_user_id = u2.id
                          WHERE c.id = ?";
    $stmt_consignment = mysqli_prepare($connection, $query_consignment);
    if (!$stmt_consignment) throw new Exception("Failed to prepare consignment fetch: " . mysqli_error($connection));
    mysqli_stmt_bind_param($stmt_consignment, 'i', $consignment_id);
    mysqli_stmt_execute($stmt_consignment);
    $result_consignment = mysqli_stmt_get_result($stmt_consignment);
    $consignment_data = mysqli_fetch_assoc($result_consignment);
    mysqli_stmt_close($stmt_consignment);

    if (!$consignment_data) {
        flash_message('error', 'Consignment not found.');
        redirect('index.php?page=consignment_management');
    }

    // Authorization for viewing: Admin, or if assigned driver, or potentially regional staff related to this consignment
    $has_view_permission = $is_admin;
    if ($user_type === USER_TYPE_DRIVER && $consignment_data['driver_id'] == $user_id) {
        $has_view_permission = true;
    }
    // Add logic here if regional Myanmar/Malay users should view consignments that pass through their region
    // This would require a more complex check (e.g., check vouchers in consignment)
    // For simplicity, current non-admin non-driver regional users only see consignments created by them, or assigned.
    // If you want them to see based on region, you need to load voucher data first.
    // Assuming for now if they are not admin/driver, they need to be the creator for view here.
    if ($consignment_data['created_by_user_id'] == $user_id) {
         $has_view_permission = true;
    }

    if (!$has_view_permission) {
        flash_message('error', 'You do not have permission to view this consignment.');
        redirect('index.php?page=consignment_management');
    }


    // Fetch associated vouchers
    $query_vouchers = "SELECT v.id, v.voucher_code, v.sender_name, v.receiver_name, v.status, v.total_amount, v.currency, v.created_at,
                              r_origin.region_name AS origin_region_name, r_dest.region_name AS destination_region_name
                       FROM vouchers v
                       JOIN regions r_origin ON v.region_id = r_origin.id
                       JOIN regions r_dest ON v.destination_region_id = r_dest.id
                       WHERE v.consignment_id = ? ORDER BY v.created_at DESC";
    $stmt_vouchers = mysqli_prepare($connection, $query_vouchers);
    if ($stmt_vouchers) {
        mysqli_stmt_bind_param($stmt_vouchers, 'i', $consignment_id);
        mysqli_stmt_execute($stmt_vouchers);
        $result_vouchers = mysqli_stmt_get_result($stmt_vouchers);
        while ($row = mysqli_fetch_assoc($result_vouchers)) {
            $associated_vouchers[] = $row;
        }
        mysqli_free_result($result_vouchers);
        mysqli_stmt_close($stmt_vouchers);
    } else {
        flash_message('error', 'Error fetching associated vouchers: ' . mysqli_error($connection));
    }

    // Fetch consignment status log
    $query_log = "SELECT csl.*, u.username AS changed_by_username
                  FROM consignment_status_log csl
                  JOIN users u ON csl.changed_by_user_id = u.id
                  WHERE csl.consignment_id = ? ORDER BY csl.change_timestamp DESC";
    $stmt_log = mysqli_prepare($connection, $query_log);
    if ($stmt_log) {
        mysqli_stmt_bind_param($stmt_log, 'i', $consignment_id);
        mysqli_stmt_execute($stmt_log);
        $result_log = mysqli_stmt_get_result($stmt_log);
        while ($row = mysqli_fetch_assoc($result_log)) {
            $consignment_status_log[] = $row;
        }
        mysqli_free_result($result_log);
        mysqli_stmt_close($stmt_log);
    } else {
        flash_message('error', 'Error fetching consignment log: ' . mysqli_error($connection));
    }

} catch (Exception $e) {
    error_log("Consignment View Error: " . $e->getMessage());
    flash_message('error', 'An error occurred while loading consignment details.');
    redirect('index.php?page=consignment_management');
}

include_template('header', ['page' => 'consignment_view']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Consignment Details: <?php echo htmlspecialchars($consignment_data['consignment_code']); ?></h2>

    <?php if ($consignment_data): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
            <div class="bg-blue-50 p-6 rounded-lg shadow-inner">
                <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Consignment Information</h3>
                <p><strong class="text-gray-800">Code:</strong> <span class="font-mono text-indigo-600 text-lg"><?php echo htmlspecialchars($consignment_data['consignment_code']); ?></span></p>
                <p><strong class="text-gray-800">Name:</strong> <?php echo htmlspecialchars($consignment_data['name'] ?: 'N/A'); ?></p>
                <p><strong class="text-gray-800">Driver:</strong> <?php echo htmlspecialchars($consignment_data['driver_name'] ?: 'Unassigned'); ?></p>
                <p><strong class="text-gray-800">Driver Phone:</strong> <?php echo htmlspecialchars($consignment_data['driver_phone'] ?: 'N/A'); ?></p>
                <p><strong class="text-gray-800">Route:</strong> <?php echo nl2br(htmlspecialchars($consignment_data['route_details'] ?: 'N/A')); ?></p>
                <p><strong class="text-gray-800">Expected Delivery:</strong> <?php echo htmlspecialchars($consignment_data['expected_delivery_date'] ?: 'N/A'); ?></p>
                <p><strong class="text-gray-800">Current Status:</strong>
                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                        <?php
                            switch ($consignment_data['status']) {
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
                        <?php echo htmlspecialchars($consignment_data['status']); ?>
                    </span>
                </p>
                <p><strong class="text-gray-800">Created By:</strong> <?php echo htmlspecialchars($consignment_data['created_by_username']); ?></p>
                <p><strong class="text-gray-800">Created At:</strong> <?php echo date('Y-m-d H:i:s', strtotime($consignment_data['created_at'])); ?></p>
            </div>

            <div class="bg-purple-50 p-6 rounded-lg shadow-inner">
                <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Update Consignment Status</h3>
                <?php
                $can_update_consignment_status = $is_admin || ($user_type === USER_TYPE_DRIVER && $consignment_data['driver_id'] == $user_id);
                if ($can_update_consignment_status):
                ?>
                <form action="index.php?page=consignment_view&id=<?php echo htmlspecialchars($consignment_id); ?>" method="POST" class="show-loader-on-submit">
                    <input type="hidden" name="action" value="update_consignment_status">
                    <div class="mb-4">
                        <label for="status" class="block text-gray-700 text-sm font-semibold mb-2">New Status:</label>
                        <select id="status" name="status" class="form-select" required>
                            <?php foreach ($consignment_statuses as $status_option): ?>
                                <option value="<?php echo htmlspecialchars($status_option); ?>"
                                    <?php echo ($consignment_data['status'] === $status_option) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="notes" class="block text-gray-700 text-sm font-semibold mb-2">Notes for Update (Optional):</label>
                        <textarea id="notes" name="notes" rows="3" class="form-input" placeholder="Add a note about this status change"></textarea>
                    </div>
                    <button type="submit" class="btn btn-green px-4 py-2 rounded-md">Update Status</button>
                </form>
                <?php else: ?>
                    <p class="text-gray-600 italic">You do not have permission to update this consignment's status.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-gray-50 p-6 rounded-lg shadow-inner mb-8">
            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Vouchers in this Consignment (<?php echo count($associated_vouchers); ?>)</h3>
            <?php if (empty($associated_vouchers)): ?>
                <p class="text-center text-gray-600">No vouchers assigned to this consignment.</p>
                <?php if ($is_admin): ?>
                    <p class="text-center text-gray-600 mt-2">You can assign vouchers via the <a href="index.php?page=voucher_create" class="text-blue-600 hover:text-blue-800">Voucher Creation page</a> or the <a href="index.php?page=status_bulk_update" class="text-blue-600 hover:text-blue-800">Bulk Status Update page</a>.</p>
                <?php endif; ?>
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
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($associated_vouchers as $voucher): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><a href="index.php?page=voucher_view&id=<?php echo htmlspecialchars($voucher['id']); ?>" class="text-blue-600 hover:text-blue-800"><?php echo htmlspecialchars($voucher['voucher_code']); ?></a></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($voucher['sender_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($voucher['receiver_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($voucher['origin_region_name'] . ' to ' . $voucher['destination_region_name']); ?></td>
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
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <?php if ($is_admin && $consignment_data['status'] !== 'Completed' && $consignment_data['status'] !== 'Cancelled'): ?>
                                        <form action="index.php?page=consignment_view&id=<?php echo htmlspecialchars($consignment_id); ?>" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to remove this voucher from the consignment?');">
                                            <input type="hidden" name="action" value="remove_voucher_from_consignment">
                                            <input type="hidden" name="voucher_id" value="<?php echo htmlspecialchars($voucher['id']); ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-900">Remove</button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="bg-gray-50 p-6 rounded-lg shadow-inner mb-8">
            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Consignment Status Log</h3>
            <?php if (empty($consignment_status_log)): ?>
                <p class="text-center text-gray-600">No status history found for this consignment.</p>
            <?php else: ?>
                <div class="overflow-x-auto rounded-lg shadow-md border border-gray-200">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Timestamp</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Old Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">New Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Notes</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Changed By</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($consignment_status_log as $log_entry): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo date('Y-m-d H:i:s', strtotime($log_entry['change_timestamp'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($log_entry['old_status'] ?: 'N/A'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($log_entry['new_status']); ?></td>
                                    <td class="px-6 py-4 text-sm text-gray-700"><?php echo nl2br(htmlspecialchars($log_entry['notes'] ?: 'No notes.')); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($log_entry['changed_by_username']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="flex justify-center mt-8">
            <a href="index.php?page=consignment_management" class="btn btn-slate py-2 px-4 rounded-lg shadow-md">Back to Consignment List</a>
        </div>
    <?php else: ?>
        <div class="text-center">
            <p class="text-red-500">Consignment data could not be loaded.</p>
        </div>
    <?php endif; ?>
</div>

<?php include_template('footer'); ?>