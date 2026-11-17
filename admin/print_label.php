<?php
/**
 * Admin Bundle Label Printer — Professional Print-Ready Production Labels
 * Implemented based on Raj Priya Official Bundle Label Reference Design:
 * - Rounded rectangular outer border (thick black)
 * - Brand Header: RAJ PRIYA (red serif with (R)), NOTE BOOKS (navy), and tagline QUALITY WRITES A BRIGHTER TOMORROW
 * - Large dark-blue product title bar (e.g. POWER 192P RULED (SR))
 * - Product Information Table (Product ID, Bundle ID, Pages, Ruling, Pack)
 * - High-precision vector Code 128 barcode with human-readable text and caption PRODUCT BARCODE
 * - Green-highlighted Bundle QR section with BUNDLE QTY, scannable QR code, product code, pack info,
 *   and caption BUNDLE QR CODE (PRODUCT DETAILS + QTY)
 * - Full dynamic mapping to products, variants, and customer orders
 */

require_once __DIR__ . '/../includes/functions.php';

// Protect Admin Access
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_id'])) {
    header("Location: ../login.php?type=admin");
    exit();
}

$db = getDB();
$logos = get_active_logos();

// Helper: Build Deployed Mobile Web URL for QR scanning
if (!function_exists('get_product_deployed_qr_url')) {
    function get_product_deployed_qr_url($product_code, $bundle_qty = 0, $bundle_id = '') {
        $dbAppUrl = get_setting('app_url', '');
        if (!empty($dbAppUrl) && stripos($dbAppUrl, 'localhost') === false && stripos($dbAppUrl, '127.0.0.1') === false) {
            $baseUrl = rtrim(trim($dbAppUrl), '/');
        } else {
            $baseUrl = 'https://www.rajpriyanotebooks.com';
        }
        $params = ['code' => $product_code];
        if ($bundle_qty > 0) $params['bundle_qty'] = $bundle_qty;
        if (!empty($bundle_id)) $params['bundle_id'] = $bundle_id;
        return $baseUrl . '/product-info.php?' . http_build_query($params);
    }
}

// Helper: Build Local Preview URL for testing inside admin on localhost
if (!function_exists('get_product_local_preview_url')) {
    function get_product_local_preview_url($product_code, $bundle_qty = 0, $bundle_id = '', $product_id = 0, $variant_id = 0) {
        $params = ['code' => $product_code];
        if ($bundle_qty > 0) $params['bundle_qty'] = $bundle_qty;
        if (!empty($bundle_id)) $params['bundle_id'] = $bundle_id;
        if ($product_id > 0) $params['product_id'] = $product_id;
        if ($variant_id > 0) $params['variant_id'] = $variant_id;
        
        // Pass current Label Printer URL query parameters as return_url so Back returns directly here
        $currentQuery = !empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        if ($currentQuery !== '') {
            $params['return_url'] = 'admin/print_label.php?' . $currentQuery;
        } else {
            $retParams = [];
            if ($product_id > 0) $retParams['product_id'] = $product_id;
            if ($variant_id > 0) $retParams['variant_id'] = $variant_id;
            if (!empty($product_code)) $retParams['code'] = $product_code;
            if ($bundle_qty > 0) $retParams['bundle_qty'] = $bundle_qty;
            if (!empty($bundle_id)) $retParams['bundle_id'] = $bundle_id;
            $params['return_url'] = 'admin/print_label.php' . (!empty($retParams) ? ('?' . http_build_query($retParams)) : '');
        }
        return '../product-info.php?' . http_build_query($params);
    }
}

// Helper: Get local network IP for mobile testing
if (!function_exists('get_server_local_ip')) {
    function get_server_local_ip() {
        $ip = gethostbyname(gethostname());
        if (filter_var($ip, FILTER_VALIDATE_IP) && $ip !== '127.0.0.1') {
            return $ip;
        }
        return '10.246.34.88';
    }
}

// Helper: Build Wi-Fi Local Network URL for phone scanning on same Wi-Fi
if (!function_exists('get_product_wifi_qr_url')) {
    function get_product_wifi_qr_url($product_code, $bundle_qty = 0, $bundle_id = '') {
        $localIp = get_server_local_ip();
        $params = ['code' => $product_code];
        if ($bundle_qty > 0) $params['bundle_qty'] = $bundle_qty;
        if (!empty($bundle_id)) $params['bundle_id'] = $bundle_id;
        return "http://{$localIp}/rajpriya/product-info.php?" . http_build_query($params);
    }
}

// Helper: Build Direct Text Payload for Mobile Phone QR Scanners
if (!function_exists('get_bundle_qr_text_payload')) {
    function get_bundle_qr_text_payload($product_name, $product_code, $bundle_id, $pages, $ruling, $bundle_qty) {
        return "RAJ PRIYA NOTEBOOKS\n" .
               "Product: {$product_name}\n" .
               "Product ID: {$product_code}\n" .
               "Bundle ID: {$bundle_id}\n" .
               "Pages: {$pages}\n" .
               "Ruling: {$ruling}\n" .
               "Bundle Qty: {$bundle_qty} NOS\n" .
               "Pack: 1 BDLS x {$bundle_qty} NOS";
    }
}

// Fetch filter inputs
$search = isset($_GET['search']) ? trim(sanitize($_GET['search'])) : '';
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$label_type = isset($_GET['type']) ? strtolower(trim(sanitize($_GET['type']))) : ''; // 'bundle' or 'extra'
$item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

$selected_product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$selected_variant_id = isset($_GET['variant_id']) ? (int)$_GET['variant_id'] : 0;
$selected_code = isset($_GET['code']) ? trim(sanitize($_GET['code'])) : '';
$copies = isset($_GET['copies']) ? max(1, min(10000, (int)$_GET['copies'])) : 1;
$custom_bundle_qty = isset($_GET['bundle_qty']) && (int)$_GET['bundle_qty'] > 0 ? (int)$_GET['bundle_qty'] : 0;
$layout_style = isset($_GET['layout']) && $_GET['layout'] === 'legacy' ? 'legacy' : 'reference';

// QR Mode: 'text' (default, instant offline text), 'wifi' (local Wi-Fi URL), or 'url' (live domain)
$qr_mode = isset($_GET['qr_mode']) ? strtolower(trim($_GET['qr_mode'])) : 'text';
if (!in_array($qr_mode, ['text', 'wifi', 'url'])) {
    $qr_mode = 'text';
}

// Code Format: 'clean' (PWR-192-SR matching reference image) or 'sku' (RP-OCT26-POW-192-SR)
$code_format = isset($_GET['code_format']) && $_GET['code_format'] === 'sku' ? 'sku' : 'clean';

// If search provided, check if it matches an Order Number / ID
if ($search !== '' && $order_id === 0) {
    $term = "%{$search}%";
    $num_id = is_numeric($search) ? (int)$search : 0;
    
    $findOrd = $db->prepare("
        SELECT id FROM orders 
        WHERE order_number LIKE ? OR customer_name LIKE ? OR customer_phone LIKE ? OR id = ? 
        LIMIT 1
    ");
    $findOrd->execute([$term, $term, $term, $num_id]);
    $matched_order_id = (int)$findOrd->fetchColumn();
    if ($matched_order_id > 0) {
        $order_id = $matched_order_id;
    }
}

// Fetch all base products for dropdown
$products_list = $db->query("SELECT id, name, pages, std_qty, brand_name, sku FROM products WHERE active = 1 ORDER BY name ASC, pages ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch variants for selected product if any
$product_variants_dropdown = [];
if ($selected_product_id > 0) {
    $stmtVarDropdown = $db->prepare("SELECT id, product_code, variety, variety_display, bundle_qty FROM product_variants WHERE product_id = ? ORDER BY id ASC");
    $stmtVarDropdown->execute([$selected_product_id]);
    $product_variants_dropdown = $stmtVarDropdown->fetchAll(PDO::FETCH_ASSOC);
}

$order_data = null;
$labels_to_print = [];

// ═══════════════════════════════════════════════════════════════════════════
// CASE A: Generating Labels for an Existing Customer Order
// ═══════════════════════════════════════════════════════════════════════════
if ($order_id > 0) {
    $stmtOrd = $db->prepare("SELECT * FROM orders WHERE id = ?");
    $stmtOrd->execute([$order_id]);
    $order_data = $stmtOrd->fetch(PDO::FETCH_ASSOC);

    if ($order_data) {
        if ($item_id > 0) {
            $stmtItm = $db->prepare("
                SELECT oi.*, p.pages AS prod_pages, p.std_qty AS prod_std_qty, p.brand_name 
                FROM order_items oi 
                LEFT JOIN products p ON oi.product_id = p.id 
                WHERE oi.order_id = ? AND oi.id = ?
            ");
            $stmtItm->execute([$order_id, $item_id]);
        } else {
            $stmtItm = $db->prepare("
                SELECT oi.*, p.pages AS prod_pages, p.std_qty AS prod_std_qty, p.brand_name 
                FROM order_items oi 
                LEFT JOIN products p ON oi.product_id = p.id 
                WHERE oi.order_id = ? 
                ORDER BY oi.id ASC
            ");
            $stmtItm->execute([$order_id]);
        }
        $raw_items = $stmtItm->fetchAll(PDO::FETCH_ASSOC);
        $order_packing_details = get_order_packing_details($order_id, $raw_items);
        $order_total_full_bundles = $order_packing_details['total_full_bundles'];

        foreach ($raw_items as $it) {
            $calc = calculate_order_item_packing($it);
            $std_ppb = $custom_bundle_qty > 0 ? $custom_bundle_qty : $calc['standard_bundle_size'];
            $full_bundles = $calc['full_bundles'];
            $remaining_pieces = $calc['remaining_pieces'];
            $partial_bundles = $calc['partial_bundles'];

            $vInfo = parse_variety_info($it['ruling_type'] ?? '');
            $pCode = !empty($it['product_code']) ? $it['product_code'] : get_variant_product_code($it['product_id'], $it['ruling_type'] ?? '');
            if (empty($pCode)) {
                $pCode = 'RP-ORD-' . $it['id'];
            }

            $pPages = !empty($vInfo['page_count']) ? $vInfo['page_count'] : (!empty($it['prod_pages']) ? ($it['prod_pages'] . ' Pages') : '192 Pages');
            $pPagesClean = preg_replace('/[^0-9]/', '', (string)$pPages) ?: '192';

            $pVariety = !empty($vInfo['variety']) ? $vInfo['variety'] : 'Ruled';
            if (strcasecmp($pVariety, 'Micro Point') === 0) {
                $pVariety = 'Maths Plain';
            }
            $vCode = !empty($it['variety']) ? strtoupper(trim($it['variety'])) : '';
            if (empty($vCode) || strlen($vCode) > 4) {
                if (preg_match('/-([A-Z]{2,3})$/i', $pCode, $mCode)) {
                    $vCode = strtoupper($mCode[1]);
                }
            }
            if (empty($vCode)) {
                $vCode = get_variant_code_for_variety($pVariety);
            }

            $productTitle = format_bundle_label_product_title($it['product_name'], $pPagesClean, $pVariety, $vCode);
            $cleanCode = ($code_format === 'clean') ? get_variant_shorthand_code($pCode, $it['product_name'], $pPagesClean, $vCode) : $pCode;
            $rulingDisplay = !empty($vCode) && stripos($pVariety, $vCode) === false ? "{$pVariety} ({$vCode})" : $pVariety;
            $rulingDisplay = strtoupper($rulingDisplay);

            $base_label = [
                'is_order_label' => true,
                'item_id' => (int)$it['id'],
                'variant_id' => 0,
                'product_id' => (int)$it['product_id'],
                'product_name' => $it['product_name'],
                'product_code' => $cleanCode,
                'raw_code' => $pCode,
                'page_count' => is_numeric($pPagesClean) ? ($pPagesClean . ' Pages') : $pPages,
                'pages_clean' => $pPagesClean,
                'variety' => $pVariety,
                'variety_code' => $vCode,
                'ruling_display' => $rulingDisplay,
                'product_title' => $productTitle
            ];

            if ($label_type === 'extra' || $label_type === 'piece' || $label_type === 'loose') {
                // Generate label only for extra/remaining loose pieces
                if ($remaining_pieces > 0) {
                    $lbl = $base_label;
                    $lbl['bundle_qty'] = $remaining_pieces;
                    $lbl['bundle_id'] = format_bundle_identifier($cleanCode, $remaining_pieces);
                    $lbl['pack_string'] = "EXTRA PCS \xc3\x97 {$remaining_pieces} NOS";
                    $labels_to_print[] = $lbl;
                }
            } elseif ($label_type === 'bundle') {
                // Generate labels for full bundles (or 1 partial bundle if entire order has 0 full bundles)
                if ($full_bundles > 0) {
                    for ($b = 1; $b <= $full_bundles; $b++) {
                        $lbl = $base_label;
                        $lbl['bundle_qty'] = $std_ppb;
                        $lbl['bundle_id'] = format_bundle_identifier($cleanCode, $std_ppb);
                        $lbl['pack_string'] = "1 BDLS \xc3\x97 {$std_ppb} NOS";
                        $labels_to_print[] = $lbl;
                    }
                } elseif ($order_total_full_bundles === 0 && $partial_bundles > 0) {
                    $lbl = $base_label;
                    $lbl['bundle_qty'] = $remaining_pieces;
                    $lbl['bundle_id'] = format_bundle_identifier($cleanCode, $remaining_pieces);
                    $lbl['pack_string'] = "PARTIAL BDL \xc3\x97 {$remaining_pieces} NOS";
                    $labels_to_print[] = $lbl;
                }
            } else {
                // All labels: full bundles + extra pieces if any
                if ($full_bundles > 0) {
                    for ($b = 1; $b <= $full_bundles; $b++) {
                        $lbl = $base_label;
                        $lbl['bundle_qty'] = $std_ppb;
                        $lbl['bundle_id'] = format_bundle_identifier($cleanCode, $std_ppb);
                        $lbl['pack_string'] = "1 BDLS \xc3\x97 {$std_ppb} NOS";
                        $labels_to_print[] = $lbl;
                    }
                } elseif ($order_total_full_bundles === 0 && $partial_bundles > 0) {
                    $lbl = $base_label;
                    $lbl['bundle_qty'] = $remaining_pieces;
                    $lbl['bundle_id'] = format_bundle_identifier($cleanCode, $remaining_pieces);
                    $lbl['pack_string'] = "PARTIAL BDL \xc3\x97 {$remaining_pieces} NOS";
                    $labels_to_print[] = $lbl;
                }
                if ($remaining_pieces > 0 && $full_bundles > 0) {
                    $lbl = $base_label;
                    $lbl['bundle_qty'] = $remaining_pieces;
                    $lbl['bundle_id'] = format_bundle_identifier($cleanCode, $remaining_pieces);
                    $lbl['pack_string'] = "EXTRA PCS \xc3\x97 {$remaining_pieces} NOS";
                    $labels_to_print[] = $lbl;
                }
            }
        }

    }
}

// ═══════════════════════════════════════════════════════════════════════════
// CASE B: Standard Product/Variant Label Printing (No Order Specified)
// ═══════════════════════════════════════════════════════════════════════════
if (empty($labels_to_print) && $order_id === 0) {
    $variants_to_print = [];

    if ($selected_variant_id > 0) {
        $varSql = "
            SELECT pv.*, p.name AS product_name, p.pages AS prod_pages, p.std_qty AS prod_std_qty, p.brand_name
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE pv.id = ?
        ";
        $varParams = [$selected_variant_id];
        if ($selected_product_id > 0) {
            $varSql .= " AND pv.product_id = ?";
            $varParams[] = $selected_product_id;
        }
        $stmt = $db->prepare($varSql);
        $stmt->execute($varParams);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($v) {
            $variants_to_print[] = $v;
            $selected_product_id = (int)$v['product_id'];
        }
    }

    if (empty($variants_to_print) && $selected_code !== '') {
        $altCode = str_replace(' ', '+', $selected_code);
        $stmt = $db->prepare("
            SELECT pv.*, p.name AS product_name, p.pages AS prod_pages, p.std_qty AS prod_std_qty, p.brand_name
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE pv.product_code = ? OR pv.product_code = ?
        ");
        $stmt->execute([$selected_code, $altCode]);
        $v = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($v) {
            $variants_to_print[] = $v;
        } else {
            // Check if shorthand alias like PWR-192-SR
            if (preg_match('/^PWR-(\d+)-([A-Z]+)$/i', $selected_code, $mCodeMatch)) {
                $aliasStmt = $db->prepare("
                    SELECT pv.*, p.name AS product_name, p.pages AS prod_pages, p.std_qty AS prod_std_qty, p.brand_name
                    FROM product_variants pv
                    JOIN products p ON pv.product_id = p.id
                    WHERE pv.product_code LIKE ? AND pv.variety = ?
                    LIMIT 1
                ");
                $aliasStmt->execute(['%POW%' . $mCodeMatch[1] . '%', strtoupper($mCodeMatch[2])]);
                $v = $aliasStmt->fetch(PDO::FETCH_ASSOC);
                if ($v) {
                    $v['product_code'] = strtoupper($selected_code);
                    $variants_to_print[] = $v;
                }
            }

            if (empty($variants_to_print)) {
                // Check products table by SKU
                $stmtP = $db->prepare("SELECT * FROM products WHERE sku = ? OR sku = ? LIMIT 1");
                $stmtP->execute([$selected_code, $altCode]);
                $pRow = $stmtP->fetch(PDO::FETCH_ASSOC);
                if ($pRow) {
                    $variants_to_print[] = [
                        'id' => 0,
                        'product_id' => $pRow['id'],
                        'product_code' => $pRow['sku'],
                        'product_name' => $pRow['name'],
                        'prod_pages' => $pRow['pages'] ?? 192,
                        'page_count' => $pRow['pages'] ?? 192,
                        'variety' => !empty($pRow['ruling_option']) && $pRow['ruling_option'] !== 'Both' ? $pRow['ruling_option'] : 'SR',
                        'variety_display' => !empty($pRow['ruling_option']) && $pRow['ruling_option'] !== 'Both' ? $pRow['ruling_option'] : 'Ruled',
                        'bundle_qty' => $pRow['std_qty'] ?? 400
                    ];
                }
            }
        }
    }

    if (empty($variants_to_print) && $selected_product_id > 0) {
        $stmt = $db->prepare("
            SELECT pv.*, p.name AS product_name, p.pages AS prod_pages, p.std_qty AS prod_std_qty, p.brand_name
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE pv.product_id = ?
            ORDER BY pv.id ASC
        ");
        $stmt->execute([$selected_product_id]);
        $variants_to_print = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($variants_to_print)) {
            $stmtP = $db->prepare("SELECT * FROM products WHERE id = ?");
            $stmtP->execute([$selected_product_id]);
            $pRow = $stmtP->fetch(PDO::FETCH_ASSOC);
            if ($pRow) {
                $variants_to_print[] = [
                    'id' => 0,
                    'product_id' => $pRow['id'],
                    'product_code' => !empty($pRow['sku']) ? $pRow['sku'] : ('RP-PROD-' . $pRow['id']),
                    'product_name' => $pRow['name'],
                    'prod_pages' => $pRow['pages'] ?? 192,
                    'page_count' => $pRow['pages'] ?? 192,
                    'variety' => !empty($pRow['ruling_option']) && $pRow['ruling_option'] !== 'Both' ? $pRow['ruling_option'] : 'SR',
                    'variety_display' => !empty($pRow['ruling_option']) && $pRow['ruling_option'] !== 'Both' ? $pRow['ruling_option'] : 'Ruled',
                    'bundle_qty' => $pRow['std_qty'] ?? 400
                ];
            }
        }
    }
    
    if (empty($variants_to_print) && $search !== '') {
        $term = "%{$search}%";
        $stmt = $db->prepare("
            SELECT pv.*, p.name AS product_name, p.pages AS prod_pages, p.std_qty AS prod_std_qty, p.brand_name
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE pv.product_code LIKE ? 
               OR p.name LIKE ? 
               OR p.sku LIKE ? 
               OR p.brand_name LIKE ? 
               OR pv.variety LIKE ? 
               OR pv.variety_display LIKE ? 
               OR pv.page_count LIKE ?
            ORDER BY pv.id ASC
            LIMIT 40
        ");
        $stmt->execute([$term, $term, $term, $term, $term, $term, $term]);
        $variants_to_print = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($variants_to_print)) {
        // Default: Load first variants of active products
        $stmt = $db->query("
            SELECT pv.*, p.name AS product_name, p.pages AS prod_pages, p.std_qty AS prod_std_qty, p.brand_name
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            ORDER BY p.id ASC, pv.id ASC
            LIMIT 8
        ");
        $variants_to_print = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    foreach ($variants_to_print as $v) {
        $vDisplay = !empty($v['variety_display']) ? $v['variety_display'] : (!empty($v['variety']) ? $v['variety'] : 'Ruled');
        if (strcasecmp($vDisplay, 'Micro Point') === 0) {
            $vDisplay = 'Maths Plain';
        }
        $vCode = !empty($v['variety']) ? strtoupper(trim($v['variety'])) : '';
        if (empty($vCode) || strlen($vCode) > 4) {
            $checkCode = !empty($v['product_code']) ? $v['product_code'] : '';
            if (preg_match('/-([A-Z]{2,3})$/i', $checkCode, $mCode)) {
                $vCode = strtoupper($mCode[1]);
            }
        }
        if (empty($vCode)) {
            $vCode = get_variant_code_for_variety($vDisplay);
        }

        $rulingDisplay = !empty($vCode) && stripos($vDisplay, $vCode) === false ? "{$vDisplay} ({$vCode})" : $vDisplay;
        $rulingDisplay = strtoupper($rulingDisplay);

        $pPagesRaw = !empty($v['page_count']) ? $v['page_count'] : (!empty($v['prod_pages']) ? $v['prod_pages'] : '192');
        $cleanPages = preg_replace('/[^0-9]/', '', (string)$pPagesRaw) ?: '192';
        $pPagesFmt = $cleanPages . ' Pages';

        $prodName = !empty($v['product_name']) ? $v['product_name'] : 'RAJPRIYA NOTEBOOK';
        $pCode = !empty($v['product_code']) ? $v['product_code'] : 'CODE MISSING';

        $piecesPerBundle = $custom_bundle_qty > 0 ? $custom_bundle_qty : (!empty($v['bundle_qty']) ? (int)$v['bundle_qty'] : (!empty($v['prod_std_qty']) ? (int)$v['prod_std_qty'] : (!empty($v['std_qty']) ? (int)$v['std_qty'] : 400)));
        if ($piecesPerBundle <= 0) $piecesPerBundle = 400;

        $productTitle = format_bundle_label_product_title($prodName, $cleanPages, $vDisplay, $vCode);
        $cleanCode = ($code_format === 'clean') ? get_variant_shorthand_code($pCode, $prodName, $cleanPages, $vCode) : $pCode;
        $bundleId = format_bundle_identifier($cleanCode, $piecesPerBundle);

        $labels_to_print[] = [
            'is_order_label' => false,
            'item_id' => 0,
            'variant_id' => (int)($v['id'] ?? 0),
            'product_id' => (int)($v['product_id'] ?? 0),
            'product_name' => $prodName,
            'product_code' => $cleanCode,
            'raw_code' => $pCode,
            'bundle_id' => $bundleId,
            'page_count' => $pPagesFmt,
            'pages_clean' => $cleanPages,
            'variety' => $vDisplay,
            'variety_code' => $vCode,
            'ruling_display' => $rulingDisplay,
            'bundle_qty' => $piecesPerBundle,
            'product_title' => $productTitle
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <link rel="icon" href="<?php echo htmlspecialchars(get_site_url() . '/' . ltrim($logos['primary'], '/'), ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Raj Priya Notebooks — Production Bundle Label Printer</title>
  
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  
  <style>
    :root {
      --rp-red: #D31E28;
      --rp-navy: #0F2C59;
      --rp-gold: #C89B3C;
      --rp-green-bg: #DCF3DD;
      --rp-green-border: #6EBA7A;
      --rp-table-lbl: #EAF2F8;
      --bg: #F8FAFC;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       SCREEN STYLES
       ═══════════════════════════════════════════════════════════════════════ */
    @media screen {
      body {
        background-color: var(--bg);
        font-family: Arial, Helvetica, sans-serif;
        padding-bottom: 70px;
        color: #1E293B;
      }
      .no-print-bar {
        background: linear-gradient(115deg, #0F2C59 0%, #17243A 100%);
        color: #FFFFFF;
        padding: 14px 24px 16px;
        margin-bottom: 18px;
        border-bottom: 3px solid #C89B3C;
        box-shadow: 0 4px 15px rgba(0,0,0,0.15);
      }
      .no-print-bar .container {
        max-width: 1600px;
      }
      .no-print-bar .btn {
        border-radius: 8px;
        transition: background-color 0.18s ease, color 0.18s ease, border-color 0.18s ease;
      }
      .container.no-print > .bg-white {
        border-radius: 14px;
        border-color: #E2E8F0;
        box-shadow: 0 4px 14px rgba(15,44,89,0.06);
      }
      .container.no-print .form-control:focus,
      .container.no-print .form-select:focus {
        border-color: #C89B3C;
        box-shadow: 0 0 0 3px rgba(200,155,60,0.16);
      }
      .admin-label-wrapper {
        background: #FFFFFF;
        border: 1px solid #E2E8F0;
        border-radius: 14px;
        padding: 14px;
        margin-bottom: 24px;
        box-shadow: 0 3px 10px rgba(15,44,89,0.06);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
      }
      .admin-label-wrapper:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(15,44,89,0.12);
      }
      .admin-label-screen-bar {
        border-bottom: 1px dashed #CBD5E1;
        padding-bottom: 8px;
        margin-bottom: 12px;
      }
      .btn-gold {
        background-color: #C89B3C;
        color: #0F2C59;
        border-color: #C89B3C;
      }
      .btn-gold:hover {
        background-color: #B5882B;
        color: #0F2C59;
      }

      /* Screen Representation of the Reference Image Bundle Label */
      .bundle-label-card-container {
        box-sizing: border-box;
        width: 530px;
        max-width: 100%;
        padding: 12px 14px;
        border: 3.5px solid #000000;
        border-radius: 18px;
        background: #FFFFFF;
        display: flex;
        flex-direction: column;
        margin: 0 auto;
        box-shadow: 0 4px 14px rgba(0,0,0,0.08);
      }
    }

    /* ═══════════════════════════════════════════════════════════════════════
       BUNDLE LABEL DESIGN (EXACT MATCH FOR USER REFERENCE IMAGE)
       ═══════════════════════════════════════════════════════════════════════ */
    .bundle-label-card-container {
      box-sizing: border-box;
      background: #FFFFFF;
      color: #000000;
      font-family: Arial, Helvetica, sans-serif;
    }

    .bl-brand-header {
      text-align: center;
      margin-bottom: 6px;
      position: relative;
    }
    .bl-brand-title {
      font-family: 'Times New Roman', Times, 'Georgia', serif;
      font-size: 26px;
      font-weight: 900;
      color: var(--rp-red);
      letter-spacing: 1.5px;
      line-height: 1.05;
      text-transform: uppercase;
      display: inline-block;
      position: relative;
    }
    .bl-reg-mark {
      font-size: 13px;
      font-family: Arial, sans-serif;
      font-weight: 700;
      vertical-align: super;
      margin-left: 1px;
    }
    .bl-brand-sub-bar {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-top: 2px;
      margin-bottom: 2px;
    }
    .bl-brand-sub-line {
      flex: 1 1 auto;
      height: 2px;
      background-color: var(--rp-red);
      max-width: 80px;
    }
    .bl-brand-sub-text {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 13px;
      font-weight: 900;
      color: var(--rp-navy);
      letter-spacing: 2px;
      text-transform: uppercase;
      white-space: nowrap;
    }
    .bl-brand-tagline {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 9px;
      font-weight: 800;
      color: var(--rp-navy);
      letter-spacing: 1.6px;
      text-transform: uppercase;
      margin-top: 1px;
    }
    /* Salem Contact Box in Header */
    .bl-contact-box {
      position: absolute;
      right: 2px;
      top: 50%;
      transform: translateY(-50%);
      background-color: var(--rp-red);
      color: #FFFFFF !important;
      text-decoration: none;
      border-radius: 7px;
      padding: 4px 10px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      box-shadow: 0 1px 4px rgba(0,0,0,0.15);
      transition: transform 0.15s ease, box-shadow 0.15s ease;
      white-space: nowrap;
      -webkit-print-color-adjust: exact !important;
      print-color-adjust: exact !important;
    }
    .bl-contact-box:hover {
      transform: translateY(-50%) scale(1.03);
      box-shadow: 0 3px 8px rgba(211,30,40,0.3);
      color: #FFFFFF !important;
    }
    .bl-contact-city {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 10px;
      font-weight: 900;
      letter-spacing: 1.8px;
      text-transform: uppercase;
      line-height: 1;
      color: #FFFFFF !important;
    }
    .bl-contact-phone {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.5px;
      line-height: 1.15;
      color: #FFFFFF !important;
      display: inline-flex;
      align-items: center;
      gap: 3px;
      margin-top: 2px;
      white-space: nowrap;
    }
    .bl-contact-phone svg {
      width: 10px;
      height: 10px;
      fill: currentColor;
      flex-shrink: 0;
    }

    /* B. Product Title Bar */
    .bl-product-title-bar {
      background-color: var(--rp-navy);
      color: #FFFFFF;
      border-radius: 6px;
      padding: 6px 10px;
      text-align: center;
      font-family: Arial, Helvetica, sans-serif;
      font-size: 17px;
      font-weight: 800;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      line-height: 1.15;
      margin-bottom: 8px;
      word-break: break-word;
    }

    /* C & D & E. Two Column Body Grid */
    .bl-body-grid {
      display: grid;
      grid-template-columns: 57% 41%;
      gap: 2%;
      align-items: stretch;
      width: 100%;
    }

    /* Left Column: Product Info Table + Barcode */
    .bl-left-col {
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      width: 100%;
      min-width: 0;
    }

    /* Product Info Table */
    .bl-info-table {
      width: 100%;
      border-collapse: collapse;
      border: 1.5px solid #000000;
      margin-bottom: 6px;
    }
    .bl-info-table tr {
      border-bottom: 1px solid #777777;
    }
    .bl-info-table tr:last-child {
      border-bottom: none;
    }
    .bl-tbl-lbl {
      background-color: var(--rp-table-lbl);
      border-right: 1px solid #777777;
      font-size: 11px;
      font-weight: 800;
      color: #000000;
      padding: 3px 6px;
      white-space: nowrap;
      width: 32%;
      line-height: 1.2;
    }
    .bl-tbl-val {
      background-color: #FFFFFF;
      font-size: 11px;
      font-weight: 800;
      color: #000000;
      padding: 3px 6px;
      line-height: 1.2;
      word-break: break-all;
    }

    /* Barcode Section */
    .bl-barcode-wrapper {
      display: flex;
      flex-direction: column;
      width: 100%;
      margin-top: auto;
    }
    .bl-barcode-box {
      border: 1.5px solid #000000;
      background: #FFFFFF;
      padding: 3px 4px 2px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      border-radius: 2px;
      width: 100%;
      box-sizing: border-box;
    }
    .bl-barcode-svg-wrap {
      width: 100%;
      height: 38px;
      display: flex;
      align-items: center;
      justify-content: center;
      overflow: hidden;
    }
    .bl-barcode-digits {
      font-family: 'Courier New', Courier, monospace, Arial;
      font-size: 9.5px;
      font-weight: 800;
      color: #000000;
      letter-spacing: 1.2px;
      margin-top: 1px;
      line-height: 1.1;
      white-space: nowrap;
      text-transform: uppercase;
    }
    .bl-barcode-caption {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 9.5px;
      font-weight: 800;
      color: #000000;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      margin-top: 3px;
      height: 22px;
      display: flex;
      align-items: center;
      justify-content: center;
      line-height: 1.1;
    }

    /* Right Column: Green Bundle QR Box + Captions */
    .bl-right-col {
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      width: 100%;
      min-width: 0;
      text-align: center;
    }
    .bl-qr-green-box {
      background-color: var(--rp-green-bg);
      border: 1.5px solid var(--rp-green-border);
      border-radius: 12px;
      padding: 5px 6px 4px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: space-between;
      width: 100%;
      box-sizing: border-box;
      flex: 1;
    }
    .bl-qr-header {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 12px;
      font-weight: 900;
      color: #000000;
      letter-spacing: 0.5px;
      text-transform: uppercase;
      line-height: 1.1;
      margin-bottom: 2px;
    }
    .bl-qr-img-frame {
      width: 104px;
      height: 104px;
      background: #FFFFFF;
      padding: 3px;
      border-radius: 4px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 1px auto 2px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    }
    .bl-qr-img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      image-rendering: pixelated;
    }
    .bl-qr-footer-code {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 10px;
      font-weight: 800;
      color: #000000;
      margin-top: 2px;
      line-height: 1.15;
      word-break: break-all;
    }
    .bl-qr-footer-pack {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 10px;
      font-weight: 800;
      color: #000000;
      line-height: 1.15;
      white-space: nowrap;
    }
    .bl-qr-bottom-caption {
      margin-top: 3px;
      height: 22px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      line-height: 1.1;
    }
    .bl-qr-caption-top {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 9.5px;
      font-weight: 800;
      color: #000000;
      text-transform: uppercase;
      letter-spacing: 0.4px;
      line-height: 1.1;
    }
    .bl-qr-caption-sub {
      font-family: Arial, Helvetica, sans-serif;
      font-size: 8.5px;
      font-weight: 800;
      color: #000000;
      text-transform: uppercase;
      letter-spacing: 0.3px;
      line-height: 1.1;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       LEGACY COMPACT DESIGN (PRESERVED FOR BACKWARD COMPATIBILITY)
       ═══════════════════════════════════════════════════════════════════════ */
    .legacy-bundle-qr-card {
      box-sizing: border-box;
      width: 250px;
      min-height: 290px;
      padding: 16px 14px;
      border: 4px solid #000000;
      border-radius: 16px;
      background: #FFFFFF;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: space-between;
      margin: 0 auto;
      box-shadow: 0 4px 14px rgba(0,0,0,0.08);
    }

    /* ═══════════════════════════════════════════════════════════════════════
       DEDICATED PRINT & PDF LAYOUT
       ═══════════════════════════════════════════════════════════════════════ */
    @media print {
      @page {
        size: auto;
        margin: 4mm;
      }
      .no-print,
      .no-print-bar,
      .admin-label-screen-bar,
      header,
      nav,
      footer,
      form,
      .btn,
      .alert {
        display: none !important;
      }
      body {
        background: #FFFFFF !important;
        padding: 0 !important;
        margin: 0 !important;
        color: #000000 !important;
        font-family: Arial, Helvetica, sans-serif !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
      .container, .container-fluid {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        margin: 0 !important;
      }
      .row {
        margin: 0 !important;
        display: flex !important;
        flex-wrap: wrap !important;
      }
      .col-auto, [class*="col-"] {
        padding: 0 !important;
        margin: 0 !important;
        width: auto !important;
        flex: 0 0 auto !important;
      }
      .sticker-print-container {
        display: flex !important;
        flex-wrap: wrap !important;
        gap: 5mm !important;
        padding: 2mm !important;
        justify-content: flex-start !important;
      }
      .admin-label-wrapper {
        border: none !important;
        padding: 0 !important;
        margin: 0 !important;
        box-shadow: none !important;
        background: transparent !important;
      }

      /* Production Physical Bundle Sticker Print Sizing (approx 100mm x 66mm) */
      .bundle-label-card-container {
        box-sizing: border-box !important;
        width: 100mm !important;
        min-height: 66mm !important;
        max-width: 100mm !important;
        padding: 3.5mm 4mm !important;
        border: 2.5pt solid #000000 !important;
        border-radius: 12pt !important;
        background: #FFFFFF !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        box-shadow: none !important;
        margin: 0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }

      .bl-brand-title {
        font-size: 17pt !important;
        color: #D31E28 !important;
        -webkit-print-color-adjust: exact !important;
      }
      .bl-reg-mark {
        font-size: 8pt !important;
      }
      .bl-brand-sub-bar {
        margin: 1mm 0 !important;
      }
      .bl-brand-sub-line {
        background-color: #D31E28 !important;
        -webkit-print-color-adjust: exact !important;
      }
      .bl-brand-sub-text {
        font-size: 8.5pt !important;
        color: #0F2C59 !important;
        -webkit-print-color-adjust: exact !important;
      }
      .bl-brand-tagline {
        font-size: 6.5pt !important;
        color: #0F2C59 !important;
        -webkit-print-color-adjust: exact !important;
      }
      .bl-contact-box {
        position: absolute !important;
        right: 1mm !important;
        top: 50% !important;
        transform: translateY(-50%) !important;
        background-color: #D31E28 !important;
        color: #FFFFFF !important;
        padding: 1.2mm 2.8mm !important;
        border-radius: 3pt !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center !important;
        text-decoration: none !important;
        box-shadow: none !important;
        white-space: nowrap !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
      .bl-contact-city {
        font-size: 6.5pt !important;
        font-weight: 900 !important;
        letter-spacing: 1pt !important;
        text-transform: uppercase !important;
        color: #FFFFFF !important;
        line-height: 1 !important;
        -webkit-print-color-adjust: exact !important;
      }
      .bl-contact-phone {
        font-size: 7.5pt !important;
        font-weight: 800 !important;
        letter-spacing: 0.3pt !important;
        color: #FFFFFF !important;
        line-height: 1.1 !important;
        display: inline-flex !important;
        align-items: center !important;
        gap: 0.8mm !important;
        margin-top: 0.5mm !important;
        white-space: nowrap !important;
        -webkit-print-color-adjust: exact !important;
      }
      .bl-contact-phone svg {
        width: 2.2mm !important;
        height: 2.2mm !important;
        fill: #FFFFFF !important;
        -webkit-print-color-adjust: exact !important;
      }
      .bl-product-title-bar {
        background-color: #0F2C59 !important;
        color: #FFFFFF !important;
        font-size: 11.5pt !important;
        padding: 1.8mm 2mm !important;
        margin-bottom: 2mm !important;
        border-radius: 4pt !important;
        -webkit-print-color-adjust: exact !important;
      }
      .bl-body-grid {
        display: grid !important;
        grid-template-columns: 57% 41% !important;
        gap: 2% !important;
        align-items: stretch !important;
        width: 100% !important;
      }
      .bl-left-col {
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        width: 100% !important;
        min-width: 0 !important;
      }
      .bl-right-col {
        display: flex !important;
        flex-direction: column !important;
        justify-content: space-between !important;
        width: 100% !important;
        min-width: 0 !important;
        text-align: center !important;
      }
      .bl-info-table {
        margin-bottom: 1.2mm !important;
        border: 1pt solid #000000 !important;
      }
      .bl-tbl-lbl {
        background-color: #EAF2F8 !important;
        font-size: 7.5pt !important;
        padding: 0.8mm 1.5mm !important;
        -webkit-print-color-adjust: exact !important;
      }
      .bl-tbl-val {
        font-size: 7.5pt !important;
        padding: 0.8mm 1.5mm !important;
      }
      .bl-barcode-box {
        border: 1pt solid #000000 !important;
        padding: 0.8mm 1.2mm 0.6mm !important;
      }
      .bl-barcode-svg-wrap {
        height: 8mm !important;
      }
      .bl-barcode-digits {
        font-size: 6.8pt !important;
        letter-spacing: 1.2px !important;
        margin-top: 0.6mm !important;
      }
      .bl-barcode-caption {
        font-size: 6.8pt !important;
        margin-top: 0.6mm !important;
        height: 5mm !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
      }
      .bl-qr-green-box {
        background-color: #DCF3DD !important;
        border: 1pt solid #6EBA7A !important;
        border-radius: 8pt !important;
        padding: 1.2mm !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: space-between !important;
        flex: 1 !important;
        -webkit-print-color-adjust: exact !important;
      }
      .bl-qr-header {
        font-size: 8.5pt !important;
        margin-bottom: 0.6mm !important;
      }
      .bl-qr-img-frame {
        width: 24mm !important;
        height: 24mm !important;
        padding: 0.6mm !important;
        margin: 0.4mm auto !important;
      }
      .bl-qr-img {
        width: 100% !important;
        height: 100% !important;
        -webkit-print-color-adjust: exact !important;
      }
      .bl-qr-footer-code,
      .bl-qr-footer-pack {
        font-size: 7.2pt !important;
      }
      .bl-qr-bottom-caption {
        margin-top: 0.6mm !important;
        height: 5mm !important;
        display: flex !important;
        flex-direction: column !important;
        align-items: center !important;
        justify-content: center !important;
      }
      .bl-qr-caption-top {
        font-size: 6.8pt !important;
      }
      .bl-qr-caption-sub {
        font-size: 6pt !important;
      }

      /* Legacy Print Dimensions */
      .legacy-bundle-qr-card {
        box-sizing: border-box !important;
        width: 68mm !important;
        min-height: 78mm !important;
        max-width: 68mm !important;
        padding: 4.5mm 3.5mm !important;
        border: 3.5pt solid #000000 !important;
        border-radius: 12pt !important;
        page-break-inside: avoid !important;
        margin: 0 !important;
      }
    }
  </style>
</head>
<body>

<!-- Top Navigation & Print Controls (Screen Only) -->
<div class="no-print no-print-bar">
  <div class="container d-flex flex-wrap align-items-center justify-content-between gap-3">
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <?php if (!empty($order_data)): ?>
        <a href="orders.php?view=<?php echo $order_id; ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Order #<?php echo htmlspecialchars($order_data['order_number'] ?: $order_id); ?></a>
        <h5 class="fw-bold mb-0 text-white"><i class="bi bi-box-seam text-gold me-2"></i> Order Bundle Labels</h5>
      <?php else: ?>
        <a href="stock.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Stock</a>
        <h5 class="fw-bold mb-0 text-white"><i class="bi bi-printer text-gold me-2"></i> Raj Priya Bundle Label Printer</h5>
      <?php endif; ?>
    </div>
    
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <!-- Layout Style Toggle -->
      <div class="btn-group btn-group-sm" role="group">
        <a href="?<?php echo http_build_query(array_merge($_GET, ['layout' => 'reference'])); ?>" class="btn <?php echo $layout_style === 'reference' ? 'btn-gold fw-bold' : 'btn-outline-light'; ?>" title="Official Reference Design: Header + Blue Bar + Table + Barcode + Green QR Section">
          <i class="bi bi-aspect-ratio me-1"></i> Bundle Label (Reference)
        </a>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['layout' => 'legacy'])); ?>" class="btn <?php echo $layout_style === 'legacy' ? 'btn-gold fw-bold' : 'btn-outline-light'; ?>" title="Compact Vertical QR Sticker">
          <i class="bi bi-qr-code me-1"></i> Compact QR
        </a>
      </div>

      <!-- Code Format Toggle -->
      <div class="btn-group btn-group-sm" role="group">
        <a href="?<?php echo http_build_query(array_merge($_GET, ['code_format' => 'clean'])); ?>" class="btn <?php echo $code_format === 'clean' ? 'btn-gold fw-bold' : 'btn-outline-light'; ?>" title="Clean Shorthand Code matching reference image (e.g. PWR-192-SR, PWR-192-SR-400)">
          <i class="bi bi-tag-fill me-1"></i> Clean (PWR-192-SR)
        </a>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['code_format' => 'sku'])); ?>" class="btn <?php echo $code_format === 'sku' ? 'btn-gold fw-bold' : 'btn-outline-light'; ?>" title="Full SKU Code from Database (e.g. RP-OCT26-POW-192-SR)">
          <i class="bi bi-upc-scan me-1"></i> Full SKU
        </a>
      </div>

      <!-- QR Mode Switcher -->
      <div class="btn-group btn-group-sm" role="group">
        <a href="?<?php echo http_build_query(array_merge($_GET, ['qr_mode' => 'text'])); ?>" class="btn <?php echo $qr_mode === 'text' ? 'btn-light text-dark fw-bold' : 'btn-outline-light'; ?>" title="Direct Text: Product & Bundle details show immediately on phone screen without internet">
          <i class="bi bi-file-text me-1"></i> Direct Text
        </a>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['qr_mode' => 'wifi'])); ?>" class="btn <?php echo $qr_mode === 'wifi' ? 'btn-light text-dark fw-bold' : 'btn-outline-light'; ?>" title="Local Wi-Fi: Scans and opens mobile web page on devices connected to this Wi-Fi">
          <i class="bi bi-wifi me-1"></i> Wi-Fi URL
        </a>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['qr_mode' => 'url'])); ?>" class="btn <?php echo $qr_mode === 'url' ? 'btn-light text-dark fw-bold' : 'btn-outline-light'; ?>" title="Live Domain: Uses rajpriyanotebooks.com">
          <i class="bi bi-globe me-1"></i> Live Domain
        </a>
      </div>

      <!-- Print / PDF Download Button -->
      <button type="button" class="btn btn-success btn-sm fw-bold px-3 shadow-sm" onclick="window.print()">
        <i class="bi bi-printer-fill me-1"></i> Print / Save PDF
      </button>
    </div>
  </div>
</div>

<!-- Filter Toolbar (Screen Only) -->
<div class="container no-print mb-4">
  <div class="bg-white p-3 rounded-3 border shadow-sm">
    <form action="print_label.php" method="GET" class="row g-2 align-items-end">
      <?php if ($qr_mode !== 'text'): ?>
        <input type="hidden" name="qr_mode" value="<?php echo htmlspecialchars($qr_mode); ?>">
      <?php endif; ?>
      <?php if ($layout_style !== 'reference'): ?>
        <input type="hidden" name="layout" value="<?php echo htmlspecialchars($layout_style); ?>">
      <?php endif; ?>
      <?php if ($code_format !== 'clean'): ?>
        <input type="hidden" name="code_format" value="<?php echo htmlspecialchars($code_format); ?>">
      <?php endif; ?>

      <!-- Product Dropdown -->
      <div class="col-lg-3 col-md-4">
        <label class="form-label small fw-semibold text-navy mb-1">Select Product</label>
        <select name="product_id" class="form-select form-select-sm" onchange="if(this.form.variant_id) this.form.variant_id.value='0'; this.form.submit()">
          <option value="0">-- All Products / Choose --</option>
          <?php foreach ($products_list as $p): ?>
            <option value="<?php echo $p['id']; ?>" <?php echo ($selected_product_id == $p['id']) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($p['name']); ?> (<?php echo $p['pages']; ?> Pages)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Variant Dropdown (if product selected) -->
      <?php if (!empty($product_variants_dropdown)): ?>
        <div class="col-lg-3 col-md-4">
          <label class="form-label small fw-semibold text-navy mb-1">Select Variety</label>
          <select name="variant_id" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="0">-- All Varieties (<?php echo count($product_variants_dropdown); ?>) --</option>
            <?php foreach ($product_variants_dropdown as $pv): ?>
              <option value="<?php echo $pv['id']; ?>" <?php echo ($selected_variant_id == $pv['id']) ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($pv['product_code']); ?> — <?php echo htmlspecialchars($pv['variety_display'] ?: $pv['variety']); ?> (<?php echo $pv['bundle_qty']; ?>/bdl)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>

      <!-- Search Box -->
      <div class="<?php echo !empty($product_variants_dropdown) ? 'col-lg-2 col-md-4' : 'col-lg-4 col-md-4'; ?>">
        <label class="form-label small fw-semibold text-navy mb-1">Search Code / Variety</label>
        <input type="text" name="search" class="form-control form-control-sm" placeholder="e.g. PWR-192, SR, Ruled, 400" value="<?php echo htmlspecialchars($search); ?>">
      </div>

      <!-- Pack Qty Override -->
      <div class="col-lg-2 col-md-2">
        <label class="form-label small fw-semibold text-navy mb-1" title="Override Pack Quantity on label (Leave blank to use actual DB pack size)">Pack NOS (Qty)</label>
        <input type="number" name="bundle_qty" value="<?php echo $custom_bundle_qty > 0 ? $custom_bundle_qty : ''; ?>" placeholder="Default DB" min="1" max="5000" class="form-control form-control-sm text-center">
      </div>

      <!-- Copies -->
      <div class="col-lg-1 col-md-2">
        <label class="form-label small fw-semibold text-navy mb-1">Copies</label>
        <input type="number" name="copies" value="<?php echo $copies; ?>" min="1" max="100" class="form-control form-control-sm text-center fw-bold">
      </div>

      <!-- Submit & Reset -->
      <div class="col-lg-1 col-md-12 d-flex gap-1">
        <button type="submit" class="btn text-white btn-sm w-100 fw-bold" style="background-color: #0F2C59;">
          <i class="bi bi-search"></i>
        </button>
        <?php if ($search !== '' || $selected_product_id > 0 || $order_id > 0 || $selected_code !== '' || $selected_variant_id > 0 || $custom_bundle_qty > 0): ?>
          <a href="print_label.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters">
            <i class="bi bi-arrow-counterclockwise"></i>
          </a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- PRINTABLE LABELS CONTAINER -->
<div class="container">
  <?php if (empty($labels_to_print)): ?>
    <div class="text-center py-5 no-print">
      <div class="bg-white p-4 rounded-3 border shadow-sm mx-auto" style="max-width: 480px;">
        <i class="bi bi-box-seam text-secondary fs-1 mb-2 d-block"></i>
        <h5 class="fw-bold text-navy mb-2">No Bundle Labels Found</h5>
        <p class="text-muted small mb-3">No products or variants matched your selection. Please select a product from the dropdown above or click below to view all.</p>
        <a href="print_label.php" class="btn btn-sm text-white px-3 fw-bold" style="background-color: #0F2C59;">View All Product Bundle Labels</a>
      </div>
    </div>
  <?php else: ?>
    <!-- Live Label Grid -->
    <div class="sticker-print-container row justify-content-center">
      <?php 
        foreach ($labels_to_print as $index => $label):
          for ($c = 0; $c < $copies; $c++):
            $pName = !empty($label['product_name']) ? $label['product_name'] : 'RAJPRIYA NOTEBOOK';
            $pCode = !empty($label['product_code']) ? $label['product_code'] : (!empty($label['raw_code']) ? $label['raw_code'] : 'RP-ITEM');
            $bundleQty = !empty($label['bundle_qty']) ? (int)$label['bundle_qty'] : 400;
            $bundleId = !empty($label['bundle_id']) ? $label['bundle_id'] : format_bundle_identifier($pCode, $bundleQty);
            $pPages = !empty($label['page_count']) ? $label['page_count'] : (!empty($label['pages_clean']) ? ($label['pages_clean'] . ' Pages') : '');
            $rulingDisplay = !empty($label['ruling_display']) ? $label['ruling_display'] : (!empty($label['variety']) ? $label['variety'] : 'RULED');
            $packString = !empty($label['pack_string']) ? $label['pack_string'] : ("1 BDLS \xc3\x97 {$bundleQty} NOS"); // 1 BDLS × 400 NOS
            $productTitle = !empty($label['product_title']) ? $label['product_title'] : format_bundle_label_product_title($pName, $label['pages_clean'] ?? $pPages, $label['variety'] ?? $rulingDisplay, $label['variety_code'] ?? '');

            // Consistent Product Barcode: PWR192SR40001 (Preserves format while ensuring 100% consistent Tally scanner recognition across all printed copies)
            $barcodeVal = format_bundle_barcode_value($pCode, $bundleQty, 1);
            $barcodeSvg = generate_code128_svg($barcodeVal, 40);

            // Spaced representation for human readable text below barcode
            $spacedBarcodeVal = implode(' ', str_split($barcodeVal));

            // QR Destinations
            $mobileWebUrl = get_product_deployed_qr_url($pCode, $bundleQty, $bundleId);
            $wifiWebUrl   = get_product_wifi_qr_url($pCode, $bundleQty, $bundleId);
            $directText   = get_bundle_qr_text_payload($pName, $pCode, $bundleId, $pPages, $rulingDisplay, $bundleQty);

            if ($qr_mode === 'wifi') {
                $activeQrPayload = $wifiWebUrl;
            } elseif ($qr_mode === 'url') {
                $activeQrPayload = $mobileWebUrl;
            } else {
                $activeQrPayload = $directText;
            }

            $qrImgUrl = generate_qr_code_image_url($activeQrPayload, 300);
            $cardDomId = 'labelCard_' . ($label['item_id'] ?: ($label['variant_id'] ?: $index)) . '_' . $c;
      ?>
        <div class="col-auto mb-4">
          <div class="admin-label-wrapper">
            <!-- Screen Only Info Bar -->
            <div class="admin-label-screen-bar no-print">
              <div class="d-flex align-items-center justify-content-between mb-1">
                <span class="badge bg-light text-dark border font-monospace" style="font-size: 0.75rem;">
                  ID: <?php echo htmlspecialchars($pCode); ?> &bull; Pack: <?php echo $bundleQty; ?> NOS
                </span>
                <div class="d-flex gap-1">
                  <a href="<?php echo htmlspecialchars(get_product_local_preview_url($pCode, $bundleQty, $bundleId, $label['product_id'] ?? 0, $label['variant_id'] ?? 0)); ?>" class="btn btn-outline-primary btn-sm py-0 px-1.5" style="font-size: 0.68rem;" title="Test Local Mobile Verification Page">
                    <i class="bi bi-eye me-0.5"></i> Test Preview
                  </a>
                  <button type="button" class="btn btn-outline-dark btn-sm py-0 px-1.5" style="font-size: 0.68rem;" onclick="printSingleLabel('<?php echo $cardDomId; ?>')" title="Print Only This Bundle Label">
                    <i class="bi bi-printer"></i> Print
                  </button>
                </div>
              </div>
            </div>

            <?php if ($layout_style === 'reference'): ?>
              <!-- ═══════════════════════════════════════════════════════════════ -->
              <!-- OFFICIAL RAJ PRIYA BUNDLE LABEL (REFERENCE IMAGE LAYOUT)       -->
              <!-- ═══════════════════════════════════════════════════════════════ -->
              <div class="bundle-label-card-container" id="<?php echo $cardDomId; ?>">
                
                <!-- A. Brand Header -->
                <div class="bl-brand-header">
                  <div class="bl-brand-title">
                    RAJ PRIYA<span class="bl-reg-mark">&reg;</span>
                  </div>
                  <div class="bl-brand-sub-bar">
                    <span class="bl-brand-sub-line"></span>
                    <span class="bl-brand-sub-text">NOTE BOOKS</span>
                    <span class="bl-brand-sub-line"></span>
                  </div>
                  <div class="bl-brand-tagline">
                    QUALITY WRITES A BRIGHTER TOMORROW
                  </div>
                  <a href="tel:9443346472" class="bl-contact-box" title="Salem Contact: 9443346472">
                    <span class="bl-contact-city">SALEM</span>
                    <span class="bl-contact-phone">
                      <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" fill="currentColor" viewBox="0 0 16 16"><path fill-rule="evenodd" d="M1.885.511a1.745 1.745 0 0 1 2.61.163L6.29 2.98c.329.423.445.974.315 1.494l-.547 2.19a.678.678 0 0 0 .178.643l2.457 2.457a.678.678 0 0 0 .644.178l2.189-.547a1.745 1.745 0 0 1 1.494.315l2.306 1.794c.829.645.905 1.87.163 2.611l-1.034 1.034c-.74.74-1.846 1.065-2.877.702a18.634 18.634 0 0 1-7.01-4.42 18.634 18.634 0 0 1-4.42-7.009c-.362-1.03-.037-2.137.703-2.877L1.885.511z"/></svg>
                      9443346472
                    </span>
                  </a>
                </div>

                <!-- B. Product Title Bar -->
                <div class="bl-product-title-bar">
                  <?php echo htmlspecialchars($productTitle); ?>
                </div>

                <!-- C, D, E. Main Content Body -->
                <div class="bl-body-grid">
                  
                  <!-- Left Column: Product Information Table + Barcode -->
                  <div class="bl-left-col">
                    
                    <!-- Product Information Table -->
                    <table class="bl-info-table">
                      <tbody>
                        <tr>
                          <td class="bl-tbl-lbl">Product ID</td>
                          <td class="bl-tbl-val"><?php echo htmlspecialchars($pCode); ?></td>
                        </tr>
                        <tr>
                          <td class="bl-tbl-lbl">Bundle ID</td>
                          <td class="bl-tbl-val"><?php echo htmlspecialchars($bundleId); ?></td>
                        </tr>
                        <tr>
                          <td class="bl-tbl-lbl">Pages</td>
                          <td class="bl-tbl-val"><?php echo htmlspecialchars($pPages); ?></td>
                        </tr>
                        <tr>
                          <td class="bl-tbl-lbl">Ruling</td>
                          <td class="bl-tbl-val"><?php echo htmlspecialchars($rulingDisplay); ?></td>
                        </tr>
                        <tr>
                          <td class="bl-tbl-lbl">Pack</td>
                          <td class="bl-tbl-val"><?php echo htmlspecialchars($packString); ?></td>
                        </tr>
                      </tbody>
                    </table>

                    <!-- D. Product Barcode -->
                    <div class="bl-barcode-wrapper">
                      <div class="bl-barcode-box">
                        <div class="bl-barcode-svg-wrap">
                          <?php echo $barcodeSvg; ?>
                        </div>
                        <div class="bl-barcode-digits">
                          <?php echo htmlspecialchars($spacedBarcodeVal); ?>
                        </div>
                      </div>
                      <div class="bl-barcode-caption">
                        PRODUCT BARCODE
                      </div>
                    </div>

                  </div>

                  <!-- Right Column: Green Bundle QR Section -->
                  <div class="bl-right-col">
                    <div class="bl-qr-green-box">
                      <div class="bl-qr-header">
                        BUNDLE QTY
                      </div>
                      <div class="bl-qr-img-frame">
                        <img src="<?php echo htmlspecialchars($qrImgUrl); ?>" 
                             alt="QR Code for <?php echo htmlspecialchars($bundleId); ?>" 
                             class="bl-qr-img" 
                             loading="eager">
                      </div>
                      <div class="bl-qr-footer-code">
                        <?php echo htmlspecialchars($pCode); ?>
                      </div>
                      <div class="bl-qr-footer-pack">
                        1 BDLS = <?php echo $bundleQty; ?> NOS
                      </div>
                    </div>

                    <div class="bl-qr-bottom-caption">
                      <div class="bl-qr-caption-top">BUNDLE QR CODE</div>
                      <div class="bl-qr-caption-sub">(PRODUCT DETAILS + QTY)</div>
                    </div>
                  </div>

                </div>

              </div>
              <!-- ═══════════════════════════════════════════════════════════════ -->

            <?php else: ?>
              <!-- Legacy Compact Card -->
              <div class="legacy-bundle-qr-card" id="<?php echo $cardDomId; ?>">
                <div class="text-center fw-bold mb-2 fs-6">
                  <?php echo htmlspecialchars($productTitle); ?>
                </div>
                <div style="width: 170px; height: 170px; display: flex; align-items: center; justify-content: center;">
                  <img src="<?php echo htmlspecialchars($qrImgUrl); ?>" alt="QR" style="width: 170px; height: 170px; object-fit: contain;">
                </div>
                <div class="text-center fw-bold mt-2">
                  QTY IN BDLS: 1 X <?php echo $bundleQty; ?>
                </div>
              </div>
            <?php endif; ?>

          </div>
        </div>
      <?php 
          endfor;
        endforeach; 
      ?>
    </div>
  <?php endif; ?>
</div>

<script>
// Print Only A Single Label Card in a Clean Popup
function printSingleLabel(cardDomId) {
  const card = document.getElementById(cardDomId);
  if (!card) return;
  
  const printWin = window.open('', '_blank', 'width=580,height=460');
  if (!printWin) {
    window.print();
    return;
  }
  
  const styles = document.querySelector('style').innerHTML;
  
  printWin.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Print Raj Priya Bundle Label</title>
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
      <style>
        ${styles}
        body {
          margin: 0;
          padding: 6mm;
          display: flex;
          align-items: center;
          justify-content: center;
          background: #FFFFFF;
        }
      </style>
    </head>
    <body onload="window.print(); setTimeout(function(){ window.close(); }, 600);">
      ${card.outerHTML}
    </body>
    </html>
  `);
  printWin.document.close();
}
</script>

</body>
</html>
