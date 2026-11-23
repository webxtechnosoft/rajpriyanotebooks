<?php
/**
 * Official Parcel Dispatch Label & Shipping Sticker Printer
 * 
 * Accurately designed to match the Raj Priya Notebook Official Reference Parcel Label:
 * - Brand Header with Registered Trademark, Tagline, Phone Pill, and Company Catalogue QR
 * - Consignee Section with Storefront Icon, Customer Name, Customer ID, Address, and Code 128 Barcode
 * - Delivery Place (Red Pin) & Transport Carrier (Blue Truck) Badge Cards
 * - Invoice No (Pink), Dispatch Date (Lavender), and Total Bundles (Orange) Badge Cards
 * - Dispatch Invoice List of Items Table with Yellow Header, Product ID, Name, Pages, Variety, Pack, Bundles, NOS
 * - Functional Dynamic "DISPATCH ITEMS QR" linked with HMAC verification to dispatch-details.php
 * 
 * Supports both single-parcel and multi-parcel printing with print-friendly page breaks.
 */

require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();
$logos = get_active_logos();

$parcel_param = $_GET['id'] ?? ($_GET['pcl'] ?? 0);
$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;

// QR Mode: 'wifi' (local Wi-Fi IP for instant phone camera testing), 'live' (production domain), 'local' (localhost)
$qr_mode = isset($_GET['qr_mode']) ? strtolower(trim(sanitize($_GET['qr_mode']))) : 'wifi';
if (!in_array($qr_mode, ['wifi', 'live', 'local'])) {
    $qr_mode = 'wifi';
}

$parcels_to_render = [];
$page_title_suffix = "";

if ($order_id > 0) {
    // ═══════════════════════════════════════════════════════════════════════════
    // Case 1: Print parcels for a specific order
    // ═══════════════════════════════════════════════════════════════════════════
    $stmtOrd = $db->prepare("SELECT * FROM orders WHERE id = ?");
    $stmtOrd->execute([$order_id]);
    $order = $stmtOrd->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo "<div style='padding:40px; text-align:center; font-family:sans-serif;'><h3>Order Not Found</h3><p>The requested order #$order_id does not exist.</p></div>";
        exit();
    }

    $existing_parcels = get_order_parcels($order_id);
    
    // Load all line items for the order
    $stmtItems = $db->prepare("
        SELECT oi.*, p.pages AS prod_pages, p.std_qty AS prod_std_qty, p.image AS prod_image 
        FROM order_items oi 
        LEFT JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id = ? 
        ORDER BY oi.id ASC
    ");
    $stmtItems->execute([$order_id]);
    $order_items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

    $normalized_order_items = [];
    $order_packing = get_order_packing_details($order_id, $order_items);
    $total_pieces_order     = $order_packing['total_ordered_pieces'];
    $total_full_bundles     = $order_packing['total_full_bundles'];
    $total_remaining_pieces = $order_packing['total_remaining_pieces'];
    $total_partial_bundles  = $order_packing['total_partial_bundles'];
    $total_packed_units     = $order_packing['total_packed_units'];
    $total_ordered_bundles  = $total_packed_units;

    // Optional manual parcel override via query parameter
    $override_parcels = isset($_GET['parcels']) && (int)$_GET['parcels'] > 0 
        ? (int)$_GET['parcels'] 
        : (isset($_GET['parcel_count']) && (int)$_GET['parcel_count'] > 0 ? (int)$_GET['parcel_count'] : 0);

    $total_parcels_count = $override_parcels > 0 ? $override_parcels : $order_packing['parcel_count'];

    foreach ($order_packing['items'] as $oi) {
        $vI = parse_variety_info($oi['ruling_type'] ?? '');
        $variety = !empty($oi['variety']) ? $oi['variety'] : (!empty($vI['variety']) ? $vI['variety'] : 'Ruled');
        $pages = !empty($oi['page_count']) ? $oi['page_count'] : (!empty($vI['page_count']) ? preg_replace('/[^0-9]/', '', $vI['page_count']) : (!empty($oi['prod_pages']) ? $oi['prod_pages'] : 192));
        $ppb = $oi['standard_bundle_size'];

        $normalized_order_items[] = [
            'order_item_id' => (int)$oi['id'],
            'product_id' => (int)$oi['product_id'],
            'code' => format_clean_dispatch_item_code($oi['product_code'], $oi['product_name'], $pages, $variety),
            'product_name' => strtoupper(trim((string)$oi['product_name'])),
            'pages' => $pages,
            'variety' => strtoupper(trim((string)$variety)),
            'pack' => "1 × {$ppb}",
            'ppb' => $ppb,
            'bundles' => $oi['packed_units'],
            'full_bundles' => $oi['full_bundles'],
            'remaining_pieces' => $oi['remaining_pieces'],
            'partial_bundles' => $oi['partial_bundles'],
            'total_pieces' => $oi['ordered_pieces']
        ];
    }

    if (!empty($existing_parcels)) {
        $real_total = max(count($existing_parcels), $total_parcels_count);
        foreach ($existing_parcels as $idx => $p) {
            $p['box_index'] = $idx + 1;
            $p['total_parcels_count'] = $real_total;
            $p['order_number'] = $order['order_number'];
            $p['user_id'] = $order['user_id'];
            $p['customer_name'] = $order['customer_name'];
            $p['customer_phone'] = $order['customer_phone'];
            $p['customer_code'] = $order['customer_code'] ?? '';
            $p['shipping_address'] = $order['shipping_address'];
            $p['address_line2'] = $order['address_line2'] ?? '';
            $p['area'] = $order['area'] ?? '';
            $p['city'] = $order['city'];
            $p['district'] = $order['district'] ?? '';
            $p['state'] = $order['state'];
            $p['pincode'] = $order['pincode'];
            $p['landmark'] = $order['landmark'] ?? '';
            $p['gst_number'] = $order['gst_number'] ?? '';
            $p['transport_name'] = !empty($p['transport_name']) ? $p['transport_name'] : ($order['transport_name'] ?? '');
            $p['transport_to'] = !empty($p['transport_to']) ? $p['transport_to'] : ($order['transport_to'] ?? '');
            $p['dispatch_date'] = !empty($p['dispatch_date']) ? $p['dispatch_date'] : (!empty($order['dispatch_date']) ? $order['dispatch_date'] : ($order['transport_date'] ?? null));
            $p['total_ordered_bundles'] = $total_ordered_bundles;

            // Load parcel-specific items from order_parcel_items
            $stmt_pi = $db->prepare("
                SELECT opi.quantity AS parcel_qty, oi.*, p.pages AS prod_pages, p.std_qty AS prod_std_qty
                FROM order_parcel_items opi
                JOIN order_items oi ON opi.order_item_id = oi.id
                LEFT JOIN products p ON oi.product_id = p.id
                WHERE opi.parcel_id = ?
                ORDER BY oi.id ASC
            ");
            $stmt_pi->execute([(int)$p['id']]);
            $raw_p_items = $stmt_pi->fetchAll(PDO::FETCH_ASSOC);

            $p_items = [];
            $p_bundles = 0;
            $p_pieces = 0;

            if (!empty($raw_p_items)) {
                foreach ($raw_p_items as $rpi) {
                    $vI = parse_variety_info($rpi['ruling_type'] ?? '');
                    $variety = !empty($rpi['variety']) ? $rpi['variety'] : (!empty($vI['variety']) ? $vI['variety'] : 'Ruled');
                    $pages = !empty($rpi['page_count']) ? $rpi['page_count'] : (!empty($vI['page_count']) ? preg_replace('/[^0-9]/', '', $vI['page_count']) : (!empty($rpi['prod_pages']) ? $rpi['prod_pages'] : 192));
                    
                    $calc_p = calculate_order_item_packing($rpi, $rpi['parcel_qty']);
                    $ppb = $calc_p['standard_bundle_size'];
                    $bQty = $calc_p['packed_units'];
                    $totPcs = $calc_p['ordered_pieces'];

                    $p_bundles += $bQty;
                    $p_pieces += $totPcs;

                    $p_items[] = [
                        'code' => format_clean_dispatch_item_code($rpi['product_code'], $rpi['product_name'], $pages, $variety),
                        'product_name' => strtoupper(trim((string)$rpi['product_name'])),
                        'pages' => $pages,
                        'variety' => strtoupper(trim((string)$variety)),
                        'pack' => "1 × {$ppb}",
                        'ppb' => $ppb,
                        'bundles' => $bQty,
                        'full_bundles' => $calc_p['full_bundles'],
                        'remaining_pieces' => $calc_p['remaining_pieces'],
                        'partial_bundles' => $calc_p['partial_bundles'],
                        'total_pieces' => $totPcs
                    ];
                }
            } else {
                // Fallback to normalized order items if parcel items empty
                $p_items = $normalized_order_items;
                $p_bundles = $total_ordered_bundles;
                $p_pieces = $total_pieces_order;
            }

            $p['dispatch_items'] = $p_items;
            $p['total_dispatch_bundles'] = $p_bundles;
            $p['total_dispatch_pieces'] = $p_pieces;

            $parcels_to_render[] = $p;
        }
    } else {
        // Synthesize physical parcel view using full order items (1 sticker per parcel)
        for ($i = 1; $i <= $total_parcels_count; $i++) {
            $synth_pcl_num = 'PCL-' . preg_replace('/[^A-Za-z0-9]/', '', $order['order_number']) . '-' . str_pad($i, 2, '0', STR_PAD_LEFT);
            $parcels_to_render[] = [
                'id' => 0,
                'parcel_number' => $synth_pcl_num,
                'order_id' => $order['id'],
                'user_id' => $order['user_id'],
                'box_index' => $i,
                'total_parcels_count' => $total_parcels_count,
                'order_number' => $order['order_number'],
                'customer_name' => $order['customer_name'],
                'customer_phone' => $order['customer_phone'],
                'customer_code' => $order['customer_code'] ?? '',
                'shipping_address' => $order['shipping_address'],
                'address_line2' => $order['address_line2'] ?? '',
                'area' => $order['area'] ?? '',
                'city' => $order['city'],
                'district' => $order['district'] ?? '',
                'state' => $order['state'],
                'pincode' => $order['pincode'],
                'landmark' => $order['landmark'] ?? '',
                'gst_number' => $order['gst_number'] ?? '',
                'transport_name' => $order['transport_name'] ?? '',
                'transport_to' => $order['transport_to'] ?? '',
                'total_ordered_bundles' => $total_ordered_bundles,
                'created_at' => $order['created_at'],
                'dispatch_date' => !empty($order['dispatch_date']) ? $order['dispatch_date'] : ($order['transport_date'] ?? null),
                'dispatch_items' => $normalized_order_items,
                'total_dispatch_bundles' => $total_ordered_bundles,
                'total_dispatch_pieces' => $total_pieces_order
            ];
        }
    }
    $page_title_suffix = "Order #" . ($order['order_number'] ?: $order['id']);
} else {
    // ═══════════════════════════════════════════════════════════════════════════
    // Case 2: Print single physical parcel by ID or Parcel Number
    // ═══════════════════════════════════════════════════════════════════════════
    $parcel = get_parcel_by_number($parcel_param);
    if (!$parcel) {
        echo "<div style='padding:40px; text-align:center; font-family:sans-serif;'><h3>Parcel Not Found</h3><p>The requested parcel identifier is invalid.</p></div>";
        exit();
    }

    $stmtOrd = $db->prepare("SELECT * FROM orders WHERE id = ?");
    $stmtOrd->execute([$parcel['order_id']]);
    $order = $stmtOrd->fetch(PDO::FETCH_ASSOC) ?: [];

    // Map parcel items
    $p_items = [];
    $p_bundles = 0;
    $p_pieces = 0;

    if (!empty($parcel['items'])) {
        foreach ($parcel['items'] as $itm) {
            $vI = parse_variety_info($itm['ruling_type'] ?? '');
            $variety = !empty($itm['variety']) ? $itm['variety'] : (!empty($vI['variety']) ? $vI['variety'] : 'Ruled');
            $pages = !empty($itm['page_count']) ? $itm['page_count'] : (!empty($vI['page_count']) ? preg_replace('/[^0-9]/', '', $vI['page_count']) : (!empty($itm['prod_pages']) ? $itm['prod_pages'] : 192));
            
            $calc_p = calculate_order_item_packing($itm, $itm['quantity']);
            $ppb = $calc_p['standard_bundle_size'];
            $bQty = $calc_p['packed_units'];
            $totPcs = $calc_p['ordered_pieces'];

            $p_bundles += $bQty;
            $p_pieces += $totPcs;

            $p_items[] = [
                'code' => format_clean_dispatch_item_code($itm['product_code'], $itm['product_name'], $pages, $variety),
                'product_name' => strtoupper(trim((string)$itm['product_name'])),
                'pages' => $pages,
                'variety' => strtoupper(trim((string)$variety)),
                'pack' => "1 × {$ppb}",
                'ppb' => $ppb,
                'bundles' => $bQty,
                'full_bundles' => $calc_p['full_bundles'],
                'remaining_pieces' => $calc_p['remaining_pieces'],
                'partial_bundles' => $calc_p['partial_bundles'],
                'total_pieces' => $totPcs
            ];
        }
    }

    $parcel['dispatch_items'] = $p_items;
    $parcel['total_dispatch_bundles'] = $p_bundles;
    $parcel['total_dispatch_pieces'] = $p_pieces;
    $parcel['gst_number'] = $order['gst_number'] ?? '';
    $parcel['dispatch_date'] = !empty($parcel['dispatch_date']) ? $parcel['dispatch_date'] : (!empty($order['dispatch_date']) ? $order['dispatch_date'] : ($order['transport_date'] ?? null));

    $parcels_to_render[] = $parcel;
    $page_title_suffix = htmlspecialchars($parcel['parcel_number']);
}

// Company Catalogue URL for header QR
$catalogue_url = ($qr_mode === 'wifi')
    ? ("http://" . (function_exists('get_server_local_ip') ? get_server_local_ip() : '10.246.34.88') . "/rajpriya/products.php")
    : "https://www.rajpriyanotebooks.com/products.php";
$catalogue_qr_image = generate_qr_code_image_url($catalogue_url, 180);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <link rel="icon" href="<?php echo htmlspecialchars(get_site_url() . '/' . ltrim($logos['primary'], '/'), ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Parcel Shipping Label <?php echo $page_title_suffix; ?> — RAJPRIYA NOTEBOOKS</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

  <style>
    :root {
      --rp-red: #D32F2F;
      --rp-navy: #102A43;
      --rp-blue-bar: #0B72B9;
      --rp-gold: #C89B3C;
    }

    body {
      font-family: 'Outfit', Arial, sans-serif;
      background: #E2E8F0;
      color: #000000;
      margin: 0;
      padding: 24px 12px 60px 12px;
    }

    .screen-toolbar {
      max-width: 580px;
      margin: 0 auto 16px auto;
    }

    .sticker-container {
      display: flex;
      flex-direction: column;
      gap: 24px;
      align-items: center;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       OFFICIAL PARCEL LABEL CARD (MATCHES REFERENCE IMAGE EXACTLY)
       ═══════════════════════════════════════════════════════════════════════ */
    .parcel-label-card {
      width: 100%;
      max-width: 540px;
      background: #FFFFFF;
      border: 1.5px solid #000000;
      border-radius: 12px;
      padding: 14px 14px 16px 14px;
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.10);
      box-sizing: border-box;
      margin: 0 auto;
      position: relative;
    }

    /* Top Brand Header */
    .pl-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      border-bottom: 2px solid #000000;
      padding-bottom: 8px;
      margin-bottom: 10px;
    }

    .pl-brand-block {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
    }

    .pl-brand-title {
      text-align: center;
      line-height: 1.05;
      margin-bottom: 2px;
      width: 100%;
    }

    .pl-brand-rajpriya {
      color: #D32F2F;
      font-family: 'Times New Roman', Times, serif;
      font-size: 34px;
      font-weight: 900;
      letter-spacing: 1px;
      display: inline-block;
      text-transform: uppercase;
    }

    .pl-brand-trademark {
      font-size: 14px;
      color: #000000;
      vertical-align: super;
      font-family: Arial, sans-serif;
      font-weight: bold;
      margin-left: 1px;
    }

    .pl-brand-notebooks-wrap {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      width: 100%;
      margin-top: 1px;
      margin-bottom: 3px;
    }

    .pl-notebooks-line {
      flex: 1;
      max-width: 65px;
      height: 2.5px;
      background: #D32F2F;
    }

    .pl-brand-notebooks {
      color: #102A43;
      font-family: 'Outfit', Arial, sans-serif;
      font-size: 22px;
      font-weight: 900;
      letter-spacing: 2px;
      text-transform: uppercase;
      white-space: nowrap;
      line-height: 1.1;
    }

    .pl-brand-tagline {
      font-size: 11px;
      font-weight: 800;
      letter-spacing: 0.8px;
      color: #000000;
      text-transform: uppercase;
      margin-top: 2px;
      margin-bottom: 3px;
      text-align: center;
      width: 100%;
    }

    .pl-brand-salem-wrap {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      width: 100%;
      margin-top: 2px;
    }

    .pl-salem-line {
      flex: 1;
      max-width: 75px;
      height: 2px;
      background: #D32F2F;
    }

    .pl-salem-text {
      color: #D32F2F;
      font-size: 16px;
      font-weight: 900;
      letter-spacing: 2.5px;
      text-transform: uppercase;
      line-height: 1;
    }

    /* Header Right: QR + Phone Pill */
    .pl-header-right {
      display: flex;
      flex-direction: column;
      align-items: center;
      margin-left: 12px;
    }

    .pl-cat-qr-box {
      text-align: center;
      margin-bottom: 5px;
    }

    .pl-cat-qr-img {
      width: 68px;
      height: 68px;
      display: block;
      image-rendering: pixelated;
      border: 1px solid #000000;
      padding: 1px;
      background: #FFFFFF;
    }

    .pl-cat-qr-caption {
      font-size: 8px;
      font-weight: 700;
      color: #000000;
      line-height: 1.15;
      margin-top: 2px;
      text-align: center;
    }

    .pl-phone-pill {
      background: #D32F2F;
      color: #FFFFFF;
      font-weight: 800;
      font-size: 12px;
      padding: 3px 8px;
      border-radius: 50px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      text-decoration: none;
      white-space: nowrap;
      margin-top: 2px;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       TO (CONSIGNEE) SECTION (BLUE BOX)
       ═══════════════════════════════════════════════════════════════════════ */
    .pl-consignee-box {
      border: 2px solid #0B72B9;
      border-radius: 8px;
      overflow: hidden;
      margin-bottom: 10px;
      background: #FFFFFF;
    }

    .pl-consignee-header {
      background: #0B72B9;
      color: #FFFFFF;
      font-weight: 900;
      font-size: 13px;
      padding: 4px 10px;
      text-transform: uppercase;
      letter-spacing: 0.8px;
    }

    .pl-consignee-body {
      padding: 8px 10px;
      display: flex;
      justify-content: space-between;
      gap: 10px;
    }

    .pl-consignee-left {
      flex: 1;
      display: flex;
      gap: 8px;
    }

    .pl-store-icon {
      color: #0B72B9;
      font-size: 32px;
      line-height: 1;
      flex-shrink: 0;
      margin-top: 2px;
    }

    .pl-customer-name {
      color: #102A43;
      font-size: 17px;
      font-weight: 900;
      text-transform: uppercase;
      line-height: 1.15;
      margin-bottom: 3px;
    }

    .pl-customer-id {
      font-size: 12px;
      font-weight: 800;
      color: #000000;
      margin-bottom: 3px;
    }

    .pl-customer-address {
      font-size: 11.5px;
      color: #000000;
      line-height: 1.35;
      font-weight: 500;
    }

    /* Consignee Right: Barcode */
    .pl-consignee-right {
      width: 170px;
      flex-shrink: 0;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      border-left: 1px dashed #CBD5E1;
      padding-left: 8px;
    }

    .pl-barcode-wrap {
      width: 100%;
      height: 38px;
    }

    .pl-barcode-digits {
      font-family: 'JetBrains Mono', monospace;
      font-size: 12px;
      font-weight: 800;
      color: #000000;
      letter-spacing: 1px;
      margin-top: 1px;
    }

    .pl-barcode-caption {
      font-size: 9px;
      font-weight: 800;
      color: #000000;
      text-transform: capitalize;
    }

    /* Consignee Bottom Strip */
    .pl-consignee-footer {
      border-top: 1.5px solid #0B72B9;
      display: flex;
      background: #F0F9FF;
      font-size: 12px;
      font-weight: 700;
    }

    .pl-footer-cell-phone {
      flex: 1.2;
      padding: 4px 10px;
      border-right: 1.5px solid #0B72B9;
    }

    .pl-footer-cell-gst {
      flex: 1;
      padding: 4px 10px;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       BADGES ROW 1: DELIVERY PLACE & TRANSPORT
       ═══════════════════════════════════════════════════════════════════════ */
    .pl-badges-row-1 {
      display: flex;
      gap: 10px;
      margin-bottom: 8px;
    }

    .pl-badge-delivery {
      flex: 1;
      background: #FFF4E5;
      border: 1.5px solid #000000;
      border-radius: 8px;
      padding: 6px 10px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .pl-badge-transport {
      flex: 1.1;
      background: #E8F8F5;
      border: 1.5px solid #000000;
      border-radius: 8px;
      padding: 6px 10px;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .pl-badge-icon-red {
      color: #D32F2F;
      font-size: 26px;
      line-height: 1;
    }

    .pl-badge-icon-blue {
      color: #0B72B9;
      font-size: 28px;
      line-height: 1;
    }

    .pl-badge-info-block {
      line-height: 1.1;
    }

    .pl-badge-label {
      font-size: 10px;
      font-weight: 800;
      color: #000000;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .pl-badge-val-large {
      font-size: 20px;
      font-weight: 900;
      color: #000000;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       BADGES ROW 2: INVOICE NO, DISPATCH DATE, TOTAL BUNDLES
       ═══════════════════════════════════════════════════════════════════════ */
    .pl-badges-row-2 {
      display: flex;
      gap: 8px;
      margin-bottom: 10px;
    }

    .pl-badge-mini {
      flex: 1;
      border-radius: 8px;
      padding: 6px 8px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .pl-badge-invoice {
      background: #FDF2F8;
      border: 1.5px solid #E11D48;
    }

    .pl-badge-date {
      background: #F5F3FF;
      border: 1.5px solid #7C3AED;
    }

    .pl-badge-bundles {
      background: #FFFBEB;
      border: 1.5px solid #D97706;
    }

    .pl-badge-bundles .pl-badge-label {
      font-size: 12px;
      letter-spacing: 0.4px;
    }

    .pl-badge-bundles .pl-mini-val {
      font-size: 28px;
      line-height: 1;
    }

    .pl-mini-icon-red {
      color: #E11D48;
      font-size: 24px;
      line-height: 1;
    }

    .pl-mini-icon-purple {
      color: #7C3AED;
      font-size: 24px;
      line-height: 1;
    }

    .pl-mini-icon-orange {
      color: #D97706;
      font-size: 26px;
      line-height: 1;
    }

    .pl-mini-val {
      font-size: 17px;
      font-weight: 900;
      color: #000000;
      line-height: 1.1;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       DISPATCH ITEMS TABLE (RED BANNER + YELLOW HEADERS)
       ═══════════════════════════════════════════════════════════════════════ */
    .pl-table-wrapper {
      border: 1.5px solid #000000;
      border-radius: 0;
      overflow: hidden;
      margin-bottom: 12px;
      background: #FFFFFF;
    }

    .pl-table-title {
      background: #D32F2F;
      color: #FFFFFF;
      font-size: 13px;
      font-weight: 900;
      padding: 4px 8px;
      text-transform: uppercase;
      letter-spacing: 0.8px;
      text-align: left;
    }

    .pl-items-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 11.5px;
      margin: 0;
    }

    .pl-items-table th {
      background: #FFF9C4;
      color: #000000;
      font-weight: 900;
      padding: 4px 5px;
      border: 1px solid #000000;
      font-size: 11px;
      text-transform: capitalize;
    }

    .pl-items-table td {
      padding: 4px 5px;
      border: 1px solid #000000;
      color: #000000;
      vertical-align: middle;
    }

    .pl-td-bold {
      font-weight: 900;
    }

    .pl-table-footer-row td {
      background: #FFFFFF;
      font-weight: 900;
      font-size: 12px;
      border-top: 1.5px solid #000000;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       BOTTOM RIGHT: DISPATCH ITEMS QR CODE CARD
       ═══════════════════════════════════════════════════════════════════════ */
    .pl-bottom-section {
      display: flex;
      justify-content: flex-end;
      align-items: flex-end;
      margin-top: 4px;
    }

    .pl-dispatch-qr-card {
      width: 175px;
      background: #E8F8F5;
      border: 2px solid #10B981;
      border-radius: 8px;
      padding: 6px 8px;
      text-align: center;
      display: flex;
      flex-direction: column;
      align-items: center;
      box-shadow: 0 2px 6px rgba(16, 185, 129, 0.15);
    }

    .pl-dispatch-qr-img {
      width: 115px;
      height: 115px;
      display: block;
      image-rendering: pixelated;
      background: #FFFFFF;
      border: 1px solid #10B981;
      padding: 2px;
      margin: 2px 0 4px 0;
    }

    .pl-dispatch-qr-title {
      font-size: 11px;
      font-weight: 900;
      color: #064E3B;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      line-height: 1.2;
    }

    .pl-dispatch-qr-subtitle {
      font-size: 9.5px;
      font-weight: 700;
      color: #047857;
      line-height: 1.1;
    }

    /* ═══════════════════════════════════════════════════════════════════════
       PRINT STYLES: 4" x 6" / A4 CRISP PRINTING
       ═══════════════════════════════════════════════════════════════════════ */
    @media print {
      @page {
        size: 105mm 165mm;
        margin: 3mm;
      }
      html, body {
        width: 100% !important;
        height: auto !important;
        background: #FFFFFF !important;
        padding: 0 !important;
        margin: 0 !important;
        color: #000000 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
      .no-print {
        display: none !important;
      }
      .sticker-container {
        gap: 0 !important;
        display: block !important;
      }
      .parcel-label-card {
        border: 1.5pt solid #000000 !important;
        box-shadow: none !important;
        max-width: 100% !important;
        width: 100% !important;
        border-radius: 8px !important;
        padding: 8px !important;
        margin: 0 0 10mm 0 !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        page-break-after: always !important;
        break-after: page !important;
      }
      .parcel-label-card:last-child {
        page-break-after: auto !important;
        break-after: auto !important;
        margin-bottom: 0 !important;
      }
      .pl-notebooks-line {
        background: #D32F2F !important;
      }
      .pl-salem-line {
        background: #D32F2F !important;
      }
      .pl-phone-pill {
        background: #D32F2F !important;
        color: #FFFFFF !important;
      }
      .pl-consignee-header {
        background: #0B72B9 !important;
        color: #FFFFFF !important;
      }
      .pl-table-title {
        background: #D32F2F !important;
        color: #FFFFFF !important;
      }
      .pl-items-table th {
        background: #FFF9C4 !important;
      }
      .pl-badge-delivery {
        background: #FFF4E5 !important;
      }
      .pl-badge-transport {
        background: #E8F8F5 !important;
      }
      .pl-badge-invoice {
        background: #FDF2F8 !important;
      }
      .pl-badge-date {
        background: #F5F3FF !important;
      }
      .pl-badge-bundles {
        background: #FFFBEB !important;
      }
      .pl-dispatch-qr-card {
        background: #E8F8F5 !important;
      }
    }
  </style>
</head>
<body>

<!-- Screen-Only Controls Toolbar -->
<div class="no-print screen-toolbar">
  <div class="card p-3 shadow-sm border-0 rounded-3 mb-2 bg-white">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
      <?php 
        $back_order_id = $order_id ?: ($parcel['order_id'] ?? 0);
      ?>
      <a href="<?php echo $back_order_id ? 'orders.php?view=' . $back_order_id : 'orders.php'; ?>" 
         class="btn btn-outline-dark btn-sm fw-bold shadow-xs">
        <i class="bi bi-arrow-left me-1"></i> Back to Order
      </a>

      <div class="d-flex align-items-center gap-2">
        <button type="button" onclick="window.print()" class="btn btn-dark btn-sm fw-bold px-3 shadow-xs">
          <i class="bi bi-printer-fill me-1.5 text-warning"></i> Print <?php echo count($parcels_to_render) > 1 ? 'All ' . count($parcels_to_render) . ' Parcel Labels' : 'Parcel Label'; ?>
        </button>
      </div>
    </div>

    <!-- QR Code Destination Mode Switcher -->
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 pt-2 border-top small">
      <span class="text-muted fw-bold">
        <i class="bi bi-qr-code text-primary me-1"></i> QR Scan Mode:
      </span>
      <div class="btn-group btn-group-sm" role="group">
        <a href="?<?php echo http_build_query(array_merge($_GET, ['qr_mode' => 'wifi'])); ?>" 
           class="btn <?php echo $qr_mode === 'wifi' ? 'btn-success fw-bold' : 'btn-outline-secondary'; ?>" 
           title="Encodes your Wi-Fi IP so real phones on the same Wi-Fi can scan & open the page immediately">
          <i class="bi bi-wifi me-1"></i> Wi-Fi LAN (Mobile Test)
        </a>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['qr_mode' => 'live'])); ?>" 
           class="btn <?php echo $qr_mode === 'live' ? 'btn-primary fw-bold' : 'btn-outline-secondary'; ?>" 
           title="Encodes the official live domain (www.rajpriyanotebooks.com) for production parcel labels">
          <i class="bi bi-globe me-1"></i> Live Production URL
        </a>
        <a href="?<?php echo http_build_query(array_merge($_GET, ['qr_mode' => 'local'])); ?>" 
           class="btn <?php echo $qr_mode === 'local' ? 'btn-dark fw-bold' : 'btn-outline-secondary'; ?>" 
           title="Encodes localhost for direct desktop browser testing">
          <i class="bi bi-laptop me-1"></i> Localhost
        </a>
      </div>
    </div>
  </div>
</div>

<div class="sticker-container">
  <?php foreach ($parcels_to_render as $pcl): 
    // ═══════════════════════════════════════════════════════════════════════════
    // DYNAMIC DATA RESOLUTION FOR THIS PARCEL
    // ═══════════════════════════════════════════════════════════════════════════
    $orderNum = !empty($pcl['order_number']) ? $pcl['order_number'] : ($order['order_number'] ?? 'ORD-' . $order['id']);
    $orderId = !empty($pcl['order_id']) ? $pcl['order_id'] : ($order['id'] ?? 0);
    $parcelNum = !empty($pcl['parcel_number']) ? $pcl['parcel_number'] : '';
    
    // Generate secure, deterministic HMAC verification token
    $dispatchToken = generate_dispatch_security_token($orderId, $orderNum, $parcelNum);

    // Dynamic Dispatch URL for QR scanning
    $dispatchQrUrl = get_dispatch_tracking_url($orderNum, $parcelNum, $dispatchToken, $qr_mode);
    $dispatchQrImg = generate_qr_code_image_url($dispatchQrUrl, 260);

    // Customer ID & Name
    $customerId = !empty($pcl['customer_code']) ? trim($pcl['customer_code']) : (!empty($order['customer_code']) ? trim($order['customer_code']) : '');
    if (empty($customerId)) {
        $cNum = !empty($pcl['user_id']) ? $pcl['user_id'] : ($order['id'] ?? 0);
        $customerId = $cNum ? ('CUST' . str_pad($cNum, 5, '0', STR_PAD_LEFT)) : 'CUST00001';
    }
    $customerName = !empty($pcl['customer_name']) ? trim($pcl['customer_name']) : (!empty($order['customer_name']) ? trim($order['customer_name']) : 'Customer');
    $customerPhone = !empty($pcl['customer_phone']) ? trim($pcl['customer_phone']) : (!empty($order['customer_phone']) ? trim($order['customer_phone']) : '');
    $gstNo = !empty($pcl['gst_number']) ? trim($pcl['gst_number']) : (!empty($order['gst_number']) ? trim($order['gst_number']) : '');

    // Formatted Address
    $addrParts = array_filter([
        $pcl['shipping_address'] ?? ($order['shipping_address'] ?? ''),
        $pcl['address_line2'] ?? ($order['address_line2'] ?? ''),
        !empty($pcl['area']) ? $pcl['area'] : (!empty($order['area']) ? $order['area'] : ''),
        !empty($pcl['landmark']) ? (stripos(trim($pcl['landmark']), 'near') === 0 ? trim($pcl['landmark']) : ('Near ' . trim($pcl['landmark']))) : (!empty($order['landmark']) ? (stripos(trim($order['landmark']), 'near') === 0 ? trim($order['landmark']) : ('Near ' . trim($order['landmark']))) : ''),
        trim(implode(', ', array_filter([
            $pcl['city'] ?? ($order['city'] ?? ''),
            $pcl['state'] ?? ($order['state'] ?? '')
        ]))),
        !empty($pcl['pincode']) ? ($pcl['pincode'] . '.') : (!empty($order['pincode']) ? ($order['pincode'] . '.') : ''),
        'Tamil Nadu, India.'
    ]);
    $addressFormatted = !empty($addrParts) ? implode('<br>', array_map('htmlspecialchars', $addrParts)) : 'Salem, Tamil Nadu, India.';

    // Code 128 Customer Barcode
    $barcodeSvg = generate_code128_svg($customerId, 36);

    // Logistics & Transport Badges
    $deliveryCity = !empty($pcl['transport_to']) ? strtoupper($pcl['transport_to']) : (!empty($order['transport_to']) ? strtoupper($order['transport_to']) : (!empty($pcl['city']) ? strtoupper($pcl['city']) : 'SALEM'));
    $transportName = !empty($pcl['transport_name']) ? strtoupper($pcl['transport_name']) : (!empty($order['transport_name']) ? strtoupper($order['transport_name']) : 'KAVITHA');
    
    // Invoice & Dispatch Badges
    $invoiceLabel = !empty($order['order_number']) ? $order['order_number'] : ('ORD-' . $order['id']);
    // If order number starts with ORD-, convert to SI- format if desired, or display actual invoice number
    if (preg_match('/^ORD-\d{8}-(\d+)$/i', $invoiceLabel, $mInv)) {
        $displayInvoice = 'SI-' . $mInv[1];
    } else {
        $displayInvoice = $invoiceLabel;
    }

    // Dispatch Date resolution: prioritize parcel's dispatch_date, then order's dispatch_date / transport_date
    $rawDispatchDate = !empty($pcl['dispatch_date']) 
        ? $pcl['dispatch_date'] 
        : (!empty($pcl['transport_date']) 
            ? $pcl['transport_date'] 
            : (!empty($order['dispatch_date']) 
                ? $order['dispatch_date'] 
                : (!empty($order['transport_date']) ? $order['transport_date'] : null)));
    $dispatchDate = format_dispatch_date_display($rawDispatchDate);
    
    $items = !empty($pcl['dispatch_items']) ? $pcl['dispatch_items'] : [];
    $totalBundles = !empty($pcl['total_dispatch_bundles']) ? (int)$pcl['total_dispatch_bundles'] : 0;
    $totalNOS = !empty($pcl['total_dispatch_pieces']) ? (int)$pcl['total_dispatch_pieces'] : 0;
  ?>

    <!-- OFFICIAL PARCEL LABEL -->
    <div class="parcel-label-card">
      
      <!-- 1. HEADER SECTION -->
      <div class="pl-header">
        <div class="pl-brand-block">
          <div class="pl-brand-title">
            <span class="pl-brand-rajpriya">RAJPRIYA</span><span class="pl-brand-trademark">&reg;</span>
          </div>
          <div class="pl-brand-notebooks-wrap">
            <div class="pl-notebooks-line"></div>
            <span class="pl-brand-notebooks">NOTE BOOKS</span>
            <div class="pl-notebooks-line"></div>
          </div>
          
          <div class="pl-brand-tagline">QUALITY WRITES A BRIGHTER TOMORROW</div>
          
          <div class="pl-brand-salem-wrap">
            <div class="pl-salem-line"></div>
            <div class="pl-salem-text">SALEM</div>
            <div class="pl-salem-line"></div>
          </div>
        </div>

        <div class="pl-header-right">
          <div class="pl-cat-qr-box">
            <img src="<?php echo $catalogue_qr_image; ?>" alt="Catalogue QR" class="pl-cat-qr-img">
            <div class="pl-cat-qr-caption">Company QR<br>Product Catalogue</div>
          </div>

          <a href="tel:9443346472" class="pl-phone-pill">
            <i class="bi bi-telephone-fill"></i> 9443346472
          </a>
        </div>
      </div>

      <!-- 2. TO (CONSIGNEE) SECTION -->
      <div class="pl-consignee-box">
        <div class="pl-consignee-header">
          TO (CONSIGNEE)
        </div>

        <div class="pl-consignee-body">
          <div class="pl-consignee-left">
            <i class="bi bi-shop pl-store-icon"></i>
            <div>
              <div class="pl-customer-name"><?php echo htmlspecialchars($customerName); ?></div>
              <div class="pl-customer-id">Customer ID : <?php echo htmlspecialchars($customerId); ?></div>
              <div class="pl-customer-address">
                <?php echo $addressFormatted; ?>
              </div>
            </div>
          </div>

          <div class="pl-consignee-right">
            <div class="pl-barcode-wrap">
              <?php echo $barcodeSvg; ?>
            </div>
            <div class="pl-barcode-digits"><?php echo htmlspecialchars($customerId); ?></div>
            <div class="pl-barcode-caption">Customer Name Barcode</div>
          </div>
        </div>

        <div class="pl-consignee-footer">
          <div class="pl-footer-cell-phone">
            Customer Phone No. : &nbsp;<?php echo htmlspecialchars($customerPhone ?: '98420 12345'); ?>
          </div>
          <div class="pl-footer-cell-gst">
            GST No. : &nbsp;<?php echo htmlspecialchars($gstNo ?: '________________'); ?>
          </div>
        </div>
      </div>

      <!-- 3. DELIVERY PLACE & TRANSPORT BADGES -->
      <div class="pl-badges-row-1">
        <!-- Delivery Place -->
        <div class="pl-badge-delivery">
          <i class="bi bi-geo-alt-fill pl-badge-icon-red"></i>
          <div class="pl-badge-info-block">
            <div class="pl-badge-label">DELIVERY PLACE</div>
            <div class="pl-badge-val-large"><?php echo htmlspecialchars($deliveryCity); ?></div>
          </div>
        </div>

        <!-- Transport -->
        <div class="pl-badge-transport">
          <i class="bi bi-truck pl-badge-icon-blue"></i>
          <div class="pl-badge-info-block">
            <div class="pl-badge-label">TRANSPORT</div>
            <div class="pl-badge-val-large"><?php echo htmlspecialchars($transportName); ?></div>
          </div>
        </div>
      </div>

      <!-- 4. INVOICE NO, DISPATCH DATE, TOTAL BUNDLES BADGES -->
      <div class="pl-badges-row-2">
        <!-- Invoice No -->
        <div class="pl-badge-mini pl-badge-invoice">
          <i class="bi bi-file-earmark-text-fill pl-mini-icon-red"></i>
          <div>
            <div class="pl-badge-label">INVOICE NO.</div>
            <div class="pl-mini-val"><?php echo htmlspecialchars($displayInvoice); ?></div>
          </div>
        </div>

        <!-- Dispatch Date -->
        <div class="pl-badge-mini pl-badge-date">
          <i class="bi bi-calendar2-week-fill pl-mini-icon-purple"></i>
          <div>
            <div class="pl-badge-label">DISPATCH DATE</div>
            <div class="pl-mini-val"><?php echo htmlspecialchars($dispatchDate); ?></div>
          </div>
        </div>

        <!-- Total Bundles -->
        <div class="pl-badge-mini pl-badge-bundles">
          <i class="bi bi-box-seam-fill pl-mini-icon-orange"></i>
          <div>
            <div class="pl-badge-label">TOTAL BUNDLES</div>
            <div class="pl-mini-val"><?php echo (int)$totalBundles; ?></div>
          </div>
        </div>
      </div>

      <!-- 5. DISPATCH INVOICE – LIST OF ITEMS TABLE -->
      <div class="pl-table-wrapper">
        <div class="pl-table-title">
          DISPATCH INVOICE &ndash; LIST OF ITEMS
        </div>

        <table class="pl-items-table">
          <thead>
            <tr>
              <th style="width: 38px; text-align: center;">Sl.No</th>
              <th style="width: 105px; text-align: center;">Product ID</th>
              <th style="text-align: left;">Product Name</th>
              <th style="width: 48px; text-align: center;">Pages</th>
              <th style="width: 105px; text-align: center;">Variety</th>
              <th style="width: 68px; text-align: center;">Pack</th>
              <th style="width: 60px; text-align: center;">Bundles</th>
              <th style="width: 78px; text-align: right; padding-right: 8px;">Total NOS</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($items)): ?>
              <tr>
                <td colspan="8" style="text-align: center; padding: 10px; color: #64748B;">No items packed for this parcel.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($items as $sIdx => $row): ?>
                <tr>
                  <td style="text-align: center; font-weight: 700;"><?php echo $sIdx + 1; ?></td>
                  <td style="text-align: center; font-family: 'JetBrains Mono', monospace; font-weight: 700; font-size: 11px;">
                    <?php echo htmlspecialchars($row['code']); ?>
                  </td>
                  <td class="pl-td-bold" style="text-align: left;"><?php echo htmlspecialchars($row['product_name']); ?></td>
                  <td style="text-align: center; font-weight: 700;"><?php echo htmlspecialchars($row['pages']); ?></td>
                  <td style="text-align: center; font-weight: 700; font-size: 10.5px;"><?php echo htmlspecialchars($row['variety']); ?></td>
                  <td style="text-align: center; font-weight: 700; font-size: 10.5px;"><?php echo htmlspecialchars($row['pack']); ?></td>
                  <td style="text-align: center; font-weight: 900; font-size: 16px;"><?php echo (int)$row['bundles']; ?></td>
                  <td style="text-align: right; font-weight: 900; font-size: 12px; padding-right: 8px;">
                    <?php echo number_format($row['total_pieces']); ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr class="pl-table-footer-row">
              <td colspan="6" style="text-align: center; letter-spacing: 1px; font-weight: 900;">TOTAL</td>
              <td style="text-align: center; font-weight: 900; font-size: 20px;"><?php echo (int)$totalBundles; ?></td>
              <td style="text-align: right; font-weight: 900; font-size: 13px; padding-right: 8px;">
                <?php echo number_format($totalNOS); ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- 6. BOTTOM RIGHT: DYNAMIC DISPATCH ITEMS QR -->
      <div class="pl-bottom-section">
        <div class="pl-dispatch-qr-card">
          <div class="pl-dispatch-qr-title">DISPATCH ITEMS QR</div>
          <div class="pl-dispatch-qr-subtitle">(Items + Dispatch Details)</div>
          
          <a href="<?php echo htmlspecialchars($dispatchQrUrl); ?>" target="_blank" title="Scan or Click to Open Verified Dispatch Page">
            <img src="<?php echo $dispatchQrImg; ?>" alt="Dispatch Items QR" class="pl-dispatch-qr-img">
          </a>
          
          <div class="d-none d-print-none small text-muted" style="font-size: 8px;">
            Token: <code><?php echo $dispatchToken; ?></code>
          </div>
        </div>
      </div>

    </div>

  <?php endforeach; ?>
</div>

</body>
</html>
