<?php
// customer_voucher_details.php - Enhanced voucher details for logged-in customers.

session_start();
require_once 'config.php';
require_once 'db_connect.php';
require_once INC_PATH . 'functions.php';

global $connection;

// Check customer login
customer_login_check();

$customer_id = $_SESSION['customer_id'];
$customer_name = $_SESSION['customer_name'] ?? 'Customer';

$voucher_id = intval($_GET['id'] ?? 0);
if ($voucher_id <= 0) {
    customer_flash_message('error', 'Invalid voucher ID provided.');
    customer_redirect('index.php?page=customer_shipment_history');
}

$voucher = null;
$breakdowns = [];
$pod_details = null;
$error_message = '';

try {
    // Fetch voucher details
    $query_voucher = "SELECT v.*,
                             r_origin.region_name AS origin_region_name,
                             r_dest.region_name AS destination_region_name,
                             u.username AS created_by_staff_username, -- Staff username for internal reference (optional to show)
                             c.consignment_code, c.name AS consignment_name, c.status AS consignment_status
                      FROM vouchers v
                      JOIN regions r_origin ON v.region_id = r_origin.id
                      LEFT JOIN regions r_dest ON v.destination_region_id = r_dest.id
                      LEFT JOIN users u ON v.created_by_user_id = u.id
                      LEFT JOIN consignments c ON v.consignment_id = c.id
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
        $error_message = 'Voucher not found.';
    } else {
        // Authorization: Ensure this voucher belongs to the logged-in customer
        if (!is_customer_authorized_for_voucher($voucher_id, $customer_id)) {
            $error_message = 'You are not authorized to view this voucher.';
            $voucher = null; // Clear voucher data if not authorized
        } else {
            // Fetch breakdowns
            $stmt_breakdowns = mysqli_prepare($connection, "SELECT item_type, kg, price_per_kg FROM voucher_breakdowns WHERE voucher_id = ?");
            if ($stmt_breakdowns) {
                mysqli_stmt_bind_param($stmt_breakdowns, 'i', $voucher_id);
                mysqli_stmt_execute($stmt_breakdowns);
                $result_breakdowns = mysqli_stmt_get_result($stmt_breakdowns);
                while ($row = mysqli_fetch_assoc($result_breakdowns)) {
                    $breakdowns[] = $row;
                }
                mysqli_stmt_close($stmt_breakdowns);
            }

            // Fetch POD details
            $stmt_pod = mysqli_prepare($connection, "SELECT image_path, signature_data, delivery_notes, delivery_timestamp FROM proof_of_delivery WHERE voucher_id = ?");
            if ($stmt_pod) {
                mysqli_stmt_bind_param($stmt_pod, 'i', $voucher_id);
                mysqli_stmt_execute($stmt_pod);
                $result_pod = mysqli_stmt_get_result($stmt_pod);
                $pod_details = mysqli_fetch_assoc($result_pod);
                mysqli_stmt_close($stmt_pod);
            }
        }
    }
} catch (Exception $e) {
    error_log("Customer Voucher Details Error: " . $e->getMessage());
    $error_message = 'An error occurred while loading voucher details.';
}

include_template('customer_header', ['page' => 'customer_voucher_details']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Your Voucher Details</h2>

    <?php if ($error_message): ?>
        <div class="bg-red-100 text-red-700 border border-red-400 p-4 rounded-lg text-center">
            <p><?php echo htmlspecialchars($error_message); ?></p>
            <a href="index.php?page=customer_shipment_history" class="text-blue-600 hover:text-blue-800 mt-2 inline-block">Back to My Shipments</a>
        </div>
    <?php elseif ($voucher): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-8">
            <!-- Voucher Main Details -->
            <div class="bg-blue-50 p-6 rounded-lg shadow-inner">
                <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Voucher Information</h3>
                <p><strong class="text-gray-800">Voucher Code:</strong> <span class="font-mono text-indigo-600 text-lg"><?php echo htmlspecialchars($voucher['voucher_code']); ?></span></p>
                <p><strong class="text-gray-800">Origin Region:</strong> <?php echo htmlspecialchars($voucher['origin_region_name']); ?></p>
                <p><strong class="text-gray-800">Destination Region:</strong> <?php echo htmlspecialchars($voucher['destination_region_name']); ?></p>
                <p><strong class="text-gray-800">Total Weight:</strong> <?php echo htmlspecialchars(number_format((float)$voucher['weight_kg'], 2)); ?> kg</p>
                <p><strong class="text-gray-800">Delivery Charge:</strong> <?php echo htmlspecialchars(number_format((float)$voucher['delivery_charge'], 2)); ?></p>
                <p class="text-xl font-bold"><strong class="text-gray-800">Total Amount:</strong> <span class="text-green-600"><?php echo htmlspecialchars(number_format((float)$voucher['total_amount'], 2) . ' ' . $voucher['currency']); ?></span></p>
                <p><strong class="text-gray-800">Payment Method:</strong> <?php echo htmlspecialchars($voucher['payment_method'] ?: 'N/A'); ?></p>
                <p><strong class="text-gray-800">Delivery Type:</strong> <?php echo htmlspecialchars($voucher['delivery_type'] ?: 'N/A'); ?></p>
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

        <!-- Consignment Details -->
        <?php if (!empty($voucher['consignment_id'])): ?>
        <div class="bg-orange-50 p-6 rounded-lg shadow-inner mb-8">
            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Consignment Details</h3>
            <p><strong class="text-gray-800">Consignment Code:</strong> <?php echo htmlspecialchars($voucher['consignment_code']); ?></p>
            <p><strong class="text-gray-800">Consignment Name:</strong> <?php echo htmlspecialchars($voucher['consignment_name'] ?: 'N/A'); ?></p>
            <p><strong class="text-gray-800">Consignment Status:</strong> <?php echo htmlspecialchars($voucher['consignment_status']); ?></p>
        </div>
        <?php endif; ?>

        <!-- Proof of Delivery (POD) Section -->
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
                        <img src="<?php echo htmlspecialchars(BASE_URL . $pod_details['image_path']); ?>" alt="Proof of Delivery Image" class="max-w-xs h-auto border rounded-lg shadow-sm">
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
            <?php endif; ?>
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

        <div class="flex justify-center mt-8">
            <a href="index.php?page=customer_shipment_history" class="bg-slate-700 hover:bg-slate-950 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300">Back to My Shipments</a>
        </div>
    <?php endif; ?>
</div>

<?php include_template('customer_footer'); ?>