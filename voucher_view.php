<?php
// voucher_view.php - Displays details of a specific voucher and allows status/notes update.

// This file is loaded by index.php, so config.php, db_connect.php, and functions.php are already loaded.
// Session is already started.

// Authentication Check
if (!is_logged_in()) {
    flash_message('error', 'Please log in to view vouchers.');
    redirect('index.php?page=login');
}

// Get Voucher ID
$voucher_id = intval($_GET['id'] ?? 0);
if ($voucher_id <= 0) {
    flash_message('error', 'Invalid voucher ID provided.');
    redirect('index.php?page=voucher_list');
}

global $connection;
$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? null;
$is_admin = is_admin();

// Fetch Current User's Details (for permissions and logging)
$user_region_id = null;
$current_username = 'System'; // Default username
$stmt_user = mysqli_prepare($connection, "SELECT username, region_id FROM users WHERE id = ?");
if ($stmt_user) {
    mysqli_stmt_bind_param($stmt_user, 'i', $user_id);
    mysqli_stmt_execute($stmt_user);
    $result_user = mysqli_stmt_get_result($stmt_user);
    if ($row = mysqli_fetch_assoc($result_user)) {
        $user_region_id = $row['region_id'];
        $current_username = $row['username'];
    }
    mysqli_stmt_close($stmt_user);
}

$possible_statuses = ['Pending', 'In Transit', 'Delivered', 'Received', 'Cancelled', 'Returned'];

// --- Handle POST request (Form Submission for Status and Notes Update) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_status = trim($_POST['status'] ?? '');
    $new_note_entry = trim($_POST['notes'] ?? '');

    $errors = [];
    if (!in_array($new_status, $possible_statuses)) {
        $errors[] = 'Invalid status selected.';
    }

    // Fetch current voucher data to verify permission AND get existing notes
    $stmt_auth_check = mysqli_prepare($connection, "SELECT created_by_user_id, status, region_id, destination_region_id, notes FROM vouchers WHERE id = ?");
    if ($stmt_auth_check) {
        mysqli_stmt_bind_param($stmt_auth_check, 'i', $voucher_id);
        mysqli_stmt_execute($stmt_auth_check);
        $result_auth_check = mysqli_stmt_get_result($stmt_auth_check);
        $voucher_current_data = mysqli_fetch_assoc($result_auth_check);
        mysqli_stmt_close($stmt_auth_check);

        if (!$voucher_current_data) {
            $errors[] = 'Voucher not found for permission check.';
        } else {
            // --- Permission Check for UPDATE ---
            $can_update = false;

            if ($is_admin || ($voucher_current_data['created_by_user_id'] == $user_id)) {
                $can_update = true;
            } elseif (($user_type === USER_TYPE_MYANMAR || $user_type === USER_TYPE_MALAY) && $user_region_id !== null) {
                if ($voucher_current_data['status'] === 'Pending' && $user_region_id == $voucher_current_data['region_id']) {
                    $can_update = true;
                } elseif ($voucher_current_data['status'] === 'Delivered') {
                    $can_update = true;
                } elseif ($user_region_id == $voucher_current_data['region_id'] || $user_region_id == $voucher_current_data['destination_region_id']) {
                    $can_update = true;
                }
            }

            if (!$can_update) {
                $errors[] = 'You do not have permission to update this voucher.';
            }
        }
    } else {
        $errors[] = 'Database error during permission check: ' . mysqli_error($connection);
    }

    if (!empty($errors)) {
        flash_message('error', implode('<br>', $errors));
    } else {
        // Fetch current status for logging
        $old_status = $voucher_current_data['status'];
        $existing_notes = $voucher_current_data['notes'];
        $updated_notes = $existing_notes;

        if (!empty($new_note_entry) || $old_status !== $new_status) {
            $timestamp = date('Y-m-d H:i:s');
            $note_prefix = "Status changed to '{$new_status}'";
            if ($old_status === $new_status && !empty($new_note_entry)) {
                 $note_prefix = "Note added";
            } else if ($old_status !== $new_status && empty($new_note_entry)) {
                 // Only status changed, no new note text
            } else if ($old_status !== $new_status && !empty($new_note_entry)) {
                 $note_prefix .= " with note";
            }

            $log_entry_text = "{$note_prefix}: {$new_note_entry}";
            $log_entry = "--- {$timestamp} by {$current_username} ---\n{$log_entry_text}\n";

            $updated_notes = $existing_notes . (empty($existing_notes) ? '' : "\n") . $log_entry;

            log_voucher_status_change($voucher_id, $old_status, $new_status, $new_note_entry, $user_id);
        }

        $update_sql = "UPDATE vouchers SET status = ?, notes = ? WHERE id = ?";
        $stmt_update = mysqli_prepare($connection, $update_sql);

        if ($stmt_update) {
            mysqli_stmt_bind_param($stmt_update, 'ssi', $new_status, $updated_notes, $voucher_id);
            if (mysqli_stmt_execute($stmt_update)) {
                flash_message('success', 'Voucher status and notes updated successfully!');
            } else {
                flash_message('error', 'Failed to update voucher: ' . mysqli_stmt_error($stmt_update));
            }
            mysqli_stmt_close($stmt_update);
        } else {
            flash_message('error', 'Failed to prepare update statement: ' . mysqli_error($connection));
        }
    }
}

// --- Fetch Full Voucher Details for Display ---
$voucher = null;
$breakdowns = [];
$total_breakdown_kg = 0.00;
$qr_code_url = ''; // Changed from qr_code_image to qr_code_url

try {
    $query_voucher = "SELECT v.*, r_origin.region_name AS origin_region_name, r_origin.prefix AS origin_prefix,
                             r_dest.region_name AS destination_region_name, u.username AS created_by_username
                      FROM vouchers v
                      LEFT JOIN regions r_origin ON v.region_id = r_origin.id
                      LEFT JOIN regions r_dest ON v.destination_region_id = r_dest.id
                      LEFT JOIN users u ON v.created_by_user_id = u.id
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
        redirect('index.php?page=voucher_list');
    }

    // --- View Permission Logic ---
    $has_view_permission = false;

    if ($is_admin) {
        $has_view_permission = true;
    } elseif ($voucher['created_by_user_id'] == $user_id) {
        $has_view_permission = true;
    } elseif (($user_type === USER_TYPE_MYANMAR || $user_type === USER_TYPE_MALAY) && $user_region_id !== null) {
        if ($voucher['status'] === 'Pending') {
            if ($user_region_id == $voucher['region_id']) {
                $has_view_permission = true;
            }
        } elseif ($voucher['status'] === 'Delivered') {
            $has_view_permission = true;
        } else {
            if ($user_region_id == $voucher['region_id'] || $user_region_id == $voucher['destination_region_id']) {
                $has_view_permission = true;
            }
        }
    } else {
        $has_view_permission = false;
    }

    if (!$has_view_permission) {
        flash_message('error', 'You do not have permission to view this voucher.');
        redirect('index.php?page=voucher_list');
    }

    // Fetch breakdowns
    $stmt_breakdowns = mysqli_prepare($connection, "SELECT item_type, kg, price_per_kg FROM voucher_breakdowns WHERE voucher_id = ?");
    if ($stmt_breakdowns) {
        mysqli_stmt_bind_param($stmt_breakdowns, 'i', $voucher_id);
        mysqli_stmt_execute($stmt_breakdowns);
        $result_breakdowns = mysqli_stmt_get_result($stmt_breakdowns);
        while ($row = mysqli_fetch_assoc($result_breakdowns)) {
            $breakdowns[] = $row;
            $total_breakdown_kg += (float)$row['kg'];
        }
        mysqli_stmt_close($stmt_breakdowns);
    }

    // Determine Current Region Display
    $current_region_display = 'N/A';
    switch ($voucher['status']) {
        case 'Pending': $current_region_display = htmlspecialchars($voucher['origin_region_name']); break;
        case 'In Transit': case 'Delivered': case 'Received': $current_region_display = htmlspecialchars($voucher['destination_region_name']); break;
        case 'Cancelled': case 'Returned': $current_region_display = 'N/A (Status: ' . htmlspecialchars($voucher['status']) . ')'; break;
        default: $current_region_display = 'Unknown'; break;
    }

    // Generate QR Code URL
    $base_url = 'http://localhost/v3.pos.mblogistics/'; // IMPORTANT: Replace with your actual base URL in production
    $qr_code_data_url = $base_url . 'index.php?page=customer_view_voucher&code=' . urlencode($voucher['voucher_code']);
    $qr_code_url = generate_qr_code_url($qr_code_data_url, 150); // Get QR code URL

} catch (Exception $e) {
    error_log("Voucher View Error: " . $e->getMessage());
    flash_message('error', 'An error occurred while loading the voucher.');
    redirect('index.php?page=voucher_list');
}

// --- Include Header and Render HTML ---
include_template('header', ['page' => 'voucher_view']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Voucher Details: #<?php echo htmlspecialchars($voucher['voucher_code']); ?></h2>

    <?php if ($voucher): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-8">
            <div class="bg-blue-50 p-6 rounded-lg shadow-inner">
                <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Voucher Information</h3>
                <p><strong class="text-gray-800">Voucher Code:</strong> <span class="font-mono text-indigo-600 text-lg"><?php echo htmlspecialchars($voucher['voucher_code']); ?></span></p>
                <p><strong class="text-gray-800">Origin Region:</strong> <?php echo htmlspecialchars($voucher['origin_region_name']); ?></p>
                <p><strong class="text-gray-800">Destination Region:</strong> <?php echo htmlspecialchars($voucher['destination_region_name']); ?></p>
                <p><strong class="text-gray-800">Current Region:</strong> <?php echo $current_region_display; ?></p>
                <p><strong class="text-gray-800">Total Weight:</strong> <?php echo htmlspecialchars(number_format((float)$voucher['weight_kg'], 2)); ?> kg</p>
                <p><strong class="text-gray-800">Delivery Charge:</strong> <?php echo htmlspecialchars(number_format((float)$voucher['delivery_charge'], 2)); ?></p>
                <p class="text-xl font-bold"><strong class="text-gray-800">Total Amount:</strong> <span class="text-green-600"><?php echo htmlspecialchars(number_format((float)$voucher['total_amount'], 2) . ' ' . $voucher['currency']); ?></span></p>
                <p><strong class="text-gray-800">Payment Method:</strong> <?php echo htmlspecialchars($voucher['payment_method'] ?: 'N/A'); ?></p>
                <p><strong class="text-gray-800">Delivery Type:</strong> <?php echo htmlspecialchars($voucher['delivery_type'] ?: 'N/A'); ?></p>
                <p><strong class="text-gray-800">Status:</strong>
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
                <p><strong class="text-gray-800">Created By:</strong> <?php echo htmlspecialchars($voucher['created_by_username']); ?></p>
                <p><strong class="text-gray-800">Created At:</strong> <?php echo date('Y-m-d H:i:s', strtotime($voucher['created_at'])); ?></p>

                <?php if (!empty($qr_code_url)): // Changed to qr_code_url ?>
                    <div class="mt-4 text-center">
                        <p class="text-gray-700 text-sm mb-2">Scan for Public View:</p>
                        <img src="<?php echo $qr_code_url; ?>" alt="QR Code for Voucher <?php echo htmlspecialchars($voucher['voucher_code']); ?>" class="mx-auto border border-gray-300 p-1 rounded-md">
                        <p class="text-xs text-gray-500 mt-1">Share this QR code with the customer.</p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="bg-green-50 p-6 rounded-lg shadow-inner">
                <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Sender Information</h3>
                <p><strong class="text-gray-800">Name:</strong> <?php echo htmlspecialchars($voucher['sender_name']); ?></p>
                <p><strong class="text-gray-800">Phone:</strong> <?php echo htmlspecialchars($voucher['sender_phone']); ?></p>
                <p><strong class="text-gray-800">Address:</strong> <?php echo nl2br(htmlspecialchars($voucher['sender_address'] ?: 'N/A')); ?></p>
            </div>

            <div class="bg-yellow-50 p-6 rounded-lg shadow-inner">
                <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Receiver Information</h3>
                <p><strong class="text-gray-800">Name:</strong> <?php echo htmlspecialchars($voucher['receiver_name']); ?></p>
                <p><strong class="text-gray-800">Phone:</strong> <?php echo htmlspecialchars($voucher['receiver_phone']); ?></p>
                <p><strong class="text-gray-800">Address:</strong> <?php echo nl2br(htmlspecialchars($voucher['receiver_address'])); ?></p>
            </div>
        </div>

        <div class="bg-purple-50 p-6 rounded-lg shadow-inner mb-8">
            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Voucher Notes & Status Update</h3>
            <div class="mb-4 bg-white p-4 rounded-lg border max-h-60 overflow-y-auto">
                <p class="text-sm text-gray-800 whitespace-pre-wrap"><?php echo htmlspecialchars($voucher['notes'] ?: 'No notes yet.'); ?></p>
            </div>

            <?php
            $can_update_status_notes = false;

            if ($is_admin || ($voucher['created_by_user_id'] == $user_id)) {
                $can_update_status_notes = true;
            } elseif (($user_type === USER_TYPE_MYANMAR || $user_type === USER_TYPE_MALAY) && $user_region_id !== null) {
                if ($voucher['status'] === 'Pending' && $user_region_id == $voucher['region_id']) {
                    $can_update_status_notes = true;
                } elseif ($voucher['status'] === 'Delivered') {
                    $can_update_status_notes = true;
                } elseif ($user_region_id == $voucher['region_id'] || $user_region_id == $voucher['destination_region_id']) {
                    $can_update_status_notes = true;
                }
            }

            if ($can_update_status_notes):
            ?>
            <form action="index.php?page=voucher_view&id=<?php echo htmlspecialchars($voucher['id']); ?>" method="POST" class="mt-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="status" class="block text-gray-700 text-sm font-semibold mb-2">Change Status:</label>
                        <select id="status" name="status" class="border border-gray-300 rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500" required>
                            <?php foreach ($possible_statuses as $status_option): ?>
                                <option value="<?php echo htmlspecialchars($status_option); ?>"
                                    <?php echo ($voucher['status'] === $status_option) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($status_option); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="notes" class="block text-gray-700 text-sm font-semibold mb-2">Add New Note (Optional):</label>
                        <textarea id="notes" name="notes" rows="3" class="border border-gray-300 rounded-lg p-2 w-full focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Add a new note to the log..."></textarea>
                    </div>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="btn btn-green font-bold py-2 px-6 rounded-lg shadow-md">Update Voucher</button>
                </div>
            </form>
            <?php else: ?>
                <p class="text-gray-600 italic">You do not have permission to update the status or notes for this voucher.</p>
            <?php endif; ?>
        </div>

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
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php
                            $breakdown_subtotal = 0;
                            foreach ($breakdowns as $item):
                                $subtotal = (float)$item['kg'] * (float)$item['price_per_kg'];
                                $breakdown_subtotal += $subtotal;
                            ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($item['item_type'] ?: 'N/A'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars(number_format((float)$item['kg'], 2)); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars(number_format((float)$item['price_per_kg'], 2)); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars(number_format($subtotal, 2)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-lg font-bold text-gray-800 mt-4">Total Kg (from items): <span class="text-blue-700"><?php echo htmlspecialchars(number_format($total_breakdown_kg, 2)); ?> kg</span></p>
            <?php endif; ?>
        </div>

        <div class="flex justify-center mt-8 space-x-4">
            <a href="voucher_print.php?id=<?php echo htmlspecialchars($voucher['id']); ?>" target="_blank" class="btn btn-blue py-2 px-4 rounded-lg shadow-md">Print Voucher</a>
            <a href="index.php?page=voucher_list" class="btn btn-slate py-2 px-4 rounded-lg shadow-md">Back to Voucher List</a>
        </div>
    <?php else: ?>
        <div class="text-center">
            <p class="text-red-500">Voucher data could not be loaded.</p>
        </div>
    <?php endif; ?>
</div>

<?php include_template('footer'); ?>