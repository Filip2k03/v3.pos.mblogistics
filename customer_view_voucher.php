<?php
// customer_view_voucher.php - Publicly displays basic voucher details via QR code scan.
// This page is designed to be standalone and does NOT require login.
 // Start session for flash messages (though unlikely needed here, good practice)
require_once 'config.php'; // For APP_NAME, DB connection details
require_once 'db_connect.php'; // Establishes $connection
require_once INC_PATH . 'functions.php'; // For generate_qr_code_url (if used for display), flash_message (if errors happen before HTML)

global $connection;

// Get voucher code from URL
$voucher_code = trim($_GET['code'] ?? '');

$voucher = null;
$breakdowns = [];
$error_message = '';

if (empty($voucher_code)) {
    $error_message = 'No voucher code provided. Please scan a valid QR code.';
} else {
    try {
        // Fetch voucher details based on voucher_code
        // Only select publicly relevant information. AVOID internal IDs, created_by_user_id, notes, etc.
        $query_voucher = "SELECT v.id, v.voucher_code, v.sender_name, v.sender_phone, v.sender_address,
                                 v.receiver_name, v.receiver_phone, v.receiver_address,
                                 v.weight_kg, v.delivery_charge, v.total_amount, v.currency, v.delivery_type,
                                 v.status, v.created_at,
                                 r_origin.region_name AS origin_region_name,
                                 r_dest.region_name AS destination_region_name
                          FROM vouchers v
                          JOIN regions r_origin ON v.region_id = r_origin.id
                          LEFT JOIN regions r_dest ON v.destination_region_id = r_dest.id
                          WHERE v.voucher_code = ?";

        $stmt_voucher = mysqli_prepare($connection, $query_voucher);
        if (!$stmt_voucher) {
            throw new Exception("Failed to prepare voucher fetch statement: " . mysqli_error($connection));
        }

        mysqli_stmt_bind_param($stmt_voucher, 's', $voucher_code);
        mysqli_stmt_execute($stmt_voucher);
        $result_voucher = mysqli_stmt_get_result($stmt_voucher);
        $voucher = mysqli_fetch_assoc($result_voucher);
        mysqli_stmt_close($stmt_voucher);

        if (!$voucher) {
            $error_message = 'Voucher not found or invalid code.';
        } else {
            // Fetch breakdowns for public display
            $stmt_breakdowns = mysqli_prepare($connection, "SELECT item_type, kg, price_per_kg FROM voucher_breakdowns WHERE voucher_id = ?");
            if ($stmt_breakdowns) {
                mysqli_stmt_bind_param($stmt_breakdowns, 'i', $voucher['id']);
                mysqli_stmt_execute($stmt_breakdowns);
                $result_breakdowns = mysqli_stmt_get_result($stmt_breakdowns);
                while ($row = mysqli_fetch_assoc($result_breakdowns)) {
                    $breakdowns[] = $row;
                }
                mysqli_stmt_close($stmt_breakdowns);
            } else {
                $error_message = 'Error fetching voucher breakdowns: ' . mysqli_error($connection);
            }
        }
    } catch (Exception $e) {
        error_log("Public Voucher View Error: " . $e->getMessage());
        $error_message = 'An error occurred while loading the voucher details. Please try again later.';
    }
}

// --- Start HTML Output (Self-contained) ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?> - Voucher Details</title>
    <!-- Minimal Tailwind CSS CDN for basic styling -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <style>
        /* Custom styles for this public page */
        body {
            font-family: 'Inter', sans-serif; /* Assuming Inter is desired, or remove */
            background-color: #f3f4f6; /* Light gray background */
            color: #374151; /* Darker text */
        }
        .container-wrapper {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        header.public-header {
            background-color: #1f2937; /* Dark gray */
            color: #ffffff;
            padding: 1rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        header.public-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
        }
        main.public-content {
            flex-grow: 1;
            padding: 1.5rem;
        }
        footer.public-footer {
            background-color: #1f2937; /* Dark gray */
            color: #d1d5db; /* Light gray text */
            padding: 1rem;
            text-align: center;
            font-size: 0.875rem;
        }
        /* Specific status badge colors (copied from your main app for consistency) */
        .status-badge-pending { background-color: #fef3c7; color: #b45309; } /* yellow-100, yellow-800 */
        .status-badge-in-transit { background-color: #dbeafe; color: #1e40af; } /* blue-100, blue-800 */
        .status-badge-delivered { background-color: #d1fae5; color: #065f46; } /* green-100, green-800 */
        .status-badge-received { background-color: #ccfbf1; color: #0d9488; } /* teal-100, teal-800 */
        .status-badge-cancelled { background-color: #fee2e2; color: #b91c1c; } /* red-100, red-800 */
        .status-badge-returned { background-color: #ede9fe; color: #6d28d9; } /* purple-100, purple-800 */
        .status-badge-default { background-color: #e5e7eb; color: #4b5563; } /* gray-100, gray-800 */

        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
        }
        .info-table th, .info-table td {
            border: 1px solid #e5e7eb; /* gray-200 */
            padding: 0.75rem;
            text-align: left;
            vertical-align: top;
        }
        .info-table th {
            background-color: #f9fafb; /* gray-50 */
            font-weight: 600;
            color: #4b5563; /* gray-700 */
        }
        .info-table tbody tr:nth-child(odd) {
            background-color: #ffffff;
        }
        .info-table tbody tr:nth-child(even) {
            background-color: #f9fafb; /* gray-50 */
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="container-wrapper">
        <header class="public-header">
            <h1><?php echo APP_NAME; ?></h1>
        </header>

        <main class="public-content">
            <div class="bg-white p-8 rounded-lg shadow-xl max-w-4xl mx-auto">
                <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Voucher Details</h2>

                <?php if ($error_message): ?>
                    <div class="bg-red-100 text-red-700 border border-red-400 p-4 rounded-lg text-center">
                        <p><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                <?php elseif ($voucher): ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                        <!-- Voucher Main Details -->
                        <div class="bg-blue-50 p-6 rounded-lg shadow-inner">
                            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Voucher Information</h3>
                            <p><strong class="text-gray-800">Voucher Code:</strong> <span class="font-mono text-indigo-600 text-lg"><?php echo htmlspecialchars($voucher['voucher_code']); ?></span></p>
                            <p><strong class="text-gray-800">Origin Region:</strong> <?php echo htmlspecialchars($voucher['origin_region_name']); ?></p>
                            <p><strong class="text-gray-800">Destination Region:</strong> <?php echo htmlspecialchars($voucher['destination_region_name']); ?></p>
                            <p><strong class="text-gray-800">Total Weight:</strong> <?php echo htmlspecialchars(number_format((float)$voucher['weight_kg'], 2)); ?> kg</p>
                            <p><strong class="text-gray-800">Total Amount:</strong> <span class="text-green-600"><?php echo htmlspecialchars(number_format((float)$voucher['total_amount'], 2) . ' ' . $voucher['currency']); ?></span></p>
                            <p><strong class="text-gray-800">Status:</strong>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    <?php
                                        switch ($voucher['status']) {
                                            case 'Pending': echo 'status-badge-pending'; break;
                                            case 'In Transit': echo 'status-badge-in-transit'; break;
                                            case 'Delivered': echo 'status-badge-delivered'; break;
                                            case 'Received': echo 'status-badge-received'; break;
                                            case 'Cancelled': echo 'status-badge-cancelled'; break;
                                            case 'Returned': echo 'status-badge-returned'; break;
                                            default: echo 'status-badge-default'; break;
                                        }
                                    ?>">
                                    <?php echo htmlspecialchars($voucher['status']); ?>
                                </span>
                            </p>
                            <p><strong class="text-gray-800">Created At:</strong> <?php echo date('Y-m-d H:i:s', strtotime($voucher['created_at'])); ?></p>
                        </div>

                        <!-- Sender & Receiver Details -->
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

                    <!-- Item Breakdown -->
                    <div class="bg-red-50 p-6 rounded-lg shadow-inner mb-8">
                        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Item Breakdown</h3>
                        <?php if (empty($breakdowns)): ?>
                            <p class="text-gray-600">No item breakdowns for this voucher.</p>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="info-table">
                                    <thead>
                                        <tr>
                                            <th>Item Type</th>
                                            <th>Weight (KG)</th>
                                            <th>Price per KG</th>
                                            <th>Subtotal</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $total_breakdown_subtotal = 0;
                                        foreach ($breakdowns as $item):
                                            $item_kg_val = is_numeric($item['kg']) ? $item['kg'] : 0;
                                            $item_price_per_kg_val = is_numeric($item['price_per_kg']) ? $item['price_per_kg'] : 0;
                                            $subtotal = $item_kg_val * $item_price_per_kg_val;
                                            $total_breakdown_subtotal += $subtotal;
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
                                    <tfoot>
                                        <tr>
                                            <th colspan="3" class="text-right">Total for Items:</th>
                                            <th><?php echo htmlspecialchars($currency) . ' ' . number_format($total_breakdown_subtotal, 2); ?></th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <footer class="public-footer">
            <p>&copy; <?php echo date('Y'); ?> <?php echo APP_NAME; ?>. All rights reserved.</p>
            <p>Developed by Payvia POS System</p>
        </footer>
    </div>
</body>
</html>