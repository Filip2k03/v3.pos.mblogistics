<?php
// customer_login.php - Dedicated login page for customers.


require_once 'config.php';
require_once 'db_connect.php';
require_once INC_PATH . 'functions.php';

global $connection;

// If already logged in as a customer, redirect to customer dashboard
if (is_customer_logged_in()) {
    customer_flash_message('info', 'You are already logged in to the customer portal.');
    customer_redirect('index.php?page=customer_dashboard');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone_number = trim($_POST['phone_number'] ?? ''); // Customers log in with phone number
    $password = $_POST['password'] ?? '';

    if (empty($phone_number) || empty($password)) {
        customer_flash_message('error', 'Please enter both phone number and password.');
        customer_redirect('index.php?page=customer_login');
    }

    $stmt = mysqli_prepare($connection, "SELECT id, customer_name, password, is_active FROM customers WHERE phone_number = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $phone_number);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $customer = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if ($customer && password_verify($password, $customer['password'])) {
            if ($customer['is_active'] == 1) {
                $_SESSION['customer_id'] = $customer['id'];
                $_SESSION['customer_name'] = $customer['customer_name'];
                // Update last login timestamp
                $stmt_update_login = mysqli_prepare($connection, "UPDATE customers SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                mysqli_stmt_bind_param($stmt_update_login, 'i', $customer['id']);
                mysqli_stmt_execute($stmt_update_login);
                mysqli_stmt_close($stmt_update_login);

                customer_flash_message('success', 'Welcome, ' . htmlspecialchars($customer['customer_name']) . '!');
                customer_redirect('index.php?page=customer_dashboard');
            } else {
                customer_flash_message('error', 'Your account is inactive. Please contact support.');
                customer_redirect('index.php?page=customer_login');
            }
        } else {
            customer_flash_message('error', 'Invalid phone number or password.');
            customer_redirect('index.php?page=customer_login');
        }
    } else {
        customer_flash_message('error', 'Database error: ' . mysqli_error($connection));
        customer_redirect('index.php?page=customer_login');
    }
}

// Display login form using customer_header/footer
include_template('customer_header', ['page' => 'customer_login']);
?>

<div class="flex items-center justify-center min-h-[calc(100vh-120px)]"> <!-- Adjust min-height based on header/footer -->
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-md">
        <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Customer Login</h2>
        <form action="index.php?page=customer_login" method="POST">
            <div class="mb-4">
                <label for="phone_number" class="block text-gray-700 text-sm font-semibold mb-2">Phone Number:</label>
                <input type="text" id="phone_number" name="phone_number" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required autofocus>
            </div>
            <div class="mb-6">
                <label for="password" class="block text-gray-700 text-sm font-semibold mb-2">Password:</label>
                <input type="password" id="password" name="password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" required>
            </div>
            <div class="flex items-center justify-between">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300">
                    Login
                </button>
            </div>
        </form>
        <p class="text-center text-gray-600 text-sm mt-4">
            Need an account? Please contact staff to register.
        </p>
    </div>
</div>

<?php include_template('customer_footer'); ?>