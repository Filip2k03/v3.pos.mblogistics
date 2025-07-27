<?php
// customer_management.php - Manages customer records (CRUD). Admins can add/edit/delete.


require_once 'config.php';
require_once 'db_connect.php';
require_once INC_PATH . 'functions.php'; // is_logged_in, flash_message, redirect, is_admin

if (!is_logged_in()) {
    flash_message('error', 'Please log in to manage customers.');
    redirect('index.php?page=login');
}

global $connection;
$is_admin = is_admin();

// Authorization: Only admin can manage customers
if (!$is_admin) {
    flash_message('error', 'You do not have permission to manage customers.');
    redirect('index.php?page=dashboard'); // Or appropriate redirect
}

$customers = [];
$errors = [];
$edit_item = null; // For holding data of item being edited (renamed from edit_customer for consistency with master_data_management)

// Initialize form data for pre-filling on errors
$form_data = [
    'customer_name' => '',
    'phone_number' => '',
    'email' => '',
    'address' => '',
    'company_name' => '',
    // No password field here, as it's not pre-filled for security
];


// --- Handle Form Submissions (Add/Edit/Toggle Status) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // Use item_id for consistency with master_data_management, but it's customer_id here
    $item_id = intval($_POST['item_id'] ?? 0); // This is the customer_id for toggle_status
    $customer_id = intval($_POST['customer_id'] ?? 0); // This is the customer_id for add/edit actions

    if ($action === 'add' || $action === 'edit') {
        $customer_name = trim($_POST['customer_name'] ?? '');
        $phone_number = trim($_POST['phone_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        $password = $_POST['password'] ?? ''; // Only for add, or if changed on edit
        $confirm_password = $_POST['confirm_password'] ?? ''; // Only for add, or if changed on edit

        // Populate form_data for pre-filling on errors
        $form_data = [
            'customer_name' => $customer_name,
            'phone_number' => $phone_number,
            'email' => $email,
            'address' => $address,
            'company_name' => $company_name,
        ];

        // --- Validation ---
        if (empty($customer_name)) $errors[] = 'Customer Name is required.';
        if (empty($phone_number)) $errors[] = 'Phone Number is required.';
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';

        if ($action === 'add') {
            if (empty($password)) $errors[] = 'Password is required for new customers.';
            if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';
            if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters long.';
        } elseif ($action === 'edit' && !empty($password)) { // Password provided on edit, so validate it
            if ($password !== $confirm_password) $errors[] = 'New passwords do not match.';
            if (strlen($password) < 6) $errors[] = 'New password must be at least 6 characters long.';
        }


        if (empty($errors)) {
            mysqli_begin_transaction($connection);
            try {
                $hashed_password = null;
                if (!empty($password)) { // Hash password only if provided
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                }

                if ($action === 'add') {
                    $stmt = mysqli_prepare($connection, "INSERT INTO customers (customer_name, phone_number, password, email, address, company_name, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                    if (!$stmt) throw new Exception("Failed to prepare add statement: " . mysqli_error($connection));
                    $bind_email = empty($email) ? null : $email;
                    $bind_address = empty($address) ? null : $address;
                    $bind_company_name = empty($company_name) ? null : $company_name;

                    mysqli_stmt_bind_param($stmt, 'ssssss', $customer_name, $phone_number, $hashed_password, $bind_email, $bind_address, $bind_company_name);
                    if (!mysqli_stmt_execute($stmt)) {
                        if (mysqli_errno($connection) == 1062) { // Duplicate entry error code
                            throw new Exception("Phone Number or Email already exists. Please use a different one.");
                        }
                        throw new Exception("Failed to add customer: " . mysqli_stmt_error($stmt));
                    }
                    flash_message('success', 'Customer added successfully!');
                } elseif ($action === 'edit') {
                    if ($customer_id <= 0) throw new Exception("Invalid customer ID for edit.");

                    $update_fields = "customer_name = ?, phone_number = ?, email = ?, address = ?, company_name = ?";
                    $bind_types = "sssss";
                    $bind_params_array = [&$customer_name, &$phone_number, &$email, &$address, &$company_name];

                    if ($hashed_password !== null) { // Add password to update if provided
                        $update_fields .= ", password = ?";
                        $bind_types .= "s";
                        $bind_params_array[] = &$hashed_password;
                    }

                    $update_sql = "UPDATE customers SET " . $update_fields . " WHERE id = ?";
                    $bind_types .= "i";
                    $bind_params_array[] = &$customer_id;

                    $stmt = mysqli_prepare($connection, $update_sql);
                    if (!$stmt) throw new Exception("Failed to prepare edit statement: " . mysqli_error($connection));

                    call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt, $bind_types], $bind_params_array));

                    if (!mysqli_stmt_execute($stmt)) {
                        if (mysqli_errno($connection) == 1062) {
                            throw new Exception("Phone Number or Email already exists. Please use a different one.");
                        }
                        throw new Exception("Failed to update customer: " . mysqli_stmt_error($stmt));
                    }
                    flash_message('success', 'Customer updated successfully!');
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
        redirect('index.php?page=customer_management');
    } elseif ($action === 'toggle_status') { // Toggle active status
        // Use $item_id here as it comes from the list table's hidden input
        $customer_id_to_toggle = $item_id;
        $new_status = intval($_POST['new_status'] ?? 0); // 0 or 1
        if ($customer_id_to_toggle <= 0) {
            flash_message('error', 'Invalid customer ID for status toggle.');
        } else {
            try {
                $stmt = mysqli_prepare($connection, "UPDATE `customers` SET `is_active` = ? WHERE `id` = ?");
                if (!$stmt) throw new Exception("Failed to prepare status toggle statement: " . mysqli_error($connection));
                mysqli_stmt_bind_param($stmt, 'ii', $new_status, $customer_id_to_toggle);
                if (!mysqli_stmt_execute($stmt)) throw new Exception("Failed to toggle status: " . mysqli_stmt_error($stmt));
                flash_message('success', 'Customer status updated successfully!');
            } catch (Exception $e) {
                flash_message('error', 'Error toggling status: ' . $e->getMessage());
            } finally {
                if (isset($stmt)) mysqli_stmt_close($stmt);
            }
        }
        redirect("index.php?page=customer_management");
    }
}

// --- Handle GET request for editing a customer ---
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $customer_id_to_edit = intval($_GET['id']);
    $stmt = mysqli_prepare($connection, "SELECT id, customer_name, phone_number, email, address, company_name, is_active FROM customers WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $customer_id_to_edit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $edit_item = mysqli_fetch_assoc($result); // Use edit_item for consistency
        mysqli_stmt_close($stmt);
        if (!$edit_item) {
            flash_message('error', 'Customer not found for editing.');
            redirect('index.php?page=customer_management');
        }
        // Pre-fill form_data for editing
        $form_data = [
            'customer_name' => $edit_item['customer_name'],
            'phone_number' => $edit_item['phone_number'],
            'email' => $edit_item['email'],
            'address' => $edit_item['address'],
            'company_name' => $edit_item['company_name'],
        ];
    } else {
        flash_message('error', 'Database error preparing edit query: ' . mysqli_error($connection));
        redirect('index.php?page=customer_management');
    }
}


// --- Fetch all customers for display ---
$query = "SELECT id, customer_name, phone_number, email, company_name, is_active FROM customers ORDER BY customer_name ASC";
$result = mysqli_query($connection, $query);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $customers[] = $row;
    }
    mysqli_free_result($result);
} else {
    flash_message('error', 'Error fetching customers: ' . mysqli_error($connection));
}

// --- CORRECTED LINE ---
include_template('header', ['page' => 'customer_management']);
// --- END CORRECTED LINE ---
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-4xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Customer Management</h2>

    <!-- Add/Edit Customer Form -->
    <div class="bg-blue-100 p-6 rounded-lg shadow-inner mb-8">
        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">
            <?php echo $edit_item ? 'Edit Customer' : 'Add New Customer'; ?>
        </h3>
        <form action="index.php?page=customer_management" method="POST" class="show-loader-on-submit">
            <input type="hidden" name="action" value="<?php echo $edit_item ? 'edit' : 'add'; ?>">
            <?php if ($edit_item): ?>
                <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($edit_item['id']); ?>">
            <?php endif; ?>

            <div class="mb-4">
                <label for="customer_name" class="block text-gray-700 text-sm font-semibold mb-2">Customer Name:</label>
                <input type="text" id="customer_name" name="customer_name" class="form-input"
                       value="<?php echo htmlspecialchars($form_data['customer_name']); ?>" required>
            </div>
            <div class="mb-4">
                <label for="phone_number" class="block text-gray-700 text-sm font-semibold mb-2">Phone Number:</label>
                <input type="text" id="phone_number" name="phone_number" class="form-input"
                       value="<?php echo htmlspecialchars($form_data['phone_number']); ?>" required>
            </div>
            <div class="mb-4">
                <label for="email" class="block text-gray-700 text-sm font-semibold mb-2">Email (Optional):</label>
                <input type="email" id="email" name="email" class="form-input"
                       value="<?php echo htmlspecialchars($form_data['email']); ?>">
            </div>
            <div class="mb-4">
                <label for="address" class="block text-gray-700 text-sm font-semibold mb-2">Address (Optional):</label>
                <textarea id="address" name="address" rows="3" class="form-input"><?php echo htmlspecialchars($form_data['address']); ?></textarea>
            </div>
            <div class="mb-4">
                <label for="company_name" class="block text-gray-700 text-sm font-semibold mb-2">Company Name (Optional):</label>
                <input type="text" id="company_name" name="company_name" class="form-input"
                       value="<?php echo htmlspecialchars($form_data['company_name']); ?>">
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="mb-4">
                    <label for="password" class="block text-gray-700 text-sm font-semibold mb-2">Password <?php echo $edit_item ? '(Leave blank to keep current)' : ''; ?>:</label>
                    <input type="password" id="password" name="password" class="form-input" <?php echo $edit_item ? '' : 'required'; ?>>
                </div>
                <div class="mb-4">
                    <label for="confirm_password" class="block text-gray-700 text-sm font-semibold mb-2">Confirm Password <?php echo $edit_item ? '(Leave blank to keep current)' : ''; ?>:</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-input" <?php echo $edit_item ? '' : 'required'; ?>>
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <button type="submit" class="btn btn-blue py-2 px-4 rounded-lg shadow-md">
                    <?php echo $edit_item ? 'Update Customer' : 'Add Customer'; ?>
                </button>
                <?php if ($edit_item): ?>
                    <a href="index.php?page=customer_management" class="btn btn-slate py-2 px-4 rounded-lg shadow-md">Cancel Edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Customer List -->
    <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">All Customers</h3>
    <?php if (empty($customers)): ?>
        <p class="text-center text-gray-600">No customers found.</p>
    <?php else: ?>
        <div class="overflow-x-auto rounded-lg shadow-md border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Company</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($customers as $customer): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($customer['customer_name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($customer['phone_number']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($customer['email'] ?: 'N/A'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($customer['company_name'] ?: 'N/A'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    <?php echo $customer['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $customer['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="index.php?page=customer_management&action=edit&id=<?php echo htmlspecialchars($customer['id']); ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                <form action="index.php?page=customer_management" method="POST" class="inline-block show-loader-on-submit" onsubmit="return confirm('Are you sure you want to toggle the status of \'<?php echo htmlspecialchars($customer['customer_name']); ?>\'?');">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="item_id" value="<?php echo htmlspecialchars($customer['id']); ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $customer['is_active'] ? '0' : '1'; ?>">
                                    <button type="submit" class="text-blue-600 hover:text-blue-900">
                                        <?php echo $customer['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php include_template('footer'); ?>