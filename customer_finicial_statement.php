<?php
// customer_financial_statement.php - Placeholder for customer financial statements.

session_start();
require_once 'config.php';
require_once 'db_connect.php';
require_once INC_PATH . 'functions.php';

global $connection;

// Check customer login
customer_login_check();

$customer_id = $_SESSION['customer_id'];
$customer_name = $_SESSION['customer_name'] ?? 'Customer';

// --- Financial Statement Logic would go here ---
// You would fetch:
// - Customer's account balance (if implemented in DB)
// - List of payments made by the customer
// - List of invoices (if invoicing is implemented)
// - Details of vouchers paid via account balance

$financial_data_available = false; // Set to true when you implement data fetching

include_template('customer_header', ['page' => 'customer_financial_statement']);
?>

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-6xl mx-auto my-8">
    <h2 class="text-3xl font-bold text-gray-800 mb-6 text-center">My Financial Statement</h2>
    <p class="text-lg text-gray-700 mb-8 text-center">Hello, <?php echo htmlspecialchars($customer_name); ?>. This page will show your account balance and transaction history.</p>

    <?php if ($financial_data_available): ?>
        <!-- Display actual financial data here -->
        <div class="bg-blue-50 p-6 rounded-lg shadow-inner mb-8">
            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Account Summary</h3>
            <p><strong>Current Balance:</strong> [Balance Amount]</p>
            <!-- More details -->
        </div>

        <div class="bg-green-50 p-6 rounded-lg shadow-inner mb-8">
            <h3 class="text-xl font-semibold text-gray-700 mb-4 border-b pb-2">Recent Transactions</h3>
            <!-- Table of transactions -->
        </div>
    <?php else: ?>
        <div class="text-center py-10">
            <p class="text-gray-600 text-lg">Financial data features are currently under development.</p>
            <p class="text-gray-600">Please check back later or contact our staff for your financial details.</p>
        </div>
    <?php endif; ?>

    <div class="flex justify-center mt-8">
        <a href="index.php?page=customer_dashboard" class="bg-slate-700 hover:bg-slate-950 text-white font-bold py-2 px-4 rounded-lg shadow-md transition duration-300">Back to Dashboard</a>
    </div>
</div>

<?php include_template('customer_footer'); ?>