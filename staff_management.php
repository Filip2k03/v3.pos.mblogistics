<?php
// staff_management.php

require_once 'config.php';
require_once 'includes/functions.php'; // is_logged_in, flash_message, redirect, is_admin

if (!is_logged_in()) {
    flash_message('error', 'Please log in to manage staff.');
    redirect('index.php?page=login');
}

global $connection;
$is_admin = is_admin();

// Authorization: Only Admins can manage staff
if (!$is_admin) {
    flash_message('error', 'You do not have permission to manage staff accounts.');
    redirect('index.php?page=dashboard');
}

$staff_users = [];
$errors = [];
$edit_user = null; // For holding data of user being edited

// Get all possible regions for dropdown
$regions = [];
$stmt_regions = mysqli_query($connection, "SELECT id, region_name FROM regions ORDER BY region_name");
if ($stmt_regions) {
    while ($row = mysqli_fetch_assoc($stmt_regions)) {
        $regions[] = $row;
    }
    mysqli_free_result($stmt_regions);
}

// Get all defined user types (from config)
$user_types = ['ADMIN', 'Myanmar', 'Malay', 'Staff', 'Driver']; // Define as an array for dropdown

// Get all defined currencies (from config/expenses)
$currencies = ['MMK', 'RM', 'BAT', 'SGD']; // Make sure this matches your system's currencies

// --- Handle Form Submissions (Add/Edit/Delete User) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $username = trim($_POST['username'] ?? '');
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $user_type_selected = trim($_POST['user_type_selected'] ?? '');
        $region_id = intval($_POST['region_id'] ?? 0);
        $currency_preference = trim($_POST['currency_preference'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $user_id_being_edited = intval($_POST['user_id'] ?? 0); // Only for 'edit'

        if (empty($username) || empty($full_name) || empty($user_type_selected)) {
            $errors[] = 'Username, Full Name, and User Type are required.';
        }
        if (!in_array($user_type_selected, $user_types)) {
            $errors[] = 'Invalid User Type selected.';
        }
        if ($region_id <= 0 && ($user_type_selected === USER_TYPE_MYANMAR || $user_type_selected === USER_TYPE_MALAY)) {
            $errors[] = 'Region is required for Myanmar and Malay user types.';
        }
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format.';
        }
        if (!in_array($currency_preference, $currencies)) {
             $errors[] = 'Invalid Currency Preference selected.';
        }


        // Additional validation for unique username/email/phone (on add and edit if changed)
        if (empty($errors)) {
            try {
                if ($action === 'add') {
                    $password = password_hash($_POST['password'] ?? 'default123', PASSWORD_DEFAULT); // Default password for new users
                    if (empty(trim($_POST['password'] ?? ''))) {
                         $errors[] = 'Password is required for new users.';
                    }
                    $stmt = mysqli_prepare($connection, "INSERT INTO users (username, password, full_name, email, phone, address, user_type, region_id, currency_preference, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    if (!$stmt) throw new Exception("Failed to prepare add user statement: " . mysqli_error($connection));
                    mysqli_stmt_bind_param($stmt, 'sssssssiis', $username, $password, $full_name, $email, $phone, $address, $user_type_selected, $region_id > 0 ? $region_id : null, $currency_preference, $is_active);
                    if (!mysqli_stmt_execute($stmt)) {
                        if (mysqli_errno($connection) == 1062) { // Duplicate entry error code
                            throw new Exception("Username, Email or Phone already exists.");
                        }
                        throw new Exception("Failed to add user: " . mysqli_stmt_error($stmt));
                    }
                    flash_message('success', 'Staff user added successfully!');
                } elseif ($action === 'edit') {
                    if ($user_id_being_edited <= 0) throw new Exception("Invalid user ID for edit.");

                    // Build dynamic update query based on whether password is provided
                    $update_fields = "full_name = ?, email = ?, phone = ?, address = ?, user_type = ?, region_id = ?, currency_preference = ?, is_active = ?";
                    $bind_types = "sssssiis";
                    $bind_params_array = [&$full_name, &$email, &$phone, &$address, &$user_type_selected, &$region_id, &$currency_preference, &$is_active];

                    if (!empty(trim($_POST['password'] ?? ''))) {
                        $new_password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                        $update_fields .= ", password = ?";
                        $bind_types .= "s";
                        $bind_params_array[] = &$new_password;
                    }

                    $update_sql = "UPDATE users SET " . $update_fields . " WHERE id = ?";
                    $bind_types .= "i";
                    $bind_params_array[] = &$user_id_being_edited;

                    $stmt = mysqli_prepare($connection, $update_sql);
                    if (!$stmt) throw new Exception("Failed to prepare edit user statement: " . mysqli_error($connection));

                    // Use call_user_func_array for dynamic bind_param
                    call_user_func_array('mysqli_stmt_bind_param', array_merge([$stmt, $bind_types], $bind_params_array));

                    if (!mysqli_stmt_execute($stmt)) {
                        if (mysqli_errno($connection) == 1062) {
                            throw new Exception("Username, Email or Phone already exists.");
                        }
                        throw new Exception("Failed to update user: " . mysqli_stmt_error($stmt));
                    }
                    flash_message('success', 'Staff user updated successfully!');
                }
            } catch (Exception $e) {
                flash_message('error', $e->getMessage());
            } finally {
                if (isset($stmt)) mysqli_stmt_close($stmt);
            }
        } else {
            flash_message('error', implode('<br>', $errors));
        }
        redirect('index.php?page=staff_management');
    } elseif ($action === 'delete') {
        $user_id_to_delete = intval($_POST['user_id'] ?? 0);
        if ($user_id_to_delete <= 0 || $user_id_to_delete == $_SESSION['user_id']) { // Prevent self-deletion
            flash_message('error', 'Invalid user ID for deletion or cannot delete yourself.');
        } else {
            try {
                // Consider impact on related records (vouchers created by this user etc.)
                // Set `is_active` to 0 instead of hard delete, or implement proper foreign key handling.
                $stmt = mysqli_prepare($connection, "DELETE FROM users WHERE id = ?"); // Or UPDATE users SET is_active = 0
                if (!$stmt) throw new Exception("Failed to prepare delete statement: " . mysqli_error($connection));
                mysqli_stmt_bind_param($stmt, 'i', $user_id_to_delete);
                if (!mysqli_stmt_execute($stmt)) throw new Exception("Failed to delete user: " . mysqli_stmt_error($stmt));
                flash_message('success', 'Staff user deleted successfully!');
            } catch (Exception $e) {
                flash_message('error', 'Error deleting staff user: ' . $e->getMessage());
            } finally {
                if (isset($stmt)) mysqli_stmt_close($stmt);
            }
        }
        redirect('index.php?page=staff_management');
    }
}

// --- Handle GET request for editing a user ---
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $user_id_to_edit = intval($_GET['id']);
    $stmt = mysqli_prepare($connection, "SELECT id, username, full_name, email, phone, address, user_type, region_id, currency_preference, is_active FROM users WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $user_id_to_edit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $edit_user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        if (!$edit_user) {
            flash_message('error', 'User not found for editing.');
            redirect('index.php?page=staff_management');
        }
    } else {
        flash_message('error', 'Database error preparing edit query: ' . mysqli_error($connection));
        redirect('index.php?page=staff_management');
    }
}


// --- Fetch all staff users for display ---
$query = "SELECT u.id, u.username, u.full_name, u.email, u.phone, u.user_type, u.is_active, r.region_name
          FROM users u
          LEFT JOIN regions r ON u.region_id = r.id
          ORDER BY u.username ASC";
$result = mysqli_query($connection, $query);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $staff_users[] = $row;
    }
    mysqli_free_result($result);
} else {
    flash_message('error', 'Error fetching staff users: ' . mysqli_error($connection));
}

include_template('header', ['page' => 'staff_management']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Staff Management</h2>

    <div class="bg-purple-100 p-6 rounded-lg shadow-inner mb-8">
        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">
            <?php echo $edit_user ? 'Edit Staff User' : 'Add New Staff User'; ?>
        </h3>
        <form action="index.php?page=staff_management" method="POST">
            <input type="hidden" name="action" value="<?php echo $edit_user ? 'edit' : 'add'; ?>">
            <?php if ($edit_user): ?>
                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($edit_user['id']); ?>">
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="mb-4">
                    <label for="username" class="block text-gray-700 text-sm font-semibold mb-2">Username:</label>
                    <input type="text" id="username" name="username" class="form-input"
                           value="<?php echo htmlspecialchars($edit_user['username'] ?? ''); ?>" <?php echo $edit_user ? 'readonly' : 'required'; ?>>
                    <?php if ($edit_user): ?><small class="text-gray-500">Username cannot be changed.</small><?php endif; ?>
                </div>
                <div class="mb-4">
                    <label for="password" class="block text-gray-700 text-sm font-semibold mb-2">Password <?php echo $edit_user ? '(Leave blank to keep current)' : ''; ?>:</label>
                    <input type="password" id="password" name="password" class="form-input" <?php echo $edit_user ? '' : 'required'; ?>>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="mb-4">
                    <label for="full_name" class="block text-gray-700 text-sm font-semibold mb-2">Full Name:</label>
                    <input type="text" id="full_name" name="full_name" class="form-input"
                           value="<?php echo htmlspecialchars($edit_user['full_name'] ?? ''); ?>" required>
                </div>
                <div class="mb-4">
                    <label for="email" class="block text-gray-700 text-sm font-semibold mb-2">Email (Optional):</label>
                    <input type="email" id="email" name="email" class="form-input"
                           value="<?php echo htmlspecialchars($edit_user['email'] ?? ''); ?>">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="mb-4">
                    <label for="phone" class="block text-gray-700 text-sm font-semibold mb-2">Phone (Optional):</label>
                    <input type="text" id="phone" name="phone" class="form-input"
                           value="<?php echo htmlspecialchars($edit_user['phone'] ?? ''); ?>">
                </div>
                <div class="mb-4">
                    <label for="address" class="block text-gray-700 text-sm font-semibold mb-2">Address (Optional):</label>
                    <textarea id="address" name="address" rows="1" class="form-input"><?php echo htmlspecialchars($edit_user['address'] ?? ''); ?></textarea>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="mb-4">
                    <label for="user_type_selected" class="block text-gray-700 text-sm font-semibold mb-2">User Type:</label>
                    <select id="user_type_selected" name="user_type_selected" class="form-select" required>
                        <option value="">Select Type</option>
                        <?php foreach ($user_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>"
                                <?php echo (isset($edit_user['user_type']) && $edit_user['user_type'] === $type) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="region_id" class="block text-gray-700 text-sm font-semibold mb-2">Assigned Region (for Myanmar/Malay/Driver):</label>
                    <select id="region_id" name="region_id" class="form-select">
                        <option value="0">None</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo htmlspecialchars($region['id']); ?>"
                                <?php echo (isset($edit_user['region_id']) && $edit_user['region_id'] == $region['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($region['region_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="currency_preference" class="block text-gray-700 text-sm font-semibold mb-2">Currency Preference:</label>
                    <select id="currency_preference" name="currency_preference" class="form-select" required>
                        <option value="">Select Currency</option>
                        <?php foreach ($currencies as $curr): ?>
                            <option value="<?php echo htmlspecialchars($curr); ?>"
                                <?php echo (isset($edit_user['currency_preference']) && $edit_user['currency_preference'] === $curr) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($curr); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mb-4 flex items-center">
                <input type="checkbox" id="is_active" name="is_active" class="form-checkbox h-5 w-5 text-green-600"
                       <?php echo (isset($edit_user['is_active']) && $edit_user['is_active'] == 1) ? 'checked' : ''; ?>>
                <label for="is_active" class="ml-2 text-gray-700 font-semibold">Account Active</label>
            </div>

            <div class="flex justify-end gap-2">
                <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold py-2 px-4 rounded-lg shadow-md transition duration-300">
                    <?php echo $edit_user ? 'Update Staff User' : 'Add Staff User'; ?>
                </button>
                <?php if ($edit_user): ?>
                    <a href="index.php?page=staff_management" class="bg-gray-400 hover:bg-gray-500 text-white font-semibold py-2 px-4 rounded-lg shadow-md transition duration-300">Cancel Edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">All Staff Users</h3>
    <?php if (empty($staff_users)): ?>
        <p class="text-center text-gray-600">No staff users found.</p>
    <?php else: ?>
        <div class="overflow-x-auto rounded-lg shadow-md border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Username</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Full Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Phone</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Region</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($staff_users as $user): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['username']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($user['email'] ?: 'N/A'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($user['phone'] ?: 'N/A'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($user['user_type']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700"><?php echo htmlspecialchars($user['region_name'] ?: 'N/A'); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    <?php echo $user['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="index.php?page=staff_management&action=edit&id=<?php echo htmlspecialchars($user['id']); ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                <?php if ($user['id'] != $_SESSION['user_id']): // Prevent logged-in user from deleting themselves ?>
                                <form action="index.php?page=staff_management" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this user? This action cannot be undone.');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($user['id']); ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-900">Delete</button>
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

<?php include_template('footer'); ?>