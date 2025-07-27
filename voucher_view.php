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
$current_username = 'System';
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

// --- Handle POST request (Form Submission for Status and Notes Update, or POD) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_status_notes') {
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
            $voucher_current_data_for_update = mysqli_fetch_assoc($result_auth_check);
            mysqli_stmt_close($stmt_auth_check);

            if (!$voucher_current_data_for_update) {
                $errors[] = 'Voucher not found for permission check.';
            } else {
                // --- Permission Check for UPDATE ---
                $can_update = false;

                if ($is_admin || ($voucher_current_data_for_update['created_by_user_id'] == $user_id)) {
                    $can_update = true;
                } elseif (($user_type === USER_TYPE_MYANMAR || $user_type === USER_TYPE_MALAY) && $user_region_id !== null) {
                    if ($voucher_current_data_for_update['status'] === 'Pending' && $user_region_id == $voucher_current_data_for_update['region_id']) {
                        $can_update = true;
                    } elseif ($voucher_current_data_for_update['status'] === 'Delivered') {
                        $can_update = true;
                    } elseif ($user_region_id == $voucher_current_data_for_update['region_id'] || $user_region_id == $voucher_current_data_for_update['destination_region_id']) {
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
            $old_status = $voucher_current_data_for_update['status'];
            $existing_notes = $voucher_current_data_for_update['notes'];
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
    } elseif ($action === 'add_pod') { // NEW: Handle POD submission
        $delivery_notes = trim($_POST['delivery_notes'] ?? '');
        $signature_data = trim($_POST['signature_data'] ?? ''); // If using signature pad (base64)
        $pod_image_file = $_FILES['pod_image'] ?? null; // If uploading image file

        $errors = [];

        if (empty($delivery_notes) && (empty($signature_data) && (empty($pod_image_file) || $pod_image_file['error'] !== UPLOAD_ERR_OK))) {
            $errors[] = 'Proof of Delivery requires notes, an image, or a signature.';
        }

        // Basic permission check for POD (e.g., Drivers or Admins can add POD)
        $can_add_pod = $is_admin || ($user_type === USER_TYPE_DRIVER);
        if (!$can_add_pod) {
            $errors[] = 'You do not have permission to add Proof of Delivery.';
        }

        // Fetch voucher data for initial status and code
        $stmt_fetch_voucher = mysqli_prepare($connection, "SELECT voucher_code, status FROM vouchers WHERE id = ?");
        if ($stmt_fetch_voucher) {
            mysqli_stmt_bind_param($stmt_fetch_voucher, 'i', $voucher_id);
            mysqli_stmt_execute($stmt_fetch_voucher);
            $result_fetch_voucher = mysqli_stmt_get_result($stmt_fetch_voucher);
            $voucher_for_pod = mysqli_fetch_assoc($result_fetch_voucher);
            mysqli_stmt_close($stmt_fetch_voucher);

            if (!$voucher_for_pod) {
                $errors[] = 'Voucher not found for POD submission.';
            } else if ($voucher_for_pod['status'] === 'Delivered') {
                $errors[] = 'Proof of Delivery cannot be added to an already delivered voucher.';
            }
        } else {
             $errors[] = 'Database error checking voucher for POD: ' . mysqli_error($connection);
        }

        $image_path = null;
        if ($pod_image_file && $pod_image_file['error'] === UPLOAD_ERR_OK) {
            $image_path = handle_pod_file_upload($pod_image_file, POD_UPLOAD_DIR, $voucher_for_pod['voucher_code']);
            if (!$image_path) {
                $errors[] = 'Failed to upload POD image. Check file type/size or server permissions.';
            }
        }

        if (empty($errors)) {
            mysqli_begin_transaction($connection);
            try {
                $stmt_pod = mysqli_prepare($connection, "INSERT INTO proof_of_delivery (voucher_id, image_path, signature_data, delivery_notes, recorded_by_user_id) VALUES (?, ?, ?, ?, ?)");
                if (!$stmt_pod) {
                    throw new Exception("Failed to prepare POD insert statement: " . mysqli_error($connection));
                }
                mysqli_stmt_bind_param($stmt_pod, 'isssi', $voucher_id, $image_path, $signature_data, $delivery_notes, $user_id);
                if (!mysqli_stmt_execute($stmt_pod)) {
                    throw new Exception("Failed to insert POD: " . mysqli_stmt_error($stmt_pod));
                }
                mysqli_stmt_close($stmt_pod);

                // Update voucher status to Delivered
                $old_status = $voucher_for_pod['status'];
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


                mysqli_commit($connection);
                flash_message('success', 'Proof of Delivery recorded and voucher status updated to Delivered!');
            } catch (Exception $e) {
                mysqli_rollback($connection);
                // Attempt to delete partially uploaded file if transaction failed
                if ($image_path && file_exists(ASSETS_PATH . 'pod_images/' . basename($image_path))) {
                    unlink(ASSETS_PATH . 'pod_images/' . basename($image_path));
                }
                flash_message('error', 'Failed to record POD: ' . $e->getMessage());
            }
        } else {
            flash_message('error', implode('<br>', $errors));
        }
    }
}

// --- Fetch Full Voucher Details for Display ---
$voucher = null;
$breakdowns = [];
$total_breakdown_kg = 0.00;
$qr_code_url = '';
$pod_details = null; // NEW: To store POD information if available

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

    // Fetch POD details (NEW)
    $stmt_pod = mysqli_prepare($connection, "SELECT image_path, signature_data, delivery_notes, delivery_timestamp FROM proof_of_delivery WHERE voucher_id = ?");
    if ($stmt_pod) {
        mysqli_stmt_bind_param($stmt_pod, 'i', $voucher_id);
        mysqli_stmt_execute($stmt_pod);
        $result_pod = mysqli_stmt_get_result($stmt_pod);
        $pod_details = mysqli_fetch_assoc($result_pod);
        mysqli_stmt_close($stmt_pod);
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
    $qr_code_data_url = BASE_URL . 'index.php?page=customer_view_voucher&code=' . urlencode($voucher['voucher_code']);
    $qr_code_url = generate_qr_code_url($qr_code_data_url, 150);

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

                <?php if (!empty($qr_code_url)): ?>
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

        <?php if (!empty($voucher['consignment_id'])): ?>
        <div class="bg-orange-50 p-6 rounded-lg shadow-inner mb-8">
            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Consignment Details</h3>
            <?php
            $consignment_details = null;
            $stmt_consignment = mysqli_prepare($connection, "SELECT consignment_code, name, status, driver_id FROM consignments WHERE id = ?");
            if ($stmt_consignment) {
                mysqli_stmt_bind_param($stmt_consignment, 'i', $voucher['consignment_id']);
                mysqli_stmt_execute($stmt_consignment);
                $result_consignment = mysqli_stmt_get_result($stmt_consignment);
                $consignment_details = mysqli_fetch_assoc($result_consignment);
                mysqli_stmt_close($stmt_consignment);
            }
            if ($consignment_details):
                $driver_name = 'N/A';
                if (!empty($consignment_details['driver_id'])) {
                    $stmt_driver = mysqli_prepare($connection, "SELECT full_name FROM users WHERE id = ?");
                    if ($stmt_driver) {
                        mysqli_stmt_bind_param($stmt_driver, 'i', $consignment_details['driver_id']);
                        mysqli_stmt_execute($stmt_driver);
                        $result_driver = mysqli_stmt_get_result($stmt_driver);
                        if ($driver_data = mysqli_fetch_assoc($result_driver)) {
                            $driver_name = htmlspecialchars($driver_data['full_name']);
                        }
                        mysqli_stmt_close($stmt_driver);
                    }
                }
            ?>
            <p><strong class="text-gray-800">Consignment Code:</strong> <a href="index.php?page=consignment_view&id=<?php echo htmlspecialchars($voucher['consignment_id']); ?>" class="text-blue-600 hover:text-blue-800 font-medium"><?php echo htmlspecialchars($consignment_details['consignment_code']); ?></a></p>
            <p><strong class="text-gray-800">Name:</strong> <?php echo htmlspecialchars($consignment_details['name'] ?: 'N/A'); ?></p>
            <p><strong class="text-gray-800">Driver:</strong> <?php echo $driver_name; ?></p>
            <p><strong class="text-gray-800">Status:</strong> <?php echo htmlspecialchars($consignment_details['status']); ?></p>
            <?php else: ?>
            <p class="text-gray-600">Consignment details not found.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="bg-teal-50 p-6 rounded-lg shadow-inner mb-8">
            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Proof of Delivery (POD)</h3>
            <?php if ($pod_details): ?>
                <p><strong class="text-gray-800">Delivered At:</strong> <?php echo date('Y-m-d H:i:s', strtotime($pod_details['delivery_timestamp'])); ?></p>
                <?php if (!empty($pod_details['delivery_notes'])): ?>
                    <p><strong class="text-gray-800">Delivery Notes:</strong> <?php echo nl2br(htmlspecialchars($pod_details['delivery_notes'])); ?></p>
                <?php endif; ?>

                <?php if (!empty($pod_details['image_path'])): ?>
                    <div class="mt-4">
                        <p class="text-gray-800 font-semibold mb-2">Delivery Image:</p>
                        <img src="<?php echo htmlspecialchars($pod_details['image_path']); ?>" alt="Proof of Delivery Image" class="max-w-xs h-auto border rounded-lg shadow-sm">
                    </div>
                <?php endif; ?>

                <?php if (!empty($pod_details['signature_data'])): ?>
                    <div class="mt-4">
                        <p class="text-gray-800 font-semibold mb-2">Customer Signature:</p>
                        <img src="data:image/png;base64,<?php echo htmlspecialchars($pod_details['signature_data']); ?>" alt="Customer Signature" class="max-w-xs h-auto border rounded-lg shadow-sm">
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <p class="text-gray-600">No Proof of Delivery recorded yet.</p>
                <?php
                // Show POD add form for authorized users
                $can_add_pod_to_voucher = $is_admin || ($user_type === USER_TYPE_DRIVER); // Define who can add POD
                if ($can_add_pod_to_voucher && $voucher['status'] !== 'Delivered'):
                ?>
                <h4 class="text-lg font-semibold text-gray-700 mt-6 mb-3">Add Proof of Delivery</h4>
                <form action="index.php?page=voucher_view&id=<?php echo htmlspecialchars($voucher['id']); ?>" method="POST" enctype="multipart/form-data" class="bg-gray-50 p-4 rounded-lg shadow-inner show-loader-on-submit">
                    <input type="hidden" name="action" value="add_pod">
                    <div class="mb-3">
                        <label for="pod_image" class="block text-gray-700 text-sm font-semibold mb-2">Upload Delivery Image (Optional):</label>
                        <input type="file" id="pod_image" name="pod_image" accept="image/*" class="form-input">
                    </div>
                    <div class="mb-3">
                        <label for="delivery_notes" class="block text-gray-700 text-sm font-semibold mb-2">Delivery Notes (Optional):</label>
                        <textarea id="delivery_notes" name="delivery_notes" rows="3" class="form-input" placeholder="e.g., Left with security, Delivered to recipient"></textarea>
                    </div>
                    <button type="submit" class="btn btn-green px-4 py-2 rounded-md">Record POD & Mark Delivered</button>
                </form>
                <?php endif; ?>
            <?php endif; ?>
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
                if ($voucher['status'] === 'Pending') {
                    if ($user_region_id == $voucher['region_id']) {
                        $can_update_status_notes = true;
                    } elseif ($voucher['status'] === 'Delivered') {
                        $can_update_status_notes = true;
                    } elseif ($user_region_id == $voucher['region_id'] || $user_region_id == $voucher['destination_region_id']) {
                        $can_update_status_notes = true;
                    }
                }
            }

            if ($can_update_status_notes):
            ?>
            <form action="index.php?page=voucher_view&id=<?php echo htmlspecialchars($voucher['id']); ?>" method="POST" class="mt-4 show-loader-on-submit">
                <input type="hidden" name="action" value="update_status_notes">
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
                        <textarea id="notes" name="notes" rows="3" class="form-input" placeholder="Add a new note to the log..."></textarea>
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
                                    <td><?php echo htmlspecialchars($item['item_type'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars(number_format((float)$item['kg'], 2)); ?></td>
                                    <td>
                                        <?php
                                            $currency = $voucher['currency'];
                                            if ($currency === '0' || $currency === '' || $currency === null) $currency = '';
                                            echo htmlspecialchars($currency) . ' ' . number_format($item['price_per_kg'], 2);
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                            echo htmlspecialchars($currency) . ' ' . number_format($subtotal, 2);
                                        ?>
                                    </td>
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