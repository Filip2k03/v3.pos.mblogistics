<?php
// voucher_print.php
// This page is dedicated to displaying a print-friendly version of the voucher.

session_start(); // Start session to access user_id for authorization

require_once 'config.php';
require_once 'db_connect.php'; // This establishes the $connection variable
require_once 'includes/functions.php'; // For flash_message, is_logged_in, is_admin, generate_qr_code_url, etc.

// Access the global $connection variable
global $connection;

if (!is_logged_in()) {
    flash_message('error', 'Please log in to view and print vouchers.');
    redirect('index.php?page=login');
}

$voucher_id = $_GET['id'] ?? null;

if (!$voucher_id || !is_numeric($voucher_id)) {
    flash_message('error', 'Invalid voucher ID for printing.');
    redirect('index.php?page=voucher_list'); // Redirect back to list
}

$user_id = $_SESSION['user_id'];
$user_type = $_SESSION['user_type'] ?? null;
$is_admin = is_admin();

$voucher_data = null;
$breakdown_items = [];
$error_message = '';
$qr_code_url = '';
$pod_details = null; // To store POD information if available

// --- Fetch Current User's Region ID ---
$user_region_id = null;
$stmt_user_region = mysqli_prepare($connection, "SELECT region_id FROM users WHERE id = ?");
if ($stmt_user_region) {
    mysqli_stmt_bind_param($stmt_user_region, 'i', $user_id);
    mysqli_stmt_execute($stmt_user_region);
    $result_user_region = mysqli_stmt_get_result($stmt_user_region);
    if ($row_user_region = mysqli_fetch_assoc($result_user_region)) {
        $user_region_id = $row_user_region['region_id'];
    }
    mysqli_stmt_close($stmt_user_region);
}

try {
    // Fetch voucher details
    $query_voucher = "SELECT v.*,
                             r_origin.region_name AS origin_region,
                             r_origin.prefix AS origin_prefix,
                             r_dest.region_name AS destination_region,
                             u.username AS created_by_username,
                             c.consignment_code,
                             c.name AS consignment_name
                      FROM vouchers v
                      JOIN regions r_origin ON v.region_id = r_origin.id
                      LEFT JOIN regions r_dest ON v.destination_region_id = r_dest.id
                      JOIN users u ON v.created_by_user_id = u.id
                      LEFT JOIN consignments c ON v.consignment_id = c.id
                      WHERE v.id = ?";

    $stmt_voucher = mysqli_prepare($connection, $query_voucher);
    if ($stmt_voucher) {
        mysqli_stmt_bind_param($stmt_voucher, 'i', $voucher_id);
        mysqli_stmt_execute($stmt_voucher);
        $result_voucher = mysqli_stmt_get_result($stmt_voucher);
        $voucher_data = mysqli_fetch_assoc($result_voucher);
        mysqli_free_result($result_voucher);
        mysqli_stmt_close($stmt_voucher);

        if (!$voucher_data) {
            $error_message = 'Voucher not found.';
        } else {
            // --- START MODIFIED PRINT PERMISSION LOGIC ---
            $has_print_permission = false;

            if ($is_admin) {
                $has_print_permission = true;
            } elseif ($voucher_data['created_by_user_id'] == $user_id) {
                $has_print_permission = true;
            } elseif (($user_type === USER_TYPE_MYANMAR || $user_type === USER_TYPE_MALAY) && $user_region_id !== null) {
                if ($voucher_data['status'] === 'Pending') {
                    if ($user_region_id == $voucher_data['region_id']) {
                        $has_print_permission = true;
                    }
                } elseif ($voucher_data['status'] === 'Delivered') {
                    $has_print_permission = true;
                } else {
                    if ($user_region_id == $voucher_data['region_id'] || $user_region_id == $voucher_data['destination_region_id']) {
                        $has_print_permission = true;
                    }
                }
            } else {
                $has_print_permission = false;
            }

            if (!$has_print_permission) {
                $error_message = 'You do not have permission to print this voucher.';
                $voucher_data = null;
            }
            // --- END MODIFIED PRINT PERMISSION LOGIC ---

            // Only fetch breakdowns and generate QR code if voucher_data is still valid
            if ($voucher_data) {
                $stmt_breakdowns = mysqli_prepare($connection, "SELECT * FROM voucher_breakdowns WHERE voucher_id = ?");
                if ($stmt_breakdowns) {
                    mysqli_stmt_bind_param($stmt_breakdowns, 'i', $voucher_id);
                    mysqli_stmt_execute($stmt_breakdowns);
                    $result_breakdowns = mysqli_stmt_get_result($stmt_breakdowns);
                    while ($row = mysqli_fetch_assoc($result_breakdowns)) {
                        $breakdown_items[] = $row;
                    }
                    mysqli_free_result($result_breakdowns);
                    mysqli_stmt_close($stmt_breakdowns);
                } else {
                    $error_message = 'Error fetching voucher breakdowns: ' . mysqli_error($connection);
                    $voucher_data = null;
                }

                // Fetch POD details (NEW)
                $stmt_pod = mysqli_prepare($connection, "SELECT image_path, signature_data, delivery_notes, delivery_timestamp FROM proof_of_delivery WHERE voucher_id = ?");
                if ($stmt_pod) {
                    mysqli_stmt_bind_param($stmt_pod, 'i', $voucher_id);
                    mysqli_stmt_execute($stmt_pod);
                    $result_pod = mysqli_stmt_get_result($stmt_pod);
                    $pod_details = mysqli_fetch_assoc($result_pod);
                    mysqli_stmt_close($stmt_pod);
                }

                // Generate QR Code URL for printing
                $qr_code_data_url = BASE_URL . 'index.php?page=customer_view_voucher&code=' . urlencode($voucher_data['voucher_code']);
                $qr_code_url = generate_qr_code_url($qr_code_data_url, 100);
            }
        }
    } else {
        $error_message = 'Error preparing voucher details query: ' . mysqli_error($connection);
    }
} catch (Exception $e) {
    error_log("Voucher Print Error: " . $e->getMessage());
    $error_message = 'An unexpected error occurred: Please try again later.';
    $voucher_data = null;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Voucher - <?php echo htmlspecialchars($voucher_data['voucher_code'] ?? 'N/A'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;900&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f8f9fa;
        }
        .voucher-print-container {
            margin: 20px auto;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.07);
            padding: 32px 24px;
            position: relative;
            max-width: 800px; /* Limit width for print */
        }
        .watermark-mb {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 6rem;
            font-weight: 900;
            color: #2563eb;
            opacity: 0.07;
            white-space: nowrap;
            pointer-events: none;
            z-index: 0;
            text-shadow: 2px 2px 16px rgba(0,0,0,0.2), 0 0 2px #2563eb;
            user-select: none;
        }
        .voucher-header,
        .voucher-section,
        .footer-section,
        .notes,
        .signature-section {
            position: relative;
            z-index: 1;
        }
        .voucher-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 24px;
            flex-wrap: wrap;
            text-align: left;
            margin-bottom: 2rem;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 1.5rem;
        }
        .voucher-header .logo-col {
            flex: 0 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .voucher-header .logo-col img {
            width: 90px;
            height: auto;
            border-radius: 50%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            background: #fff;
            padding: 4px;
            object-fit: contain;
        }
        .voucher-header .info-col {
            flex: 1 1 200px;
            min-width: 200px;
            text-align: left;
        }
        .voucher-header h2 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
            margin-top: 0;
            color: #1a202c;
        }
        .voucher-header h4 {
            font-size: 1.3rem;
            font-weight: 500;
            margin-bottom: 0.5rem;
            margin-top: 0;
            color: #4a5568;
        }
        .voucher-header p {
            margin-bottom: 0;
            margin-top: 0.25rem;
            color: #4a5568;
        }
        .voucher-section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: #2563eb;
            margin-bottom: 0.8rem;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 0.5rem;
        }
        .info-table {
            width: 100%;
            margin-bottom: 2rem;
            border-collapse: collapse;
            font-size: 0.95rem;
        }
        .info-table th, .info-table td {
            border: 1px solid #e2e8f0;
            padding: 10px 14px;
            vertical-align: top;
            color: #2d3748;
        }
        .info-table th {
            background: #f1f5f9;
            width: 35%;
            font-weight: 600;
            color: #4a5568;
        }
        .info-table thead th {
            background: #e2e8f0;
            color: #2d3748;
        }
        .info-table tbody tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .footer-section {
            margin-top: 2.5rem;
            text-align: center;
            font-size: 0.9rem;
            color: #64748b;
            border-top: 1px dashed #e2e8f0;
            padding-top: 1rem;
        }
        .notes {
            margin-top: 2rem;
        }
        .notes ol {
            background: #fff5f5;
            border-radius: 6px;
            padding: 18px 24px;
            border: 1px solid #ffeaea;
            list-style-type: decimal;
            padding-left: 40px;
        }
        .notes ol li {
            margin-bottom: 8px;
            color: #4a5568;
        }
        .signature-section {
            display: flex;
            justify-content: space-between;
            margin-top: 3rem;
            gap: 20px;
        }
        .signature-box {
            border-top: 1px solid #333;
            flex: 1;
            text-align: center;
            padding-top: 10px;
            font-size: 0.9rem;
            min-height: 40px;
            color: #4a5568;
        }
        .d-grid {
            display: grid;
            gap: 1rem;
        }

        /* Print specific styles */
        @media print {
            body {
                background: #fff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                margin: 0;
                padding: 0;
            }
            .voucher-print-container {
                margin: 0;
                border: none;
                box-shadow: none;
                padding: 10mm;
            }
            .watermark-mb {
                font-size: 8rem;
                opacity: 0.1;
                color: #d1d8e0;
                text-shadow: none;
            }
            .no-print {
                display: none !important;
            }
            .info-table th, .info-table td {
                border-color: #bfd0f3 !important;
            }
            .voucher-header {
                border-bottom: 2px solid #bfd0f3 !important;
            }
            .voucher-section-title {
                border-bottom: 1px solid #bfd0f3 !important;
            }
            .footer-section {
                border-top: 1px dashed #bfd0f3 !important;
            }
            .qr-code-col {
                flex: 0 0 auto;
                order: 1;
            }
            .voucher-header .info-col {
                order: 2;
                text-align: right;
            }
            .voucher-header .logo-col {
                order: 3;
            }
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <h1 class="mb-4 text-center no-print">Shipment Voucher Print Preview</h1>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
                <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php elseif ($voucher_data): ?>
            <div class="voucher-print-container">
                <div class="watermark-mb">MBlogistics</div>
                <div class="voucher-header">
                    <div class="logo-col">
                        <img src="bg.jpg" alt="MB Logistics Logo" >
                    </div>
                    <div class="info-col">
                        <h2>MB LOGISTICS</h2>
                        <h4>Shipment Voucher</h4>
                        <p><strong>Voucher Number: <?php echo htmlspecialchars($voucher_data['voucher_code']); ?></strong></p>
                        <p><strong>Date: <?php echo date('Y-m-d H:i:s', strtotime($voucher_data['created_at'])); ?> (GMT+6:30)</strong></p>
                    </div>
                    <?php if (!empty($qr_code_url)): ?>
                        <div class="qr-code-col text-center">
                            <img src="<?php echo $qr_code_url; ?>" alt="QR Code for Voucher <?php echo htmlspecialchars($voucher_data['voucher_code']); ?>" style="width: 100px; height: 100px; border: 1px solid #ccc; padding: 5px; border-radius: 5px;">
                            <p style="font-size: 0.7rem; margin-top: 5px;">Scan for details</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="voucher-section mb-4">
                    <div class="voucher-section-title">Sender & Receiver Information</div>
                    <table class="info-table">
                        <thead>
                            <tr>
                                <th>Sender</th>
                                <th>Receiver</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <strong>Name:</strong> <?php echo htmlspecialchars($voucher_data['sender_name']); ?><br>
                                    <strong>Phone:</strong> <?php echo htmlspecialchars($voucher_data['sender_phone']); ?><br>
                                    <strong>Address:</strong> <address><?php echo nl2br(htmlspecialchars($voucher_data['sender_address'])); ?></address>
                                </td>
                                <td>
                                    <strong>Name:</strong> <?php echo htmlspecialchars($voucher_data['receiver_name']); ?><br>
                                    <strong>Phone:</strong> <?php echo htmlspecialchars($voucher_data['receiver_phone']); ?><br>
                                    <strong>Address:</strong> <address><?php echo nl2br(htmlspecialchars($voucher_data['receiver_address'])); ?></address>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="voucher-section mb-4">
                    <div class="voucher-section-title">Voucher Details</div>
                    <?php if (!empty($breakdown_items)): ?>
                    <div class="voucher-section mb-4">
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
                                $type_totals = [];
                                $grand_total = 0;
                                foreach ($breakdown_items as $item):
                                    $item_kg_val = is_numeric($item['kg']) ? $item['kg'] : 0;
                                    $item_price_per_kg_val = is_numeric($item['price_per_kg']) ? $item['price_per_kg'] : 0;
                                    $subtotal = $item_kg_val * $item_price_per_kg_val;
                                    $grand_total += $subtotal;

                                    if (!isset($type_totals[$item['item_type']])) {
                                        $type_totals[$item['item_type']] = 0;
                                    }
                                    $type_totals[$item['item_type']] += $subtotal;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['item_type'] ?: 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars(number_format((float)$item['kg'], 2)); ?></td>
                                    <td>
                                        <?php
                                            $currency = $voucher_data['currency'];
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
                                    <th colspan="3" style="text-align: right;">Grand Total:</th>
                                    <th><?php echo htmlspecialchars($currency) . ' ' . number_format($grand_total, 2); ?></th>
                                </tr>
                            </tfoot>
                        </table>

                    </div>
                    <?php endif; ?>
                    <table class="info-table">
                        <tbody>
                            <tr>
                                <th>Origin Region</th>
                                <td><?php echo htmlspecialchars($voucher_data['origin_region']); ?></td>
                            </tr>
                            <tr>
                                <th>Destination Region</th>
                                <td><?php echo htmlspecialchars($voucher_data['destination_region'] ?? 'N/A'); ?></td>
                            </tr>
                            <tr>
                                <th>Weight (KG)</th>
                                <td><?php echo htmlspecialchars($voucher_data['weight_kg']); ?> KG</td>
                            </tr>
                            <tr>
                                <th>Delivery Charge</th>
                                <td>
                                    <?php
                                        $currency = $voucher_data['currency'];
                                        if ($currency === '0' || $currency === '' || $currency === null) $currency = '';
                                        echo htmlspecialchars($currency) . ' ' . number_format($voucher_data['delivery_charge'], 2);
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <th>Total Amount</th>
                                <td>
                                    <strong>
                                        <?php
                                            $currency = $voucher_data['currency'];
                                            if ($currency === '0' || $currency === '' || $currency === null) $currency = '';
                                            echo htmlspecialchars($currency) . ' ' . number_format($voucher_data['total_amount'], 2);
                                        ?>
                                    </strong>
                                </td>
                            </tr>
                             <tr>
                                 <th>Payment Method</th>
                                 <td><?php echo htmlspecialchars($voucher_data['payment_method'] ?: 'N/A'); ?></td>
                             </tr>
                             <tr>
                                 <th>Delivery Type</th>
                                 <td><?php echo htmlspecialchars($voucher_data['delivery_type'] ?: 'N/A'); ?></td>
                             </tr>
                             <tr>
                                 <th>Notes</th>
                                 <td><?php echo nl2br(htmlspecialchars($voucher_data['notes'] ?: 'N/A')); ?></td>
                             </tr>
                        </tbody>
                    </table>
                </div>

                <div class="notes">
                    <p style="color: #dc3545; font-weight: bold;"><strong>Important Notes:</strong></p>
                    <ol style="background: #fff5f5; border-radius: 6px; padding: 18px 24px; border: 1px solid #ffeaea;">
                        <li>ဥပဒေနှင့်မလွတ်ကင်းသောပစ္စည်းများ လုံးဝ(လုံးဝ) လက်မခံပါ။</li>
                        <li>ပါဝင်ပစ္စည်းများအား မှန်ကန်စွာပြောပါ။ ကြိုတင်ကြေငြာထားခြင်းမရှိပဲ ခိုးထည့်သောပစ္စည်းများအတွက် တာဝန်မယူပါ။ ယင်းပစ္စည်းများနှင့်ပတ်သက်ပြီ ပြဿနာတစ်စုံတစ်ရာဖြစ်ပေါ်ပါက ပိုဆောင်သူဘက်မှတာဝန်ယူဖြေရှင်းရမည်။</li>
                        <li>ပစ္စည်းပိုဆောင်စဉ် လုံခြုံရေးအရ ဖွင့်ဖေါက်စစ်ဆေးမှုအား လက်ခံပေးရပါမည်။</li>
                        <li>အစားအသောက်နှင့် ကြိုးကျေလွယ်သောပစ္စည်းများ အပျက်အစီး တာဝန်မယူပါ။</li>
                        <li>သက်မှတ်KG နှုန်းထားများသည် ရုံးထုတ်ဈေးသာဖြစ်ပြီး တစ်ဖက်နိုင်ငံတွင် အရောက်ပိုလျှင် အရောက်ပိုခ ထပ်ပေးရပါမည်။</li>
                    </ol>
                </div>

                <div class="signature-section">
                    <div class="signature-box">Sender's Signature</div>
                    <div class="signature-box">Receiver's Signature</div>
                    <div class="signature-box">Staff Signature</div>
                </div>

                <div class="footer-section">
                    <p>Thank you for choosing MB Logistics - Your trusted global shipping partner</p>
                    <p>Printed On: <?php echo date('Y-m-d H:i:s'); ?> | www.mblogistics.express</p>
                </div>
            </div>

            <div class="d-grid gap-3 mt-4 no-print" style="max-width: 400px; margin: 0 auto;">
                <button class="btn btn-primary btn-lg" onclick="window.print()">
                    Print Voucher
                </button>
                <a href="index.php?page=voucher_view&id=<?php echo htmlspecialchars($voucher_id); ?>" class="btn btn-outline-secondary btn-lg">
                    Back to Voucher Details
                </a>
            </div>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
</body>
</html>