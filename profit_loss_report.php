<?php
// profit_loss_report.php - Generates a Profit and Loss report.

// This file is loaded by index.php, so config.php, db_connect.php, and functions.php are already loaded.
// Session is already started.

if (!is_logged_in()) {
    flash_message('error', 'Please log in to view reports.');
    redirect('index.php?page=login');
}

// Authorization: Only Admins can access P&L report
if (!is_admin()) {
    flash_message('error', 'You do not have permission to view the Profit & Loss report.');
    redirect('index.php?page=dashboard');
}

global $connection;

// --- Define Currencies and Sample Exchange Rates ---
$base_currency = 'MMK'; // Define your base currency for consolidated reports
$currencies = ['MMK', 'RM', 'BAT', 'SGD']; // Must match what's in config/expenses
// IMPORTANT: These exchange rates are HARDCODED FOR DEMONSTRATION.
// In a real system, you NEED a database table for exchange rates
// and a mechanism to update them regularly.
$exchange_rates_to_base = [
    'MMK' => 1.0,
    'RM'  => 500.0,  // Example: 1 RM = 500 MMK
    'BAT' => 80.0,   // Example: 1 BAT = 80 MMK (assuming BAT is BHAT/Thai Baht)
    'SGD' => 2500.0  // Example: 1 SGD = 2500 MMK
];

// --- Get Filter Parameters ---
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to start of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d');     // Default to current date

$report_data = [
    'total_revenue_by_currency' => array_fill_keys($currencies, 0.00),
    'total_expenses_by_currency' => array_fill_keys($currencies, 0.00),
    'gross_profit_loss_base_currency' => 0.00
];
$report_errors = [];

try {
    // --- Fetch Revenue (from Vouchers) ---
    $query_revenue = "SELECT currency, SUM(total_amount) AS total_revenue
                      FROM vouchers
                      WHERE DATE(created_at) >= ? AND DATE(created_at) <= ?
                      GROUP BY currency";
    $stmt_revenue = mysqli_prepare($connection, $query_revenue);
    if ($stmt_revenue) {
        mysqli_stmt_bind_param($stmt_revenue, 'ss', $start_date, $end_date);
        mysqli_stmt_execute($stmt_revenue);
        $result_revenue = mysqli_stmt_get_result($stmt_revenue);
        while ($row = mysqli_fetch_assoc($result_revenue)) {
            if (in_array($row['currency'], $currencies)) { // Only include defined currencies
                $report_data['total_revenue_by_currency'][$row['currency']] = (float)$row['total_revenue'];
            }
        }
        mysqli_free_result($result_revenue);
        mysqli_stmt_close($stmt_revenue);
    } else {
        $report_errors[] = 'Error fetching revenue data: ' . mysqli_error($connection);
    }

    // --- Fetch Expenses ---
    $query_expenses = "SELECT currency, SUM(amount) AS total_expenses
                       FROM expenses
                       WHERE DATE(expense_date) >= ? AND DATE(expense_date) <= ?
                       GROUP BY currency";
    $stmt_expenses = mysqli_prepare($connection, $query_expenses);
    if ($stmt_expenses) {
        mysqli_stmt_bind_param($stmt_expenses, 'ss', $start_date, $end_date);
        mysqli_stmt_execute($stmt_expenses);
        $result_expenses = mysqli_stmt_get_result($stmt_expenses);
        while ($row = mysqli_fetch_assoc($result_expenses)) {
            if (in_array($row['currency'], $currencies)) { // Only include defined currencies
                $report_data['total_expenses_by_currency'][$row['currency']] = (float)$row['total_expenses'];
            }
        }
        mysqli_free_result($result_expenses);
        mysqli_stmt_close($stmt_expenses);
    } else {
        $report_errors[] = 'Error fetching expenses data: ' . mysqli_error($connection);
    }

    // --- Calculate Gross Profit/Loss in Base Currency ---
    $total_revenue_in_base = 0.00;
    foreach ($report_data['total_revenue_by_currency'] as $currency => $amount) {
        $rate = $exchange_rates_to_base[$currency] ?? 0; // Get rate, default to 0 if not found
        $total_revenue_in_base += $amount * $rate;
    }

    $total_expenses_in_base = 0.00;
    foreach ($report_data['total_expenses_by_currency'] as $currency => $amount) {
        $rate = $exchange_rates_to_base[$currency] ?? 0;
        $total_expenses_in_base += $amount * $rate;
    }

    $report_data['gross_profit_loss_base_currency'] = $total_revenue_in_base - $total_expenses_in_base;

} catch (Exception $e) {
    error_log("P&L Report Error: " . $e->getMessage());
    $report_errors[] = 'An unexpected error occurred while generating the report.';
}

if (!empty($report_errors)) {
    flash_message('error', implode('<br>', $report_errors));
}

include_template('header', ['page' => 'profit_loss_report']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">Profit & Loss Report</h2>

    <div class="bg-gray-100 p-6 rounded-lg shadow-inner mb-8">
        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Filter Report by Date</h3>
        <form action="index.php" method="GET" class="flex flex-wrap items-end gap-4 show-loader-on-submit"> <input type="hidden" name="page" value="profit_loss_report">
            <div>
                <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date:</label>
                <input type="date" id="start_date" name="start_date" class="form-input" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div>
                <label for="end_date" class="block text-sm font-medium text-gray-700">End Date:</label>
                <input type="date" id="end_date" name="end_date" class="form-input" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div>
                <button type="submit" class="btn btn-blue px-4 py-2 rounded-md">Generate Report</button>
            </div>
        </form>
    </div>

    <div class="mb-8">
        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Revenue Summary (Vouchers)</h3>
        <div class="overflow-x-auto rounded-lg shadow-md border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-green-100">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Currency</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-700 uppercase tracking-wider">Total Revenue</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-700 uppercase tracking-wider">In <?php echo $base_currency; ?> (Est.)</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($report_data['total_revenue_by_currency'] as $currency => $amount): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($currency); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-700"><?php echo number_format($amount, 2); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-700">
                                <?php
                                $rate = $exchange_rates_to_base[$currency] ?? 0;
                                echo number_format($amount * $rate, 2) . ' ' . $base_currency;
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="mb-8">
        <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Expense Summary</h3>
        <div class="overflow-x-auto rounded-lg shadow-md border border-gray-200">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-red-100">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Currency</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-700 uppercase tracking-wider">Total Expenses</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-700 uppercase tracking-wider">In <?php echo $base_currency; ?> (Est.)</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                    <?php foreach ($report_data['total_expenses_by_currency'] as $currency => $amount): ?>
                        <tr>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($currency); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-700"><?php echo number_format($amount, 2); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-700">
                                <?php
                                $rate = $exchange_rates_to_base[$currency] ?? 0;
                                echo number_format($amount * $rate, 2) . ' ' . $base_currency;
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="text-center p-6 rounded-lg shadow-md
        <?php echo $report_data['gross_profit_loss_base_currency'] >= 0 ? 'bg-green-500 text-white' : 'bg-red-500 text-white'; ?>">
        <h3 class="text-2xl font-bold mb-2">Gross Profit/Loss</h3>
        <p class="text-4xl font-extrabold">
            <?php echo number_format($report_data['gross_profit_loss_base_currency'], 2); ?> <?php echo $base_currency; ?>
        </p>
        <p class="text-sm mt-2">
            (Based on estimated exchange rates. Rates are for demonstration only and should be dynamic in production.)
        </p>
    </div>

</div>

<?php include_template('footer'); ?>