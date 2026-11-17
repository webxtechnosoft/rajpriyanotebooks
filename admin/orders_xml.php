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
    set_flash('error', 'No orders found for the selected XML export criteria.');
    header("Location: orders.php");
    exit();
}

// Generate XML Document
$dom = new DOMDocument('1.0', 'UTF-8');
$dom->formatOutput = true;

$ordersNode = $dom->createElement('orders');
$dom->appendChild($ordersNode);

foreach ($orders as $ord) {
    $orderNode = $dom->createElement('order');
    $ordersNode->appendChild($orderNode);

    // 1. Order ID & Date
    $orderIdNode = $dom->createElement('order_id');
    $orderIdNode->appendChild($dom->createTextNode((string)$ord['order_number']));
    $orderNode->appendChild($orderIdNode);

    $orderDateNode = $dom->createElement('order_date');
    $orderDateNode->appendChild($dom->createTextNode((string)$ord['created_at']));
    $orderNode->appendChild($orderDateNode);

    // 2. Customer Information
    $customerNode = $dom->createElement('customer');

    $cName = $dom->createElement('name');
    $cName->appendChild($dom->createTextNode((string)$ord['customer_name']));
    $customerNode->appendChild($cName);

    $cMobile = $dom->createElement('mobile');
    $cMobile->appendChild($dom->createTextNode((string)$ord['customer_phone']));
    $customerNode->appendChild($cMobile);

    $cEmail = $dom->createElement('email');
    $cEmail->appendChild($dom->createTextNode((string)(!empty($ord['customer_email']) ? $ord['customer_email'] : '')));
    $customerNode->appendChild($cEmail);

    $orderNode->appendChild($customerNode);

    // 3. Shipping Address
    $fullAddress = format_display_address($ord, false);
    $shippingNode = $dom->createElement('shipping_address');

    $addrNode = $dom->createElement('address');
    $addrNode->appendChild($dom->createTextNode((string)$fullAddress));
    $shippingNode->appendChild($addrNode);

    $cityNode = $dom->createElement('city');
    $cityNode->appendChild($dom->createTextNode((string)($ord['city'] ?? '')));
    $shippingNode->appendChild($cityNode);

    $districtVal = !empty($ord['district']) ? $ord['district'] : ($ord['city'] ?? '');
    $districtNode = $dom->createElement('district');
    $districtNode->appendChild($dom->createTextNode((string)$districtVal));
    $shippingNode->appendChild($districtNode);

    $stateNode = $dom->createElement('state');
    $stateNode->appendChild($dom->createTextNode((string)($ord['state'] ?? '')));
    $shippingNode->appendChild($stateNode);

    $pincodeNode = $dom->createElement('pincode');
    $pincodeNode->appendChild($dom->createTextNode((string)($ord['pincode'] ?? '')));
    $shippingNode->appendChild($pincodeNode);

    $orderNode->appendChild($shippingNode);

    // 4. Order Items
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

    $itemsNode = $dom->createElement('items');

    if (!empty($items)) {
        foreach ($items as $itm) {
            $itemNode = $dom->createElement('item');

            $product_code = !empty($itm['product_code']) ? $itm['product_code'] : get_variant_product_code($itm['product_id'], $itm['ruling_type'] ?? '', $itm['pages'] ?? null);
            $sku = !empty($product_code) ? $product_code : (!empty($itm['sku']) ? $itm['sku'] : ('PROD-' . $itm['product_id']));

            $vInfo = parse_variety_info($itm['ruling_type'] ?? '', !empty($itm['pages']) ? $itm['pages'] : '');
            $variant = !empty($itm['ruling_type']) ? $itm['ruling_type'] : 'Standard';
            $pageCount = !empty($vInfo['page_count']) ? $vInfo['page_count'] : (!empty($itm['pages']) ? ($itm['pages'] . ' Pages') : '');
            $variety = !empty($vInfo['variety']) ? $vInfo['variety'] : (!empty($itm['ruling_type']) ? $itm['ruling_type'] : 'Standard');

            $pNameNode = $dom->createElement('product_name');
            $pNameNode->appendChild($dom->createTextNode((string)$itm['product_name']));
            $itemNode->appendChild($pNameNode);

            $skuNode = $dom->createElement('sku');
            $skuNode->appendChild($dom->createTextNode((string)$sku));
            $itemNode->appendChild($skuNode);

            $varNode = $dom->createElement('variant');
            $varNode->appendChild($dom->createTextNode((string)$variant));
            $itemNode->appendChild($varNode);

            $pageNode = $dom->createElement('page_count');
            $pageNode->appendChild($dom->createTextNode((string)$pageCount));
            $itemNode->appendChild($pageNode);

            $varietyNode = $dom->createElement('variety');
            $varietyNode->appendChild($dom->createTextNode((string)$variety));
            $itemNode->appendChild($varietyNode);

            $qtyNode = $dom->createElement('quantity');
            $qtyNode->appendChild($dom->createTextNode((string)$itm['quantity']));
            $itemNode->appendChild($qtyNode);

            $unitPriceNode = $dom->createElement('unit_price');
            $unitPriceNode->appendChild($dom->createTextNode(number_format((float)$itm['price'], 2, '.', '')));
            $itemNode->appendChild($unitPriceNode);

            $totalNode = $dom->createElement('total');
            $totalNode->appendChild($dom->createTextNode(number_format((float)$itm['total_price'], 2, '.', '')));
            $itemNode->appendChild($totalNode);

            $itemsNode->appendChild($itemNode);
        }
    }
    $orderNode->appendChild($itemsNode);

    // 5. Shipping Charge, Grand Total & Statuses
    $shippingChargeNode = $dom->createElement('shipping_charge');
    $shippingChargeNode->appendChild($dom->createTextNode(number_format((float)($ord['delivery_fee'] ?? 0), 2, '.', '')));
    $orderNode->appendChild($shippingChargeNode);

    $orderTotalNode = $dom->createElement('order_total');
    $orderTotalNode->appendChild($dom->createTextNode(number_format((float)$ord['grand_total'], 2, '.', '')));
    $orderNode->appendChild($orderTotalNode);

    $payStatusNode = $dom->createElement('payment_status');
    $payStatusNode->appendChild($dom->createTextNode((string)$ord['payment_status']));
    $orderNode->appendChild($payStatusNode);

    $ordStatusNode = $dom->createElement('order_status');
    $ordStatusNode->appendChild($dom->createTextNode((string)$ord['order_status']));
    $orderNode->appendChild($ordStatusNode);
}

// Generate Filename
if ($order_id > 0 && isset($orders[0])) {
    $filename = 'order_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $orders[0]['order_number']) . '_packing_sheet.xml';
} elseif ($export_all) {
    $filename = 'rajpriya_all_orders_packing_sheet_' . date('Ymd') . '.xml';
} else {
    $filename = 'rajpriya_orders_packing_sheet_' . date('Ymd') . '.xml';
}

header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo $dom->saveXML();
exit();
