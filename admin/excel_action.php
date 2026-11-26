<?php
/**
 * Product Excel Action Handler
 * Handles: template download, export existing products, preview upload, commit import
 */
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

require_once __DIR__ . '/includes/excel_parser.php';

$db = getDB();
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// ─────────────────────────────────────────────────────────────────────────────
// 1) Download Product Excel Template
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rajpriya_product_import_template.csv');

    $output = fopen('php://output', 'w');
    // Write UTF-8 BOM for Excel compatibility
    fputs($output, "\xEF\xBB\xBF");

    // Header row matching standard database fields
    fputcsv($output, [
        'Product ID',
        'SKU',
        'Product Name',
        'Brand',
        'Category Name',
        'Rate / Price',
        'MRP',
        'Selling Price Ruled',
        'Discounted Price Ruled',
        'Selling Price Unruled',
        'Discounted Price Unruled',
        'Size',
        'Paper Type',
        'GSM',
        'Binding',
        'Cover Type',
        'Description',
        'GST Rate',
        'Manual Verification Flag',
        'Manual Verification Notes',
        'Variant ID',
        'Variant Code',
        'Variety Code',
        'Variety',
        'Variant Page Count',
        'Variant Bundle Qty',
        'Variant Bundle Stock',
        'Variant Loose Stock',
        'Variant Stock',
        'Variant MRP',
        'Variant Regular Price',
        'Variant Sale Price',
        'Variant GST Rate',
        'Variant Basic Price',
        'Variant Selling Price',
        'Variant Discounted Price'
    ]);

    // Populate template with existing product rows as reference examples
    $stmt = $db->query("
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        ORDER BY p.id ASC LIMIT 15
    ");
    $sampleProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $sampleVariantStmt = $db->prepare("SELECT id, product_code, page_count, variety, variety_display,
        bundle_qty, bundle_stock, loose_stock, stock, mrp, price, sale_price, gst_rate,
        basic_price, selling_price, discounted_price
        FROM product_variants WHERE product_id = ? ORDER BY id ASC");

    foreach ($sampleProducts as $p) {
        $sampleVariantStmt->execute([(int)$p['id']]);
        $sampleVariants = $sampleVariantStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sampleVariants as $v) {
            fputcsv($output, [
            $p['id'],
            $p['sku'],
            $p['name'],
            $p['brand_name'] ?: $p['name'],
            $p['category_name'],
            $p['price'],
            $p['mrp'] ?: $p['price'],
            $p['selling_price_ruled'] ?: '',
            $p['discounted_price_ruled'] ?: '',
            $p['selling_price_unruled'] ?: '',
            $p['discounted_price_unruled'] ?: '',
            $p['size'],
            $p['paper_type'],
            $p['gsm'],
            $p['binding'],
            $p['cover_type'],
            $p['description'],
            $p['gst_rate'] ?? '',
            (int)!empty($p['manual_verification_flag']),
            $p['manual_verification_notes'] ?? '',
            $v['id'], $v['product_code'], $v['variety'], $v['variety_display'],
            $v['page_count'], $v['bundle_qty'], $v['bundle_stock'], $v['loose_stock'],
            $v['stock'], $v['mrp'], $v['price'], $v['sale_price'], $v['gst_rate'],
            $v['basic_price'], $v['selling_price'], $v['discounted_price']
            ]);
        }
    }

    fclose($output);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 2) Export All Existing Products as CSV
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'export') {
    $stmt = $db->query("
        SELECT p.*, c.name as category_name
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        ORDER BY p.id ASC
    ");
    $allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Product Management edits both the parent product and its variety rows.
    // Export one row for each actual variant so every client-editable value
    // remains in its own Excel cell.
    $variantStmt = $db->prepare("SELECT id, product_code, page_count, variety, variety_display,
        bundle_qty, bundle_stock, loose_stock, stock, mrp, price, sale_price, gst_rate,
        basic_price, selling_price, discounted_price
        FROM product_variants WHERE product_id = ? ORDER BY id ASC");

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rajpriya_products_export_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Product ID',
        'SKU',
        'Product Name',
        'Brand',
        'Category Name',
        'Rate / Price',
        'MRP',
        'Selling Price Ruled',
        'Discounted Price Ruled',
        'Selling Price Unruled',
        'Discounted Price Unruled',
        'Size',
        'Paper Type',
        'GSM',
        'Binding',
        'Cover Type',
        'Featured',
        'Bestseller',
        'New Arrival',
        'Active',
        'Description',
        'GST Rate',
        'Manual Verification Flag',
        'Manual Verification Notes',
        'Variant ID',
        'Variant Code',
        'Variety Code',
        'Variety',
        'Variant Page Count',
        'Variant Bundle Qty',
        'Variant Bundle Stock',
        'Variant Loose Stock',
        'Variant Stock',
        'Variant MRP',
        'Variant Regular Price',
        'Variant Sale Price',
        'Variant GST Rate',
        'Variant Basic Price',
        'Variant Selling Price',
        'Variant Discounted Price'
    ]);

    foreach ($allProducts as $p) {
        $variantStmt->execute([(int)$p['id']]);
        $variants = $variantStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($variants as $v) {
            fputcsv($output, [
            $p['id'],
            $p['sku'],
            $p['name'],
            $p['brand_name'] ?: '',
            $p['category_name'],
            $p['price'],
            $p['mrp'] ?: '',
            $p['selling_price_ruled'] ?: '',
            $p['discounted_price_ruled'] ?: '',
            $p['selling_price_unruled'] ?: '',
            $p['discounted_price_unruled'] ?: '',
            $p['size'],
            $p['paper_type'],
            $p['gsm'],
            $p['binding'],
            $p['cover_type'],
            $p['featured'] ? 'Yes' : 'No',
            $p['bestseller'] ? 'Yes' : 'No',
            $p['new_arrival'] ? 'Yes' : 'No',
            $p['active'] ? 'Yes' : 'No',
            $p['description'],
            $p['gst_rate'] ?? '',
            (int)!empty($p['manual_verification_flag']),
            $p['manual_verification_notes'] ?? '',
            $v['id'], $v['product_code'], $v['variety'], $v['variety_display'],
            $v['page_count'], $v['bundle_qty'], $v['bundle_stock'], $v['loose_stock'],
            $v['stock'], $v['mrp'], $v['price'], $v['sale_price'], $v['gst_rate'],
            $v['basic_price'], $v['selling_price'], $v['discounted_price']
            ]);
        }
    }

    fclose($output);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 3) Preview Uploaded Product Excel/CSV (AJAX)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'preview') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['excel_file'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'No file was uploaded.']);
        exit();
    }

    $file = $_FILES['excel_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'File upload error code: ' . $file['error']]);
        exit();
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx', 'xls', 'txt'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid file format. Please upload .xlsx or .csv files.']);
        exit();
    }

    $rawRows = parse_excel_or_csv_data($file['tmp_name']);
    $analysis = analyze_flat_product_import($db, $rawRows);

    header('Content-Type: application/json');
    echo json_encode($analysis);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 4) Commit Product Import
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'commit') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['import_token'])) {
        set_flash('error', 'Invalid import confirmation request.');
        header('Location: excel-manager.php');
        exit();
    }

    $token = sanitize($_POST['import_token']);
    $result = execute_flat_product_import($db, $token);

    if ($result['success']) {
        set_flash('success', $result['message']);
    } else {
        set_flash('error', $result['message']);
    }

    $redirect = isset($_POST['redirect']) && $_POST['redirect'] === 'excel-manager' ? 'excel-manager.php' : 'products.php';
    header('Location: ' . $redirect);
    exit();
}

header('Location: excel-manager.php');
exit();
