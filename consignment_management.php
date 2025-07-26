<?php
// consignment_management.php - Manages consignments (batches of vouchers).

// This file is loaded by index.php, so config.php, db_connect.php, and functions.php are already loaded.
// Session is already started.

if (!is_logged_in()) {
    flash_message('error', 'Please log in to manage consignments.');
    redirect('index.php?page=login');
}

// Authorization: Only Admins and potentially regional managers (Staff/Myanmar/Malay with specific region) can manage consignments
// For simplicity, let's start with Admin-only for creating/editing, and maybe wider view.
// Refined: Admin can do everything. Non-admin regional staff can view, maybe update status of assigned ones.
global $connection;
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];
$is_admin = is_admin();

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

// Allowed statuses for consignments
$consignment_statuses = ['Pending', 'Departed', 'In Transit', 'Arrived at Hub', 'Out for Delivery', 'Completed', 'Cancelled'];

$consignments = [];
$errors = [];
$edit_consignment = null; // For holding data of consignment being edited

// Fetch available drivers for assignment
$drivers = [];
$stmt_drivers = mysqli_query($connection, "SELECT id, full_name FROM users WHERE user_type = 'Driver' AND is_active = 1 ORDER BY full_name ASC");
if ($stmt_drivers) {
    while ($row = mysqli_fetch_assoc($stmt_drivers)) {
        $drivers[] = $row;
    }
    mysqli_free_result($stmt_drivers);
} else {
    flash_message('warning', 'Could not load drivers for assignment: ' . mysqli_error($connection));
}


// --- Handle Form Submissions (Create/Update Consignment) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Check permissions for POST actions
    if (!$is_admin) { // Only admin can create/edit/delete consignments
        flash_message('error', 'You do not have permission to perform this action.');
        redirect('index.php?page=consignment_management');
    }

    if ($action === 'create_consignment' || $action === 'update_consignment') {
        $name = trim($_POST['name'] ?? '');
        $driver_id = intval($_POST['driver_id'] ?? 0);
        $route_details = trim($_POST['route_details'] ?? '');
        $expected_delivery_date = trim($_POST['expected_delivery_date'] ?? '');
        $consignment_id = intval($_POST['consignment_id'] ?? 0); // Only for update
        $new_status = trim($_POST['status'] ?? 'Pending'); // For updates, default to Pending if not set.

        if (empty($name)) $errors[] = 'Consignment Name is required.';
        if (!empty($driver_id) && $driver_id <= 0) $errors[] = 'Invalid Driver selected.';
        if (!empty($expected_delivery_date) && !preg_match("/^\d{4}-\d{2}-\d{2}$/", $expected_delivery_date)) $errors[] = 'Invalid Expected Delivery Date format.';
        if (!in_array($new_status, $consignment_statuses)) $errors[] = 'Invalid Consignment Status selected.';

        if (empty($errors)) {
            mysqli_begin_transaction($connection);
            try {
                if ($action === 'create_consignment') {
                    $consignment_code = generate_consignment_code(); // Generate unique code
                    $stmt = mysqli_prepare($connection, "INSERT INTO consignments (consignment_code, name, driver_id, route_details, expected_delivery_date, status, created_by_user_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if (!$stmt) throw new Exception("Failed to prepare create statement: " . mysqli_error($connection));
                    $bind_driver_id = ($driver_id > 0) ? $driver_id : null;
                    $bind_expected_delivery_date = empty($expected_delivery_date) ? null : $expected_delivery_date;

                    mysqli_stmt_bind_param($stmt, 'ssisssi', $consignment_code, $name, $bind_driver_id, $route_details, $bind_expected_delivery_date, 'Pending', $user_id);
                    if (!mysqli_stmt_execute($stmt)) throw new Exception("Failed to create consignment: " . mysqli_stmt_error($stmt));
                    $new_consignment_id = mysqli_insert_id($connection);
                    log_consignment_status_change($new_consignment_id, null, 'Pending', 'Consignment created.', $user_id);
                    flash_message('success', "Consignment '{$consignment_code}' created successfully!");

                } elseif ($action === 'update_consignment') {
                    if ($consignment_id <= 0) throw new Exception("Invalid Consignment ID for update.");

                    // Get old status for logging
                    $old_status_query = mysqli_prepare($connection, "SELECT status FROM consignments WHERE id = ?");
                    mysqli_stmt_bind_param($old_status_query, 'i', $consignment_id);
                    mysqli_stmt_execute($old_status_query);
                    $old_status_result = mysqli_stmt_get_result($old_status_query);
                    $old_consignment_data = mysqli_fetch_assoc($old_status_result);
                    mysqli_stmt_close($old_status_query);
                    $old_status = $old_consignment_data['status'] ?? 'Unknown';

                    $stmt = mysqli_prepare($connection, "UPDATE consignments SET name = ?, driver_id = ?, route_details = ?, expected_delivery_date = ?, status = ? WHERE id = ?");
                    if (!$stmt) throw new Exception("Failed to prepare update statement: " . mysqli_error($connection));
                    $bind_driver_id = ($driver_id > 0) ? $driver_id : null;
                    $bind_expected_delivery_date = empty($expected_delivery_date) ? null : $expected_delivery_date;

                    mysqli_stmt_bind_param($stmt, 'sisssi', $name, $bind_driver_id, $route_details, $bind_expected_delivery_date, $new_status, $consignment_id);
                    if (!mysqli_stmt_execute($stmt)) throw new Exception("Failed to update consignment: " . mysqli_stmt_error($stmt));

                    // Log consignment status change
                    if ($old_status !== $new_status) {
                        log_consignment_status_change($consignment_id, $old_status, $new_status, 'Status updated via management page.', $user_id);
                        // OPTIONAL: Also update statuses of associated vouchers
                        // Example: If consignment status becomes 'Departed', all vouchers inside could become 'In Transit'
                        // This requires a separate update query for vouchers
                        // $stmt_update_vouchers = mysqli_prepare($connection, "UPDATE vouchers SET status = ? WHERE consignment_id = ? AND status != ?");
                        // mysqli_stmt_bind_param($stmt_update_vouchers, 'sis', 'In Transit', $consignment_id, 'Delivered'); // Avoid changing Delivered vouchers
                        // mysqli_stmt_execute($stmt_update_vouchers);
                        // mysqli_stmt_close($stmt_update_vouchers);
                    }
                    flash_message('success', "Consignment updated successfully!");
                }
                mysqli_commit($connection);
            } catch (Exception $e) {
                mysqli_rollback($connection);
                flash_message('error', $e->getMessage());
            } finally {
                if (isset($stmt)) mysqli_stmt_close($stmt);
            }
        } else {
            flash_message('error', implode('<br>', $errors));
        }
        redirect('index.php?page=consignment_management');
    }
}

// --- Handle GET request for editing a consignment ---
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    if (!$is_admin) {
        flash_message('error', 'You do not have permission to edit consignments.');
        redirect('index.php?page=consignment_management');
    }
    $consignment_id_to_edit = intval($_GET['id']);
    $stmt = mysqli_prepare($connection, "SELECT * FROM consignments WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $consignment_id_to_edit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $edit_consignment = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        if (!$edit_consignment) {
            flash_message('error', 'Consignment not found for editing.');
            redirect('index.php?page=consignment_management');
        }
    } else {
        flash_message('error', 'Database error preparing edit query: ' . mysqli_error($connection));
        redirect('index.php?page=consignment_management');
    }
}


// --- Fetch Consignments for Display ---
$consignments = [];
$query = "SELECT c.*, u.full_name AS driver_name, u2.username AS created_by_username
          FROM consignments c
          LEFT JOIN users u ON c.driver_id = u.id
          LEFT JOIN users u2 ON c.created_by_user_id = u2.id";

// Filtering for non-admin users (e.g., Myanmar/Malay users see consignments passing through their region)
// This is a simplified example; full regional consignment filtering can be complex.
if (!$is_admin && $user_region_id) {
    // This is a placeholder. Real consignment regional filtering needs careful logic
    // (e.g., if consignment origin/destination is user's region, or if vouchers within are from/to region).
    // For now, non-admins (not drivers) might only see general consignments or those created by them.
    // If you want non-admin regional users to see consignments related to their region,
    // you'd need a more complex join condition here to check associated vouchers' regions,
    // or directly store origin/destination regions on the consignment itself.
    // FOR SIMPLICITY, I'm allowing non-admins to see ALL consignments (if they pass the menu link check).
    // If strict filtering is needed, add WHERE clauses here based on role and region.
}

$query .= " ORDER BY c.created_at DESC";

$result = mysqli_query($connection, $query);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $consignments[] = $row;
    }
    mysqli_free_result($result);
} else {
    flash_message('error', 'Error fetching consignments: ' . mysqli_error($connection));
}

include_template('header', ['page' => 'consignment_management']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Consignment Management</h2>

    <?php if ($is_admin): // Only admins can create/edit consignments ?>
    <div class="bg-blue-100 p-6 rounded-lg shadow-inner mb-8">
        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">
            <?php echo $edit_consignment ? 'Edit Consignment' : 'Create New Consignment'; ?>
        </h3>
        <form action="index.php?page=consignment_management" method="POST" class="show-loader-on-submit">
            <input type="hidden" name="action" value="<?php echo $edit_consignment ? 'update_consignment' : 'create_consignment'; ?>">
            <?php if ($edit_consignment): ?>
                <input type="hidden" name="consignment_id" value="<?php echo htmlspecialchars($edit_consignment['id']); ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="mb-4">
                    <label for="name" class="block text-gray-700 text-sm font-semibold mb-2">Consignment Name:</label>
                    <input type="text" id="name" name="name" class="form-input"
                           value="<?php echo htmlspecialchars($edit_consignment['name'] ?? ''); ?>" required placeholder="e.g., YGN-MDY Express Truck A">
                </div>
                <div class="mb-4">
                    <label for="driver_id" class="block text-gray-700 text-sm font-semibold mb-2">Assigned Driver (Optional):</label>
                    <select id="driver_id" name="driver_id" class="form-select">
                        <option value="0">Unassigned</option>
                        <?php foreach ($drivers as $driver): ?>
                            <option value="<?php echo htmlspecialchars($driver['id']); ?>"
                                <?php echo (isset($edit_consignment['driver_id']) && $edit_consignment['driver_id'] == $driver['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($driver['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mb-4">
                <label for="route_details" class="block text-gray-700 text-sm font-semibold mb-2">Route Details (Optional):</label>
                <textarea id="route_details" name="route_details" rows="3" class="form-input" placeholder="e.g., Via Naypyitaw, stop at Taunggyi"><?php echo htmlspecialchars($edit_consignment['route_details'] ?? ''); ?></textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="mb-4">
                    <label for="expected_delivery_date" class="block text-gray-700 text-sm font-semibold mb-2">Expected Delivery Date (Optional):</label>
                    <input type="date" id="expected_delivery_date" name="expected_delivery_date" class="form-input"
                           value="<?php echo htmlspecialchars($edit_consignment['expected_delivery_date'] ?? ''); ?>">
                </div>
                <?php if ($edit_consignment): ?>
                <div class="mb-4">
                    <label for="status" class="block text-gray-700 text-sm font-semibold mb-2">Consignment Status:</label>
                    <select id="status" name="status" class="form-select" required>
                        <?php foreach ($consignment_statuses as $status_option): ?>
                            <option value="<?php echo htmlspecialchars($status_option); ?>"
                                <?php echo ($edit_consignment['status'] === $status_option) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status_option); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>

            <div class="flex justify-end gap-2">
                <button type="submit" class="btn btn-green py-2 px-4 rounded-lg shadow-md">
                    <?php echo $edit_consignment ? 'Update Consignment' : 'Create Consignment'; ?>
                </button>
                <?php if ($edit_consignment): ?>
                    <a href="index.php?page=consignment_management" class="btn btn-slate py-2 px-4 rounded-lg shadow-md">Cancel Edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">All Consignments</h3>
    <?php if (empty($consignments)): ?>
        <p class="text-center text-gray-600">No consignments found.</p>
    <?php else: ?>
        <div class="overflow-x-auto rounded-lg shadow-md border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Driver</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created By</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Created At</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($consignments as $consignment): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <a href="index.php?page=consignment_view&id=<?php echo htmlspecialchars($consignment['id']); ?>" class="text-blue-600 hover:text-blue-800 font-medium">
                                    <?php echo htmlspecialchars($consignment['consignment_code']); ?>
                                </a>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($consignment['name'] ?: 'N/A'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($consignment['driver_name'] ?: 'N/A'); ?></td>
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
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($consignment['created_by_username']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('Y-m-d H:i', strtotime($consignment['created_at'])); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                <a href="index.php?page=consignment_management&action=edit&id=<?php echo htmlspecialchars($consignment['id']); ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include_template('footer'); ?>