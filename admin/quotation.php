<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();
$admin = get_current_admin();

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$quotation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Resolve quotation and order
$quotation = null;
$order = null;

if ($quotation_id > 0) {
    $stmt = $db->prepare("SELECT * FROM quotations WHERE id = ?");
    $stmt->execute([$quotation_id]);
    $quotation = $stmt->fetch();
    if ($quotation) {
        $order_id = (int)$quotation['order_id'];
    }
}

if ($order_id > 0) {
    $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
}

if (!$order && !$quotation) {
    set_flash('error', 'Order or Quotation not found.');
    header("Location: orders.php");
    exit();
}

// If quotation wasn't loaded by ID, check if an existing quotation exists for this order
if (!$quotation && $order_id > 0) {
    $stmt = $db->prepare("SELECT * FROM quotations WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$order_id]);
    $quotation = $stmt->fetch();
}

// Resolve customer details from order or users table
$user_id = (int)($order['user_id'] ?? ($quotation['user_id'] ?? 0));
$customer = null;
if ($user_id > 0) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $customer = $stmt->fetch();
}

// Fallback user lookup by customer_code or phone if user_id is missing
if (!$customer && !empty($order['customer_code'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE customer_code = ?");
    $stmt->execute([$order['customer_code']]);
    $customer = $stmt->fetch();
}
if (!$customer && !empty($order['customer_phone'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE phone = ?");
    $stmt->execute([$order['customer_phone']]);
    $customer = $stmt->fetch();
}

// If still no user_id, use user_id from customer record if found, else 0
if ($user_id <= 0 && $customer) {
    $user_id = (int)$customer['id'];
}

// Default quotation terms if new
$default_terms = "1. Quotation is valid for 7 calendar days from the date of issue.
2. Prices are strictly wholesale net rates inclusive of applicable Goods & Services Tax (GST).
3. Standard bundle packaging and transit protection included.
4. Freight / transport charges are as agreed or to be paid to the carrier upon arrival.
5. Delivery dispatch initiated within 24-48 hours of quotation acceptance.";

$default_notes = "Thank you for choosing Rajpriya Notebooks. We have prepared this custom wholesale quotation tailored for your order. Please review the bundle counts, ruling types, and prices below and click Accept to proceed with dispatch.";

// Handle POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $post_q_id = isset($_POST['quotation_id']) ? (int)$_POST['quotation_id'] : ($quotation['id'] ?? 0);

    // 1. Single Item Status Update (Accept / Decline Individual Product)
    if ($action === 'update_item_status') {
        $item_id = (int)($_POST['item_id'] ?? 0);
        $new_status = sanitize($_POST['item_status'] ?? '');
        $admin_comment = sanitize($_POST['admin_comment'] ?? '');

        $res = update_quotation_item_status($post_q_id, $item_id, $new_status, $admin_comment, $admin['id'] ?? 1);
        if ($res['success']) {
            record_quotation_revision($post_q_id, 'admin_review', 'admin', $admin['id'] ?? 1, "Admin set product item #{$item_id} status to {$new_status}" . ($admin_comment ? ": \"{$admin_comment}\"" : ""));
            set_flash('success', "Product item marked as {$new_status} successfully.");
        } else {
            set_flash('error', $res['message'] ?? 'Failed to update item status.');
        }
        header("Location: quotation.php?id=" . $post_q_id);
        exit();
    }

    // 2. Final Approval & Official Invoice Synchronization
    if ($action === 'sync_final_invoice') {
        $res = sync_final_quotation_to_invoice($post_q_id, $admin['id'] ?? 1);
        if ($res['success']) {
            set_flash('success', "Quotation successfully approved! Official Tax Invoice generated with {$res['approved_count']} approved products (Grand Total: " . format_price($res['grand_total']) . "). Declined and customer-removed products have been excluded.");
        } else {
            set_flash('error', $res['message'] ?? 'Failed to approve quotation and sync invoice.');
        }
        header("Location: quotation.php?id=" . $post_q_id);
        exit();
    }

    // 2.1 WhatsApp Invoice Resend / Retry
    if ($action === 'send_whatsapp_invoice') {
        $target_order_id = (int)($_POST['order_id'] ?? $order_id);
        $res = send_order_invoice_whatsapp($target_order_id, true);
        if (!empty($res['success'])) {
            $msg = "Invoice PDF sent via WhatsApp successfully to " . htmlspecialchars($res['phone'] ?? '') . ".";
            if (!empty($res['message_id'])) {
                $msg .= " (Message ID: " . htmlspecialchars($res['message_id']) . ")";
            }
            set_flash('success', $msg);
        } else {
            set_flash('error', "WhatsApp Delivery Failed: " . htmlspecialchars($res['error'] ?? 'Unknown error'));
        }
        header("Location: quotation.php?id=" . $post_q_id);
        exit();
    }

    // 3. Save Draft or Send Quotation
    if ($action === 'save_draft' || $action === 'send_quotation') {
        $q_number = sanitize($_POST['quotation_number'] ?? '');
        if (empty($q_number)) {
            $q_number = generate_quotation_number($order_id);
        }

        $admin_notes = trim($_POST['admin_notes'] ?? '');
        $terms_conditions = trim($_POST['terms_conditions'] ?? '');
        $discount_input = max(0.0, (float)($_POST['discount'] ?? 0.0));
        $delivery_fee_input = max(0.0, (float)($_POST['delivery_fee'] ?? 0.0));

        // Process submitted line items
        $raw_items = $_POST['items'] ?? [];
        $processed_items = [];

        if (empty($raw_items) || !is_array($raw_items)) {
            set_flash('error', 'Quotation must have at least one product item.');
            header("Location: quotation.php?order_id=" . $order_id);
            exit();
        }

        foreach ($raw_items as $item) {
            $p_name = sanitize($item['product_name'] ?? '');
            $p_code = sanitize($item['product_code'] ?? '');
            $p_variety = sanitize($item['variety'] ?? '');
            $p_ruling = sanitize($item['ruling_type'] ?? '');
            $p_pages = isset($item['page_count']) ? (int)$item['page_count'] : null;
            $p_order_type = sanitize($item['order_type'] ?? 'Bundle');
            $p_ppb = max(1, (int)($item['pieces_per_bundle'] ?? 1));
            $p_qty = max(1, (int)($item['quantity'] ?? 1));
            $p_unit_price = max(0.0, (float)($item['unit_price'] ?? 0.0));
            $p_discount = max(0.0, (float)($item['discount'] ?? 0.0));
            $p_gst_rate = isset($item['gst_rate']) && is_numeric($item['gst_rate']) ? (float)$item['gst_rate'] : 18.00;
            $p_prod_id = !empty($item['product_id']) ? (int)$item['product_id'] : null;
            $p_var_id = !empty($item['variant_id']) ? (int)$item['variant_id'] : null;

            $item_status = sanitize($item['item_status'] ?? 'Pending');
            if (!in_array($item_status, ['Pending', 'Accepted', 'Declined', 'Change Requested'])) {
                $item_status = 'Pending';
            }
            $orig_qty = isset($item['original_quantity']) && is_numeric($item['original_quantity']) ? (int)$item['original_quantity'] : $p_qty;
            $cust_req_qty = isset($item['customer_requested_quantity']) && is_numeric($item['customer_requested_quantity']) ? (int)$item['customer_requested_quantity'] : null;
            $cust_action = sanitize($item['customer_action'] ?? 'none');
            $is_cust_removed = !empty($item['is_customer_removed']) ? 1 : 0;
            $admin_comment = sanitize($item['admin_comment'] ?? '');

            $total_pieces = ($p_order_type === 'Bundle') ? ($p_qty * $p_ppb) : $p_qty;

            $processed_items[] = [
                'product_id' => $p_prod_id,
                'variant_id' => $p_var_id,
                'product_code' => $p_code,
                'product_name' => $p_name ?: 'Notebook',
                'variety' => $p_variety,
                'ruling_type' => $p_ruling,
                'page_count' => $p_pages,
                'order_type' => $p_order_type,
                'pieces_per_bundle' => $p_ppb,
                'quantity' => $p_qty,
                'total_pieces' => $total_pieces,
                'unit_price' => $p_unit_price,
                'discount' => $p_discount,
                'gst_rate' => $p_gst_rate,
                'item_status' => $item_status,
                'original_quantity' => $orig_qty,
                'customer_requested_quantity' => $cust_req_qty,
                'customer_action' => $cust_action,
                'is_customer_removed' => $is_cust_removed,
                'admin_comment' => $admin_comment
            ];
        }

        // Backend calculation verification (exclude customer removed items from active quotation financial calculations)
        $active_items = array_filter($processed_items, function($it) {
            return empty($it['is_customer_removed']);
        });
        if (empty($active_items)) {
            $active_items = $processed_items; // fallback
        }
        $calculated = calculate_quotation_totals($active_items, $discount_input, $delivery_fee_input);

        $customer_name = $order['customer_name'] ?? ($customer['name'] ?? 'Customer');
        $customer_email = $order['customer_email'] ?? ($customer['email'] ?? null);
        $customer_phone = $order['customer_phone'] ?? ($customer['phone'] ?? '');
        $customer_code = $order['customer_code'] ?? ($customer['customer_code'] ?? null);
        $shipping_address = format_display_address($order ?: $customer);

        $is_send = ($action === 'send_quotation');
        $status = $is_send ? 'Sent' : 'Draft';

        try {
            $db->beginTransaction();

            if ($post_q_id <= 0) {
                if (!empty($quotation['id'])) {
                    $post_q_id = (int)$quotation['id'];
                } else {
                    $stmtFindQ = $db->prepare("SELECT id FROM quotations WHERE quotation_number = ? LIMIT 1");
                    $stmtFindQ->execute([$q_number]);
                    $existingId = $stmtFindQ->fetchColumn();
                    if ($existingId) {
                        $post_q_id = (int)$existingId;
                    }
                }
            }

            if ($post_q_id > 0) {
                // Updating existing quotation
                $stmt_cur = $db->prepare("SELECT * FROM quotations WHERE id = ?");
                $stmt_cur->execute([$post_q_id]);
                $existing_q = $stmt_cur->fetch();

                $new_version = (int)($existing_q['version'] ?? 1);
                if ($is_send && in_array($existing_q['status'], ['Sent', 'Change Requested', 'Accepted', 'Declined'])) {
                    $new_version++;
                }

                $sent_at = $is_send ? date('Y-m-d H:i:s') : $existing_q['sent_at'];
                $cust_response_at = $is_send ? null : $existing_q['customer_response_at'];
                $decline_reason = $is_send ? null : $existing_q['customer_decline_reason'];

                $stmt_up = $db->prepare("
                    UPDATE quotations SET 
                        subtotal = ?,
                        discount = ?,
                        delivery_fee = ?,
                        taxable_amount = ?,
                        total_gst = ?,
                        total_cgst = ?,
                        total_sgst = ?,
                        grand_total = ?,
                        admin_notes = ?,
                        terms_conditions = ?,
                        status = ?,
                        version = ?,
                        sent_at = ?,
                        customer_response_at = ?,
                        customer_decline_reason = ?,
                        is_final_approved = CASE WHEN ? = 1 THEN 0 ELSE is_final_approved END,
                        invoice_synced_at = CASE WHEN ? = 1 THEN NULL ELSE invoice_synced_at END,
                        created_by_admin = ?,
                        customer_name = ?,
                        customer_email = ?,
                        customer_phone = ?,
                        customer_code = ?,
                        shipping_address = ?
                    WHERE id = ?
                ");
                $stmt_up->execute([
                    $calculated['subtotal'],
                    $calculated['discount'],
                    $calculated['delivery_fee'],
                    $calculated['taxable_amount'],
                    $calculated['total_gst'],
                    $calculated['total_cgst'],
                    $calculated['total_sgst'],
                    $calculated['grand_total'],
                    $admin_notes,
                    $terms_conditions,
                    $status,
                    $new_version,
                    $sent_at,
                    $cust_response_at,
                    $decline_reason,
                    $is_send ? 1 : 0,
                    $is_send ? 1 : 0,
                    $admin['id'] ?? 1,
                    $customer_name,
                    $customer_email,
                    $customer_phone,
                    $customer_code,
                    $shipping_address,
                    $post_q_id
                ]);

                // Replace line items
                $db->prepare("DELETE FROM quotation_items WHERE quotation_id = ?")->execute([$post_q_id]);
                $saved_quotation_id = $post_q_id;
            } else {
                // Insert new quotation
                $stmt_ins = $db->prepare("
                    INSERT INTO quotations (
                        quotation_number, order_id, order_number, user_id, customer_code,
                        customer_name, customer_email, customer_phone, shipping_address,
                        subtotal, discount, delivery_fee, taxable_amount, total_gst,
                        total_cgst, total_sgst, grand_total, admin_notes, terms_conditions,
                        status, version, sent_at, created_by_admin
                    ) VALUES (
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, 1, ?, ?
                    )
                ");
                $stmt_ins->execute([
                    $q_number,
                    $order_id,
                    $order['order_number'] ?? ('ORD-' . $order_id),
                    $user_id,
                    $customer_code,
                    $customer_name,
                    $customer_email,
                    $customer_phone,
                    $shipping_address,
                    $calculated['subtotal'],
                    $calculated['discount'],
                    $calculated['delivery_fee'],
                    $calculated['taxable_amount'],
                    $calculated['total_gst'],
                    $calculated['total_cgst'],
                    $calculated['total_sgst'],
                    $calculated['grand_total'],
                    $admin_notes,
                    $terms_conditions,
                    $status,
                    $is_send ? date('Y-m-d H:i:s') : null,
                    $admin['id'] ?? 1
                ]);
                $saved_quotation_id = (int)$db->lastInsertId();
            }

            // Insert quotation items with workflow tracking fields
            $stmt_item_ins = $db->prepare("
                INSERT INTO quotation_items (
                    quotation_id, product_id, variant_id, product_code, product_name,
                    variety, ruling_type, page_count, order_type, pieces_per_bundle,
                    quantity, total_pieces, unit_price, discount, total_price,
                    gst_rate, taxable_amount, gst_amount, cgst_amount, sgst_amount,
                    item_status, original_quantity, customer_requested_quantity, customer_action,
                    is_customer_removed, admin_comment
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?
                )
            ");

            foreach ($processed_items as $pItem) {
                // Recalculate line totals
                $pUnit = (float)$pItem['unit_price'];
                $pQty = (int)$pItem['quantity'];
                $pDisc = (float)$pItem['discount'];
                $pTot = max(0.0, ($pUnit * $pQty) - $pDisc);
                $pGstRate = (float)$pItem['gst_rate'];
                $pTaxable = $pTot;
                $pGst = round(($pTaxable * $pGstRate) / 100, 2);
                $pCgst = round($pGst / 2, 2);
                $pSgst = round($pGst - $pCgst, 2);

                $stmt_item_ins->execute([
                    $saved_quotation_id,
                    $pItem['product_id'],
                    $pItem['variant_id'],
                    $pItem['product_code'],
                    $pItem['product_name'],
                    $pItem['variety'],
                    $pItem['ruling_type'],
                    $pItem['page_count'],
                    $pItem['order_type'],
                    $pItem['pieces_per_bundle'],
                    $pItem['quantity'],
                    $pItem['total_pieces'],
                    $pItem['unit_price'],
                    $pItem['discount'],
                    $pTot,
                    $pGstRate,
                    $pTaxable,
                    $pGst,
                    $pCgst,
                    $pSgst,
                    $pItem['item_status'],
                    $pItem['original_quantity'],
                    $pItem['customer_requested_quantity'],
                    $pItem['customer_action'],
                    $pItem['is_customer_removed'],
                    $pItem['admin_comment']
                ]);
            }

            // Record revision snapshot in quotation_revisions for audit history
            record_quotation_revision(
                $saved_quotation_id,
                $is_send ? 'admin_update' : 'initial',
                'admin',
                $admin['id'] ?? 1,
                $is_send ? 'Admin sent quotation to customer' : 'Admin saved quotation draft'
            );

            $db->commit();

            if ($is_send) {
                set_flash('success', "Quotation #{$q_number} has been sent successfully to customer '{$customer_name}'!");
            } else {
                set_flash('success', "Quotation #{$q_number} draft saved successfully.");
            }

            header("Location: quotation.php?id=" . $saved_quotation_id);
            exit();

        } catch (Exception $e) {
            $db->rollBack();
            set_flash('error', 'Failed to save quotation: ' . $e->getMessage());
            header("Location: quotation.php?order_id=" . $order_id);
            exit();
        }
    }
}

// Fetch active catalog products and variants for "+ Add Product" picker
$catalog_products = $db->query("
    SELECT p.id, p.name, p.sku, p.price, p.sale_price, p.pages, p.size, p.gst_rate, p.std_qty, p.brand_name
    FROM products p 
    WHERE p.active = 1 
    ORDER BY p.name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$catalog_variants = $db->query("
    SELECT pv.id, pv.product_id, pv.product_code, pv.page_count, pv.variety, pv.variety_display, pv.bundle_qty, pv.selling_price
    FROM product_variants pv 
    ORDER BY pv.product_id ASC, pv.variety_display ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Map variants by product_id
$variants_by_product = [];
foreach ($catalog_variants as $v) {
    $variants_by_product[$v['product_id']][] = $v;
}

// Load quotation items (from existing quotation OR from order_items if new)
$items = [];
if ($quotation) {
    $items = get_quotation_items($quotation['id']);
} elseif ($order) {
    $stmt_oi = $db->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
    $stmt_oi->execute([$order['id']]);
    $order_items = $stmt_oi->fetchAll();

    foreach ($order_items as $oi) {
        $vInfo = parse_variety_info($oi['ruling_type'] ?? '');
        $is_bundle = (stripos($oi['order_type'] ?? '', 'Bundle') !== false) || (stripos($oi['ruling_type'] ?? '', 'Bundle') !== false);
        $ppb = !empty($oi['pieces_per_bundle']) ? (int)$oi['pieces_per_bundle'] : get_product_pieces_per_bundle($oi['product_id'], $oi['ruling_type'] ?? '', null);
        $total_pieces = !empty($oi['total_pieces']) ? (int)$oi['total_pieces'] : ($is_bundle ? ((int)$oi['quantity'] * $ppb) : (int)$oi['quantity']);

        $items[] = [
            'id' => 0,
            'product_id' => $oi['product_id'],
            'variant_id' => $oi['variant_id'],
            'product_code' => $oi['product_code'],
            'product_name' => $oi['product_name'],
            'variety' => $oi['variety'] ?: ($vInfo['variety'] ?? 'Unruled'),
            'ruling_type' => $oi['ruling_type'],
            'page_count' => $oi['page_count'] ?: 192,
            'order_type' => $is_bundle ? 'Bundle' : 'Piece',
            'pieces_per_bundle' => max(1, $ppb),
            'quantity' => (int)$oi['quantity'],
            'total_pieces' => $total_pieces,
            'unit_price' => (float)$oi['price'],
            'discount' => 0.00,
            'total_price' => (float)$oi['total_price'],
            'gst_rate' => !empty($oi['gst_rate']) ? (float)$oi['gst_rate'] : 18.00,
            'taxable_amount' => !empty($oi['taxable_amount']) ? (float)$oi['taxable_amount'] : (float)$oi['total_price'],
            'gst_amount' => (float)($oi['gst_amount'] ?? 0),
            'cgst_amount' => (float)($oi['cgst_amount'] ?? 0),
            'sgst_amount' => (float)($oi['sgst_amount'] ?? 0)
        ];
    }
}

$quotation_number = $quotation['quotation_number'] ?? generate_quotation_number($order['id']);
$quotation_status = $quotation['status'] ?? 'Draft';
$quotation_version = $quotation['version'] ?? 1;
$admin_notes_val = $quotation['admin_notes'] ?? $default_notes;
$terms_val = $quotation['terms_conditions'] ?? $default_terms;
$discount_val = (float)($quotation['discount'] ?? ($order['discount'] ?? 0.0));
$delivery_val = (float)($quotation['delivery_fee'] ?? ($order['delivery_fee'] ?? 0.0));

// Calculate item decision counts and change request diffs
$accepted_count = 0;
$declined_count = 0;
$pending_items_count = 0;
$change_req_count = 0;
$removed_items_count = 0;
$modified_items_count = 0;
$unchanged_items_count = 0;
$has_customer_changes = false;

foreach ($items as $chkItem) {
    $st = $chkItem['item_status'] ?? 'Pending';
    $isRem = !empty($chkItem['is_customer_removed']) || (($chkItem['customer_action'] ?? '') === 'removed');
    $origQ = (int)($chkItem['original_quantity'] ?? $chkItem['quantity']);
    $currQ = (int)$chkItem['quantity'];
    $isMod = !$isRem && ($origQ !== $currQ || ($chkItem['customer_action'] ?? '') === 'modified');

    if ($isRem) {
        $removed_items_count++;
        $has_customer_changes = true;
    } elseif ($isMod) {
        $modified_items_count++;
        $has_customer_changes = true;
    } else {
        $unchanged_items_count++;
    }

    if ($isRem) {
        // Customer removed items are excluded from active invoice
    } elseif ($st === 'Accepted') {
        $accepted_count++;
    } elseif ($st === 'Declined') {
        $declined_count++;
    } elseif ($st === 'Change Requested') {
        $change_req_count++;
    } else {
        $pending_items_count++;
    }
}

$revisions = $quotation ? get_quotation_revisions($quotation['id']) : [];

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="mb-4 d-flex justify-content-between align-items-center flex-wrap gap-2 admin-page-header">
  <div>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-1 small">
        <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none text-secondary">Dashboard</a></li>
        <li class="breadcrumb-item"><a href="orders.php" class="text-decoration-none text-secondary">Orders</a></li>
        <?php if ($order): ?>
          <li class="breadcrumb-item"><a href="orders.php?view=<?php echo $order['id']; ?>" class="text-decoration-none text-secondary">Order #<?php echo htmlspecialchars($order['order_number']); ?></a></li>
        <?php endif; ?>
        <li class="breadcrumb-item active text-gold fw-bold" aria-current="page">Quotation #<?php echo htmlspecialchars($quotation_number); ?></li>
      </ol>
    </nav>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <h3 class="fw-bold text-navy mb-0">
        <i class="bi bi-file-earmark-text text-gold me-1"></i> Order Quotation Editor
      </h3>
      <span class="badge bg-gold text-navy fw-bold px-2.5 py-1">#<?php echo htmlspecialchars($quotation_number); ?></span>
      <?php if ($quotation_version > 1): ?>
        <span class="badge bg-light text-dark border fw-bold px-2 py-1">Version <?php echo $quotation_version; ?></span>
      <?php endif; ?>
      <?php echo render_quotation_status_badge($quotation_status); ?>
      <?php if (!empty($quotation['is_final_approved'])): ?>
        <span class="badge bg-success text-white fw-bold px-2.5 py-1"><i class="bi bi-check-all me-1"></i> Invoice Synced</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <a href="orders.php?view=<?php echo $order['id']; ?>" class="btn btn-outline-secondary btn-sm px-3 fw-semibold">
      <i class="bi bi-arrow-left me-1"></i> Back to Order
    </a>
    <a href="quotations.php" class="btn btn-outline-navy btn-sm px-3 fw-semibold">
      <i class="bi bi-journal-text me-1"></i> All Quotations
    </a>
    <?php if ($quotation): ?>
      <button type="button" onclick="window.print()" class="btn btn-outline-dark btn-sm px-3 fw-semibold">
        <i class="bi bi-printer me-1"></i> Print Quotation
      </button>
    <?php endif; ?>
  </div>
</div>

<!-- Customer Response Status Banner -->
<?php if (!empty($quotation['is_final_approved'])): 
  $wa_q_log = $order ? get_order_whatsapp_delivery_status($order['id']) : null;
  $wa_q_status = $wa_q_log ? strtolower($wa_q_log['status']) : 'not_sent';
?>
  <div class="alert alert-success border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2 p-3 mb-4 rounded-3">
    <div class="d-flex align-items-center gap-3">
      <i class="bi bi-patch-check-fill fs-2 text-success"></i>
      <div>
        <h6 class="fw-bold text-success mb-1">
          Final Quotation Approved &amp; Invoice Generated
          <?php if ($wa_q_status === 'delivered' || $wa_q_status === 'read'): ?>
            <span class="badge bg-success ms-2"><i class="bi bi-whatsapp me-1"></i> WhatsApp: Delivered</span>
          <?php elseif ($wa_q_status === 'sent' || $wa_q_status === 'accepted'): ?>
            <span class="badge bg-primary ms-2"><i class="bi bi-whatsapp me-1"></i> WhatsApp: Sent</span>
          <?php elseif ($wa_q_status === 'failed'): ?>
            <span class="badge bg-danger ms-2" title="<?php echo htmlspecialchars($wa_q_log['error_message'] ?? ''); ?>"><i class="bi bi-whatsapp me-1"></i> WhatsApp: Failed</span>
          <?php elseif ($wa_q_status === 'pending'): ?>
            <span class="badge bg-warning text-dark ms-2"><i class="bi bi-whatsapp me-1"></i> WhatsApp: Pending</span>
          <?php endif; ?>
        </h6>
        <div class="small">
          Final approved on <strong><?php echo format_datetime(!empty($quotation['invoice_synced_at']) ? $quotation['invoice_synced_at'] : $quotation['updated_at']); ?></strong>.
          The official customer tax invoice contains only the <strong><?php echo $accepted_count; ?> accepted product(s)</strong> with recalculated GST and grand total.
          <?php if ($wa_q_log && !empty($wa_q_log['recipient_phone'])): ?>
            <span class="d-block mt-0.5 text-secondary">
              WhatsApp sent to: <strong class="text-dark"><?php echo htmlspecialchars($wa_q_log['recipient_phone']); ?></strong> 
              (Attempted <?php echo format_datetime($wa_q_log['attempt_time'] ?? $wa_q_log['created_at']); ?>)
              <?php if (!empty($wa_q_log['error_message']) && $wa_q_status === 'failed'): ?>
                &mdash; <span class="text-danger fw-semibold"><?php echo htmlspecialchars($wa_q_log['error_message']); ?></span>
              <?php endif; ?>
            </span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <?php if ($order): ?>
        <form action="quotation.php?id=<?php echo $quotation['id']; ?>" method="POST" class="d-inline" onsubmit="return confirm('Retry / Resend Invoice PDF via WhatsApp?');">
          <input type="hidden" name="action" value="send_whatsapp_invoice">
          <input type="hidden" name="quotation_id" value="<?php echo $quotation['id']; ?>">
          <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
          <button type="submit" class="btn <?php echo ($wa_q_status === 'failed') ? 'btn-danger' : 'btn-outline-success'; ?> btn-sm px-3 fw-bold shadow-xs">
            <i class="bi bi-whatsapp me-1"></i> <?php echo ($wa_q_status === 'failed') ? 'Retry WhatsApp' : 'Resend WhatsApp'; ?>
          </button>
        </form>
      <?php endif; ?>
      <a href="orders.php?view=<?php echo $order['id']; ?>" class="btn btn-success btn-sm px-3 fw-bold">
        <i class="bi bi-receipt me-1"></i> View Official Tax Invoice #<?php echo htmlspecialchars($order['order_number']); ?>
      </a>
    </div>
  </div>
<?php elseif ($quotation_status === 'Change Requested'): ?>
  <div class="alert alert-warning border-0 shadow-sm d-flex align-items-start gap-3 p-3 mb-4 rounded-3">
    <i class="bi bi-exclamation-triangle-fill fs-2 text-warning"></i>
    <div class="flex-grow-1">
      <h6 class="fw-bold text-navy mb-1">Customer Requested Changes to this Quotation</h6>
      <div class="small">
        Change requested on <strong><?php echo format_datetime(!empty($quotation['change_requested_at']) ? $quotation['change_requested_at'] : $quotation['updated_at']); ?></strong>.
        <?php if (!empty($quotation['customer_notes'])): ?>
          <div class="mt-2 p-2.5 bg-white rounded border border-warning-subtle text-dark">
            <strong>Customer Remarks:</strong> "<?php echo htmlspecialchars($quotation['customer_notes']); ?>"
          </div>
        <?php endif; ?>
        <div class="mt-2 text-dark">
          Review the requested changes in the comparison card below. Accept or decline individual products as needed before finalizing approval.
        </div>
      </div>
    </div>
  </div>
<?php elseif ($quotation_status === 'Accepted'): ?>
  <div class="alert alert-success border-0 shadow-sm d-flex align-items-center gap-3 p-3 mb-4 rounded-3">
    <i class="bi bi-check-circle-fill fs-2 text-success"></i>
    <div>
      <h6 class="fw-bold text-success mb-1">Customer Accepted This Quotation</h6>
      <div class="small">
        Accepted on <strong><?php echo !empty($quotation['customer_response_at']) ? format_datetime($quotation['customer_response_at']) : 'Recently'; ?></strong>.
        You can now review individual products and click <strong>“Approve Quotation &amp; Generate Invoice”</strong> to finalize the order invoice.
      </div>
    </div>
  </div>
<?php elseif ($quotation_status === 'Declined'): ?>
  <div class="alert alert-danger border-0 shadow-sm d-flex align-items-start gap-3 p-3 mb-4 rounded-3">
    <i class="bi bi-x-circle-fill fs-2 text-danger"></i>
    <div>
      <h6 class="fw-bold text-danger mb-1">Customer Declined This Quotation</h6>
      <div class="small">
        Declined on <strong><?php echo !empty($quotation['customer_response_at']) ? format_datetime($quotation['customer_response_at']) : 'Recently'; ?></strong>.
        <?php if (!empty($quotation['customer_decline_reason'])): ?>
          <div class="mt-2 p-2.5 bg-white rounded border border-danger-subtle text-dark">
            <strong>Customer Reason / Note:</strong> "<?php echo htmlspecialchars($quotation['customer_decline_reason']); ?>"
          </div>
        <?php endif; ?>
        <div class="mt-2 fw-semibold text-danger">
          Tip: You can edit prices, discounts, or quantities below and click <strong>“Send Revised Quotation”</strong> to resend a new version to the customer.
        </div>
      </div>
    </div>
  </div>
<?php elseif ($quotation_status === 'Sent'): ?>
  <div class="alert alert-primary border-0 shadow-sm d-flex align-items-center gap-3 p-3 mb-4 rounded-3">
    <i class="bi bi-send-check fs-2 text-primary"></i>
    <div>
      <h6 class="fw-bold text-primary mb-1">Quotation Sent — Pending Customer Response</h6>
      <div class="small text-secondary">
        Sent to customer on <strong><?php echo !empty($quotation['sent_at']) ? format_datetime($quotation['sent_at']) : 'Recently'; ?></strong>.
        The quotation is live in the customer's account dashboard awaiting their review.
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="alert alert-secondary border-0 shadow-sm d-flex align-items-center gap-3 p-3 mb-4 rounded-3">
    <i class="bi bi-pencil-square fs-2 text-secondary"></i>
    <div>
      <h6 class="fw-bold text-navy mb-1">Draft Quotation</h6>
      <div class="small text-muted">
        This quotation is currently saved as a draft. Review or customize quantities, unit prices, discounts, and terms below, then click <strong>“Send Quotation”</strong> to deliver it to the customer.
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Customer Change Request Comparison Card (Requirements 3 & 4) -->
<?php if ($quotation_status === 'Change Requested' || !empty($quotation['change_requested_at']) || $has_customer_changes): ?>
  <div class="card border-warning shadow-sm mb-4">
    <div class="card-header bg-warning-subtle text-dark d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <span class="badge bg-warning text-dark fw-bold me-2"><i class="bi bi-arrow-repeat me-1"></i> Customer Change Request</span>
        <strong class="text-navy">Visual Product &amp; Quantity Differences</strong>
      </div>
      <div class="small text-muted">
        Customer Modification Date: <strong><?php echo format_datetime(!empty($quotation['change_requested_at']) ? $quotation['change_requested_at'] : $quotation['updated_at']); ?></strong>
      </div>
    </div>
    <div class="card-body p-4">
      <?php if (!empty($quotation['customer_notes'])): ?>
        <div class="p-3 bg-light rounded border border-warning-subtle mb-3">
          <div class="small fw-bold text-navy mb-1"><i class="bi bi-chat-left-quote-fill text-gold me-1"></i> Customer Remarks / Note:</div>
          <div class="text-dark">"<?php echo htmlspecialchars($quotation['customer_notes']); ?>"</div>
        </div>
      <?php endif; ?>

      <div class="d-flex gap-2 flex-wrap mb-3 small">
        <span class="badge bg-light text-dark border p-2">Original Line Items: <strong><?php echo count($items); ?></strong></span>
        <span class="badge bg-danger-subtle text-danger border border-danger-subtle p-2">Removed by Customer: <strong><?php echo $removed_items_count; ?></strong></span>
        <span class="badge bg-warning-subtle text-dark border border-warning-subtle p-2">Quantity Modified: <strong><?php echo $modified_items_count; ?></strong></span>
        <span class="badge bg-success-subtle text-success border border-success-subtle p-2">Unchanged: <strong><?php echo $unchanged_items_count; ?></strong></span>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle mb-0">
          <thead class="table-light text-navy">
            <tr>
              <th>Product &amp; Variety</th>
              <th class="text-center" style="width: 120px;">Original Qty</th>
              <th class="text-center" style="width: 140px;">Requested Qty</th>
              <th style="width: 190px;">Customer Action</th>
              <th class="text-center" style="width: 130px;">Approval Status</th>
              <th class="text-center" style="width: 170px;">Individual Decision</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $cIdx => $diffItem): 
              $isRem = !empty($diffItem['is_customer_removed']) || (($diffItem['customer_action'] ?? '') === 'removed');
              $origQ = (int)($diffItem['original_quantity'] ?? $diffItem['quantity']);
              $currQ = (int)$diffItem['quantity'];
              $isMod = !$isRem && ($origQ !== $currQ || ($diffItem['customer_action'] ?? '') === 'modified');
            ?>
              <tr class="<?php echo $isRem ? 'table-danger-subtle' : ($isMod ? 'table-warning-subtle' : ''); ?>">
                <td>
                  <span class="<?php echo $isRem ? 'text-decoration-line-through text-muted' : 'fw-bold text-navy'; ?>">
                    <?php echo htmlspecialchars($diffItem['product_name']); ?>
                  </span>
                  <?php if (!empty($diffItem['variety'])): ?>
                    <span class="badge bg-gold-subtle text-navy border ms-1"><?php echo htmlspecialchars($diffItem['variety']); ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-center font-monospace">
                  <?php echo $origQ; ?> <?php echo htmlspecialchars($diffItem['order_type'] ?? 'Bdl'); ?>
                </td>
                <td class="text-center font-monospace fw-bold">
                  <?php if ($isRem): ?>
                    <span class="text-danger"><i class="bi bi-x-circle me-1"></i> 0 (Removed)</span>
                  <?php elseif ($isMod): ?>
                    <span class="text-warning-emphasis"><?php echo $currQ; ?> <?php echo htmlspecialchars($diffItem['order_type'] ?? 'Bdl'); ?></span>
                    <span class="small badge bg-warning text-dark ms-1"><?php echo ($currQ > $origQ ? '+'.($currQ - $origQ) : ($currQ - $origQ)); ?></span>
                  <?php else: ?>
                    <span class="text-muted"><?php echo $currQ; ?> <?php echo htmlspecialchars($diffItem['order_type'] ?? 'Bdl'); ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ($isRem): ?>
                    <span class="badge bg-danger"><i class="bi bi-trash me-1"></i> Removed by Customer</span>
                  <?php elseif ($isMod): ?>
                    <span class="badge bg-warning text-dark"><i class="bi bi-pencil me-1"></i> Quantity Changed (<?php echo $origQ; ?> &rarr; <?php echo $currQ; ?>)</span>
                  <?php else: ?>
                    <span class="badge bg-light text-secondary border">Unchanged</span>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <?php echo render_quotation_item_status_badge($diffItem['item_status'], $isRem); ?>
                </td>
                <td class="text-center">
                  <?php if ($isRem): ?>
                    <span class="small text-muted fst-italic">Excluded from Invoice</span>
                  <?php else: ?>
                    <div class="btn-group btn-group-sm">
                      <button type="button" class="btn <?php echo ($diffItem['item_status'] === 'Accepted') ? 'btn-success text-white fw-bold' : 'btn-outline-success'; ?>" onclick="quickSetItemStatus(<?php echo (int)($diffItem['id'] ?? 0); ?>, <?php echo $cIdx; ?>, 'Accepted')" title="Accept this product">
                        <i class="bi bi-check-lg"></i> Accept
                      </button>
                      <button type="button" class="btn <?php echo ($diffItem['item_status'] === 'Declined') ? 'btn-danger text-white fw-bold' : 'btn-outline-danger'; ?>" onclick="quickSetItemStatus(<?php echo (int)($diffItem['id'] ?? 0); ?>, <?php echo $cIdx; ?>, 'Declined')" title="Decline this product">
                        <i class="bi bi-x-lg"></i> Decline
                      </button>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Quotation Decision Progress & Invoice Sync Bar (Requirement 1 & 5) -->
<div class="alert alert-light border shadow-sm p-3 mb-4 rounded-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <span class="fw-bold text-navy"><i class="bi bi-check2-square text-gold me-1"></i> Product Approval Progress:</span>
    <span class="badge bg-success px-2.5 py-1.5"><i class="bi bi-check-circle-fill me-1"></i> <?php echo $accepted_count; ?> Accepted</span>
    <span class="badge bg-danger px-2.5 py-1.5"><i class="bi bi-x-circle-fill me-1"></i> <?php echo $declined_count; ?> Declined</span>
    <?php if ($pending_items_count > 0): ?>
      <span class="badge bg-secondary px-2.5 py-1.5"><i class="bi bi-clock-history me-1"></i> <?php echo $pending_items_count; ?> Pending Review</span>
    <?php endif; ?>
    <?php if ($change_req_count > 0): ?>
      <span class="badge bg-warning text-dark px-2.5 py-1.5"><i class="bi bi-arrow-repeat me-1"></i> <?php echo $change_req_count; ?> Change Requested</span>
    <?php endif; ?>
    <?php if ($removed_items_count > 0): ?>
      <span class="badge bg-light text-muted border px-2.5 py-1.5"><i class="bi bi-trash me-1"></i> <?php echo $removed_items_count; ?> Customer Removed</span>
    <?php endif; ?>
  </div>
  <div>
    <?php if ($quotation && !empty($quotation['is_final_approved'])): ?>
      <a href="orders.php?view=<?php echo $order['id']; ?>" class="btn btn-outline-success fw-bold px-3">
        <i class="bi bi-receipt me-1"></i> View Synchronized Tax Invoice
      </a>
    <?php elseif ($quotation_status !== 'Accepted'): ?>
      <button type="button" class="btn btn-outline-secondary fw-semibold px-3" disabled>
        <i class="bi bi-hourglass-split me-1"></i> Awaiting Customer Acceptance
      </button>
    <?php elseif ($pending_items_count === 0 && $change_req_count === 0 && $accepted_count > 0): ?>
      <button type="button" class="btn btn-success fw-bold px-3 shadow-xs" onclick="confirmSyncInvoice()">
        <i class="bi bi-file-earmark-check-fill me-1"></i> Approve Quotation &amp; Generate Invoice
      </button>
    <?php else: ?>
      <button type="button" class="btn btn-outline-secondary fw-semibold px-3" onclick="confirmSyncInvoice()">
        <i class="bi bi-lock me-1"></i> Approve &amp; Generate Invoice <?php echo ($pending_items_count > 0 || $change_req_count > 0) ? "({$pending_items_count} Pending Decisions)" : ''; ?>
      </button>
    <?php endif; ?>
  </div>
</div>

<!-- Main Quotation Form -->
<form action="quotation.php<?php echo $order_id ? '?order_id='.$order_id : ''; ?><?php echo $quotation ? '&id='.$quotation['id'] : ''; ?>" method="POST" id="quotationForm" onsubmit="return handleFormSubmit(event)">
  <input type="hidden" name="quotation_id" value="<?php echo $quotation['id'] ?? 0; ?>">
  <input type="hidden" name="order_id" value="<?php echo $order['id'] ?? 0; ?>">
  <input type="hidden" name="quotation_number" value="<?php echo htmlspecialchars($quotation_number); ?>">
  <input type="hidden" name="action" id="formActionField" value="save_draft">

  <div class="row g-4 mb-4">
    <!-- Left Column: Customer & Order Overview (Read-only metadata) -->
    <div class="col-lg-12">
      <div class="bg-white p-4 rounded-3 border shadow-sm">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-3 mb-3">
          <h5 class="fw-bold text-navy mb-0">
            <i class="bi bi-person-badge text-gold me-1"></i> Customer &amp; Order Information
          </h5>
          <div class="small text-muted">
            Order Date: <strong><?php echo format_datetime($order['created_at']); ?></strong>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-md-3">
            <label class="form-label small text-muted mb-0">Order Reference</label>
            <div class="fw-bold text-navy">
              <a href="orders.php?view=<?php echo $order['id']; ?>" class="text-navy text-decoration-none" target="_blank" title="Open full order in new tab">
                <?php echo htmlspecialchars($order['order_number'] ?? '—'); ?> <i class="bi bi-box-arrow-up-right small text-gold"></i>
              </a>
            </div>
            <div class="small text-muted">Current Order Status: <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($order['order_status'] ?? 'Pending'); ?></span></div>
          </div>
          <div class="col-md-3">
            <label class="form-label small text-muted mb-0">Customer Name &amp; ID</label>
            <div class="fw-bold text-navy"><?php echo htmlspecialchars($order['customer_name'] ?? ($customer['name'] ?? 'Guest')); ?></div>
            <div class="small">
              <?php if (!empty($customer['customer_code']) || !empty($order['customer_code'])): ?>
                <span class="badge bg-navy text-gold fw-bold"><?php echo htmlspecialchars($customer['customer_code'] ?? $order['customer_code']); ?></span>
              <?php endif; ?>
              <span class="badge bg-gold-subtle text-navy border fw-bold"><?php echo htmlspecialchars($customer['customer_type'] ?? ($order['customer_type'] ?? 'Retailer')); ?></span>
            </div>
          </div>
          <div class="col-md-3">
            <label class="form-label small text-muted mb-0">Contact Details</label>
            <div class="small text-dark"><i class="bi bi-telephone text-gold me-1"></i> <?php echo htmlspecialchars($order['customer_phone'] ?? ($customer['phone'] ?? '—')); ?></div>
            <div class="small text-dark"><i class="bi bi-envelope text-gold me-1"></i> <?php echo htmlspecialchars($order['customer_email'] ?? ($customer['email'] ?? 'Not Provided')); ?></div>
          </div>
          <div class="col-md-3">
            <label class="form-label small text-muted mb-0">GST Registration Status</label>
            <?php 
            $gst_is_reg = (!empty($order['gst_status']) && strcasecmp($order['gst_status'], 'Registered') === 0) || !empty($order['gst_number']) || (!empty($customer['gst_status']) && strcasecmp($customer['gst_status'], 'Registered') === 0);
            $gst_num = $order['gst_number'] ?? ($customer['gst_number'] ?? '');
            ?>
            <div class="mt-1">
              <?php if ($gst_is_reg && !empty($gst_num)): ?>
                <span class="badge bg-success"><i class="bi bi-check-circle-fill me-1"></i> Registered</span>
                <span class="badge bg-gold text-navy fw-bold font-monospace"><?php echo htmlspecialchars($gst_num); ?></span>
              <?php else: ?>
                <span class="badge bg-secondary"><i class="bi bi-x-circle me-1"></i> Not Registered</span>
              <?php endif; ?>
            </div>
            <div class="small text-muted mt-1">Delivery: <?php echo htmlspecialchars(format_display_address($order ?: $customer)); ?></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Items Section: Editable Table -->
    <div class="col-lg-12">
      <div class="bg-white p-4 rounded-3 border shadow-sm">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 border-bottom pb-3 mb-3">
          <div>
            <h5 class="fw-bold text-navy mb-0">
              <i class="bi bi-list-check text-gold me-1"></i> Quotation Line Items
            </h5>
            <small class="text-secondary">Every product has independent Accept and Decline controls. Totals and GST calculate dynamically.</small>
          </div>
          <div>
            <button type="button" class="btn btn-outline-gold btn-sm fw-semibold" data-bs-toggle="modal" data-bs-target="#addProductModal">
              <i class="bi bi-plus-circle me-1"></i> Add Product from Catalog
            </button>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table align-middle table-hover" id="quotationItemsTable">
            <thead class="table-light text-navy">
              <tr>
                <th style="min-width: 220px;">Product &amp; Variety</th>
                <th style="min-width: 100px;">Type</th>
                <th style="min-width: 80px;" class="text-center">Pcs/Bdl</th>
                <th style="min-width: 90px;" class="text-center">Qty</th>
                <th style="min-width: 85px;" class="text-center">Total Pcs</th>
                <th style="min-width: 110px;" class="text-end">Unit Price (₹)</th>
                <th style="min-width: 90px;" class="text-end">Disc (₹)</th>
                <th style="min-width: 80px;" class="text-center">GST %</th>
                <th style="min-width: 110px;" class="text-end">Line Total (₹)</th>
                <th style="min-width: 130px;" class="text-center">Approval Status</th>
                <th style="min-width: 180px;" class="text-center">Product Decision</th>
              </tr>
            </thead>
            <tbody id="itemsTableBody">
              <?php if (empty($items)): ?>
                <tr id="noItemsRow">
                  <td colspan="11" class="text-center py-4 text-muted">
                    No products currently in quotation. Click "+ Add Product from Catalog" to add notebooks.
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($items as $idx => $it): ?>
                  <?php 
                  $isBundle = (strcasecmp($it['order_type'] ?? '', 'Bundle') === 0) || (stripos($it['ruling_type'] ?? '', 'Bundle') !== false);
                  $ppb = max(1, (int)($it['pieces_per_bundle'] ?? 1));
                  $qty = max(1, (int)($it['quantity'] ?? 1));
                  $totPcs = $isBundle ? ($qty * $ppb) : $qty;
                  $unitP = (float)($it['unit_price'] ?? ($it['price'] ?? 0));
                  $disc = (float)($it['discount'] ?? 0);
                  $lineTot = max(0, ($unitP * $qty) - $disc);
                  $gstR = (float)($it['gst_rate'] ?? 18.00);

                  $itemStatus = $it['item_status'] ?? 'Pending';
                  $isCustomerRemoved = !empty($it['is_customer_removed']) || (($it['customer_action'] ?? '') === 'removed');
                  $origQty = (int)($it['original_quantity'] ?? $qty);
                  $isQtyModified = !$isCustomerRemoved && ($origQty !== $qty || ($it['customer_action'] ?? '') === 'modified');
                  ?>
                  <tr class="quotation-item-row <?php echo $isCustomerRemoved ? 'table-danger-subtle' : ($isQtyModified ? 'table-warning-subtle' : ''); ?>" data-index="<?php echo $idx; ?>" data-customer-removed="<?php echo $isCustomerRemoved ? '1' : '0'; ?>">
                    <td>
                      <input type="hidden" name="items[<?php echo $idx; ?>][item_id]" value="<?php echo (int)($it['id'] ?? 0); ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][product_id]" value="<?php echo htmlspecialchars($it['product_id'] ?? ''); ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][variant_id]" value="<?php echo htmlspecialchars($it['variant_id'] ?? ''); ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][product_code]" value="<?php echo htmlspecialchars($it['product_code'] ?? ''); ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][variety]" value="<?php echo htmlspecialchars($it['variety'] ?? ''); ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][ruling_type]" value="<?php echo htmlspecialchars($it['ruling_type'] ?? ''); ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][page_count]" value="<?php echo htmlspecialchars($it['page_count'] ?? ''); ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][gst_rate]" class="item-gst-rate" value="<?php echo htmlspecialchars($gstR); ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][item_status]" id="item_status_<?php echo $idx; ?>" value="<?php echo htmlspecialchars($itemStatus); ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][original_quantity]" value="<?php echo $origQty; ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][customer_requested_quantity]" value="<?php echo htmlspecialchars($it['customer_requested_quantity'] ?? ''); ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][customer_action]" value="<?php echo htmlspecialchars($it['customer_action'] ?? 'none'); ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][is_customer_removed]" value="<?php echo $isCustomerRemoved ? '1' : '0'; ?>">
                      <input type="hidden" name="items[<?php echo $idx; ?>][admin_comment]" id="admin_comment_<?php echo $idx; ?>" value="<?php echo htmlspecialchars($it['admin_comment'] ?? ''); ?>">

                      <input type="text" name="items[<?php echo $idx; ?>][product_name]" class="form-control form-control-sm fw-bold text-navy mb-1 <?php echo $isCustomerRemoved ? 'text-decoration-line-through' : ''; ?>" value="<?php echo htmlspecialchars($it['product_name']); ?>" required>
                      <div class="small d-flex align-items-center gap-1.5 flex-wrap">
                        <?php if (!empty($it['product_code'])): ?>
                          <span class="badge bg-light text-secondary border font-monospace"><?php echo htmlspecialchars($it['product_code']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($it['variety'])): ?>
                          <span class="badge bg-gold-subtle text-navy border border-warning-subtle"><?php echo htmlspecialchars($it['variety']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($it['page_count'])): ?>
                          <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($it['page_count']); ?> Pages</span>
                        <?php endif; ?>
                        <?php if ($isCustomerRemoved): ?>
                          <span class="badge bg-danger"><i class="bi bi-trash me-1"></i> Customer Removed</span>
                        <?php elseif ($isQtyModified): ?>
                          <span class="badge bg-warning text-dark"><i class="bi bi-pencil me-1"></i> Qty: <?php echo $origQty; ?> &rarr; <?php echo $qty; ?></span>
                        <?php endif; ?>
                      </div>
                    </td>
                    <td>
                      <select name="items[<?php echo $idx; ?>][order_type]" class="form-select form-select-sm item-order-type" onchange="recalcRow(this)">
                        <option value="Bundle" <?php echo $isBundle ? 'selected' : ''; ?>>Bundle</option>
                        <option value="Piece" <?php echo !$isBundle ? 'selected' : ''; ?>>Piece / Loose</option>
                      </select>
                    </td>
                    <td class="text-center">
                      <input type="number" name="items[<?php echo $idx; ?>][pieces_per_bundle]" class="form-control form-control-sm text-center item-ppb" min="1" value="<?php echo $ppb; ?>" oninput="recalcRow(this)" required>
                    </td>
                    <td class="text-center">
                      <input type="number" name="items[<?php echo $idx; ?>][quantity]" class="form-control form-control-sm text-center fw-bold item-qty" min="1" step="1" value="<?php echo $qty; ?>" oninput="recalcRow(this)" onchange="recalcRow(this)" required>
                    </td>
                    <td class="text-center">
                      <span class="fw-bold text-navy item-total-pieces-label"><?php echo number_format($totPcs); ?></span>
                    </td>
                    <td>
                      <input type="number" step="0.01" min="0.01" name="items[<?php echo $idx; ?>][unit_price]" class="form-control form-control-sm text-end fw-semibold item-unit-price" value="<?php echo number_format($unitP, 2, '.', ''); ?>" oninput="recalcRow(this)" required>
                      <div class="small text-muted text-end item-price-caption mt-1"></div>
                    </td>
                    <td>
                      <input type="number" step="0.01" min="0" name="items[<?php echo $idx; ?>][discount]" class="form-control form-control-sm text-end item-discount" value="<?php echo number_format($disc, 2, '.', ''); ?>" oninput="recalcRow(this)">
                    </td>
                    <td class="text-center">
                      <span class="badge bg-info-subtle text-primary border border-primary-subtle fw-bold"><?php echo (float)$gstR; ?>%</span>
                    </td>
                    <td class="text-end">
                      <span class="fw-bold text-navy fs-6 item-line-total-label">₹<?php echo number_format($lineTot, 2); ?></span>
                    </td>
                    <td class="text-center" id="item_badge_col_<?php echo $idx; ?>">
                      <?php echo render_quotation_item_status_badge($itemStatus, $isCustomerRemoved); ?>
                    </td>
                    <td class="text-center">
                      <?php if ($isCustomerRemoved): ?>
                        <span class="badge bg-light text-muted border fst-italic">Excluded</span>
                      <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center gap-1">
                          <button type="button" class="btn btn-sm <?php echo ($itemStatus === 'Accepted') ? 'btn-success text-white' : 'btn-outline-success'; ?> px-2 py-1 fw-bold" onclick="quickSetItemStatus(<?php echo (int)($it['id'] ?? 0); ?>, <?php echo $idx; ?>, 'Accepted')" title="Accept this product">
                            <i class="bi bi-check-lg"></i> Accept
                          </button>
                          <button type="button" class="btn btn-sm <?php echo ($itemStatus === 'Declined') ? 'btn-danger text-white' : 'btn-outline-danger'; ?> px-2 py-1 fw-bold" onclick="quickSetItemStatus(<?php echo (int)($it['id'] ?? 0); ?>, <?php echo $idx; ?>, 'Declined')" title="Decline this product">
                            <i class="bi bi-x-lg"></i> Decline
                          </button>
                          <button type="button" class="btn btn-outline-secondary btn-sm p-1" onclick="removeRow(this)" title="Remove item row">
                            <i class="bi bi-trash"></i>
                          </button>
                        </div>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Left Bottom: Terms & Notes | Right Bottom: Calculations & Grand Total -->
    <div class="col-lg-7">
      <div class="bg-white p-4 rounded-3 border shadow-sm h-100">
        <h5 class="fw-bold text-navy mb-3">
          <i class="bi bi-card-text text-gold me-1"></i> Admin Notes &amp; Quotation Terms
        </h5>

        <div class="mb-3">
          <label class="form-label small fw-semibold text-navy">Message / Note to Customer</label>
          <textarea name="admin_notes" class="form-control" rows="3" placeholder="Add custom wholesale notes, transport remarks, or discounts message..."><?php echo htmlspecialchars($admin_notes_val); ?></textarea>
          <div class="form-text small text-muted">This message is highlighted at the top of the customer's quotation view.</div>
        </div>

        <div class="mb-2">
          <label class="form-label small fw-semibold text-navy">Quotation Terms &amp; Conditions</label>
          <textarea name="terms_conditions" class="form-control" rows="5" placeholder="Enter terms of sale, pricing validity, transport conditions, payment terms..."><?php echo htmlspecialchars($terms_val); ?></textarea>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="bg-white p-4 rounded-3 border shadow-sm h-100 d-flex flex-column justify-content-between">
        <div>
          <h5 class="fw-bold text-navy mb-3 pb-2 border-bottom">
            <i class="bi bi-calculator text-gold me-1"></i> Quotation Financial Summary
          </h5>

          <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <span class="text-secondary">Subtotal (Active Items):</span>
            <span class="fw-bold text-navy fs-6" id="summarySubtotal">₹0.00</span>
          </div>

          <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <span class="text-secondary">Overall Discount (₹):</span>
            <div style="max-width: 140px;">
              <input type="number" step="0.01" min="0" name="discount" id="overallDiscountInput" class="form-control form-control-sm text-end" value="<?php echo number_format($discount_val, 2, '.', ''); ?>" oninput="recalcAll()">
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <span class="text-secondary">Delivery / Transport Fee (₹):</span>
            <div style="max-width: 140px;">
              <input type="number" step="0.01" min="0" name="delivery_fee" id="deliveryFeeInput" class="form-control form-control-sm text-end" value="<?php echo number_format($delivery_val, 2, '.', ''); ?>" oninput="recalcAll()">
            </div>
          </div>

          <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <span class="text-secondary">Net Taxable Amount:</span>
            <span class="fw-semibold text-dark" id="summaryTaxable">₹0.00</span>
          </div>

          <!-- Dynamic GST Breakdown Container -->
          <div id="summaryGstBreakdown" class="py-2 border-bottom">
            <!-- Dynamically populated via JavaScript -->
          </div>

          <div class="d-flex justify-content-between align-items-center py-2 border-bottom bg-gold-subtle p-2 rounded mt-2">
            <span class="text-navy fw-bold fs-6">Total GST Amount:</span>
            <span class="fw-bold text-navy fs-6" id="summaryTotalGst">₹0.00</span>
          </div>

          <div class="d-flex justify-content-between align-items-center py-3 mt-2 bg-navy text-white px-3 rounded-3 shadow-xs">
            <div>
              <div class="small text-gold fw-bold text-uppercase">Final Quotation Grand Total</div>
              <div class="small" style="font-size: 0.725rem; opacity: 0.8;">Inclusive of all applicable taxes</div>
            </div>
            <div class="fs-4 fw-bold text-gold" id="summaryGrandTotal">₹0.00</div>
          </div>
        </div>

        <!-- Submission Buttons -->
        <div class="pt-4 mt-3 border-top d-flex gap-2 flex-wrap">
          <button type="button" class="btn btn-outline-secondary flex-grow-1 py-2.5 fw-semibold" id="btnSaveDraft" onclick="submitFormWithAction('save_draft')">
            <i class="bi bi-save me-1"></i> Save as Draft
          </button>
          <button type="button" class="btn btn-gold flex-grow-1 py-2.5 fw-bold shadow-sm" id="btnSendQuotation" onclick="submitFormWithAction('send_quotation')">
            <i class="bi bi-send-check me-1"></i> <?php echo ($quotation_status === 'Sent' || $quotation_status === 'Change Requested' || $quotation_status === 'Declined' || $quotation_status === 'Accepted') ? 'Send Revised Quotation' : 'Send Quotation to Customer'; ?>
          </button>
          <?php if ($quotation): ?>
            <?php if ($quotation_status === 'Accepted'): ?>
              <button type="button" class="btn btn-success flex-grow-1 py-2.5 fw-bold shadow-sm" id="btnSyncInvoiceBottom" onclick="confirmSyncInvoice()">
                <i class="bi bi-file-earmark-check-fill me-1"></i> Approve &amp; Generate Invoice
              </button>
            <?php else: ?>
              <button type="button" class="btn btn-outline-secondary flex-grow-1 py-2.5 fw-semibold" id="btnSyncInvoiceBottom" disabled>
                <i class="bi bi-hourglass-split me-1"></i> Awaiting Customer Acceptance
              </button>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</form>

<!-- Quotation Revision & Audit History Accordion (Requirement 4 & 6) -->
<?php if (!empty($revisions)): ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-navy text-white d-flex justify-content-between align-items-center py-3">
      <div class="d-flex align-items-center gap-2">
        <i class="bi bi-clock-history text-gold fs-5"></i>
        <h6 class="fw-bold mb-0 text-white">Quotation Revision &amp; Audit History</h6>
      </div>
      <span class="badge bg-gold text-navy fw-bold px-2.5 py-1"><?php echo count($revisions); ?> Snapshot(s) Recorded</span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light text-navy">
            <tr>
              <th style="width: 80px;" class="text-center">Rev #</th>
              <th style="width: 170px;">Date &amp; Time</th>
              <th style="width: 120px;">Role</th>
              <th style="width: 190px;">Revision Event</th>
              <th>Audit Notes / Feedback</th>
              <th style="width: 140px;" class="text-center">Snapshot Items</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($revisions as $rev): 
              $revItems = json_decode($rev['items_snapshot'] ?? '[]', true) ?: [];
              $revTotals = json_decode($rev['totals_snapshot'] ?? '[]', true) ?: [];
            ?>
              <tr>
                <td class="text-center fw-bold font-monospace">
                  <span class="badge bg-light text-dark border">v<?php echo (int)$rev['revision_number']; ?></span>
                </td>
                <td class="small text-muted">
                  <?php echo format_datetime($rev['created_at']); ?>
                </td>
                <td>
                  <?php if ($rev['created_by_role'] === 'admin'): ?>
                    <span class="badge bg-navy text-gold fw-bold"><i class="bi bi-shield-check me-1"></i> Admin</span>
                  <?php else: ?>
                    <span class="badge bg-primary text-white fw-bold"><i class="bi bi-person-circle me-1"></i> Customer</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php 
                  switch ($rev['revision_type']) {
                      case 'final_approval':
                          echo '<span class="badge bg-success text-white"><i class="bi bi-check-all me-1"></i> Final Approval & Sync</span>';
                          break;
                      case 'customer_change_request':
                          echo '<span class="badge bg-warning text-dark"><i class="bi bi-pencil-square me-1"></i> Customer Change Request</span>';
                          break;
                      case 'admin_review':
                          echo '<span class="badge bg-info text-dark"><i class="bi bi-eye me-1"></i> Product-Wise Review</span>';
                          break;
                      case 'admin_update':
                          echo '<span class="badge bg-secondary text-white"><i class="bi bi-arrow-repeat me-1"></i> Admin Resent Quotation</span>';
                          break;
                      default:
                          echo '<span class="badge bg-light text-secondary border">Initial Draft</span>';
                          break;
                  }
                  ?>
                </td>
                <td class="small text-dark">
                  <?php echo htmlspecialchars($rev['notes'] ?: 'No notes provided.'); ?>
                </td>
                <td class="text-center">
                  <button type="button" class="btn btn-outline-navy btn-sm py-1 px-2.5" data-bs-toggle="collapse" data-bs-target="#revCollapse_<?php echo $rev['id']; ?>">
                    <i class="bi bi-list-nested me-1"></i> View (<?php echo count($revItems); ?>)
                  </button>
                </td>
              </tr>
              <tr class="collapse" id="revCollapse_<?php echo $rev['id']; ?>">
                <td colspan="6" class="p-3 bg-light border-bottom">
                  <div class="p-3 bg-white rounded border">
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                      <strong class="text-navy small">Items in Snapshot Revision #<?php echo $rev['revision_number']; ?></strong>
                      <span class="badge bg-gold-subtle text-navy border">Total: ₹<?php echo number_format((float)($revTotals['grand_total'] ?? 0), 2); ?></span>
                    </div>
                    <div class="table-responsive">
                      <table class="table table-sm table-bordered align-middle small mb-0">
                        <thead class="table-light">
                          <tr>
                            <th>Product</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Total</th>
                            <th class="text-center">Item Decision</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($revItems as $rItm): ?>
                            <tr>
                              <td><?php echo htmlspecialchars($rItm['product_name'] ?? ''); ?> (<?php echo htmlspecialchars($rItm['variety'] ?? ''); ?>)</td>
                              <td class="text-center"><?php echo (int)($rItm['quantity'] ?? 1); ?></td>
                              <td class="text-end">₹<?php echo number_format((float)($rItm['unit_price'] ?? 0), 2); ?></td>
                              <td class="text-end font-monospace">₹<?php echo number_format((float)($rItm['total_price'] ?? 0), 2); ?></td>
                              <td class="text-center">
                                <?php echo render_quotation_item_status_badge($rItm['item_status'] ?? 'Pending', !empty($rItm['is_customer_removed'])); ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Modal: Add Product from Catalog -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-labelledby="addProductModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content rounded-3 border-0 shadow">
      <div class="modal-header bg-navy text-white">
        <h5 class="modal-title fw-bold" id="addProductModalLabel">
          <i class="bi bi-journal-plus text-gold me-2"></i> Add Notebook Product from Catalog
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <div class="mb-3">
          <label class="form-label fw-semibold text-navy">1. Select Notebook Product</label>
          <select id="modalProductSelect" class="form-select" onchange="onModalProductSelect()">
            <option value="">-- Choose a Product --</option>
            <?php foreach ($catalog_products as $cp): ?>
              <option value="<?php echo $cp['id']; ?>" 
                      data-name="<?php echo htmlspecialchars($cp['name']); ?>"
                      data-sku="<?php echo htmlspecialchars($cp['sku']); ?>"
                      data-price="<?php echo (float)$cp['price']; ?>"
                      data-sale-price="<?php echo (float)($cp['sale_price'] ?? $cp['price']); ?>"
                      data-pages="<?php echo (int)$cp['pages']; ?>"
                      data-std-qty="<?php echo (int)($cp['std_qty'] ?? 1); ?>"
                      data-gst="<?php echo (float)$cp['gst_rate']; ?>">
                <?php echo htmlspecialchars($cp['name']); ?> (<?php echo htmlspecialchars($cp['sku']); ?>) — <?php echo htmlspecialchars($cp['pages']); ?> Pages — ₹<?php echo number_format($cp['sale_price'] ?: $cp['price'], 2); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="mb-3" id="modalVariantContainer" style="display: none;">
          <label class="form-label fw-semibold text-navy">2. Select Ruling / Variety</label>
          <select id="modalVariantSelect" class="form-select" onchange="onModalVariantSelect()">
            <!-- Populated via JS -->
          </select>
        </div>

        <div class="row g-3" id="modalDetailsContainer" style="display: none;">
          <div class="col-md-4">
            <label class="form-label small fw-semibold text-navy">Order Type</label>
            <select id="modalOrderType" class="form-select form-select-sm" onchange="onModalOrderTypeChange()">
              <option value="Bundle">Bundle</option>
              <option value="Piece">Individual Piece</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold text-navy">Pieces per Bundle</label>
            <input type="number" id="modalPpb" class="form-control form-control-sm text-center" min="1" value="1" onchange="onModalPpbChange()" oninput="onModalPpbChange()">
          </div>
          <div class="col-md-4">
            <label class="form-label small fw-semibold text-navy">Quantity</label>
            <input type="number" id="modalQty" class="form-control form-control-sm text-center" min="1" value="1">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold text-navy">Unit / Wholesale Price (₹)</label>
            <input type="number" step="0.01" min="0.01" id="modalPrice" class="form-control form-control-sm text-end" value="0.00">
          </div>
          <div class="col-md-6">
            <label class="form-label small fw-semibold text-navy">GST Rate (%)</label>
            <input type="number" step="0.01" id="modalGst" class="form-control form-control-sm text-center" readonly style="background-color: #f8f9fa;">
          </div>
        </div>
      </div>
      <div class="modal-footer bg-light">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-gold fw-bold px-4" id="modalBtnAdd" onclick="addProductToTable()" disabled>
          <i class="bi bi-plus-lg me-1"></i> Add to Quotation
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Catalog data injected safely
const catalogVariantsByProduct = <?php echo json_encode($variants_by_product, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let itemRowIndexCounter = <?php echo count($items); ?>;

function onModalProductSelect() {
    const sel = document.getElementById('modalProductSelect');
    const pid = sel.value;
    const vContainer = document.getElementById('modalVariantContainer');
    const vSelect = document.getElementById('modalVariantSelect');
    const detContainer = document.getElementById('modalDetailsContainer');
    const btnAdd = document.getElementById('modalBtnAdd');

    if (!pid) {
        vContainer.style.display = 'none';
        detContainer.style.display = 'none';
        btnAdd.disabled = true;
        return;
    }

    const opt = sel.options[sel.selectedIndex];
    const stdQty = parseInt(opt.getAttribute('data-std-qty') || 1, 10);
    const salePrice = parseFloat(opt.getAttribute('data-sale-price') || 0);
    const gstRate = parseFloat(opt.getAttribute('data-gst') || 18.0);

    const variants = catalogVariantsByProduct[pid] || [];
    vSelect.innerHTML = '';

    if (variants.length > 0) {
        vSelect.innerHTML = '<option value="">-- Choose Ruling / Variety --</option>';
        variants.forEach(v => {
            const vOpt = document.createElement('option');
            vOpt.value = v.id;
            vOpt.textContent = `${v.variety_display || v.variety} (${v.product_code}) - Bundle: ${v.bundle_qty || stdQty} Pcs`;
            vOpt.setAttribute('data-code', v.product_code || '');
            vOpt.setAttribute('data-variety', v.variety_display || v.variety || '');
            vOpt.setAttribute('data-bundle-qty', v.bundle_qty || stdQty);
            vOpt.setAttribute('data-pages', v.page_count || opt.getAttribute('data-pages'));
            vSelect.appendChild(vOpt);
        });
        vContainer.style.display = 'block';
    } else {
        vContainer.style.display = 'none';
    }

    document.getElementById('modalPpb').value = stdQty;
    document.getElementById('modalQty').value = 1;
    // Bundle price default if bundle
    const bundlePrice = (salePrice * stdQty).toFixed(2);
    document.getElementById('modalPrice').value = bundlePrice;
    document.getElementById('modalGst').value = gstRate;

    detContainer.style.display = 'flex';
    btnAdd.disabled = (variants.length > 0);
}

function onModalVariantSelect() {
    const vSel = document.getElementById('modalVariantSelect');
    const btnAdd = document.getElementById('modalBtnAdd');
    const pSel = document.getElementById('modalProductSelect');
    const pOpt = pSel.options[pSel.selectedIndex];
    const salePrice = parseFloat(pOpt.getAttribute('data-sale-price') || 0);

    if (vSel.value) {
        const vOpt = vSel.options[vSel.selectedIndex];
        const bQty = parseInt(vOpt.getAttribute('data-bundle-qty') || 1, 10);
        document.getElementById('modalPpb').value = bQty;
        if (document.getElementById('modalOrderType').value === 'Piece') {
            document.getElementById('modalPpb').value = '1';
            document.getElementById('modalPpb').readOnly = true;
            document.getElementById('modalPrice').value = salePrice.toFixed(2);
        } else {
            document.getElementById('modalPpb').readOnly = false;
            document.getElementById('modalPrice').value = (salePrice * bQty).toFixed(2);
        }
        btnAdd.disabled = false;
    } else {
        btnAdd.disabled = true;
    }
}

function onModalOrderTypeChange() {
    const orderType = document.getElementById('modalOrderType').value;
    const ppbInput = document.getElementById('modalPpb');
    const priceInput = document.getElementById('modalPrice');
    const productSelect = document.getElementById('modalProductSelect');
    const productOption = productSelect.options[productSelect.selectedIndex];
    const salePrice = parseFloat(productOption ? productOption.getAttribute('data-sale-price') : 0) || 0;

    if (orderType === 'Piece') {
        ppbInput.value = '1';
        ppbInput.readOnly = true;
        priceInput.value = salePrice.toFixed(2);
    } else {
        ppbInput.readOnly = false;
        const ppb = Math.max(1, parseInt(ppbInput.value || 1, 10));
        priceInput.value = (salePrice * ppb).toFixed(2);
    }
}

function onModalPpbChange() {
    if (document.getElementById('modalOrderType').value !== 'Bundle') return;
    const ppb = Math.max(1, parseInt(document.getElementById('modalPpb').value || 1, 10));
    const productSelect = document.getElementById('modalProductSelect');
    const productOption = productSelect.options[productSelect.selectedIndex];
    const salePrice = parseFloat(productOption ? productOption.getAttribute('data-sale-price') : 0) || 0;
    document.getElementById('modalPrice').value = (salePrice * ppb).toFixed(2);
}

function addProductToTable() {
    const pSel = document.getElementById('modalProductSelect');
    if (!pSel.value) return;

    const pOpt = pSel.options[pSel.selectedIndex];
    const prodId = pSel.value;
    const prodName = pOpt.getAttribute('data-name');
    const pages = pOpt.getAttribute('data-pages') || 192;
    const gstRate = parseFloat(pOpt.getAttribute('data-gst') || 18.0);

    const vSel = document.getElementById('modalVariantSelect');
    let variantId = '';
    let prodCode = pOpt.getAttribute('data-sku') || '';
    let variety = 'Unruled';

    if (vSel && vSel.value) {
        const vOpt = vSel.options[vSel.selectedIndex];
        variantId = vSel.value;
        prodCode = vOpt.getAttribute('data-code') || prodCode;
        variety = vOpt.getAttribute('data-variety') || variety;
    }

    const orderType = document.getElementById('modalOrderType').value;
    const ppb = parseInt(document.getElementById('modalPpb').value || 1, 10);
    const qty = parseInt(document.getElementById('modalQty').value || 1, 10);
    const unitPrice = parseFloat(document.getElementById('modalPrice').value || 0);

    const noRow = document.getElementById('noItemsRow');
    if (noRow) noRow.remove();

    const tbody = document.getElementById('itemsTableBody');
    const idx = itemRowIndexCounter++;

    const isBundle = (orderType === 'Bundle');
    const totalPcs = isBundle ? (qty * ppb) : qty;
    const lineTotal = (unitPrice * qty).toFixed(2);

    const tr = document.createElement('tr');
    tr.className = 'quotation-item-row';
    tr.setAttribute('data-index', idx);
    tr.setAttribute('data-customer-removed', '0');
    tr.innerHTML = `
        <td>
            <input type="hidden" name="items[${idx}][item_id]" value="0">
            <input type="hidden" name="items[${idx}][product_id]" value="${escapeHtml(prodId)}">
            <input type="hidden" name="items[${idx}][variant_id]" value="${escapeHtml(variantId)}">
            <input type="hidden" name="items[${idx}][product_code]" value="${escapeHtml(prodCode)}">
            <input type="hidden" name="items[${idx}][variety]" value="${escapeHtml(variety)}">
            <input type="hidden" name="items[${idx}][ruling_type]" value="${escapeHtml(variety)}">
            <input type="hidden" name="items[${idx}][page_count]" value="${escapeHtml(pages)}">
            <input type="hidden" name="items[${idx}][gst_rate]" class="item-gst-rate" value="${gstRate}">
            <input type="hidden" name="items[${idx}][item_status]" id="item_status_${idx}" value="Pending">
            <input type="hidden" name="items[${idx}][original_quantity]" value="${qty}">
            <input type="hidden" name="items[${idx}][customer_requested_quantity]" value="">
            <input type="hidden" name="items[${idx}][customer_action]" value="none">
            <input type="hidden" name="items[${idx}][is_customer_removed]" value="0">
            <input type="hidden" name="items[${idx}][admin_comment]" id="admin_comment_${idx}" value="">

            <input type="text" name="items[${idx}][product_name]" class="form-control form-control-sm fw-bold text-navy mb-1" value="${escapeHtml(prodName)}" required>
            <div class="small d-flex align-items-center gap-1.5 flex-wrap">
                <span class="badge bg-light text-secondary border font-monospace">${escapeHtml(prodCode)}</span>
                <span class="badge bg-gold-subtle text-navy border border-warning-subtle">${escapeHtml(variety)}</span>
                <span class="badge bg-light text-dark border">${escapeHtml(pages)} Pages</span>
            </div>
        </td>
        <td>
            <select name="items[${idx}][order_type]" class="form-select form-select-sm item-order-type" onchange="recalcRow(this)">
                <option value="Bundle" ${isBundle ? 'selected' : ''}>Bundle</option>
                <option value="Piece" ${!isBundle ? 'selected' : ''}>Piece / Loose</option>
            </select>
        </td>
        <td class="text-center">
            <input type="number" name="items[${idx}][pieces_per_bundle]" class="form-control form-control-sm text-center item-ppb" min="1" value="${ppb}" oninput="recalcRow(this)" required>
        </td>
        <td class="text-center">
            <input type="number" name="items[${idx}][quantity]" class="form-control form-control-sm text-center fw-bold item-qty" min="1" step="1" value="${qty}" oninput="recalcRow(this)" onchange="recalcRow(this)" required>
        </td>
        <td class="text-center">
            <span class="fw-bold text-navy item-total-pieces-label">${totalPcs.toLocaleString()}</span>
        </td>
        <td>
            <input type="number" step="0.01" min="0.01" name="items[${idx}][unit_price]" class="form-control form-control-sm text-end fw-semibold item-unit-price" value="${unitPrice.toFixed(2)}" oninput="recalcRow(this)" required>
            <div class="small text-muted text-end item-price-caption mt-1"></div>
        </td>
        <td>
            <input type="number" step="0.01" min="0" name="items[${idx}][discount]" class="form-control form-control-sm text-end item-discount" value="0.00" oninput="recalcRow(this)">
        </td>
        <td class="text-center">
            <span class="badge bg-info-subtle text-primary border border-primary-subtle fw-bold">${gstRate}%</span>
        </td>
        <td class="text-end">
            <span class="fw-bold text-navy fs-6 item-line-total-label">₹${lineTotal}</span>
        </td>
        <td class="text-center" id="item_badge_col_${idx}">
            <span class="badge bg-warning-subtle text-warning border border-warning-subtle"><i class="bi bi-hourglass-split me-1"></i> Pending</span>
        </td>
        <td class="text-center">
            <div class="d-flex align-items-center justify-content-center gap-1">
                <button type="button" class="btn btn-sm btn-outline-success px-2 py-1 fw-bold" onclick="quickSetItemStatus(0, ${idx}, 'Accepted')" title="Accept this product">
                    <i class="bi bi-check-lg"></i> Accept
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger px-2 py-1 fw-bold" onclick="quickSetItemStatus(0, ${idx}, 'Declined')" title="Decline this product">
                    <i class="bi bi-x-lg"></i> Decline
                </button>
                <button type="button" class="btn btn-outline-secondary btn-sm p-1" onclick="removeRow(this)" title="Remove item row">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </td>
    `;
    tr.dataset.lastOrderType = orderType;
    tr.dataset.bundlePpb = String(isBundle ? ppb : 1);
    tr.dataset.bundleUnitPrice = unitPrice.toFixed(2);
    tr.dataset.piecePrice = (isBundle && ppb > 0
        ? (unitPrice / ppb).toFixed(2)
        : unitPrice.toFixed(2));
    tbody.appendChild(tr);

    // Close modal
    const modalEl = document.getElementById('addProductModal');
    const modalInst = bootstrap.Modal.getInstance(modalEl);
    if (modalInst) modalInst.hide();

    // Reset modal fields
    pSel.value = '';
    onModalProductSelect();

    recalcAll();
}

function quickSetItemStatus(itemId, idx, newStatus) {
    const quotationId = <?php echo (int)($quotation['id'] ?? 0); ?>;
    
    // If not yet saved in database, simply update in-memory input and badge
    if (!itemId || !quotationId) {
        const statusInput = document.getElementById('item_status_' + idx);
        if (statusInput) statusInput.value = newStatus;
        const badgeCol = document.getElementById('item_badge_col_' + idx);
        if (badgeCol) {
            badgeCol.innerHTML = (newStatus === 'Accepted') 
                ? '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="bi bi-check-circle me-1"></i> Accepted</span>'
                : '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="bi bi-x-circle me-1"></i> Declined</span>';
        }
        return;
    }

    // Direct status update form submission
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'quotation.php?id=' + quotationId;

    const fields = {
        action: 'update_item_status',
        quotation_id: quotationId,
        item_id: itemId,
        item_status: newStatus,
        admin_comment: ''
    };

    for (const key in fields) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = key;
        input.value = fields[key];
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
}

function confirmSyncInvoice() {
    const rows = document.querySelectorAll('.quotation-item-row');
    let hasPending = false;
    let acceptedCount = 0;

    rows.forEach(r => {
        const statusInput = r.querySelector('input[name*="[item_status]"]');
        const isRemovedInput = r.querySelector('input[name*="[is_customer_removed]"]');
        const isRemoved = isRemovedInput && (isRemovedInput.value === '1');
        const status = statusInput ? statusInput.value : 'Pending';

        if (!isRemoved) {
            if (status === 'Pending' || status === 'Change Requested') {
                hasPending = true;
            } else if (status === 'Accepted') {
                acceptedCount++;
            }
        }
    });

    if (hasPending) {
        alert('Cannot generate invoice yet: Some products are still marked as Pending or Change Requested. Please Accept or Decline all active products first.');
        return;
    }
    if (acceptedCount === 0) {
        alert('Cannot generate invoice: No products have been Accepted.');
        return;
    }

    if (confirm(`Approve quotation and generate the official Tax Invoice with ${acceptedCount} accepted product(s)?\n\nDeclined and customer-removed products will be excluded from the final invoice.`)) {
        const quotationId = <?php echo (int)($quotation['id'] ?? 0); ?>;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'quotation.php?id=' + quotationId;

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'sync_final_invoice';
        form.appendChild(actionInput);

        const qIdInput = document.createElement('input');
        qIdInput.type = 'hidden';
        qIdInput.name = 'quotation_id';
        qIdInput.value = quotationId;
        form.appendChild(qIdInput);

        document.body.appendChild(form);
        form.submit();
    }
}

function removeRow(btn) {
    const row = btn.closest('tr');
    const allRows = document.querySelectorAll('.quotation-item-row');
    if (allRows.length <= 1) {
        alert('A quotation must contain at least one product item.');
        return;
    }
    if (confirm('Remove this product item from the quotation?')) {
        row.remove();
        recalcAll();
    }
}

function recalcRow(inputElem) {
    const row = inputElem.closest('tr');
    const orderTypeSelect = row.querySelector('.item-order-type');
    const ppbInput = row.querySelector('.item-ppb');
    const unitPriceInput = row.querySelector('.item-unit-price');
    const qtyInput = row.querySelector('.item-qty');
    const orderType = orderTypeSelect.value;
    let ppb = Math.max(1, parseInt(ppbInput.value || 1, 10));
    const typedQty = parseInt(qtyInput.value || 1, 10);
    const qty = Number.isFinite(typedQty) ? Math.max(1, typedQty) : 1;
    let unitPrice = Math.max(0, parseFloat(unitPriceInput.value || 0));
    const disc = Math.max(0, parseFloat(row.querySelector('.item-discount').value || 0));
    const changedField = inputElem.classList.contains('item-ppb') ? 'ppb'
        : inputElem.classList.contains('item-unit-price') ? 'unit'
        : inputElem.classList.contains('item-qty') ? 'qty'
        : 'type';

    const previousOrderType = row.dataset.lastOrderType || orderType;
    let piecePrice = Math.max(0, parseFloat(row.dataset.piecePrice || 0));
    if (!piecePrice) {
        piecePrice = orderType === 'Bundle'
            ? (ppb > 0 ? unitPrice / ppb : unitPrice)
            : unitPrice;
    }

    if (previousOrderType !== orderType) {
        if (orderType === 'Piece') {
            ppbInput.value = '1';
            ppb = 1;
            unitPriceInput.value = piecePrice.toFixed(2);
            unitPrice = piecePrice;
        } else {
            const savedBundlePpb = Math.max(1, parseInt(row.dataset.bundlePpb || ppb, 10));
            ppbInput.value = String(savedBundlePpb);
            ppb = savedBundlePpb;
            unitPrice = piecePrice * ppb;
            unitPriceInput.value = unitPrice.toFixed(2);
        }
        row.dataset.lastOrderType = orderType;
    }

    if (orderType === 'Piece') {
        ppbInput.value = '1';
        ppb = 1;
        ppbInput.readOnly = true;
        if (changedField === 'unit') {
            piecePrice = unitPrice;
        } else {
            unitPrice = piecePrice;
            unitPriceInput.value = piecePrice.toFixed(2);
        }
    } else {
        ppbInput.readOnly = false;
        if (changedField === 'unit') {
            piecePrice = ppb > 0 ? unitPrice / ppb : unitPrice;
        } else if (changedField === 'ppb' || parseInt(row.dataset.bundlePpb || ppb, 10) !== ppb) {
            unitPrice = piecePrice * ppb;
            unitPriceInput.value = unitPrice.toFixed(2);
        }
    }

    row.dataset.piecePrice = piecePrice.toFixed(2);
    row.dataset.bundlePpb = String(ppb);
    row.dataset.bundleUnitPrice = unitPrice.toFixed(2);
    const priceCaption = row.querySelector('.item-price-caption');
    if (priceCaption) {
        priceCaption.textContent = orderType === 'Bundle'
            ? 'Piece: ₹' + piecePrice.toFixed(2)
            : 'Piece Price';
    }

    const totalPcs = (orderType === 'Bundle') ? (qty * ppb) : qty;
    const lineTotal = Math.max(0, (unitPrice * qty) - disc);

    row.querySelector('.item-total-pieces-label').textContent = totalPcs.toLocaleString();
    row.querySelector('.item-line-total-label').textContent = '₹' + lineTotal.toFixed(2);

    recalcAll();
}

function recalcAll() {
    const rows = document.querySelectorAll('.quotation-item-row');
    let rawSubtotal = 0;
    const rowData = [];

    rows.forEach(row => {
        const qty = Math.max(1, parseInt(row.querySelector('.item-qty').value || 1, 10));
        const unitPrice = Math.max(0, parseFloat(row.querySelector('.item-unit-price').value || 0));
        const disc = Math.max(0, parseFloat(row.querySelector('.item-discount').value || 0));
        const gstRate = parseFloat(row.querySelector('.item-gst-rate').value || 18.0);
        const lineTotal = Math.max(0, (unitPrice * qty) - disc);

        rawSubtotal += lineTotal;
        rowData.push({
            lineTotal: lineTotal,
            gstRate: gstRate
        });
    });

    const overallDiscount = Math.max(0, parseFloat(document.getElementById('overallDiscountInput').value || 0));
    const deliveryFee = Math.max(0, parseFloat(document.getElementById('deliveryFeeInput').value || 0));

    let totalTaxable = 0;
    let totalGst = 0;
    const ratesBreakdown = {};

    rowData.forEach(item => {
        let lineTaxable = item.lineTotal;
        if (overallDiscount > 0 && rawSubtotal > 0) {
            const proratedDisc = (item.lineTotal / rawSubtotal) * overallDiscount;
            lineTaxable = Math.max(0, item.lineTotal - proratedDisc);
        }

        const rateKey = item.gstRate.toFixed(2);
        const itemGst = (lineTaxable * item.gstRate) / 100;
        const itemCgst = itemGst / 2;
        const itemSgst = itemGst - itemCgst;

        if (!ratesBreakdown[rateKey]) {
            ratesBreakdown[rateKey] = {
                rate: item.gstRate,
                taxable: 0,
                gst: 0,
                cgst: 0,
                sgst: 0
            };
        }

        ratesBreakdown[rateKey].taxable += lineTaxable;
        ratesBreakdown[rateKey].gst += itemGst;
        ratesBreakdown[rateKey].cgst += itemCgst;
        ratesBreakdown[rateKey].sgst += itemSgst;

        totalTaxable += lineTaxable;
        totalGst += itemGst;
    });

    const grandTotal = Math.max(0, totalTaxable + totalGst + deliveryFee);

    document.getElementById('summarySubtotal').textContent = '₹' + rawSubtotal.toFixed(2);
    document.getElementById('summaryTaxable').textContent = '₹' + totalTaxable.toFixed(2);
    document.getElementById('summaryTotalGst').textContent = '₹' + totalGst.toFixed(2);
    document.getElementById('summaryGrandTotal').textContent = '₹' + grandTotal.toFixed(2);

    // Build dynamic rate breakdown HTML
    const gstBreakdownContainer = document.getElementById('summaryGstBreakdown');
    let breakdownHtml = '';
    const sortedRateKeys = Object.keys(ratesBreakdown).sort((a,b) => parseFloat(b) - parseFloat(a));

    sortedRateKeys.forEach(key => {
        const r = ratesBreakdown[key];
        const halfRate = (r.rate / 2);
        breakdownHtml += `
            <div class="small text-muted d-flex justify-content-between my-1">
                <span>CGST (${halfRate}% on ₹${r.taxable.toFixed(2)}):</span>
                <span class="font-monospace">₹${r.cgst.toFixed(2)}</span>
            </div>
            <div class="small text-muted d-flex justify-content-between my-1">
                <span>SGST (${halfRate}% on ₹${r.taxable.toFixed(2)}):</span>
                <span class="font-monospace">₹${r.sgst.toFixed(2)}</span>
            </div>
        `;
    });

    if (sortedRateKeys.length === 0) {
        breakdownHtml = '<div class="small text-muted fst-italic">No taxable items</div>';
    }
    gstBreakdownContainer.innerHTML = breakdownHtml;
}

function submitFormWithAction(actionName) {
    const rows = document.querySelectorAll('.quotation-item-row');
    if (rows.length === 0) {
        alert('Please add at least one product to the quotation before saving.');
        return;
    }

    if (actionName === 'send_quotation') {
        const isConfirm = confirm('Send this finalized quotation to the customer? It will become visible in their account dashboard for review and acceptance.');
        if (!isConfirm) return;
    }

    document.getElementById('formActionField').value = actionName;

    // Prevent duplicate submissions and show loading state
    const btnDraft = document.getElementById('btnSaveDraft');
    const btnSend = document.getElementById('btnSendQuotation');
    if (btnDraft) btnDraft.disabled = true;
    if (btnSend) {
        btnSend.disabled = true;
        btnSend.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Saving & Sending...';
    }

    document.getElementById('quotationForm').submit();
}

function handleFormSubmit(e) {
    // Normal browser validation
    const form = document.getElementById('quotationForm');
    if (!form.checkValidity()) {
        const btnDraft = document.getElementById('btnSaveDraft');
        const btnSend = document.getElementById('btnSendQuotation');
        if (btnDraft) btnDraft.disabled = false;
        if (btnSend) {
            btnSend.disabled = false;
            btnSend.innerHTML = '<i class="bi bi-send-check me-1"></i> Send Quotation';
        }
        return true;
    }
    return true;
}

function escapeHtml(text) {
    if (!text) return '';
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

// Initial calculation on page load
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.quotation-item-row').forEach(row => {
        const orderTypeSelect = row.querySelector('.item-order-type');
        const ppbInput = row.querySelector('.item-ppb');
        const unitPriceInput = row.querySelector('.item-unit-price');
        if (!orderTypeSelect || !ppbInput || !unitPriceInput) return;

        const initialOrderType = orderTypeSelect.value;
        const initialPpb = Math.max(1, parseInt(ppbInput.value || 1, 10));
        const initialUnitPrice = Math.max(0, parseFloat(unitPriceInput.value || 0));

        row.dataset.lastOrderType = initialOrderType;
        row.dataset.bundlePpb = String(initialOrderType === 'Piece' ? 1 : initialPpb);
        row.dataset.bundleUnitPrice = initialUnitPrice.toFixed(2);
        row.dataset.piecePrice = (initialOrderType === 'Piece'
            ? initialUnitPrice
            : (initialPpb > 0 ? initialUnitPrice / initialPpb : initialUnitPrice)).toFixed(2);

        if (initialOrderType === 'Piece') {
            ppbInput.value = '1';
            ppbInput.readOnly = true;
        }
        recalcRow(orderTypeSelect);

    });
    recalcAll();
});
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
