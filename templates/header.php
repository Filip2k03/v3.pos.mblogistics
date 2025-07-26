<?php
// templates/header.php
// Common header for all pages, including Tailwind CSS CDN

// Ensure functions are loaded for is_logged_in, is_admin etc.
// This file is included via include_template, so config.php should already be loaded.

// Get current user details for dynamic menu visibility
$current_user_is_admin = is_admin();
$is_logged_in_user = is_logged_in();
$logged_in_user_type = $_SESSION['user_type'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - <?php echo ucfirst(str_replace('_', ' ', $page ?? 'Dashboard')); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Custom styles for consistency */
        .form-input, .form-select, textarea {
            display: block;
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #d2d6dc;
            border-radius: 0.375rem;
            background-color: #fff;
            color: #4a5568;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        .form-input:focus, .form-select:focus, textarea:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.45);
            outline: none;
        }
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.375rem;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.15s ease-in-out, color 0.15s ease-in-out;
        }
        /* Specific button colors */
        .btn-blue { background-color: #4299e1; color: white; }
        .btn-blue:hover { background-color: #3182ce; }
        .btn-red { background-color: #ef4444; color: white; }
        .btn-red:hover { background-color: #dc2626; }
        .btn-green { background-color: #48bb78; color: white; }
        .btn-green:hover { background-color: #38a169; }
        .btn-purple { background-color: #805ad5; color: white; }
        .btn-purple:hover { background-color: #6b46c1; }
        .btn-indigo { background-color: #667eea; color: white; }
        .btn-indigo:hover { background-color: #5a67d8; }
        .btn-slate { background-color: #64748b; color: white; }
        .btn-slate:hover { background-color: #475569; }

        /* Backgrounds for cards/sections */
        .bg-sky-50 { background-color: #f0f9ff; }
        .bg-blue-100 { background-color: #dbeafe; }
        .bg-yellow-50 { background-color: #fffbeb; }
        .bg-green-50 { background-color: #ecfdf5; }
        .bg-purple-50 { background-color: #faf5ff; }
        .bg-red-50 { background-color: #fef2f2; }
        .bg-lime-500 { background-color: #84cc16; }
        .bg-gray-400 { background-color: #9ca3af; }

        /* Flash message specific styles */
        .flash-message {
            padding: 0.75rem 1.25rem;
            margin-bottom: 1rem;
            border: 1px solid transparent;
            border-radius: 0.25rem;
            opacity: 1;
            transition: opacity 0.5s ease-out;
            position: fixed;
            top: 1rem;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            width: 90%;
            max-width: 500px;
            text-align: center;
        }
        .flash-message.hidden {
            opacity: 0;
            display: none;
        }

        /* --- Loader Styles --- */
        #loader-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(255, 255, 255, 0.8); /* Semi-transparent white */
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999; /* Ensures it's on top of everything */
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease-in-out, visibility 0.3s ease-in-out;
        }
        #loader-overlay.show {
            opacity: 1;
            visibility: visible;
        }
        .spinner {
            border: 6px solid #f3f3f3; /* Light grey */
            border-top: 6px solid #3498db; /* Blue */
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="min-h-screen flex flex-col bg-gray-100">

    <div id="loader-overlay">
        <div class="spinner"></div>
    </div>

    <header class="bg-gray-800 text-white p-4 shadow-md">
        <div class="container mx-auto flex justify-between items-center">
            <a href="index.php?page=dashboard" class="text-2xl font-bold"><?php echo APP_NAME; ?></a>
            <nav class="space-x-2">
                <?php if ($is_logged_in_user): ?>
                    <a href="index.php?page=dashboard" class="px-3 py-2 hover:bg-gray-700 rounded">Dashboard</a>
                    <a href="index.php?page=voucher_create" class="px-3 py-2 hover:bg-gray-700 rounded">New Voucher</a>
                    <a href="index.php?page=voucher_list" class="px-3 py-2 hover:bg-gray-700 rounded">Vouchers</a>
                    <a href="index.php?page=expenses" class="px-3 py-2 hover:bg-gray-700 rounded">Expenses</a>

                    <?php if ($current_user_is_admin): ?>
                        <a href="index.php?page=customer_management" class="px-3 py-2 hover:bg-gray-700 rounded">Customers</a>
                        <a href="index.php?page=staff_management" class="px-3 py-2 hover:bg-gray-700 rounded">Staff</a>
                        <a href="index.php?page=status_bulk_update" class="px-3 py-2 hover:bg-gray-700 rounded">Bulk Status</a>
                        <a href="index.php?page=profit_loss_report" class="px-3 py-2 hover:bg-gray-700 rounded">P&L Report</a> <?php endif; ?>
                    <!-- <a href="index.php?page=consignment_management" class="px-3 py-2 hover:bg-gray-700 rounded">Consignments</a> -->
                    <a href="index.php?page=consignment_view" class="px-3 py-2 hover:bg-gray-700 rounded">Consignment View</a>

                    <a href="logout.php" class="px-3 py-2 bg-red-600 hover:bg-red-700 rounded">Logout</a>
                <?php else: ?>
                    <a href="index.php?page=login" class="px-3 py-2 hover:bg-gray-700 rounded">Login</a>
                    <a href="index.php?page=register" class="px-3 py-2 hover:bg-gray-700 rounded">Register</a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="flex-grow container mx-auto p-4">
        <?php
        // Display flash messages from session
        if (isset($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            $class = '';
            switch ($flash['type']) {
                case 'success': $class = 'bg-green-100 text-green-700 border-green-400'; break;
                case 'error': $class = 'bg-red-100 text-red-700 border-red-400'; break;
                case 'info': $class = 'bg-blue-100 text-blue-700 border-blue-400'; break;
                case 'warning': $class = 'bg-yellow-100 text-yellow-700 border-yellow-400'; break;
            }
            echo "<div class='flash-message p-4 mb-4 text-sm rounded-lg border {$class}' role='alert'>{$flash['message']}</div>";
            unset($_SESSION['flash']);
            ?>
            <script>
                // JavaScript to auto-hide flash messages
                setTimeout(() => {
                    const flash = document.querySelector('.flash-message');
                    if (flash) {
                        flash.classList.add('hidden');
                    }
                }, 5000); // Hide after 5 seconds
            </script>
            <?php
        }
        ?>
        <script>
            function showLoader() {
                document.getElementById('loader-overlay').classList.add('show');
            }

            function hideLoader() {
                document.getElementById('loader-overlay').classList.remove('show');
            }

            // Show loader on form submissions
            document.addEventListener('DOMContentLoaded', function() {
                const forms = document.querySelectorAll('form');
                forms.forEach(form => {
                    form.addEventListener('submit', function() {
                        // Only show loader for POST forms (or explicitly marked GET forms)
                        // For P&L report, it's a GET form that reloads, so we want the loader.
                        if (this.method.toLowerCase() === 'post' || this.classList.contains('show-loader-on-submit')) {
                             showLoader();
                        }
                    });
                });

                // Ensure loader is hidden if page loads normally (e.g., direct navigation, back/forward)
                hideLoader();
            });

            // Fallback for pageshow (for browser back/forward cache)
            window.addEventListener('pageshow', function(event) {
                if (event.persisted) { // Checks if the page is loaded from cache
                    hideLoader();
                }
            });
        </script>