<?php
require_once __DIR__ . '/includes/functions.php';

if (is_admin_logged_in() && !is_customer_logged_in()) {
    header("Location: admin/index.php");
    exit();
}

if (!is_customer_logged_in()) {
    header("Location: login.php");
    exit();
}

$db = getDB();
$customer_id = (int)$_SESSION['customer_id'];
$errors = [];
$success_msg = '';

// Handle Direct Customer ID Regeneration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'regenerate_id') {
    $cust = get_current_customer();
    if ($cust) {
        $newId = regenerate_customer_code($customer_id);
        if ($newId) {
            set_flash('success', "Customer ID successfully regenerated to {$newId}. Historical orders and invoices remain preserved.");
        } else {
            set_flash('error', "Failed to regenerate Customer ID. Please try again.");
        }
    }
    header("Location: account.php");
    exit();
}

// Handle Profile & Address Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $name = sanitize($_POST['name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $email_raw = trim($_POST['email'] ?? '');
    $email = !empty($email_raw) ? sanitize($email_raw) : null;
    $customer_type = trim($_POST['customer_type'] ?? '');
    $regenerate_id = !empty($_POST['regenerate_id']);

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

    // Validate Required
    if (empty($name) || empty($phone)) {
        $errors[] = "Full Name and Mobile Phone Number are required.";
    }

    // Validate Customer Type
    if (empty($customer_type) || !is_valid_customer_type($customer_type)) {
        $errors[] = "Please select a valid Customer Type (Wholesaler, Distributor, Retailer, or Other).";
    }

    // Validate Email if provided
    if ($email !== null) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Please enter a valid email address or leave the field blank.";
        } else {
            // Check if email already used by another user
            $stmt_check = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt_check->execute([$email, $customer_id]);
            if ($stmt_check->fetch()) {
                $errors[] = "This email address is already in use by another account.";
            }
        }
    }

    if (empty($errors)) {
        $stmt_update = $db->prepare("
            UPDATE users SET 
                name = ?,
                email = ?,
                phone = ?,
                customer_type = ?,
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
        $stmt_update->execute([
            $name,
            $email,
            $phone,
            $customer_type,
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
            $customer_id
        ]);

        $_SESSION['customer_name'] = $name;

        if ($regenerate_id) {
            $newCode = regenerate_customer_code($customer_id, $district ?: $city, $name, $customer_type);
            set_flash('success', "Your profile has been updated and Customer ID was regenerated to {$newCode}. Order history preserved.");
        } else {
            set_flash('success', 'Your profile and address details have been updated successfully.');
        }

        header("Location: account.php");
        exit();
    }
}

$customer = get_current_customer();

// Fetch customer's orders
$stmt = $db->prepare("SELECT * FROM orders WHERE user_id = ? OR (customer_code = ? AND customer_code IS NOT NULL) ORDER BY id DESC");
$stmt->execute([$customer['id'], $customer['customer_code'] ?? '']);
$orders = $stmt->fetchAll();

$has_saved_address = !empty($customer['address']) && !empty($customer['city']) && !empty($customer['pincode']);

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section-padding py-4">
  <?php echo render_page_navigation('My Account Dashboard', ['My Account' => '']); ?>
  
  <div class="row g-4">
    <!-- Account Nav -->
    <div class="col-lg-3">
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

          <?php 
          $custCode = $customer['customer_code'] ?? ('CUST' . str_pad($customer['id'], 5, '0', STR_PAD_LEFT));
          $custQrImg = get_customer_qr_image_url($custCode, 150);
          ?>
          <div class="mt-3 pt-3 border-top text-center">
            <div class="p-2 bg-light rounded-3 border d-inline-block shadow-sm mb-2">
              <img src="<?php echo htmlspecialchars($custQrImg); ?>" alt="Customer QR Code" style="width: 120px; height: 120px; display: block;" class="rounded">
            </div>
            <div class="fw-bold text-navy" style="font-size: 0.825rem;">Verified Customer QR</div>
            <div class="text-muted" style="font-size: 0.725rem;">Scan for profile & order tracking</div>
            <a href="<?php echo htmlspecialchars($custQrImg); ?>" download="QR-<?php echo htmlspecialchars($custCode); ?>.png" target="_blank" class="btn btn-outline-secondary btn-sm mt-2 w-100 fw-semibold" style="font-size: 0.75rem;">
              <i class="bi bi-download me-1"></i> Download QR PNG
            </a>
            <form action="account.php" method="POST" onsubmit="return confirm('Regenerate your unique Customer ID based on your latest Name, District, and Customer Type? Historical orders and invoices will remain intact.');" class="mt-2">
              <input type="hidden" name="action" value="regenerate_id">
              <button type="submit" class="btn btn-outline-gold btn-sm w-100 fw-semibold" style="font-size: 0.75rem;" title="Regenerate Customer ID matching current details">
                <i class="bi bi-arrow-repeat me-1"></i> Regenerate Customer ID
              </button>
            </form>
          </div>
        </div>
        <div class="list-group list-group-flush">
          <a href="account.php" class="list-group-item list-group-item-action fw-semibold text-warning"><i class="bi bi-person me-2"></i> Account Dashboard</a>
          <a href="orders.php" class="list-group-item list-group-item-action"><i class="bi bi-bag me-2"></i> My Orders</a>
          <?php $acc_pending_quotations = get_customer_pending_quotation_count($customer['id']); ?>
          <a href="quotations.php" class="list-group-item list-group-item-action">
            <i class="bi bi-file-earmark-text me-2"></i> My Quotations
            <?php if ($acc_pending_quotations > 0): ?>
              <span class="badge bg-danger rounded-pill float-end"><?php echo $acc_pending_quotations; ?></span>
            <?php endif; ?>
          </a>
          <a href="logout.php" class="list-group-item list-group-item-action text-danger"><i class="bi bi-box-arrow-right me-2"></i> Logout</a>
        </div>
      </div>
    </div>

    <!-- Account Main Body -->
    <div class="col-lg-9">
      
      <?php if ($acc_pending_quotations > 0): ?>
        <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center justify-content-between flex-wrap gap-2 p-3 mb-4 rounded-3">
          <div class="d-flex align-items-center gap-3">
            <i class="bi bi-file-earmark-text-fill fs-2 text-warning"></i>
            <div>
              <h6 class="fw-bold text-navy mb-0.5">Wholesale Quotation Waiting for Your Review</h6>
              <div class="small text-secondary">
                You have <strong><?php echo $acc_pending_quotations; ?></strong> pending quotation(s) sent by the Rajpriya team for your orders.
              </div>
            </div>
          </div>
          <a href="quotations.php" class="btn btn-gold btn-sm px-3 fw-bold shadow-xs">
            Review Quotation(s) <i class="bi bi-arrow-right ms-1"></i>
          </a>
        </div>
      <?php endif; ?>

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger py-2.5 px-3 mb-4 rounded-3 small">
          <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?>
              <li><?php echo htmlspecialchars($e); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <!-- SAVED DELIVERY ADDRESS & PROFILE OVERVIEW -->
      <div class="bg-white p-4 rounded-3 border shadow-sm mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center pb-3 mb-3 border-bottom gap-2">
          <div>
            <h4 class="fw-bold text-navy mb-0"><i class="bi bi-geo-alt-fill text-gold me-2"></i> Saved Delivery Address & Profile</h4>
            <small class="text-secondary">This address will be automatically used for your checkout and tax invoices.</small>
          </div>
          <div>
            <button type="button" id="toggleAddressBtn" class="btn btn-outline-gold btn-sm fw-semibold" onclick="toggleEditAddress()">
              <i class="bi bi-pencil-square me-1"></i> <span id="toggleAddressBtnText"><?php echo $has_saved_address ? 'Edit Address' : 'Add Saved Address'; ?></span>
            </button>
          </div>
        </div>

        <!-- READ-ONLY SAVED ADDRESS SUMMARY CARD -->
        <div id="savedAddressView" style="<?php echo (!$has_saved_address && !empty($errors)) ? 'display: none;' : ''; ?>">
          <?php if ($has_saved_address): ?>
            <div class="p-3.5 bg-light rounded-3 border">
              <div class="row g-3">
                <div class="col-md-6 border-end-md">
                  <div class="text-uppercase small fw-bold text-gold mb-2 letter-spacing-1"><i class="bi bi-person-badge me-1"></i> Customer Information</div>
                  <div class="fw-bold text-navy fs-6"><?php echo htmlspecialchars($customer['name']); ?></div>
                  <div class="text-dark small mt-1"><i class="bi bi-telephone text-muted me-1.5"></i> <?php echo htmlspecialchars($customer['phone']); ?></div>
                  <div class="text-dark small mt-1">
                    <i class="bi bi-envelope text-muted me-1.5"></i> 
                    <?php echo !empty($customer['email']) ? htmlspecialchars($customer['email']) : '<span class="text-muted fst-italic">Email: Not Provided</span>'; ?>
                  </div>
                  <div class="text-dark small mt-1">
                    <i class="bi bi-briefcase text-muted me-1.5"></i> Customer Type: 
                    <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold ms-1"><?php echo htmlspecialchars($customer['customer_type'] ?? 'Retailer'); ?></span>
                  </div>
                  <div class="mt-3 pt-2 border-top">
                    <div class="text-uppercase small fw-bold text-gold mb-1"><i class="bi bi-receipt me-1"></i> GST Registration Info</div>
                    <div class="small fw-semibold text-navy">
                      Registration Status: 
                      <?php 
                      $acc_is_reg = (!empty($customer['gst_status']) && strcasecmp($customer['gst_status'], 'Registered') === 0) || !empty($customer['gst_number']);
                      if ($acc_is_reg && !empty($customer['gst_number'])): ?>
                        <span class="badge bg-success ms-1"><i class="bi bi-check-circle-fill me-1"></i>Registered</span>
                        <span class="badge bg-gold text-navy px-2 py-1 ms-1"><?php echo htmlspecialchars($customer['gst_number']); ?></span>
                      <?php else: ?>
                        <span class="badge bg-secondary ms-1"><i class="bi bi-x-circle me-1"></i>Not Registered</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>

                <div class="col-md-6">
                  <div class="text-uppercase small fw-bold text-gold mb-2 letter-spacing-1"><i class="bi bi-truck me-1"></i> Default Shipping Address</div>
                  <div class="text-dark small lh-lg">
                    <div class="fw-semibold text-navy"><?php echo htmlspecialchars($customer['address']); ?></div>
                    <?php if (!empty($customer['address_line2'])): ?>
                      <div><?php echo htmlspecialchars($customer['address_line2']); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($customer['area'])): ?>
                      <div>Area / Locality: <?php echo htmlspecialchars($customer['area']); ?></div>
                    <?php endif; ?>
                    <div class="fw-bold text-navy mt-1">
                      <?php echo htmlspecialchars($customer['city']); ?>
                      <?php if (!empty($customer['district']) && strcasecmp($customer['district'], $customer['city']) !== 0): ?>
                        , <?php echo htmlspecialchars($customer['district']); ?>
                      <?php endif; ?>
                      , <?php echo htmlspecialchars($customer['state']); ?> - <?php echo htmlspecialchars($customer['pincode']); ?>
                    </div>
                    <?php if (!empty($customer['landmark'])): ?>
                      <div class="text-muted fst-italic mt-1"><i class="bi bi-compass me-1"></i> Landmark: <?php echo htmlspecialchars($customer['landmark']); ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            </div>
          <?php else: ?>
            <div class="p-4 bg-light rounded-3 border text-center">
              <i class="bi bi-geo-alt fs-1 text-muted d-block mb-2"></i>
              <h6 class="fw-bold text-navy mb-1">No Delivery Address Saved Yet</h6>
              <p class="text-muted small mb-3">Add your delivery address once, and it will automatically pre-fill during all future notebook orders.</p>
              <button type="button" class="btn btn-gold btn-sm px-3" onclick="toggleEditAddress()">
                <i class="bi bi-plus-circle me-1"></i> Add Saved Address Now
              </button>
            </div>
          <?php endif; ?>
        </div>

        <!-- EDIT ADDRESS & PROFILE FORM -->
        <div id="editAddressForm" style="<?php echo ($has_saved_address && empty($errors)) ? 'display: none;' : ''; ?>">
          <form action="account.php" method="POST" id="profileAddressForm" novalidate>
            <input type="hidden" name="action" value="update_profile">

            <!-- SECTION 1: CUSTOMER INFORMATION -->
            <div class="mb-4">
              <h6 class="fw-bold text-navy mb-3 pb-1 border-bottom text-uppercase small letter-spacing-1">
                <i class="bi bi-person text-gold me-1"></i> Customer Information
              </h6>
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                  <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($customer['name']); ?>" placeholder="Full Name">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Mobile Number <span class="text-danger">*</span></label>
                  <input type="tel" name="phone" class="form-control" required pattern="[0-9]{10}" title="10-digit mobile number" value="<?php echo htmlspecialchars($customer['phone']); ?>" placeholder="10-digit Mobile Number">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Customer Type <span class="text-danger">*</span></label>
                  <select name="customer_type" class="form-select" required id="accountCustomerType">
                    <option value="Wholesaler" <?php echo (($customer['customer_type'] ?? '') === 'Wholesaler') ? 'selected' : ''; ?>>Wholesaler</option>
                    <option value="Distributor" <?php echo (($customer['customer_type'] ?? '') === 'Distributor') ? 'selected' : ''; ?>>Distributor</option>
                    <option value="Retailer" <?php echo (($customer['customer_type'] ?? 'Retailer') === 'Retailer') ? 'selected' : ''; ?>>Retailer</option>
                    <option value="Other" <?php echo (($customer['customer_type'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                  </select>
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Email Address <span class="text-muted fw-normal">(Optional)</span></label>
                  <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($customer['email'] ?? ''); ?>" placeholder="name@example.com">
                  <div class="form-text small text-muted">Email is optional. You can continue without an email address.</div>
                </div>
              </div>
            </div>

            <!-- SECTION 2: DELIVERY ADDRESS -->
            <div class="mb-4">
              <h6 class="fw-bold text-navy mb-3 pb-1 border-bottom text-uppercase small letter-spacing-1">
                <i class="bi bi-geo-alt text-gold me-1"></i> Delivery Address
              </h6>
              <div class="row g-3">
                <div class="col-md-12">
                  <label class="form-label small fw-semibold">Address Line 1 (Door No. / Street / Building) <span class="text-danger">*</span></label>
                  <input type="text" name="address" class="form-control" required value="<?php echo htmlspecialchars($customer['address'] ?? ''); ?>" placeholder="e.g. 12 Main Road, Gandhi Nagar">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Address Line 2 <span class="text-muted fw-normal">(Optional)</span></label>
                  <input type="text" name="address_line2" class="form-control" value="<?php echo htmlspecialchars($customer['address_line2'] ?? ''); ?>" placeholder="Apartment, Suite, Unit, etc.">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Area / Locality <span class="text-danger">*</span></label>
                  <input type="text" name="area" class="form-control" required value="<?php echo htmlspecialchars($customer['area'] ?? ''); ?>" placeholder="e.g. Anna Nagar, Near Bus Stand">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">City <span class="text-danger">*</span></label>
                  <input type="text" name="city" class="form-control" required value="<?php echo htmlspecialchars($customer['city'] ?? ''); ?>" placeholder="e.g. Madurai">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">District <span class="text-danger">*</span></label>
                  <input type="text" id="accountDistrict" name="district" class="form-control" required value="<?php echo htmlspecialchars($customer['district'] ?? ''); ?>" placeholder="e.g. Madurai">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">State <span class="text-danger">*</span></label>
                  <input type="text" name="state" class="form-control" required value="<?php echo htmlspecialchars($customer['state'] ?? 'Tamil Nadu'); ?>" placeholder="Tamil Nadu">
                </div>
                <div class="col-md-6">
                  <label class="form-label small fw-semibold">Pincode <span class="text-danger">*</span></label>
                  <input type="text" id="accountPincode" name="pincode" class="form-control" required pattern="[0-9]{6}" title="6-digit pincode" value="<?php echo htmlspecialchars($customer['pincode'] ?? ''); ?>" placeholder="e.g. 625001">
                </div>
                <div class="col-md-12">
                  <label class="form-label small fw-semibold">Landmark <span class="text-muted fw-normal">(Optional)</span></label>
                  <input type="text" name="landmark" class="form-control" value="<?php echo htmlspecialchars($customer['landmark'] ?? ''); ?>" placeholder="e.g. Opposite Government High School">
                </div>
              </div>
            </div>

            <!-- SECTION 3: BUSINESS / TAX INFORMATION -->
            <div class="mb-4">
              <h6 class="fw-bold text-navy mb-3 pb-1 border-bottom text-uppercase small letter-spacing-1">
                <i class="bi bi-file-earmark-text text-gold me-1"></i> GST Registration & Tax Information
              </h6>
              <?php 
              $current_gst_is_reg = (!empty($_POST['gst_registration'])) 
                  ? (strcasecmp($_POST['gst_registration'], 'Registered') === 0) 
                  : ((!empty($customer['gst_status']) && strcasecmp($customer['gst_status'], 'Registered') === 0) || !empty($customer['gst_number']));
              $current_gstin_val = $_POST['gst_number'] ?? ($current_gst_is_reg ? ($customer['gst_number'] ?? '') : '');
              ?>

              <!-- GST Registration Selection -->
              <label class="form-label small fw-semibold text-navy d-block mb-2">GST Registration Status <span class="text-danger fw-bold">*</span></label>
              <div class="d-flex align-items-center gap-4 mb-3">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="gst_registration" id="account_gst_not_registered" value="Not Registered" <?php echo !$current_gst_is_reg ? 'checked' : ''; ?> onchange="handleAccountGstRadioChange()">
                  <label class="form-check-label fw-semibold text-dark" for="account_gst_not_registered">
                    <i class="bi bi-x-circle text-muted me-1"></i> Not Registered
                  </label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="gst_registration" id="account_gst_registered" value="Registered" <?php echo $current_gst_is_reg ? 'checked' : ''; ?> onchange="handleAccountGstRadioChange()">
                  <label class="form-check-label fw-semibold text-dark" for="account_gst_registered">
                    <i class="bi bi-check-circle-fill text-success me-1"></i> Registered
                  </label>
                </div>
              </div>

              <!-- Mandatory GSTIN Input Container (Displayed only when Registered) -->
              <div id="account_gstin_container" style="<?php echo $current_gst_is_reg ? '' : 'display: none;'; ?>">
                <label class="form-label small fw-semibold text-navy mb-1" for="account_gst_input">
                  GSTIN Number <span class="text-danger fw-bold">*</span>
                </label>
                <div class="input-group">
                  <span class="input-group-text bg-light text-navy fw-bold small"><i class="bi bi-shield-check text-gold"></i></span>
                  <input type="text" name="gst_number" id="account_gst_input" class="form-control text-uppercase font-monospace fw-bold" maxlength="15" value="<?php echo htmlspecialchars($current_gstin_val); ?>" placeholder="e.g. 33AAAAA0000A1Z5" autocomplete="off">
                </div>
                <div class="form-text small text-muted">Standard 15-character Indian GSTIN (e.g. 33AAAAA0000A1Z5).</div>
                <div id="account_gst_feedback" class="small text-danger mt-1 fw-semibold" style="display: none;"></div>
              </div>
            </div>

            <!-- SECTION 4: CUSTOMER ID REGENERATION OPTION -->
            <div class="mb-4">
              <div class="p-3 bg-light rounded-3 border">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="regenerate_id" value="1" id="accountRegenerateId">
                  <label class="form-check-label fw-semibold text-navy small" for="accountRegenerateId">
                    <i class="bi bi-arrow-repeat text-gold me-1"></i> Update / Regenerate Customer ID to reflect changes in Name, District, or Customer Type
                  </label>
                </div>
                <div class="small text-muted ps-4 mt-1">
                  Leave unchecked to keep your current Customer ID (<strong><?php echo htmlspecialchars($customer['customer_code'] ?? ''); ?></strong>). If checked, your Customer ID will be updated based on your latest Name, District, and Customer Type, while keeping all previous orders, invoices, and QR codes permanently linked.
                </div>
              </div>
            </div>

            <!-- Action Buttons -->
            <div class="d-flex align-items-center gap-2 pt-2">
              <button type="submit" class="btn btn-gold px-4 py-2 fw-semibold">
                <i class="bi bi-check2-circle me-1"></i> Save Address & Profile
              </button>
              <?php if ($has_saved_address): ?>
                <button type="button" class="btn btn-outline-secondary px-3 py-2 fw-semibold" onclick="toggleEditAddress()">
                  Cancel
                </button>
              <?php endif; ?>
            </div>
          </form>
        </div>

      </div>

      <!-- Recent Orders List -->
      <div class="bg-white p-4 rounded-3 border shadow-sm">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h4 class="fw-bold text-navy mb-0">Recent Orders</h4>
          <a href="orders.php" class="view-all-link">View All <i class="bi bi-arrow-right"></i></a>
        </div>

        <?php if (empty($orders)): ?>
          <p class="text-muted mb-0">You have not placed any orders yet.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr class="table-light">
                  <th>Order ID</th>
                  <th>Date</th>
                  <th>Amount</th>
                  <th>Status</th>
                  <th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach (array_slice($orders, 0, 5) as $ord): ?>
                  <tr>
                    <td class="fw-bold text-navy"><?php echo htmlspecialchars($ord['order_number']); ?></td>
                    <td class="small text-muted"><?php echo format_datetime($ord['created_at']); ?></td>
                    <td class="fw-semibold"><?php echo format_price($ord['grand_total']); ?></td>
                    <td>
                      <span class="badge bg-gold"><?php echo htmlspecialchars($ord['order_status']); ?></span>
                    </td>
                    <td><a href="order-details.php?id=<?php echo $ord['id']; ?>" class="btn btn-outline-gold btn-sm">View Details</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<script>
function toggleEditAddress() {
    const view = document.getElementById('savedAddressView');
    const form = document.getElementById('editAddressForm');
    const btnText = document.getElementById('toggleAddressBtnText');
    
    if (form.style.display === 'none' || form.style.display === '') {
        form.style.display = 'block';
        if (view) view.style.display = 'none';
        btnText.textContent = 'Cancel Edit';
    } else {
        form.style.display = 'none';
        if (view) view.style.display = 'block';
        btnText.textContent = '<?php echo $has_saved_address ? "Edit Address" : "Add Saved Address"; ?>';
    }
}

// Frontend GST Registration Toggle & Validation
function handleAccountGstRadioChange() {
    const isReg = document.getElementById('account_gst_registered').checked;
    const container = document.getElementById('account_gstin_container');
    const input = document.getElementById('account_gst_input');
    const feedback = document.getElementById('account_gst_feedback');

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

const gstInput = document.getElementById('account_gst_input');
const gstFeedback = document.getElementById('account_gst_feedback');
const gstRegex = /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/;

function validateAccountGST() {
    const isReg = document.getElementById('account_gst_registered').checked;
    if (!isReg) {
        if (gstInput) gstInput.value = '';
        return true;
    }

    const val = gstInput ? gstInput.value.trim().toUpperCase() : '';
    if (val === '') {
        if (gstFeedback) {
            gstFeedback.textContent = 'GSTIN Number is mandatory when Registered is selected.';
            gstFeedback.style.display = 'block';
        }
        if (gstInput) gstInput.classList.add('is-invalid');
        return false;
    }

    if (!gstRegex.test(val)) {
        if (gstFeedback) {
            gstFeedback.textContent = 'Please enter a valid 15-character Indian GSTIN (e.g. 33AAAAA0000A1Z5).';
            gstFeedback.style.display = 'block';
        }
        if (gstInput) gstInput.classList.add('is-invalid');
        return false;
    }

    if (gstFeedback) gstFeedback.style.display = 'none';
    if (gstInput) gstInput.classList.remove('is-invalid');
    return true;
}

if (gstInput) {
    gstInput.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
        if (gstFeedback && gstFeedback.style.display !== 'none') {
            validateAccountGST();
        }
    });
}

const formEl = document.getElementById('profileAddressForm');
if (formEl) {
    formEl.addEventListener('submit', function(e) {
        if (!validateAccountGST()) {
            e.preventDefault();
            if (gstInput) gstInput.focus();
        }
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
