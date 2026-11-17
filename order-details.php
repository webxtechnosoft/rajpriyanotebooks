<?php
require_once __DIR__ . '/includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$order_param = sanitize($_GET['ord'] ?? ($_GET['order'] ?? ($_GET['order_number'] ?? '')));

$db = getDB();

if ($id > 0) {
    $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$id]);
    $order = $stmt->fetch();
} elseif (!empty($order_param)) {
    $stmt = $db->prepare("SELECT * FROM orders WHERE order_number = ?");
    $stmt->execute([$order_param]);
    $order = $stmt->fetch();
} else {
    $order = null;
}

if (!$order) {
    // If order not found, route to unified tracking page which shows friendly Order Not Found card
    header("Location: track_order.php" . (!empty($order_param) ? "?ord=" . urlencode($order_param) : ""));
    exit();
}

$stmt_items = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt_items->execute([$order['id']]);
$items = $stmt_items->fetchAll();

$logos = get_active_logos();

$sigPath = __DIR__ . '/assets/images/signature.webp';
if (!file_exists($sigPath) || !is_file($sigPath)) {
    $sigPath = __DIR__ . '/uploads/signature.webp';
}
if (!file_exists($sigPath) || !is_file($sigPath)) {
    $sigPath = __DIR__ . '/assets/images/signature_jpg.webp';
}
if (!file_exists($sigPath) || !is_file($sigPath)) {
    $sigPath = __DIR__ . '/uploads/signature_jpg.webp';
}
if (file_exists($sigPath) && is_file($sigPath)) {
    $sigMime = mime_content_type($sigPath) ?: 'image/png';
    $signatureSrc = 'data:' . $sigMime . ';base64,' . base64_encode(file_get_contents($sigPath));
} else {
    $signatureSrc = 'assets/images/signature.webp';
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
@media print {
  body {
    background: #FFFFFF !important;
    padding: 0 !important;
    margin: 0 !important;
  }
  .no-print, 
  header, 
  footer, 
  .top-bar, 
  .main-header, 
  .main-footer, 
  .navbar, 
  .btn, 
  .page-top-nav-wrap, 
  .screen-only-page-content,
  .order-success-wrapper {
    display: none !important;
  }
  
  .customer-print-invoice {
    display: block !important;
    position: absolute !important;
    top: 0 !important;
    left: 0 !important;
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #FFFFFF !important;
    z-index: 99999 !important;
  }
}

@media screen {
  .customer-print-invoice {
    display: none !important;
  }
}
</style>

<div class="container section-padding py-4 screen-only-page-content">
  <?php echo render_page_navigation('Order #' . $order['order_number'], ['My Orders' => 'orders.php', 'Order Details' => '']); ?>
  
  <div class="d-flex justify-content-end align-items-center gap-2 mb-4 no-print flex-wrap">
    <?php 
    $order_qr_link = get_order_tracking_url($order['order_number']);
    $order_qr_image = generate_qr_code_image_url($order_qr_link, 150);
    ?>
    <a href="<?php echo htmlspecialchars($order_qr_link); ?>" target="_blank" class="btn btn-outline-dark btn-sm px-3 fw-bold" title="Public Live Order & Parcel Tracking View">
      <i class="bi bi-qr-code text-gold me-1"></i> Track with QR
    </a>
    <?php 
    $ord_q = get_order_quotation($order['id']);
    if ($ord_q && $ord_q['status'] !== 'Draft' && is_customer_logged_in()): 
    ?>
      <a href="quotations.php?view=<?php echo $ord_q['id']; ?>" class="btn btn-outline-gold btn-sm px-3 fw-bold" title="View Wholesale Quotation">
        <i class="bi bi-file-earmark-text me-1"></i> View Quotation (<?php echo htmlspecialchars($ord_q['status']); ?>)
      </a>
    <?php endif; ?>
    <button onclick="window.print()" class="btn btn-warning btn-sm px-3 fw-bold"><i class="bi bi-printer me-1"></i> Print Invoice</button>
  </div>

  <div class="row g-4">
    <div class="col-lg-8">
      <div class="bg-white p-4 rounded-3 border mb-4">
        <h5 class="fw-bold text-navy mb-3">Order Items</h5>
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr class="table-light">
                <th>Product &amp; Variety</th>
                <th class="text-center" style="width: 100px;">Code</th>
                <th class="text-center" style="width: 95px;">Pcs / Bdl</th>
                <th class="text-center" style="width: 95px;">Bundles</th>
                <th class="text-center" style="width: 105px;">Total Pieces</th>
                <th class="text-end" style="width: 95px;">Price</th>
                <th class="text-end" style="width: 110px;">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): 
                $vInfo = parse_variety_info($item['ruling_type'] ?? '');
                $itemCode = !empty($item['product_code']) ? $item['product_code'] : get_variant_product_code($item['product_id'], $item['ruling_type'] ?? '');
                $isBundle = (stripos($vInfo['order_type'] ?? '', 'Bundle') !== false) || (stripos($item['ruling_type'] ?? '', 'Bundle') !== false);
                $qty = (int)$item['quantity'];
                $ppb = !empty($item['pieces_per_bundle']) ? (int)$item['pieces_per_bundle'] : get_product_pieces_per_bundle($item['product_id'], $item['ruling_type'] ?? '', null);
                $totalPieces = !empty($item['total_pieces']) ? (int)$item['total_pieces'] : ($isBundle ? ($qty * $ppb) : $qty);
              ?>
                <tr>
                  <td>
                    <div class="fw-bold text-navy"><?php echo htmlspecialchars($item['product_name']); ?></div>
                    <div class="small text-dark mt-0.5">
                      <?php if (!empty($vInfo['page_count'])): ?>
                        <span class="fw-bold text-navy me-1"><?php echo htmlspecialchars($vInfo['page_count']); ?></span>
                      <?php endif; ?>
                      <span class="badge" style="background-color: #FEF3C7; color: #92400E; border: 1px solid #F59E0B; font-weight: 700; font-size: 0.75rem; padding: 4px 8px;">Variety: <?php echo htmlspecialchars($vInfo['variety']); ?></span>
                      <?php if (!empty($vInfo['order_type'])): ?>
                        <span class="badge ms-1" style="background-color: #E0F2FE; color: #0369A1; border: 1px solid #38BDF8; font-weight: 700; font-size: 0.75rem; padding: 4px 8px;"><?php echo htmlspecialchars($vInfo['order_type']); ?></span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td class="text-center font-monospace small fw-bold text-navy"><?php echo htmlspecialchars($itemCode ?: '-'); ?></td>
                  <td class="text-center small text-muted"><?php echo $isBundle ? ($ppb . ' Pcs') : '1 (Loose)'; ?></td>
                  <td class="text-center fw-bold text-navy"><?php echo $isBundle ? $qty : '&mdash;'; ?></td>
                  <td class="text-center fw-bold">
                    <span class="badge <?php echo $isBundle ? 'bg-gold text-navy' : 'bg-light text-dark border'; ?> fs-7">
                      <?php echo $totalPieces; ?> Pcs
                    </span>
                  </td>
                  <td class="text-end fw-bold text-navy"><?php echo format_price($item['price']); ?></td>
                  <td class="text-end fw-bold text-navy"><?php echo format_price($item['total_price']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <?php $order_gst = get_order_gst_summary($order, $items); ?>
              <tr><td colspan="6" class="text-end fw-bold text-navy small">Taxable Amount:</td><td class="text-end fw-bold text-navy"><?php echo format_price($order_gst['taxable_amount']); ?></td></tr>
              <?php if ($order['discount'] > 0): ?>
                <tr><td colspan="6" class="text-end text-success fw-bold small">Discount:</td><td class="text-end text-success fw-bold">-<?php echo format_price($order['discount']); ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($order_gst['has_multiple_rates'])): ?>
                <?php foreach ($order_gst['rates_breakdown'] as $r): ?>
                  <tr>
                    <td colspan="6" class="text-end text-muted small"><?php echo htmlspecialchars($r['rate_label']); ?>:</td>
                    <td class="text-end text-muted"><?php echo format_price($r['gst_amount']); ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr>
                  <td colspan="6" class="text-end fw-semibold text-navy small">Total GST:</td>
                  <td class="text-end fw-semibold text-navy"><?php echo format_price($order_gst['total_gst']); ?></td>
                </tr>
              <?php else: ?>
                <?php $single_rate = !empty($order_gst['rates_breakdown']) ? reset($order_gst['rates_breakdown']) : null; ?>
                <tr><td colspan="6" class="text-end text-muted small">CGST (<?php echo $single_rate ? $single_rate['cgst_rate'] : 9; ?>%):</td><td class="text-end text-muted"><?php echo format_price($order_gst['cgst']); ?></td></tr>
                <tr><td colspan="6" class="text-end text-muted small">SGST (<?php echo $single_rate ? $single_rate['sgst_rate'] : 9; ?>%):</td><td class="text-end text-muted"><?php echo format_price($order_gst['sgst']); ?></td></tr>
                <tr><td colspan="6" class="text-end fw-semibold text-navy small">Total GST (<?php echo $single_rate ? $single_rate['rate'] : 18; ?>%):</td><td class="text-end fw-semibold text-navy"><?php echo format_price($order_gst['total_gst']); ?></td></tr>
              <?php endif; ?>
              <?php if ($order['delivery_fee'] > 0): ?>
              <tr><td colspan="6" class="text-end fw-bold text-navy small">Delivery Fee:</td><td class="text-end fw-bold text-navy"><?php echo format_price($order['delivery_fee']); ?></td></tr>
              <?php endif; ?>
              <tr class="fs-5 fw-bold"><td colspan="6" class="text-end text-navy">Grand Total:</td><td class="text-end text-gold"><?php echo format_price($order['grand_total']); ?></td></tr>
            </tfoot>
          </table>
        </div>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="bg-white p-4 rounded-3 border mb-4 shadow-sm">
        <h5 class="fw-bold text-navy mb-3"><i class="bi bi-clock-history text-gold me-1.5"></i> Order Status</h5>
        <div class="p-3.5 bg-light rounded-3 border text-center mb-0">
          <div class="mb-2">
            <?php echo render_order_status_badge($order['order_status']); ?>
          </div>
          <div class="small fw-bold text-navy mt-2.5">
            Payment Status: 
            <span class="badge" style="background-color: #FEF3C7; color: #92400E; border: 1px solid #F59E0B; font-weight: 700; padding: 4px 10px; font-size: 0.8rem; margin-left: 4px;">
              <?php echo htmlspecialchars($order['payment_status']); ?>
            </span>
          </div>
        </div>
      </div>

      <?php 
      $has_transport = !empty($order['transport_from']) || 
                       !empty($order['transport_to']) || 
                       !empty($order['transport_name']) || 
                       !empty($order['transport_branch']) || 
                       !empty($order['transport_contact']) || 
                       !empty($order['transport_lr_number']) || 
                       !empty($order['transport_vehicle_number']) || 
                       !empty($order['transport_date']) || 
                       !empty($order['transport_remarks']);
      ?>

      <?php if ($has_transport): ?>
        <div class="bg-white p-4 rounded-3 border shadow-sm mb-4">
          <h5 class="fw-bold text-navy mb-3"><i class="bi bi-truck text-gold me-1.5"></i> Transport / Parcel Details</h5>
          <div class="p-3 bg-light rounded border small text-dark">
            <div class="row g-2">
              <?php if (!empty($order['transport_from'])): ?>
                <div class="col-12 col-sm-6"><strong>From:</strong> <?php echo htmlspecialchars($order['transport_from']); ?></div>
              <?php endif; ?>
              <?php if (!empty($order['transport_to'])): ?>
                <div class="col-12 col-sm-6"><strong>To:</strong> <?php echo htmlspecialchars($order['transport_to']); ?></div>
              <?php endif; ?>
              <?php if (!empty($order['transport_name'])): ?>
                <div class="col-12"><strong>Transport Service:</strong> <?php echo htmlspecialchars($order['transport_name']); ?></div>
              <?php endif; ?>
              <?php if (!empty($order['transport_branch'])): ?>
                <div class="col-12 col-sm-6"><strong>Branch:</strong> <?php echo htmlspecialchars($order['transport_branch']); ?></div>
              <?php endif; ?>
              <?php if (!empty($order['transport_contact'])): ?>
                <div class="col-12 col-sm-6"><strong>Contact:</strong> <?php echo htmlspecialchars($order['transport_contact']); ?></div>
              <?php endif; ?>
              <?php if (!empty($order['transport_lr_number'])): ?>
                <div class="col-12 col-sm-6"><strong>LR / Parcel No:</strong> <code class="fw-bold text-navy"><?php echo htmlspecialchars($order['transport_lr_number']); ?></code></div>
              <?php endif; ?>
              <?php if (!empty($order['transport_vehicle_number'])): ?>
                <div class="col-12 col-sm-6"><strong>Vehicle No:</strong> <?php echo htmlspecialchars($order['transport_vehicle_number']); ?></div>
              <?php endif; ?>
              <?php if (!empty($order['transport_date'])): ?>
                <div class="col-12"><strong>Transport Date:</strong> <?php echo format_date($order['transport_date']); ?></div>
              <?php endif; ?>
              <?php if (!empty($order['transport_remarks'])): ?>
                <div class="col-12 mt-1 pt-1 border-top"><strong>Remarks:</strong> <?php echo htmlspecialchars($order['transport_remarks']); ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <div class="bg-white p-4 rounded-3 border shadow-sm">
        <h5 class="fw-bold text-navy mb-3"><i class="bi bi-geo-alt-fill text-gold me-1.5"></i> Delivery Information</h5>
        <div class="small text-dark">
          <strong class="d-block text-navy fs-6 mb-2"><?php echo htmlspecialchars($order['customer_name']); ?></strong>
          <?php if (!empty($order['customer_code'])): ?>
            <div class="mb-1 text-dark fw-medium">
              <i class="bi bi-person-badge-fill text-gold me-2"></i>
              Customer ID: <span class="badge bg-navy text-gold px-2 py-0.5"><?php echo htmlspecialchars($order['customer_code']); ?></span>
              <?php if (!empty($order['customer_type'])): ?>
                <span class="badge bg-gold-subtle text-navy border border-warning-subtle px-2 py-0.5 ms-1"><?php echo htmlspecialchars($order['customer_type']); ?></span>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="mb-1 text-dark fw-medium"><i class="bi bi-telephone-fill text-gold me-2"></i><?php echo htmlspecialchars($order['customer_phone']); ?></div>
          <div class="mb-1 text-dark fw-medium">
            <i class="bi bi-envelope-fill text-gold me-2"></i>
            <?php echo !empty($order['customer_email']) ? htmlspecialchars($order['customer_email']) : '<span class="text-muted fst-italic">Email: Not Provided</span>'; ?>
          </div>
          <div class="mb-2 text-dark fw-medium">
            <i class="bi bi-receipt text-gold me-2"></i>
            GST: <?php echo !empty($order['gst_number']) ? '<span class="badge bg-gold text-navy px-2 py-0.5">' . htmlspecialchars($order['gst_number']) . '</span>' : '<span class="text-muted fst-italic">Not Provided</span>'; ?>
          </div>
          <div class="p-3 bg-light rounded border border-secondary border-opacity-25 mt-2">
            <div class="fw-bold text-navy mb-1"><i class="bi bi-building me-1 text-gold"></i> Shipping Address</div>
            <div class="text-dark fw-medium"><?php echo htmlspecialchars($order['shipping_address']); ?></div>
            <?php if (!empty($order['address_line2'])): ?>
              <div class="text-dark small"><?php echo htmlspecialchars($order['address_line2']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['area'])): ?>
              <div class="text-dark small">Area: <?php echo htmlspecialchars($order['area']); ?></div>
            <?php endif; ?>
            <div class="text-dark fw-bold mt-1">
              <?php echo htmlspecialchars($order['city']); ?>
              <?php if (!empty($order['district']) && strcasecmp($order['district'], $order['city']) !== 0): ?>
                , <?php echo htmlspecialchars($order['district']); ?>
              <?php endif; ?>
              , <?php echo htmlspecialchars($order['state'] . ' - ' . $order['pincode']); ?>
            </div>
            <?php if (!empty($order['landmark'])): ?>
              <div class="fst-italic text-secondary mt-1 small">Landmark: <?php echo htmlspecialchars($order['landmark']); ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- OFFICIAL CUSTOMER TAX INVOICE PRINT LETTERHEAD (PRINT ONLY) -->
<div class="customer-print-invoice">
  <!-- 1. Header & Brand Lockup -->
  <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #17243A; padding-bottom: 12px; margin-bottom: 16px;">
    <div>
      <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 4px;">
        <img src="<?php echo htmlspecialchars($logos['primary']); ?>" alt="Raj Priya Notebooks" style="height: 48px; object-fit: contain;">
        <div>
          <h2 style="font-weight: 800; color: #17243A; margin: 0; font-size: 1.4rem; letter-spacing: 0.5px;">RAJ PRIYA NOTEBOOKS</h2>
          <div style="font-size: 0.75rem; color: #C89B3C; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Write Every Idea. Keep Every Thought.</div>
        </div>
      </div>
      <div style="font-size: 0.775rem; color: #475569; margin-top: 4px; line-height: 1.4;">
        <strong>Mfd. by: Prabu Trader</strong>, SALEM, Tamil Nadu, India<br>
        <strong>GSTIN:</strong> <?php echo htmlspecialchars(COMPANY_GSTIN); ?><br>
        WhatsApp: 98427 46472 | Sales: 94433 46472 | Email: sales@rajpriyanotebooks.com | Web: www.rajpriyanotebooks.com
      </div>
    </div>
    <div style="text-align: right; display: flex; align-items: flex-start; gap: 12px; justify-content: flex-end;">
      <div>
        <span style="background: #17243A; color: #C89B3C; font-weight: 800; font-size: 0.75rem; padding: 4px 12px; border-radius: 20px; text-transform: uppercase; display: inline-block; margin-bottom: 6px;">
          OFFICIAL ORDER RECEIPT
        </span>
        <div style="font-weight: 800; color: #17243A; font-size: 1.1rem;">Order #: <?php echo htmlspecialchars($order['order_number']); ?></div>
        <div style="font-size: 0.775rem; color: #64748B;">Date: <?php echo format_datetime($order['created_at']); ?></div>
        <div style="font-size: 0.775rem; color: #17243A; font-weight: 600; margin-top: 2px;">
          Payment: <?php echo htmlspecialchars($order['payment_method']); ?> (<?php echo htmlspecialchars($order['payment_status']); ?>)
        </div>
      </div>
      <div style="text-align: center;">
        <img src="<?php echo $order_qr_image; ?>" alt="QR" style="width: 72px; height: 72px; border: 1px solid #cbd5e1; border-radius: 4px; padding: 2px; background: #fff;">
        <div style="font-size: 0.55rem; color: #64748B; font-weight: 700; margin-top: 2px;">SCAN TO VERIFY</div>
      </div>
    </div>
  </div>

  <!-- 2. Customer & Shipping Address Grid -->
  <div style="display: flex; gap: 16px; margin-bottom: 16px;">
    <div style="flex: 1; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 12px;">
      <div style="font-weight: 700; color: #17243A; font-size: 0.85rem; border-bottom: 1px solid #E2E8F0; padding-bottom: 4px; margin-bottom: 6px;">
        Billed Customer Details
      </div>
      <div style="font-weight: 700; color: #17243A; font-size: 0.9rem;"><?php echo htmlspecialchars($order['customer_name']); ?></div>
      <?php if (!empty($order['customer_code'])): ?>
        <div style="font-size: 0.8rem; color: #17243A; font-weight: 700; margin-bottom: 2px;">Customer ID: <span style="color: #92400E;"><?php echo htmlspecialchars($order['customer_code']); ?></span></div>
      <?php endif; ?>
      <div style="font-size: 0.8rem; color: #475569;">Phone: <?php echo htmlspecialchars($order['customer_phone']); ?></div>
      <div style="font-size: 0.8rem; color: #475569;">Email: <?php echo !empty($order['customer_email']) ? htmlspecialchars($order['customer_email']) : 'Not Provided'; ?></div>
      <div style="font-size: 0.8rem; color: #17243A; font-weight: 600; margin-top: 2px;">GST Number: <?php echo !empty($order['gst_number']) ? htmlspecialchars($order['gst_number']) : 'Not Provided'; ?></div>
    </div>
    <div style="flex: 1; background: #F8FAFC; border: 1px solid #E2E8F0; border-radius: 8px; padding: 12px;">
      <div style="font-weight: 700; color: #17243A; font-size: 0.85rem; border-bottom: 1px solid #E2E8F0; padding-bottom: 4px; margin-bottom: 6px;">
        Shipping Address
      </div>
      <div style="font-size: 0.8rem; color: #1E293B; line-height: 1.4;">
        <div><?php echo htmlspecialchars($order['shipping_address']); ?></div>
        <?php if (!empty($order['address_line2'])): ?>
          <div><?php echo htmlspecialchars($order['address_line2']); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['area'])): ?>
          <div>Area: <?php echo htmlspecialchars($order['area']); ?></div>
        <?php endif; ?>
        <div>
          <?php echo htmlspecialchars($order['city']); ?>
          <?php if (!empty($order['district']) && strcasecmp($order['district'], $order['city']) !== 0): ?>
            , <?php echo htmlspecialchars($order['district']); ?>
          <?php endif; ?>
          , <?php echo htmlspecialchars($order['state'] . ' - ' . $order['pincode']); ?>
        </div>
        <?php if (!empty($order['landmark'])): ?>
          <div style="font-style: italic; color: #64748B; margin-top: 2px;">Landmark: <?php echo htmlspecialchars($order['landmark']); ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($has_transport): ?>
    <!-- 2.5 Transport / Parcel Reference Block (Print Only) -->
    <div style="background: #F8FAFC; border: 1px solid #CBD5E1; border-radius: 8px; padding: 12px; margin-bottom: 16px;">
      <div style="font-weight: 700; color: #17243A; font-size: 0.85rem; border-bottom: 1px solid #CBD5E1; padding-bottom: 4px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
        <span>TRANSPORT / PARCEL DETAILS</span>
        <span style="font-size: 0.7rem; background: #17243A; color: #C89B3C; font-weight: 700; padding: 2px 8px; border-radius: 4px;">DISPATCH REF</span>
      </div>
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; font-size: 0.8rem; color: #1E293B;">
        <?php if (!empty($order['transport_from'])): ?>
          <div><strong>From Location:</strong> <?php echo htmlspecialchars($order['transport_from']); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['transport_to'])): ?>
          <div><strong>To Location:</strong> <?php echo htmlspecialchars($order['transport_to']); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['transport_name'])): ?>
          <div><strong>Transport Service:</strong> <?php echo htmlspecialchars($order['transport_name']); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['transport_branch'])): ?>
          <div><strong>Branch Location:</strong> <?php echo htmlspecialchars($order['transport_branch']); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['transport_contact'])): ?>
          <div><strong>Contact Phone:</strong> <?php echo htmlspecialchars($order['transport_contact']); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['transport_lr_number'])): ?>
          <div><strong>LR / Parcel No:</strong> <span style="font-family: monospace; font-weight: 700; color: #17243A;"><?php echo htmlspecialchars($order['transport_lr_number']); ?></span></div>
        <?php endif; ?>
        <?php if (!empty($order['transport_vehicle_number'])): ?>
          <div><strong>Vehicle No:</strong> <?php echo htmlspecialchars($order['transport_vehicle_number']); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['transport_date'])): ?>
          <div><strong>Transport Date:</strong> <?php echo format_date($order['transport_date']); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['transport_remarks'])): ?>
          <div style="grid-column: span 2; border-top: 1px dashed #CBD5E1; padding-top: 4px; margin-top: 2px;"><strong>Remarks:</strong> <?php echo htmlspecialchars($order['transport_remarks']); ?></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- 3. Ordered Items Table -->
  <div style="font-weight: 700; color: #17243A; font-size: 0.9rem; margin-bottom: 8px;">Order Items Summary</div>
  <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 0.825rem;">
    <thead>
      <tr style="background: #17243A; color: #FFFFFF; text-align: left; font-size: 0.76rem; text-transform: uppercase;">
        <th style="border: 1px solid #17243A; padding: 7px 6px; width: 28px; text-align: center;">#</th>
        <th style="border: 1px solid #17243A; padding: 7px 8px;">Notebook Item &amp; Variety</th>
        <th style="border: 1px solid #17243A; padding: 7px 6px; width: 100px; text-align: center;">Product Code</th>
        <th style="border: 1px solid #17243A; padding: 7px 6px; width: 85px; text-align: center;">Pcs / Bdl</th>
        <th style="border: 1px solid #17243A; padding: 7px 6px; width: 75px; text-align: center;">Bundles</th>
        <th style="border: 1px solid #17243A; padding: 7px 6px; width: 85px; text-align: center;">Total Pieces</th>
        <th style="border: 1px solid #17243A; padding: 7px 6px; width: 85px; text-align: right;">Unit Price</th>
        <th style="border: 1px solid #17243A; padding: 7px 6px; width: 95px; text-align: right;">Total Price</th>
      </tr>
    </thead>
    <tbody>
      <?php 
      $p_sn = 1;
      foreach ($items as $itm): 
        $vInfo = parse_variety_info($itm['ruling_type'] ?? '');
        $itemCode = !empty($itm['product_code']) ? $itm['product_code'] : get_variant_product_code($itm['product_id'], $itm['ruling_type'] ?? '');
        $isBundle = (stripos($vInfo['order_type'] ?? '', 'Bundle') !== false) || (stripos($itm['ruling_type'] ?? '', 'Bundle') !== false);
        $qty = (int)$itm['quantity'];
        $ppb = !empty($itm['pieces_per_bundle']) ? (int)$itm['pieces_per_bundle'] : get_product_pieces_per_bundle($itm['product_id'], $itm['ruling_type'] ?? '', null);
        $totalPieces = !empty($itm['total_pieces']) ? (int)$itm['total_pieces'] : ($isBundle ? ($qty * $ppb) : $qty);
      ?>
        <tr>
          <td style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: center; color: #64748B;"><?php echo $p_sn++; ?></td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 8px;">
            <strong style="color: #17243A; font-size: 0.85rem;"><?php echo htmlspecialchars($itm['product_name']); ?></strong>
            <div style="color: #64748B; font-size: 0.75rem; margin-top: 2px;">
              <?php if (!empty($vInfo['page_count'])): ?>
                <span><?php echo htmlspecialchars($vInfo['page_count']); ?></span> &bull;
              <?php endif; ?>
              <span>Variety: <?php echo htmlspecialchars($vInfo['variety']); ?></span>
              <?php if (!empty($vInfo['order_type'])): ?>
                &bull; <span><?php echo htmlspecialchars($vInfo['order_type']); ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 8px; font-family: monospace; font-weight: 700; color: #17243A; text-align: center;"><?php echo htmlspecialchars($itemCode ?: '-'); ?></td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: center; color: #475569;"><?php echo $isBundle ? ($ppb . ' Pcs') : '1 (Loose)'; ?></td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: center; font-weight: 700; color: #17243A;"><?php echo $isBundle ? $qty : '&mdash;'; ?></td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: center; font-weight: 700; color: #0F172A; background: #F8FAFC;"><?php echo $totalPieces; ?> Pcs</td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: right;"><?php echo format_price($itm['price']); ?></td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: right; font-weight: 700; color: #17243A;"><?php echo format_price($itm['total_price']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: right; font-weight: 600;">Taxable Amount:</td>
        <td style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: right; font-weight: 600;"><?php echo format_price($order_gst['taxable_amount']); ?></td>
      </tr>
      <?php if ($order['discount'] > 0): ?>
        <tr>
          <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: right; color: #16A34A; font-weight: 600;">Discount Applied:</td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: right; color: #16A34A; font-weight: 600;">-<?php echo format_price($order['discount']); ?></td>
        </tr>
      <?php endif; ?>
      <?php if (!empty($order_gst['has_multiple_rates'])): ?>
        <?php foreach ($order_gst['rates_breakdown'] as $r): ?>
          <tr>
            <td colspan="7" style="border: 1px solid #CBD5E1; padding: 5px 8px; text-align: right; color: #475569;"><?php echo htmlspecialchars($r['rate_label']); ?>:</td>
            <td style="border: 1px solid #CBD5E1; padding: 5px 8px; text-align: right; color: #475569;"><?php echo format_price($r['gst_amount']); ?></td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: right; font-weight: 600; color: #17243A;">Total GST:</td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: right; font-weight: 600; color: #17243A;"><?php echo format_price($order_gst['total_gst']); ?></td>
        </tr>
      <?php else: ?>
        <?php $single_rate = !empty($order_gst['rates_breakdown']) ? reset($order_gst['rates_breakdown']) : null; ?>
        <tr>
          <td colspan="7" style="border: 1px solid #CBD5E1; padding: 5px 8px; text-align: right; color: #475569;">CGST (<?php echo $single_rate ? $single_rate['cgst_rate'] : 9; ?>%):</td>
          <td style="border: 1px solid #CBD5E1; padding: 5px 8px; text-align: right; color: #475569;"><?php echo format_price($order_gst['cgst']); ?></td>
        </tr>
        <tr>
          <td colspan="7" style="border: 1px solid #CBD5E1; padding: 5px 8px; text-align: right; color: #475569;">SGST (<?php echo $single_rate ? $single_rate['sgst_rate'] : 9; ?>%):</td>
          <td style="border: 1px solid #CBD5E1; padding: 5px 8px; text-align: right; color: #475569;"><?php echo format_price($order_gst['sgst']); ?></td>
        </tr>
        <tr>
          <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: right; font-weight: 600; color: #17243A;">Total GST (<?php echo $single_rate ? $single_rate['rate'] : 18; ?>%):</td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: right; font-weight: 600; color: #17243A;"><?php echo format_price($order_gst['total_gst']); ?></td>
        </tr>
      <?php endif; ?>
      <?php if ($order['delivery_fee'] > 0): ?>
      <tr>
        <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: right;">Delivery Fee:</td>
        <td style="border: 1px solid #CBD5E1; padding: 6px 8px; text-align: right;"><?php echo format_price($order['delivery_fee']); ?></td>
      </tr>
      <?php endif; ?>
      <tr style="background: #FCF8EF;">
        <td colspan="7" style="border: 1px solid #CBD5E1; padding: 8px; text-align: right; font-size: 0.95rem; font-weight: 800; color: #17243A;">Grand Total:</td>
        <td style="border: 1px solid #CBD5E1; padding: 8px; text-align: right; font-size: 0.95rem; font-weight: 800; color: #C89B3C;"><?php echo format_price($order['grand_total']); ?></td>
      </tr>
    </tfoot>
  </table>

  <!-- 4. Footer & Sign-off -->
  <div style="display: flex; justify-content: space-between; align-items: flex-end; padding-top: 12px; border-top: 1px solid #CBD5E1; font-size: 0.775rem; margin-top: 20px;">
    <div style="color: #64748B; max-width: 60%;">
      <strong style="color: #17243A;">Thank you for your business with Raj Priya Notebooks!</strong><br>
      This is a computer generated order receipt. For any questions, contact sales@rajpriyanotebooks.com.
    </div>
    <div style="text-align: center; color: #17243A; min-width: 150px;">
      <div style="font-weight: 700; margin-bottom: 4px; font-size: 0.85rem; color: #17243A;">Prabu Traders</div>
      <div style="height: 65px; display: flex; align-items: center; justify-content: center; margin: 2px 0;">
        <img src="<?php echo htmlspecialchars($signatureSrc); ?>" alt="Authorized Signature" style="max-height: 65px; max-width: 140px; width: auto; height: auto; object-fit: contain; display: block; margin: 0 auto;">
      </div>
      <div style="font-size: 0.7rem; color: #64748B;">(Authorized Signatory)</div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
