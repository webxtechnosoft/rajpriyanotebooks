<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();

// Handle Status Change or Transport Details POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status') {
        $order_id = (int)$_POST['order_id'];
        $order_status = sanitize($_POST['order_status']);
        $payment_status = sanitize($_POST['payment_status']);

        $stmt_cur = $db->prepare("SELECT order_status, order_number FROM orders WHERE id = ?");
        $stmt_cur->execute([$order_id]);
        $current_order = $stmt_cur->fetch();

        if ($current_order && $current_order['order_status'] !== 'Cancelled' && $order_status === 'Cancelled') {
            // Restore stock for cancelled order items
            $stmt_items = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $stmt_items->execute([$order_id]);
            $order_items = $stmt_items->fetchAll();

            $stmt_rst_prod = $db->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
            $stmt_rst_var = $db->prepare("
                UPDATE product_variants 
                SET stock = stock + ? 
                WHERE product_id = ? 
                  AND (product_code = ? OR UPPER(variety) = ? OR UPPER(variety_display) = ?)
                LIMIT 1
            ");
            $stmt_rst_log = $db->prepare("INSERT INTO stock_history (product_id, previous_stock, new_stock, change_amount, reason, admin_username) VALUES (?, ?, ?, ?, ?, ?)");

            $admin = get_current_admin();
            $adminUser = $admin['username'] ?? 'admin';

            foreach ($order_items as $oi) {
                $pid = (int)$oi['product_id'];
                $qty = (int)$oi['quantity'];
                $isBundleOrder = (stripos($oi['ruling_type'] ?? '', 'Bundle') !== false);

                $vInfo = parse_variety_info($oi['ruling_type'] ?? '');
                $pureVar = !empty($vInfo['variety']) ? $vInfo['variety'] : ($oi['ruling_type'] ?? '');

                // Fetch variant to restore wholesale bundles/pieces
                $stmt_find_v = $db->prepare("
                    SELECT id, bundle_stock, bundle_qty, loose_stock, stock 
                    FROM product_variants 
                    WHERE product_id = ? 
                      AND (product_code = ? OR UPPER(variety) = ? OR UPPER(variety_display) = ?)
                    LIMIT 1
                ");
                $stmt_find_v->execute([$pid, $oi['product_code'], strtoupper($pureVar), strtoupper($pureVar)]);
                $curVar = $stmt_find_v->fetch(PDO::FETCH_ASSOC);

                if ($curVar) {
                    $bStock = (int)$curVar['bundle_stock'];
                    $bQty = max(1, (int)$curVar['bundle_qty']);
                    $lStock = (int)$curVar['loose_stock'];

                    if ($isBundleOrder) {
                        // Restore bundles directly
                        $newBundleStock = $bStock + $qty;
                        $newLooseStock = $lStock;
                    } else {
                        // Restore loose pieces
                        $newLooseStock = $lStock + $qty;
                        $newBundleStock = $bStock;
                    }

                    $newTotalPieces = ($newBundleStock * $bQty) + $newLooseStock;

                    $stmt_up_v = $db->prepare("
                        UPDATE product_variants 
                        SET bundle_stock = ?, loose_stock = ?, stock = ? 
                        WHERE id = ?
                    ");
                    $stmt_up_v->execute([$newBundleStock, $newLooseStock, $newTotalPieces, $curVar['id']]);
                }

                $curProdStock = (int)$db->query("SELECT stock FROM products WHERE id = $pid")->fetchColumn();
                $newProdStock = $curProdStock + $qty;

                // Sync parent product totals
                $db->prepare("
                    UPDATE products 
                    SET bundle_stock = (SELECT COALESCE(SUM(bundle_stock), 0) FROM product_variants WHERE product_id = ?),
                        stock = (SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = ?) 
                    WHERE id = ?
                ")->execute([$pid, $pid, $pid]);

                $logMsg = $isBundleOrder ? "Order #" . $current_order['order_number'] . " Cancelled - $qty Bundles of $pureVar Restored" : "Order #" . $current_order['order_number'] . " Cancelled - $qty Pcs of $pureVar Restored";
                $stmt_rst_log->execute([
                    $pid,
                    $curProdStock,
                    $newProdStock,
                    $qty,
                    $logMsg,
                    $adminUser
                ]);
            }
        }

        $stmt = $db->prepare("UPDATE orders SET order_status = ?, payment_status = ? WHERE id = ?");
        $stmt->execute([$order_status, $payment_status, $order_id]);

        set_flash('success', "Order #$order_id status updated to '$order_status'.");
        header("Location: orders.php?view=" . $order_id);
        exit();
    } elseif ($_POST['action'] === 'update_transport') {
        $order_id = (int)$_POST['order_id'];
        $transport_from = sanitize($_POST['transport_from'] ?? '');
        $transport_to = sanitize($_POST['transport_to'] ?? '');
        $transport_name = sanitize($_POST['transport_name'] ?? '');
        $transport_branch = sanitize($_POST['transport_branch'] ?? '');
        $transport_contact = sanitize($_POST['transport_contact'] ?? '');
        $transport_lr_number = sanitize($_POST['transport_lr_number'] ?? '');
        $transport_vehicle_number = sanitize($_POST['transport_vehicle_number'] ?? '');
        $raw_date = !empty($_POST['dispatch_date']) ? $_POST['dispatch_date'] : (!empty($_POST['transport_date']) ? $_POST['transport_date'] : null);
        $transport_date = !empty($raw_date) ? sanitize($raw_date) : null;
        $dispatch_date = $transport_date;
        $transport_remarks = sanitize($_POST['transport_remarks'] ?? '');

        $stmt = $db->prepare("
            UPDATE orders SET 
                transport_from = ?,
                transport_to = ?,
                transport_name = ?,
                transport_branch = ?,
                transport_contact = ?,
                transport_lr_number = ?,
                transport_vehicle_number = ?,
                transport_date = ?,
                dispatch_date = ?,
                transport_remarks = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $transport_from,
            $transport_to,
            $transport_name,
            $transport_branch,
            $transport_contact,
            $transport_lr_number,
            $transport_vehicle_number,
            $transport_date,
            $dispatch_date,
            $transport_remarks,
            $order_id
        ]);

        // Synchronize dispatch date & transport info to all existing parcels of this order
        $stmt_pcl_sync = $db->prepare("
            UPDATE order_parcels SET 
                dispatch_date = ?,
                transport_name = ?,
                lr_number = ?,
                transport_from = ?,
                transport_to = ?,
                vehicle_number = ?,
                transport_contact = ?
            WHERE order_id = ?
        ");
        $stmt_pcl_sync->execute([
            $dispatch_date,
            $transport_name,
            $transport_lr_number,
            $transport_from,
            $transport_to,
            $transport_vehicle_number,
            $transport_contact,
            $order_id
        ]);

        set_flash('success', "Transport details updated successfully for Order #$order_id.");
        header("Location: orders.php?view=" . $order_id);
        exit();
    } elseif ($_POST['action'] === 'create_parcel') {
        $order_id = (int)$_POST['order_id'];
        $items_pack = $_POST['items'] ?? [];
        $parcel_weight = (float)($_POST['parcel_weight'] ?? 0);
        $box_dimensions = sanitize($_POST['box_dimensions'] ?? '');
        $remarks = sanitize($_POST['remarks'] ?? '');

        $parcel_id = create_order_parcel($order_id, $items_pack, $parcel_weight, $box_dimensions, $remarks);
        if ($parcel_id) {
            set_flash('success', "New Parcel created successfully! Unique QR Code generated.");
        } else {
            set_flash('danger', "Failed to create parcel. Please select at least 1 item quantity to pack.");
        }
        header("Location: orders.php?view=" . $order_id);
        exit();
    } elseif ($_POST['action'] === 'delete_parcel') {
        $parcel_id = (int)$_POST['parcel_id'];
        $order_id = (int)$_POST['order_id'];
        delete_order_parcel($parcel_id);
        set_flash('success', "Parcel removed successfully.");
        header("Location: orders.php?view=" . $order_id);
        exit();
    } elseif ($_POST['action'] === 'save_parcel_verification') {
        $order_id = (int)$_POST['order_id'];
        $expected_parcels = (isset($_POST['expected_parcels']) && $_POST['expected_parcels'] !== '') ? max(1, (int)$_POST['expected_parcels']) : null;
        $actual_parcels = (isset($_POST['actual_parcels']) && $_POST['actual_parcels'] !== '') ? max(1, (int)$_POST['actual_parcels']) : null;

        $stmt = $db->prepare("UPDATE orders SET expected_parcels = ?, actual_parcels = ? WHERE id = ?");
        $stmt->execute([$expected_parcels, $actual_parcels, $order_id]);

        set_flash('success', "Parcel packing verification details saved for Order #$order_id.");
        header("Location: orders.php?view=" . $order_id);
        exit();
    } elseif ($_POST['action'] === 'send_whatsapp_invoice') {
        $order_id = (int)$_POST['order_id'];
        $res = send_order_invoice_whatsapp($order_id, true);
        if (!empty($res['success'])) {
            $msg = "Invoice PDF successfully sent via WhatsApp to " . htmlspecialchars($res['phone'] ?? '') . ".";
            if (!empty($res['message_id'])) {
                $msg .= " (Message ID: " . htmlspecialchars($res['message_id']) . ")";
            }
            set_flash('success', $msg);
        } else {
            set_flash('error', "WhatsApp Delivery Failed: " . htmlspecialchars($res['error'] ?? 'Unknown error'));
        }
        header("Location: orders.php?view=" . $order_id);
        exit();
    }
}

require_once __DIR__ . '/includes/admin_header.php';
$logos = get_active_logos();

// Single Order Detail View
$view_id = isset($_GET['view']) ? (int)$_GET['view'] : 0;
if ($view_id > 0) {
    $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$view_id]);
    $single_order = $stmt->fetch();

    if ($single_order) {
        $stmt_items = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt_items->execute([$single_order['id']]);
        $single_items = $stmt_items->fetchAll();

        // Precise piece, bundle, remaining, and packed unit calculations
        $order_packing = get_order_packing_details($single_order['id'], $single_items);
        $total_pieces_to_dispatch = $order_packing['total_ordered_pieces'];
        $total_full_bundles       = $order_packing['total_full_bundles'];
        $total_remaining_pieces   = $order_packing['total_remaining_pieces'];
        $total_partial_bundles    = $order_packing['total_partial_bundles'];
        $total_packed_units       = $order_packing['total_packed_units'];
        $total_bundle_count       = $total_full_bundles;
        $total_extra_piece_qty    = $total_remaining_pieces;
        $order_parcel_count       = $order_packing['parcel_count'];

        $order_bundle_items = [];
        $order_extra_piece_items = [];
        foreach ($order_packing['items'] as $item_row) {
            if ($item_row['full_bundles'] > 0) {
                $b_row = $item_row;
                $b_row['bundle_display_qty'] = $item_row['full_bundles'];
                $order_bundle_items[] = $b_row;
            }
            if ($item_row['remaining_pieces'] > 0) {
                $e_row = $item_row;
                $e_row['extra_display_qty'] = $item_row['remaining_pieces'];
                $order_extra_piece_items[] = $e_row;
            }
        }
    }
}

// Filter Status, Search & Dates
$status_filter = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_from = isset($_GET['date_from']) ? sanitize($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? sanitize($_GET['date_to']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$whereClauses = ["1=1"];
$params = [];

if ($status_filter) {
    $whereClauses[] = "o.order_status = ?";
    $params[] = $status_filter;
}

if ($search !== '') {
    $term = "%{$search}%";
    $num_id = is_numeric($search) ? (int)$search : 0;
    
    $searchParts = [
        "o.order_number LIKE ?",
        "o.customer_code LIKE ?",
        "o.customer_name LIKE ?",
        "o.customer_email LIKE ?",
        "o.customer_phone LIKE ?",
        "o.order_status LIKE ?",
        "o.shipping_address LIKE ?",
        "o.city LIKE ?",
        "o.transport_lr_number LIKE ?",
        "o.transport_name LIKE ?",
        "EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND (oi.product_name LIKE ? OR oi.product_code LIKE ?))",
        "EXISTS (SELECT 1 FROM order_parcels op WHERE op.order_id = o.id AND (op.parcel_number LIKE ? OR op.lr_number LIKE ?))"
    ];
    
    $params[] = $term; // order_number
    $params[] = $term; // customer_code
    $params[] = $term; // customer_name
    $params[] = $term; // customer_email
    $params[] = $term; // customer_phone
    $params[] = $term; // order_status
    $params[] = $term; // shipping_address
    $params[] = $term; // city
    $params[] = $term; // transport_lr_number
    $params[] = $term; // transport_name
    $params[] = $term; // oi.product_name
    $params[] = $term; // oi.product_code
    $params[] = $term; // op.parcel_number
    $params[] = $term; // op.lr_number
    
    if ($num_id > 0) {
        $searchParts[] = "o.id = ?";
        $params[] = $num_id;
    }
    
    $whereClauses[] = "(" . implode(" OR ", $searchParts) . ")";
}

if ($date_from) {
    $whereClauses[] = "DATE(o.created_at) >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $whereClauses[] = "DATE(o.created_at) <= ?";
    $params[] = $date_to;
}

$whereSql = implode(" AND ", $whereClauses);

// Count matching orders for pagination
$countStmt = $db->prepare("SELECT COUNT(*) FROM orders o WHERE $whereSql");
$countStmt->execute($params);
$total_orders_count = (int)$countStmt->fetchColumn();
$total_pages = max(1, ceil($total_orders_count / $per_page));

// Fetch paginated orders
$querySql = "SELECT o.* FROM orders o WHERE $whereSql ORDER BY o.id DESC LIMIT $per_page OFFSET $offset";
$stmt = $db->prepare($querySql);
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Batch prefetch latest quotation per order for high-performance listing
$order_quotations_map = [];
if (!empty($orders)) {
    $orderIds = array_column($orders, 'id');
    $inPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
    $stmt_q_map = $db->prepare("
        SELECT q.order_id, q.id, q.quotation_number, q.status, q.grand_total, q.sent_at, q.customer_response_at, q.customer_decline_reason 
        FROM quotations q
        INNER JOIN (
            SELECT MAX(id) as max_id FROM quotations WHERE order_id IN ($inPlaceholders) GROUP BY order_id
        ) latest ON q.id = latest.max_id
    ");
    $stmt_q_map->execute($orderIds);
    while ($qRow = $stmt_q_map->fetch(PDO::FETCH_ASSOC)) {
        $order_quotations_map[$qRow['order_id']] = $qRow;
    }
}
?>

<?php if (isset($single_order) && $single_order): ?>
  <!-- Single Order Details & Tax Invoice View -->
  <div class="d-flex justify-content-between align-items-center mb-4 no-print flex-wrap gap-2 admin-page-header">
    <div>
      <a href="orders.php" class="small text-secondary"><i class="bi bi-arrow-left"></i> Back to All Orders</a>
      <h3 class="fw-bold text-navy mb-0">Order Invoice #<?php echo htmlspecialchars($single_order['order_number']); ?></h3>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <!-- Bundle Label Button -->
      <?php 
        $bundle_btn_suffix = '';
        if ($total_full_bundles > 0) {
            $bundle_btn_suffix = " ({$total_full_bundles})";
        } elseif ($total_partial_bundles > 0) {
            $bundle_btn_suffix = " (1 Part)";
        }
      ?>
      <a href="print_label.php?order_id=<?php echo $single_order['id']; ?>&type=bundle" target="_blank" class="btn btn-outline-dark btn-sm px-3 fw-bold" style="border-radius: 6px;" title="Print Bundle Label">
        <i class="bi bi-box-seam text-gold me-1.5"></i> Bundle Label<?php echo $bundle_btn_suffix; ?>
      </a>

      <!-- Extra Pieces Label Button -->
      <?php if ($total_extra_piece_qty > 0): ?>
        <a href="print_label.php?order_id=<?php echo $single_order['id']; ?>&type=extra" target="_blank" class="btn btn-primary btn-sm px-3 fw-bold shadow-sm" style="border-radius: 6px; background-color: #2B6CB0; border-color: #2B6CB0;" title="Create & Print Extra Piece Label">
          <i class="bi bi-tag-fill me-1.5"></i> Extra Pieces Label (<?php echo $total_extra_piece_qty; ?> Pieces)
        </a>
      <?php else: ?>
        <button type="button" class="btn btn-outline-secondary btn-sm px-3 fw-semibold disabled" style="border-radius: 6px; opacity: 0.6; cursor: not-allowed;" title="No extra pieces in this order" disabled>
          <i class="bi bi-tag me-1.5"></i> Extra Pieces Label (0 Pieces)
        </button>
      <?php endif; ?>

      <!-- Parcel Label Button (Reference Parcel Shipping Sticker) -->
      <a href="print_parcel_sticker.php?order_id=<?php echo $single_order['id']; ?>" target="_blank" class="btn btn-warning btn-sm px-3 fw-bold shadow-sm" style="border-radius: 6px;" title="Print Official Parcel Dispatch Label Sticker with Scannable QR Code">
        <i class="bi bi-box-seam-fill me-1.5"></i> Parcel Label<?php echo $order_parcel_count > 0 ? " ({$order_parcel_count})" : ''; ?>
      </a>

      <!-- Order Verification QR Codes -->
      <?php 
      $order_qr_link = get_order_tracking_url($single_order['order_number']);
      $order_qr_image = generate_qr_code_image_url($order_qr_link, 160);
      ?>
      <a href="<?php echo htmlspecialchars($order_qr_link); ?>" target="_blank" class="btn btn-outline-dark btn-sm px-3 fw-bold" style="border-radius: 6px;" title="Scan/View Public QR Order Tracking Page">
        <i class="bi bi-qr-code text-gold me-1.5"></i> Order QR
      </a>
      <a href="<?php echo $order_qr_image; ?>" download="QR-<?php echo htmlspecialchars($single_order['order_number']); ?>.png" target="_blank" class="btn btn-outline-secondary btn-sm px-2.5 fw-bold" style="border-radius: 6px;" title="Download Order QR Code PNG">
        <i class="bi bi-download me-1"></i> QR PNG
      </a>

      <a href="orders_xml.php?order_id=<?php echo $single_order['id']; ?>" class="btn btn-outline-primary btn-sm px-3 fw-bold" style="border-radius: 6px;">
        <i class="bi bi-file-earmark-code me-1.5"></i> Download XML
      </a>
      <a href="orders_excel.php?order_id=<?php echo $single_order['id']; ?>" class="btn text-white btn-sm px-3 fw-bold" style="background-color: #17243A; border-radius: 6px;">
        <i class="bi bi-file-earmark-excel text-gold me-1.5"></i> Download Excel
      </a>
      <?php $single_order_quotation = get_order_quotation($single_order['id']); ?>
      <a href="quotation.php?order_id=<?php echo $single_order['id']; ?>" class="btn btn-gold btn-sm px-3 fw-bold shadow-sm" style="border-radius: 6px;" title="Create, Edit, or Send Order Quotation">
        <i class="bi bi-file-earmark-text me-1.5"></i> <?php echo $single_order_quotation ? 'Quotation (' . $single_order_quotation['status'] . ')' : 'Send Quotation'; ?>
      </a>
      <button onclick="window.print()" class="btn btn-warning btn-sm px-3 fw-bold"><i class="bi bi-printer me-1"></i> Print Tax Invoice</button>
      <form action="orders.php?view=<?php echo $single_order['id']; ?>" method="POST" class="d-inline" onsubmit="return confirm('Send / Resend Tax Invoice PDF to customer via WhatsApp?');">
        <input type="hidden" name="action" value="send_whatsapp_invoice">
        <input type="hidden" name="order_id" value="<?php echo $single_order['id']; ?>">
        <button type="submit" class="btn btn-success btn-sm px-3 fw-bold shadow-sm" style="border-radius: 6px;" title="Send Official Invoice PDF to Customer WhatsApp">
          <i class="bi bi-whatsapp me-1"></i> WhatsApp Invoice
        </button>
      </form>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <!-- Left Column: Official Printable Tax Invoice Letterhead -->
    <div class="col-lg-8">
      <div class="bg-white p-4 rounded-3 border">
        
        <!-- 1. Invoice Top Header & Brand Logo -->
        <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-4">
          <div>
            <img src="../<?php echo htmlspecialchars($logos['primary']); ?>" alt="Rajpriya Notebooks" style="height: 58px; object-fit: contain;" class="mb-2">
            <h4 class="fw-bold text-navy mb-0">RAJPRIYA NOTEBOOKS</h4>
            <div class="small text-muted fw-semibold">Quality Notebooks — Made for Better Learning</div>
            <div class="small text-secondary mt-1">123, Station Road, Salem - 636001, Tamil Nadu | Phone: +91 98765 43210</div>
            <div class="small fw-bold text-navy mt-1">GSTIN: <?php echo htmlspecialchars(COMPANY_GSTIN); ?></div>
          </div>
          <div class="text-end d-flex align-items-center justify-content-end gap-3">
            <div>
              <span class="badge bg-gold text-navy fw-bold px-3 py-1.5 fs-6 mb-2">TAX INVOICE</span>
              <div class="fw-bold text-navy fs-5">Invoice #: <?php echo htmlspecialchars($single_order['order_number']); ?></div>
              <div class="small text-muted">Date: <?php echo format_datetime($single_order['created_at']); ?></div>
              <div class="small text-navy fw-semibold mt-1">Payment: <?php echo htmlspecialchars($single_order['payment_method']); ?> (<?php echo htmlspecialchars($single_order['payment_status']); ?>)</div>
            </div>
            <div class="text-center">
              <a href="<?php echo htmlspecialchars($order_qr_link); ?>" target="_blank" title="Scan or Click to Verify Live Order">
                <img src="<?php echo $order_qr_image; ?>" alt="Invoice QR" style="width: 78px; height: 78px; border-radius: 4px;" class="border p-1 bg-white">
              </a>
              <div class="text-muted fw-bold" style="font-size: 0.58rem; letter-spacing: 0.5px;">SCAN TO VERIFY</div>
            </div>
          </div>
        </div>

        <!-- 2. Customer & Shipping Info Grid -->
        <div class="row g-3 mb-4">
          <div class="col-md-6">
            <div class="p-3 bg-light rounded border h-100">
              <div class="d-flex align-items-center justify-content-between mb-1">
                <h6 class="fw-bold text-navy border-bottom pb-1 mb-0 flex-grow-1"><i class="bi bi-person-fill text-gold me-1"></i> Customer & Billed To:</h6>
                <?php if (!empty($single_order['customer_code'])): ?>
                  <a href="customers.php?view=<?php echo urlencode($single_order['customer_code']); ?>" class="badge bg-navy text-gold text-decoration-none fw-bold px-2 py-1 ms-2" title="View Customer Profile & Lifetime Orders">
                    <i class="bi bi-person-badge me-1"></i><?php echo htmlspecialchars($single_order['customer_code']); ?>
                  </a>
                <?php endif; ?>
              </div>
              <div class="fw-bold text-navy fs-6"><?php echo htmlspecialchars($single_order['customer_name']); ?></div>
              <div class="small text-secondary mt-1">Phone: <?php echo htmlspecialchars($single_order['customer_phone']); ?></div>
              <div class="small text-secondary">
                Email: <?php echo !empty($single_order['customer_email']) ? htmlspecialchars($single_order['customer_email']) : '<span class="text-muted fst-italic">Not Provided</span>'; ?>
              </div>
              <div class="small text-navy fw-semibold mt-1">
                GST Registration: 
                <?php 
                $ord_is_reg = (!empty($single_order['gst_status']) && strcasecmp($single_order['gst_status'], 'Registered') === 0) || !empty($single_order['gst_number']);
                if ($ord_is_reg && !empty($single_order['gst_number'])): ?>
                  <span class="badge bg-success px-1.5 py-0.5"><i class="bi bi-check-circle-fill me-0.5"></i>Registered</span>
                  <span class="badge bg-gold text-navy px-1.5 py-0.5"><?php echo htmlspecialchars($single_order['gst_number']); ?></span>
                <?php else: ?>
                  <span class="badge bg-secondary px-1.5 py-0.5"><i class="bi bi-x-circle me-0.5"></i>Not Registered</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <div class="col-md-6">
            <div class="p-3 bg-light rounded border h-100">
              <h6 class="fw-bold text-navy border-bottom pb-1 mb-2"><i class="bi bi-geo-alt-fill text-gold me-1"></i> Shipping Address:</h6>
              <div class="small text-dark">
                <div class="fw-semibold text-navy"><?php echo htmlspecialchars($single_order['shipping_address']); ?></div>
                <?php if (!empty($single_order['address_line2'])): ?>
                  <div><?php echo htmlspecialchars($single_order['address_line2']); ?></div>
                <?php endif; ?>
                <?php if (!empty($single_order['area'])): ?>
                  <div>Area: <?php echo htmlspecialchars($single_order['area']); ?></div>
                <?php endif; ?>
                <div class="fw-bold text-navy mt-0.5">
                  <?php echo htmlspecialchars($single_order['city']); ?>
                  <?php if (!empty($single_order['district']) && strcasecmp($single_order['district'], $single_order['city']) !== 0): ?>
                    , <?php echo htmlspecialchars($single_order['district']); ?>
                  <?php endif; ?>
                  , <?php echo htmlspecialchars($single_order['state'] . ' - ' . $single_order['pincode']); ?>
                </div>
                <?php if (!empty($single_order['landmark'])): ?>
                  <div class="fst-italic text-muted mt-1"><small>Landmark: <?php echo htmlspecialchars($single_order['landmark']); ?></small></div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <?php 
        $has_transport = !empty($single_order['transport_from']) || 
                         !empty($single_order['transport_to']) || 
                         !empty($single_order['transport_name']) || 
                         !empty($single_order['transport_branch']) || 
                         !empty($single_order['transport_contact']) || 
                         !empty($single_order['transport_lr_number']) || 
                         !empty($single_order['transport_vehicle_number']) || 
                         !empty($single_order['transport_date']) || 
                         !empty($single_order['transport_remarks']);
        ?>

        <?php if ($has_transport): ?>
          <!-- 2.5 Transport / Parcel Reference Details (Shown on Invoice & Print) -->
          <div class="p-3 bg-light rounded border mb-4">
            <h6 class="fw-bold text-navy border-bottom pb-1 mb-2 d-flex align-items-center justify-content-between">
              <span><i class="bi bi-truck text-gold me-1.5"></i> TRANSPORT / PARCEL DETAILS:</span>
              <span class="badge bg-navy text-gold fw-bold px-2 py-0.5" style="font-size: 0.685rem;">DISPATCH REF</span>
            </h6>
            <div class="row g-2 small text-dark">
              <?php if (!empty($single_order['transport_from'])): ?>
                <div class="col-sm-6"><strong>From Location:</strong> <?php echo htmlspecialchars($single_order['transport_from']); ?></div>
              <?php endif; ?>
              <?php if (!empty($single_order['transport_to'])): ?>
                <div class="col-sm-6"><strong>To Location:</strong> <?php echo htmlspecialchars($single_order['transport_to']); ?></div>
              <?php endif; ?>
              <?php if (!empty($single_order['transport_name'])): ?>
                <div class="col-sm-6"><strong>Transport Service:</strong> <?php echo htmlspecialchars($single_order['transport_name']); ?></div>
              <?php endif; ?>
              <?php if (!empty($single_order['transport_branch'])): ?>
                <div class="col-sm-6"><strong>Transport Branch:</strong> <?php echo htmlspecialchars($single_order['transport_branch']); ?></div>
              <?php endif; ?>
              <?php if (!empty($single_order['transport_contact'])): ?>
                <div class="col-sm-6"><strong>Transport Contact:</strong> <?php echo htmlspecialchars($single_order['transport_contact']); ?></div>
              <?php endif; ?>
              <?php if (!empty($single_order['transport_lr_number'])): ?>
                <div class="col-sm-6"><strong>LR / Parcel No:</strong> <code class="fw-bold text-navy fs-7"><?php echo htmlspecialchars($single_order['transport_lr_number']); ?></code></div>
              <?php endif; ?>
              <?php if (!empty($single_order['transport_vehicle_number'])): ?>
                <div class="col-sm-6"><strong>Vehicle No:</strong> <?php echo htmlspecialchars($single_order['transport_vehicle_number']); ?></div>
              <?php endif; ?>
              <?php 
              $disp_date = !empty($single_order['dispatch_date']) ? $single_order['dispatch_date'] : ($single_order['transport_date'] ?? null);
              if (!empty($disp_date) && $disp_date !== '0000-00-00'): 
              ?>
                <div class="col-sm-6"><strong>Dispatch Date:</strong> <?php echo format_dispatch_date_display($disp_date); ?></div>
              <?php endif; ?>
              <?php if (!empty($single_order['transport_remarks'])): ?>
                <div class="col-12 mt-1 pt-1 border-top border-secondary-subtle"><strong>Remarks:</strong> <?php echo htmlspecialchars($single_order['transport_remarks']); ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- 3. Itemized Order Breakdown Table -->
        <h5 class="fw-bold text-navy mb-3"><i class="bi bi-list-check text-gold me-1"></i> Order Items Breakdown</h5>
        <div class="table-responsive">
          <table class="table table-bordered align-middle mb-4">
            <thead class="table-light">
              <tr class="small text-muted">
                <th style="width: 35px;" class="text-center">#</th>
                <th>Notebook &amp; Variety</th>
                <th class="text-center" style="width: 105px;">Product Code</th>
                <th class="text-center" style="width: 95px;">Pcs / Bdl</th>
                <th class="text-center" style="width: 100px;">Bundles</th>
                <th class="text-center" style="width: 110px;">Total Pieces</th>
                <th class="text-end" style="width: 95px;">Price</th>
                <th class="text-end" style="width: 110px;">Total</th>
              </tr>
            </thead>
            <tbody>
              <?php 
              $sn = 1;
              foreach ($single_items as $itm): 
                $vInfo = parse_variety_info($itm['ruling_type'] ?? '');
                $itemCode = !empty($itm['product_code']) ? $itm['product_code'] : get_variant_product_code($itm['product_id'], $itm['ruling_type'] ?? '');
                $isBundle = (stripos($vInfo['order_type'] ?? '', 'Bundle') !== false) || (stripos($itm['ruling_type'] ?? '', 'Bundle') !== false);
                $qty = (int)$itm['quantity'];
                $ppb = !empty($itm['pieces_per_bundle']) ? (int)$itm['pieces_per_bundle'] : get_product_pieces_per_bundle($itm['product_id'], $itm['ruling_type'] ?? '', null);
                $totalPieces = !empty($itm['total_pieces']) ? (int)$itm['total_pieces'] : ($isBundle ? ($qty * $ppb) : $qty);
              ?>
                <tr>
                  <td class="text-center small text-muted"><?php echo $sn++; ?></td>
                  <td>
                    <div class="fw-bold text-navy"><?php echo htmlspecialchars($itm['product_name']); ?></div>
                    <div class="small text-secondary mt-0.5">
                      <?php if (!empty($vInfo['page_count'])): ?>
                        <span class="fw-semibold text-navy me-1"><?php echo htmlspecialchars($vInfo['page_count']); ?></span>
                      <?php endif; ?>
                      <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold px-1.5 py-0.5" style="font-size: 0.7rem;">Variety: <?php echo htmlspecialchars($vInfo['variety']); ?></span>
                      <?php if (!empty($vInfo['order_type'])): ?>
                        &bull; <span class="text-muted small"><?php echo htmlspecialchars($vInfo['order_type']); ?></span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td class="text-center"><code class="fw-bold text-navy" style="font-size: 0.825rem;"><?php echo htmlspecialchars($itemCode ?: '-'); ?></code></td>
                  <td class="text-center small text-muted"><?php echo $isBundle ? ($ppb . ' Pcs') : '1 (Loose)'; ?></td>
                  <td class="text-center fw-bold text-navy"><?php echo $isBundle ? $qty : '&mdash;'; ?></td>
                  <td class="text-center fw-bold">
                    <span class="badge <?php echo $isBundle ? 'bg-gold text-navy' : 'bg-light text-dark border'; ?> fs-7">
                      <?php echo $totalPieces; ?> Pcs
                    </span>
                  </td>
                  <td class="text-end"><?php echo format_price($itm['price']); ?></td>
                  <td class="text-end fw-bold text-navy"><?php echo format_price($itm['total_price']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <?php $order_gst = get_order_gst_summary($single_order, $single_items); ?>
              <tr><td colspan="7" class="text-end fw-semibold">Taxable Amount:</td><td class="text-end fw-semibold"><?php echo format_price($order_gst['taxable_amount']); ?></td></tr>
              <?php if ($single_order['discount'] > 0): ?>
                <tr><td colspan="7" class="text-end text-success">Discount:</td><td class="text-end text-success">-<?php echo format_price($single_order['discount']); ?></td></tr>
              <?php endif; ?>
              <?php if (!empty($order_gst['has_multiple_rates'])): ?>
                <?php foreach ($order_gst['rates_breakdown'] as $r): ?>
                  <tr>
                    <td colspan="7" class="text-end text-muted small"><?php echo htmlspecialchars($r['rate_label']); ?>:</td>
                    <td class="text-end text-muted small"><?php echo format_price($r['gst_amount']); ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr><td colspan="7" class="text-end fw-semibold text-navy small">Total GST:</td><td class="text-end fw-semibold text-navy small"><?php echo format_price($order_gst['total_gst']); ?></td></tr>
              <?php else: ?>
                <?php $single_rate = !empty($order_gst['rates_breakdown']) ? reset($order_gst['rates_breakdown']) : null; ?>
                <tr><td colspan="7" class="text-end text-muted small">CGST (<?php echo $single_rate ? $single_rate['cgst_rate'] : 9; ?>%):</td><td class="text-end text-muted small"><?php echo format_price($order_gst['cgst']); ?></td></tr>
                <tr><td colspan="7" class="text-end text-muted small">SGST (<?php echo $single_rate ? $single_rate['sgst_rate'] : 9; ?>%):</td><td class="text-end text-muted small"><?php echo format_price($order_gst['sgst']); ?></td></tr>
                <tr><td colspan="7" class="text-end fw-semibold text-navy small">Total GST (<?php echo $single_rate ? $single_rate['rate'] : 18; ?>%):</td><td class="text-end fw-semibold text-navy small"><?php echo format_price($order_gst['total_gst']); ?></td></tr>
              <?php endif; ?>
              <?php if ($single_order['delivery_fee'] > 0): ?>
                <tr><td colspan="7" class="text-end">Delivery Fee:</td><td class="text-end"><?php echo format_price($single_order['delivery_fee']); ?></td></tr>
              <?php endif; ?>
              <tr class="fs-5 fw-bold"><td colspan="7" class="text-end text-navy">Grand Total:</td><td class="text-end text-gold"><?php echo format_price($single_order['grand_total']); ?></td></tr>
            </tfoot>
          </table>
        </div>

        <!-- 4. Invoice Footer & Sign-off -->
        <?php
        $sigPath = dirname(__DIR__) . '/assets/images/signature.webp';
        if (!file_exists($sigPath) || !is_file($sigPath)) {
            $sigPath = dirname(__DIR__) . '/uploads/signature.webp';
        }
        if (!file_exists($sigPath) || !is_file($sigPath)) {
            $sigPath = dirname(__DIR__) . '/assets/images/signature_jpg.webp';
        }
        if (!file_exists($sigPath) || !is_file($sigPath)) {
            $sigPath = dirname(__DIR__) . '/uploads/signature_jpg.webp';
        }
        if (file_exists($sigPath) && is_file($sigPath)) {
            $sigMime = mime_content_type($sigPath) ?: 'image/png';
            $signatureSrc = 'data:' . $sigMime . ';base64,' . base64_encode(file_get_contents($sigPath));
        } else {
            $signatureSrc = '../assets/images/signature.webp';
        }
        ?>
        <div class="d-flex justify-content-between align-items-end pt-3 border-top">
          <div class="small text-muted">
            <strong>Thank you for choosing Raj Priya Notebooks!</strong><br>
            For any billing queries, email sales@rajpriyanotebooks.com or call +91 98765 43210.
          </div>
          <div class="text-center" style="min-width: 150px;">
            <div class="small fw-bold text-navy mb-1">Prabu Traders</div>
            <div class="d-flex align-items-center justify-content-center my-1" style="min-height: 65px;">
              <img src="<?php echo htmlspecialchars($signatureSrc); ?>" alt="Authorized Signature" style="max-height: 65px; max-width: 140px; width: auto; height: auto; object-fit: contain;">
            </div>
            <div class="small text-muted">(Authorized Signatory)</div>
          </div>
        </div>

        <!-- 4.5 Dispatch & Barcode Sticker Label Creation -->
        <div class="mt-4 pt-4 border-top no-print">
          <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="fw-bold text-navy mb-0">
              <i class="bi bi-printer-fill text-gold me-2"></i> Order Label Create (Dispatch Stickers)
            </h5>
            <span class="badge bg-navy text-gold fw-bold px-2.5 py-1">
              BARCODE LABELS
            </span>
          </div>

          <div class="row g-3 mb-2">
            <!-- Option 1: Bundle Label -->
            <div class="col-md-6">
              <div class="p-3 bg-light rounded-3 border h-100 d-flex flex-column justify-content-between">
                <div>
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-bold text-navy fs-6">
                      <i class="bi bi-box-seam text-gold me-1.5"></i> Bundle Label
                    </span>
                    <span class="badge bg-gold text-navy fw-bold">
                      <?php echo $total_full_bundles; ?> <?php echo $total_full_bundles === 1 ? 'Full Bundle' : 'Full Bundles'; ?>
                      <?php if ($total_partial_bundles > 0): ?>
                        + <?php echo $total_partial_bundles; ?> Part
                      <?php endif; ?>
                    </span>
                  </div>
                  <p class="small text-muted mb-2">Print standard barcode stickers for all bundles ordered.</p>
                  <div class="small mb-3">
                    <?php if (!empty($order_bundle_items)): ?>
                      <?php foreach ($order_bundle_items as $b_itm): ?>
                        <div class="badge bg-white text-dark border me-1 my-0.5 text-wrap text-start">
                          <?php echo htmlspecialchars($b_itm['product_name']); ?> &times; <strong><?php echo $b_itm['bundle_display_qty']; ?> Full Bundle(s)</strong> <span class="text-muted">(<?php echo $b_itm['standard_bundle_size']; ?> pcs/bdl)</span>
                        </div>
                      <?php endforeach; ?>
                    <?php elseif ($total_partial_bundles > 0): ?>
                      <div class="text-primary small">
                        <i class="bi bi-info-circle me-1"></i> 1 Partial bundle ordered (<?php echo $total_remaining_pieces; ?> pieces packed together).
                      </div>
                    <?php else: ?>
                      <span class="text-muted fst-italic">No bundles in this order</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div>
                  <?php if (!empty($order_bundle_items) || $total_partial_bundles > 0): ?>
                    <a href="print_label.php?order_id=<?php echo $single_order['id']; ?>&type=bundle" target="_blank" class="btn text-white w-100 fw-bold btn-sm py-2" style="background-color: #17243A; border-radius: 6px;">
                      <i class="bi bi-printer me-1.5"></i> Create Bundle Label (<?php echo $total_full_bundles > 0 ? ($total_full_bundles . ' Bundle' . ($total_full_bundles === 1 ? '' : 's')) : '1 Partial Bundle'; ?>)
                    </a>
                  <?php else: ?>
                    <button class="btn btn-outline-secondary w-100 btn-sm disabled" disabled>No Bundles to Print</button>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <!-- Option 2: Extra Pieces Label -->
            <div class="col-md-6">
              <div class="p-3 rounded-3 border h-100 d-flex flex-column justify-content-between <?php echo $total_extra_piece_qty > 0 ? 'border-primary-subtle' : 'bg-light'; ?>" style="<?php echo $total_extra_piece_qty > 0 ? 'background-color: #F0F7FF;' : ''; ?>">
                <div>
                  <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="fw-bold text-navy fs-6">
                      <i class="bi bi-tag-fill text-primary me-1.5"></i> Extra Pieces Label
                    </span>
                    <?php if ($total_extra_piece_qty > 0): ?>
                      <span class="badge bg-primary text-white fw-bold fs-7">
                        <?php echo $total_extra_piece_qty; ?> Pieces
                      </span>
                    <?php else: ?>
                      <span class="badge bg-secondary text-white fw-semibold">
                        0 Pieces
                      </span>
                    <?php endif; ?>
                  </div>
                  <p class="small text-muted mb-2">Print dispatch barcode stickers for loose/extra individual pieces.</p>
                  <div class="small mb-3">
                    <?php if (!empty($order_extra_piece_items)): ?>
                      <?php foreach ($order_extra_piece_items as $e_itm): ?>
                        <div class="badge bg-white text-dark border me-1 my-0.5 text-wrap text-start">
                          <?php echo htmlspecialchars($e_itm['product_name']); ?> &times; <strong class="text-primary"><?php echo $e_itm['extra_display_qty']; ?> Loose Pieces</strong>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <span class="text-muted fst-italic">No extra pieces in this order</span>
                    <?php endif; ?>
                  </div>
                </div>
                <div>
                  <?php if ($total_extra_piece_qty > 0): ?>
                    <a href="print_label.php?order_id=<?php echo $single_order['id']; ?>&type=extra" target="_blank" class="btn text-white w-100 fw-bold btn-sm py-2 shadow-sm" style="background-color: #2B6CB0; border-radius: 6px;">
                      <i class="bi bi-tag-fill me-1.5"></i> Create Extra Piece Label (<?php echo $total_extra_piece_qty; ?> Pieces)
                    </a>
                  <?php else: ?>
                    <button class="btn btn-outline-secondary w-100 btn-sm disabled" style="opacity: 0.6; cursor: not-allowed;" disabled title="No extra pieces in this order">
                      <i class="bi bi-dash-circle me-1"></i> Extra Pieces Label (Disabled - 0 Pieces)
                    </button>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- 5. Parcel Packing Verification & QR Code System -->
        <?php 
        $existing_parcels = get_order_parcels($single_order['id']);
        $packing_summary = get_order_item_packing_summary($single_order['id']);
        $total_unpacked = 0;
        foreach ($packing_summary as $ps) {
            $total_unpacked += $ps['remaining_quantity'];
        }

        $expected_parcels_val = (isset($single_order['expected_parcels']) && $single_order['expected_parcels'] !== null && $single_order['expected_parcels'] !== '') 
            ? (int)$single_order['expected_parcels'] 
            : (!empty($order_parcel_count) ? $order_parcel_count : 1);

        $actual_parcels_val = (isset($single_order['actual_parcels']) && $single_order['actual_parcels'] !== null && $single_order['actual_parcels'] !== '') 
            ? (int)$single_order['actual_parcels'] 
            : (!empty($existing_parcels) ? count($existing_parcels) : null);

        $total_parcels_for_numbering = !empty($actual_parcels_val) 
            ? $actual_parcels_val 
            : (!empty($existing_parcels) ? count($existing_parcels) : $expected_parcels_val);
        ?>
        <div class="mt-4 pt-4 border-top no-print">
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
            <div>
              <h5 class="fw-bold text-navy mb-0">
                <i class="bi bi-box-seam text-gold me-2"></i> Parcel Packing Verification &amp; Dispatch Management
              </h5>
              <div class="small text-muted mt-0.5">Verify ordered pieces, full bundles, partial bundles, and actual physical parcels.</div>
            </div>
            <div class="d-flex align-items-center gap-2">
              <a href="print_parcel_sticker.php?order_id=<?php echo $single_order['id']; ?>" target="_blank" class="btn btn-warning btn-sm fw-bold shadow-sm">
                <i class="bi bi-printer-fill me-1"></i> Print Parcel Stickers (All <?php echo $total_parcels_for_numbering; ?> <?php echo $total_parcels_for_numbering === 1 ? 'Parcel' : 'Parcels'; ?>)
              </a>
              <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold py-1.5 px-2.5">
                <?php echo count($existing_parcels); ?> Physical Box(es) Logged
              </span>
            </div>
          </div>

          <!-- 3.1 Six Distinct Key Metric Cards for Packing Verification (Separated Counts) -->
          <div class="row g-2 mb-3">
            <div class="col-6 col-lg-2 col-md-4">
              <div class="p-2.5 bg-white rounded-3 border text-center shadow-xs h-100">
                <div class="small text-uppercase fw-bold text-muted letter-spacing-1 mb-1" style="font-size: 0.68rem;">Ordered Pieces</div>
                <div class="fs-4 fw-bold text-gold"><?php echo number_format($total_pieces_to_dispatch); ?></div>
                <div class="small text-secondary" style="font-size: 0.72rem;">Customer Requested</div>
              </div>
            </div>

            <div class="col-6 col-lg-2 col-md-4">
              <div class="p-2.5 bg-white rounded-3 border text-center shadow-xs h-100">
                <div class="small text-uppercase fw-bold text-muted letter-spacing-1 mb-1" style="font-size: 0.68rem;">Full Bundles</div>
                <div class="fs-4 fw-bold text-navy"><?php echo $total_full_bundles; ?></div>
                <div class="small text-secondary" style="font-size: 0.72rem;">Complete Std Packs</div>
              </div>
            </div>

            <div class="col-6 col-lg-2 col-md-4">
              <div class="p-2.5 bg-white rounded-3 border text-center shadow-xs h-100">
                <div class="small text-uppercase fw-bold text-muted letter-spacing-1 mb-1" style="font-size: 0.68rem;">Partial Bundle</div>
                <div class="fs-4 fw-bold text-primary"><?php echo $total_remaining_pieces > 0 ? ($total_remaining_pieces . ' pcs') : '0'; ?></div>
                <div class="small text-secondary" style="font-size: 0.72rem;"><?php echo $total_partial_bundles > 0 ? '1 Partial Pack' : 'No Loose Pieces'; ?></div>
              </div>
            </div>

            <div class="col-6 col-lg-2 col-md-4">
              <div class="p-2.5 bg-white rounded-3 border text-center shadow-xs h-100">
                <div class="small text-uppercase fw-bold text-muted letter-spacing-1 mb-1" style="font-size: 0.68rem;">Packed Units</div>
                <div class="fs-4 fw-bold text-dark"><?php echo $total_packed_units; ?></div>
                <div class="small text-secondary" style="font-size: 0.72rem;">Full + Partial Units</div>
              </div>
            </div>

            <div class="col-6 col-lg-2 col-md-4">
              <div class="p-2.5 bg-white rounded-3 border text-center shadow-xs h-100">
                <div class="small text-uppercase fw-bold text-muted letter-spacing-1 mb-1" style="font-size: 0.68rem;">Expected Parcels</div>
                <div class="fs-4 fw-bold text-dark"><?php echo $expected_parcels_val; ?></div>
                <div class="small text-secondary" style="font-size: 0.72rem;">Expected Boxes</div>
              </div>
            </div>

            <div class="col-6 col-lg-2 col-md-4">
              <div class="p-2.5 bg-white rounded-3 border text-center shadow-xs h-100">
                <div class="small text-uppercase fw-bold text-muted letter-spacing-1 mb-1" style="font-size: 0.68rem;">Actual Parcels</div>
                <div class="fs-4 fw-bold <?php echo ($actual_parcels_val !== null && $actual_parcels_val != $expected_parcels_val) ? 'text-danger' : 'text-success'; ?>">
                  <?php echo $actual_parcels_val !== null ? $actual_parcels_val : '<span class="text-muted small fst-italic" style="font-size:0.8rem;">Pending</span>'; ?>
                </div>
                <div class="small text-secondary" style="font-size: 0.72rem;">Physical Boxes</div>
              </div>
            </div>
          </div>

          <!-- 3.2 Verification Warning / Success Banner -->
          <?php if ($actual_parcels_val !== null && $expected_parcels_val !== null): ?>
            <?php if ($actual_parcels_val != $expected_parcels_val): ?>
              <div class="alert alert-warning border-warning d-flex align-items-center gap-3 p-3 rounded-3 shadow-xs mb-3" role="alert">
                <i class="bi bi-exclamation-triangle-fill text-warning fs-3 flex-shrink-0"></i>
                <div class="small text-dark">
                  <strong class="d-block text-dark fs-6 mb-0.5">⚠️ Parcel Packing Mismatch Warning:</strong>
                  Actual parcel count (<strong><?php echo $actual_parcels_val; ?></strong>) does <strong>NOT</strong> match expected parcel count (<strong><?php echo $expected_parcels_val; ?></strong>). 
                  Please verify physical packing arrangement before dispatch. 
                  <span class="text-muted">Note: Packed units (<?php echo $total_packed_units; ?>) and parcel count (<?php echo $actual_parcels_val; ?>) are different based on packaging arrangement. Multiple bundles can be placed inside one parcel.</span>
                </div>
              </div>
            <?php else: ?>
              <div class="alert alert-success border-success d-flex align-items-center gap-3 p-3 rounded-3 shadow-xs mb-3" role="alert">
                <i class="bi bi-check-circle-fill text-success fs-3 flex-shrink-0"></i>
                <div class="small text-dark">
                  <strong class="d-block text-success fs-6 mb-0.5">✅ Parcel Count Verified:</strong>
                  Actual parcel count (<strong><?php echo $actual_parcels_val; ?></strong>) matches expected parcel count (<strong><?php echo $expected_parcels_val; ?></strong>). All <?php echo number_format($total_pieces_to_dispatch); ?> notebook pieces (<?php echo $total_packed_units; ?> packed units) are accounted for dispatch.
                </div>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <div class="alert alert-info border-info d-flex align-items-center gap-2 p-2.5 rounded-3 mb-3 small">
              <i class="bi bi-info-circle-fill text-info fs-5 flex-shrink-0"></i>
              <span>Packing staff: Enter the actual parcel count packed below to complete dispatch verification.</span>
            </div>
          <?php endif; ?>

          <!-- 3.3 Staff Parcel Verification Form -->
          <div class="card p-3 mb-4 bg-light border rounded-3">
            <form action="orders.php?view=<?php echo $single_order['id']; ?>" method="POST" class="row g-2 align-items-end">
              <input type="hidden" name="action" value="save_parcel_verification">
              <input type="hidden" name="order_id" value="<?php echo $single_order['id']; ?>">

              <div class="col-md-4">
                <label class="form-label small fw-bold text-navy mb-1">Expected Parcel Count</label>
                <input type="number" name="expected_parcels" min="1" max="999" class="form-control form-control-sm bg-white" value="<?php echo htmlspecialchars($expected_parcels_val ?? ''); ?>" placeholder="e.g. 2">
              </div>

              <div class="col-md-4">
                <label class="form-label small fw-bold text-navy mb-1">Actual Parcels Packed (Staff Input)</label>
                <input type="number" name="actual_parcels" min="1" max="999" class="form-control form-control-sm bg-white fw-bold" value="<?php echo htmlspecialchars($actual_parcels_val ?? (count($existing_parcels) ?: '')); ?>" placeholder="e.g. 4">
              </div>

              <div class="col-md-4">
                <button type="submit" class="btn btn-dark btn-sm w-100 fw-bold py-1.5" style="background-color: #17243A;">
                  <i class="bi bi-shield-check text-gold me-1"></i> Save Parcel Verification
                </button>
              </div>

              <div class="col-12 mt-1">
                <small class="text-muted" style="font-size: 0.76rem;">
                  <i class="bi bi-info-circle text-primary me-1"></i>
                  <strong>Important:</strong> Bundle count and parcel count are different. Never assume that 2 bundles automatically mean 2 parcels. Calculate or enter parcel count based on the actual packing arrangement.
                </small>
              </div>
            </form>
          </div>

          <!-- Existing Parcels List with Parcel 1 of N Numbering -->
          <?php if (!empty($existing_parcels)): ?>
            <div class="row g-3 mb-4">
              <?php foreach ($existing_parcels as $idx => $pcl): 
                $tracking_pcl_link = get_parcel_tracking_url($pcl['parcel_number']);
                $qr_thumb = generate_qr_code_image_url($tracking_pcl_link, 150);
                $parcel_current_number = $idx + 1;
                $parcel_total_number = $total_parcels_for_numbering;
              ?>
                <div class="col-12">
                  <div class="p-3 bg-light rounded-3 border d-flex align-items-center justify-content-between flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-3">
                      <a href="<?php echo htmlspecialchars($tracking_pcl_link); ?>" target="_blank" title="Click to Test Parcel QR Scan">
                        <img src="<?php echo $qr_thumb; ?>" alt="QR Code" style="width: 75px; height: 75px; border-radius: 6px;" class="border p-1 bg-white">
                      </a>
                      <div>
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                          <strong class="text-navy fs-6"><?php echo htmlspecialchars($pcl['parcel_number']); ?></strong>
                          <span class="badge bg-navy text-gold fw-bold px-2 py-1" style="font-size: 0.75rem; letter-spacing: 0.5px;">
                            Parcel <?php echo $parcel_current_number; ?> of <?php echo $parcel_total_number; ?>
                          </span>
                        </div>
                        <div class="small text-muted mb-1">
                          Created: <?php echo format_datetime($pcl['created_at']); ?>
                          <?php if (!empty($pcl['parcel_weight']) && $pcl['parcel_weight'] > 0): ?> &bull; Weight: <strong><?php echo htmlspecialchars($pcl['parcel_weight']); ?> kg</strong><?php endif; ?>
                        </div>
                        <div class="small text-dark">
                          <strong>Packed Items:</strong>
                          <?php foreach ($pcl['items'] as $p_item): ?>
                            <span class="badge bg-white text-dark border me-1 my-0.5">
                              <?php echo htmlspecialchars($p_item['product_name']); ?> (<?php echo htmlspecialchars($p_item['ruling_type']); ?>) &times; <strong><?php echo $p_item['quantity']; ?></strong>
                            </span>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                      <a href="<?php echo $qr_thumb; ?>" download="QR-<?php echo htmlspecialchars($pcl['parcel_number']); ?>.png" target="_blank" class="btn btn-outline-secondary btn-sm fw-bold" title="Download Parcel QR Code Image">
                        <i class="bi bi-download"></i> QR
                      </a>
                      <a href="<?php echo htmlspecialchars($tracking_pcl_link); ?>" target="_blank" class="btn btn-outline-dark btn-sm fw-bold" title="Test Live Tracking Page">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Track
                      </a>
                      <a href="print_parcel_sticker.php?id=<?php echo $pcl['id']; ?>" target="_blank" class="btn btn-gold btn-sm fw-bold">
                        <i class="bi bi-printer-fill me-1"></i> Print Parcel Sticker (Parcel <?php echo $parcel_current_number; ?> of <?php echo $parcel_total_number; ?>)
                      </a>
                      <form action="orders.php?view=<?php echo $single_order['id']; ?>" method="POST" onsubmit="return confirm('Are you sure you want to remove this parcel?');" style="display:inline;">
                        <input type="hidden" name="action" value="delete_parcel">
                        <input type="hidden" name="parcel_id" value="<?php echo $pcl['id']; ?>">
                        <input type="hidden" name="order_id" value="<?php echo $single_order['id']; ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete Parcel">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <!-- Pack New Parcel Card -->
          <div class="p-3.5 bg-white rounded-3 border shadow-sm">
            <h6 class="fw-bold text-navy mb-2"><i class="bi bi-plus-circle-fill text-gold me-1.5"></i> Pack Items into a New Physical Box / Parcel</h6>
            <p class="small text-muted mb-3">Select quantities of items to assign to this parcel box. A unique Parcel ID & QR sticker will be generated.</p>

            <form action="orders.php?view=<?php echo $single_order['id']; ?>" method="POST">
              <input type="hidden" name="action" value="create_parcel">
              <input type="hidden" name="order_id" value="<?php echo $single_order['id']; ?>">

              <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered align-middle mb-0">
                  <thead class="table-light small">
                    <tr>
                      <th>Product & Variety</th>
                      <th class="text-center" style="width: 105px;">Std Bundle</th>
                      <th class="text-center" style="width: 105px;">Ordered Qty</th>
                      <th class="text-center">Pack Breakdown</th>
                      <th class="text-center" style="width: 95px;">Packed</th>
                      <th class="text-center" style="width: 95px;">Remaining</th>
                      <th class="text-center" style="width: 130px;">Qty for THIS Box</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($packing_summary as $ps_item): 
                      $is_piece_order = (stripos($ps_item['order_type'] ?? '', 'Bundle') === false && stripos($ps_item['ruling_type'] ?? '', 'Bundle') === false);
                      $unit_str = $is_piece_order ? 'pcs' : 'bdl';
                    ?>
                      <tr>
                        <td>
                          <strong class="text-navy d-block"><?php echo htmlspecialchars($ps_item['product_name']); ?></strong>
                          <small class="text-muted"><?php echo htmlspecialchars($ps_item['ruling_type']); ?></small>
                        </td>
                        <td class="text-center small fw-semibold text-secondary">
                          <?php echo $ps_item['standard_bundle_size']; ?> pcs/bdl
                        </td>
                        <td class="text-center fw-bold text-navy">
                          <?php echo number_format($ps_item['ordered_pieces']); ?> <span class="small text-muted fw-normal">pcs</span>
                        </td>
                        <td class="text-center small">
                          <?php if ($ps_item['full_bundles'] > 0 && $ps_item['remaining_pieces'] > 0): ?>
                            <span class="badge bg-navy text-white"><?php echo $ps_item['full_bundles']; ?> Full</span> + 
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?php echo $ps_item['remaining_pieces']; ?> Loose</span>
                          <?php elseif ($ps_item['full_bundles'] > 0): ?>
                            <span class="badge bg-navy text-white"><?php echo $ps_item['full_bundles']; ?> Full Bundle<?php echo $ps_item['full_bundles'] > 1 ? 's' : ''; ?></span>
                          <?php elseif ($ps_item['partial_bundles'] > 0): ?>
                            <span class="badge bg-warning-subtle text-dark border border-warning-subtle">1 Partial Bundle (<?php echo $ps_item['remaining_pieces']; ?> pcs)</span>
                          <?php else: ?>
                            <span class="text-muted">-</span>
                          <?php endif; ?>
                        </td>
                        <td class="text-center text-success fw-bold">
                          <?php echo $ps_item['packed_quantity']; ?> <span class="small fw-normal"><?php echo $unit_str; ?></span>
                        </td>
                        <td class="text-center fw-bold <?php echo $ps_item['remaining_quantity'] > 0 ? 'text-danger' : 'text-muted'; ?>">
                          <?php echo $ps_item['remaining_quantity']; ?> <span class="small fw-normal"><?php echo $unit_str; ?></span>
                        </td>
                        <td class="text-center">
                          <input type="number" 
                                 name="items[<?php echo $ps_item['id']; ?>]" 
                                 class="form-control form-control-sm text-center fw-bold" 
                                 min="0" 
                                 max="<?php echo $ps_item['remaining_quantity']; ?>" 
                                 value="<?php echo $ps_item['remaining_quantity']; ?>"
                                 <?php echo $ps_item['remaining_quantity'] <= 0 ? 'disabled' : ''; ?>>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <div class="row g-2 mb-3">
                <div class="col-md-6">
                  <label class="form-label small fw-semibold text-secondary mb-1">Parcel Weight (kg)</label>
                  <input type="number" step="0.01" name="parcel_weight" class="form-control form-control-sm" placeholder="e.g. 12.50">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold text-secondary mb-1">Box Dimensions (Optional)</label>
                  <input type="text" name="box_dimensions" class="form-control form-control-sm" placeholder="e.g. 40x30x20 cm">
                </div>
                <div class="col-12">
                  <label class="form-label small fw-semibold text-secondary mb-1">Packing Remarks (Optional)</label>
                  <input type="text" name="remarks" class="form-control form-control-sm" placeholder="e.g. Box 1 contains 50 Bundles of Unruled notebooks">
                </div>
              </div>

              <button type="submit" class="btn text-white fw-bold w-100" style="background-color: #17243A; border-radius: 6px;">
                <i class="bi bi-qr-code-scan text-gold me-1.5"></i> Pack Selected Items & Generate Parcel QR Code
              </button>
            </form>
          </div>
        </div>

      </div>
    </div>

    <!-- Right Column: Update Order Status & Transport Details Panels (Screen Only) -->
    <div class="col-lg-4">
      <!-- Admin Transport / Parcel Details Panel -->
      <div class="bg-white p-4 rounded-3 border mb-4 no-print shadow-sm">
        <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
          <h5 class="fw-bold text-navy mb-0"><i class="bi bi-truck text-gold me-1.5"></i> Transport Details</h5>
          <?php if (!empty($single_order['transport_name']) || !empty($single_order['transport_lr_number'])): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold">Saved</span>
          <?php endif; ?>
        </div>

        <form action="orders.php?view=<?php echo $single_order['id']; ?>" method="POST">
          <input type="hidden" name="action" value="update_transport">
          <input type="hidden" name="order_id" value="<?php echo $single_order['id']; ?>">

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Sending From Location</label>
              <input type="text" name="transport_from" class="form-control form-control-sm" placeholder="e.g. Madurai" value="<?php echo htmlspecialchars($single_order['transport_from'] ?? ''); ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Receiving To Location</label>
              <input type="text" name="transport_to" class="form-control form-control-sm" placeholder="e.g. Tirunelveli" value="<?php echo htmlspecialchars($single_order['transport_to'] ?? ''); ?>">
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label small fw-semibold text-secondary mb-1">Transport Service Name</label>
            <input type="text" name="transport_name" class="form-control form-control-sm" placeholder="e.g. XYZ Transport" value="<?php echo htmlspecialchars($single_order['transport_name'] ?? ''); ?>">
          </div>

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Transport Branch</label>
              <input type="text" name="transport_branch" class="form-control form-control-sm" placeholder="e.g. Tirunelveli Branch" value="<?php echo htmlspecialchars($single_order['transport_branch'] ?? ''); ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Transport Contact No</label>
              <input type="text" name="transport_contact" class="form-control form-control-sm" placeholder="e.g. +91 98765 43210" value="<?php echo htmlspecialchars($single_order['transport_contact'] ?? ''); ?>">
            </div>
          </div>

          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small fw-semibold text-secondary mb-1">LR / Parcel No</label>
              <input type="text" name="transport_lr_number" class="form-control form-control-sm" placeholder="e.g. LR123456" value="<?php echo htmlspecialchars($single_order['transport_lr_number'] ?? ''); ?>">
            </div>
            <div class="col-6">
              <label class="form-label small fw-semibold text-secondary mb-1">Vehicle No (Optional)</label>
              <input type="text" name="transport_vehicle_number" class="form-control form-control-sm" placeholder="e.g. TN58AB1234" value="<?php echo htmlspecialchars($single_order['transport_vehicle_number'] ?? ''); ?>">
            </div>
          </div>

          <div class="mb-2">
            <label class="form-label small fw-semibold text-secondary mb-1">Dispatch Date</label>
            <input type="date" name="transport_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($single_order['dispatch_date'] ?: ($single_order['transport_date'] ?? '')); ?>">
          </div>

          <div class="mb-3">
            <label class="form-label small fw-semibold text-secondary mb-1">Transport Remarks (Optional)</label>
            <textarea name="transport_remarks" class="form-control form-control-sm" rows="2" placeholder="e.g. Handle with care"><?php echo htmlspecialchars($single_order['transport_remarks'] ?? ''); ?></textarea>
          </div>

          <button type="submit" class="btn text-white w-100 btn-sm fw-bold" style="background-color: #17243A; border-radius: 6px;">
            <i class="bi bi-floppy-fill text-gold me-1"></i> Save Transport Details
          </button>
        </form>
      </div>

      <!-- Order Quotation Management Card -->
      <?php $ord_quotation = get_order_quotation($single_order['id']); ?>
      <div class="bg-white p-4 rounded-3 border mb-4 no-print shadow-sm">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="fw-bold text-navy mb-0">Order Quotation</h5>
          <?php if ($ord_quotation): ?>
            <?php echo render_quotation_status_badge($ord_quotation['status']); ?>
          <?php endif; ?>
        </div>
        
        <?php if ($ord_quotation): ?>
          <div class="small mb-3">
            <div class="d-flex justify-content-between py-1 border-bottom">
              <span class="text-muted">Quotation #:</span>
              <span class="fw-bold text-navy"><?php echo htmlspecialchars($ord_quotation['quotation_number']); ?></span>
            </div>
            <div class="d-flex justify-content-between py-1 border-bottom">
              <span class="text-muted">Quotation Total:</span>
              <span class="fw-bold text-navy"><?php echo format_price($ord_quotation['grand_total']); ?></span>
            </div>
            <?php if (!empty($ord_quotation['sent_at'])): ?>
              <div class="d-flex justify-content-between py-1 border-bottom">
                <span class="text-muted">Sent Date:</span>
                <span><?php echo format_datetime($ord_quotation['sent_at']); ?></span>
              </div>
            <?php endif; ?>
            <?php if (!empty($ord_quotation['customer_response_at'])): ?>
              <div class="d-flex justify-content-between py-1 border-bottom">
                <span class="text-muted">Customer Response:</span>
                <span class="fw-bold <?php echo $ord_quotation['status'] === 'Accepted' ? 'text-success' : 'text-danger'; ?>">
                  <?php echo format_datetime($ord_quotation['customer_response_at']); ?>
                </span>
              </div>
            <?php endif; ?>
            <?php if (!empty($ord_quotation['customer_decline_reason'])): ?>
              <div class="mt-2 p-2 bg-light border border-danger-subtle rounded small text-danger">
                <strong>Decline Reason:</strong> "<?php echo htmlspecialchars($ord_quotation['customer_decline_reason']); ?>"
              </div>
            <?php endif; ?>
          </div>
          <a href="quotation.php?order_id=<?php echo $single_order['id']; ?>" class="btn btn-gold w-100 fw-bold btn-sm py-2">
            <i class="bi bi-pencil-square me-1"></i> View / Edit Quotation
          </a>
        <?php else: ?>
          <p class="small text-muted mb-3">No quotation has been generated for this order yet. Send a customized wholesale quotation with bundle discounts and GST breakdown.</p>
          <a href="quotation.php?order_id=<?php echo $single_order['id']; ?>" class="btn btn-gold w-100 fw-bold btn-sm py-2">
            <i class="bi bi-file-earmark-text me-1"></i> Create & Send Quotation
          </a>
        <?php endif; ?>
      </div>

      <!-- WhatsApp Invoice Delivery Panel -->
      <?php 
      $wa_delivery_log = get_order_whatsapp_delivery_status($single_order['id']);
      $wa_status = $wa_delivery_log ? strtolower($wa_delivery_log['status']) : 'not_sent';
      $wa_pdf_path = dirname(__DIR__) . '/uploads/invoices/Invoice-' . $single_order['order_number'] . '.pdf';
      $wa_has_pdf = file_exists($wa_pdf_path);
      $wa_recipient_phone = $wa_delivery_log['recipient_phone'] ?? (!empty($single_order['customer_phone']) ? format_phone_number_display($single_order['customer_phone']) : '');
      ?>
      <div class="bg-white p-4 rounded-3 border mb-4 no-print shadow-sm">
        <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
          <h5 class="fw-bold text-navy mb-0">
            <i class="bi bi-whatsapp text-success me-1.5"></i> WhatsApp Delivery
          </h5>
          <?php if ($wa_status === 'delivered' || $wa_status === 'read'): ?>
            <span class="badge bg-success fw-bold px-2 py-1"><i class="bi bi-check-all me-1"></i> Delivered</span>
          <?php elseif ($wa_status === 'sent' || $wa_status === 'accepted'): ?>
            <span class="badge bg-primary fw-bold px-2 py-1"><i class="bi bi-check2 me-1"></i> Sent</span>
          <?php elseif ($wa_status === 'failed'): ?>
            <span class="badge bg-danger fw-bold px-2 py-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Failed</span>
          <?php elseif ($wa_status === 'pending'): ?>
            <span class="badge bg-warning text-dark fw-bold px-2 py-1"><i class="bi bi-hourglass-split me-1"></i> Pending</span>
          <?php else: ?>
            <span class="badge bg-secondary fw-bold px-2 py-1"><i class="bi bi-dash-circle me-1"></i> Not Sent</span>
          <?php endif; ?>
        </div>

        <div class="small mb-3">
          <div class="d-flex justify-content-between py-1 border-bottom">
            <span class="text-muted">Customer Mobile:</span>
            <span class="fw-bold text-navy"><?php echo htmlspecialchars($single_order['customer_phone'] ?: 'None'); ?></span>
          </div>
          <?php if ($wa_delivery_log): ?>
            <div class="d-flex justify-content-between py-1 border-bottom">
              <span class="text-muted">Target WhatsApp:</span>
              <span class="font-monospace fw-semibold"><?php echo htmlspecialchars($wa_recipient_phone); ?></span>
            </div>
            <div class="d-flex justify-content-between py-1 border-bottom">
              <span class="text-muted">Last Attempt:</span>
              <span><?php echo format_datetime($wa_delivery_log['attempt_time'] ?? $wa_delivery_log['created_at']); ?></span>
            </div>
            <?php if (!empty($wa_delivery_log['message_id'])): ?>
              <div class="d-flex justify-content-between py-1 border-bottom">
                <span class="text-muted">Message ID:</span>
                <span class="font-monospace text-truncate" style="max-width: 170px;" title="<?php echo htmlspecialchars($wa_delivery_log['message_id']); ?>"><?php echo htmlspecialchars($wa_delivery_log['message_id']); ?></span>
              </div>
            <?php endif; ?>
            <?php if ($wa_status === 'failed' && !empty($wa_delivery_log['error_message'])): ?>
              <div class="mt-2 p-2 bg-danger-subtle text-danger border border-danger-subtle rounded small">
                <strong>Error:</strong> <?php echo htmlspecialchars($wa_delivery_log['error_message']); ?>
              </div>
            <?php endif; ?>
          <?php else: ?>
            <p class="text-muted small mt-2 mb-0">Official PDF invoice can be automatically sent or retried to customer's WhatsApp.</p>
          <?php endif; ?>
        </div>

        <?php if ($wa_has_pdf): ?>
          <div class="mb-3">
            <a href="../uploads/invoices/Invoice-<?php echo htmlspecialchars($single_order['order_number']); ?>.pdf" target="_blank" class="btn btn-outline-secondary btn-sm w-100 fw-bold">
              <i class="bi bi-file-earmark-pdf text-danger me-1"></i> View Generated Invoice PDF
            </a>
          </div>
        <?php endif; ?>

        <form action="orders.php?view=<?php echo $single_order['id']; ?>" method="POST">
          <input type="hidden" name="action" value="send_whatsapp_invoice">
          <input type="hidden" name="order_id" value="<?php echo $single_order['id']; ?>">
          
          <button type="submit" class="btn <?php echo ($wa_status === 'failed') ? 'btn-danger' : (($wa_status === 'delivered' || $wa_status === 'read' || $wa_status === 'sent') ? 'btn-outline-success' : 'btn-success'); ?> w-100 fw-bold py-2 shadow-sm" onclick="return confirm('<?php echo ($wa_delivery_log ? 'Resend / Retry sending this Tax Invoice PDF to WhatsApp?' : 'Send Tax Invoice PDF to customer via WhatsApp?'); ?>');">
            <i class="bi bi-whatsapp me-1.5"></i> 
            <?php 
            if ($wa_status === 'failed') {
                echo 'Retry WhatsApp Delivery';
            } elseif ($wa_status === 'delivered' || $wa_status === 'read' || $wa_status === 'sent') {
                echo 'Resend via WhatsApp';
            } else {
                echo 'Send Invoice via WhatsApp';
            }
            ?>
          </button>
        </form>
      </div>

      <div class="bg-white p-4 rounded-3 border mb-4 no-print shadow-sm">
        <h5 class="fw-bold text-navy mb-3">Update Order Status</h5>
        <form action="orders.php?view=<?php echo $single_order['id']; ?>" method="POST">
          <input type="hidden" name="action" value="update_status">
          <input type="hidden" name="order_id" value="<?php echo $single_order['id']; ?>">

          <div class="mb-3">
            <label class="form-label small fw-semibold">Order Fulfillment Status</label>
            <select name="order_status" class="form-select fw-semibold text-navy">
              <?php foreach (['Pending', 'Confirmed', 'Processing', 'Packed', 'Shipped', 'Delivered', 'Cancelled'] as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo ($single_order['order_status'] === $st)?'selected':''; ?>><?php echo $st; ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-4">
            <label class="form-label small fw-semibold">Payment Status</label>
            <select name="payment_status" class="form-select fw-semibold">
              <option value="Pending" <?php echo ($single_order['payment_status'] === 'Pending')?'selected':''; ?>>Pending</option>
              <option value="Paid" <?php echo ($single_order['payment_status'] === 'Paid')?'selected':''; ?>>Paid</option>
              <option value="Failed" <?php echo ($single_order['payment_status'] === 'Failed')?'selected':''; ?>>Failed</option>
            </select>
          </div>

          <button type="submit" class="btn btn-gold w-100 fw-bold">Update Order Status</button>
        </form>
      </div>

      <!-- Customer Shipping Address Summary Card (Screen Only) -->
      <div class="bg-white p-4 rounded-3 border no-print shadow-sm">
        <h5 class="fw-bold text-navy mb-2">Customer Shipping Address</h5>
        <div class="small">
          <div class="d-flex align-items-center justify-content-between mb-1">
            <strong class="text-navy fs-6"><?php echo htmlspecialchars($single_order['customer_name']); ?></strong>
            <?php if (!empty($single_order['customer_code'])): ?>
              <a href="customers.php?view=<?php echo urlencode($single_order['customer_code']); ?>" class="badge bg-navy text-gold text-decoration-none fw-bold px-2 py-0.5" title="View Customer Profile">
                <?php echo htmlspecialchars($single_order['customer_code']); ?>
              </a>
            <?php endif; ?>
          </div>
          <div>Phone: <?php echo htmlspecialchars($single_order['customer_phone']); ?></div>
          <div>
            Email: <?php echo !empty($single_order['customer_email']) ? htmlspecialchars($single_order['customer_email']) : '<span class="text-muted fst-italic">Not Provided</span>'; ?>
          </div>
          <div>
            GST Registration: 
            <?php 
            $ord_is_reg = (!empty($single_order['gst_status']) && strcasecmp($single_order['gst_status'], 'Registered') === 0) || !empty($single_order['gst_number']);
            if ($ord_is_reg && !empty($single_order['gst_number'])): ?>
              <span class="badge bg-success px-1.5 py-0.5">Registered</span>
              <span class="badge bg-gold text-navy px-1.5 py-0.5"><?php echo htmlspecialchars($single_order['gst_number']); ?></span>
            <?php else: ?>
              <span class="text-muted fst-italic">Not Registered</span>
            <?php endif; ?>
          </div>
          <hr class="my-2">
          <div class="fw-semibold text-navy"><?php echo htmlspecialchars($single_order['shipping_address']); ?></div>
          <?php if (!empty($single_order['address_line2'])): ?>
            <div><?php echo htmlspecialchars($single_order['address_line2']); ?></div>
          <?php endif; ?>
          <?php if (!empty($single_order['area'])): ?>
            <div>Area: <?php echo htmlspecialchars($single_order['area']); ?></div>
          <?php endif; ?>
          <div class="fw-bold text-navy">
            <?php echo htmlspecialchars($single_order['city']); ?>
            <?php if (!empty($single_order['district']) && strcasecmp($single_order['district'], $single_order['city']) !== 0): ?>
              , <?php echo htmlspecialchars($single_order['district']); ?>
            <?php endif; ?>
            , <?php echo htmlspecialchars($single_order['state'] . ' - ' . $single_order['pincode']); ?>
          </div>
          <?php if (!empty($single_order['landmark'])): ?><div class="fst-italic text-muted"><small>Landmark: <?php echo htmlspecialchars($single_order['landmark']); ?></small></div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

<?php else: ?>

  <!-- Orders List View -->
  <div class="d-flex justify-content-between align-items-center mb-4 admin-page-header">
    <div>
      <h3 class="fw-bold text-navy mb-0">Order Management</h3>
      <p class="text-secondary small mb-0">View, search, update fulfillment status & export Tally order invoices.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <a href="orders_xml.php?<?php echo http_build_query($_GET); ?>" class="btn btn-outline-primary btn-sm px-3 fw-bold" style="border-radius: 6px;" title="Download XML Packing Sheet">
        <i class="bi bi-file-earmark-code me-1.5"></i> Download XML
      </a>
      <a href="orders_excel.php?export_all=1" class="btn btn-outline-secondary btn-sm px-3 fw-semibold">
        <i class="bi bi-download me-1"></i> Download All Excel
      </a>
      <a href="orders_excel.php?<?php echo http_build_query($_GET); ?>" class="btn text-white btn-sm px-3 fw-bold" style="background-color: #17243A; border-radius: 6px;">
        <i class="bi bi-file-earmark-excel text-gold me-1.5"></i> Download Excel
      </a>
    </div>
  </div>

  <!-- Filter & Search Bar -->
  <div class="bg-white p-3 rounded-3 border mb-4 shadow-xs">
    <form action="orders.php" method="GET" class="row g-2 align-items-center" id="orderSearchForm">
      <div class="col-lg-4 col-md-12">
        <div class="input-group">
          <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
          <input type="text" name="search" id="orderSearchInput" class="form-control border-start-0 ps-0" 
                 placeholder="Search Order # / Bill, Customer, Phone, Product Name, Code, Parcel #..." 
                 value="<?php echo htmlspecialchars($search); ?>">
          <?php if ($search !== ''): ?>
            <button type="button" class="btn btn-outline-secondary border-start-0" id="orderClearInputBtn" title="Clear keyword">
              <i class="bi bi-x-lg"></i>
            </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-lg-2 col-md-3 col-sm-6">
        <select name="status" class="form-select form-select-sm py-2" onchange="this.form.submit()">
          <option value="">All Statuses</option>
          <?php foreach (['Pending', 'Confirmed', 'Processing', 'Packed', 'Shipped', 'Delivered', 'Cancelled'] as $st): ?>
            <option value="<?php echo $st; ?>" <?php echo ($status_filter===$st)?'selected':''; ?>><?php echo $st; ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-lg-2 col-md-3 col-sm-6">
        <input type="date" name="date_from" class="form-control form-control-sm py-2" value="<?php echo htmlspecialchars($date_from); ?>" title="Date From" placeholder="From Date">
      </div>
      <div class="col-lg-2 col-md-3 col-sm-6">
        <input type="date" name="date_to" class="form-control form-control-sm py-2" value="<?php echo htmlspecialchars($date_to); ?>" title="Date To" placeholder="To Date">
      </div>
      <div class="col-lg-2 col-md-3 col-sm-6 d-flex gap-2">
        <button type="submit" class="btn btn-gold btn-sm w-100 fw-bold py-2">
          <i class="bi bi-funnel-fill me-1"></i> Filter
        </button>
        <?php if ($status_filter || $search || $date_from || $date_to): ?>
          <a href="orders.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center" title="Reset all filters" style="min-width: 38px;">
            <i class="bi bi-arrow-counterclockwise"></i>
          </a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="bg-white p-4 rounded-3 border shadow-xs">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div class="fw-bold text-navy d-flex align-items-center">
        <i class="bi bi-box-seam text-gold me-2 fs-5"></i>
        <span>Orders & Invoices</span>
        <span class="badge bg-light text-navy border ms-2"><?php echo number_format($total_orders_count); ?> orders found</span>
        <?php if ($search !== ''): ?>
          <span class="badge bg-gold-subtle text-navy border border-warning-subtle ms-2">Search: "<?php echo htmlspecialchars($search); ?>"</span>
        <?php endif; ?>
        <?php if ($status_filter !== ''): ?>
          <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-2">Status: <?php echo htmlspecialchars($status_filter); ?></span>
        <?php endif; ?>
      </div>
      <div class="small text-muted fw-semibold">
        Showing page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo count($orders); ?> displayed)
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead>
          <tr class="table-light">
            <th>Order #</th>
            <th>Date</th>
            <th>Customer</th>
            <th>Payment</th>
            <th>Grand Total</th>
            <th>Order Status</th>
            <th>Quotation</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
            <tr>
              <td colspan="8" class="text-center py-5 text-muted">
                <div class="py-4">
                  <i class="bi bi-box-seam fs-1 text-muted d-block mb-2"></i>
                  <h6 class="fw-bold text-navy mb-1">No orders found matching your search</h6>
                  <p class="small text-secondary mb-3">No orders or bills matched your keyword "<strong><?php echo htmlspecialchars($search); ?></strong>".</p>
                  <a href="orders.php" class="btn btn-gold btn-sm px-3 fw-bold">Reset Filters & Show All Orders</a>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($orders as $ord): ?>
              <?php $qInfo = $order_quotations_map[$ord['id']] ?? null; ?>
              <tr>
                <td class="fw-bold text-navy"><?php echo htmlspecialchars($ord['order_number']); ?></td>
                <td class="small text-muted"><?php echo format_datetime($ord['created_at']); ?></td>
                <td>
                  <div class="fw-semibold text-navy"><?php echo htmlspecialchars($ord['customer_name']); ?></div>
                  <div class="small d-flex align-items-center gap-1.5 flex-wrap">
                    <span class="text-muted"><?php echo htmlspecialchars($ord['customer_phone']); ?></span>
                    <?php if (!empty($ord['customer_code'])): ?>
                      <a href="customers.php?view=<?php echo urlencode($ord['customer_code']); ?>" class="badge bg-gold-subtle text-navy border border-warning-subtle text-decoration-none fw-bold" style="font-size: 0.7rem;" title="View Customer History">
                        <i class="bi bi-person-badge"></i> <?php echo htmlspecialchars($ord['customer_code']); ?>
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="d-flex flex-wrap align-items-center gap-2">
                    <small class="badge bg-light text-dark border"><?php echo htmlspecialchars($ord['payment_method']); ?></small>
                    <?php if ($ord['payment_status']==='Paid'): ?><span class="badge bg-success">Paid</span><?php else: ?><span class="badge bg-warning text-dark">Pending</span><?php endif; ?>
                  </div>
                </td>
                <td class="fw-bold text-navy"><?php echo format_price($ord['grand_total']); ?></td>
                <td>
                  <?php echo render_order_status_badge($ord['order_status']); ?>
                </td>
                <td>
                  <?php if ($qInfo): ?>
                    <div>
                      <a href="quotation.php?order_id=<?php echo $ord['id']; ?>" class="text-decoration-none">
                        <?php echo render_quotation_status_badge($qInfo['status']); ?>
                      </a>
                    </div>
                    <?php if ($qInfo['status'] === 'Accepted' && !empty($qInfo['customer_response_at'])): ?>
                      <div class="text-success small fw-semibold" style="font-size: 0.7rem;">Accepted <?php echo format_datetime($qInfo['customer_response_at']); ?></div>
                    <?php elseif ($qInfo['status'] === 'Declined' && !empty($qInfo['customer_response_at'])): ?>
                      <div class="text-danger small fw-semibold" style="font-size: 0.7rem;">Declined <?php echo format_datetime($qInfo['customer_response_at']); ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="badge bg-light text-muted border">None</span>
                  <?php endif; ?>
                </td>
                <td class="text-end text-nowrap">
                  <a href="orders_xml.php?order_id=<?php echo $ord['id']; ?>" class="btn btn-sm btn-outline-secondary me-1" title="Download XML Packing Sheet">
                    <i class="bi bi-file-earmark-code"></i> XML
                  </a>
                  <a href="print_parcel_sticker.php?order_id=<?php echo $ord['id']; ?>" target="_blank" class="btn btn-sm btn-outline-dark me-1" title="Print Official Parcel Shipping Label">
                    <i class="bi bi-box-seam text-warning"></i> Label
                  </a>
                  <a href="quotation.php?order_id=<?php echo $ord['id']; ?>" class="btn btn-sm btn-outline-navy me-1" title="Send or Edit Order Quotation">
                    <i class="bi bi-file-earmark-text text-gold me-1"></i> Send Quotation
                  </a>
                  <a href="orders.php?view=<?php echo $ord['id']; ?>" class="btn btn-sm btn-gold">Manage & Invoice</a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination Controls -->
    <?php if ($total_pages > 1): ?>
      <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2 bg-light mt-3 rounded-2">
        <div class="small text-muted">
          Showing <strong><?php echo min($total_orders_count, $offset + 1); ?></strong> to <strong><?php echo min($total_orders_count, $offset + count($orders)); ?></strong> of <strong><?php echo $total_orders_count; ?></strong> orders
        </div>
        <nav aria-label="Orders pagination">
          <ul class="pagination pagination-sm mb-0">
            <?php
              $pagination_query = $_GET;
              unset($pagination_query['page']);
              $base_url = 'orders.php?' . (empty($pagination_query) ? '' : http_build_query($pagination_query) . '&');
            ?>
            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
              <a class="page-link" href="<?php echo $base_url . 'page=' . ($page - 1); ?>"><i class="bi bi-chevron-left"></i> Previous</a>
            </li>
            
            <?php 
              $start_p = max(1, $page - 2);
              $end_p = min($total_pages, $page + 2);
              if ($start_p > 1) {
                echo '<li class="page-item"><a class="page-link" href="' . $base_url . 'page=1">1</a></li>';
                if ($start_p > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
              }
              for ($i = $start_p; $i <= $end_p; $i++):
            ?>
              <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                <a class="page-link <?php echo ($page == $i) ? 'bg-gold border-0 text-white' : ''; ?>" href="<?php echo $base_url . 'page=' . $i; ?>"><?php echo $i; ?></a>
              </li>
            <?php endfor; ?>
            
            <?php 
              if ($end_p < $total_pages) {
                if ($end_p < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                echo '<li class="page-item"><a class="page-link" href="' . $base_url . 'page=' . $total_pages . '">' . $total_pages . '</a></li>';
              }
            ?>

            <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
              <a class="page-link" href="<?php echo $base_url . 'page=' . ($page + 1); ?>">Next <i class="bi bi-chevron-right"></i></a>
            </li>
          </ul>
        </nav>
      </div>
    <?php endif; ?>
  </div>

  <script>
  document.addEventListener('DOMContentLoaded', function() {
    const clearBtn = document.getElementById('orderClearInputBtn');
    const input = document.getElementById('orderSearchInput');
    if (clearBtn && input) {
      clearBtn.addEventListener('click', function() {
        input.value = '';
        window.location.href = 'orders.php';
      });
    }
  });
  </script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
