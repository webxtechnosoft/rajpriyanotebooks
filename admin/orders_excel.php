<?php
require_once __DIR__ . '/../includes/functions.php';

// Ensure admin is logged in
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

$db = getDB();

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$export_all = isset($_GET['export_all']) ? (int)$_GET['export_all'] : 0;
$status_filter = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? sanitize($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? sanitize($_GET['date_to']) : '';

$sql = "SELECT o.*, u.id as registered_user_id 
        FROM orders o 
        LEFT JOIN users u ON o.user_id = u.id 
        WHERE 1=1";
$params = [];

if ($order_id > 0) {
    $sql .= " AND o.id = ?";
    $params[] = $order_id;
} elseif (!$export_all) {
    if ($status_filter) {
        $sql .= " AND o.order_status = ?";
        $params[] = $status_filter;
    }
    if ($search) {
        $sql .= " AND (o.order_number LIKE ? OR o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.customer_phone LIKE ?)";
        $term = "%$search%";
        $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
    }
    if ($date_from) {
        $sql .= " AND DATE(o.created_at) >= ?";
        $params[] = $date_from;
    }
    if ($date_to) {
        $sql .= " AND DATE(o.created_at) <= ?";
        $params[] = $date_to;
    }
}

$sql .= " ORDER BY o.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($orders)) {
    set_flash('error', 'No orders found for the selected Excel export criteria.');
    header("Location: orders.php");
    exit();
}

// Generate Filename
if ($order_id > 0 && isset($orders[0])) {
    $filename = 'order_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $orders[0]['order_number']) . '_tally.csv';
} elseif ($export_all) {
    $filename = 'rajpriya_all_orders_tally_' . date('Ymd') . '.csv';
} else {
    $filename = 'rajpriya_filtered_orders_tally_' . date('Ymd') . '.csv';
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');
// Write UTF-8 BOM for Excel compatibility
fputs($output, "\xEF\xBB\xBF");

// Tally-Friendly Column Headers
fputcsv($output, [
    'Order ID',
    'Order Date',
    'Customer ID',
    'Customer Name',
    'Mobile Number',
    'Email',
    'Billing Address',
    'Shipping Address',
    'City',
    'State',
    'Pincode',
    'Landmark',
    'GSTIN',
    'Product Code (SKU)',
    'Product Name',
    'Category',
    'Variant (Ruling)',
    'Page Count',
    'Quantity',
    'Rate (Historical)',
    'Line Total',
    'GST Rate (%)',
    'Line GST',
    'Subtotal (Taxable Amount)',
    'Order Discount',
    'Total CGST',
    'Total SGST',
    'Total GST',
    'Delivery Fee',
    'Grand Total',
    'Payment Method',
    'Payment Status',
    'Order Status'
]);

foreach ($orders as $ord) {
    // Fetch line items using historical saved price and ruling type
    $stmt_items = $db->prepare("
        SELECT oi.*, p.sku, p.pages, c.name as category_name 
        FROM order_items oi 
        LEFT JOIN products p ON oi.product_id = p.id 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE oi.order_id = ? 
        ORDER BY oi.id ASC
    ");
    $stmt_items->execute([$ord['id']]);
    $items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

    $fullAddress = format_display_address($ord, false);
    $custId = !empty($ord['user_id']) ? $ord['user_id'] : 'Guest';
    $ord_gst = get_order_gst_summary($ord, $items);

    if (!empty($items)) {
        foreach ($items as $itm) {
            $product_code = !empty($itm['product_code']) ? $itm['product_code'] : get_variant_product_code($itm['product_id'], $itm['ruling_type'] ?? '', $itm['pages'] ?? null);
            $sku = !empty($product_code) ? $product_code : (!empty($itm['sku']) ? $itm['sku'] : ('PROD-' . $itm['product_id']));
            $ruling = !empty($itm['ruling_type']) ? $itm['ruling_type'] : 'Standard';
            $pages = !empty($itm['pages']) ? $itm['pages'] : '';
            $catName = !empty($itm['category_name']) ? $itm['category_name'] : 'Notebooks';

            // CRITICAL: Must use historical saved rate from order_items table ($itm['price'])
            $historicalRate = (float)$itm['price'];
            $lineTotal = (float)$itm['total_price'];

            $itm_gst_rate = isset($itm['gst_rate']) && $itm['gst_rate'] !== null && $itm['gst_rate'] !== '' ? (float)$itm['gst_rate'] : 18.00;
            $itm_gst = isset($itm['gst_amount']) && (float)$itm['gst_amount'] > 0 ? (float)$itm['gst_amount'] : round(($historicalRate * $itm['quantity'] * $itm_gst_rate) / 100, 2);

            fputcsv($output, [
                $ord['order_number'],
                $ord['created_at'],
                $custId,
                $ord['customer_name'],
                $ord['customer_phone'],
                !empty($ord['customer_email']) ? $ord['customer_email'] : 'Not Provided',
                $fullAddress,
                $fullAddress,
                $ord['city'],
                $ord['state'],
                $ord['pincode'],
                $ord['landmark'] ?: '',
                !empty($ord['gst_number']) ? $ord['gst_number'] : 'Not Provided',
                $sku,
                $itm['product_name'],
                $catName,
                $ruling,
                $pages,
                $itm['quantity'],
                number_format($historicalRate, 2, '.', ''),
                number_format($lineTotal, 2, '.', ''),
                number_format($itm_gst_rate, 2, '.', ''),
                number_format($itm_gst, 2, '.', ''),
                number_format($ord_gst['taxable_amount'], 2, '.', ''),
                number_format((float)$ord['discount'], 2, '.', ''),
                number_format($ord_gst['cgst'], 2, '.', ''),
                number_format($ord_gst['sgst'], 2, '.', ''),
                number_format($ord_gst['total_gst'], 2, '.', ''),
                number_format((float)$ord['delivery_fee'], 2, '.', ''),
                number_format((float)$ord['grand_total'], 2, '.', ''),
                $ord['payment_method'],
                $ord['payment_status'],
                $ord['order_status']
            ]);
        }
    } else {
        // Order with no items fallback
        fputcsv($output, [
            $ord['order_number'],
            $ord['created_at'],
            $custId,
            $ord['customer_name'],
            $ord['customer_phone'],
            $ord['customer_email'],
            $fullAddress,
            $fullAddress,
            $ord['city'],
            $ord['state'],
            $ord['pincode'],
            $ord['landmark'] ?: '',
            'N/A',
            'N/A',
            'Order Entry',
            'General',
            'Standard',
            '',
            1,
            number_format((float)$ord['grand_total'], 2, '.', ''),
            number_format((float)$ord['grand_total'], 2, '.', ''),
            number_format($ord_gst['taxable_amount'], 2, '.', ''),
            number_format((float)$ord['discount'], 2, '.', ''),
            number_format($ord_gst['cgst'], 2, '.', ''),
            number_format($ord_gst['sgst'], 2, '.', ''),
            number_format($ord_gst['total_gst'], 2, '.', ''),
            number_format((float)$ord['delivery_fee'], 2, '.', ''),
            number_format((float)$ord['grand_total'], 2, '.', ''),
            $ord['payment_method'],
            $ord['payment_status'],
            $ord['order_status']
        ]);
    }
}

fclose($output);
exit();
