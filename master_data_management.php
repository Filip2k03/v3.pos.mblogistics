<?php
// master_data_management.php - Allows administrators to manage master data (payment methods, delivery types, item types).

require_once 'config.php';
require_once 'db_connect.php';
require_once INC_PATH . 'functions.php';

if (!is_logged_in()) {
    flash_message('error', 'Please log in to manage master data.');
    redirect('index.php?page=login');
}

global $connection;
$is_admin = is_admin();

// Authorization: Only Admins can access master data management
if (!$is_admin) {
    flash_message('error', 'You do not have permission to manage master data.');
    redirect('index.php?page=dashboard');
}

$errors = [];
$edit_item = null; // For holding data of item being edited
$current_section = $_GET['section'] ?? 'payment_methods'; // Default section

// Map sections to their respective tables
$master_data_sections = [
    'payment_methods' => 'payment_methods',
    'delivery_types' => 'delivery_types',
    'item_types' => 'item_types',
];

// Ensure current_section is valid
if (!array_key_exists($current_section, $master_data_sections)) {
    $current_section = 'payment_methods';
}
$current_table = $master_data_sections[$current_section];


// --- Handle Form Submissions (Add/Edit/Toggle Status) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $item_id = intval($_POST['item_id'] ?? 0);
    $item_name = trim($_POST['item_name'] ?? '');
    $redirect_to_section = $_POST['redirect_to_section'] ?? $current_section; // Keep user on same section after POST

    if ($action === 'add' || $action === 'edit') {
        if (empty($item_name)) {
            $errors[] = 'Name is required.';
        }

        if (empty($errors)) {
            try {
                if ($action === 'add') {
                    $stmt = mysqli_prepare($connection, "INSERT INTO `{$current_table}` (`name`) VALUES (?)");
                    if (!$stmt) throw new Exception("Failed to prepare add statement: " . mysqli_error($connection));
                    mysqli_stmt_bind_param($stmt, 's', $item_name);
                    if (!mysqli_stmt_execute($stmt)) {
                        if (mysqli_errno($connection) == 1062) { // Duplicate entry error code
                            throw new Exception("Name already exists in {$current_section}.");
                        }
                        throw new Exception("Failed to add item: " . mysqli_stmt_error($stmt));
                    }
                    flash_message('success', ucfirst($current_section) . ' item added successfully!');
                } elseif ($action === 'edit') {
                    if ($item_id <= 0) throw new Exception("Invalid item ID for edit.");
                    $stmt = mysqli_prepare($connection, "UPDATE `{$current_table}` SET `name` = ? WHERE `id` = ?");
                    if (!$stmt) throw new Exception("Failed to prepare edit statement: " . mysqli_error($connection));
                    mysqli_stmt_bind_param($stmt, 'si', $item_name, $item_id);
                    if (!mysqli_stmt_execute($stmt)) {
                        if (mysqli_errno($connection) == 1062) {
                            throw new Exception("Name already exists in {$current_section}.");
                        }
                        throw new Exception("Failed to update item: " . mysqli_stmt_error($stmt));
                    }
                    flash_message('success', ucfirst($current_section) . ' item updated successfully!');
                }
            } catch (Exception $e) {
                flash_message('error', $e->getMessage());
            } finally {
                if (isset($stmt)) mysqli_stmt_close($stmt);
            }
        } else {
            flash_message('error', implode('<br>', $errors));
        }
        redirect("index.php?page=master_data_management&section={$redirect_to_section}");

    } elseif ($action === 'toggle_status') {
        $new_status = intval($_POST['new_status'] ?? 0); // 0 or 1
        if ($item_id <= 0) {
            flash_message('error', 'Invalid item ID for status toggle.');
        } else {
            try {
                $stmt = mysqli_prepare($connection, "UPDATE `{$current_table}` SET `is_active` = ? WHERE `id` = ?");
                if (!$stmt) throw new Exception("Failed to prepare status toggle statement: " . mysqli_error($connection));
                mysqli_stmt_bind_param($stmt, 'ii', $new_status, $item_id);
                if (!mysqli_stmt_execute($stmt)) throw new Exception("Failed to toggle status: " . mysqli_stmt_error($stmt));
                flash_message('success', ucfirst($current_section) . ' item status updated successfully!');
            } catch (Exception $e) {
                flash_message('error', 'Error toggling status: ' . $e->getMessage());
            } finally {
                if (isset($stmt)) mysqli_stmt_close($stmt);
            }
        }
        redirect("index.php?page=master_data_management&section={$redirect_to_section}");
    }
}

// --- Handle GET request for editing an item ---
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $item_id_to_edit = intval($_GET['id']);
    $stmt = mysqli_prepare($connection, "SELECT * FROM `{$current_table}` WHERE id = ?");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $item_id_to_edit);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $edit_item = mysqli_fetch_assoc($result);
        mysqli_stmt_close($stmt);
        if (!$edit_item) {
            flash_message('error', 'Item not found for editing.');
            redirect("index.php?page=master_data_management&section={$current_section}");
        }
    } else {
        flash_message('error', 'Database error preparing edit query: ' . mysqli_error($connection));
        redirect("index.php?page=master_data_management&section={$current_section}");
    }
}

// --- Fetch all items for the current section ---
$items = [];
$query = "SELECT id, name, is_active FROM `{$current_table}` ORDER BY name ASC";
$result = mysqli_query($connection, $query);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = $row;
    }
    mysqli_free_result($result);
} else {
    flash_message('error', 'Error fetching items for ' . $current_section . ': ' . mysqli_error($connection));
}

include_template('header', ['page' => 'master_data_management']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Master Data Management</h2>

    <!-- Section Navigation Tabs -->
    <div class="mb-8 border-b border-gray-200">
        <ul class="flex flex-wrap -mb-px text-sm font-medium text-center" id="myTab" role="tablist">
            <?php foreach ($master_data_sections as $section_key => $table_name): ?>
                <li class="mr-2" role="presentation">
                    <a href="index.php?page=master_data_management&section=<?php echo htmlspecialchars($section_key); ?>"
                       class="inline-block p-4 border-b-2 rounded-t-lg
                       <?php echo ($current_section === $section_key) ? 'text-blue-600 border-blue-600 active' : 'text-gray-500 hover:text-gray-600 hover:border-gray-300'; ?>"
                       role="tab">
                        <?php echo ucfirst(str_replace('_', ' ', $section_key)); ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- Add/Edit Item Form -->
    <div class="bg-blue-100 p-6 rounded-lg shadow-inner mb-8">
        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">
            <?php echo $edit_item ? 'Edit ' . ucfirst(str_replace('_', ' ', $current_section)) . ' Item' : 'Add New ' . ucfirst(str_replace('_', ' ', $current_section)) . ' Item'; ?>
        </h3>
        <form action="index.php?page=master_data_management" method="POST" class="show-loader-on-submit">
            <input type="hidden" name="action" value="<?php echo $edit_item ? 'edit' : 'add'; ?>">
            <input type="hidden" name="redirect_to_section" value="<?php echo htmlspecialchars($current_section); ?>">
            <?php if ($edit_item): ?>
                <input type="hidden" name="item_id" value="<?php echo htmlspecialchars($edit_item['id']); ?>">
            <?php endif; ?>

            <div class="mb-4">
                <label for="item_name" class="block text-gray-700 text-sm font-semibold mb-2">Name:</label>
                <input type="text" id="item_name" name="item_name" class="form-input"
                       value="<?php echo htmlspecialchars($edit_item['name'] ?? ''); ?>" required>
            </div>

            <div class="flex justify-end gap-2">
                <button type="submit" class="btn btn-green py-2 px-4 rounded-lg shadow-md">
                    <?php echo $edit_item ? 'Update Item' : 'Add Item'; ?>
                </button>
                <?php if ($edit_item): ?>
                    <a href="index.php?page=master_data_management&section=<?php echo htmlspecialchars($current_section); ?>" class="btn btn-slate py-2 px-4 rounded-lg shadow-md">Cancel Edit</a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Item List -->
    <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">All <?php echo ucfirst(str_replace('_', ' ', $current_section)); ?> Items</h3>
    <?php if (empty($items)): ?>
        <p class="text-center text-gray-600">No items found for <?php echo str_replace('_', ' ', $current_section); ?>.</p>
    <?php else: ?>
        <div class="overflow-x-auto rounded-lg shadow-md border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($items as $item): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['name']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    <?php echo $item['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo $item['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                <a href="index.php?page=master_data_management&section=<?php echo htmlspecialchars($current_section); ?>&action=edit&id=<?php echo htmlspecialchars($item['id']); ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                <form action="index.php?page=master_data_management" method="POST" class="inline-block show-loader-on-submit" onsubmit="return confirm('Are you sure you want to toggle the status of \'<?php echo htmlspecialchars($item['name']); ?>\'?');">
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="redirect_to_section" value="<?php echo htmlspecialchars($current_section); ?>">
                                    <input type="hidden" name="item_id" value="<?php echo htmlspecialchars($item['id']); ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $item['is_active'] ? '0' : '1'; ?>">
                                    <button type="submit" class="text-blue-600 hover:text-blue-900">
                                        <?php echo $item['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </button>
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