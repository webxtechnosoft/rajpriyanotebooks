<?php
require_once __DIR__ . '/includes/functions.php';

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$db = getDB();

$stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    header("Location: index.php");
    exit();
}

$stmt_items = $db->prepare("
    SELECT oi.*, p.image, p.pages, p.size 
    FROM order_items oi 
    LEFT JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id = ?
");
$stmt_items->execute([$order['id']]);
$order_items = $stmt_items->fetchAll();

$logos = get_active_logos();

if (!function_exists('render_customer_tax_invoice_block')) {
function render_customer_tax_invoice_block($order, $order_items, $logos, $order_gst, $has_transport) {
    ob_start();
    
    // Embed primary logo as base64 data URI if file exists locally so offline invoices display logo properly
    $primaryLogo = !empty($logos['primary']) ? $logos['primary'] : 'uploads/logos/logo_primary.webp';
    $fullLogoPath = __DIR__ . '/' . ltrim($primaryLogo, '/');
    if (file_exists($fullLogoPath) && is_file($fullLogoPath)) {
        $mime = mime_content_type($fullLogoPath) ?: 'image/png';
        $logoSrc = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullLogoPath));
    } else {
        $logoSrc = $primaryLogo;
    }

    // Embed authorized signature image as base64 data URI (prefer transparent PNG)
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
    ?>
  <!-- 1. Header & Brand Lockup -->
  <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2.5px solid #17243A; padding-bottom: 14px; margin-bottom: 16px;">
    <div style="flex: 1; padding-right: 20px;">
      <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
        <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="Raj Priya Notebooks" style="height: 48px; object-fit: contain;">
        <div>
          <h2 style="font-weight: 800; color: #17243A; margin: 0; font-size: 1.35rem; letter-spacing: 0.5px; line-height: 1.2;">RAJ PRIYA NOTEBOOKS</h2>
          <div style="font-size: 0.725rem; color: #C89B3C; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Write Every Idea. Keep Every Thought.</div>
        </div>
      </div>
      <div style="font-size: 0.76rem; color: #475569; line-height: 1.45; margin-top: 4px;">
        <strong>Mfd. by: Prabu Trader</strong>, SALEM, Tamil Nadu, India &bull; <strong>GSTIN:</strong> <?php echo htmlspecialchars(COMPANY_GSTIN); ?><br>
        WhatsApp: 98427 46472 | Sales: 94433 46472 | Email: sales@rajpriyanotebooks.com | Web: www.rajpriyanotebooks.com
      </div>
    </div>
    
    <div style="text-align: right; flex-shrink: 0; white-space: nowrap; display: flex; align-items: flex-start; gap: 10px; justify-content: flex-end;">
      <div>
        <span style="background: #17243A; color: #C89B3C; font-weight: 800; font-size: 0.75rem; padding: 4px 14px; border-radius: 20px; text-transform: uppercase; display: inline-block; margin-bottom: 6px; letter-spacing: 0.5px;">
          OFFICIAL ORDER RECEIPT
        </span>
        <div style="font-weight: 800; color: #17243A; font-size: 1.05rem; white-space: nowrap; line-height: 1.2;">
          Order #: <?php echo htmlspecialchars($order['order_number']); ?>
        </div>
        <div style="font-size: 0.775rem; color: #64748B; margin-top: 3px; white-space: nowrap;">
          Date: <?php echo format_datetime($order['created_at']); ?>
        </div>
        <div style="font-size: 0.775rem; color: #17243A; font-weight: 600; margin-top: 3px; white-space: nowrap;">
          Payment: <?php echo htmlspecialchars($order['payment_method']); ?> 
          <span style="color: #92400E; font-weight: 700;">(<?php echo htmlspecialchars($order['payment_status']); ?>)</span>
        </div>
      </div>
      <?php 
      $receipt_qr_link = get_order_tracking_url($order['order_number']);
      $receipt_qr_img = generate_qr_code_image_url($receipt_qr_link, 130);
      ?>
      <div style="text-align: center;">
        <img src="<?php echo $receipt_qr_img; ?>" alt="QR" style="width: 66px; height: 66px; border: 1px solid #CBD5E1; border-radius: 4px; padding: 2px; background: #fff;">
        <div style="font-size: 0.525rem; color: #64748B; font-weight: 700; margin-top: 2px;">SCAN TO VERIFY</div>
      </div>
    </div>
  </div>

  <!-- 2. Customer & Shipping Address Grid -->
  <div style="display: flex; gap: 14px; margin-bottom: 16px; align-items: stretch;">
    <div style="flex: 1; background: #F8FAFC; border: 1.5px solid #CBD5E1; border-radius: 8px; padding: 12px 14px;">
      <div style="font-weight: 800; color: #17243A; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #CBD5E1; padding-bottom: 5px; margin-bottom: 8px;">
        Billed Customer Details
      </div>
      <div style="font-weight: 800; color: #17243A; font-size: 0.95rem; margin-bottom: 3px;">
        <?php echo htmlspecialchars($order['customer_name']); ?>
      </div>
      <?php if (!empty($order['customer_code'])): ?>
      <div style="font-size: 0.8rem; color: #17243A; font-weight: 700; margin-bottom: 2px;">
        <strong>Customer ID:</strong> <span style="color: #92400E;"><?php echo htmlspecialchars($order['customer_code']); ?></span>
        <?php if (!empty($order['customer_type'])): ?>
          <span style="font-size: 0.72rem; background: #FEF3C7; color: #92400E; padding: 1px 6px; border-radius: 4px; font-weight: 700; border: 1px solid #FCD34D; margin-left: 4px;"><?php echo htmlspecialchars($order['customer_type']); ?></span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div style="font-size: 0.8rem; color: #475569; margin-bottom: 2px;">
        <strong>Phone:</strong> <?php echo htmlspecialchars($order['customer_phone']); ?>
      </div>
      <div style="font-size: 0.8rem; color: #475569; margin-bottom: 2px;">
        <strong>Email:</strong> <?php echo !empty($order['customer_email']) ? htmlspecialchars($order['customer_email']) : 'Not Provided'; ?>
      </div>
      <div style="font-size: 0.8rem; color: #17243A; font-weight: 600; margin-top: 4px;">
        <strong>GST Number:</strong> <?php echo !empty($order['gst_number']) ? htmlspecialchars($order['gst_number']) : 'Not Provided'; ?>
      </div>
    </div>

    <div style="flex: 1; background: #F8FAFC; border: 1.5px solid #CBD5E1; border-radius: 8px; padding: 12px 14px;">
      <div style="font-weight: 800; color: #17243A; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #CBD5E1; padding-bottom: 5px; margin-bottom: 8px;">
        Shipping Address
      </div>
      <div style="font-size: 0.8rem; color: #1E293B; line-height: 1.45;">
        <div style="font-weight: 600; color: #17243A;"><?php echo htmlspecialchars($order['shipping_address']); ?></div>
        <?php if (!empty($order['address_line2'])): ?>
          <div><?php echo htmlspecialchars($order['address_line2']); ?></div>
        <?php endif; ?>
        <?php if (!empty($order['area'])): ?>
          <div>Area: <?php echo htmlspecialchars($order['area']); ?></div>
        <?php endif; ?>
        <div style="font-weight: 600;">
          <?php echo htmlspecialchars($order['city']); ?>
          <?php if (!empty($order['district']) && strcasecmp($order['district'], $order['city']) !== 0): ?>
            , <?php echo htmlspecialchars($order['district']); ?>
          <?php endif; ?>
          , <?php echo htmlspecialchars($order['state'] . ' - ' . $order['pincode']); ?>
        </div>
        <?php if (!empty($order['landmark'])): ?>
          <div style="font-style: italic; color: #64748B; margin-top: 3px;">Landmark: <?php echo htmlspecialchars($order['landmark']); ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($has_transport): ?>
    <!-- 2.5 Transport / Parcel Reference Block (Print Only) -->
    <div style="background: #F8FAFC; border: 1.5px solid #CBD5E1; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px;">
      <div style="font-weight: 800; color: #17243A; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #CBD5E1; padding-bottom: 5px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
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
  <div style="font-weight: 800; color: #17243A; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Order Items Summary</div>
  <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 0.825rem;">
    <thead>
      <tr style="text-align: left; font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.5px; background: #17243A; color: #FFFFFF;">
        <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 28px; text-align: center; vertical-align: middle;">#</th>
        <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 8px; vertical-align: middle; line-height: 1.25;">Notebook Item &amp; Variety</th>
        <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 100px; text-align: center; vertical-align: middle;">Product Code</th>
        <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 85px; text-align: center; vertical-align: middle;">Pcs / Bundle</th>
        <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 75px; text-align: center; vertical-align: middle;">Bundles</th>
        <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 85px; text-align: center; vertical-align: middle;">Total Pieces</th>
        <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 85px; text-align: right; vertical-align: middle;">Unit Price</th>
        <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 95px; text-align: right; vertical-align: middle;">Total Price</th>
      </tr>
    </thead>
    <tbody>
      <?php 
      $p_sn = 1;
      foreach ($order_items as $itm): 
        $itmVInfo = parse_variety_info($itm['ruling_type'] ?? '');
        $itemCode = !empty($itm['product_code']) ? $itm['product_code'] : get_variant_product_code($itm['product_id'], $itm['ruling_type'] ?? '');
        $itmIsBundle = (stripos($itmVInfo['order_type'] ?? '', 'Bundle') !== false) || (stripos($itm['ruling_type'] ?? '', 'Bundle') !== false);
        $itmQty = (int)$itm['quantity'];
        $itmPpb = !empty($itm['pieces_per_bundle']) ? (int)$itm['pieces_per_bundle'] : get_product_pieces_per_bundle($itm['product_id'], $itm['ruling_type'] ?? '', null);
        $itmTotalPieces = !empty($itm['total_pieces']) ? (int)$itm['total_pieces'] : ($itmIsBundle ? ($itmQty * $itmPpb) : $itmQty);
      ?>
        <tr>
          <td style="border: 1px solid #CBD5E1; padding: 7px 8px; text-align: center; color: #64748B; font-weight: 600; vertical-align: middle;"><?php echo $p_sn++; ?></td>
          <td style="border: 1px solid #CBD5E1; padding: 7px 10px; vertical-align: middle;">
            <strong style="color: #17243A; font-size: 0.85rem; display: block; line-height: 1.25;"><?php echo htmlspecialchars($itm['product_name']); ?></strong>
            <div style="color: #64748B; font-size: 0.74rem; margin-top: 2px;">
              <?php if (!empty($itmVInfo['page_count'])): ?>
                <span><?php echo htmlspecialchars($itmVInfo['page_count']); ?></span> &bull;
              <?php endif; ?>
              <span>Variety: <?php echo htmlspecialchars($itmVInfo['variety']); ?></span>
              <?php if (!empty($itmVInfo['order_type'])): ?>
                &bull; <span><?php echo htmlspecialchars($itmVInfo['order_type']); ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td style="border: 1px solid #CBD5E1; padding: 7px 8px; font-family: monospace; font-weight: 700; color: #17243A; text-align: center; font-size: 0.78rem; vertical-align: middle;">
            <?php echo htmlspecialchars($itemCode ?: '-'); ?>
          </td>
          <td style="border: 1px solid #CBD5E1; padding: 7px 8px; text-align: center; vertical-align: middle; color: #475569; font-size: 0.85rem;">
            <?php echo $itmIsBundle ? ($itmPpb . ' Pcs') : '1 (Loose)'; ?>
          </td>
          <td style="border: 1px solid #CBD5E1; padding: 7px 8px; text-align: center; vertical-align: middle; font-weight: 700; color: #17243A; font-size: 0.88rem;">
            <?php echo $itmIsBundle ? $itmQty : '&mdash;'; ?>
          </td>
          <td style="border: 1px solid #CBD5E1; padding: 7px 8px; text-align: center; vertical-align: middle; font-weight: 700; color: #0F172A; font-size: 0.88rem; background: #F8FAFC;">
            <?php echo $itmTotalPieces; ?> Pcs
          </td>
          <td style="border: 1px solid #CBD5E1; padding: 7px 8px; text-align: right; vertical-align: middle;"><?php echo format_price($itm['price']); ?></td>
          <td style="border: 1px solid #CBD5E1; padding: 7px 8px; text-align: right; font-weight: 700; color: #17243A; vertical-align: middle;"><?php echo format_price($itm['total_price']); ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 600; color: #475569;">Taxable Amount:</td>
        <td style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 700; color: #17243A;"><?php echo format_price($order_gst['taxable_amount']); ?></td>
      </tr>
      <?php if ($order['discount'] > 0): ?>
        <tr>
          <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; color: #16A34A; font-weight: 600;">Discount Applied:</td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; color: #16A34A; font-weight: 700;">-<?php echo format_price($order['discount']); ?></td>
        </tr>
      <?php endif; ?>
      <?php if (!empty($order_gst['has_multiple_rates'])): ?>
        <?php foreach ($order_gst['rates_breakdown'] as $r): ?>
          <tr>
            <td colspan="7" style="border: 1px solid #CBD5E1; padding: 5px 10px; text-align: right; color: #64748B; font-size: 0.78rem;"><?php echo htmlspecialchars($r['rate_label']); ?>:</td>
            <td style="border: 1px solid #CBD5E1; padding: 5px 10px; text-align: right; color: #475569;"><?php echo format_price($r['gst_amount']); ?></td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 600; color: #17243A;">Total GST:</td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 700; color: #17243A;"><?php echo format_price($order_gst['total_gst']); ?></td>
        </tr>
      <?php else: ?>
        <?php $single_rate = !empty($order_gst['rates_breakdown']) ? reset($order_gst['rates_breakdown']) : null; ?>
        <tr>
          <td colspan="7" style="border: 1px solid #CBD5E1; padding: 5px 10px; text-align: right; color: #64748B; font-size: 0.78rem;">CGST (<?php echo $single_rate ? $single_rate['cgst_rate'] : 9; ?>%):</td>
          <td style="border: 1px solid #CBD5E1; padding: 5px 10px; text-align: right; color: #475569;"><?php echo format_price($order_gst['cgst']); ?></td>
        </tr>
        <tr>
          <td colspan="7" style="border: 1px solid #CBD5E1; padding: 5px 10px; text-align: right; color: #64748B; font-size: 0.78rem;">SGST (<?php echo $single_rate ? $single_rate['sgst_rate'] : 9; ?>%):</td>
          <td style="border: 1px solid #CBD5E1; padding: 5px 10px; text-align: right; color: #475569;"><?php echo format_price($order_gst['sgst']); ?></td>
        </tr>
        <tr>
          <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 600; color: #17243A;">Total GST (<?php echo $single_rate ? $single_rate['rate'] : 18; ?>%):</td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 700; color: #17243A;"><?php echo format_price($order_gst['total_gst']); ?></td>
        </tr>
      <?php endif; ?>
      <?php if ($order['delivery_fee'] > 0): ?>
        <tr>
          <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 600; color: #475569;">Delivery Fee:</td>
          <td style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 700; color: #17243A;"><?php echo format_price($order['delivery_fee']); ?></td>
        </tr>
      <?php endif; ?>
      <tr style="background: #17243A; color: #FFFFFF; font-size: 0.95rem;">
        <td colspan="7" style="border: 1px solid #17243A; padding: 9px 10px; text-align: right; font-weight: 800; text-transform: uppercase;">Grand Total:</td>
        <td style="border: 1px solid #17243A; padding: 9px 10px; text-align: right; font-weight: 800; color: #C89B3C;"><?php echo format_price($order['grand_total']); ?></td>
    </tfoot>
  </table>

  <!-- 4. Footer & Sign-off -->
  <div style="display: flex; justify-content: space-between; align-items: flex-end; padding-top: 14px; border-top: 1.5px solid #CBD5E1; font-size: 0.775rem; margin-top: 20px;">
    <div style="color: #64748B; max-width: 65%; line-height: 1.45;">
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
    <?php
    return ob_get_clean();
}
}

// Handle AJAX Upload of Client-Generated Invoice PDF
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_invoice_pdf') {
    header('Content-Type: application/json; charset=UTF-8');

    $req_order_id = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
    if ($req_order_id !== (int)$order['id']) {
        echo json_encode(['success' => false, 'message' => 'Invalid order reference.']);
        exit();
    }

    if (empty($_FILES['invoice_pdf']) || $_FILES['invoice_pdf']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No valid invoice PDF received.']);
        exit();
    }

    $file = $_FILES['invoice_pdf'];
    if ($file['size'] > 15 * 1024 * 1024) { // 15MB limit
        echo json_encode(['success' => false, 'message' => 'Invoice file exceeds size limit.']);
        exit();
    }

    // Validate PDF magic bytes (%PDF)
    $handle = fopen($file['tmp_name'], 'rb');
    $header = fread($handle, 4);
    fclose($handle);
    if ($header !== '%PDF') {
        echo json_encode(['success' => false, 'message' => 'Uploaded file is not a valid PDF document.']);
        exit();
    }

    $invoices_dir = __DIR__ . '/uploads/invoices';
    if (!is_dir($invoices_dir)) {
        mkdir($invoices_dir, 0755, true);
    }

    $safe_order_num = preg_replace('/[^A-Za-z0-9_-]/', '', $order['order_number']);
    $filename = 'Invoice-' . $safe_order_num . '.pdf';
    $target_path = $invoices_dir . '/' . $filename;

    $saved = is_uploaded_file($file['tmp_name']) 
        ? move_uploaded_file($file['tmp_name'], $target_path) 
        : copy($file['tmp_name'], $target_path);

    if ($saved) {
        $pdf_url = get_site_url() . '/uploads/invoices/' . $filename;
        echo json_encode([
            'success' => true,
            'pdf_url' => $pdf_url,
            'filename' => $filename
        ]);
        exit();
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save invoice on server.']);
        exit();
    }
}

// Handle Direct Download of Official Tax Invoice
if (isset($_GET['action']) && $_GET['action'] === 'download_invoice') {
    $order_gst = get_order_gst_summary($order, $order_items);
    $has_transport = !empty($order['transport_from']) || 
                     !empty($order['transport_to']) || 
                     !empty($order['transport_name']) || 
                     !empty($order['transport_branch']) || 
                     !empty($order['transport_contact']) || 
                     !empty($order['transport_lr_number']) || 
                     !empty($order['transport_vehicle_number']) || 
                     !empty($order['transport_date']) || 
                     !empty($order['transport_remarks']);

    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tax Invoice - <?php echo htmlspecialchars($order['order_number']); ?></title>
  <link rel="icon" href="<?php echo htmlspecialchars(get_site_url() . '/' . ltrim($logos['primary'], '/'), ENT_QUOTES, 'UTF-8'); ?>">
  <script src="assets/js/html2pdf.bundle.min.js"></script>
  <style>
    * { box-sizing: border-box; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: #f8fafc;
      color: #17243A;
      margin: 0;
      padding: 0;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    .pdf-download-bar {
      background: #17243A;
      color: #ffffff;
      padding: 12px 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      position: sticky;
      top: 0;
      z-index: 1000;
      box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }
    .pdf-download-bar button {
      background: #C89B3C;
      color: #17243A;
      border: none;
      padding: 8px 18px;
      border-radius: 6px;
      font-weight: 700;
      cursor: pointer;
    }
    .customer-print-invoice {
      max-width: 860px;
      margin: 24px auto;
      background: #ffffff;
      padding: 24px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.06);
      border-radius: 8px;
    }
    @media print {
      .pdf-download-bar { display: none !important; }
      body { background: #ffffff; }
      .customer-print-invoice { margin: 0; padding: 0; box-shadow: none; border-radius: 0; }
    }
  </style>
</head>
<body>
  <div class="pdf-download-bar">
    <div id="pdfStatusMsg" style="font-size: 0.9rem;">
      <span style="display: inline-block; animation: pulse 1s infinite;">⏳</span> Generating and downloading <strong>Invoice-<?php echo htmlspecialchars($order['order_number']); ?>.pdf</strong>...
    </div>
    <button onclick="triggerPdfDownload()">Download PDF Again</button>
  </div>
  <div class="customer-print-invoice" id="invoiceContainer" style="max-width: 700px; margin: 20px auto; padding: 14px 16px; box-sizing: border-box; background: #ffffff;">
    <?php echo render_customer_tax_invoice_block($order, $order_items, $logos, $order_gst, $has_transport); ?>
  </div>

  <script>
    function triggerPdfDownload() {
      const element = document.getElementById('invoiceContainer');
      const statusEl = document.getElementById('pdfStatusMsg');
      const opt = {
        margin:       [6, 6, 6, 6],
        filename:     'Invoice-<?php echo htmlspecialchars(addslashes($order["order_number"])); ?>.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, logging: false, scrollX: 0, scrollY: 0 },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
      };

      if (typeof html2pdf !== 'undefined') {
        html2pdf().set(opt).from(element).save().then(() => {
          if (statusEl) {
            statusEl.innerHTML = '✅ <strong>Invoice-<?php echo htmlspecialchars($order["order_number"]); ?>.pdf</strong> downloaded successfully!';
          }
        }).catch(err => {
          console.error('PDF generation error:', err);
          if (statusEl) {
            statusEl.innerHTML = '⚠️ Error generating PDF. Please click "Download PDF Again" or print using your browser.';
          }
        });
      }
    }
    window.addEventListener('load', () => {
      setTimeout(triggerPdfDownload, 600);
    });
  </script>
</body>
</html>
    <?php
    exit();
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ─── Modern Wholesale Order Success Page ─── */
.order-success-wrapper {
  max-width: 960px;
  margin: 0 auto;
}

.order-hero-card {
  background: #ffffff;
  border-radius: 16px;
  border: 1px solid #E2E8F0;
  box-shadow: 0 4px 20px rgba(23, 36, 58, 0.05);
  padding: 36px 30px;
  text-align: center;
  position: relative;
  overflow: hidden;
}

.order-hero-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 5px;
  background: linear-gradient(90deg, #10B981 0%, #C89B3C 50%, #17243A 100%);
}

.success-badge-glow {
  width: 72px;
  height: 72px;
  background: linear-gradient(135deg, #10B981 0%, #059669 100%);
  color: #FFFFFF;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2.25rem;
  margin: 0 auto 16px;
  box-shadow: 0 10px 25px rgba(16, 185, 129, 0.25);
}

.order-quick-summary {
  background: #F8FAFC;
  border: 1px solid #E2E8F0;
  border-radius: 12px;
  padding: 16px 22px;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 16px;
  margin-top: 24px;
  text-align: left;
}

.quick-summary-item {
  display: flex;
  flex-direction: column;
}

.quick-summary-label {
  font-size: 0.75rem;
  font-weight: 600;
  color: #64748B;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 2px;
}

.quick-summary-value {
  font-size: 1rem;
  font-weight: 700;
  color: #17243A;
}

.info-card {
  background: #ffffff;
  border-radius: 14px;
  border: 1px solid #E2E8F0;
  padding: 22px 24px;
  height: 100%;
  box-shadow: 0 2px 8px rgba(0,0,0,0.02);
}

.info-card-title {
  font-size: 0.95rem;
  font-weight: 700;
  color: #17243A;
  margin-bottom: 16px;
  padding-bottom: 10px;
  border-bottom: 1px solid #F1F5F9;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.items-card {
  background: #ffffff;
  border-radius: 14px;
  border: 1px solid #E2E8F0;
  box-shadow: 0 2px 8px rgba(0,0,0,0.02);
  overflow: hidden;
}

.order-items-table th {
  background-color: #F8FAFC;
  color: #475569;
  font-size: 0.775rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  padding: 12px 16px;
  border-bottom: 2px solid #E2E8F0;
}

.order-items-table td {
  padding: 14px 16px;
  border-bottom: 1px solid #F1F5F9;
  vertical-align: middle;
}

.order-items-table tfoot td {
  padding: 8px 16px;
}

@page {
  size: A4 portrait;
  margin: 10mm 12mm;
}

@media print {
  html, body {
    width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #FFFFFF !important;
    color: #000000 !important;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
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
    width: 100% !important;
    max-width: 100% !important;
    margin: 0 !important;
    padding: 0 !important;
    background: #FFFFFF !important;
    box-sizing: border-box !important;
  }
}

@media screen {
  .customer-print-invoice {
    display: none !important;
  }
}
</style>

<div class="container section-padding py-4 py-md-5 screen-only-page-content">
  <div class="order-success-wrapper">
    
    <!-- ─── 1. Header Confirmation Banner ─── -->
    <div class="order-hero-card mb-4">
      <div class="success-badge-glow">
        <i class="bi bi-check-lg"></i>
      </div>

      <span class="badge bg-gold-subtle text-navy border border-warning-subtle px-3 py-1.5 rounded-pill fw-bold small mb-2">
        <i class="bi bi-shield-check text-gold me-1"></i> Order Successfully Placed
      </span>

      <h1 class="display-6 fw-bold text-navy mb-2">Thank You For Your Order!</h1>
      <p class="text-secondary mb-0">Your wholesale notebook order has been received and confirmed in our system.</p>

      <!-- Quick Summary Row -->
      <div class="order-quick-summary">
        <div class="quick-summary-item">
          <span class="quick-summary-label">Order Number</span>
          <span class="quick-summary-value font-monospace" style="color: #17243A;"><?php echo htmlspecialchars($order['order_number']); ?></span>
        </div>
        <div class="quick-summary-item">
          <span class="quick-summary-label">Order Date</span>
          <span class="quick-summary-value"><?php echo format_datetime($order['created_at']); ?></span>
        </div>
        <div class="quick-summary-item">
          <span class="quick-summary-label">Payment</span>
          <span class="quick-summary-value">
            <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($order['payment_method']); ?></span>
          </span>
        </div>
        <div class="quick-summary-item">
          <span class="quick-summary-label">Grand Total</span>
          <span class="quick-summary-value text-gold"><?php echo format_price($order['grand_total']); ?></span>
        </div>
      </div>
    </div>

    <!-- ─── 2. Shipping Details & Payment Info Grid ─── -->
    <div class="row g-3 mb-4">
      <div class="col-md-6">
        <div class="info-card">
          <div class="info-card-title">
            <span><i class="bi bi-geo-alt-fill text-gold me-1.5"></i> Shipping & Customer Info</span>
            <span class="badge bg-light text-secondary border small fw-normal">Delivery</span>
          </div>

          <div class="small">
            <div class="fs-6 fw-bold text-navy mb-2"><?php echo htmlspecialchars($order['customer_name']); ?></div>
            <?php if (!empty($order['customer_code'])): ?>
            <div class="mb-2 text-dark">
              <i class="bi bi-person-badge-fill text-gold me-2"></i><strong>Customer ID:</strong> 
              <span class="badge bg-navy text-gold fw-bold px-2 py-1"><?php echo htmlspecialchars($order['customer_code']); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="mb-1 text-dark">
              <i class="bi bi-telephone-fill text-gold me-2"></i><strong>Phone:</strong> <?php echo htmlspecialchars($order['customer_phone']); ?>
            </div>
            
            <div class="mb-2 text-dark">
              <i class="bi bi-envelope-fill text-gold me-2"></i><strong>Email:</strong> 
              <?php echo !empty($order['customer_email']) ? htmlspecialchars($order['customer_email']) : '<span class="text-muted fst-italic">Not Provided</span>'; ?>
            </div>

            <div class="mb-3 text-dark">
              <i class="bi bi-receipt text-gold me-2"></i><strong>GST Number:</strong> 
              <?php if (!empty($order['gst_number'])): ?>
                <span class="badge bg-gold text-white fw-bold px-2 py-1"><?php echo htmlspecialchars($order['gst_number']); ?></span>
              <?php else: ?>
                <span class="text-muted fst-italic">Not Provided</span>
              <?php endif; ?>
            </div>

            <!-- Structured Delivery Address -->
            <div class="p-3 bg-light rounded-3 border">
              <div class="fw-bold text-navy mb-1 small text-uppercase letter-spacing-1">
                <i class="bi bi-building me-1 text-gold"></i> Delivery Address
              </div>
              <div class="text-dark fw-medium lh-sm mb-1"><?php echo htmlspecialchars($order['shipping_address']); ?></div>
              <?php if (!empty($order['address_line2'])): ?>
                <div class="text-secondary small"><?php echo htmlspecialchars($order['address_line2']); ?></div>
              <?php endif; ?>
              <?php if (!empty($order['area'])): ?>
                <div class="text-secondary small">Area: <?php echo htmlspecialchars($order['area']); ?></div>
              <?php endif; ?>
              <div class="text-dark fw-bold mt-1">
                <?php echo htmlspecialchars($order['city']); ?>
                <?php if (!empty($order['district']) && strcasecmp($order['district'], $order['city']) !== 0): ?>
                  , <?php echo htmlspecialchars($order['district']); ?>
                <?php endif; ?>
                , <?php echo htmlspecialchars($order['state'] . ' - ' . $order['pincode']); ?>
              </div>
              <?php if (!empty($order['landmark'])): ?>
                <div class="text-muted small fst-italic mt-1">Landmark: <?php echo htmlspecialchars($order['landmark']); ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <div class="info-card">
          <div class="info-card-title">
            <span><i class="bi bi-credit-card-2-back-fill text-gold me-1.5"></i> Payment & Order Info</span>
            <span class="badge bg-light text-secondary border small fw-normal">Details</span>
          </div>

          <div class="small">
            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
              <span class="text-muted fw-semibold">Order ID:</span>
              <span class="fw-bold text-navy font-monospace"><?php echo htmlspecialchars($order['order_number']); ?></span>
            </div>
            
            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
              <span class="text-muted fw-semibold">Order Placed Date:</span>
              <span class="fw-bold text-navy"><?php echo format_datetime($order['created_at']); ?></span>
            </div>
            
            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
              <span class="text-muted fw-semibold">Payment Method:</span>
              <span class="badge bg-light text-navy border fw-bold px-2 py-1"><?php echo htmlspecialchars($order['payment_method']); ?></span>
            </div>
            
            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
              <span class="text-muted fw-semibold">Payment Status:</span>
              <span class="badge" style="background-color: #FEF3C7; color: #92400E; border: 1px solid #F59E0B; font-weight: 700; padding: 4px 10px;">
                <?php echo htmlspecialchars($order['payment_status']); ?>
              </span>
            </div>
            
            <div class="d-flex justify-content-between align-items-center py-2">
              <span class="text-muted fw-semibold">Order Status:</span>
              <?php echo render_order_status_badge($order['order_status']); ?>
            </div>

            <!-- Wholesale Assurance Note -->
            <div class="mt-3 p-3 rounded-3 border" style="background-color: #FCF8EF; border-color: #E6DCB8 !important;">
              <div class="d-flex align-items-center gap-2">
                <i class="bi bi-info-circle-fill text-gold fs-5"></i>
                <div class="small text-navy">
                  <strong>Wholesale Processing Notice:</strong><br>
                  Our sales team is preparing your package. You will receive updates once ready for dispatch.
                </div>
              </div>
            </div>
          </div>
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
      <!-- Transport / Parcel Reference Card (Screen View) -->
      <div class="bg-white p-4 rounded-3 border shadow-sm mb-4">
        <h5 class="fw-bold text-navy mb-3"><i class="bi bi-truck text-gold me-1.5"></i> Transport / Parcel Details</h5>
        <div class="p-3 bg-light rounded border small text-dark">
          <div class="row g-2">
            <?php if (!empty($order['transport_from'])): ?>
              <div class="col-sm-6"><strong>From Location:</strong> <?php echo htmlspecialchars($order['transport_from']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_to'])): ?>
              <div class="col-sm-6"><strong>To Location:</strong> <?php echo htmlspecialchars($order['transport_to']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_name'])): ?>
              <div class="col-sm-6"><strong>Transport Service:</strong> <?php echo htmlspecialchars($order['transport_name']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_branch'])): ?>
              <div class="col-sm-6"><strong>Transport Branch:</strong> <?php echo htmlspecialchars($order['transport_branch']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_contact'])): ?>
              <div class="col-sm-6"><strong>Transport Contact:</strong> <?php echo htmlspecialchars($order['transport_contact']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_lr_number'])): ?>
              <div class="col-sm-6"><strong>LR / Parcel No:</strong> <code class="fw-bold text-navy"><?php echo htmlspecialchars($order['transport_lr_number']); ?></code></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_vehicle_number'])): ?>
              <div class="col-sm-6"><strong>Vehicle No:</strong> <?php echo htmlspecialchars($order['transport_vehicle_number']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_date'])): ?>
              <div class="col-sm-6"><strong>Transport Date:</strong> <?php echo format_date($order['transport_date']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_remarks'])): ?>
              <div class="col-12 mt-1 pt-1 border-top"><strong>Remarks:</strong> <?php echo htmlspecialchars($order['transport_remarks']); ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- ─── 3. Ordered Notebooks Breakdown Card ─── -->
    <div class="items-card mb-4">
      <div class="p-3.5 px-4 border-bottom bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="fw-bold text-navy mb-0">
          <i class="bi bi-journal-bookmark-fill text-gold me-2"></i> Ordered Notebooks
        </h5>
        <span class="badge bg-light text-secondary border"><?php echo count($order_items); ?> Item(s) in Order</span>
      </div>

      <div class="table-responsive">
        <table class="table order-items-table align-middle mb-0">
          <thead>
            <tr>
              <th class="align-middle">Notebook Item &amp; Specifications</th>
              <th class="text-center align-middle" style="width: 105px; font-size: 0.78rem; text-transform: uppercase;">Pcs / Bundle</th>
              <th class="text-center align-middle" style="width: 110px; font-size: 0.78rem; text-transform: uppercase;">Bundles Ordered</th>
              <th class="text-center align-middle" style="width: 115px; font-size: 0.78rem; text-transform: uppercase;">Total Pieces</th>
              <th class="text-end align-middle" style="width: 115px; font-size: 0.78rem; text-transform: uppercase;">Unit Price</th>
              <th class="text-end align-middle" style="width: 125px; font-size: 0.78rem; text-transform: uppercase;">Total Price</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($order_items as $item): 
              $vInfo = parse_variety_info($item['ruling_type'] ?? '');
              $itemCode = !empty($item['product_code']) ? $item['product_code'] : get_variant_product_code($item['product_id'], $item['ruling_type'] ?? '');
              $imgSrc = !empty($item['image']) ? $item['image'] : 'uploads/products/prod_navy_single.webp';

              $isBundle = (stripos($vInfo['order_type'] ?? '', 'Bundle') !== false) || (stripos($item['ruling_type'] ?? '', 'Bundle') !== false);
              $qty = (int)$item['quantity'];
              $ppb = !empty($item['pieces_per_bundle']) ? (int)$item['pieces_per_bundle'] : get_product_pieces_per_bundle($item['product_id'], $item['ruling_type'] ?? '', null);
              $totalPieces = !empty($item['total_pieces']) ? (int)$item['total_pieces'] : ($isBundle ? ($qty * $ppb) : $qty);
            ?>
              <tr>
                <td>
                  <div class="d-flex align-items-center gap-3">
                    <img src="<?php echo htmlspecialchars($imgSrc); ?>" alt="<?php echo htmlspecialchars($item['product_name']); ?>" style="width: 52px; height: 52px; object-fit: contain;" class="rounded border p-1 bg-light flex-shrink-0">
                    <div>
                      <div class="fw-bold text-navy fs-6 lh-sm"><?php echo htmlspecialchars($item['product_name']); ?></div>
                      <div class="small mt-1 d-flex flex-wrap align-items-center gap-1.5">
                        <?php if (!empty($vInfo['page_count'])): ?>
                          <span class="fw-bold text-navy me-1"><?php echo htmlspecialchars($vInfo['page_count']); ?></span>
                          <span class="mx-1 text-muted">&bull;</span>
                        <?php endif; ?>
                        <span class="badge" style="background-color: #FEF3C7; color: #92400E; border: 1px solid #F59E0B; font-weight: 700; font-size: 0.75rem; padding: 4px 8px;">Variety: <?php echo htmlspecialchars($vInfo['variety']); ?></span>
                        <?php if (!empty($vInfo['order_type'])): ?>
                          <span class="badge ms-1" style="background-color: #E0F2FE; color: #0369A1; border: 1px solid #38BDF8; font-weight: 700; font-size: 0.75rem; padding: 4px 8px;"><?php echo htmlspecialchars($vInfo['order_type']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($itemCode)): ?>
                          <span class="badge ms-1 bg-light text-dark border font-monospace" style="font-size: 0.75rem; padding: 4px 8px;">
                            <i class="bi bi-barcode text-gold me-1"></i><?php echo htmlspecialchars($itemCode); ?>
                          </span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td class="text-center align-middle text-muted small fw-semibold">
                  <?php echo $isBundle ? ($ppb . ' Pcs') : '1 (Loose)'; ?>
                </td>
                <td class="text-center align-middle fw-bold text-navy fs-6">
                  <?php echo $isBundle ? ($qty . ' ' . ($qty > 1 ? 'Bundles' : 'Bundle')) : '&mdash;'; ?>
                </td>
                <td class="text-center align-middle fw-bold fs-6">
                  <span class="badge <?php echo $isBundle ? 'bg-gold-subtle text-navy border border-warning-subtle' : 'bg-light text-dark border'; ?> px-2.5 py-1.5 fs-7">
                    <?php echo $totalPieces; ?> Pieces
                  </span>
                </td>
                <td class="text-end fw-semibold text-navy"><?php echo format_price($item['price']); ?></td>
                <td class="text-end fw-bold text-navy fs-6"><?php echo format_price($item['total_price']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <?php $order_gst = get_order_gst_summary($order, $order_items); ?>
            <tr>
              <td colspan="5" class="text-end fw-semibold text-muted small border-0 pt-3">Taxable Amount:</td>
              <td class="text-end fw-bold text-navy border-0 pt-3"><?php echo format_price($order_gst['taxable_amount']); ?></td>
            </tr>
            <?php if ($order['discount'] > 0): ?>
              <tr>
                <td colspan="5" class="text-end text-success fw-semibold small border-0">Discount Applied:</td>
                <td class="text-end text-success fw-bold border-0">-<?php echo format_price($order['discount']); ?></td>
              </tr>
            <?php endif; ?>
            <?php if (!empty($order_gst['has_multiple_rates'])): ?>
              <?php foreach ($order_gst['rates_breakdown'] as $r): ?>
                <tr>
                  <td colspan="5" class="text-end text-muted small border-0"><?php echo htmlspecialchars($r['rate_label']); ?>:</td>
                  <td class="text-end text-muted border-0"><?php echo format_price($r['gst_amount']); ?></td>
                </tr>
              <?php endforeach; ?>
              <tr>
                <td colspan="5" class="text-end text-navy fw-semibold small border-0">Total GST:</td>
                <td class="text-end text-navy fw-semibold border-0"><?php echo format_price($order_gst['total_gst']); ?></td>
              </tr>
            <?php else: ?>
              <?php $single_rate = !empty($order_gst['rates_breakdown']) ? reset($order_gst['rates_breakdown']) : null; ?>
              <tr>
                <td colspan="5" class="text-end text-muted small border-0">CGST (<?php echo $single_rate ? $single_rate['cgst_rate'] : 9; ?>%):</td>
                <td class="text-end text-muted border-0"><?php echo format_price($order_gst['cgst']); ?></td>
              </tr>
              <tr>
                <td colspan="5" class="text-end text-muted small border-0">SGST (<?php echo $single_rate ? $single_rate['sgst_rate'] : 9; ?>%):</td>
                <td class="text-end text-muted border-0"><?php echo format_price($order_gst['sgst']); ?></td>
              </tr>
              <tr>
                <td colspan="5" class="text-end text-navy fw-semibold small border-0">Total GST (<?php echo $single_rate ? $single_rate['rate'] : 18; ?>%):</td>
                <td class="text-end text-navy fw-semibold border-0"><?php echo format_price($order_gst['total_gst']); ?></td>
              </tr>
            <?php endif; ?>
            <?php if ($order['delivery_fee'] > 0): ?>
              <tr>
                <td colspan="5" class="text-end fw-semibold text-navy small border-0">Delivery Fee:</td>
                <td class="text-end fw-bold text-navy border-0"><?php echo format_price($order['delivery_fee']); ?></td>
              </tr>
            <?php endif; ?>
            <tr class="fs-5 fw-bold bg-light">
              <td colspan="5" class="text-end text-navy border-top">Grand Total:</td>
              <td class="text-end text-gold border-top"><?php echo format_price($order['grand_total']); ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- ─── 4. Action Buttons ─── -->
    <div class="d-flex flex-wrap justify-content-center gap-3 no-print mb-4">
      <a href="orders.php" class="btn btn-navy px-4 py-2.5 fw-bold">
        <i class="bi bi-bag-check me-2"></i> View My Orders
      </a>
      <a href="track_order.php?ord=<?php echo urlencode($order['order_number']); ?>" target="_blank" class="btn btn-outline-dark px-3.5 py-2.5 fw-semibold d-inline-flex align-items-center gap-1.5 shadow-sm">
        <i class="bi bi-qr-code text-gold me-1"></i> Track with QR
      </a>
      <a href="products.php" class="btn btn-gold px-4 py-2.5 fw-bold">
        <i class="bi bi-arrow-left me-2"></i> Continue Shopping
      </a>
      <a href="order-success.php?order_id=<?php echo $order['id']; ?>&action=download_invoice" 
         onclick="downloadInvoice(event, '<?php echo htmlspecialchars(addslashes($order['order_number'])); ?>')" 
         class="btn btn-outline-secondary px-3.5 py-2.5 fw-semibold d-inline-flex align-items-center gap-1.5" 
         id="btnDownloadInvoice">
        <i class="bi bi-download me-1"></i> Download Invoice
      </a>
      <a href="#" 
         id="btnShareWhatsapp" 
         class="btn px-3.5 py-2.5 fw-bold d-none align-items-center gap-1.5 text-white shadow-sm" 
         style="background-color: #25D366; border-color: #25D366;" 
         target="_blank" 
         rel="noopener noreferrer">
        <i class="bi bi-whatsapp fs-6"></i> Share Invoice to Admin WhatsApp
      </a>
    </div>

    <div id="invoiceShareNotice" class="text-center small mx-auto no-print" style="max-width: 580px; display: none;"></div>

  </div>
</div>

<!-- OFFICIAL CUSTOMER TAX INVOICE PRINT LETTERHEAD (PRINT ONLY) -->
<div class="customer-print-invoice">
  <?php echo render_customer_tax_invoice_block($order, $order_items, $logos, $order_gst, $has_transport); ?>
</div>

<!-- DEDICATED PDF CAPTURE WRAPPER (IN NORMAL FLOW FOR CRISP NON-EMPTY HTML2CANVAS RENDERING) -->
<div id="invoicePdfCaptureWrapper" style="display: none; width: 700px; margin: 0 auto; background: #ffffff; color: #17243A; padding: 14px 16px; box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
  <?php echo render_customer_tax_invoice_block($order, $order_items, $logos, $order_gst, $has_transport); ?>
</div>

<script src="assets/js/html2pdf.bundle.min.js"></script>
<script>
const adminWhatsAppNumber = <?php echo json_encode(get_admin_whatsapp_number()); ?>;
const orderData = {
    orderId: <?php echo json_encode($order['id']); ?>,
    orderNumber: <?php echo json_encode($order['order_number']); ?>,
    customerName: <?php echo json_encode($order['customer_name']); ?>
};

function downloadInvoice(event, orderNumber) {
    if (event) event.preventDefault();
    const btn = document.getElementById('btnDownloadInvoice');
    const originalHtml = btn ? btn.innerHTML : '';
    const shareBtn = document.getElementById('btnShareWhatsapp');
    const noticeEl = document.getElementById('invoiceShareNotice');

    if (noticeEl) {
        noticeEl.style.display = 'none';
        noticeEl.innerHTML = '';
    }

    if (btn) {
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1.5" role="status" aria-hidden="true"></span> Generating PDF...';
        btn.style.pointerEvents = 'none';
    }

    const captureWrapper = document.getElementById('invoicePdfCaptureWrapper');
    if (!captureWrapper) {
        if (btn) {
            btn.innerHTML = originalHtml;
            btn.style.pointerEvents = 'auto';
        }
        window.location.href = 'order-success.php?order_id=<?php echo $order["id"]; ?>&action=download_invoice';
        return;
    }

    // Display in normal document flow so html2canvas computes full height and all contents
    captureWrapper.style.display = 'block';

    const opt = {
        margin:       [6, 6, 6, 6],
        filename:     `Invoice-${orderNumber}.pdf`,
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true, logging: false, scrollX: 0, scrollY: 0 },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    if (typeof html2pdf !== 'undefined') {
        html2pdf().set(opt).from(captureWrapper).toPdf().get('pdf').then(function(pdfObj) {
            // 1. Hide capture wrapper immediately once PDF is generated
            captureWrapper.style.display = 'none';

            // 2. Download non-empty PDF directly to customer's machine
            pdfObj.save(`Invoice-${orderNumber}.pdf`);

            // 3. Extract binary Blob for server upload & admin WhatsApp link
            const pdfBlob = pdfObj.output('blob');

            // 4. Restore Download button
            if (btn) {
                btn.innerHTML = originalHtml;
                btn.style.pointerEvents = 'auto';
            }

            // 5. Upload PDF Blob to backend endpoint
            const formData = new FormData();
            formData.append('action', 'upload_invoice_pdf');
            formData.append('order_id', orderData.orderId);
            formData.append('invoice_pdf', pdfBlob, `Invoice-${orderNumber}.pdf`);

            fetch('order-success.php?order_id=' + encodeURIComponent(orderData.orderId), {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success && data.pdf_url) {
                    prepareWhatsAppShare(data.pdf_url);
                } else {
                    handleUploadError(data && data.message ? data.message : 'WhatsApp sharing is unavailable.');
                }
            })
            .catch(err => {
                console.error('Invoice PDF server upload failed:', err);
                handleUploadError('WhatsApp sharing is unavailable.');
            });

        }).catch(err => {
            captureWrapper.style.display = 'none';
            if (btn) {
                btn.innerHTML = originalHtml;
                btn.style.pointerEvents = 'auto';
            }
            console.error('PDF generation error:', err);
            window.location.href = 'order-success.php?order_id=<?php echo $order["id"]; ?>&action=download_invoice';
        });
    } else {
        captureWrapper.style.display = 'none';
        if (btn) {
            btn.innerHTML = originalHtml;
            btn.style.pointerEvents = 'auto';
        }
        window.location.href = 'order-success.php?order_id=<?php echo $order["id"]; ?>&action=download_invoice';
    }
}

function prepareWhatsAppShare(pdfUrl) {
    const shareBtn = document.getElementById('btnShareWhatsapp');
    const noticeEl = document.getElementById('invoiceShareNotice');

    if (!adminWhatsAppNumber || !adminWhatsAppNumber.trim()) {
        if (noticeEl) {
            noticeEl.className = 'alert alert-warning text-center small py-2 px-3 mt-3 w-100 border';
            noticeEl.style.display = 'block';
            noticeEl.textContent = 'Admin WhatsApp number is not configured.';
        }
        return;
    }

    const cleanNumber = adminWhatsAppNumber.replace(/[^0-9]/g, '');
    const message = `New Invoice\nOrder ID: ${orderData.orderNumber}\nCustomer: ${orderData.customerName}\nInvoice: ${pdfUrl}`;
    const waUrl = `https://wa.me/${cleanNumber}?text=${encodeURIComponent(message)}`;

    if (shareBtn) {
        shareBtn.href = waUrl;
        shareBtn.classList.remove('d-none');
        shareBtn.classList.add('d-inline-flex');
        shareBtn.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    if (noticeEl) {
        noticeEl.className = 'alert alert-success text-center small py-2 px-3 mt-3 w-100 border d-flex align-items-center justify-content-center gap-2';
        noticeEl.style.display = 'flex';
        noticeEl.innerHTML = '<i class="bi bi-check-circle-fill text-success"></i> Invoice downloaded! Click <strong>"Share Invoice to Admin WhatsApp"</strong> to send this invoice to Rajpriya Admin.';
    }
}

function handleUploadError(msg) {
    const noticeEl = document.getElementById('invoiceShareNotice');
    if (noticeEl) {
        noticeEl.className = 'alert alert-info text-center small py-2 px-3 mt-3 w-100 border';
        noticeEl.style.display = 'block';
        noticeEl.textContent = msg || 'WhatsApp sharing is unavailable.';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const shareBtn = document.getElementById('btnShareWhatsapp');
    if (shareBtn) {
        shareBtn.addEventListener('click', function(e) {
            if (!adminWhatsAppNumber || !adminWhatsAppNumber.trim()) {
                e.preventDefault();
                alert('Admin WhatsApp number is not configured.');
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
