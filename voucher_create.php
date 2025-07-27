<?php
// voucher_create.php

// This file is loaded by index.php, so config.php, db_connect.php, and functions.php are already loaded.
// Session is already started.

if (!is_logged_in()) {
    flash_message('error', 'Please log in to create a voucher.');
    redirect('index.php?page=login');
}

global $connection;

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'];

// Define currencies for the dropdown (still hardcoded here for now, can be dynamic later)
$currencies = ['MMK', 'RM', 'BAT', 'SGD', 'BHAT'];

// Fetch master data dynamically (NEW)
$payment_methods = get_master_data('payment_methods');
$delivery_types = get_master_data('delivery_types');
$item_types_options = get_master_data('item_types'); // Renamed to avoid conflict with $_POST['item_types']

// Fetch active consignments for assignment dropdown
$active_consignments = [];
$stmt_consignments = mysqli_prepare($connection, "SELECT id, consignment_code, name, status FROM consignments WHERE status IN ('Pending', 'Departed', 'In Transit', 'Arrived at Hub') ORDER BY consignment_code DESC");
if ($stmt_consignments) {
    mysqli_stmt_execute($stmt_consignments);
    $result_consignments = mysqli_stmt_get_result($stmt_consignments);
    while ($row = mysqli_fetch_assoc($result_consignments)) {
        $active_consignments[] = $row;
    }
    mysqli_free_result($result_consignments);
    mysqli_stmt_close($stmt_consignments);
} else {
    flash_message('warning', 'Could not load active consignments for assignment.');
}


// --- Handle POST request (Form Submission) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Collect and Sanitize Input ---
    $sender_name = trim($_POST['sender_name'] ?? '');
    $sender_phone = trim($_POST['sender_phone'] ?? '');
    $sender_address = trim($_POST['sender_address'] ?? '');
    $use_sender_address_for_checkout = isset($_POST['use_sender_address_for_checkout']) ? 1 : 0;

    $receiver_name = trim($_POST['receiver_name'] ?? '');
    $receiver_phone = trim($_POST['receiver_phone'] ?? '');
    $receiver_address = trim($_POST['receiver_address'] ?? ''); // Required

    $payment_method = trim($_POST['payment_method'] ?? '');
    $delivery_charge = floatval($_POST['delivery_charge'] ?? 0);
    $currency = trim($_POST['currency'] ?? 'MMK'); // Default to MMK
    $delivery_type = trim($_POST['delivery_type'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $voucher_region_id = intval($_POST['voucher_region_id'] ?? 0); // Origin Region
    $destination_region_id = intval($_POST['destination_region_id'] ?? 0); // NEW: Destination Region
    $consignment_id = intval($_POST['consignment_id'] ?? 0); // NEW: Selected Consignment ID

    // Item breakdown details (arrays from dynamic form fields)
    $item_types_submitted = $_POST['item_type'] ?? []; // Renamed to avoid conflict
    $item_kgs = $_POST['item_kg'] ?? [];
    $item_price_per_kgs = $_POST['item_price_per_kg'] ?? [];

    // --- Input Validation ---
    $errors = [];
    if (empty($sender_name)) $errors[] = 'Sender Name is required.';
    if (empty($sender_phone)) $errors[] = 'Sender Phone is required.';
    if (empty($receiver_name)) $errors[] = 'Receiver Name is required.';
    if (empty($receiver_phone)) $errors[] = 'Receiver Phone is required.';
    if (empty($receiver_address)) $errors[] = 'Receiver Address is required.';
    if (empty($voucher_region_id)) $errors[] = 'Voucher Origin Region is required.';
    if (empty($destination_region_id)) $errors[] = 'Voucher Destination Region is required.';
    if (empty($payment_method)) $errors[] = 'Payment Method is required.';
    if (empty($currency)) $errors[] = 'Currency is required.';
    if (empty($delivery_type)) $errors[] = 'Delivery Type is required.';
    if ($voucher_region_id === $destination_region_id) $errors[] = 'Origin and Destination regions cannot be the same.';

    // Validate fetched master data options
    $valid_payment_methods = array_column($payment_methods, 'name');
    if (!in_array($payment_method, $valid_payment_methods)) $errors[] = 'Invalid Payment Method selected.';
    $valid_delivery_types = array_column($delivery_types, 'name');
    if (!in_array($delivery_type, $valid_delivery_types)) $errors[] = 'Invalid Delivery Type selected.';
    $valid_currencies = $currencies; // Currencies are still hardcoded for now, but could be fetched dynamically too
    if (!in_array($currency, $valid_currencies)) $errors[] = 'Invalid Currency selected.';


    // Calculate total weight and total item price from breakdown
    $total_voucher_weight = 0;
    $total_items_calculated_price = 0;
    $has_valid_item = false;
    $valid_item_types = array_column($item_types_options, 'name'); // Use fetched item types

    foreach ($item_types_submitted as $key => $type) {
        if (!empty(trim($type))) {
            if (!in_array($type, $valid_item_types)) { // Validate submitted item type
                $errors[] = "Invalid Item Type '{$type}' detected.";
                continue; // Skip invalid item type
            }

            $has_valid_item = true;
            $item_kg_val = !empty($item_kgs[$key]) ? floatval($item_kgs[$key]) : 0;
            $item_price_per_kg_val = !empty($item_price_per_kgs[$key]) ? floatval($item_price_per_kgs[$key]) : 0;

            if ($item_kg_val <= 0 || $item_price_per_kg_val <= 0) {
                   $errors[] = "Item type '{$type}' must have positive Kg and Price per Kg.";
            }

            $total_voucher_weight += $item_kg_val;
            $total_items_calculated_price += ($item_kg_val * $item_price_per_kg_val);
        }
    }

    if (!$has_valid_item) {
        $errors[] = 'At least one item breakdown is required with an item type, kg, and price.';
    }

    if ($total_voucher_weight <= 0 && $has_valid_item) {
        $errors[] = 'Total weight from item breakdown must be greater than 0.';
    }

    if (!empty($errors)) {
        flash_message('error', implode('<br>', $errors));
        redirect('index.php?page=voucher_create');
    }

    $total_amount = $total_items_calculated_price + $delivery_charge;
    $initial_voucher_status = ($consignment_id > 0) ? 'In Transit' : 'Pending';


    mysqli_begin_transaction($connection);

    try {
        $stmt_region = mysqli_prepare($connection, "SELECT prefix, current_sequence FROM regions WHERE id = ? FOR UPDATE");
        if (!$stmt_region) {
            throw new Exception("Failed to prepare region statement: " . mysqli_error($connection));
        }
        mysqli_stmt_bind_param($stmt_region, 'i', $voucher_region_id);
        mysqli_stmt_execute($stmt_region);
        $result_region = mysqli_stmt_get_result($stmt_region);
        $region_data = mysqli_fetch_assoc($result_region);
        mysqli_free_result($result_region);
        mysqli_stmt_close($stmt_region);

        if (!$region_data) {
            throw new Exception("Selected voucher origin region not found.");
        }

        $new_sequence = $region_data['current_sequence'] + 1;
        $voucher_code = generate_voucher_code($region_data['prefix'], $new_sequence);

        $stmt_voucher = mysqli_prepare($connection,
            "INSERT INTO vouchers (voucher_code, sender_name, sender_phone, sender_address, use_sender_address_for_checkout,
                                 receiver_name, receiver_phone, receiver_address, customer_id, consignment_id, payment_method, weight_kg,
                                 price_per_kg_at_voucher, delivery_charge, total_amount, currency, delivery_type, notes,
                                 status, region_id, destination_region_id, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt_voucher) {
            throw new Exception("Failed to prepare voucher insert statement: " . mysqli_error($connection));
        }

        $dummy_price_per_kg_at_voucher_column = 0.00;
        $customer_id_null = null;
        $bind_consignment_id = ($consignment_id > 0) ? $consignment_id : null;

        mysqli_stmt_bind_param($stmt_voucher, 'ssssissssiddssdssiii',
            $voucher_code, $sender_name, $sender_phone, $sender_address, $use_sender_address_for_checkout,
            $receiver_name, $receiver_phone, $receiver_address, $customer_id_null, $bind_consignment_id,
            $payment_method, $total_voucher_weight,
            $dummy_price_per_kg_at_voucher_column,
            $delivery_charge, $total_amount, $currency, $delivery_type, $notes,
            $initial_voucher_status, $voucher_region_id, $destination_region_id, $user_id
        );

        if (!mysqli_stmt_execute($stmt_voucher)) {
            throw new Exception("Failed to insert voucher: " . mysqli_stmt_error($stmt_voucher));
        }
        $voucher_id = mysqli_insert_id($connection);
        mysqli_stmt_close($stmt_voucher);

        if (!empty($item_types_submitted)) {
            $stmt_breakdown = mysqli_prepare($connection,
                "INSERT INTO voucher_breakdowns (voucher_id, item_type, kg, price_per_kg)
                 VALUES (?, ?, ?, ?)"
            );
            if (!$stmt_breakdown) {
                throw new Exception("Failed to prepare breakdown insert statement: " . mysqli_error($connection));
            }

            foreach ($item_types_submitted as $key => $type) {
                if (!empty(trim($type))) {
                    $item_kg = !empty($item_kgs[$key]) ? floatval($item_kgs[$key]) : null;
                    $item_price_per_kg = !empty($item_price_per_kgs[$key]) ? floatval($item_price_per_kgs[$key]) : null;

                    mysqli_stmt_bind_param($stmt_breakdown, 'isdd',
                        $voucher_id, $type, $item_kg, $item_price_per_kg
                    );
                    if (!mysqli_stmt_execute($stmt_breakdown)) {
                        throw new Exception("Failed to insert voucher breakdown: " . mysqli_stmt_error($stmt_breakdown));
                    }
                }
            }
            mysqli_stmt_close($stmt_breakdown);
        }

        $stmt_update_sequence = mysqli_prepare($connection, "UPDATE regions SET current_sequence = ? WHERE id = ?");
        if (!$stmt_update_sequence) {
            throw new Exception("Failed to prepare sequence update statement: " . mysqli_error($connection));
        }
        mysqli_stmt_bind_param($stmt_update_sequence, 'ii', $new_sequence, $voucher_region_id);
        if (!mysqli_stmt_execute($stmt_update_sequence)) {
            throw new Exception("Failed to update origin region sequence: " . mysqli_stmt_error($stmt_update_sequence));
        }
        mysqli_stmt_close($stmt_update_sequence);

        log_voucher_status_change($voucher_id, null, $initial_voucher_status, 'Voucher created' . ($consignment_id > 0 ? ' and assigned to consignment ' . $consignment_id : ''), $user_id);


        mysqli_commit($connection);
        flash_message('success', "Voucher '{$voucher_code}' created successfully!");
        redirect('index.php?page=voucher_view&id=' . $voucher_id);

    } catch (Exception $e) {
        mysqli_rollback($connection);
        flash_message('error', 'Failed to create voucher: ' . $e->getMessage());
        redirect('index.php?page=voucher_create');
    }
}

// --- End of POST handling. If we reach here, it's a GET request or a POST that didn't redirect. ---

// Fetch regions for dropdowns (Origin and Destination)
$regions = [];
$stmt_regions = mysqli_query($connection, "SELECT id, region_name, prefix, price_per_kg FROM regions ORDER BY region_name");
if ($stmt_regions) {
    while ($row = mysqli_fetch_assoc($stmt_regions)) {
        $regions[] = $row;
    }
    mysqli_free_result($stmt_regions);
} else {
    flash_message('error', 'Error loading regions: ' . mysqli_error($connection));
}

// Get user's default region for origin if applicable (for Myanmar/Malay user types)
$user_origin_region_id = null;
if ($user_type === USER_TYPE_MYANMAR || $user_type === USER_TYPE_MALAY) {
    $stmt_user_region = mysqli_prepare($connection, "SELECT region_id FROM users WHERE id = ?");
    if ($stmt_user_region) {
        mysqli_stmt_bind_param($stmt_user_region, 'i', $user_id);
        mysqli_stmt_execute($stmt_user_region);
        mysqli_stmt_bind_result($stmt_user_region, $region_id_result);
        mysqli_stmt_fetch($stmt_user_region);
        $user_origin_region_id = $region_id_result;
        mysqli_stmt_close($stmt_user_region);
    } else {
        flash_message('error', 'Error fetching user region: ' . mysqli_error($connection));
    }
}

// Now include the header and render the form
include_template('header', ['page' => 'voucher_create']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Create New Voucher</h2>
    <form action="index.php?page=voucher_create" method="POST">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <!-- Sender Details -->
            <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Sender Details</h3>
                <div class="mb-4">
                    <label for="sender_name" class="block text-gray-700 text-sm font-semibold mb-2">Sender Name:</label>
                    <input type="text" id="sender_name" name="sender_name" class="form-input" required>
                </div>
                <div class="mb-4">
                    <label for="sender_phone" class="block text-gray-700 text-sm font-semibold mb-2">Sender Phone:</label>
                    <input type="text" id="sender_phone" name="sender_phone" class="form-input" required>
                </div>
                <div class="mb-4">
                    <label for="sender_address" class="block text-gray-700 text-sm font-semibold mb-2">Sender Address:</label>
                    <textarea id="sender_address" name="sender_address" rows="3" class="form-input"></textarea>
                </div>
                <div class="flex items-center mb-4">
                    <input type="checkbox" id="use_sender_address_for_checkout" name="use_sender_address_for_checkout" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                    <label for="use_sender_address_for_checkout" class="ml-2 block text-sm text-gray-900">
                        Use sender address for checkout
                    </label>
                </div>
            </div>

            <!-- Receiver Details -->
            <div class="bg-gray-50 p-6 rounded-lg shadow-inner">
                <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Receiver Details</h3>
                <div class="mb-4">
                    <label for="receiver_name" class="block text-gray-700 text-sm font-semibold mb-2">Receiver Name:</label>
                    <input type="text" id="receiver_name" name="receiver_name" class="form-input" required>
                </div>
                <div class="mb-4">
                    <label for="receiver_phone" class="block text-gray-700 text-sm font-semibold mb-2">Receiver Phone:</label>
                    <input type="text" id="receiver_phone" name="receiver_phone" class="form-input" required>
                </div>
                <div class="mb-4">
                    <label for="receiver_address" class="block text-gray-700 text-sm font-semibold mb-2">Receiver Address:</label>
                    <textarea id="receiver_address" name="receiver_address" rows="3" class="form-input" required></textarea>
                </div>
            </div>
        </div>

        <!-- Voucher Details -->
        <div class="bg-gray-50 p-6 rounded-lg shadow-inner mb-8">
            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Voucher Details</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="mb-4">
                    <label for="voucher_region_id" class="block text-gray-700 text-sm font-semibold mb-2">Voucher Origin Region:</label>
                    <select id="voucher_region_id" name="voucher_region_id" class="form-select" required onchange="updateItemPriceDefault()">
                        <option value="">Select Region</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo htmlspecialchars($region['id']); ?>" data-price="<?php echo htmlspecialchars($region['price_per_kg']); ?>"
                                <?php echo ($user_origin_region_id == $region['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($region['region_name']); ?> (<?php echo htmlspecialchars($region['prefix']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="destination_region_id" class="block text-gray-700 text-sm font-semibold mb-2">Voucher Destination Region:</label>
                    <select id="destination_region_id" name="destination_region_id" class="form-select" required>
                        <option value="">Select Destination Region</option>
                        <?php foreach ($regions as $region): ?>
                            <option value="<?php echo htmlspecialchars($region['id']); ?>">
                                <?php echo htmlspecialchars($region['region_name']); ?> (<?php echo htmlspecialchars($region['prefix']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="consignment_id" class="block text-gray-700 text-sm font-semibold mb-2">Assign to Consignment (Optional):</label>
                    <select id="consignment_id" name="consignment_id" class="form-select">
                        <option value="0">Not Assigned</option>
                        <?php foreach ($active_consignments as $cons): ?>
                            <option value="<?php echo htmlspecialchars($cons['id']); ?>">
                                <?php echo htmlspecialchars($cons['consignment_code']); ?> (<?php echo htmlspecialchars($cons['name'] ?: 'No Name'); ?>) - Status: <?php echo htmlspecialchars($cons['status']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="delivery_charge" class="block text-gray-700 text-sm font-semibold mb-2">Delivery Charge:</label>
                    <input type="number" id="delivery_charge" name="delivery_charge" class="form-input" step="0.01" value="0.00" oninput="calculateTotal()">
                </div>
                <div class="mb-4">
                    <label for="payment_method" class="block text-gray-700 text-sm font-semibold mb-2">Payment Method:</label>
                    <select id="payment_method" name="payment_method" class="form-select" required>
                        <option value="">Select Payment Method</option>
                        <?php foreach ($payment_methods as $method): ?>
                            <option value="<?php echo htmlspecialchars($method['name']); ?>"><?php echo htmlspecialchars($method['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="currency" class="block text-gray-700 text-sm font-semibold mb-2">Currency:</label>
                    <select id="currency" name="currency" class="form-select" required onchange="calculateTotal()">
                        <option value="">Select Currency</option>
                        <?php foreach ($currencies as $curr): ?>
                            <option value="<?php echo htmlspecialchars($curr); ?>" <?php echo ($curr === 'MMK') ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($curr); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-4">
                    <label for="delivery_type" class="block text-gray-700 text-sm font-semibold mb-2">Delivery Type:</label>
                    <select id="delivery_type" name="delivery_type" class="form-select" required>
                        <option value="">Select Delivery Type</option>
                           <?php foreach ($delivery_types as $d_type): ?>
                            <option value="<?php echo htmlspecialchars($d_type['name']); ?>"><?php echo htmlspecialchars($d_type['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-4">
                <label for="notes" class="block text-gray-700 text-sm font-semibold mb-2">Notes:</label>
                <textarea id="notes" name="notes" rows="3" class="form-input"></textarea>
            </div>
        </div>

        <!-- Voucher Breakdown -->
        <div class="bg-gray-50 p-6 rounded-lg shadow-inner mb-8">
            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Voucher Breakdown (Items)</h3>
            <div id="item_breakdowns_container" data-default-item-price-per-kg="0.00">
                <!-- Item breakdown rows will be added here by JavaScript -->
            </div>
            <button type="button" id="add_item_btn" class="btn btn-blue mt-4">Add Another Item</button>

            <div class="text-right mt-4">
                <p class="text-lg font-bold text-gray-800">Total for Items: <span id="total_items_price_display" class="text-indigo-600">0.00</span></p>
                <p class="text-xl font-bold text-gray-800">Grand Total (incl. Delivery): <span id="total_amount_display" class="text-green-600">0.00</span></p>
                <input type="hidden" id="total_amount_input" name="total_amount">
            </div>
        </div>

        <div class="flex justify-center">
            <button type="submit" class="btn bg-burgundy hover:bg-burgundy-dark px-8 py-3 text-lg">Generate Voucher</button>
        </div>
    </form>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // --- Voucher Create Page Logic ---
        const voucherRegionSelect = document.getElementById('voucher_region_id');
        const deliveryChargeInput = document.getElementById('delivery_charge');
        const currencySelect = document.getElementById('currency');
        const totalItemsPriceDisplay = document.getElementById('total_items_price_display');
        const totalAmountDisplay = document.getElementById('total_amount_display');
        const totalAmountInput = document.getElementById('total_amount_input');
        const itemBreakdownsContainer = document.getElementById('item_breakdowns_container');
        const addItemBtn = document.getElementById('add_item_btn');

        // Item Type options for dynamic rows (from PHP variable)
        const itemTypeOptions = <?php echo json_encode($item_types_options); ?>.map(item => ({
            value: item.name,
            text: item.name
        }));

        // Helper function to generate item type select HTML
        function generateItemTypeSelectHtml(selectedValue = '') {
            let html = `<select name="item_type[]" class="form-select item-type" required><option value="">Select Item Type</option>`;
            itemTypeOptions.forEach(option => {
                html += `<option value="${option.value}" ${selectedValue === option.value ? 'selected' : ''}>${option.text}</option>`;
            });
            html += `</select>`;
            return html;
        }

        // Only run voucher specific JS if elements exist (i.e., on voucher_create.php)
        if (voucherRegionSelect && deliveryChargeInput && totalAmountDisplay) {

            // Function to update default price per kg for new items based on selected region
            window.updateItemPriceDefault = function() {
                const selectedOption = voucherRegionSelect.options[voucherRegionSelect.selectedIndex];
                const defaultPrice = selectedOption.dataset.price;
                itemBreakdownsContainer.dataset.defaultItemPricePerKg = defaultPrice || '0.00';
                calculateTotal();
            }

            // Function to calculate and update total amount
            window.calculateTotal = function() {
                let totalItemsPrice = 0;
                document.querySelectorAll('.item-breakdown-row').forEach(row => {
                    const itemKgInput = row.querySelector('.item-kg');
                    const itemPricePerKgInput = row.querySelector('.item-price-per-kg');

                    const itemKg = parseFloat(itemKgInput.value) || 0;
                    const itemPricePerKg = parseFloat(itemPricePerKgInput.value) || 0;

                    totalItemsPrice += (itemKg * itemPricePerKg);
                });

                const deliveryCharge = parseFloat(deliveryChargeInput.value) || 0;
                const currentCurrency = currencySelect.value;

                const grandTotal = totalItemsPrice + deliveryCharge;

                totalItemsPriceDisplay.textContent = `${currentCurrency} ${grandTotal.toFixed(2)}`;
                totalAmountDisplay.textContent = `${currentCurrency} ${grandTotal.toFixed(2)}`;
                totalAmountInput.value = grandTotal.toFixed(2);
            }

            // Function to add a new item breakdown row
            function addItemBreakdownRow() {
                const defaultItemPricePerKg = itemBreakdownsContainer.dataset.defaultItemPricePerKg || '0.00';

                const newRow = document.createElement('div');
                newRow.classList.add('item-breakdown-row', 'grid', 'grid-cols-1', 'md:grid-cols-4', 'gap-4', 'items-end', 'mb-4', 'p-4', 'border', 'border-gray-200', 'rounded-md', 'bg-white', 'shadow-sm');

                newRow.innerHTML = `
                    <div class="col-span-1">
                        <label class="block text-gray-700 text-sm font-semibold mb-2">Item Type:</label>
                        ${generateItemTypeSelectHtml()}
                    </div>
                    <div class="col-span-1">
                        <label class="block text-gray-700 text-sm font-semibold mb-2">Item Kg:</label>
                        <input type="number" name="item_kg[]" class="form-input item-kg" step="0.01" min="0.01" required oninput="calculateTotal()">
                    </div>
                    <div class="col-span-1">
                        <label class="block text-gray-700 text-sm font-semibold mb-2">Price per Kg:</label>
                        <input type="number" name="item_price_per_kg[]" class="form-input item-price-per-kg" step="0.01" min="0.01" required oninput="calculateTotal()" value="${defaultItemPricePerKg}">
                    </div>
                    <div class="col-span-1 flex justify-end items-center">
                        <button type="button" class="btn-red px-3 py-1 text-xs remove-item-btn">Remove</button>
                    </div>
                `;
                itemBreakdownsContainer.appendChild(newRow);
                attachRemoveEventListeners();
                calculateTotal();
            }

            // Function to attach event listeners to remove buttons
            function attachRemoveEventListeners() {
                document.querySelectorAll('.remove-item-btn').forEach(button => {
                    button.onclick = function() {
                        const rowToRemove = this.closest('.item-breakdown-row');
                        if (itemBreakdownsContainer.children.length > 1) {
                            rowToRemove.remove();
                            calculateTotal();
                        } else {
                            showCustomAlert('At least one item breakdown is required.');
                        }
                    };
                });
            }

            // Custom alert function (replaces window.alert)
            function showCustomAlert(message) {
                const alertContainer = document.createElement('div');
                alertContainer.className = 'fixed inset-x-0 top-0 flex items-center justify-center z-50 p-4 pointer-events-none';
                alertContainer.innerHTML = `
                    <div class="bg-red-500 text-white px-6 py-3 rounded-md shadow-lg flex items-center space-x-2 transform transition-all duration-300 ease-out scale-100 opacity-100" role="alert">
                        <span>${message}</span>
                    </div>
                `;
                document.body.appendChild(alertContainer);

                // Automatically remove alert after 3 seconds
                setTimeout(() => {
                    alertContainer.classList.add('opacity-0', 'scale-95');
                    alertContainer.addEventListener('transitionend', () => alertContainer.remove());
                }, 3000);
            }

            // Initial calls
            addItemBtn.addEventListener('click', addItemBreakdownRow);
            addItemBreakdownRow(); // Add the first row on page load
            voucherRegionSelect.dispatchEvent(new Event('change')); // Trigger change to set default price on load
            calculateTotal(); // Calculate total on page load

            // --- Sender Address for Checkout Checkbox Logic ---
            const senderAddressField = document.getElementById('sender_address');
            const receiverAddressField = document.getElementById('receiver_address');
            const useSenderAddressCheckbox = document.getElementById('use_sender_address_for_checkout');

            if (senderAddressField && receiverAddressField && useSenderAddressCheckbox) {
                useSenderAddressCheckbox.addEventListener('change', function() {
                    if (this.checked) {
                        receiverAddressField.value = senderAddressField.value;
                        receiverAddressField.readOnly = true;
                        receiverAddressField.classList.add('bg-gray-100', 'cursor-not-allowed');
                    } else {
                        receiverAddressField.value = '';
                        receiverAddressField.readOnly = false;
                        receiverAddressField.classList.remove('bg-gray-100', 'cursor-not-allowed');
                    }
                });

                // Optional: If sender address changes while checkbox is checked, update receiver address
                senderAddressField.addEventListener('input', function() {
                    if (useSenderAddressCheckbox.checked) {
                        receiverAddressField.value = senderAddressField.value;
                    }
                });
            }
        }
    });
</script>

<?php include_template('footer'); ?>