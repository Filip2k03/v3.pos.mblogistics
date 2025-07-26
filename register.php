<?php
// register.php - Allows new staff users to register.

session_start();
require_once 'config.php';
require_once 'db_connect.php'; // Includes config.php and establishes $connection
require_once INC_PATH . 'functions.php'; // Helper functions

global $connection;

// If already logged in, redirect to dashboard
if (is_logged_in()) {
    flash_message('info', 'You are already logged in.');
    redirect('index.php?page=dashboard');
}

// Define allowed user types for self-registration (EXCLUDE 'ADMIN' for security)
$allowed_registration_user_types = [
    USER_TYPE_STAFF,
    USER_TYPE_DRIVER,
    USER_TYPE_MYANMAR,
    USER_TYPE_MALAY
];

// Define currencies for the dropdown (from expenses.php, ensure consistency)
$currencies = ['MMK', 'RM', 'BAT', 'SGD'];

// Fetch all regions for the dropdown
$regions = [];
$stmt_regions = mysqli_query($connection, "SELECT id, region_name FROM regions ORDER BY region_name");
if ($stmt_regions) {
    while ($row = mysqli_fetch_assoc($stmt_regions)) {
        $regions[] = $row;
    }
    mysqli_free_result($stmt_regions);
} else {
    flash_message('error', 'Error loading regions: ' . mysqli_error($connection));
}

// Initialize form data for pre-filling on errors
$form_data = [
    'username' => '',
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'user_type_selected' => '',
    'region_id' => '',
    'currency_preference' => 'MMK', // Default currency
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect and sanitize input
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $user_type_selected = trim($_POST['user_type_selected'] ?? '');
    $region_id = intval($_POST['region_id'] ?? 0);
    $currency_preference = trim($_POST['currency_preference'] ?? '');

    // Populate form_data for pre-filling
    $form_data = [
        'username' => $username,
        'full_name' => $full_name,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'user_type_selected' => $user_type_selected,
        'region_id' => $region_id,
        'currency_preference' => $currency_preference,
    ];

    $errors = [];

    // --- Validation ---
    if (empty($username)) $errors[] = 'Username is required.';
    if (empty($password)) $errors[] = 'Password is required.';
    if (empty($confirm_password)) $errors[] = 'Confirm Password is required.';
    if ($password !== $confirm_password) $errors[] = 'Passwords do not match.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters long.';
    if (empty($full_name)) $errors[] = 'Full Name is required.';
    if (empty($user_type_selected)) $errors[] = 'User Type is required.';
    if (!in_array($user_type_selected, $allowed_registration_user_types)) $errors[] = 'Invalid user type selected for registration.';
    if (!in_array($currency_preference, $currencies)) $errors[] = 'Invalid currency preference selected.';

    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email format.';

    // Check if region is required for specific user types
    if (($user_type_selected === USER_TYPE_MYANMAR || $user_type_selected === USER_TYPE_MALAY || $user_type_selected === USER_TYPE_DRIVER) && $region_id <= 0) {
        $errors[] = 'Region is required for the selected user type.';
    }

    if (empty($errors)) {
        try {
            // Hash the password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            // Prepare and execute the insert statement
            $stmt = mysqli_prepare($connection, "INSERT INTO users (username, password, full_name, email, phone, address, user_type, region_id, currency_preference, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
            if (!$stmt) {
                throw new Exception("Failed to prepare user registration statement: " . mysqli_error($connection));
            }

            // Bind parameters. Use null for optional fields if empty.
            $bind_region_id = ($region_id > 0) ? $region_id : null;
            $bind_email = empty($email) ? null : $email;
            $bind_phone = empty($phone) ? null : $phone;
            $bind_address = empty($address) ? null : $address;

            mysqli_stmt_bind_param($stmt, 'sssssssis',
                $username,
                $hashed_password,
                $full_name,
                $bind_email,
                $bind_phone,
                $bind_address,
                $user_type_selected,
                $bind_region_id,
                $currency_preference
            );

            if (mysqli_stmt_execute($stmt)) {
                flash_message('success', 'Registration successful! You can now log in.');
                redirect('index.php?page=login');
            } else {
                // Check for duplicate entry errors (username, email, phone)
                if (mysqli_errno($connection) == 1062) {
                    $errors[] = 'Username, Email, or Phone number already exists. Please use a different one.';
                } else {
                    $errors[] = 'Error during registration: ' . mysqli_stmt_error($stmt);
                }
            }
            mysqli_stmt_close($stmt);

        } catch (Exception $e) {
            $errors[] = 'An unexpected error occurred: ' . $e->getMessage();
        }
    }

    if (!empty($errors)) {
        flash_message('error', implode('<br>', $errors));
        // Fall through to display the form with errors and pre-filled data
    }
}

// Display registration form
include_template('header', ['page' => 'register']);
?>

<div class="flex items-center justify-center min-h-screen -mt-16">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-2xl">
        <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Register New Staff Account</h2>
        <form action="index.php?page=register" method="POST">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="mb-4">
                    <label for="username" class="block text-gray-700 text-sm font-semibold mb-2">Username:</label>
                    <input type="text" id="username" name="username" class="form-input" value="<?php echo htmlspecialchars($form_data['username']); ?>" required>
                </div>
                <div class="mb-4">
                    <label for="password" class="block text-gray-700 text-sm font-semibold mb-2">Password:</label>
                    <input type="password" id="password" name="password" class="form-input" required>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="mb-4">
                    <label for="confirm_password" class="block text-gray-700 text-sm font-semibold mb-2">Confirm Password:</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-input" required>
                </div>
                <div class="mb-4">
                    <label for="full_name" class="block text-gray-700 text-sm font-semibold mb-2">Full Name:</label>
                    <input type="text" id="full_name" name="full_name" class="form-input" value="<?php echo htmlspecialchars($form_data['full_name']); ?>" required>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="mb-4">
                    <label for="email" class="block text-gray-700 text-sm font-semibold mb-2">Email (Optional):</label>
                    <input type="email" id="email" name="email" class="form-input" value="<?php echo htmlspecialchars($form_data['email']); ?>">
                </div>
                <div class="mb-4">
                    <label for="phone" class="block text-gray-700 text-sm font-semibold mb-2">Phone (Optional):</label>
                    <input type="text" id="phone" name="phone" class="form-input" value="<?php echo htmlspecialchars($form_data['phone']); ?>">
                </div>
            </div>

            <div class="mb-4">
                <label for="address" class="block text-gray-700 text-sm font-semibold mb-2">Address (Optional):</label>
                <textarea id="address" name="address" rows="2" class="form-input"><?php echo htmlspecialchars($form_data['address']); ?></textarea>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="mb-4">
                    <label for="user_type_selected" class="block text-gray-700 text-sm font-semibold mb-2">User Type:</label>
                    <select id="user_type_selected" name="user_type_selected" class="form-select" required>
                        <option value="">Select Type</option>
                        <?php foreach ($allowed_registration_user_types as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>"
                                <?php echo ($form_data['user_type_selected'] === $type) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="region_id" class="block text-gray-700 text-sm font-semibold mb-2">Assigned Region (Optional, required for some types):</label>
                    <select id="region_id" name="region_id" class="form-select">
                        <option value="0">None</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo htmlspecialchars($region['id']); ?>"
                                <?php echo ($form_data['region_id'] == $region['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($region['region_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="mb-6">
                <label for="currency_preference" class="block text-gray-700 text-sm font-semibold mb-2">Currency Preference:</label>
                <select id="currency_preference" name="currency_preference" class="form-select" required>
                    <option value="">Select Currency</option>
                    <?php foreach ($currencies as $curr): ?>
                        <option value="<?php echo htmlspecialchars($curr); ?>"
                            <?php echo ($form_data['currency_preference'] === $curr) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($curr); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center justify-between">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300">
                    Register Account
                </button>
            </div>
        </form>
        <p class="text-center text-gray-600 text-sm mt-4">
            Already have an account? <a href="index.php?page=login" class="text-indigo-600 hover:text-indigo-800 font-semibold">Login here</a>
        </p>
    </div>
</div>

<?php include_template('footer'); ?>