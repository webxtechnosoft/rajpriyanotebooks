<?php
require_once __DIR__ . '/includes/functions.php';

// Protect Checkout: Require Customer Login
if (is_admin_logged_in() && !is_customer_logged_in()) {
    set_flash('info', 'Customer checkout is reserved for customer accounts.');
    header("Location: admin/index.php");
    exit();
}

if (!is_customer_logged_in()) {
    $_SESSION['redirect_url'] = 'checkout.php';
    header("Location: login.php?redirect=checkout.php");
    exit();
}

$items = get_cart_contents();
if (empty($items)) {
    header("Location: cart.php");
    exit();
}

$totals = calculate_cart_totals();
$db = getDB();

$current_customer = get_current_customer();
$has_saved_address = !empty($current_customer['address']) && !empty($current_customer['city']) && !empty($current_customer['pincode']);

$errors = [];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $email_raw = trim($_POST['email'] ?? '');
    $email = !empty($email_raw) ? sanitize($email_raw) : null;

    $address = sanitize($_POST['address'] ?? '');
    $address_line2 = sanitize($_POST['address_line2'] ?? '');
    $area = sanitize($_POST['area'] ?? '');
    $city = sanitize($_POST['city'] ?? '');
    $district = sanitize($_POST['district'] ?? '');
    $state = sanitize($_POST['state'] ?? 'Tamil Nadu');
    $pincode = sanitize($_POST['pincode'] ?? '');
    $landmark = sanitize($_POST['landmark'] ?? '');

    // GST Registration Option
    $gst_registration = trim($_POST['gst_registration'] ?? 'Not Registered');
    $gst_status = (strcasecmp($gst_registration, 'Registered') === 0) ? 'Registered' : 'Not Registered';

    if ($gst_status === 'Registered') {
        $gst_raw = strtoupper(trim($_POST['gst_number'] ?? ''));
        if (empty($gst_raw)) {
            $errors[] = "GSTIN Number is mandatory when Registered is selected.";
        } elseif (!is_valid_gst_number($gst_raw)) {
            $errors[] = "Please enter a valid 15-character Indian GSTIN (e.g. 33AAAAA0000A1Z5).";
        } else {
            $gst_number = $gst_raw;
        }
    } else {
        $gst_status = 'Not Registered';
        $gst_number = null; // Do not retain stale GSTIN
    }

    $payment_method = sanitize($_POST['payment_method'] ?? 'Cash On Delivery');
    $save_address = isset($_POST['save_address']) && $_POST['save_address'] == '1';

    // Validation: Name, Phone, Address, City, State, Pincode are mandatory. Email is OPTIONAL.
    if (empty($name) || empty($phone) || empty($address) || empty($city) || empty($state) || empty($pincode)) {
        $errors[] = "Please fill in all mandatory shipping fields.";
    }

    // Validate Email if provided
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address or leave the field blank.";
    }

    if (empty($errors)) {
        $order_number = generate_order_number();
        $logged_user_id = is_customer_logged_in() ? (int)$_SESSION['customer_id'] : null;

        $logged_user = $logged_user_id ? get_current_customer() : null;
        $customer_type = !empty($logged_user['customer_type']) ? $logged_user['customer_type'] : 'Retailer';

        // Requirement 1: Identify existing customer or create unique Customer ID
        $cust_data = [
            'name'          => $name,
            'phone'         => $phone,
            'email'         => $email,
            'address'       => $address,
            'address_line2' => $address_line2,
            'area'          => $area,
            'city'          => $city,
            'district'      => $district,
            'customer_type' => $customer_type,
            'state'         => $state,
            'pincode'       => $pincode,
            'landmark'      => $landmark,
            'gst_status'    => $gst_status,
            'gst_number'    => $gst_number
        ];
        $customer_result = get_or_create_customer_id($cust_data, $logged_user_id);
        $user_id = $customer_result['user_id'];
        $customer_code = $customer_result['customer_code'];
        $cust_type_final = $customer_result['customer_type'] ?? $customer_type;

        $db->beginTransaction();
        try {
            // Insert Order with Customer ID, address, GST registration status and GSTIN snapshot
            $stmt = $db->prepare("
                INSERT INTO orders (
                    order_number, user_id, customer_code, customer_type, customer_name, customer_email, customer_phone, 
                    shipping_address, address_line2, area, city, district, state, pincode, landmark, gst_status, gst_number,
                    payment_method, payment_status, order_status, 
                    subtotal, discount, delivery_fee, grand_total, total_gst, total_cgst, total_sgst
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $payment_status = ($payment_method === 'UPI / Online') ? 'Paid' : 'Pending';

            $stmt->execute([
                $order_number, $user_id, $customer_code, $cust_type_final, $name, $email, $phone,
                $address, $address_line2, $area, $city, $district, $state, $pincode, $landmark, $gst_status, $gst_number,
                $payment_method, $payment_status,
                $totals['subtotal'], $totals['discount'], $totals['delivery_fee'], $totals['grand_total'],
                $totals['total_gst'], $totals['cgst'], $totals['sgst']
            ]);

            $order_id = $db->lastInsertId();

            // Save or Update Customer Profile Address if requested
            if ($user_id && $save_address) {
                $stmt_save_profile = $db->prepare("
                    UPDATE users SET 
                        name = ?,
                        phone = ?,
                        email = COALESCE(?, email),
                        address = ?,
                        address_line2 = ?,
                        area = ?,
                        city = ?,
                        district = ?,
                        state = ?,
                        pincode = ?,
                        landmark = ?,
                        gst_status = ?,
                        gst_number = ?
                    WHERE id = ?
                ");
                $stmt_save_profile->execute([
                    $name,
                    $phone,
                    $email,
                    $address,
                    $address_line2,
                    $area,
                    $city,
                    $district,
                    $state,
                    $pincode,
                    $landmark,
                    $gst_status,
                    $gst_number,
                    $user_id
                ]);
                $_SESSION['customer_name'] = $name;
            }

            // Insert Items & Update Stock (Storing individual product historical price, configuration, and GST snapshot)
            $stmt_item = $db->prepare("
                INSERT INTO order_items (
                    order_id, product_id, variant_id, product_name, product_code, ruling_type, 
                    page_count, variety, pieces_per_bundle, total_pieces, order_type,
                    price, quantity, total_price, gst_rate, taxable_amount, gst_amount, cgst_amount, sgst_amount
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt_history = $db->prepare("INSERT INTO stock_history (product_id, previous_stock, new_stock, change_amount, reason) VALUES (?, ?, ?, ?, ?)");

            foreach ($items as $item) {
                $effective_price = isset($item['effective_price']) ? (float)$item['effective_price'] : (!empty($item['cart_unit_price']) ? (float)$item['cart_unit_price'] : (float)($item['price'] ?? 0));
                $item_total = round($effective_price * (int)$item['quantity'], 2);
                $r_type = !empty($item['ruling_type']) ? $item['ruling_type'] : 'Unruled';
                $p_code = !empty($item['product_code']) ? $item['product_code'] : get_variant_product_code($item['id'], $r_type, $item['pages'] ?? null);

                // Requirements 3 & 4: Variant Configuration and Price Snapshot at Order Time
                $vInfo = parse_variety_info($r_type);
                $page_count = !empty($vInfo['pages']) ? (int)$vInfo['pages'] : (!empty($item['pages']) ? (int)$item['pages'] : null);
                $variety = !empty($vInfo['variety']) ? $vInfo['variety'] : 'Standard';
                $isBundle = (stripos($r_type, 'Bundle') !== false);
                $order_type = $isBundle ? 'Bundle' : 'Single Piece';

                $orderedQty = max(1, (int)$item['quantity']);
                $variantId = (int)($item['variant_id'] ?? 0);
                $ppb = !empty($item['pieces_per_bundle']) && (int)$item['pieces_per_bundle'] > 0
                    ? (int)$item['pieces_per_bundle']
                    : get_product_pieces_per_bundle($item['id'], $r_type, $item, $variantId);
                if (!$isBundle) {
                    $ppb = 1;
                }
                $totalPieces = $isBundle ? ($ppb * $orderedQty) : $orderedQty;
                $stock_record = get_product_variant_stock_record($db, $item['id'], $p_code, $r_type, $item, true, $variantId);
                if (!$stock_record) {
                    throw new RuntimeException('Insufficient individual-piece stock for ' . $item['name'] . '. Please update the quantity in your cart.');
                }
                if ($isBundle) {
                    $ppb = max(1, (int)$stock_record['bundle_qty']);
                    $totalPieces = $ppb * $orderedQty;
                }
                // A zero quantity in the stock bucket being ordered is allowed;
                // positive stock still follows the existing safety checks.
                $stock_is_zero = $isBundle
                    ? (int)$stock_record['bundle_stock'] <= 0
                    : (int)$stock_record['stock'] <= 0;
                if (!$stock_is_zero && $totalPieces > (int)$stock_record['stock']) {
                    throw new RuntimeException('Insufficient individual-piece stock for ' . $item['name'] . '. Please update the quantity in your cart.');
                }
                $order_type = $isBundle ? 'Bundle' : 'Single Piece';
                if (!$stock_is_zero && $isBundle && $orderedQty > (int)$stock_record['bundle_stock']) {
                    throw new RuntimeException('Insufficient complete bundles for ' . $item['name'] . '. Please update the quantity in your cart.');
                }

                $item_gst_rate = isset($item['gst_rate']) && $item['gst_rate'] !== null && $item['gst_rate'] !== '' ? (float)$item['gst_rate'] : 18.00;
                $item_taxable = round($effective_price * $orderedQty, 2);
                $item_gst = round(($item_taxable * $item_gst_rate) / 100, 2);
                $item_cgst = round($item_gst / 2, 2);
                $item_sgst = round($item_gst - $item_cgst, 2);

                $stmt_item->execute([
                    $order_id,
                    $item['id'],
                    $variantId ?: null,
                    $item['name'],
                    $p_code,
                    $r_type,
                    $page_count,
                    $variety,
                    $ppb,
                    $totalPieces,
                    $order_type,
                    $effective_price,
                    $orderedQty,
                    $item_total,
                    $item_gst_rate,
                    $item_taxable,
                    $item_gst,
                    $item_cgst,
                    $item_sgst
                ]);

                if (!$stock_is_zero) {
                    // Deduct stock in individual pieces and recalculate complete bundles.
                    $vInfo = parse_variety_info($r_type);
                    $pureVariety = !empty($vInfo['variety']) ? $vInfo['variety'] : $r_type;
                    $oldStock = (int)$stock_record['stock'];
                    $newTotalPieces = $oldStock - $totalPieces;
                    $bundleQty = max(1, (int)$stock_record['bundle_qty']);
                    $stockBreakdown = calculate_stock_breakdown($newTotalPieces, $bundleQty);
                    $newBundleStock = $stockBreakdown['bundle_stock'];
                    $newLooseStock = $stockBreakdown['loose_stock'];

                    if ($stock_record['id'] !== null) {
                    $stmt_up_v = $db->prepare("
                        UPDATE product_variants 
                        SET bundle_stock = ?, loose_stock = ?, stock = ? 
                        WHERE id = ? AND stock >= ?
                    ");
                    $stmt_up_v->execute([$newBundleStock, $newLooseStock, $newTotalPieces, $stock_record['id'], $totalPieces]);
                    if ($stmt_up_v->rowCount() !== 1) {
                        throw new RuntimeException('Insufficient stock for ' . $item['name'] . '. Please update the quantity in your cart.');
                    }

                    $stmt_sync_p = $db->prepare("
                        UPDATE products 
                        SET bundle_stock = (SELECT COALESCE(SUM(bundle_stock), 0) FROM product_variants WHERE product_id = ?),
                            stock = (SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = ?) 
                        WHERE id = ?
                    ");
                    $stmt_sync_p->execute([$item['id'], $item['id'], $item['id']]);
                    } else {
                    $stmt_update_product = $db->prepare("
                        UPDATE products
                        SET stock = ?, bundle_stock = ?
                        WHERE id = ? AND stock >= ?
                    ");
                    $stmt_update_product->execute([$newTotalPieces, $newBundleStock, $item['id'], $totalPieces]);
                    if ($stmt_update_product->rowCount() !== 1) {
                        throw new RuntimeException('Insufficient stock for ' . $item['name'] . '. Please update the quantity in your cart.');
                    }
                    }

                    $logMsg = $isBundle ? "Customer Order #$order_number ($orderedQty Bundles of $pureVariety)" : "Customer Order #$order_number ($orderedQty Pcs of $pureVariety)";
                    $stmt_history->execute([$item['id'], $oldStock, $newTotalPieces, -$totalPieces, $logMsg]);
                }
            }

            // Clear Cart
            $session_id = get_cart_session_id();
            if ($user_id) {
                $stmt_clear = $db->prepare("DELETE ci FROM cart_items ci JOIN cart c ON ci.cart_id = c.id WHERE c.user_id = ? OR c.session_id = ?");
                $stmt_clear->execute([$user_id, $session_id]);
            } else {
                $stmt_clear = $db->prepare("DELETE ci FROM cart_items ci JOIN cart c ON ci.cart_id = c.id WHERE c.session_id = ?");
                $stmt_clear->execute([$session_id]);
            }
            unset($_SESSION['applied_coupon']);

            $db->commit();

            header("Location: order-success.php?order_id=" . $order_id);
            exit();

        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = "Order Placement Failed: " . $e->getMessage();
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section-padding py-4">
  <?php echo render_page_navigation('Checkout', ['Cart' => 'cart.php', 'Checkout' => '']); ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger mb-4 rounded-3 py-2.5">
      <ul class="mb-0 ps-3">
        <?php foreach ($errors as $err): ?>
          <li><?php echo htmlspecialchars($err); ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form action="checkout.php" method="POST" id="checkoutForm" novalidate>
    <div class="row g-4">
      
      <!-- Left Column: Shipping & Payment Details -->
      <div class="col-lg-7">
        
        <!-- DELIVERY ADDRESS CARD -->
        <div class="bg-white p-4 rounded-3 border mb-4 shadow-sm">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold text-navy mb-0"><i class="bi bi-geo-alt-fill text-gold me-2"></i> Delivery Address</h5>
            <?php if ($has_saved_address): ?>
              <button type="button" class="btn btn-outline-gold btn-sm fw-semibold" id="toggleChangeAddressBtn" onclick="toggleCheckoutAddressForm()">
                <i class="bi bi-pencil-square me-1"></i> <span id="changeAddressBtnText">Change Address</span>
              </button>
            <?php endif; ?>
          </div>

          <?php if ($has_saved_address): ?>
            <!-- CLEAN SAVED ADDRESS SUMMARY UI (REQUIREMENT 9) -->
            <div id="savedAddressSummaryCard" class="p-3 bg-light rounded-3 border mb-3" style="<?php echo !empty($errors) ? 'display: none;' : ''; ?>">
              <div class="d-flex align-items-center justify-content-between mb-2 pb-1 border-bottom">
                <span class="text-uppercase small fw-bold text-gold letter-spacing-1">
                  <i class="bi bi-check-circle-fill me-1"></i> Saved Default Address
                </span>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-0.5 small">Auto-Loaded</span>
              </div>
              <div class="fw-bold text-navy fs-6"><?php echo htmlspecialchars($current_customer['name']); ?></div>
              <div class="text-dark small lh-base mt-1">
                <div><?php echo htmlspecialchars($current_customer['address']); ?></div>
                <?php if (!empty($current_customer['address_line2'])): ?>
                  <div><?php echo htmlspecialchars($current_customer['address_line2']); ?></div>
                <?php endif; ?>
                <?php if (!empty($current_customer['area'])): ?>
                  <div>Area: <?php echo htmlspecialchars($current_customer['area']); ?></div>
                <?php endif; ?>
                <div class="fw-bold text-navy mt-1">
                  <?php echo htmlspecialchars($current_customer['city']); ?>
                  <?php if (!empty($current_customer['district']) && strcasecmp($current_customer['district'], $current_customer['city']) !== 0): ?>
                    , <?php echo htmlspecialchars($current_customer['district']); ?>
                  <?php endif; ?>
                  , <?php echo htmlspecialchars($current_customer['state'] ?? 'Tamil Nadu'); ?> - <?php echo htmlspecialchars($current_customer['pincode']); ?>
                </div>
                <?php if (!empty($current_customer['landmark'])): ?>
                  <div class="text-muted fst-italic mt-1"><i class="bi bi-compass me-1"></i> Landmark: <?php echo htmlspecialchars($current_customer['landmark']); ?></div>
                <?php endif; ?>
              </div>
              
              <div class="mt-2.5 pt-2 border-top d-flex flex-wrap gap-3 small text-dark">
                <div><strong>Mobile:</strong> <?php echo htmlspecialchars($current_customer['phone']); ?></div>
                <div><strong>Email:</strong> <?php echo !empty($current_customer['email']) ? htmlspecialchars($current_customer['email']) : '<span class="text-muted fst-italic">Not Provided</span>'; ?></div>
                <div>
                  <strong>GST:</strong> 
                  <?php 
                  $chk_is_reg = (!empty($current_customer['gst_status']) && strcasecmp($current_customer['gst_status'], 'Registered') === 0) || !empty($current_customer['gst_number']);
                  if ($chk_is_reg && !empty($current_customer['gst_number'])): ?>
                    <span class="badge bg-success px-1.5 py-0.5"><i class="bi bi-check-circle-fill me-0.5"></i>Registered</span>
                    <span class="badge bg-gold text-navy px-1.5 py-0.5"><?php echo htmlspecialchars($current_customer['gst_number']); ?></span>
                  <?php else: ?>
                    <span class="badge bg-secondary px-1.5 py-0.5"><i class="bi bi-x-circle me-0.5"></i>Not Registered</span>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endif; ?>

          <!-- ADDRESS EDIT / INPUT FORM CONTAINER -->
          <div id="checkoutAddressFields" style="<?php echo ($has_saved_address && empty($errors)) ? 'display: none;' : ''; ?>">
            
            <!-- SECTION 1: CUSTOMER INFORMATION -->
            <div class="mb-3.5">
              <div class="text-uppercase small fw-bold text-gold mb-2.5 letter-spacing-1 border-bottom pb-1">
                <i class="bi bi-person-badge me-1"></i> Customer Information
              </div>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                  <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($_POST['name'] ?? ($current_customer['name'] ?? '')); ?>" placeholder="John Doe">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Mobile Number <span class="text-danger">*</span></label>
                  <input type="tel" name="phone" class="form-control" required pattern="[0-9]{10}" title="10-digit mobile number" value="<?php echo htmlspecialchars($_POST['phone'] ?? ($current_customer['phone'] ?? '')); ?>" placeholder="9876543210">
                </div>
                <div class="col-md-12">
                  <label class="form-label small fw-semibold">Email Address <span class="text-muted fw-normal">(Optional)</span></label>
                  <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($_POST['email'] ?? ($current_customer['email'] ?? '')); ?>" placeholder="name@example.com">
                  <div class="form-text small text-muted">Email is optional. You can continue without an email address.</div>
                </div>
              </div>
            </div>

            <!-- SECTION 2: DELIVERY ADDRESS -->
            <div class="mb-3.5">
              <div class="text-uppercase small fw-bold text-gold mb-2.5 letter-spacing-1 border-bottom pb-1">
                <i class="bi bi-geo-alt me-1"></i> Delivery Address
              </div>
              <div class="row g-3">
                <div class="col-md-12">
                  <label class="form-label small fw-semibold">Address Line 1 (Door No. / Street) <span class="text-danger">*</span></label>
                  <textarea name="address" rows="2" class="form-control" required placeholder="e.g. 12 Main Road, Gandhi Nagar"><?php echo htmlspecialchars($_POST['address'] ?? ($current_customer['address'] ?? '')); ?></textarea>
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Address Line 2 <span class="text-muted fw-normal">(Optional)</span></label>
                  <input type="text" name="address_line2" class="form-control" value="<?php echo htmlspecialchars($_POST['address_line2'] ?? ($current_customer['address_line2'] ?? '')); ?>" placeholder="Apartment, Suite, Unit, etc.">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Area / Locality <span class="text-danger">*</span></label>
                  <input type="text" name="area" class="form-control" required value="<?php echo htmlspecialchars($_POST['area'] ?? ($current_customer['area'] ?? '')); ?>" placeholder="e.g. Anna Nagar">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">City <span class="text-danger">*</span></label>
                  <input type="text" name="city" class="form-control" required value="<?php echo htmlspecialchars($_POST['city'] ?? ($current_customer['city'] ?? '')); ?>" placeholder="e.g. Madurai">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">District <span class="text-danger">*</span></label>
                  <input type="text" name="district" class="form-control" required value="<?php echo htmlspecialchars($_POST['district'] ?? ($current_customer['district'] ?? '')); ?>" placeholder="e.g. Madurai">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">State <span class="text-danger">*</span></label>
                  <input type="text" name="state" class="form-control" required value="<?php echo htmlspecialchars($_POST['state'] ?? ($current_customer['state'] ?? 'Tamil Nadu')); ?>" placeholder="Tamil Nadu">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Pincode <span class="text-danger">*</span></label>
                  <input type="text" name="pincode" class="form-control" required pattern="[0-9]{6}" title="6-digit pincode" value="<?php echo htmlspecialchars($_POST['pincode'] ?? ($current_customer['pincode'] ?? '')); ?>" placeholder="625001">
                </div>
                <div class="col-md-12">
                  <label class="form-label small fw-semibold">Landmark <span class="text-muted fw-normal">(Optional)</span></label>
                  <input type="text" name="landmark" class="form-control" value="<?php echo htmlspecialchars($_POST['landmark'] ?? ($current_customer['landmark'] ?? '')); ?>" placeholder="e.g. Near Bus Stand">
                </div>
              </div>
            </div>

            <!-- SECTION 3: BUSINESS / TAX INFORMATION (GST) -->
            <div class="mb-3.5">
              <div class="text-uppercase small fw-bold text-gold mb-2.5 letter-spacing-1 border-bottom pb-1">
                <i class="bi bi-receipt me-1"></i> GST Registration & Tax Information
              </div>
              <?php 
              $chk_saved_reg = (!empty($_POST['gst_registration'])) 
                  ? (strcasecmp($_POST['gst_registration'], 'Registered') === 0) 
                  : ((!empty($current_customer['gst_status']) && strcasecmp($current_customer['gst_status'], 'Registered') === 0) || !empty($current_customer['gst_number']));
              $chk_saved_gstin = $_POST['gst_number'] ?? ($chk_saved_reg ? ($current_customer['gst_number'] ?? '') : '');
              ?>

              <!-- GST Registration Radio Selection -->
              <label class="form-label small fw-semibold text-navy d-block mb-2">GST Registration Status <span class="text-danger fw-bold">*</span></label>
              <div class="d-flex align-items-center gap-4 mb-3">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="gst_registration" id="checkout_gst_not_registered" value="Not Registered" <?php echo !$chk_saved_reg ? 'checked' : ''; ?> onchange="handleCheckoutGstRadioChange()">
                  <label class="form-check-label fw-semibold text-dark" for="checkout_gst_not_registered">
                    <i class="bi bi-x-circle text-muted me-1"></i> Not Registered
                  </label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="gst_registration" id="checkout_gst_registered" value="Registered" <?php echo $chk_saved_reg ? 'checked' : ''; ?> onchange="handleCheckoutGstRadioChange()">
                  <label class="form-check-label fw-semibold text-dark" for="checkout_gst_registered">
                    <i class="bi bi-check-circle-fill text-success me-1"></i> Registered
                  </label>
                </div>
              </div>

              <!-- Mandatory GSTIN Input Container (Displayed only when Registered) -->
              <div id="checkout_gstin_container" style="<?php echo $chk_saved_reg ? '' : 'display: none;'; ?>">
                <label class="form-label small fw-semibold text-navy mb-1" for="checkout_gst_input">
                  GSTIN Number <span class="text-danger fw-bold">*</span>
                </label>
                <div class="input-group">
                  <span class="input-group-text bg-light text-navy fw-bold small"><i class="bi bi-shield-check text-gold"></i></span>
                  <input type="text" name="gst_number" id="checkout_gst_input" class="form-control text-uppercase font-monospace fw-bold" maxlength="15" value="<?php echo htmlspecialchars($chk_saved_gstin); ?>" placeholder="e.g. 33AAAAA0000A1Z5" autocomplete="off">
                </div>
                <div class="form-text small text-muted">Standard 15-character Indian GSTIN (e.g. 33AAAAA0000A1Z5).</div>
                <div id="checkout_gst_feedback" class="small text-danger mt-1 fw-semibold" style="display: none;"></div>
              </div>
            </div>

            <!-- SAVE ADDRESS CHECKBOX -->
            <div class="form-check mt-3 pt-2 border-top">
              <input class="form-check-input" type="checkbox" name="save_address" id="saveAddressCheckbox" value="1" checked>
              <label class="form-check-label small fw-semibold text-navy" for="saveAddressCheckbox">
                Save / update this address in my profile for future orders
              </label>
              <div class="text-muted small">Your next orders will automatically use this updated address.</div>
            </div>

          </div>

        </div>

        <!-- PAYMENT METHOD -->
        <div class="bg-white p-4 rounded-3 border shadow-sm">
          <h5 class="fw-bold text-navy mb-3"><i class="bi bi-credit-card text-gold me-2"></i> Payment Method</h5>
          
          <div class="form-check mb-2 p-3 border rounded-2">
            <input class="form-check-input ms-0 me-2" type="radio" name="payment_method" id="payCOD" value="Cash On Delivery" checked>
            <label class="form-check-label fw-semibold text-navy" for="payCOD">
              Cash On Delivery (COD)
              <div class="small text-muted font-normal">Pay with cash when your notebook package arrives at your doorstep.</div>
            </label>
          </div>

          <div class="form-check p-3 border rounded-2">
            <input class="form-check-input ms-0 me-2" type="radio" name="payment_method" id="payOnline" value="UPI / Online">
            <label class="form-check-label fw-semibold text-navy" for="payOnline">
              UPI / Online Payment (Razorpay / GPay / PhonePe)
              <div class="small text-muted font-normal">Instant digital payment via UPI, Debit/Credit Card, or Net Banking.</div>
            </label>
          </div>
        </div>
      </div>

      <!-- Right Column: Order Summary -->
      <div class="col-lg-5">
        <div class="bg-white p-3.5 p-md-4 rounded-3 border sticky-top shadow-sm sticky-top-mobile-reset" style="top: 90px;">
          <h5 class="fw-bold text-navy mb-3">Order Items (<?php echo count($items); ?>)</h5>
          
          <div class="mb-3" style="max-height: 240px; overflow-y: auto;">
            <?php foreach ($items as $item): 
              $effective_price = isset($item['effective_price']) ? (float)$item['effective_price'] : (!empty($item['cart_unit_price']) ? (float)$item['cart_unit_price'] : (float)($item['price'] ?? 0));
              $vInfo = parse_variety_info($item['ruling_type'] ?? '', $item['pages'] ?? '');
              $isBundle = (stripos($vInfo['order_type'] ?? '', 'Bundle') !== false);
              $qty = (int)$item['quantity'];
              $unit_label = $isBundle ? ($qty === 1 ? 'Bundle' : 'Bundles') : ($qty === 1 ? 'Piece' : 'Pieces');
              $itemCode = !empty($item['product_code']) ? $item['product_code'] : get_variant_product_code($item['id'], $item['ruling_type'] ?? '', $item['pages'] ?? null);
              $ppb = !empty($item['pieces_per_bundle']) && (int)$item['pieces_per_bundle'] > 0 ? (int)$item['pieces_per_bundle'] : get_product_pieces_per_bundle($item['id'], $item['ruling_type'] ?? '', $item);
              if (!$isBundle) $ppb = 1;
              $totalPcs = $isBundle ? ($ppb * $qty) : $qty;
            ?>
              <div class="d-flex align-items-start justify-content-between mb-2 pb-2 border-bottom gap-2">
                <div class="d-flex align-items-start gap-2" style="min-width: 0; flex: 1;">
                  <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" style="width: 44px; height: 44px; object-fit: contain; flex-shrink: 0;" class="rounded border p-1 bg-light">
                  <div style="min-width: 0; flex: 1;">
                    <div class="fw-semibold small text-navy mb-0.5 text-truncate" title="<?php echo htmlspecialchars($item['name']); ?>">
                      <?php echo htmlspecialchars($item['name']); ?>
                      <?php if ($itemCode): ?>
                        <code class="ms-1 small text-secondary fw-bold" style="font-size: 0.72rem;">[<?php echo htmlspecialchars($itemCode); ?>]</code>
                      <?php endif; ?>
                    </div>
                    <small class="text-secondary d-block" style="font-size: 0.75rem;">
                      <span class="fw-semibold text-navy"><?php echo htmlspecialchars(!empty($vInfo['page_count']) ? $vInfo['page_count'] : ($item['pages'] . ' Pages')); ?></span>
                      &bull; <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold px-1.5 py-0.5" style="font-size: 0.7rem;">Variety: <?php echo htmlspecialchars($vInfo['variety']); ?></span>
                      <?php if ($isBundle): ?>
                        &bull; <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-1.5 py-0.5" style="font-size: 0.7rem;"><?php echo $ppb; ?> Pcs/Bdl</span>
                      <?php endif; ?>
                    </small>
                    <small class="text-muted d-block" style="font-size: 0.725rem;">
                      <?php if ($isBundle): ?>
                        <strong><?php echo $qty; ?> <?php echo $unit_label; ?></strong> = <strong class="text-primary"><?php echo number_format($totalPcs); ?> Pieces</strong> &times; <?php echo format_price($effective_price); ?>/Bdl
                      <?php else: ?>
                        Qty: <strong><?php echo $qty; ?> Pieces</strong> &times; <?php echo format_price($effective_price); ?>
                      <?php endif; ?>
                    </small>
                  </div>
                </div>
                <span class="fw-bold small text-navy text-nowrap flex-shrink-0 pt-0.5"><?php echo format_price($effective_price * $item['quantity']); ?></span>
              </div>
            <?php endforeach; ?>
          </div>

          <hr>

          <div class="d-flex justify-content-between mb-2">
            <span class="text-secondary">Taxable Amount</span>
            <span class="fw-semibold text-navy"><?php echo format_price($totals['taxable_amount']); ?></span>
          </div>

          <?php if ($totals['discount'] > 0): ?>
            <div class="d-flex justify-content-between mb-2 text-success">
              <span>Coupon Discount</span>
              <span class="fw-semibold">-<?php echo format_price($totals['discount']); ?></span>
            </div>
          <?php endif; ?>

          <?php if (!empty($totals['has_multiple_rates'])): ?>
            <!-- Multiple GST Rates Breakdown -->
            <div class="my-2 p-2.5 bg-light rounded border border-light-subtle">
              <div class="small fw-bold text-navy mb-1.5 border-bottom pb-1"><i class="bi bi-pie-chart text-gold me-1"></i> GST Breakdown:</div>
              <?php foreach ($totals['rates_breakdown'] as $r): ?>
                <div class="d-flex justify-content-between small text-dark mb-1">
                  <span class="fw-semibold"><?php echo htmlspecialchars($r['rate_label']); ?></span>
                  <span class="fw-bold text-navy"><?php echo format_price($r['gst_amount']); ?></span>
                </div>
                <div class="d-flex justify-content-between text-muted ps-2 mb-1" style="font-size: 0.75rem;">
                  <span>&bull; <?php echo htmlspecialchars($r['cgst_rate_label']); ?> / <?php echo htmlspecialchars($r['sgst_rate_label']); ?></span>
                  <span><?php echo format_price($r['cgst_amount']); ?> + <?php echo format_price($r['sgst_amount']); ?></span>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="d-flex justify-content-between mb-2 py-1 px-2 rounded bg-light border border-light-subtle">
              <span class="text-secondary small fw-bold">Total GST</span>
              <span class="fw-bold text-navy"><?php echo format_price($totals['total_gst']); ?></span>
            </div>
          <?php else: ?>
            <!-- Single GST Rate Clean Display -->
            <?php $single_rate = !empty($totals['rates_breakdown']) ? reset($totals['rates_breakdown']) : null; ?>
            <div class="d-flex justify-content-between mb-1.5 small">
              <span class="text-secondary">CGST (<?php echo $single_rate ? $single_rate['cgst_rate'] : 9; ?>%)</span>
              <span class="fw-semibold text-navy"><?php echo format_price($totals['cgst']); ?></span>
            </div>
            <div class="d-flex justify-content-between mb-1.5 small">
              <span class="text-secondary">SGST (<?php echo $single_rate ? $single_rate['sgst_rate'] : 9; ?>%)</span>
              <span class="fw-semibold text-navy"><?php echo format_price($totals['sgst']); ?></span>
            </div>
            <div class="d-flex justify-content-between mb-2 py-1 px-2 rounded bg-light border border-light-subtle">
              <span class="text-secondary small fw-bold">Total GST (<?php echo $single_rate ? $single_rate['rate'] : 18; ?>%)</span>
              <span class="fw-bold text-navy"><?php echo format_price($totals['total_gst']); ?></span>
            </div>
          <?php endif; ?>

          <div class="d-flex justify-content-between mb-3">
            <span class="text-secondary">Delivery Fee</span>
            <span class="fw-semibold text-navy"><?php echo ($totals['delivery_fee'] == 0) ? '<span class="text-success">FREE</span>' : format_price($totals['delivery_fee']); ?></span>
          </div>

          <hr class="my-3">

          <div class="d-flex justify-content-between mb-3 fs-5">
            <span class="fw-bold text-navy">Grand Total</span>
            <span class="fw-bold text-gold"><?php echo format_price($totals['grand_total']); ?></span>
          </div>

          <div class="small text-muted py-1.5 px-2 bg-light rounded text-center mb-3 border border-light-subtle" style="font-size: 0.75rem;">
            <i class="bi bi-shield-check text-gold me-1"></i>GSTIN: <strong class="text-navy"><?php echo htmlspecialchars($totals['gstin']); ?></strong>
          </div>

          <button type="submit" class="btn btn-gold w-100 justify-content-center py-2 fs-6">Place Order Now <i class="bi bi-check-circle-fill ms-1"></i></button>
        </div>
      </div>

    </div>
  </form>
</div>

<script>
function toggleCheckoutAddressForm() {
    const summaryCard = document.getElementById('savedAddressSummaryCard');
    const fieldsDiv = document.getElementById('checkoutAddressFields');
    const btnText = document.getElementById('changeAddressBtnText');
    
    if (fieldsDiv.style.display === 'none' || fieldsDiv.style.display === '') {
        fieldsDiv.style.display = 'block';
        if (summaryCard) summaryCard.style.display = 'none';
        if (btnText) btnText.textContent = 'Keep Current Address';
    } else {
        fieldsDiv.style.display = 'none';
        if (summaryCard) summaryCard.style.display = 'block';
        if (btnText) btnText.textContent = 'Change Address';
    }
}

// Frontend GST Registration Toggle & Validation on Checkout
function handleCheckoutGstRadioChange() {
    const isReg = document.getElementById('checkout_gst_registered').checked;
    const container = document.getElementById('checkout_gstin_container');
    const input = document.getElementById('checkout_gst_input');
    const feedback = document.getElementById('checkout_gst_feedback');

    if (isReg) {
        container.style.display = 'block';
        input.setAttribute('required', 'required');
        input.focus();
    } else {
        container.style.display = 'none';
        input.removeAttribute('required');
        input.value = ''; // Stale GSTIN cleared so it is not submitted
        input.classList.remove('is-invalid');
        if (feedback) {
            feedback.style.display = 'none';
            feedback.textContent = '';
        }
    }
}

const checkoutGstInput = document.getElementById('checkout_gst_input');
const checkoutGstFeedback = document.getElementById('checkout_gst_feedback');
const gstRegexPattern = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/;

function validateCheckoutGST() {
    const isReg = document.getElementById('checkout_gst_registered') ? document.getElementById('checkout_gst_registered').checked : false;
    if (!isReg) {
        if (checkoutGstInput) checkoutGstInput.value = '';
        return true;
    }

    const val = checkoutGstInput ? checkoutGstInput.value.trim().toUpperCase() : '';
    if (val === '') {
        if (checkoutGstFeedback) {
            checkoutGstFeedback.textContent = 'GSTIN Number is mandatory when Registered is selected.';
            checkoutGstFeedback.style.display = 'block';
        }
        if (checkoutGstInput) checkoutGstInput.classList.add('is-invalid');
        return false;
    }

    if (!gstRegexPattern.test(val)) {
        if (checkoutGstFeedback) {
            checkoutGstFeedback.textContent = 'Please enter a valid 15-character Indian GSTIN (e.g. 33AAAAA0000A1Z5).';
            checkoutGstFeedback.style.display = 'block';
        }
        if (checkoutGstInput) checkoutGstInput.classList.add('is-invalid');
        return false;
    }

    if (checkoutGstFeedback) checkoutGstFeedback.style.display = 'none';
    if (checkoutGstInput) checkoutGstInput.classList.remove('is-invalid');
    return true;
}

if (checkoutGstInput) {
    checkoutGstInput.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
        if (checkoutGstFeedback && checkoutGstFeedback.style.display !== 'none') {
            validateCheckoutGST();
        }
    });
}

const checkoutFormEl = document.getElementById('checkoutForm');
if (checkoutFormEl) {
    checkoutFormEl.addEventListener('submit', function(e) {
        if (!validateCheckoutGST()) {
            e.preventDefault();
            // Ensure fields are visible so user can see error
            const fieldsDiv = document.getElementById('checkoutAddressFields');
            if (fieldsDiv && fieldsDiv.style.display === 'none') {
                toggleCheckoutAddressForm();
            }
            if (checkoutGstInput) checkoutGstInput.focus();
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
