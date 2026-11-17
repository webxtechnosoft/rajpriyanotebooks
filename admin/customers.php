<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();

// Handle Status Toggle
if (isset($_GET['action']) && $_GET['action'] === 'toggle' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("UPDATE users SET status = IF(status='active', 'inactive', 'active') WHERE id = ?");
    $stmt->execute([$id]);
    set_flash('success', 'Customer account status updated.');
    $redirect = 'customers.php' . (isset($_GET['view']) ? '?view=' . urlencode($_GET['view']) : '');
    header("Location: " . $redirect);
    exit();
}

// Handle Customer Edit POST (Preserves Customer ID unless explicit regeneration requested)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_customer') {
    $cust_id = (int)($_POST['customer_id'] ?? 0);
    $name = sanitize($_POST['name'] ?? '');
    $phone = sanitize($_POST['phone'] ?? '');
    $email_raw = trim($_POST['email'] ?? '');
    $email = !empty($email_raw) ? sanitize($email_raw) : null;
    $customer_type = trim($_POST['customer_type'] ?? 'Retailer');
    if (!is_valid_customer_type($customer_type)) {
        $customer_type = 'Retailer';
    }
    $regenerate_id = !empty($_POST['regenerate_id']);

    $address = sanitize($_POST['address'] ?? '');
    $address_line2 = sanitize($_POST['address_line2'] ?? '');
    $area = sanitize($_POST['area'] ?? '');
    $city = sanitize($_POST['city'] ?? '');
    $district = sanitize($_POST['district'] ?? '');
    $state = sanitize($_POST['state'] ?? 'Tamil Nadu');
    $pincode = sanitize($_POST['pincode'] ?? '');
    $landmark = sanitize($_POST['landmark'] ?? '');
    $gst_status = sanitize($_POST['gst_status'] ?? 'Not Registered');
    $gst_number = ($gst_status === 'Registered' && !empty($_POST['gst_number'])) ? strtoupper(trim($_POST['gst_number'])) : null;

    if ($cust_id > 0 && !empty($name) && !empty($phone)) {
        $stmt_up = $db->prepare("
            UPDATE users SET 
                name = ?, phone = ?, email = ?, customer_type = ?,
                address = ?, address_line2 = ?, area = ?, city = ?, district = ?, state = ?, pincode = ?, landmark = ?,
                gst_status = ?, gst_number = ?
            WHERE id = ?
        ");
        $stmt_up->execute([
            $name, $phone, $email, $customer_type,
            $address, $address_line2, $area, $city, $district, $state, $pincode, $landmark,
            $gst_status, $gst_number,
            $cust_id
        ]);

        if ($regenerate_id) {
            $newCode = regenerate_customer_code($cust_id, $district ?: $city, $name, $customer_type);
            set_flash('success', "Customer details updated and Customer ID safely regenerated to {$newCode}. Order history preserved.");
            $redirect = 'customers.php?view=' . urlencode($newCode);
            header("Location: " . $redirect);
            exit();
        } else {
            set_flash('success', 'Customer details updated successfully without altering Customer ID.');
        }
    } else {
        set_flash('error', 'Customer name and phone number are required.');
    }
    $redirect = 'customers.php' . (!empty($_POST['view_code']) ? '?view=' . urlencode($_POST['view_code']) : '');
    header("Location: " . $redirect);
    exit();
}

// Handle Safe Customer ID Regeneration (Supports new format and migration)
if (isset($_GET['action']) && in_array($_GET['action'], ['regenerate_id', 'migrate_id'], true) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $newCode = regenerate_customer_code($id);
    if ($newCode) {
        set_flash('success', "Customer ID safely regenerated to {$newCode}. All historical orders and invoices remain permanently linked.");
        header("Location: customers.php?view=" . urlencode($newCode));
        exit();
    } else {
        set_flash('error', "Failed to regenerate Customer ID.");
        header("Location: customers.php");
        exit();
    }
}

// Check if viewing a specific customer
$view_code = isset($_GET['view']) ? trim($_GET['view']) : '';
$customer_detail = null;
$customer_orders = [];

if (!empty($view_code)) {
    $customer_detail = get_customer_by_code($view_code);
    if ($customer_detail) {
        $customer_orders = get_customer_orders_history($customer_detail['customer_code'] ?? $customer_detail['id']);
    }
}

// Fetch list of customers with search & pagination
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$whereClauses = ["1=1"];
$params = [];

if ($search !== '') {
    $term = "%{$search}%";
    $num_id = is_numeric($search) ? (int)$search : 0;
    
    $searchParts = [
        "u.name LIKE ?",
        "u.customer_code LIKE ?",
        "u.customer_type LIKE ?",
        "u.previous_customer_codes LIKE ?",
        "u.phone LIKE ?",
        "u.email LIKE ?",
        "u.gst_number LIKE ?",
        "u.address LIKE ?",
        "u.address_line2 LIKE ?",
        "u.area LIKE ?",
        "u.city LIKE ?",
        "u.district LIKE ?",
        "u.state LIKE ?",
        "u.pincode LIKE ?",
        "u.landmark LIKE ?"
    ];
    $params = array_fill(0, count($searchParts), $term);
    
    if ($num_id > 0) {
        $searchParts[] = "u.id = ?";
        $params[] = $num_id;
    }
    
    $whereClauses[] = "(" . implode(" OR ", $searchParts) . ")";
}

$whereSql = implode(" AND ", $whereClauses);

// Count total matching customers for pagination
$countStmt = $db->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
$countStmt->execute($params);
$total_customers_count = (int)$countStmt->fetchColumn();
$total_pages = max(1, ceil($total_customers_count / $per_page));

// Fetch paginated customers
$sql = "
    SELECT 
        u.*, 
        (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id OR o.customer_code = u.customer_code) as order_count,
        (SELECT COALESCE(SUM(o.grand_total), 0) FROM orders o WHERE (o.user_id = u.id OR o.customer_code = u.customer_code) AND o.order_status != 'Cancelled') as total_spent,
        (SELECT MAX(o.created_at) FROM orders o WHERE o.user_id = u.id OR o.customer_code = u.customer_code) as latest_order_date
    FROM users u 
    WHERE $whereSql
    ORDER BY u.id DESC
    LIMIT $per_page OFFSET $offset
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/admin_header.php';
?>

<?php if ($customer_detail): ?>
  <!-- ========================================================================= -->
  <!-- SINGLE CUSTOMER DETAIL & COMPLETE ORDER HISTORY VIEW                      -->
  <!-- ========================================================================= -->

  <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 admin-page-header">
    <div>
      <div class="d-flex align-items-center gap-2 mb-1">
        <a href="customers.php" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-arrow-left me-1"></i> Back to Customers List
        </a>
        <span class="badge bg-navy text-gold fw-bold px-2.5 py-1.5 fs-7">
          <i class="bi bi-person-badge me-1"></i> Customer ID: <?php echo htmlspecialchars($customer_detail['customer_code'] ?? 'CUST' . str_pad($customer_detail['id'], 5, '0', STR_PAD_LEFT)); ?>
        </span>
        <span class="badge bg-gold text-navy fw-bold px-2.5 py-1.5 fs-7">
          <i class="bi bi-briefcase me-1"></i> <?php echo htmlspecialchars($customer_detail['customer_type'] ?? 'Retailer'); ?>
        </span>
      </div>
      <h3 class="fw-bold text-navy mb-0">Customer Profile: <?php echo htmlspecialchars($customer_detail['name']); ?></h3>
      <p class="text-secondary small mb-0">View full profile details, saved addresses, lifetime metrics, and complete order history snapshot.</p>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
      <?php 
      $cust_qr_link = get_customer_tracking_url($customer_detail['customer_code']);
      $cust_qr_img = generate_qr_code_image_url($cust_qr_link, 200);
      ?>
      <a href="print_customer_card.php?id=<?php echo $customer_detail['id']; ?>" target="_blank" class="btn btn-navy text-gold btn-sm px-2.5 fw-bold" title="Print Physical Customer Card / Label Sticker">
        <i class="bi bi-printer me-1"></i> Print ID Card
      </a>
      <a href="<?php echo $cust_qr_img; ?>" download="QR-<?php echo htmlspecialchars($customer_detail['customer_code']); ?>.png" target="_blank" class="btn btn-outline-secondary btn-sm px-2.5 fw-bold" title="Download High-Res Customer QR Code PNG">
        <i class="bi bi-download me-1"></i> QR PNG
      </a>
      <a href="<?php echo htmlspecialchars($cust_qr_link); ?>" target="_blank" class="btn btn-outline-dark btn-sm px-2.5 fw-bold" title="Open Customer Verified QR Tracking Page">
        <i class="bi bi-qr-code text-gold me-1"></i> Customer QR
      </a>
      <button type="button" class="btn btn-outline-primary btn-sm px-2.5 fw-bold" data-bs-toggle="modal" data-bs-target="#editCustomerModal" title="Edit Customer Contact & Address Details">
        <i class="bi bi-pencil-square me-1"></i> Edit Profile
      </button>
      <a href="customers.php?action=regenerate_id&id=<?php echo $customer_detail['id']; ?>" class="btn btn-outline-warning text-dark btn-sm px-2.5 fw-bold" onclick="return confirm('Regenerate Customer ID based on updated Name, District, and Customer Type? Historical orders and invoices remain permanently preserved.');" title="Regenerate Customer ID matching current details">
        <i class="bi bi-arrow-repeat me-1"></i> Regenerate ID
      </a>
      <a href="customers.php?action=toggle&id=<?php echo $customer_detail['id']; ?>&view=<?php echo urlencode($customer_detail['customer_code']); ?>" class="btn btn-sm <?php echo ($customer_detail['status']==='active') ? 'btn-outline-danger' : 'btn-outline-success'; ?>">
        <i class="bi <?php echo ($customer_detail['status']==='active') ? 'bi-slash-circle' : 'bi-check-circle'; ?> me-1"></i>
        <?php echo ($customer_detail['status']==='active') ? 'Disable Account' : 'Activate Account'; ?>
      </a>
    </div>
  </div>

  <!-- Summary Metric Cards -->
  <div class="row g-3 mb-4">
    <div class="col-md-3 col-sm-6">
      <div class="stat-card">
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon bg-navy text-gold"><i class="bi bi-person-badge"></i></div>
          <div>
            <div class="small text-muted fw-semibold">Customer ID</div>
            <h5 class="fw-bold text-navy mb-0"><?php echo htmlspecialchars($customer_detail['customer_code']); ?></h5>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-sm-6">
      <div class="stat-card">
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon"><i class="bi bi-box-seam"></i></div>
          <div>
            <div class="small text-muted fw-semibold">Total Orders</div>
            <h5 class="fw-bold text-navy mb-0"><?php echo $customer_detail['total_orders']; ?> Order(s)</h5>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-sm-6">
      <div class="stat-card">
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon"><i class="bi bi-currency-rupee"></i></div>
          <div>
            <div class="small text-muted fw-semibold">Total Purchase Amount</div>
            <h5 class="fw-bold text-gold mb-0"><?php echo format_price($customer_detail['total_spent']); ?></h5>
          </div>
        </div>
      </div>
    </div>
    <div class="col-md-3 col-sm-6">
      <div class="stat-card">
        <div class="d-flex align-items-center gap-3">
          <div class="stat-icon"><i class="bi bi-calendar-event"></i></div>
          <div>
            <div class="small text-muted fw-semibold">Latest Order Date</div>
            <h6 class="fw-bold text-navy mb-0">
              <?php echo !empty($customer_detail['latest_order_date']) ? format_datetime($customer_detail['latest_order_date']) : 'No Orders Yet'; ?>
            </h6>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Customer Contact, Delivery Address & Verified QR Badge Cards -->
  <div class="row g-4 mb-4">
    <!-- 1. Contact & Account Information -->
    <div class="col-lg-4 col-md-6">
      <div class="bg-white p-4 rounded-3 border h-100 shadow-sm">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
          <h5 class="fw-bold text-navy mb-0">
            <i class="bi bi-person-lines-fill text-gold me-2"></i> Contact Info
          </h5>
          <button type="button" class="btn btn-outline-secondary btn-sm px-2 py-0.5" data-bs-toggle="modal" data-bs-target="#editCustomerModal" title="Edit Contact Details">
            <i class="bi bi-pencil"></i>
          </button>
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-borderless align-middle mb-0">
            <tr>
              <td class="text-muted fw-semibold" style="width: 120px;">Customer ID:</td>
              <td><span class="badge bg-navy text-gold fw-bold"><?php echo htmlspecialchars($customer_detail['customer_code']); ?></span></td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">Customer Type:</td>
              <td>
                <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold px-2 py-1">
                  <i class="bi bi-briefcase me-1"></i><?php echo htmlspecialchars($customer_detail['customer_type'] ?? 'Retailer'); ?>
                </span>
              </td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">Full Name:</td>
              <td class="fw-bold text-navy"><?php echo htmlspecialchars($customer_detail['name']); ?></td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">Mobile:</td>
              <td>
                <a href="tel:<?php echo htmlspecialchars($customer_detail['phone']); ?>" class="fw-bold text-decoration-none text-navy">
                  <i class="bi bi-telephone text-gold me-1"></i><?php echo htmlspecialchars($customer_detail['phone']); ?>
                </a>
              </td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">Email:</td>
              <td>
                <?php if (!empty($customer_detail['email'])): ?>
                  <a href="mailto:<?php echo htmlspecialchars($customer_detail['email']); ?>" class="text-decoration-none">
                    <i class="bi bi-envelope text-muted me-1"></i><?php echo htmlspecialchars($customer_detail['email']); ?>
                  </a>
                <?php else: ?>
                  <span class="text-muted fst-italic">Not Provided</span>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">GSTIN:</td>
              <td>
                <?php 
                $c_is_reg = (!empty($customer_detail['gst_status']) && strcasecmp($customer_detail['gst_status'], 'Registered') === 0) || !empty($customer_detail['gst_number']);
                if ($c_is_reg && !empty($customer_detail['gst_number'])): ?>
                  <span class="badge bg-success me-1"><i class="bi bi-check-circle-fill me-0.5"></i>Registered</span>
                  <span class="badge bg-gold text-navy fw-bold px-2 py-0.5"><?php echo htmlspecialchars($customer_detail['gst_number']); ?></span>
                <?php else: ?>
                  <span class="badge bg-secondary"><i class="bi bi-x-circle me-0.5"></i>Not Registered</span>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">Status:</td>
              <td>
                <?php if ($customer_detail['status'] === 'active'): ?>
                  <span class="badge bg-success">Active</span>
                <?php else: ?>
                  <span class="badge bg-danger">Disabled</span>
                <?php endif; ?>
              </td>
            </tr>
            <tr>
              <td class="text-muted fw-semibold">Joined:</td>
              <td class="small text-muted"><?php echo format_date($customer_detail['created_at']); ?></td>
            </tr>
          </table>
        </div>
      </div>
    </div>

    <!-- 2. Saved Delivery Address -->
    <div class="col-lg-4 col-md-6">
      <div class="bg-white p-4 rounded-3 border h-100 shadow-sm">
        <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
          <h5 class="fw-bold text-navy mb-0">
            <i class="bi bi-geo-alt-fill text-gold me-2"></i> Delivery Address
          </h5>
          <button type="button" class="btn btn-outline-secondary btn-sm px-2 py-0.5" data-bs-toggle="modal" data-bs-target="#editCustomerModal" title="Edit Address">
            <i class="bi bi-pencil"></i>
          </button>
        </div>
        <?php 
        $has_addr = !empty($customer_detail['address']) || !empty($customer_detail['city']);
        ?>
        <?php if ($has_addr): ?>
          <div class="p-3 bg-light rounded-3 border">
            <div class="fw-bold text-navy fs-6 mb-1"><?php echo htmlspecialchars($customer_detail['name']); ?></div>
            <div class="text-dark small lh-base">
              <div><?php echo htmlspecialchars($customer_detail['address']); ?></div>
              <?php if (!empty($customer_detail['address_line2'])): ?>
                <div><?php echo htmlspecialchars($customer_detail['address_line2']); ?></div>
              <?php endif; ?>
              <?php if (!empty($customer_detail['area'])): ?>
                <div>Area: <?php echo htmlspecialchars($customer_detail['area']); ?></div>
              <?php endif; ?>
              <div class="fw-bold text-navy mt-1">
                <?php echo htmlspecialchars($customer_detail['city']); ?>
                <?php if (!empty($customer_detail['district']) && strcasecmp($customer_detail['district'], $customer_detail['city']) !== 0): ?>
                  , <?php echo htmlspecialchars($customer_detail['district']); ?>
                <?php endif; ?>
                , <?php echo htmlspecialchars($customer_detail['state'] ?? 'Tamil Nadu'); ?> - <?php echo htmlspecialchars($customer_detail['pincode']); ?>
              </div>
              <?php if (!empty($customer_detail['landmark'])): ?>
                <div class="fst-italic text-secondary mt-1"><small>Landmark: <?php echo htmlspecialchars($customer_detail['landmark']); ?></small></div>
              <?php endif; ?>
            </div>
          </div>
        <?php else: ?>
          <div class="p-4 bg-light rounded-3 border text-center text-muted">
            <i class="bi bi-geo-alt fs-2 text-muted mb-2 d-block"></i>
            No default shipping address recorded yet.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- 3. Customer QR Code & ID Badge Card -->
    <div class="col-lg-4 col-md-12">
      <div class="bg-white p-4 rounded-3 border h-100 shadow-sm text-center d-flex flex-column justify-content-between">
        <div>
          <h5 class="fw-bold text-navy border-bottom pb-2 mb-3 text-start">
            <i class="bi bi-qr-code text-gold me-2"></i> Verified Customer QR
          </h5>
          <div class="p-2.5 bg-light rounded-3 border d-inline-block shadow-sm mb-2.5">
            <img src="<?php echo htmlspecialchars($cust_qr_img); ?>" alt="Customer QR Code" style="width: 135px; height: 135px; display: block;" class="rounded mx-auto">
          </div>
          <div class="mb-2">
            <span class="badge bg-navy text-gold fw-bold px-3 py-1.5 fs-6 letter-spacing-1">
              <?php echo htmlspecialchars($customer_detail['customer_code']); ?>
            </span>
          </div>
          <p class="text-secondary small mb-0 lh-sm" style="font-size: 0.775rem;">
            Encodes secure customer verification URL. Scan with smartphone camera to view verified profile & lifetime orders.
          </p>
        </div>
        <div class="d-flex flex-column gap-2 mt-3 pt-3 border-top">
          <div class="row g-2">
            <div class="col-6">
              <a href="<?php echo $cust_qr_img; ?>" download="QR-<?php echo htmlspecialchars($customer_detail['customer_code']); ?>.png" target="_blank" class="btn btn-outline-secondary btn-sm w-100 fw-semibold" style="font-size: 0.775rem;">
                <i class="bi bi-download me-1"></i> PNG
              </a>
            </div>
            <div class="col-6">
              <a href="print_customer_card.php?id=<?php echo $customer_detail['id']; ?>" target="_blank" class="btn btn-navy text-gold btn-sm w-100 fw-semibold" style="font-size: 0.775rem;">
                <i class="bi bi-printer me-1"></i> Print Card
              </a>
            </div>
          </div>
          <a href="<?php echo htmlspecialchars($cust_qr_link); ?>" target="_blank" class="btn btn-outline-dark btn-sm w-100 fw-semibold" style="font-size: 0.775rem;">
            <i class="bi bi-box-arrow-up-right me-1"></i> Open Verification Page
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Complete Customer Order History Section -->
  <div class="bg-white p-4 rounded-3 border shadow-sm mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <div>
        <h4 class="fw-bold text-navy mb-0">
          <i class="bi bi-clock-history text-gold me-2"></i> Complete Order History
        </h4>
        <small class="text-secondary">Historical orders permanently linked to Customer ID <strong><?php echo htmlspecialchars($customer_detail['customer_code']); ?></strong>.</small>
      </div>
      <span class="badge bg-navy text-gold fw-bold px-2.5 py-1">
        <?php echo count($customer_orders); ?> Recorded Order(s)
      </span>
    </div>

    <?php if (empty($customer_orders)): ?>
      <div class="text-center py-5 bg-light rounded-3 border">
        <i class="bi bi-box-seam fs-1 text-muted mb-2 d-block"></i>
        <h5 class="text-navy fw-bold">No Orders Placed Yet</h5>
        <p class="text-secondary small mb-0">This customer has not placed any orders so far.</p>
      </div>
    <?php else: ?>
      <div class="d-flex flex-column gap-4">
        <?php foreach ($customer_orders as $ord_idx => $ord): ?>
          <div class="border rounded-3 overflow-hidden shadow-sm">
            <!-- Order Header Bar -->
            <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-navy text-gold fw-bold fs-7">#<?php echo ($ord_idx + 1); ?></span>
                <span class="fw-bold text-navy fs-6">Order #: <?php echo htmlspecialchars($ord['order_number']); ?></span>
                <span class="text-muted small">&bull; <?php echo format_datetime($ord['created_at']); ?></span>
              </div>
              <div class="d-flex align-items-center gap-2 flex-wrap">
                <span class="badge bg-light text-dark border"><?php echo htmlspecialchars($ord['payment_method']); ?></span>
                <?php if ($ord['payment_status'] === 'Paid'): ?>
                  <span class="badge bg-success">Paid</span>
                <?php else: ?>
                  <span class="badge bg-warning text-dark">Payment Pending</span>
                <?php endif; ?>
                <?php echo render_order_status_badge($ord['order_status']); ?>
                <a href="orders.php?view=<?php echo $ord['id']; ?>" class="btn btn-sm btn-gold fw-bold px-2.5">
                  <i class="bi bi-eye-fill me-1"></i> View Order Snapshot & Invoice
                </a>
              </div>
            </div>

            <!-- Ordered Products Snapshot Table -->
            <div class="p-3 bg-white">
              <div class="table-responsive">
                <table class="table table-sm align-middle table-bordered mb-0">
                  <thead class="table-light small">
                    <tr>
                      <th style="width: 35px;" class="text-center">#</th>
                      <th>Product & Variant Configuration</th>
                      <th style="width: 130px;" class="text-center">Product Code</th>
                      <th style="width: 80px;" class="text-center">Qty</th>
                      <th style="width: 110px;" class="text-end">Price at Order Time</th>
                      <th style="width: 120px;" class="text-end">Subtotal</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php 
                    $item_sn = 1;
                    foreach ($ord['items'] as $itm): 
                      $vInfo = parse_variety_info($itm['ruling_type'] ?? '');
                      $pg = !empty($itm['page_count']) ? $itm['page_count'] : (!empty($vInfo['pages']) ? $vInfo['pages'] : null);
                      $vr = !empty($itm['variety']) ? $itm['variety'] : (!empty($vInfo['variety']) ? $vInfo['variety'] : 'Standard');
                      $ot = !empty($itm['order_type']) ? $itm['order_type'] : ((stripos($itm['ruling_type'] ?? '', 'Bundle') !== false) ? 'Bundle' : 'Single Piece');
                    ?>
                      <tr>
                        <td class="text-center small text-muted"><?php echo $item_sn++; ?></td>
                        <td>
                          <div class="fw-bold text-navy"><?php echo htmlspecialchars($itm['product_name']); ?></div>
                          <div class="small text-secondary mt-0.5">
                            <?php if ($pg): ?>
                              <span class="badge bg-light text-navy border me-1"><?php echo htmlspecialchars($pg); ?> Pages</span>
                            <?php endif; ?>
                            <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold me-1">Variety: <?php echo htmlspecialchars($vr); ?></span>
                            <span class="badge bg-secondary-subtle text-dark border me-1"><?php echo htmlspecialchars($ot); ?></span>
                            <span class="text-muted small">&bull; GST <?php echo (float)$itm['gst_rate']; ?>%</span>
                          </div>
                        </td>
                        <td class="text-center font-monospace small fw-bold text-navy"><?php echo htmlspecialchars($itm['product_code'] ?: '-'); ?></td>
                        <td class="text-center fw-bold"><?php echo $itm['quantity']; ?></td>
                        <td class="text-end font-monospace"><?php echo format_price($itm['price']); ?></td>
                        <td class="text-end font-monospace fw-bold text-navy"><?php echo format_price($itm['total_price']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                  <tfoot class="table-light small">
                    <tr>
                      <td colspan="5" class="text-end fw-semibold">Taxable Subtotal:</td>
                      <td class="text-end fw-semibold font-monospace"><?php echo format_price($ord['subtotal']); ?></td>
                    </tr>
                    <?php if ($ord['discount'] > 0): ?>
                      <tr>
                        <td colspan="5" class="text-end text-success fw-semibold">Discount:</td>
                        <td class="text-end text-success fw-semibold font-monospace">-<?php echo format_price($ord['discount']); ?></td>
                      </tr>
                    <?php endif; ?>
                    <?php if ($ord['delivery_fee'] > 0): ?>
                      <tr>
                        <td colspan="5" class="text-end fw-semibold">Delivery Fee:</td>
                        <td class="text-end font-monospace"><?php echo format_price($ord['delivery_fee']); ?></td>
                      </tr>
                    <?php endif; ?>
                    <?php if ($ord['total_gst'] > 0): ?>
                      <tr>
                        <td colspan="5" class="text-end fw-semibold">Total GST:</td>
                        <td class="text-end font-monospace"><?php echo format_price($ord['total_gst']); ?></td>
                      </tr>
                    <?php endif; ?>
                    <tr class="fs-6 fw-bold">
                      <td colspan="5" class="text-end text-navy">Final Order Total:</td>
                      <td class="text-end text-gold font-monospace"><?php echo format_price($ord['grand_total']); ?></td>
                    </tr>
                  </tfoot>
                </table>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <!-- Edit Customer Profile Modal -->
  <div class="modal fade" id="editCustomerModal" tabindex="-1" aria-labelledby="editCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
        <form action="customers.php" method="POST">
          <input type="hidden" name="action" value="edit_customer">
          <input type="hidden" name="customer_id" value="<?php echo $customer_detail['id']; ?>">
          <input type="hidden" name="view_code" value="<?php echo htmlspecialchars($customer_detail['customer_code']); ?>">

          <div class="modal-header bg-navy text-white p-3 px-4">
            <h5 class="modal-title fw-bold text-gold" id="editCustomerModalLabel">
              <i class="bi bi-pencil-square me-2"></i> Edit Customer Profile: <?php echo htmlspecialchars($customer_detail['name']); ?>
            </h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>

          <div class="modal-body p-4 bg-light">
            <div class="alert alert-info py-2 px-3 small rounded-3 mb-3 d-flex align-items-center gap-2">
              <i class="bi bi-info-circle-fill fs-6 flex-shrink-0"></i>
              <div>Customer ID: <strong><?php echo htmlspecialchars($customer_detail['customer_code']); ?></strong> remains permanently preserved across edits.</div>
            </div>

            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label small fw-bold text-navy">Full Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" required value="<?php echo htmlspecialchars($customer_detail['name']); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-bold text-navy">Mobile Phone <span class="text-danger">*</span></label>
                <input type="tel" name="phone" class="form-control" required value="<?php echo htmlspecialchars($customer_detail['phone']); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-bold text-navy">Customer Type <span class="text-danger">*</span></label>
                <select name="customer_type" class="form-select" required>
                  <option value="Wholesaler" <?php echo (($customer_detail['customer_type'] ?? '') === 'Wholesaler') ? 'selected' : ''; ?>>Wholesaler</option>
                  <option value="Distributor" <?php echo (($customer_detail['customer_type'] ?? '') === 'Distributor') ? 'selected' : ''; ?>>Distributor</option>
                  <option value="Retailer" <?php echo (($customer_detail['customer_type'] ?? 'Retailer') === 'Retailer') ? 'selected' : ''; ?>>Retailer</option>
                  <option value="Other" <?php echo (($customer_detail['customer_type'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                </select>
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-bold text-navy">Email Address</label>
                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($customer_detail['email'] ?? ''); ?>" placeholder="Optional">
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-bold text-navy">GST Registration</label>
                <select name="gst_status" class="form-select" onchange="document.getElementById('editCustGstWrap').style.display = (this.value==='Registered') ? 'block' : 'none';">
                  <option value="Not Registered" <?php echo ($customer_detail['gst_status'] !== 'Registered') ? 'selected' : ''; ?>>Not Registered</option>
                  <option value="Registered" <?php echo ($customer_detail['gst_status'] === 'Registered') ? 'selected' : ''; ?>>Registered</option>
                </select>
              </div>

              <div class="col-md-12" id="editCustGstWrap" style="<?php echo ($customer_detail['gst_status'] === 'Registered') ? '' : 'display: none;'; ?>">
                <label class="form-label small fw-bold text-navy">GSTIN Number</label>
                <input type="text" name="gst_number" class="form-control" maxlength="15" value="<?php echo htmlspecialchars($customer_detail['gst_number'] ?? ''); ?>" placeholder="15-character GSTIN">
              </div>

              <div class="col-12">
                <label class="form-label small fw-bold text-navy">Street Address</label>
                <input type="text" name="address" class="form-control" value="<?php echo htmlspecialchars($customer_detail['address'] ?? ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-bold text-navy">Address Line 2 / Building</label>
                <input type="text" name="address_line2" class="form-control" value="<?php echo htmlspecialchars($customer_detail['address_line2'] ?? ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-bold text-navy">Area / Locality</label>
                <input type="text" name="area" class="form-control" value="<?php echo htmlspecialchars($customer_detail['area'] ?? ''); ?>">
              </div>

              <div class="col-md-4">
                <label class="form-label small fw-bold text-navy">City / Town</label>
                <input type="text" name="city" class="form-control" value="<?php echo htmlspecialchars($customer_detail['city'] ?? ''); ?>">
              </div>

              <div class="col-md-4">
                <label class="form-label small fw-bold text-navy">District</label>
                <input type="text" name="district" class="form-control" value="<?php echo htmlspecialchars($customer_detail['district'] ?? ''); ?>">
              </div>

              <div class="col-md-4">
                <label class="form-label small fw-bold text-navy">Pincode</label>
                <input type="text" name="pincode" class="form-control" maxlength="10" value="<?php echo htmlspecialchars($customer_detail['pincode'] ?? ''); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-bold text-navy">State</label>
                <input type="text" name="state" class="form-control" value="<?php echo htmlspecialchars($customer_detail['state'] ?? 'Tamil Nadu'); ?>">
              </div>

              <div class="col-md-6">
                <label class="form-label small fw-bold text-navy">Landmark</label>
                <input type="text" name="landmark" class="form-control" value="<?php echo htmlspecialchars($customer_detail['landmark'] ?? ''); ?>">
              </div>

              <div class="col-12 mt-2">
                <div class="form-check p-3 bg-white rounded-3 border">
                  <input class="form-check-input" type="checkbox" name="regenerate_id" value="1" id="adminRegenerateId">
                  <label class="form-check-label small fw-bold text-navy" for="adminRegenerateId">
                    <i class="bi bi-arrow-repeat text-gold me-1"></i> Regenerate Customer ID based on Name, District, & Customer Type
                  </label>
                  <div class="text-muted small ps-4 mt-0.5">
                    Unchecked preserves current Customer ID (<strong><?php echo htmlspecialchars($customer_detail['customer_code'] ?? ''); ?></strong>). If checked, generates a new Customer ID matching these details while keeping all past orders and invoices permanently linked.
                  </div>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer bg-white p-3">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-navy text-gold btn-sm fw-bold px-3">
              <i class="bi bi-check-circle me-1"></i> Save Changes
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>

<?php else: ?>

  <!-- ========================================================================= -->
  <!-- ALL CUSTOMERS LIST VIEW                                                   -->
  <!-- ========================================================================= -->

  <div class="d-flex justify-content-between align-items-center mb-4 admin-page-header">
    <div>
      <h3 class="fw-bold text-navy mb-0">Customer Management</h3>
      <p class="text-secondary small mb-0">Manage customer profiles, Customer IDs, lifetime order counts, total purchase amounts, and complete historical records.</p>
    </div>
  </div>

  <div class="bg-white p-3 rounded-3 border mb-4 shadow-xs">
    <form action="customers.php" method="GET" class="row g-2 align-items-center" id="custSearchForm">
      <div class="col-md-9 col-sm-8">
        <div class="input-group">
          <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
          <input type="text" name="search" id="custSearchInput" class="form-control border-start-0 ps-0" 
                 placeholder="Search by Customer ID (e.g. CUST00001), name, mobile phone, email, city, GST..." 
                 value="<?php echo htmlspecialchars($search); ?>">
          <?php if ($search !== ''): ?>
            <button type="button" class="btn btn-outline-secondary border-start-0" id="custClearInputBtn" title="Clear keyword">
              <i class="bi bi-x-lg"></i>
            </button>
          <?php endif; ?>
        </div>
      </div>
      <div class="col-md-3 col-sm-4 d-flex gap-2">
        <button type="submit" class="btn btn-gold btn-sm w-100 fw-bold py-2">
          <i class="bi bi-search me-1"></i> Search
        </button>
        <?php if ($search !== ''): ?>
          <a href="customers.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center" title="Reset Search" style="min-width: 38px;">
            <i class="bi bi-arrow-counterclockwise"></i>
          </a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="bg-white p-4 rounded-3 border shadow-sm">
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <div class="fw-bold text-navy d-flex align-items-center">
        <i class="bi bi-people text-gold me-2 fs-5"></i>
        <span>Registered Customers</span>
        <span class="badge bg-light text-navy border ms-2"><?php echo number_format($total_customers_count); ?> customers found</span>
        <?php if ($search !== ''): ?>
          <span class="badge bg-gold-subtle text-navy border border-warning-subtle ms-2">Search: "<?php echo htmlspecialchars($search); ?>"</span>
        <?php endif; ?>
      </div>
      <div class="small text-muted fw-semibold">
        Showing page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo count($customers); ?> displayed)
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle">
        <thead>
          <tr class="table-light">
            <th style="width: 150px;">Customer ID & Type</th>
            <th>Name & Registered Date</th>
            <th>Mobile & Email</th>
            <th>City / Delivery Location</th>
            <th class="text-center" style="width: 90px;">Total Orders</th>
            <th class="text-end" style="width: 130px;">Total Spent</th>
            <th style="width: 140px;">Latest Order Date</th>
            <th style="width: 80px;">Status</th>
            <th class="text-end" style="width: 140px;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($customers)): ?>
            <tr>
              <td colspan="9" class="text-center text-muted py-5">
                <i class="bi bi-people fs-2 text-muted mb-2 d-block"></i>
                No customer accounts found matching your search.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($customers as $c): 
              $cCode = !empty($c['customer_code']) ? $c['customer_code'] : 'CUST' . str_pad($c['id'], 5, '0', STR_PAD_LEFT);
            ?>
              <tr>
                <td>
                  <a href="customers.php?view=<?php echo urlencode($cCode); ?>" class="badge bg-navy text-gold fw-bold text-decoration-none px-2 py-1 fs-7 d-inline-block" title="View Customer Profile & Orders">
                    <i class="bi bi-person-badge me-1"></i> <?php echo htmlspecialchars($cCode); ?>
                  </a>
                  <div class="mt-1">
                    <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-semibold" style="font-size: 0.7rem;">
                      <i class="bi bi-briefcase me-1"></i><?php echo htmlspecialchars($c['customer_type'] ?? 'Retailer'); ?>
                    </span>
                  </div>
                </td>
                <td>
                  <a href="customers.php?view=<?php echo urlencode($cCode); ?>" class="fw-bold text-navy fs-6 text-decoration-none d-block">
                    <?php echo htmlspecialchars($c['name']); ?>
                  </a>
                  <small class="text-muted">Registered: <?php echo format_date($c['created_at']); ?></small>
                </td>
                <td>
                  <div><i class="bi bi-telephone text-gold me-1 small"></i> <strong><?php echo htmlspecialchars($c['phone']); ?></strong></div>
                  <div class="small mt-0.5">
                    <?php if (!empty($c['email'])): ?>
                      <i class="bi bi-envelope text-muted me-1"></i><span><?php echo htmlspecialchars($c['email']); ?></span>
                    <?php else: ?>
                      <span class="text-muted fst-italic">No Email</span>
                    <?php endif; ?>
                  </div>
                </td>
                <td>
                  <div class="small text-navy fw-semibold">
                    <?php echo !empty($c['city']) ? htmlspecialchars($c['city']) : '<span class="text-muted fst-italic">City Not Set</span>'; ?>
                    <?php if (!empty($c['state'])): ?>, <?php echo htmlspecialchars($c['state']); ?><?php endif; ?>
                  </div>
                  <?php if (!empty($c['pincode'])): ?>
                    <small class="text-muted">PIN: <?php echo htmlspecialchars($c['pincode']); ?></small>
                  <?php endif; ?>
                </td>
                <td class="text-center">
                  <span class="badge bg-light text-navy border fw-bold px-2 py-1"><?php echo $c['order_count']; ?></span>
                </td>
                <td class="text-end font-monospace fw-bold text-navy">
                  <?php echo format_price($c['total_spent']); ?>
                </td>
                <td class="small text-muted">
                  <?php echo !empty($c['latest_order_date']) ? format_datetime($c['latest_order_date']) : '<span class="fst-italic text-muted">No Orders</span>'; ?>
                </td>
                <td>
                  <?php if ($c['status'] === 'active'): ?>
                    <span class="badge bg-success">Active</span>
                  <?php else: ?>
                    <span class="badge bg-danger">Disabled</span>
                  <?php endif; ?>
                </td>
                <td class="text-end">
                  <div class="d-inline-flex gap-1">
                    <a href="customers.php?view=<?php echo urlencode($cCode); ?>" class="btn btn-sm btn-gold fw-semibold" title="View Customer and Previous Orders">
                      <i class="bi bi-eye-fill me-1"></i> View
                    </a>
                    <a href="print_customer_card.php?id=<?php echo $c['id']; ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Print Customer ID Card / QR Code Label">
                      <i class="bi bi-printer"></i>
                    </a>
                    <a href="customers.php?action=toggle&id=<?php echo $c['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Toggle Account Status">
                      <i class="bi <?php echo ($c['status']==='active') ? 'bi-lock' : 'bi-unlock'; ?>"></i>
                    </a>
                  </div>
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
          Showing <strong><?php echo min($total_customers_count, $offset + 1); ?></strong> to <strong><?php echo min($total_customers_count, $offset + count($customers)); ?></strong> of <strong><?php echo $total_customers_count; ?></strong> customers
        </div>
        <nav aria-label="Customers pagination">
          <ul class="pagination pagination-sm mb-0">
            <?php
              $pagination_query = $_GET;
              unset($pagination_query['page']);
              $base_url = 'customers.php?' . (empty($pagination_query) ? '' : http_build_query($pagination_query) . '&');
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
    const clearBtn = document.getElementById('custClearInputBtn');
    const input = document.getElementById('custSearchInput');
    if (clearBtn && input) {
      clearBtn.addEventListener('click', function() {
        input.value = '';
        window.location.href = 'customers.php';
      });
    }
  });
  </script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
