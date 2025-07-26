<?php
// login.php

// session_start();
require_once 'config.php';
require_once 'db_connect.php';
require_once INC_PATH . 'functions.php';

global $connection;

// If already logged in, redirect to dashboard
if (is_logged_in()) {
    redirect('index.php?page=dashboard');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        flash_message('error', 'Please enter both username and password.');
        redirect('index.php?page=login');
    }

    $stmt = mysqli_prepare($connection, "SELECT id, username, password, user_type, is_active FROM users WHERE username = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);

        if ($user && password_verify($password, $user['password'])) {
            if ($user['is_active'] == 1) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['user_type'] = $user['user_type']; // Store user type in session
                flash_message('success', 'Welcome, ' . htmlspecialchars($user['full_name'] ?? $user['username']) . '!');
                redirect('index.php?page=dashboard');
            } else {
                flash_message('error', 'Your account is inactive. Please contact support.');
                redirect('index.php?page=login');
            }
        } else {
            flash_message('error', 'Invalid username or password.');
            redirect('index.php?page=login');
        }
    } else {
        flash_message('error', 'Database error: ' . mysqli_error($connection));
        redirect('index.php?page=login');
    }
}

// Display login form
include_template('header', ['page' => 'login']);
?>

<div class="flex items-center justify-center min-h-screen -mt-16">
    <div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-md">
        <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Login to <?php echo APP_NAME; ?></h2>
        <form action="index.php?page=login" method="POST">
            <div class="mb-4">
                <label for="username" class="block text-gray-700 text-sm font-semibold mb-2">Username:</label>
                <input type="text" id="username" name="username" class="form-input" required autofocus>
            </div>
            <div class="mb-6">
                <label for="password" class="block text-gray-700 text-sm font-semibold mb-2">Password:</label>
                <input type="password" id="password" name="password" class="form-input" required>
            </div>
            <div class="flex items-center justify-between">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300">
                    Login
                </button>
            </div>
        </form>
    </div>
</div>

<?php include_template('footer'); ?>