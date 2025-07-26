<?php
// customer_management.php

// session_start();
require_once 'config.php';
require_once 'includes/functions.php'; // is_logged_in, flash_message, redirect, is_admin

if (!is_logged_in()) {
    flash_message('error', 'Please log in to manage customers.');
    redirect('index.php?page=login');
}

global $connection;
$is_admin = is_admin(); // Assume only admin can manage customers

// Basic authorization: Only admins can access customer management
if (!$is_admin) {
    flash_message('error', 'You do not have permission to manage customers.');
    redirect('index.php?page=dashboard'); // Or appropriate redirect
}

$customers = [];
$errors = [];
$edit_customer = null; // For holding data of customer being edited

// --- Handle Form Submissions (Add/Edit/Delete) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $customer_name = trim($_POST['customer_name'] ?? '');
        $phone_number = trim($_POST['phone_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $company_name = trim($_POST['company_name'] ?? '');
        $customer_id = intval($_POST['customer_id'] ?? 0); // Only for 'edit'

        if (empty($customer_name) || empty($phone_number)) {
            $errors[] = 'Customer Name and Phone Number are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) && !empty($email)) {
            $errors[] = 'Invalid email format.';
        }

        if (empty($errors)) {
            try {
                if ($action === 'add') {
                    $stmt = mysqli_prepare($connection, "INSERT INTO customers (customer_name, phone_number, email, address, company_name) VALUES (?, ?, ?, ?, ?)");
                    if (!$stmt) throw new Exception("Failed to prepare add statement: " . mysqli_error($connection));
                    mysqli_stmt_bind_param($stmt, 'sssss', $customer_name, $phone_number, $email, $address, $company_name);
                    if (!mysqli_stmt_execute($stmt)) throw new Exception("Failed to add customer: " . mysqli_stmt_error($stmt));
                    flash_message('success', 'Customer added successfully!');
                } elseif ($action === 'edit') {
                    if ($customer_id <= 0) throw new Exception("Invalid customer ID for edit.");
                    $stmt = mysqli_prepare($connection, "UPDATE customers SET customer_name = ?, phone_number = ?, email = ?, address = ?, company_name = ? WHERE id = ?");
                    if (!$stmt) throw new Exception("Failed to prepare edit statement: " . mysqli_error($connection));
                    mysqli_stmt_bind_param($stmt, 'sssssi', $customer_name, $phone_number, $email, $address, $company_name, $customer_id);
                    if (!mysqli_stmt_execute($stmt)) throw new Exception("Failed to update customer: " . mysqli_stmt_error($stmt));
                    flash_message('success', 'Customer updated successfully!');
                }
            } catch (Exception $e) {
                flash_message('error', $e->getMessage());
            } finally {
                if (isset($stmt)) mysqli_stmt_close($stmt);
            }
        } else {
            flash_message('error', implode('<br>', $errors));
        }
        redirect('index.php?page=customer_management');
    } elseif ($action === 'delete') {
        $customer_id = intval($_POST['customer_id'] ?? 0);
        if ($customer_id <= 0) {
            flash_message('error', 'Invalid customer ID for deletion.');
        } else {
            try {
                $stmt = mysqli_prepare($connection, "DELETE FROM customers WHERE id = ?");
                if (!$stmt) throw new Exception("Failed to prepare delete statement: " . mysqli_error($connection));
                mysqli_stmt_bind_param($stmt, 'i', $customer_id);
                if (!mysqli_stmt_execute($stmt)) throw new Exception("Failed to delete customer: " . mysqli_stmt_error($stmt));
                flash_message('success', 'Customer deleted successfully!');
            } catch (Exception $e) {
                flash_message('error', 'Error deleting customer: ' . $e->getMessage());
            } finally {
                if (isset($stmt)) mysqli_stmt_close($stmt);
            }
        }
        redirect('index.php?page=customer_management');
    }
}

// --- Handle GET request for editing a customer ---
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $customer_id_to_edit = intval($_GET['id']);
    $stmt = mysqli_prepare($connection, "SELECT * FROM customers WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $customer_id_to_edit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $edit_customer = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        if (!$edit_customer) {
            flash_message('error', 'Customer not found for editing.');
            redirect('index.php?page=customer_management');
        }
    } else {
        flash_message('error', 'Database error preparing edit query: ' . mysqli_error($connection));
        redirect('index.php?page=customer_management');
    }
}


// --- Fetch all customers for display ---
$query = "SELECT * FROM customers ORDER BY customer_name ASC";
$result = mysqli_query($connection, $query);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $customers[] = $row;
    }
    mysqli_free_result($result);
} else {
    flash_message('error', 'Error fetching customers: ' . mysqli_error($connection));
}

include_template('header', ['page' => 'customer_management']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-4xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Customer Management</h2>

    <div class="bg-blue-100 p-6 rounded-lg shadow-inner mb-8">
        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">
            <?php echo $edit_customer ? 'Edit Customer' : 'Add New Customer'; ?>
        </h3>
        <form action="index.php?page=customer_management" method="POST">
            <input type="hidden" name="action" value="<?php echo $edit_customer ? 'edit' : 'add'; ?>">
            <?php if ($edit_customer): ?>
                <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($edit_customer['id']); ?>">
            <?php endif; ?>

            <div class="mb-4">
                <label for="customer_name" class="block text-gray-700 text-sm font-semibold mb-2">Customer Name:</label>
                <input type="text" id="customer_name" name="customer_name" class="form-input"
                       value="<?php echo htmlspecialchars($edit_customer['customer_name'] ?? ''); ?>" required>
            </div>
            <div class="mb-4">
                <label for="phone_number" class="block text-gray-700 text-sm font-semibold mb-2">Phone Number:</label>
                <input type="text" id="phone_number" name="phone_number" class="form-input"
                       value="<?php echo htmlspecialchars($edit_customer['phone_number'] ?? ''); ?>" required>
            </div>
            <div class="mb-4">
                <label for="email" class="block text-gray-700 text-sm font-semibold mb-2">Email (Optional):</label>
                <input type="email" id="email" name="email" class="form-input"
                       value="<?php echo htmlspecialchars($edit_customer['email'] ?? ''); ?>">
            </div>
            <div class="mb-4">
                <label for="address" class="block text-gray-700 text-sm font-semibold mb-2">Address (Optional):</label>
                <textarea id="address" name="address" rows="3" class="form-input"><?php echo htmlspecialchars($edit_customer['address'] ?? ''); ?></textarea>
            </div>
            <div class="mb-4">
                <label for="company_name" class="block text-gray-700 text-sm font-semibold mb-2">Company Name (Optional):</label>
                <input type="text" id="company_name" name="company_name" class="form-input"
                       value="<?php echo htmlspecialchars($edit_customer['company_name'] ?? ''); ?>">
            </div>

            <div class="flex justify-end gap-2">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg shadow-md transition duration-300">
                    <?php echo $edit_customer ? 'Update Customer' : 'Add Customer'; ?>
                </button>
                <?php if ($edit_customer): ?>
                    <a href="index.php?page=customer_management" class="bg-gray-400 hover:bg-gray-500 text-white font-semibold py-2 px-4 rounded-lg shadow-md transition duration-300">Cancel Edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

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
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="index.php?page=customer_management&action=edit&id=<?php echo htmlspecialchars($customer['id']); ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                <form action="index.php?page=customer_management" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this customer? This action cannot be undone.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="customer_id" value="<?php echo htmlspecialchars($customer['id']); ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
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