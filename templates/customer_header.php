<?php
// templates/customer_header.php
// Header for the Customer Portal

// Ensure functions are loaded for is_customer_logged_in etc.
// This file is included via include_template, so config.php should already be loaded.

// Get current customer details for dynamic menu visibility
$is_customer_logged_in = is_customer_logged_in();
$customer_name = $_SESSION['customer_name'] ?? 'Guest'; // Assuming customer_name is stored in session
$customer_id = $_SESSION['customer_id'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Customer Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* General styles for customer portal (can be shared or specific) */
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6; /* Light gray background */
            color: #374151; /* Darker text */
        }
        .container-wrapper {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        header.customer-portal-header {
            background-color: #1a202c; /* Darker header */
            color: #ffffff;
            padding: 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        header.customer-portal-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
        }
        main.customer-portal-content {
            flex-grow: 1;
            padding: 1.5rem;
        }
        footer.customer-portal-footer {
            background-color: #1a202c;
            color: #d1d5db;
            padding: 1rem;
            text-align: center;
            font-size: 0.875rem;
        }

        /* Flash message specific styles (similar to main app but for customer_flash) */
        .customer-flash-message {
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
        .customer-flash-message.hidden {
            opacity: 0;
            display: none;
        }

        /* Status badge colors (copied from your main app for consistency) */
        .status-badge-pending { background-color: #fef3c7; color: #b45309; }
        .status-badge-in-transit { background-color: #dbeafe; color: #1e40af; }
        .status-badge-delivered { background-color: #d1fae5; color: #065f46; }
        .status-badge-received { background-color: #ccfbf1; color: #0d9488; }
        .status-badge-cancelled { background-color: #fee2e2; color: #b91c1c; }
        .status-badge-returned { background-color: #ede9fe; color: #6d28d9; }
        .status-badge-default { background-color: #e5e7eb; color: #4b5563; }

        /* Responsive Table styles (copied from main app) */
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }
        .info-table th, .info-table td {
            border: 1px solid #e5e7eb;
            padding: 0.75rem;
            text-align: left;
            vertical-align: top;
        }
        .info-table th {
            background-color: #f9fafb;
            font-weight: 600;
            color: #4b5563;
        }
        .info-table tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }
        .info-table tbody tr:nth-child(even) {
            background-color: #f9fafb;
        }
    </style>
</head>
<body>
    <div class="container-wrapper">
        <header class="customer-portal-header">
            <div class="container mx-auto flex justify-between items-center">
                <a href="index.php?page=customer_dashboard" class="text-2xl font-bold"><?php echo APP_NAME; ?> Customer Portal</a>
                <nav class="space-x-4">
                    <?php if ($is_customer_logged_in): ?>
                        <span class="text-gray-300">Welcome, <?php echo htmlspecialchars($customer_name); ?>!</span>
                        <a href="index.php?page=customer_dashboard" class="px-3 py-2 hover:bg-gray-700 rounded">Dashboard</a>
                        <a href="index.php?page=customer_shipment_history" class="px-3 py-2 hover:bg-gray-700 rounded">My Shipments</a>
                        <a href="index.php?page=customer_logout" class="px-3 py-2 bg-red-600 hover:bg-red-700 rounded">Logout</a>
                    <?php else: ?>
                        <a href="index.php?page=customer_login" class="px-3 py-2 hover:bg-gray-700 rounded">Customer Login</a>
                        <a href="index.php?page=login" class="px-3 py-2 hover:bg-gray-700 rounded">Staff Login</a>
                    <?php endif; ?>
                </nav>
            </div>
        </header>

        <main class="customer-portal-content">
            <?php
            // Display customer flash messages
            if (isset($_SESSION['customer_flash'])) {
                $flash = $_SESSION['customer_flash'];
                $class = '';
                switch ($flash['type']) {
                    case 'success': $class = 'bg-green-100 text-green-700 border-green-400'; break;
                    case 'error': $class = 'bg-red-100 text-red-700 border-red-400'; break;
                    case 'info': $class = 'bg-blue-100 text-blue-700 border-blue-400'; break;
                    case 'warning': $class = 'bg-yellow-100 text-yellow-700 border-yellow-400'; break;
                }
                echo "<div class='customer-flash-message p-4 mb-4 text-sm rounded-lg border {$class}' role='alert'>{$flash['message']}</div>";
                unset($_SESSION['customer_flash']);
                ?>
                <script>
                    setTimeout(() => {
                        const flash = document.querySelector('.customer-flash-message');
                        if (flash) {
                            flash.classList.add('hidden');
                        }
                    }, 5000);
                </script>
                <?php
            }
            ?>
            <!-- Page content will be loaded here by index.php -->