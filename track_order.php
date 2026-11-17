<?php
/**
 * Public Order & Parcel QR Code Verification & Tracking Page
 * Accessible by scanning QR codes on parcel stickers, packing sheets, invoices, or customer cards.
 * No login required to view verified order status.
 */

require_once __DIR__ . '/includes/functions.php';

$ord_param  = sanitize($_GET['ord'] ?? ($_GET['order'] ?? ($_GET['order_number'] ?? '')));
$pcl_param  = sanitize($_GET['pcl'] ?? ($_GET['parcel'] ?? ''));
$id_param   = sanitize($_GET['id'] ?? '');
$cust_param = sanitize($_GET['cust'] ?? ($_GET['customer'] ?? ''));

$search_term = $ord_param ?: ($pcl_param ?: ($id_param ?: $cust_param));

$order = null;
$customer = null;
$customer_orders = [];
$searched_parcel = null;

if (!empty($search_term)) {
    // 1. If searching by customer code (e.g. AMB-SS-001, CUST..., or via ?cust=)
    if (!empty($cust_param)) {
        $customer = get_customer_by_code($cust_param);
        if ($customer) {
            $customer_orders = get_customer_orders_history($customer['customer_code'] ?? $customer['id']);
        }
    } elseif (str_starts_with(strtoupper($search_term), 'CUST') || str_contains($search_term, '-SS-') || preg_match('/^[A-Z0-9]+-[A-Z0-9]+-\d+$/i', $search_term) || ($possibleCustomer = get_customer_by_code($search_term))) {
        $customer = $possibleCustomer ?? get_customer_by_code($search_term);
        if ($customer) {
            $customer_orders = get_customer_orders_history($customer['customer_code'] ?? $customer['id']);
        }
    }

    // 2. Otherwise search by Order Number, Parcel Number, or Order ID
    if (!$customer) {
        $order = get_order_full_details_for_tracking($search_term);
        
        // If searched by parcel number specifically, identify which parcel was scanned
        if ($order && (!empty($pcl_param) || str_starts_with(strtoupper($search_term), 'PCL-'))) {
            $pcl_code = !empty($pcl_param) ? $pcl_param : $search_term;
            foreach ($order['parcels'] as $p) {
                if (strcasecmp($p['parcel_number'], $pcl_code) === 0) {
                    $searched_parcel = $p;
                    break;
                }
            }
        }
    }
}

$page_title = "Track Order & Parcel Status — RAJPRIYA NOTEBOOKS";
if ($order) {
    $page_title = "Order #{$order['order_number']} — Verified Tracking";
} elseif ($customer) {
    $page_title = "Customer {$customer['customer_code']} — Order History";
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container py-4 py-md-5" style="max-width: 960px;">
  
  <?php if ($order): ?>
    <!-- ========================================================================= -->
    <!-- 1. VERIFIED ORDER DISPLAY VIEW -->
    <!-- ========================================================================= -->
    
    <!-- Top Verification Header Banner -->
    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden" style="background: linear-gradient(135deg, #17243A 0%, #223758 100%);">
      <div class="card-body p-4 text-white">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-success text-white fw-bold px-3 py-1.5 fs-7 d-flex align-items-center gap-1.5 shadow-sm">
              <i class="bi bi-shield-check-fill"></i> QR VERIFIED ORDER
            </span>
            <?php if (!empty($order['customer_code'])): ?>
              <span class="badge bg-gold text-navy fw-bold px-2.5 py-1.5 fs-7" title="Permanent Customer ID">
                <i class="bi bi-person-badge me-1"></i><?php echo htmlspecialchars($order['customer_code']); ?>
              </span>
            <?php endif; ?>
          </div>

          <div class="d-flex align-items-center gap-2">
            <?php echo render_order_status_badge($order['order_status']); ?>
            <?php if ($order['payment_status'] === 'Paid'): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold px-2.5 py-1 fs-7">
                <i class="bi bi-check-circle-fill me-1"></i>Paid
              </span>
            <?php else: ?>
              <span class="badge bg-warning-subtle text-dark border border-warning-subtle fw-bold px-2.5 py-1 fs-7">
                Payment: <?php echo htmlspecialchars($order['payment_status']); ?>
              </span>
            <?php endif; ?>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-end flex-wrap gap-3">
          <div>
            <div class="small text-white-50 text-uppercase fw-bold letter-spacing-1 mb-1">Official Order Number</div>
            <h1 class="display-6 fw-bold mb-1 text-gold"><?php echo htmlspecialchars($order['order_number']); ?></h1>
            <div class="small text-white-50">
              Placed on <strong class="text-white"><?php echo format_datetime($order['created_at']); ?></strong> &bull; 
              Payment: <strong class="text-white"><?php echo htmlspecialchars($order['payment_method']); ?></strong>
            </div>
          </div>
          
          <div class="text-end">
            <div class="small text-white-50">Grand Total</div>
            <div class="fs-2 fw-bold text-white"><?php echo format_price($order['grand_total']); ?></div>
            <div class="small text-white-50"><?php echo count($order['items']); ?> Product item(s)</div>
          </div>
        </div>
      </div>
    </div>

    <?php if ($searched_parcel): ?>
      <!-- Highlighted Specific Parcel Alert if Scanned via Parcel QR -->
      <div class="alert alert-warning border-2 border-warning-subtle d-flex align-items-center justify-content-between flex-wrap gap-2 rounded-3 mb-4 shadow-sm">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-box-seam-fill fs-3 text-warning"></i>
          <div>
            <strong class="text-navy fs-6">You scanned Parcel Box: <?php echo htmlspecialchars($searched_parcel['parcel_number']); ?></strong>
            <div class="small text-secondary">
              Weight: <strong><?php echo !empty($searched_parcel['parcel_weight']) ? $searched_parcel['parcel_weight'] . ' kg' : 'N/A'; ?></strong> &bull; 
              Status: <span class="badge bg-success-subtle text-success border border-success-subtle"><?php echo htmlspecialchars($searched_parcel['status'] ?? 'Packed'); ?></span>
            </div>
          </div>
        </div>
        <span class="badge bg-navy text-gold fw-bold px-3 py-2">PHYSICAL DISPATCH BOX</span>
      </div>
    <?php endif; ?>

    <!-- Customer & Shipping Information Grid -->
    <div class="row g-4 mb-4">
      <!-- Customer Information Card -->
      <div class="col-md-6">
        <div class="card border shadow-sm h-100 rounded-3">
          <div class="card-body p-4">
            <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
              <h6 class="fw-bold text-navy mb-0">
                <i class="bi bi-person-fill text-gold me-1.5"></i> CUSTOMER INFORMATION
              </h6>
              <?php if (!empty($order['customer_code'])): ?>
                <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold" style="font-size: 0.725rem;">
                  ID: <?php echo htmlspecialchars($order['customer_code']); ?>
                </span>
              <?php endif; ?>
            </div>

            <div class="fw-bold text-navy fs-5 mb-1"><?php echo htmlspecialchars($order['customer_name']); ?></div>
            
            <div class="d-flex align-items-center gap-2 mb-2">
              <i class="bi bi-telephone-fill text-secondary small"></i>
              <a href="tel:<?php echo htmlspecialchars($order['customer_phone']); ?>" class="fw-bold text-navy text-decoration-none">
                <?php echo htmlspecialchars($order['customer_phone']); ?>
              </a>
              <a href="tel:<?php echo htmlspecialchars($order['customer_phone']); ?>" class="btn btn-outline-success btn-xs py-0 px-2 fw-semibold" style="font-size: 0.75rem;">
                <i class="bi bi-telephone-outbound me-1"></i>Call
              </a>
            </div>

            <?php if (!empty($order['customer_email'])): ?>
              <div class="small text-secondary mb-2">
                <i class="bi bi-envelope-fill text-secondary me-1.5"></i>
                <a href="mailto:<?php echo htmlspecialchars($order['customer_email']); ?>" class="text-secondary text-decoration-none">
                  <?php echo htmlspecialchars($order['customer_email']); ?>
                </a>
              </div>
            <?php endif; ?>

            <?php if (!empty($order['gst_number'])): ?>
              <div class="small text-navy fw-semibold mt-2 pt-2 border-top">
                GSTIN: <span class="badge bg-navy text-gold px-2 py-1"><?php echo htmlspecialchars($order['gst_number']); ?></span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Shipping Address Card -->
      <div class="col-md-6">
        <div class="card border shadow-sm h-100 rounded-3">
          <div class="card-body p-4">
            <h6 class="fw-bold text-navy border-bottom pb-2 mb-3">
              <i class="bi bi-geo-alt-fill text-gold me-1.5"></i> SHIPPING / DELIVERY ADDRESS
            </h6>
            <div class="small text-dark">
              <div class="fw-semibold text-navy fs-6 mb-1"><?php echo htmlspecialchars($order['shipping_address']); ?></div>
              <?php if (!empty($order['address_line2'])): ?>
                <div><?php echo htmlspecialchars($order['address_line2']); ?></div>
              <?php endif; ?>
              <?php if (!empty($order['area'])): ?>
                <div>Area: <?php echo htmlspecialchars($order['area']); ?></div>
              <?php endif; ?>
              <div class="fw-bold text-navy mt-1">
                <?php echo htmlspecialchars($order['city']); ?>
                <?php if (!empty($order['district']) && strcasecmp($order['district'], $order['city']) !== 0): ?>
                  , <?php echo htmlspecialchars($order['district']); ?>
                <?php endif; ?>
                , <?php echo htmlspecialchars($order['state'] . ' - ' . $order['pincode']); ?>
              </div>
              <?php if (!empty($order['landmark'])): ?>
                <div class="fst-italic text-muted mt-2 pt-1 border-top"><small><i class="bi bi-pin-map me-1 text-gold"></i> Landmark: <?php echo htmlspecialchars($order['landmark']); ?></small></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Transport / Dispatch Info Card (If present) -->
    <?php 
    $has_transport = !empty($order['transport_name']) || 
                     !empty($order['transport_lr_number']) || 
                     !empty($order['transport_from']) || 
                     !empty($order['transport_to']) || 
                     !empty($order['transport_vehicle_number']);
    ?>
    <?php if ($has_transport): ?>
      <div class="card border shadow-sm rounded-3 mb-4">
        <div class="card-header bg-light border-bottom p-3 d-flex align-items-center justify-content-between">
          <h6 class="fw-bold text-navy mb-0">
            <i class="bi bi-truck text-gold me-1.5"></i> TRANSPORT DISPATCH & CARRIER DETAILS
          </h6>
          <span class="badge bg-navy text-gold fw-bold px-2.5 py-1 fs-7">PARCEL DISPATCH</span>
        </div>
        <div class="card-body p-3.5">
          <div class="row g-3 small text-dark">
            <?php if (!empty($order['transport_name'])): ?>
              <div class="col-sm-6 col-md-4">
                <span class="text-muted d-block">Transport Service:</span>
                <strong class="text-navy fs-6"><?php echo htmlspecialchars($order['transport_name']); ?></strong>
              </div>
            <?php endif; ?>
            <?php if (!empty($order['transport_lr_number'])): ?>
              <div class="col-sm-6 col-md-4">
                <span class="text-muted d-block">LR / Parcel Number:</span>
                <code class="fw-bold text-navy fs-6"><?php echo htmlspecialchars($order['transport_lr_number']); ?></code>
              </div>
            <?php endif; ?>
            <?php if (!empty($order['transport_from']) || !empty($order['transport_to'])): ?>
              <div class="col-sm-6 col-md-4">
                <span class="text-muted d-block">Dispatch Route:</span>
                <strong class="text-dark"><?php echo htmlspecialchars($order['transport_from']); ?> &rarr; <?php echo htmlspecialchars($order['transport_to']); ?></strong>
              </div>
            <?php endif; ?>
            <?php if (!empty($order['transport_vehicle_number'])): ?>
              <div class="col-sm-6 col-md-4">
                <span class="text-muted d-block">Vehicle Number:</span>
                <strong class="text-dark"><?php echo htmlspecialchars($order['transport_vehicle_number']); ?></strong>
              </div>
            <?php endif; ?>
            <?php if (!empty($order['transport_contact'])): ?>
              <div class="col-sm-6 col-md-4">
                <span class="text-muted d-block">Transport Contact:</span>
                <strong class="text-dark"><?php echo htmlspecialchars($order['transport_contact']); ?></strong>
              </div>
            <?php endif; ?>
            <?php if (!empty($order['transport_date'])): ?>
              <div class="col-sm-6 col-md-4">
                <span class="text-muted d-block">Dispatch Date:</span>
                <strong class="text-dark"><?php echo format_date($order['transport_date']); ?></strong>
              </div>
            <?php endif; ?>
            <?php if (!empty($order['transport_remarks'])): ?>
              <div class="col-12 pt-2 border-top text-muted">
                <strong>Remarks:</strong> <?php echo htmlspecialchars($order['transport_remarks']); ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Ordered Items Breakdown Table -->
    <div class="card border shadow-sm rounded-3 mb-4">
      <div class="card-header bg-light border-bottom p-3 d-flex align-items-center justify-content-between">
        <h6 class="fw-bold text-navy mb-0">
          <i class="bi bi-list-check text-gold me-1.5"></i> ORDERED PRODUCTS & HISTORICAL PRICES
        </h6>
        <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold">
          <?php echo count($order['items']); ?> Unique Items
        </span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light small text-muted">
              <tr>
                <th class="ps-3" style="width: 40px;">#</th>
                <th>Notebook & Variety</th>
                <th>Product Code</th>
                <th class="text-center" style="width: 80px;">Qty</th>
                <th class="text-end" style="width: 120px;">Price (Order Time)</th>
                <th class="text-end pe-3" style="width: 130px;">Subtotal</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $sn = 1;
              foreach ($order['items'] as $item): 
                $vInfo = parse_variety_info($item['ruling_type'] ?? '', $item['page_count'] ?? '');
                $itemCode = !empty($item['product_code']) ? $item['product_code'] : get_variant_product_code($item['product_id'], $item['ruling_type'] ?? '');
              ?>
                <tr>
                  <td class="ps-3 text-muted small"><?php echo $sn++; ?></td>
                  <td>
                    <strong class="text-navy d-block"><?php echo htmlspecialchars($item['product_name']); ?></strong>
                    <div class="small mt-0.5">
                      <?php if (!empty($vInfo['page_count'])): ?>
                        <span class="fw-semibold text-navy me-1"><?php echo htmlspecialchars($vInfo['page_count']); ?></span>
                      <?php endif; ?>
                      <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold px-1.5 py-0.5" style="font-size: 0.7rem;">
                        Variety: <?php echo htmlspecialchars($vInfo['variety']); ?>
                      </span>
                      <?php if (!empty($vInfo['order_type'])): ?>
                        &bull; <span class="badge bg-light text-dark border px-1.5 py-0.5" style="font-size: 0.7rem;"><?php echo htmlspecialchars($vInfo['order_type']); ?></span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td><code><?php echo htmlspecialchars($itemCode ?: 'N/A'); ?></code></td>
                  <td class="text-center">
                    <span class="badge bg-navy text-gold fw-bold fs-7 px-2.5 py-1"><?php echo $item['quantity']; ?></span>
                  </td>
                  <td class="text-end fw-semibold text-secondary">
                    <?php echo format_price($item['price']); ?>
                  </td>
                  <td class="text-end pe-3 fw-bold text-navy">
                    <?php echo format_price($item['total_price']); ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="card-footer bg-light p-3">
        <div class="row justify-content-end">
          <div class="col-md-5">
            <table class="table table-sm table-borderless small mb-0">
              <tr>
                <td class="text-secondary">Items Subtotal:</td>
                <td class="text-end fw-bold text-dark"><?php echo format_price($order['subtotal']); ?></td>
              </tr>
              <?php if ((float)$order['discount'] > 0): ?>
                <tr>
                  <td class="text-secondary">Discount:</td>
                  <td class="text-end text-success fw-bold">-<?php echo format_price($order['discount']); ?></td>
                </tr>
              <?php endif; ?>
              <?php if ((float)$order['delivery_fee'] > 0): ?>
                <tr>
                  <td class="text-secondary">Shipping / Delivery Fee:</td>
                  <td class="text-end text-dark"><?php echo format_price($order['delivery_fee']); ?></td>
                </tr>
              <?php endif; ?>
              <?php if ((float)$order['total_gst'] > 0): ?>
                <tr>
                  <td class="text-secondary">GST / Tax Included:</td>
                  <td class="text-end text-dark"><?php echo format_price($order['total_gst']); ?></td>
                </tr>
              <?php endif; ?>
              <tr class="border-top">
                <td class="fs-6 fw-bold text-navy pt-2">Final Grand Total:</td>
                <td class="fs-6 fw-bold text-navy text-end pt-2"><?php echo format_price($order['grand_total']); ?></td>
              </tr>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Physical Parcels / Packing Details (If packed) -->
    <?php if (!empty($order['parcels'])): ?>
      <div class="card border shadow-sm rounded-3 mb-4">
        <div class="card-header bg-light border-bottom p-3 d-flex align-items-center justify-content-between">
          <h6 class="fw-bold text-navy mb-0">
            <i class="bi bi-boxes text-gold me-1.5"></i> PHYSICAL PARCELS / PACKED BOXES (<?php echo count($order['parcels']); ?>)
          </h6>
          <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold">Ready for Dispatch</span>
        </div>
        <div class="card-body p-3">
          <div class="row g-3">
            <?php foreach ($order['parcels'] as $idx => $pcl): 
              $is_this_scanned = $searched_parcel && ($searched_parcel['id'] == $pcl['id']);
              $qr_pcl_img = generate_qr_code_image_url(get_parcel_tracking_url($pcl['parcel_number']), 140);
            ?>
              <div class="col-md-6">
                <div class="p-3 rounded-3 border <?php echo $is_this_scanned ? 'border-warning bg-warning-subtle' : 'bg-light'; ?>">
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <div>
                      <strong class="text-navy fs-6"><?php echo htmlspecialchars($pcl['parcel_number']); ?></strong>
                      <span class="badge bg-navy text-gold fw-bold ms-1" style="font-size: 0.7rem;">BOX <?php echo ($idx + 1); ?> OF <?php echo count($order['parcels']); ?></span>
                    </div>
                    <?php if ($is_this_scanned): ?>
                      <span class="badge bg-warning text-dark fw-bold">SCANNED BOX</span>
                    <?php endif; ?>
                  </div>

                  <div class="d-flex gap-3 align-items-center">
                    <img src="<?php echo $qr_pcl_img; ?>" alt="QR" style="width: 70px; height: 70px; border-radius: 6px;" class="border p-1 bg-white">
                    <div class="small flex-grow-1">
                      <div>Weight: <strong><?php echo !empty($pcl['parcel_weight']) ? $pcl['parcel_weight'] . ' kg' : 'N/A'; ?></strong></div>
                      <div>Packed on: <?php echo format_datetime($pcl['created_at']); ?></div>
                      <div class="mt-1">
                        <strong>Box Items:</strong>
                        <?php foreach ($pcl['items'] as $pi): ?>
                          <span class="badge bg-white text-dark border me-1 my-0.5">
                            <?php echo htmlspecialchars($pi['product_name']); ?> &times; <strong><?php echo $pi['quantity']; ?></strong>
                          </span>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- QR Share & Actions Card -->
    <div class="card border-0 shadow-sm rounded-4 text-center p-4" style="background: #FAF8F2;">
      <div class="d-flex justify-content-center align-items-center flex-column">
        <h6 class="fw-bold text-navy mb-2"><i class="bi bi-qr-code text-gold me-1.5"></i> Order Verification QR Code</h6>
        <p class="small text-muted mb-3" style="max-width: 480px;">
          This official QR Code permanently verifies Order <strong>#<?php echo htmlspecialchars($order['order_number']); ?></strong>. Scanning it on any smartphone immediately loads this verified order record.
        </p>

        <?php 
        $order_qr_img = generate_qr_code_image_url(get_order_tracking_url($order['order_number']), 200);
        ?>
        <div class="p-3 bg-white rounded-3 border shadow-sm mb-3">
          <img src="<?php echo $order_qr_img; ?>" alt="Order QR Code" style="width: 140px; height: 140px;">
          <div class="small fw-bold text-navy mt-1"><?php echo htmlspecialchars($order['order_number']); ?></div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap justify-content-center">
          <a href="<?php echo $order_qr_img; ?>" download="QR-<?php echo htmlspecialchars($order['order_number']); ?>.png" target="_blank" class="btn btn-outline-dark btn-sm fw-bold px-3">
            <i class="bi bi-download me-1"></i> Download QR Image
          </a>
          <button onclick="window.print()" class="btn btn-navy text-white btn-sm fw-bold px-3">
            <i class="bi bi-printer-fill text-gold me-1"></i> Print Order Verification
          </button>
          <a href="index.php" class="btn btn-gold btn-sm fw-bold px-3">
            <i class="bi bi-house-door-fill me-1"></i> Return to Store
          </a>
        </div>
      </div>
    </div>


  <?php elseif ($customer): ?>
    <!-- ========================================================================= -->
    <!-- 2. VERIFIED CUSTOMER PROFILE & LIFETIME ORDERS VIEW -->
    <!-- ========================================================================= -->
    
    <div class="card border-0 shadow-sm rounded-4 mb-4 overflow-hidden" style="background: linear-gradient(135deg, #17243A 0%, #223758 100%);">
      <div class="card-body p-4 text-white">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
          <span class="badge bg-success text-white fw-bold px-3 py-1.5 fs-7">
            <i class="bi bi-shield-check-fill me-1"></i> VERIFIED CUSTOMER PROFILE
          </span>
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-gold text-navy fw-bold px-3 py-1.5 fs-7">
              ID: <?php echo htmlspecialchars($customer['customer_code']); ?>
            </span>
            <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold px-2.5 py-1.5 fs-7">
              <i class="bi bi-briefcase me-1"></i><?php echo htmlspecialchars($customer['customer_type'] ?? 'Retailer'); ?>
            </span>
          </div>
        </div>

        <h1 class="display-6 fw-bold mb-1 text-gold"><?php echo htmlspecialchars($customer['name']); ?></h1>
        <div class="small text-white-50">
          Mobile: <strong class="text-white"><?php echo htmlspecialchars($customer['phone']); ?></strong> &bull; 
          Type: <strong class="text-gold"><?php echo htmlspecialchars($customer['customer_type'] ?? 'Retailer'); ?></strong> &bull; 
          Location: <strong class="text-white"><?php echo htmlspecialchars($customer['city'] . ', ' . $customer['state']); ?></strong>
        </div>
      </div>
    </div>

    <!-- Customer Summary Metrics -->
    <div class="row g-3 mb-4">
      <div class="col-sm-4">
        <div class="p-3 bg-white rounded-3 border text-center shadow-sm">
          <div class="small text-muted fw-bold text-uppercase">Total Orders Placed</div>
          <div class="fs-3 fw-bold text-navy"><?php echo (int)($customer['total_orders'] ?? count($customer_orders)); ?></div>
        </div>
      </div>
      <div class="col-sm-4">
        <div class="p-3 bg-white rounded-3 border text-center shadow-sm">
          <div class="small text-muted fw-bold text-uppercase">Total Lifetime Spend</div>
          <div class="fs-3 fw-bold text-gold"><?php echo format_price($customer['total_spent'] ?? 0); ?></div>
        </div>
      </div>
      <div class="col-sm-4">
        <div class="p-3 bg-white rounded-3 border text-center shadow-sm">
          <div class="small text-muted fw-bold text-uppercase">Latest Order</div>
          <div class="fs-6 fw-bold text-navy mt-1">
            <?php echo !empty($customer['latest_order_date']) ? format_datetime($customer['latest_order_date']) : 'N/A'; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Orders History Table -->
    <div class="card border shadow-sm rounded-3 mb-4">
      <div class="card-header bg-light border-bottom p-3">
        <h6 class="fw-bold text-navy mb-0"><i class="bi bi-clock-history text-gold me-1.5"></i> Order History for Customer <?php echo htmlspecialchars($customer['customer_code']); ?></h6>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light small text-muted">
              <tr>
                <th class="ps-3">Order #</th>
                <th>Date</th>
                <th>Items</th>
                <th class="text-end">Total</th>
                <th>Status</th>
                <th class="text-end pe-3">Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($customer_orders as $cord): ?>
                <tr>
                  <td class="ps-3 fw-bold text-navy"><?php echo htmlspecialchars($cord['order_number']); ?></td>
                  <td class="small text-muted"><?php echo format_datetime($cord['created_at']); ?></td>
                  <td class="small">
                    <?php foreach ($cord['items'] as $citm): ?>
                      <span class="badge bg-light text-dark border me-1">
                        <?php echo htmlspecialchars($citm['product_name']); ?> &times; <?php echo $citm['quantity']; ?>
                      </span>
                    <?php endforeach; ?>
                  </td>
                  <td class="text-end fw-bold text-navy"><?php echo format_price($cord['grand_total']); ?></td>
                  <td><?php echo render_order_status_badge($cord['order_status']); ?></td>
                  <td class="text-end pe-3">
                    <a href="track_order.php?ord=<?php echo urlencode($cord['order_number']); ?>" class="btn btn-sm btn-gold fw-bold">
                      View Order
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>


  <?php else: ?>
    <!-- ========================================================================= -->
    <!-- 3. ORDER / PARCEL NOT FOUND VIEW (Friendly Search Prompt) -->
    <!-- ========================================================================= -->
    
    <div class="card border-0 shadow-sm rounded-4 text-center p-5 mx-auto" style="max-width: 620px;">
      <div class="display-1 text-muted mb-3 opacity-50">
        <i class="bi bi-qr-code-scan"></i>
      </div>

      <?php if (!empty($search_term)): ?>
        <h3 class="fw-bold text-navy mb-2">Order / Parcel Not Found</h3>
        <p class="text-secondary small mb-4">
          We could not locate any order or parcel matching: <code class="fw-bold text-danger"><?php echo htmlspecialchars($search_term); ?></code>.<br>
          Please check the code on your parcel sticker or search using your registered phone number or Order ID below.
        </p>
      <?php else: ?>
        <h3 class="fw-bold text-navy mb-2">Live Order & Parcel Tracking</h3>
        <p class="text-secondary small mb-4">
          Scan the QR Code on your dispatch box or order invoice, or enter your Order Number below to check live status.
        </p>
      <?php endif; ?>

      <form action="track_order.php" method="GET" class="text-start">
        <div class="mb-3">
          <label class="form-label small fw-semibold text-navy">Enter Order # (e.g. ORD-...), Parcel # (e.g. PCL-...), or Customer ID</label>
          <div class="input-group">
            <span class="input-group-text bg-light text-secondary"><i class="bi bi-search"></i></span>
            <input type="text" name="ord" class="form-control" placeholder="e.g. ORD-20261005-0001 or PCL-20261008-0001" required value="<?php echo htmlspecialchars($search_term); ?>">
            <button type="submit" class="btn btn-gold fw-bold px-4">Track</button>
          </div>
        </div>
      </form>

      <div class="mt-4 pt-3 border-top">
        <a href="index.php" class="btn btn-outline-secondary btn-sm px-4">
          <i class="bi bi-house-door me-1"></i> Return to Home
        </a>
      </div>
    </div>

  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
