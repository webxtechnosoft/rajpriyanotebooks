<?php
require_once __DIR__ . '/includes/functions.php';

if (!is_customer_logged_in()) {
    header("Location: login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
    exit();
}

$db = getDB();
$customer_id = (int)$_SESSION['customer_id'];
$customer = get_current_customer();

if (!$customer) {
    header("Location: logout.php");
    exit();
}

// Handle Customer Response POST: Accept, Decline, or Request Changes on Quotation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $q_id = (int)($_POST['quotation_id'] ?? 0);
    $action = $_POST['action'];

    $quotation_to_act = get_quotation_by_id($q_id, $customer_id);

    if (!$quotation_to_act) {
        set_flash('error', 'Unauthorized action or invalid quotation.');
        header("Location: quotations.php");
        exit();
    }

    if ($action === 'accept') {
        if ($quotation_to_act['status'] !== 'Sent') {
            set_flash('error', 'This quotation cannot be accepted because its status is ' . htmlspecialchars($quotation_to_act['status']) . '.');
            header("Location: quotations.php?view=" . $q_id);
            exit();
        }

        $stmt_up = $db->prepare("
            UPDATE quotations SET 
                status = 'Accepted', 
                customer_response_at = NOW(),
                customer_decline_reason = NULL 
            WHERE id = ?
        ");
        $stmt_up->execute([$q_id]);

        record_quotation_revision($q_id, 'customer_acceptance', 'customer', $customer_id, 'Customer accepted quotation version ' . (int)$quotation_to_act['version'] . '.');

        set_flash('success', 'Thank you! You have accepted Quotation #' . $quotation_to_act['quotation_number'] . '. Our team will complete the final order review and share the confirmed invoice.');
        header("Location: quotations.php?view=" . $q_id);
        exit();

    } elseif ($action === 'decline') {
        if ($quotation_to_act['status'] !== 'Sent') {
            set_flash('error', 'This quotation cannot be declined because its status is ' . htmlspecialchars($quotation_to_act['status']) . '.');
            header("Location: quotations.php?view=" . $q_id);
            exit();
        }

        $reason = sanitize(trim($_POST['decline_reason'] ?? ''));
        $stmt_up = $db->prepare("
            UPDATE quotations SET 
                status = 'Declined', 
                customer_response_at = NOW(),
                customer_decline_reason = ? 
            WHERE id = ?
        ");
        $stmt_up->execute([$reason ?: 'Declined by customer without note', $q_id]);

        record_quotation_revision($q_id, 'customer_change_request', 'customer', $customer_id, 'Customer declined quotation: ' . ($reason ?: 'No reason given'));

        set_flash('success', 'You have declined Quotation #' . $quotation_to_act['quotation_number'] . '. Our team will review your comments and may provide a revised quotation.');
        header("Location: quotations.php?view=" . $q_id);
        exit();

    } elseif ($action === 'request_change') {
        if (!in_array($quotation_to_act['status'], ['Sent', 'Change Requested'])) {
            set_flash('error', 'Change requests are only available for open or revised quotations.');
            header("Location: quotations.php?view=" . $q_id);
            exit();
        }

        $items_data = $_POST['items'] ?? [];
        $customer_notes = sanitize(trim($_POST['customer_notes'] ?? ''));

        $res = submit_customer_quotation_change_request($q_id, $customer_id, $items_data, $customer_notes);
        if ($res['success']) {
            set_flash('success', 'Your requested changes have been submitted to our sales team for review. Quotation updated to Version ' . $res['version'] . '.');
        } else {
            set_flash('error', $res['message'] ?? 'Failed to submit change request.');
        }
        header("Location: quotations.php?view=" . $q_id);
        exit();
    }
}

// Check if viewing single quotation details
$view_id = isset($_GET['view']) ? (int)$_GET['view'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
$single_quotation = null;
$single_items = [];

if ($view_id > 0) {
    $single_quotation = get_quotation_by_id($view_id, $customer_id);
    if (!$single_quotation) {
        set_flash('error', 'Quotation not found or access denied.');
        header("Location: quotations.php");
        exit();
    }
    $single_items = get_quotation_items($single_quotation['id']);
}

// Fetch all quotations belonging to this customer
$quotations = get_customer_quotations($customer_id);
$pending_count = get_customer_pending_quotation_count($customer_id);

$logos = get_active_logos();

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section-padding py-4">
  <?php 
  if ($single_quotation) {
      echo render_page_navigation('Quotation #' . $single_quotation['quotation_number'], [
          'My Account' => 'account.php',
          'My Quotations' => 'quotations.php',
          'Quotation Details' => ''
      ]);
  } else {
      echo render_page_navigation('My Quotations', [
          'My Account' => 'account.php',
          'My Quotations' => ''
      ]);
  }
  ?>

  <div class="row g-4">
    <!-- Customer Account Sidebar -->
    <div class="col-lg-3 no-print">
      <div class="bg-white p-4 rounded-3 border shadow-sm">
        <div class="text-center pb-3 mb-3 border-bottom">
          <div class="author-avatar mx-auto mb-2 fs-3" style="width: 56px; height: 56px; background: #17243A; color: #C89B3C; font-weight: 700; border-radius: 50%; display: flex; align-items: center; justify-content: center;">
            <?php echo strtoupper(substr($customer['name'], 0, 1)); ?>
          </div>
          <h5 class="fw-bold text-navy mb-0"><?php echo htmlspecialchars($customer['name']); ?></h5>
          <div class="d-flex flex-wrap justify-content-center gap-1 mt-1">
            <span class="badge bg-navy text-gold fw-bold px-2.5 py-1">
              <i class="bi bi-person-badge me-1"></i> ID: <?php echo htmlspecialchars($customer['customer_code'] ?? 'CUST' . str_pad($customer['id'], 5, '0', STR_PAD_LEFT)); ?>
            </span>
            <span class="badge bg-gold text-navy fw-bold px-2.5 py-1">
              <i class="bi bi-briefcase me-1"></i> <?php echo htmlspecialchars($customer['customer_type'] ?? 'Retailer'); ?>
            </span>
          </div>
          <div class="small text-muted mt-1">
            <?php if (!empty($customer['email'])): ?>
              <i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($customer['email']); ?>
            <?php else: ?>
              <i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($customer['phone']); ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="list-group list-group-flush">
          <a href="account.php" class="list-group-item list-group-item-action"><i class="bi bi-person me-2"></i> Account Dashboard</a>
          <a href="orders.php" class="list-group-item list-group-item-action"><i class="bi bi-bag me-2"></i> My Orders</a>
          <a href="quotations.php" class="list-group-item list-group-item-action active fw-semibold bg-gold-subtle text-navy border-0">
            <i class="bi bi-file-earmark-text text-gold me-2"></i> My Quotations
            <?php if ($pending_count > 0): ?>
              <span class="badge bg-danger rounded-pill float-end"><?php echo $pending_count; ?></span>
            <?php endif; ?>
          </a>
          <a href="logout.php" class="list-group-item list-group-item-action text-danger"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
        </div>
      </div>
    </div>

    <!-- Main Quotations Content Area -->
    <div class="col-lg-9">
      
      <?php if ($single_quotation): ?>
        <!-- ======================================================== -->
        <!-- VIEW SINGLE QUOTATION DETAILS -->
        <!-- ======================================================== -->

        <!-- Top Action / Status Bar -->
        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2 no-print">
          <a href="quotations.php" class="btn btn-outline-secondary btn-sm px-3 fw-semibold">
            <i class="bi bi-arrow-left me-1"></i> Back to Quotations List
          </a>
          <div class="d-flex align-items-center gap-2">
            <button type="button" onclick="window.print()" class="btn btn-outline-dark btn-sm px-3 fw-semibold">
              <i class="bi bi-printer me-1"></i> Print Quotation
            </button>
            <?php echo render_quotation_status_badge($single_quotation['status']); ?>
          </div>
        </div>

        <!-- Customer Status Callout Banner -->
        <?php if ($single_quotation['status'] === 'Accepted'): ?>
          <div class="alert alert-success border-0 shadow-sm d-flex align-items-center gap-3 p-3 mb-4 rounded-3 no-print">
            <i class="bi bi-check-circle-fill fs-2 text-success"></i>
            <div>
              <h6 class="fw-bold text-success mb-1">
                <?php echo !empty($single_quotation['is_final_approved']) ? 'Order Confirmed' : 'Quotation Accepted'; ?>
              </h6>
              <div class="small">
                You accepted this quotation on <strong><?php echo !empty($single_quotation['customer_response_at']) ? format_datetime($single_quotation['customer_response_at']) : 'Recently'; ?></strong>.
                <?php if (!empty($single_quotation['is_final_approved'])): ?>
                  Your order is confirmed. The final invoice is available in your order details.
                <?php else: ?>
                  Our team is completing the final order review. We will confirm the order and share the invoice when it is ready.
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php elseif ($single_quotation['status'] === 'Declined'): ?>
          <div class="alert alert-danger border-0 shadow-sm d-flex align-items-start gap-3 p-3 mb-4 rounded-3 no-print">
            <i class="bi bi-x-circle-fill fs-2 text-danger"></i>
            <div>
              <h6 class="fw-bold text-danger mb-1">Quotation Declined</h6>
              <div class="small">
                You declined this quotation on <strong><?php echo !empty($single_quotation['customer_response_at']) ? format_datetime($single_quotation['customer_response_at']) : 'Recently'; ?></strong>.
                <?php if (!empty($single_quotation['customer_decline_reason'])): ?>
                  <div class="mt-2 p-2 bg-white rounded border border-danger-subtle text-dark">
                    <strong>Your Note:</strong> "<?php echo htmlspecialchars($single_quotation['customer_decline_reason']); ?>"
                  </div>
                <?php endif; ?>
                <div class="mt-2 text-muted">
                  If you need revised pricing, our sales team will get in touch or issue an updated quotation.
                </div>
              </div>
            </div>
          </div>
        <?php elseif ($single_quotation['status'] === 'Change Requested'): ?>
          <div class="alert alert-info border-0 shadow-sm d-flex align-items-start gap-3 p-3 mb-4 rounded-3 no-print">
            <i class="bi bi-clock-history fs-2 text-info"></i>
            <div class="flex-grow-1">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="fw-bold text-info mb-1"><i class="bi bi-hourglass-split me-1"></i> Change Request Submitted &mdash; Under Admin Review</h6>
                <span class="badge bg-info text-dark font-monospace">Version <?php echo (int)$single_quotation['version']; ?></span>
              </div>
              <div class="small text-dark mt-1">
                You submitted a change request for this quotation on <strong><?php echo !empty($single_quotation['change_requested_at']) ? format_datetime($single_quotation['change_requested_at']) : 'Recently'; ?></strong>.
                Our wholesale sales team has been notified and will review your adjusted products and quantities shortly.
              </div>
              <?php if (!empty($single_quotation['customer_notes'])): ?>
                <div class="mt-2 p-2 bg-white rounded border border-info-subtle small text-dark">
                  <strong>Your Remarks / Change Request:</strong> "<?php echo htmlspecialchars($single_quotation['customer_notes']); ?>"
                </div>
              <?php endif; ?>
              <div class="mt-2.5">
                <button type="button" class="btn btn-outline-info text-dark btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#requestChangeModal">
                  <i class="bi bi-pencil-square me-1"></i> Modify Change Request
                </button>
              </div>
            </div>
          </div>
        <?php elseif ($single_quotation['status'] === 'Sent'): ?>
          <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2 p-3 mb-4 rounded-3 no-print">
            <div class="d-flex align-items-center gap-3">
              <i class="bi bi-exclamation-circle-fill fs-2 text-warning"></i>
              <div>
                <h6 class="fw-bold text-navy mb-1">Action Required: Review &amp; Respond</h6>
                <div class="small text-secondary">
                  Please review the wholesale prices, bundle quantities, and terms below. Click <strong>Accept</strong> to confirm, <strong>Request Changes</strong> to adjust items/quantities, or <strong>Decline</strong>.
                </div>
              </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <button type="button" class="btn btn-outline-danger btn-sm px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#declineQuotationModal">
                <i class="bi bi-x-circle me-1"></i> Decline
              </button>
              <button type="button" class="btn btn-outline-warning text-dark btn-sm px-3 fw-bold" data-bs-toggle="modal" data-bs-target="#requestChangeModal">
                <i class="bi bi-pencil-square me-1"></i> Request Changes
              </button>
              <button type="button" class="btn btn-success btn-sm px-3 fw-bold shadow-xs" data-bs-toggle="modal" data-bs-target="#acceptQuotationModal">
                <i class="bi bi-check-circle-fill me-1"></i> Accept Quotation
              </button>
            </div>
          </div>
        <?php endif; ?>

        <!-- Quotation Document Paper / Card -->
        <div class="bg-white p-4 p-md-5 rounded-3 border shadow-sm mb-4">
          
          <!-- Document Header -->
          <div class="d-flex justify-content-between align-items-start border-bottom pb-4 mb-4 flex-wrap gap-3">
            <div>
              <img src="<?php echo htmlspecialchars($logos['primary']); ?>" alt="Rajpriya Notebooks" style="height: 54px; object-fit: contain;" class="mb-2">
              <h4 class="fw-bold text-navy mb-0">RAJPRIYA NOTEBOOKS</h4>
              <div class="small text-muted fw-semibold">Quality Notebooks — Made for Better Learning</div>
              <div class="small text-secondary mt-1">123, Station Road, Salem - 636001, Tamil Nadu | Phone: +91 98765 43210</div>
              <div class="small fw-bold text-navy mt-1">GSTIN: <?php echo htmlspecialchars(COMPANY_GSTIN); ?></div>
            </div>
            <div class="text-md-end">
              <span class="badge bg-gold text-navy fw-bold px-3 py-1.5 fs-6 mb-2">ORDER QUOTATION</span>
              <div class="fw-bold text-navy fs-5">#<?php echo htmlspecialchars($single_quotation['quotation_number']); ?></div>
              <?php if ((int)$single_quotation['version'] > 1): ?>
                <div class="small text-muted">Version <?php echo (int)$single_quotation['version']; ?> (Revised)</div>
              <?php endif; ?>
              <div class="small text-muted mt-1">
                Date: <strong><?php echo format_datetime(!empty($single_quotation['sent_at']) ? $single_quotation['sent_at'] : $single_quotation['created_at']); ?></strong>
              </div>
              <div class="small text-navy mt-1">
                Related Order: <a href="order-details.php?id=<?php echo $single_quotation['order_id']; ?>" class="fw-bold text-navy"><?php echo htmlspecialchars($single_quotation['order_number']); ?></a>
              </div>
            </div>
          </div>

          <!-- Customer & Billed Information -->
          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <div class="p-3 bg-light rounded border h-100">
                <div class="text-uppercase small fw-bold text-gold mb-1"><i class="bi bi-person-circle me-1"></i> Quotation For:</div>
                <div class="fw-bold text-navy fs-6"><?php echo htmlspecialchars($single_quotation['customer_name']); ?></div>
                <div class="small text-dark mt-1"><i class="bi bi-telephone text-muted me-1"></i> <?php echo htmlspecialchars($single_quotation['customer_phone']); ?></div>
                <?php if (!empty($single_quotation['customer_email'])): ?>
                  <div class="small text-dark"><i class="bi bi-envelope text-muted me-1"></i> <?php echo htmlspecialchars($single_quotation['customer_email']); ?></div>
                <?php endif; ?>
                <?php if (!empty($single_quotation['customer_code'])): ?>
                  <div class="mt-2">
                    <span class="badge bg-navy text-gold fw-bold">Customer ID: <?php echo htmlspecialchars($single_quotation['customer_code']); ?></span>
                  </div>
                <?php endif; ?>
              </div>
            </div>
            <div class="col-md-6">
              <div class="p-3 bg-light rounded border h-100">
                <div class="text-uppercase small fw-bold text-gold mb-1"><i class="bi bi-truck me-1"></i> Shipping / Delivery Destination:</div>
                <div class="small text-dark lh-base">
                  <?php echo nl2br(htmlspecialchars($single_quotation['shipping_address'] ?: 'As registered in customer account')); ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Message from Rajpriya Admin (if any) -->
          <?php if (!empty($single_quotation['admin_notes'])): ?>
            <div class="p-3 bg-gold-subtle rounded-3 border border-warning-subtle mb-4">
              <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-chat-quote-fill text-gold fs-5"></i>
                <h6 class="fw-bold text-navy mb-0">Message from Rajpriya Team:</h6>
              </div>
              <p class="small text-dark mb-0 lh-base"><?php echo nl2br(htmlspecialchars($single_quotation['admin_notes'])); ?></p>
            </div>
          <?php endif; ?>

          <!-- Quotation Line Items Table -->
          <div class="table-responsive mb-4">
            <table class="table table-bordered align-middle">
              <thead class="table-light text-navy">
                <tr>
                  <th style="width: 40px;" class="text-center">#</th>
                  <th>Product Description &amp; Variety</th>
                  <th class="text-center" style="width: 90px;">Ruling / Pages</th>
                  <th class="text-center" style="width: 90px;">Type</th>
                  <th class="text-center" style="width: 90px;">Quantity</th>
                  <th class="text-center" style="width: 90px;">Total Pcs</th>
                  <th class="text-end" style="width: 120px;">Unit Price (₹)</th>
                  <th class="text-center" style="width: 80px;">GST %</th>
                  <th class="text-end" style="width: 130px;">Line Total (₹)</th>
                  <th class="text-center" style="width: 130px;">Approval Status</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $line_num = 1;
                foreach ($single_items as $item): 
                  $isBdl = (strcasecmp($item['order_type'] ?? '', 'Bundle') === 0);
                  $isRemoved = !empty($item['is_customer_removed']) || (($item['customer_action'] ?? '') === 'removed');
                  $origQty = isset($item['original_quantity']) && $item['original_quantity'] !== null ? (int)$item['original_quantity'] : (int)$item['quantity'];
                  $curQty = (int)$item['quantity'];
                  $isQtyChanged = !$isRemoved && ($origQty !== $curQty || ($item['customer_action'] ?? '') === 'modified');
                  $itemStatus = $item['item_status'] ?? 'Pending';
                ?>
                  <tr class="<?php echo $isRemoved ? 'table-danger-subtle opacity-75' : ($isQtyChanged ? 'table-warning-subtle' : ''); ?>">
                    <td class="text-center small text-muted"><?php echo $line_num++; ?></td>
                    <td>
                      <div class="fw-bold text-navy <?php echo $isRemoved ? 'text-decoration-line-through text-muted' : ''; ?>"><?php echo htmlspecialchars($item['product_name']); ?></div>
                      <div class="small text-muted d-flex align-items-center gap-1 flex-wrap mt-0.5">
                        <?php if (!empty($item['variety'])): ?>
                          <span class="badge bg-gold-subtle text-navy border border-warning-subtle"><?php echo htmlspecialchars($item['variety']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($item['product_code'])): ?>
                          <span class="font-monospace text-secondary"><?php echo htmlspecialchars($item['product_code']); ?></span>
                        <?php endif; ?>
                        <?php if ($isRemoved): ?>
                          <span class="badge bg-danger text-white"><i class="bi bi-trash me-1"></i> Customer Removed</span>
                        <?php elseif ($isQtyChanged): ?>
                          <span class="badge bg-warning text-dark"><i class="bi bi-pencil me-1"></i> Qty Changed</span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td class="text-center small">
                      <?php if (!empty($item['page_count'])): ?>
                        <?php echo htmlspecialchars($item['page_count']); ?> Pages
                      <?php else: ?>
                        —
                      <?php endif; ?>
                    </td>
                    <td class="text-center">
                      <span class="badge <?php echo $isBdl ? 'bg-navy text-gold' : 'bg-light text-dark border'; ?> fw-semibold">
                        <?php echo htmlspecialchars($item['order_type']); ?>
                      </span>
                      <?php if ($isBdl && !empty($item['pieces_per_bundle'])): ?>
                        <div class="text-muted" style="font-size: 0.685rem;"><?php echo $item['pieces_per_bundle']; ?> Pcs/Bdl</div>
                      <?php endif; ?>
                    </td>
                    <td class="text-center">
                      <span class="fw-bold text-navy fs-6 <?php echo $isRemoved ? 'text-decoration-line-through text-muted' : ''; ?>"><?php echo $curQty; ?></span>
                      <?php if ($isQtyChanged): ?>
                        <div class="small text-muted" style="font-size: 0.7rem;">Orig: <?php echo $origQty; ?></div>
                      <?php endif; ?>
                    </td>
                    <td class="text-center fw-semibold text-secondary">
                      <?php echo number_format((int)$item['total_pieces']); ?>
                    </td>
                    <td class="text-end fw-semibold">
                      ₹<?php echo number_format((float)$item['unit_price'], 2); ?>
                      <?php if ((float)$item['discount'] > 0): ?>
                        <div class="small text-success">-₹<?php echo number_format((float)$item['discount'], 2); ?> disc</div>
                      <?php endif; ?>
                    </td>
                    <td class="text-center small fw-semibold text-primary">
                      <?php echo (float)$item['gst_rate']; ?>%
                    </td>
                    <td class="text-end fw-bold text-navy <?php echo $isRemoved ? 'text-decoration-line-through text-muted' : ''; ?>">
                      ₹<?php echo number_format((float)$item['total_price'], 2); ?>
                    </td>
                    <td class="text-center">
                      <?php echo render_quotation_item_status_badge($itemStatus, $isRemoved); ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <!-- Financial Calculation Breakdown -->
          <div class="row g-4 mb-4">
            <div class="col-md-6">
              <?php if (!empty($single_quotation['terms_conditions'])): ?>
                <div class="p-3 bg-light rounded border h-100">
                  <h6 class="fw-bold text-navy border-bottom pb-1 mb-2">Terms & Conditions of Sale:</h6>
                  <div class="small text-secondary lh-base" style="white-space: pre-line;">
                    <?php echo htmlspecialchars($single_quotation['terms_conditions']); ?>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <div class="col-md-6">
              <div class="p-3 bg-light rounded border">
                <div class="d-flex justify-content-between py-1.5 border-bottom">
                  <span class="text-secondary small">Items Subtotal:</span>
                  <span class="fw-semibold text-navy">₹<?php echo number_format((float)$single_quotation['subtotal'], 2); ?></span>
                </div>

                <?php if ((float)$single_quotation['discount'] > 0): ?>
                  <div class="d-flex justify-content-between py-1.5 border-bottom text-success">
                    <span class="small">Overall Special Discount:</span>
                    <span class="fw-semibold">-₹<?php echo number_format((float)$single_quotation['discount'], 2); ?></span>
                  </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between py-1.5 border-bottom">
                  <span class="text-secondary small">Net Taxable Value:</span>
                  <span class="fw-semibold text-navy">₹<?php echo number_format((float)$single_quotation['taxable_amount'], 2); ?></span>
                </div>

                <!-- GST Split (CGST + SGST) -->
                <?php if ((float)$single_quotation['total_cgst'] > 0 || (float)$single_quotation['total_sgst'] > 0): ?>
                  <div class="d-flex justify-content-between py-1 border-bottom text-muted small">
                    <span>Central GST (CGST):</span>
                    <span class="font-monospace">₹<?php echo number_format((float)$single_quotation['total_cgst'], 2); ?></span>
                  </div>
                  <div class="d-flex justify-content-between py-1 border-bottom text-muted small">
                    <span>State GST (SGST):</span>
                    <span class="font-monospace">₹<?php echo number_format((float)$single_quotation['total_sgst'], 2); ?></span>
                  </div>
                <?php endif; ?>

                <div class="d-flex justify-content-between py-1.5 border-bottom">
                  <span class="text-navy fw-semibold small">Total GST:</span>
                  <span class="fw-bold text-navy">₹<?php echo number_format((float)$single_quotation['total_gst'], 2); ?></span>
                </div>

                <div class="d-flex justify-content-between py-1.5 border-bottom">
                  <span class="text-secondary small">Delivery / Transport Charges:</span>
                  <span class="fw-semibold text-navy">
                    <?php echo (float)$single_quotation['delivery_fee'] > 0 ? ('₹' . number_format((float)$single_quotation['delivery_fee'], 2)) : 'Free / As Agreed'; ?>
                  </span>
                </div>

                <div class="d-flex justify-content-between align-items-center py-2.5 mt-2 bg-navy text-white px-3 rounded">
                  <span class="fw-bold fs-6 text-gold">Final Quotation Total:</span>
                  <span class="fw-bold fs-4 text-gold"><?php echo format_price($single_quotation['grand_total']); ?></span>
                </div>
              </div>
            </div>
          </div>

          <!-- Bottom Customer Action Confirmation Box (if still Sent) -->
          <?php if ($single_quotation['status'] === 'Sent'): ?>
            <div class="p-4 bg-light rounded-3 border text-center no-print">
              <h5 class="fw-bold text-navy mb-2">Respond to this Wholesale Quotation</h5>
              <p class="text-secondary small mb-3">
                Review all the details carefully. Once accepted, our sales and logistics team will prepare and dispatch your parcel.
              </p>
              <div class="d-flex justify-content-center gap-3 flex-wrap">
                <button type="button" class="btn btn-outline-danger px-4 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#declineQuotationModal">
                  <i class="bi bi-x-circle me-1.5"></i> Decline Quotation
                </button>
                <button type="button" class="btn btn-outline-warning text-dark px-4 py-2 fw-semibold" data-bs-toggle="modal" data-bs-target="#requestChangeModal">
                  <i class="bi bi-pencil-square me-1.5"></i> Request Changes
                </button>
                <button type="button" class="btn btn-gold px-5 py-2 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#acceptQuotationModal">
                  <i class="bi bi-check-circle-fill me-1.5"></i> Accept Quotation (<?php echo format_price($single_quotation['grand_total']); ?>)
                </button>
              </div>
            </div>
          <?php endif; ?>

        </div>

        <!-- MODAL: ACCEPT QUOTATION CONFIRMATION -->
        <div class="modal fade" id="acceptQuotationModal" tabindex="-1" aria-labelledby="acceptModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-3 border-0 shadow">
              <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold" id="acceptModalLabel">
                  <i class="bi bi-check-circle-fill me-2"></i> Confirm Quotation Acceptance
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <form action="quotations.php" method="POST">
                <input type="hidden" name="quotation_id" value="<?php echo $single_quotation['id']; ?>">
                <input type="hidden" name="action" value="accept">
                
                <div class="modal-body p-4 text-center">
                  <i class="bi bi-patch-check-fill text-success fs-1 mb-2 d-block"></i>
                  <h5 class="fw-bold text-navy mb-2">Accept Quotation #<?php echo htmlspecialchars($single_quotation['quotation_number']); ?>?</h5>
                  <p class="text-secondary small mb-3">
                    You are accepting this wholesale order quotation for a total of <strong class="text-navy fs-6"><?php echo format_price($single_quotation['grand_total']); ?></strong>.
                  </p>
                  <div class="alert alert-light border small text-start mb-0">
                    <ul class="mb-0 ps-3">
                      <li>The quotation will be locked as Accepted.</li>
                      <li>Rajpriya factory dispatch team will proceed with your order packing.</li>
                      <li>Payment and transport details will follow the agreed quotation terms.</li>
                    </ul>
                  </div>
                </div>
                <div class="modal-footer bg-light">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button type="submit" class="btn btn-success fw-bold px-4">
                    <i class="bi bi-check2-circle me-1"></i> Yes, Accept Quotation
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- MODAL: DECLINE QUOTATION CONFIRMATION -->
        <div class="modal fade" id="declineQuotationModal" tabindex="-1" aria-labelledby="declineModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content rounded-3 border-0 shadow">
              <div class="modal-header bg-danger text-white">
                <h5 class="modal-title fw-bold" id="declineModalLabel">
                  <i class="bi bi-x-circle-fill me-2"></i> Decline Quotation
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <form action="quotations.php" method="POST">
                <input type="hidden" name="quotation_id" value="<?php echo $single_quotation['id']; ?>">
                <input type="hidden" name="action" value="decline">

                <div class="modal-body p-4">
                  <p class="text-secondary small mb-3">
                    Are you sure you want to decline Quotation #<strong><?php echo htmlspecialchars($single_quotation['quotation_number']); ?></strong>?
                  </p>
                  <div class="mb-3">
                    <label class="form-label small fw-semibold text-navy">Reason for declining (Optional):</label>
                    <textarea name="decline_reason" class="form-control" rows="3" placeholder="e.g. Price too high, need different ruling types, change in quantity..."></textarea>
                    <div class="form-text small text-muted">Providing a reason helps our sales team revise the quotation according to your budget and specifications.</div>
                  </div>
                </div>
                <div class="modal-footer bg-light">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button type="submit" class="btn btn-danger fw-bold px-4">
                    <i class="bi bi-x-circle me-1"></i> Confirm &amp; Decline
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <!-- MODAL: REQUEST CHANGES ON QUOTATION (Requirement 2) -->
        <div class="modal fade" id="requestChangeModal" tabindex="-1" aria-labelledby="requestChangeModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content rounded-3 border-0 shadow">
              <div class="modal-header bg-navy text-white">
                <h5 class="modal-title fw-bold" id="requestChangeModalLabel">
                  <i class="bi bi-pencil-square text-gold me-2"></i> Request Changes to Quotation #<?php echo htmlspecialchars($single_quotation['quotation_number']); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <form action="quotations.php" method="POST" id="customerChangeRequestForm" onsubmit="return validateCustomerChanges()">
                <input type="hidden" name="quotation_id" value="<?php echo $single_quotation['id']; ?>">
                <input type="hidden" name="action" value="request_change">

                <div class="modal-body p-4">
                  <div class="alert alert-light border border-secondary-subtle small mb-3">
                    <i class="bi bi-info-circle-fill text-primary me-1"></i>
                    You can remove products you no longer need or adjust the required quantity for any product. Our wholesale manager will review your revisions and update the quotation accordingly.
                  </div>

                  <h6 class="fw-bold text-navy mb-2">Review &amp; Adjust Quotation Products:</h6>
                  <div class="table-responsive mb-3 border rounded">
                    <table class="table align-middle table-sm mb-0">
                      <thead class="table-light text-navy">
                        <tr>
                          <th style="min-width: 200px;">Product &amp; Variety</th>
                          <th class="text-center" style="width: 100px;">Order Type</th>
                          <th class="text-center" style="width: 140px;">Quantity</th>
                          <th class="text-end" style="width: 110px;">Unit Price</th>
                          <th class="text-center" style="width: 110px;">Remove Item?</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($single_items as $sItm): 
                          $sItemId = (int)$sItm['id'];
                          $sIsRemoved = !empty($sItm['is_customer_removed']) || (($sItm['customer_action'] ?? '') === 'removed');
                          $sQty = (int)$sItm['quantity'];
                          $sOrigQty = isset($sItm['original_quantity']) && $sItm['original_quantity'] !== null ? (int)$sItm['original_quantity'] : $sQty;
                        ?>
                          <tr class="modal-item-row <?php echo $sIsRemoved ? 'table-danger-subtle' : ''; ?>" id="modal_item_row_<?php echo $sItemId; ?>">
                            <td>
                              <div class="fw-bold text-navy"><?php echo htmlspecialchars($sItm['product_name']); ?></div>
                              <div class="small text-muted d-flex align-items-center gap-1 flex-wrap">
                                <?php if (!empty($sItm['variety'])): ?>
                                  <span class="badge bg-gold-subtle text-navy border border-warning-subtle"><?php echo htmlspecialchars($sItm['variety']); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($sItm['product_code'])): ?>
                                  <span class="font-monospace text-secondary"><?php echo htmlspecialchars($sItm['product_code']); ?></span>
                                <?php endif; ?>
                              </div>
                            </td>
                            <td class="text-center small">
                              <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($sItm['order_type']); ?></span>
                            </td>
                            <td class="text-center">
                              <div class="d-flex align-items-center justify-content-center gap-1">
                                <input type="number" 
                                       name="items[<?php echo $sItemId; ?>][quantity]" 
                                       id="modal_qty_<?php echo $sItemId; ?>" 
                                       class="form-control form-control-sm text-center fw-bold modal-item-qty" 
                                       min="1" 
                                       value="<?php echo $sQty; ?>" 
                                       style="max-width: 90px;"
                                       <?php echo $sIsRemoved ? 'disabled' : ''; ?>>
                              </div>
                              <div class="small text-muted mt-0.5" style="font-size: 0.72rem;">Original: <?php echo $sOrigQty; ?></div>
                            </td>
                            <td class="text-end fw-semibold">
                              ₹<?php echo number_format((float)$sItm['unit_price'], 2); ?>
                            </td>
                            <td class="text-center">
                              <div class="form-check form-switch d-inline-block">
                                <input class="form-check-input modal-remove-toggle" 
                                       type="checkbox" 
                                       role="switch" 
                                       name="items[<?php echo $sItemId; ?>][remove]" 
                                       value="1" 
                                       id="remove_switch_<?php echo $sItemId; ?>" 
                                       <?php echo $sIsRemoved ? 'checked' : ''; ?>
                                       onchange="toggleModalItemRemoval(this, <?php echo $sItemId; ?>)">
                                <label class="form-check-label small text-danger fw-semibold" for="remove_switch_<?php echo $sItemId; ?>">
                                  Remove
                                </label>
                              </div>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>

                  <div class="mb-3">
                    <label class="form-label small fw-semibold text-navy">
                      <i class="bi bi-chat-left-text me-1"></i> Note to Rajpriya Wholesale Manager (Optional):
                    </label>
                    <textarea name="customer_notes" class="form-control" rows="3" placeholder="Explain your requested changes (e.g., reduce order size for unruled notebook, only need 300 pieces of 192pgs, budget adjustments...)"><?php echo htmlspecialchars($single_quotation['customer_notes'] ?? ''); ?></textarea>
                  </div>
                </div>
                <div class="modal-footer bg-light">
                  <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                  <button type="submit" class="btn btn-warning fw-bold px-4 text-dark shadow-sm">
                    <i class="bi bi-send-check-fill me-1"></i> Submit Revised Request
                  </button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <script>
        function toggleModalItemRemoval(chk, itemId) {
            const qtyInput = document.getElementById('modal_qty_' + itemId);
            const row = document.getElementById('modal_item_row_' + itemId);
            if (chk.checked) {
                if (qtyInput) qtyInput.disabled = true;
                if (row) row.classList.add('table-danger-subtle');
            } else {
                if (qtyInput) qtyInput.disabled = false;
                if (row) row.classList.remove('table-danger-subtle');
            }
        }

        function validateCustomerChanges() {
            const toggles = document.querySelectorAll('.modal-remove-toggle');
            let totalItems = toggles.length;
            let removedItems = 0;

            toggles.forEach(t => {
                if (t.checked) removedItems++;
            });

            if (totalItems > 0 && removedItems >= totalItems) {
                alert('You cannot remove all products from the quotation. At least one product must remain.');
                return false;
            }

            return true;
        }
        </script>

      <?php else: ?>
        <!-- ======================================================== -->
        <!-- QUOTATIONS LIST VIEW -->
        <!-- ======================================================== -->
        <div class="bg-white p-4 rounded-3 border shadow-sm">
          <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
            <div>
              <h4 class="fw-bold text-navy mb-0">
                <i class="bi bi-file-earmark-text text-gold me-2"></i> My Order Quotations
              </h4>
              <small class="text-secondary">Review wholesale price quotations sent by the Rajpriya team for your orders.</small>
            </div>
            <?php if ($pending_count > 0): ?>
              <span class="badge bg-danger rounded-pill px-3 py-1.5">
                <?php echo $pending_count; ?> Quotation(s) Pending Review
              </span>
            <?php endif; ?>
          </div>

          <?php if (empty($quotations)): ?>
            <div class="text-center py-5">
              <i class="bi bi-file-earmark-text fs-1 text-muted d-block mb-3"></i>
              <h5 class="fw-bold text-navy mb-1">No Quotations Available</h5>
              <p class="text-secondary small mb-4">
                You do not have any wholesale quotations at this time. When you request a quotation or our sales team reviews your bulk orders, quotations will be delivered here.
              </p>
              <div class="d-flex justify-content-center gap-2">
                <a href="products.php" class="btn btn-gold btn-sm px-4 fw-bold">Explore Notebooks</a>
                <a href="orders.php" class="btn btn-outline-navy btn-sm px-4 fw-semibold">View My Orders</a>
              </div>
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table align-middle table-hover">
                <thead class="table-light text-navy">
                  <tr>
                    <th>Quotation #</th>
                    <th>Related Order</th>
                    <th>Date Sent</th>
                    <th>Items Summary</th>
                    <th>Total Amount</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($quotations as $q): ?>
                    <tr class="<?php echo ($q['status'] === 'Sent') ? 'table-warning-subtle' : ''; ?>">
                      <td>
                        <div class="fw-bold text-navy">
                          <a href="quotations.php?view=<?php echo $q['id']; ?>" class="text-navy text-decoration-none">
                            <?php echo htmlspecialchars($q['quotation_number']); ?>
                          </a>
                        </div>
                        <?php if ((int)$q['version'] > 1): ?>
                          <span class="badge bg-light text-dark border">Version <?php echo $q['version']; ?></span>
                        <?php endif; ?>
                      </td>
                      <td>
                        <a href="order-details.php?id=<?php echo $q['order_id']; ?>" class="text-decoration-none fw-semibold text-navy">
                          <?php echo htmlspecialchars($q['order_number']); ?>
                        </a>
                      </td>
                      <td class="small text-muted">
                        <?php echo format_datetime(!empty($q['sent_at']) ? $q['sent_at'] : $q['created_at']); ?>
                      </td>
                      <td class="small">
                        <div class="text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($q['items_summary'] ?? ''); ?>">
                          <?php echo htmlspecialchars($q['items_summary'] ?: ($q['item_count'] . ' Product items')); ?>
                        </div>
                      </td>
                      <td class="fw-bold text-navy fs-6">
                        <?php echo format_price($q['grand_total']); ?>
                      </td>
                      <td>
                        <?php echo render_quotation_status_badge($q['status']); ?>
                        <?php if ($q['status'] === 'Accepted' && !empty($q['customer_response_at'])): ?>
                          <div class="small text-success" style="font-size: 0.7rem;">Accepted <?php echo format_datetime($q['customer_response_at']); ?></div>
                        <?php elseif ($q['status'] === 'Declined' && !empty($q['customer_response_at'])): ?>
                          <div class="small text-danger" style="font-size: 0.7rem;">Declined <?php echo format_datetime($q['customer_response_at']); ?></div>
                        <?php endif; ?>
                      </td>
                      <td class="text-end">
                        <a href="quotations.php?view=<?php echo $q['id']; ?>" class="btn btn-sm <?php echo ($q['status'] === 'Sent') ? 'btn-gold fw-bold' : 'btn-outline-gold'; ?>">
                          <?php echo ($q['status'] === 'Sent') ? 'Review & Respond' : 'View Details'; ?>
                        </a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
