<?php
// driver_voucher_detail.php - Allows drivers to update voucher status and record POD.

session_start();
require_once 'config.php';
require_once 'db_connect.php';
require_once INC_PATH . 'functions.php';

if (!is_logged_in()) {
    flash_message('error', 'Please log in to access voucher details.');
    redirect('index.php?page=login');
}

global $connection;
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? null;
$is_admin = is_admin(); // Admin can also access this for testing/override

// Authorization: Only drivers (or admins) can access this page
if ($user_type !== USER_TYPE_DRIVER && !$is_admin) {
    flash_message('error', 'You do not have permission to access this page.');
    redirect('index.php?page=dashboard');
}

$voucher_id = intval($_GET['id'] ?? 0);
if ($voucher_id <= 0) {
    flash_message('error', 'Invalid voucher ID provided.');
    redirect('index.php?page=driver_dashboard');
}

$current_username = $_SESSION['username'] ?? 'System'; // For logging notes

$possible_statuses = ['Pending', 'In Transit', 'Delivered', 'Received', 'Cancelled', 'Returned'];

// --- Handle POST request (Status Update or POD) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Fetch current voucher data for permission and old status
    $stmt_fetch_voucher = mysqli_prepare($connection, "SELECT voucher_code, status, assigned_driver_id, consignment_id, notes FROM vouchers WHERE id = ?");
    if ($stmt_fetch_voucher) {
        mysqli_stmt_bind_param($stmt_fetch_voucher, 'i', $voucher_id);
        mysqli_stmt_execute($stmt_fetch_voucher);
        $result_fetch_voucher = mysqli_stmt_get_result($stmt_fetch_voucher);
        $voucher_current_data = mysqli_fetch_assoc($result_fetch_voucher);
        mysqli_stmt_close($stmt_fetch_voucher);

        if (!$voucher_current_data) {
            flash_message('error', 'Voucher not found for update.');
            redirect('index.php?page=driver_dashboard');
        }
    } else {
        flash_message('error', 'Database error checking voucher for update: ' . mysqli_error($connection));
        redirect('index.php?page=driver_dashboard');
    }

    // Permission check for update actions
    $can_update = $is_admin; // Admin can always update
    if ($user_type === USER_TYPE_DRIVER && ($voucher_current_data['assigned_driver_id'] == $user_id || ($voucher_current_data['consignment_id'] && is_driver_assigned_to_consignment($voucher_current_data['consignment_id'], $user_id)))) {
        $can_update = true; // Driver assigned directly or to consignment can update
    }

    if (!$can_update) {
        flash_message('error', 'You do not have permission to update this voucher.');
        redirect('index.php?page=driver_dashboard');
    }


    if ($action === 'update_status') {
        $new_status = trim($_POST['new_status'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        $errors = [];
        if (!in_array($new_status, $possible_statuses)) {
            $errors[] = 'Invalid status selected.';
        }
        if ($voucher_current_data['status'] === 'Delivered' && $new_status !== 'Delivered') {
            $errors[] = 'Cannot change status of a delivered voucher unless it\'s to Delivered again.';
        }

        if (empty($errors)) {
            mysqli_begin_transaction($connection);
            try {
                $old_status = $voucher_current_data['status'];

                $update_sql = "UPDATE vouchers SET status = ?, notes = CONCAT(IFNULL(notes, ''), '\n--- " . date('Y-m-d H:i:s') . " by {$current_username} ---\nStatus changed to {$new_status}: {$notes}\n') WHERE id = ?";
                $stmt_update = mysqli_prepare($connection, $update_sql);
                if (!$stmt_update) throw new Exception("Failed to prepare update statement: " . mysqli_error($connection));
                mysqli_stmt_bind_param($stmt_update, 'si', $new_status, $voucher_id);
                if (!mysqli_stmt_execute($stmt_update)) throw new Exception("Failed to update voucher: " . mysqli_stmt_error($stmt_update));
                mysqli_stmt_close($stmt_update);

                log_voucher_status_change($voucher_id, $old_status, $new_status, $notes, $user_id);

                flash_message('success', 'Voucher status updated successfully!');
                mysqli_commit($connection);
            } catch (Exception $e) {
                mysqli_rollback($connection);
                flash_message('error', 'Failed to update voucher status: ' . $e->getMessage());
            }
        } else {
            flash_message('error', implode('<br>', $errors));
        }
        redirect('index.php?page=driver_voucher_detail&id=' . $voucher_id);

    } elseif ($action === 'add_pod') {
        $delivery_notes = trim($_POST['delivery_notes'] ?? '');
        $pod_image_file = $_FILES['pod_image'] ?? null;

        $errors = [];
        if (empty($delivery_notes) && (empty($pod_image_file) || $pod_image_file['error'] !== UPLOAD_ERR_OK)) {
            $errors[] = 'Proof of Delivery requires notes and/or an image.';
        }
        if ($voucher_current_data['status'] === 'Delivered') {
            $errors[] = 'Proof of Delivery cannot be added to an already delivered voucher.';
        }

        $image_path = null;
        if ($pod_image_file && $pod_image_file['error'] === UPLOAD_ERR_OK) {
            $image_path = handle_pod_file_upload($pod_image_file, POD_UPLOAD_DIR, $voucher_current_data['voucher_code']);
            if (!$image_path) {
                $errors[] = 'Failed to upload POD image. Check file type/size or server permissions.';
            }
        }

        if (empty($errors)) {
            mysqli_begin_transaction($connection);
            try {
                $stmt_pod = mysqli_prepare($connection, "INSERT INTO proof_of_delivery (voucher_id, image_path, delivery_notes, recorded_by_user_id) VALUES (?, ?, ?, ?)");
                if (!$stmt_pod) {
                    throw new Exception("Failed to prepare POD insert statement: " . mysqli_error($connection));
                }
                mysqli_stmt_bind_param($stmt_pod, 'issi', $voucher_id, $image_path, $delivery_notes, $user_id);
                if (!mysqli_stmt_execute($stmt_pod)) {
                    throw new Exception("Failed to insert POD: " . mysqli_stmt_error($stmt_pod));
                }
                mysqli_stmt_close($stmt_pod);

                // Update voucher status to Delivered
                $old_status = $voucher_current_data['status'];
                $new_status = 'Delivered';
                $voucher_notes_update = "Voucher delivered. POD recorded.";
                $stmt_update_voucher = mysqli_prepare($connection, "UPDATE vouchers SET status = ?, notes = CONCAT(IFNULL(notes, ''), '\n--- " . date('Y-m-d H:i:s') . " by {$current_username} ---\n{$voucher_notes_update}\n') WHERE id = ?");
                if (!$stmt_update_voucher) {
                    throw new Exception("Failed to prepare voucher status update for POD: " . mysqli_error($connection));
                }
                mysqli_stmt_bind_param($stmt_update_voucher, 'si', $new_status, $voucher_id);
                if (!mysqli_stmt_execute($stmt_update_voucher)) {
                    throw new Exception("Failed to update voucher status after POD: " . mysqli_stmt_error($stmt_update_voucher));
                }
                mysqli_stmt_close($stmt_update_voucher);
                log_voucher_status_change($voucher_id, $old_status, $new_status, $voucher_notes_update, $user_id);

                flash_message('success', 'Proof of Delivery recorded and voucher status updated to Delivered!');
                mysqli_commit($connection);
            } catch (Exception $e) {
                mysqli_rollback($connection);
                if ($image_path && file_exists(ASSETS_PATH . 'pod_images/' . basename($image_path))) {
                    unlink(ASSETS_PATH . 'pod_images/' . basename($image_path));
                }
                flash_message('error', 'Failed to record POD: ' . $e->getMessage());
            }
        } else {
            flash_message('error', implode('<br>', $errors));
        }
        redirect('index.php?page=driver_voucher_detail&id=' . $voucher_id);
    }
}

// --- Fetch Voucher Details for Display ---
$voucher = null;
$breakdowns = [];
$pod_details = null;

try {
    $query_voucher = "SELECT v.*,
                             r_origin.region_name AS origin_region_name,
                             r_dest.region_name AS destination_region_name,
                             c.consignment_code, c.name AS consignment_name, c.status AS consignment_status
                      FROM vouchers v
                      LEFT JOIN regions r_origin ON v.region_id = r_origin.id
                      LEFT JOIN regions r_dest ON v.destination_region_id = r_dest.id
                      LEFT JOIN consignments c ON v.consignment_id = c.id
                      WHERE v.id = ?";

    $stmt_voucher = mysqli_prepare($connection, $query_voucher);
    if (!$stmt_voucher) {
        throw new Exception("Failed to prepare voucher fetch statement: " . mysqli_error($connection));
    }
    mysqli_stmt_bind_param($stmt_voucher, 'i', $voucher_id);
    mysqli_stmt_execute($stmt_voucher);
    $result_voucher = mysqli_stmt_get_result($stmt_voucher);
    $voucher = mysqli_fetch_assoc($result_voucher);
    mysqli_stmt_close($stmt_voucher);

    if (!$voucher) {
        flash_message('error', 'Voucher not found.');
        redirect('index.php?page=driver_dashboard');
    }

    // Driver-specific view permission: Must be assigned directly or via consignment, or be admin
    $can_view_voucher = $is_admin;
    if ($user_type === USER_TYPE_DRIVER) {
        if ($voucher['assigned_driver_id'] == $user_id) {
            $can_view_voucher = true;
        } elseif ($voucher['consignment_id'] && is_driver_assigned_to_consignment($voucher['consignment_id'], $user_id)) {
            $can_view_voucher = true;
        }
    }

    if (!$can_view_voucher) {
        flash_message('error', 'You do not have permission to view this voucher.');
        redirect('index.php?page=driver_dashboard');
    }

    // Fetch breakdowns
    $stmt_breakdowns = mysqli_prepare($connection, "SELECT item_type, kg, price_per_kg FROM voucher_breakdowns WHERE voucher_id = ?");
    if ($stmt_breakdowns) {
        mysqli_stmt_bind_param($stmt_breakdowns, 'i', $voucher_id);
        mysqli_stmt_execute($stmt_breakdowns);
        $result_breakdowns = mysqli_stmt_get_result($stmt_breakdowns);
        while ($row = mysqli_fetch_assoc($result_breakdowns)) {
            $breakdowns[] = $row;
        }
        mysqli_stmt_close($stmt_breakdowns);
    }

    // Fetch POD details
    $stmt_pod = mysqli_prepare($connection, "SELECT image_path, signature_data, delivery_notes, delivery_timestamp FROM proof_of_delivery WHERE voucher_id = ?");
    if ($stmt_pod) {
        mysqli_stmt_bind_param($stmt_pod, 'i', $voucher_id);
        mysqli_stmt_execute($stmt_pod);
        $result_pod = mysqli_stmt_get_result($stmt_pod);
        $pod_details = mysqli_fetch_assoc($result_pod);
        mysqli_stmt_close($stmt_pod);
    }

} catch (Exception $e) {
    error_log("Driver Voucher Detail Error: " . $e->getMessage());
    flash_message('error', 'An error occurred while loading voucher details.');
    redirect('index.php?page=driver_dashboard');
}

// Helper to check if a driver is assigned to a consignment (used in authorization)
function is_driver_assigned_to_consignment($consignment_id, $driver_id) {
    global $connection;
    $stmt = mysqli_prepare($connection, "SELECT COUNT(*) FROM consignments WHERE id = ? AND driver_id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'ii', $consignment_id, $driver_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $count);
        mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);
        return $count > 0;
    }
    return false;
}

include_template('header', ['page' => 'driver_voucher_detail']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-4xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Voucher #<?php echo htmlspecialchars($voucher['voucher_code']); ?></h2>

    <?php if ($voucher): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <!-- Voucher Basic Info -->
            <div class="bg-blue-50 p-6 rounded-lg shadow-inner">
                <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Voucher Information</h3>
                <p><strong class="text-gray-800">Voucher Code:</strong> <span class="font-mono text-indigo-600 text-lg"><?php echo htmlspecialchars($voucher['voucher_code']); ?></span></p>
                <p><strong class="text-gray-800">Origin:</strong> <?php echo htmlspecialchars($voucher['origin_region_name']); ?></p>
                <p><strong class="text-gray-800">Destination:</strong> <?php echo htmlspecialchars($voucher['destination_region_name']); ?></p>
                <p><strong class="text-gray-800">Current Status:</strong>
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
                </p>
                <?php if (!empty($voucher['consignment_id'])): ?>
                    <p><strong class="text-gray-800">Consignment:</strong> <a href="index.php?page=consignment_view&id=<?php echo htmlspecialchars($voucher['consignment_id']); ?>" class="text-blue-600 hover:text-blue-800"><?php echo htmlspecialchars($voucher['consignment_code'] ?: 'N/A'); ?></a></p>
                    <p><strong class="text-gray-800">Consignment Status:</strong> <?php echo htmlspecialchars($voucher['consignment_status'] ?: 'N/A'); ?></p>
                <?php endif; ?>
                <p><strong class="text-gray-800">Created At:</strong> <?php echo date('Y-m-d H:i:s', strtotime($voucher['created_at'])); ?></p>
            </div>

            <!-- Sender & Receiver Details -->
            <div class="bg-green-50 p-6 rounded-lg shadow-inner">
                <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Sender Information</h3>
                <p><strong class="text-gray-800">Name:</strong> <?php echo htmlspecialchars($voucher['sender_name']); ?></p>
                <p><strong class="text-gray-800">Phone:</strong> <?php echo htmlspecialchars($voucher['sender_phone']); ?></p>
                <p><strong class="text-gray-800">Address:</strong> <?php echo nl2br(htmlspecialchars($voucher['sender_address'] ?: 'N/A')); ?></p>
                <h3 class="text-xl font-semibold text-gray-700 mt-4 mb-4 border-b pb-2">Receiver Information</h3>
                <p><strong class="text-gray-800">Name:</strong> <?php echo htmlspecialchars($voucher['receiver_name']); ?></p>
                <p><strong class="text-gray-800">Phone:</strong> <?php echo htmlspecialchars($voucher['receiver_phone']); ?></p>
                <p><strong class="text-gray-800">Address:</strong> <?php echo nl2br(htmlspecialchars($voucher['receiver_address'])); ?></p>
            </div>
        </div>

        <!-- Item Breakdown -->
        <div class="bg-red-50 p-6 rounded-lg shadow-inner mb-8">
            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Item Breakdown</h3>
            <?php if (empty($breakdowns)): ?>
                <p class="text-gray-600">No item breakdowns for this voucher.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kg</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Price/Kg</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($breakdowns as $item): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($item['item_type'] ?: 'N/A'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars(number_format((float)$item['kg'], 2)); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars(number_format((float)$item['price_per_kg'], 2)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- POD & Status Update Form -->
        <div class="bg-purple-50 p-6 rounded-lg shadow-inner mb-8">
            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Update Status & Add POD</h3>

            <?php if ($pod_details): // Display POD if exists ?>
                <div class="bg-teal-50 p-4 rounded-lg mb-4">
                    <p class="text-gray-800 font-semibold">Proof of Delivery Recorded:</p>
                    <p class="text-sm text-gray-700">Delivered At: <?php echo date('Y-m-d H:i:s', strtotime($pod_details['delivery_timestamp'])); ?></p>
                    <?php if (!empty($pod_details['delivery_notes'])): ?>
                        <p class="text-sm text-gray-700">Notes: <?php echo nl2br(htmlspecialchars($pod_details['delivery_notes'])); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($pod_details['image_path'])): ?>
                        <div class="mt-2">
                            <img src="<?php echo htmlspecialchars(BASE_URL . $pod_details['image_path']); ?>" alt="POD Image" class="max-w-xs h-auto border rounded-lg shadow-sm">
                        </div>
                    <?php endif; ?>
                    <!-- Signature data display if available -->
                </div>
            <?php endif; ?>

            <?php if ($voucher['status'] !== 'Delivered' && $voucher['status'] !== 'Cancelled' && $voucher['status'] !== 'Returned'): // Allow update if not already final status ?>
                <form action="index.php?page=driver_voucher_detail&id=<?php echo htmlspecialchars($voucher['id']); ?>" method="POST" enctype="multipart/form-data" class="show-loader-on-submit">
                    <input type="hidden" name="action" value="update_status">
                    <div class="mb-4">
                        <label for="new_status" class="block text-gray-700 text-sm font-semibold mb-2">Change Status:</label>
                        <select id="new_status" name="new_status" class="form-select" required>
                            <option value="">Select New Status</option>
                            <?php
                            $allowed_driver_statuses = ['In Transit', 'Delivered', 'Received', 'Returned']; // Drivers can only set these
                            foreach ($allowed_driver_statuses as $status_option):
                                // Allow 'Delivered' only if POD is also being added, or if it's already delivered
                                $is_delivered_option = ($status_option === 'Delivered');
                                $can_select_delivered = ($is_delivered_option && !$pod_details) || ($is_delivered_option && $voucher['status'] === 'Delivered');

                                if ($is_delivered_option && !$can_select_delivered) {
                                    // Don't show "Delivered" unless POD is being added or it's already delivered
                                    continue;
                                }
                            ?>
                                <option value="<?php echo htmlspecialchars($status_option); ?>"
                                    <?php echo ($voucher['status'] === $status_option) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label for="notes" class="block text-gray-700 text-sm font-semibold mb-2">Notes for Status Update (Optional):</label>
                        <textarea id="notes" name="notes" rows="2" class="form-input" placeholder="Add a note about this status change"></textarea>
                    </div>

                    <h4 class="text-lg font-semibold text-gray-700 mt-6 mb-3">Record Proof of Delivery (POD)</h4>
                    <input type="hidden" name="action" value="add_pod"> <!-- This action will override status update if file is present -->
                    <div class="mb-3">
                        <label for="pod_image" class="block text-gray-700 text-sm font-semibold mb-2">Upload Delivery Image (Optional):</label>
                        <input type="file" id="pod_image" name="pod_image" accept="image/*" class="form-input">
                    </div>
                    <div class="mb-3">
                        <label for="delivery_notes" class="block text-gray-700 text-sm font-semibold mb-2">Delivery Notes for POD (Optional):</label>
                        <textarea id="delivery_notes" name="delivery_notes" rows="2" class="form-input" placeholder="e.g., Left with security, Delivered to recipient"></textarea>
                    </div>

                    <button type="submit" class="btn btn-green px-4 py-2 rounded-md">Update Status & Record POD</button>
                </form>
            <?php else: ?>
                <p class="text-gray-600 italic">This voucher's status (<?php echo htmlspecialchars($voucher['status']); ?>) cannot be updated from here.</p>
            <?php endif; ?>
        </div>

        <!-- Action Buttons -->
        <div class="flex justify-center mt-8 space-x-4">
            <a href="index.php?page=driver_dashboard" class="btn btn-slate py-2 px-4 rounded-lg shadow-md">Back to Dashboard</a>
        </div>
    <?php else: ?>
        <div class="text-center">
            <p class="text-red-500">Voucher data could not be loaded.</p>
        </div>
    <?php endif; ?>
</div>

<?php include_template('footer'); ?>