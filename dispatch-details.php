<?php
/**
 * Official Public Dispatch & Parcel Verification Endpoint
 * 
 * Accessed by scanning the "DISPATCH ITEMS QR" on parcel shipping labels.
 * Provides instant, mobile-friendly verification of dispatched items, bundle quantities,
 * transport carrier, and consignee details without requiring user login.
 * 
 * Secured with tamper-proof HMAC verification tokens.
 */

require_once __DIR__ . '/includes/functions.php';

$db = getDB();
$logos = get_active_logos();

$ord_param   = sanitize($_GET['ord'] ?? ($_GET['order'] ?? ''));
$pcl_param   = sanitize($_GET['pcl'] ?? ($_GET['parcel'] ?? ''));
$token_param = sanitize($_GET['token'] ?? '');

$order = null;
$parcel = null;
$error_message = '';
$is_token_valid = false;

// 1. Locate Parcel Record if pcl parameter provided
if (!empty($pcl_param)) {
    $stmt_p = $db->prepare("SELECT * FROM order_parcels WHERE parcel_number = ? OR id = ? LIMIT 1");
    $stmt_p->execute([$pcl_param, is_numeric($pcl_param) ? (int)$pcl_param : 0]);
    $parcel = $stmt_p->fetch(PDO::FETCH_ASSOC);
    if ($parcel) {
        $stmt_o = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
        $stmt_o->execute([(int)$parcel['order_id']]);
        $order = $stmt_o->fetch(PDO::FETCH_ASSOC);
    }
}

// 2. Locate Order Record if not found via parcel
if (!$order && !empty($ord_param)) {
    $stmt_o = $db->prepare("SELECT * FROM orders WHERE order_number = ? OR id = ? LIMIT 1");
    $stmt_o->execute([$ord_param, is_numeric($ord_param) ? (int)$ord_param : 0]);
    $order = $stmt_o->fetch(PDO::FETCH_ASSOC);
}

// 3. Security Token Verification
if ($order) {
    $parcel_num_for_token = $parcel['parcel_number'] ?? '';
    
    // If a token is provided in the URL, it MUST be valid
    if (!empty($token_param)) {
        if (verify_dispatch_security_token($order['id'], $order['order_number'], $parcel_num_for_token, $token_param) ||
            verify_dispatch_security_token($order['id'], $order['order_number'], '', $token_param)) {
            $is_token_valid = true;
        } else {
            $is_token_valid = false;
            $error_message = "Invalid or expired dispatch verification token. This dispatch link could not be verified.";
        }
    } else {
        // No token provided: only allow authenticated admin preview
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!empty($_SESSION['admin_id'])) {
            $is_token_valid = true;
        } else {
            $is_token_valid = false;
            $error_message = "Dispatch verification token is required to view this dispatch record.";
        }
    }
} else {
    $error_message = "The requested dispatch or order record was not found.";
}

// 4. Load Dispatched Items if verified
$dispatched_items = [];
$total_dispatch_bundles = 0;
$total_dispatch_pieces = 0;

if ($order && $is_token_valid) {
    if ($parcel) {
        // Specific parcel scanned: Load only items assigned to this parcel
        $stmt_items = $db->prepare("
            SELECT opi.quantity AS parcel_qty, oi.*, p.pages AS prod_pages, p.std_qty AS prod_std_qty
            FROM order_parcel_items opi
            JOIN order_items oi ON opi.order_item_id = oi.id
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE opi.parcel_id = ?
            ORDER BY oi.id ASC
        ");
        $stmt_items->execute([(int)$parcel['id']]);
        $raw_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        foreach ($raw_items as $itm) {
            $vInfo = parse_variety_info($itm['ruling_type'] ?? '');
            $variety = !empty($itm['variety']) ? $itm['variety'] : (!empty($vInfo['variety']) ? $vInfo['variety'] : 'Ruled');
            $pages = !empty($itm['page_count']) ? $itm['page_count'] : (!empty($vInfo['page_count']) ? preg_replace('/[^0-9]/', '', $vInfo['page_count']) : (!empty($itm['prod_pages']) ? $itm['prod_pages'] : 192));
            
            $calc = calculate_order_item_packing($itm, $itm['parcel_qty']);
            $ppb = $calc['standard_bundle_size'];
            $bundles = $calc['packed_units'];
            $total_pieces = $calc['ordered_pieces'];

            $total_dispatch_bundles += $bundles;
            $total_dispatch_pieces += $total_pieces;

            $dispatched_items[] = [
                'code' => format_clean_dispatch_item_code($itm['product_code'], $itm['product_name'], $pages, $variety),
                'product_name' => strtoupper(trim((string)$itm['product_name'])),
                'pages' => $pages,
                'variety' => strtoupper(trim((string)$variety)),
                'pack' => "1 × {$ppb}",
                'ppb' => $ppb,
                'bundles' => $bundles,
                'full_bundles' => $calc['full_bundles'],
                'remaining_pieces' => $calc['remaining_pieces'],
                'partial_bundles' => $calc['partial_bundles'],
                'total_pieces' => $total_pieces
            ];
        }
    } else {
        // Entire order dispatch view: Load all line items for this order
        $stmt_items = $db->prepare("
            SELECT oi.*, p.pages AS prod_pages, p.std_qty AS prod_std_qty
            FROM order_items oi
            LEFT JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ?
            ORDER BY oi.id ASC
        ");
        $stmt_items->execute([(int)$order['id']]);
        $raw_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        $order_packing = get_order_packing_details($order['id'], $raw_items);
        $total_dispatch_bundles = $order_packing['total_packed_units'];
        $total_dispatch_pieces  = $order_packing['total_ordered_pieces'];

        foreach ($order_packing['items'] as $itm) {
            $vInfo = parse_variety_info($itm['ruling_type'] ?? '');
            $variety = !empty($itm['variety']) ? $itm['variety'] : (!empty($vInfo['variety']) ? $vInfo['variety'] : 'Ruled');
            $pages = !empty($itm['page_count']) ? $itm['page_count'] : (!empty($vInfo['page_count']) ? preg_replace('/[^0-9]/', '', $vInfo['page_count']) : (!empty($itm['prod_pages']) ? $itm['prod_pages'] : 192));
            $ppb = $itm['standard_bundle_size'];

            $dispatched_items[] = [
                'code' => format_clean_dispatch_item_code($itm['product_code'], $itm['product_name'], $pages, $variety),
                'product_name' => strtoupper(trim((string)$itm['product_name'])),
                'pages' => $pages,
                'variety' => strtoupper(trim((string)$variety)),
                'pack' => "1 × {$ppb}",
                'ppb' => $ppb,
                'bundles' => $itm['packed_units'],
                'full_bundles' => $itm['full_bundles'],
                'remaining_pieces' => $itm['remaining_pieces'],
                'partial_bundles' => $itm['partial_bundles'],
                'total_pieces' => $itm['ordered_pieces']
            ];
        }
    }

    // Customer & Transport extraction
    $customerId = !empty($order['customer_code']) ? trim($order['customer_code']) : ('CUST' . str_pad($order['user_id'] ?: $order['id'], 5, '0', STR_PAD_LEFT));
    $customerName = !empty($order['customer_name']) ? trim($order['customer_name']) : 'Customer';
    $customerPhone = !empty($order['customer_phone']) ? trim($order['customer_phone']) : '';
    $gstNo = !empty($order['gst_number']) ? trim($order['gst_number']) : '-';

    $addrLines = array_filter([
        $order['shipping_address'] ?? '',
        $order['address_line2'] ?? '',
        !empty($order['area']) ? ('Area: ' . $order['area']) : '',
        !empty($order['landmark']) ? ('Landmark: ' . $order['landmark']) : '',
        !empty($order['district']) ? $order['district'] : '',
        trim(implode(', ', array_filter([$order['city'] ?? '', $order['state'] ?? '']))),
        !empty($order['pincode']) ? ('PIN: ' . $order['pincode']) : ''
    ]);

    $transportName = !empty($parcel['transport_name']) ? $parcel['transport_name'] : (!empty($order['transport_name']) ? $order['transport_name'] : 'BY ROAD');
    $deliveryPlace = !empty($parcel['transport_to']) ? $parcel['transport_to'] : (!empty($order['transport_to']) ? $order['transport_to'] : ($order['city'] ?? 'SALEM'));
    $rawDispatchDate = !empty($parcel['dispatch_date']) 
        ? $parcel['dispatch_date'] 
        : (!empty($parcel['transport_date']) 
            ? $parcel['transport_date'] 
            : (!empty($order['dispatch_date']) 
                ? $order['dispatch_date'] 
                : (!empty($order['transport_date']) ? $order['transport_date'] : null)));
    $dispatchDate = function_exists('format_dispatch_date_display') 
        ? format_dispatch_date_display($rawDispatchDate) 
        : (!empty($rawDispatchDate) ? date('d-m-Y', strtotime($rawDispatchDate)) : '—');
    $invoiceNo = !empty($order['order_number']) ? $order['order_number'] : ('ORD-' . $order['id']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo ($order && $is_token_valid && !empty($invoiceNo)) ? 'Verified Dispatch: ' . htmlspecialchars($invoiceNo) : 'Dispatch Verification'; ?> — RAJPRIYA NOTEBOOKS</title>
  <link rel="icon" href="<?php echo htmlspecialchars(get_site_url() . '/' . ltrim($logos['primary'], '/'), ENT_QUOTES, 'UTF-8'); ?>">
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

  <style>
    :root {
      --rp-red: #D32F2F;
      --rp-navy: #102A43;
      --rp-gold: #C89B3C;
      --rp-bg: #F8FAFC;
    }

    body {
      font-family: 'Outfit', sans-serif;
      background: var(--rp-bg);
      color: #1E293B;
      padding: 16px 10px 48px 10px;
      margin: 0;
    }

    .dispatch-view-card {
      max-width: 720px;
      margin: 0 auto;
      background: #FFFFFF;
      border: 1px solid #E2E8F0;
      border-radius: 14px;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(0, 0, 0, 0.06);
    }

    /* Top Brand Header */
    .dispatch-header {
      background: linear-gradient(135deg, #102A43 0%, #1A365D 100%);
      color: #FFFFFF;
      padding: 22px 20px;
      text-align: center;
      position: relative;
    }

    .brand-title-red {
      color: #FF4D4F;
      font-weight: 800;
      font-size: 1.45rem;
      letter-spacing: 1px;
      display: inline-block;
    }

    .brand-title-white {
      color: #FFFFFF;
      font-weight: 800;
      font-size: 1.45rem;
      letter-spacing: 1px;
    }

    .brand-tagline {
      font-size: 0.75rem;
      letter-spacing: 1.2px;
      color: #E2E8F0;
      text-transform: uppercase;
      font-weight: 600;
      margin-top: 4px;
    }

    .verified-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #10B981;
      color: #FFFFFF;
      font-size: 0.74rem;
      font-weight: 700;
      letter-spacing: 0.6px;
      padding: 5px 14px;
      border-radius: 50px;
      margin-top: 10px;
      box-shadow: 0 2px 8px rgba(16, 185, 129, 0.35);
    }

    /* Dispatch Key Stats Row */
    .dispatch-stat-box {
      background: #FFFFFF;
      border-radius: 10px;
      padding: 12px 14px;
      border: 1.5px solid #E2E8F0;
      height: 100%;
    }

    .stat-label {
      font-size: 0.68rem;
      text-transform: uppercase;
      letter-spacing: 0.6px;
      font-weight: 700;
      color: #64748B;
      margin-bottom: 3px;
    }

    .stat-val {
      font-size: 1.15rem;
      font-weight: 800;
      color: #0F172A;
      line-height: 1.2;
    }

    /* Items Table */
    .table-dispatch {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
    }

    .table-dispatch thead th {
      background-color: #FEF3C7;
      color: #78350F;
      font-weight: 700;
      padding: 10px 8px;
      border: 1px solid #FDE68A;
      text-transform: uppercase;
      font-size: 0.72rem;
      letter-spacing: 0.4px;
    }

    .table-dispatch tbody td {
      padding: 10px 8px;
      border: 1px solid #E2E8F0;
      vertical-align: middle;
    }

    .table-dispatch tfoot td {
      background-color: #F8FAFC;
      font-weight: 800;
      padding: 11px 8px;
      border: 1px solid #CBD5E1;
    }

    .code-badge {
      font-family: 'JetBrains Mono', monospace;
      font-size: 0.78rem;
      font-weight: 700;
      color: #0F172A;
      background: #F1F5F9;
      padding: 3px 6px;
      border-radius: 4px;
      border: 1px solid #E2E8F0;
      display: inline-block;
    }

    /* Action bar */
    .btn-action-call {
      background: #10B981;
      color: #FFFFFF;
      font-weight: 600;
      border-radius: 8px;
      text-decoration: none;
      padding: 8px 16px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 0.86rem;
    }

    .btn-action-call:hover {
      background: #059669;
      color: #FFFFFF;
    }

    @media print {
      body {
        background: #FFFFFF !important;
        padding: 0 !important;
      }
      .dispatch-view-card {
        box-shadow: none !important;
        border: none !important;
        max-width: 100% !important;
      }
      .no-print {
        display: none !important;
      }
    }
  </style>
</head>
<body>

<div class="dispatch-view-card">

  <?php if ($order && $is_token_valid): ?>

    <!-- Top Official Verification Header -->
    <div class="dispatch-header">
      <div>
        <span class="brand-title-red">RAJ PRIYA</span>
        <span class="brand-title-white">NOTE BOOKS</span>
        <sup style="font-size: 0.65rem; color: #FF4D4F; font-weight: bold;">&reg;</sup>
      </div>
      <div class="brand-tagline">QUALITY WRITES A BRIGHTER TOMORROW &bull; SALEM</div>
      
      <div class="verified-pill">
        <i class="bi bi-shield-fill-check"></i>
        <span>OFFICIAL VERIFIED DISPATCH RECORD</span>
      </div>
    </div>

    <div class="p-3 p-md-4">

      <!-- Dispatch Key Highlights -->
      <div class="row g-2 mb-3">
        <div class="col-6 col-sm-3">
          <div class="dispatch-stat-box" style="border-left: 4px solid #D32F2F;">
            <div class="stat-label">Invoice / Order</div>
            <div class="stat-val text-truncate" title="<?php echo htmlspecialchars($invoiceNo); ?>">
              <?php echo htmlspecialchars($invoiceNo); ?>
            </div>
          </div>
        </div>

        <div class="col-6 col-sm-3">
          <div class="dispatch-stat-box" style="border-left: 4px solid #8E44AD;">
            <div class="stat-label">Dispatch Date</div>
            <div class="stat-val"><?php echo htmlspecialchars($dispatchDate); ?></div>
          </div>
        </div>

        <div class="col-6 col-sm-3">
          <div class="dispatch-stat-box" style="border-left: 4px solid #D97706;">
            <div class="stat-label">Total Bundles</div>
            <div class="stat-val text-warning-emphasis"><?php echo number_format($total_dispatch_bundles); ?></div>
          </div>
        </div>

        <div class="col-6 col-sm-3">
          <div class="dispatch-stat-box" style="border-left: 4px solid #059669;">
            <div class="stat-label">Total Pieces (NOS)</div>
            <div class="stat-val text-success"><?php echo number_format($total_dispatch_pieces); ?></div>
          </div>
        </div>
      </div>

      <!-- Consignee & Transport 2-Column Info Grid -->
      <div class="row g-3 mb-4">
        <!-- Customer / Consignee Details -->
        <div class="col-md-6">
          <div class="card h-100 border rounded-3 bg-light-subtle shadow-xs">
            <div class="card-header bg-white border-bottom py-2 px-3 d-flex align-items-center justify-content-between">
              <span class="fw-bold text-navy small">
                <i class="bi bi-shop text-primary me-1.5"></i> TO (CONSIGNEE)
              </span>
              <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace" style="font-size: 0.72rem;">
                ID: <?php echo htmlspecialchars($customerId); ?>
              </span>
            </div>
            <div class="card-body p-3">
              <div class="fw-bold text-navy fs-6 mb-1"><?php echo htmlspecialchars($customerName); ?></div>
              
              <div class="small text-secondary mb-2" style="line-height: 1.45;">
                <?php echo implode('<br>', array_map('htmlspecialchars', $addrLines)); ?>
              </div>

              <?php if (!empty($customerPhone)): ?>
                <div class="pt-2 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
                  <div class="small">
                    <span class="text-muted">Phone:</span>
                    <strong class="text-dark"><?php echo htmlspecialchars($customerPhone); ?></strong>
                  </div>
                  <a href="tel:<?php echo htmlspecialchars($customerPhone); ?>" class="btn-action-call py-1 px-2.5" style="font-size: 0.75rem;">
                    <i class="bi bi-telephone-fill"></i> Call
                  </a>
                </div>
              <?php endif; ?>

              <?php if (!empty($gstNo) && $gstNo !== '-'): ?>
                <div class="small text-muted mt-1.5 pt-1.5 border-top">
                  GST No: <strong class="text-dark font-monospace"><?php echo htmlspecialchars($gstNo); ?></strong>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Logistics & Transport Carrier Details -->
        <div class="col-md-6">
          <div class="card h-100 border rounded-3 bg-light-subtle shadow-xs">
            <div class="card-header bg-white border-bottom py-2 px-3 d-flex align-items-center justify-content-between">
              <span class="fw-bold text-navy small">
                <i class="bi bi-truck text-success me-1.5"></i> LOGISTICS &amp; TRANSPORT
              </span>
              <span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold" style="font-size: 0.72rem;">
                <?php echo htmlspecialchars($order['order_status'] ?? 'Packed'); ?>
              </span>
            </div>
            <div class="card-body p-3">
              <div class="mb-2">
                <span class="text-muted small d-block">Transport Carrier:</span>
                <strong class="text-navy fs-6"><?php echo htmlspecialchars($transportName); ?></strong>
              </div>

              <div class="mb-2">
                <span class="text-muted small d-block">Delivery Destination:</span>
                <strong class="text-dark"><?php echo htmlspecialchars($deliveryPlace); ?></strong>
              </div>

              <?php if (!empty($order['transport_lr_number']) || !empty($parcel['lr_number'])): ?>
                <div class="mb-2">
                  <span class="text-muted small d-block">LR / Waybill Number:</span>
                  <code class="fw-bold text-navy"><?php echo htmlspecialchars($parcel['lr_number'] ?? $order['transport_lr_number']); ?></code>
                </div>
              <?php endif; ?>

              <?php if (!empty($parcel['parcel_number'])): ?>
                <div class="pt-2 border-top small">
                  <span class="text-muted">Physical Box ID:</span>
                  <strong class="text-primary font-monospace"><?php echo htmlspecialchars($parcel['parcel_number']); ?></strong>
                  <?php if (!empty($parcel['parcel_weight']) && (float)$parcel['parcel_weight'] > 0): ?>
                    &bull; Weight: <strong><?php echo $parcel['parcel_weight']; ?> kg</strong>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Dispatched Items List Table -->
      <div class="card border rounded-3 overflow-hidden shadow-xs mb-3">
        <div class="py-2.5 px-3 text-white fw-bold small d-flex align-items-center justify-content-between" style="background-color: var(--rp-red);">
          <span><i class="bi bi-list-check me-1.5"></i> DISPATCH INVOICE – LIST OF ITEMS</span>
          <span class="badge bg-white text-dark"><?php echo count($dispatched_items); ?> Product Line(s)</span>
        </div>

        <div class="table-responsive">
          <table class="table-dispatch mb-0">
            <thead>
              <tr>
                <th class="text-center" style="width: 45px;">Sl.No</th>
                <th class="text-center" style="width: 125px;">Product ID</th>
                <th>Product Name</th>
                <th class="text-center" style="width: 55px;">Pages</th>
                <th class="text-center" style="width: 130px;">Variety</th>
                <th class="text-center" style="width: 85px;">Pack</th>
                <th class="text-center" style="width: 75px;">Bundles</th>
                <th class="text-end pe-3" style="width: 95px;">Total NOS</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($dispatched_items)): ?>
                <tr>
                  <td colspan="8" class="text-center py-4 text-muted">
                    No items logged for this parcel dispatch.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($dispatched_items as $idx => $item): ?>
                  <tr>
                    <td class="text-center text-muted fw-semibold"><?php echo $idx + 1; ?></td>
                    <td class="text-center">
                      <span class="code-badge"><?php echo htmlspecialchars($item['code']); ?></span>
                    </td>
                    <td class="fw-bold text-navy"><?php echo htmlspecialchars($item['product_name']); ?></td>
                    <td class="text-center"><?php echo htmlspecialchars($item['pages']); ?></td>
                    <td class="text-center font-monospace" style="font-size: 0.8rem;"><?php echo htmlspecialchars($item['variety']); ?></td>
                    <td class="text-center text-muted"><?php echo htmlspecialchars($item['pack']); ?></td>
                    <td class="text-center fw-bold text-primary"><?php echo number_format($item['bundles']); ?></td>
                    <td class="text-end pe-3 fw-bold text-success"><?php echo number_format($item['total_pieces']); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <tfoot>
              <tr>
                <td colspan="6" class="text-center fw-bold text-uppercase letter-spacing-1">TOTAL</td>
                <td class="text-center fw-bold text-primary fs-6"><?php echo number_format($total_dispatch_bundles); ?></td>
                <td class="text-end pe-3 fw-bold text-success fs-6"><?php echo number_format($total_dispatch_pieces); ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Verification Footer & Authenticity Badge -->
      <div class="p-3 bg-light rounded-3 border d-flex align-items-center justify-content-between flex-wrap gap-2 text-muted small">
        <div>
          <i class="bi bi-patch-check-fill text-success me-1"></i>
          <strong>Authentic Dispatch Record</strong> &bull; Generated by Raj Priya Notebooks Inventory System
        </div>
        <div>
          <span>Verified At: <strong><?php echo format_datetime('now'); ?></strong></span>
        </div>
      </div>

      <!-- Action Buttons -->
      <div class="mt-3 text-center no-print d-flex justify-content-center gap-2">
        <button type="button" class="btn btn-outline-dark btn-sm px-3 fw-bold" onclick="window.print()">
          <i class="bi bi-printer me-1"></i> Print Verification Slip
        </button>
        <a href="index.php" class="btn btn-gold btn-sm px-3 fw-bold">
          <i class="bi bi-house me-1"></i> Raj Priya Store
        </a>
      </div>

    </div>

  <?php else: ?>

    <!-- Error / Invalid Token View -->
    <div class="dispatch-header">
      <div>
        <span class="brand-title-red">RAJ PRIYA</span>
        <span class="brand-title-white">NOTE BOOKS</span>
      </div>
      <div class="brand-tagline">QUALITY WRITES A BRIGHTER TOMORROW &bull; SALEM</div>
    </div>

    <div class="p-5 text-center">
      <div class="mb-3">
        <i class="bi bi-shield-x text-danger" style="font-size: 3.5rem;"></i>
      </div>
      <h4 class="fw-bold text-navy mb-2">Dispatch Record Verification Failed</h4>
      <p class="text-secondary mb-4" style="max-width: 480px; margin: 0 auto;">
        <?php echo htmlspecialchars($error_message ?: "The scanned QR code link is invalid, expired, or has an invalid verification signature."); ?>
      </p>

      <div class="alert alert-warning d-inline-block text-start small mb-4">
        <strong>Need Assistance?</strong>
        <div>Please contact Raj Priya Notebooks customer support with your physical parcel label.</div>
        <div>Phone: <strong>94433 46472</strong> &bull; Email: <strong>info@rajpriyanotebooks.com</strong></div>
      </div>

      <div>
        <a href="index.php" class="btn btn-dark btn-sm px-4 fw-bold">
          <i class="bi bi-arrow-left me-1"></i> Go to Homepage
        </a>
      </div>
    </div>

  <?php endif; ?>

</div>

</body>
</html>
