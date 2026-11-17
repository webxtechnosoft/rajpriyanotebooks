<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$is_ajax = isset($_GET['ajax']) || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

// Handle AJAX Search for Dashboard Instant Autocomplete
if ($is_ajax && $search !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $term = "%{$search}%";
    $numeric_term = is_numeric($search) ? (int)$search : 0;

    // 1. Products
    $p_stmt = $db->prepare("
        SELECT p.id, p.name, p.sku, p.brand_name, p.price, p.sale_price, p.stock, p.image, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.name LIKE ? OR p.sku LIKE ? OR p.brand_name LIKE ? OR c.name LIKE ?
           OR EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND (pv.product_code LIKE ? OR pv.variety LIKE ?))
        ORDER BY p.id DESC LIMIT 6
    ");
    $p_stmt->execute([$term, $term, $term, $term, $term, $term]);
    $matched_products = $p_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Categories
    $c_stmt = $db->prepare("
        SELECT c.id, c.name, c.slug, c.image, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) as product_count
        FROM categories c
        WHERE c.name LIKE ? OR c.slug LIKE ? OR c.description LIKE ?
        ORDER BY c.name ASC LIMIT 5
    ");
    $c_stmt->execute([$term, $term, $term]);
    $matched_categories = $c_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Customers
    $u_stmt = $db->prepare("
        SELECT u.id, u.name, u.customer_code, u.customer_type, u.phone, u.email, u.city
        FROM users u
        WHERE u.name LIKE ? OR u.customer_code LIKE ? OR u.customer_type LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR u.city LIKE ?
        ORDER BY u.id DESC LIMIT 5
    ");
    $u_stmt->execute([$term, $term, $term, $term, $term, $term]);
    $matched_customers = $u_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Orders
    $o_stmt = $db->prepare("
        SELECT o.id, o.order_number, o.customer_name, o.customer_phone, o.grand_total, o.order_status, o.created_at
        FROM orders o
        WHERE o.order_number LIKE ? OR o.id = ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR o.customer_code LIKE ? OR o.order_status LIKE ?
           OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND (oi.product_name LIKE ? OR oi.product_code LIKE ?))
        ORDER BY o.id DESC LIMIT 6
    ");
    $o_stmt->execute([$term, $numeric_term, $term, $term, $term, $term, $term, $term]);
    $matched_orders = $o_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'query' => $search,
        'products' => $matched_products,
        'categories' => $matched_categories,
        'customers' => $matched_customers,
        'orders' => $matched_orders,
        'total' => count($matched_products) + count($matched_categories) + count($matched_customers) + count($matched_orders)
    ]);
    exit();
}

// Full Page Search Results if submitted via GET
$matched_products = [];
$matched_categories = [];
$matched_customers = [];
$matched_orders = [];
$total_matches = 0;

if ($search !== '') {
    $term = "%{$search}%";
    $numeric_term = is_numeric($search) ? (int)$search : 0;

    // Products
    $p_stmt = $db->prepare("
        SELECT p.*, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.name LIKE ? OR p.sku LIKE ? OR p.brand_name LIKE ? OR c.name LIKE ?
           OR EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND (pv.product_code LIKE ? OR pv.variety LIKE ?))
        ORDER BY p.id DESC LIMIT 15
    ");
    $p_stmt->execute([$term, $term, $term, $term, $term, $term]);
    $matched_products = $p_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Categories
    $c_stmt = $db->prepare("
        SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) as product_count
        FROM categories c
        WHERE c.name LIKE ? OR c.slug LIKE ? OR c.description LIKE ?
        ORDER BY c.name ASC LIMIT 10
    ");
    $c_stmt->execute([$term, $term, $term]);
    $matched_categories = $c_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Customers
    $u_stmt = $db->prepare("
        SELECT u.*, 
          (SELECT COUNT(*) FROM orders o WHERE o.user_id = u.id OR o.customer_code = u.customer_code) as order_count,
          (SELECT COALESCE(SUM(o.grand_total), 0) FROM orders o WHERE (o.user_id = u.id OR o.customer_code = u.customer_code) AND o.order_status != 'Cancelled') as total_spent
        FROM users u
        WHERE u.name LIKE ? OR u.customer_code LIKE ? OR u.customer_type LIKE ? OR u.phone LIKE ? OR u.email LIKE ? OR u.city LIKE ? OR u.landmark LIKE ? OR u.address LIKE ?
        ORDER BY u.id DESC LIMIT 10
    ");
    $u_stmt->execute([$term, $term, $term, $term, $term, $term, $term, $term]);
    $matched_customers = $u_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Orders
    $o_stmt = $db->prepare("
        SELECT o.*
        FROM orders o
        WHERE o.order_number LIKE ? OR o.id = ? OR o.customer_name LIKE ? OR o.customer_phone LIKE ? OR o.customer_code LIKE ? OR o.order_status LIKE ?
           OR EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND (oi.product_name LIKE ? OR oi.product_code LIKE ?))
        ORDER BY o.id DESC LIMIT 15
    ");
    $o_stmt->execute([$term, $numeric_term, $term, $term, $term, $term, $term, $term]);
    $matched_orders = $o_stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_matches = count($matched_products) + count($matched_categories) + count($matched_customers) + count($matched_orders);
}

// Stat Queries
$total_products   = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();
$total_categories = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$total_orders     = $db->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$total_customers  = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_sales      = $db->query("SELECT SUM(grand_total) FROM orders WHERE order_status != 'Cancelled'")->fetchColumn() ?: 0.00;

$pending_orders    = $db->query("SELECT COUNT(*) FROM orders WHERE order_status = 'Pending'")->fetchColumn();
$processing_orders = $db->query("SELECT COUNT(*) FROM orders WHERE order_status = 'Processing'")->fetchColumn();
$delivered_orders  = $db->query("SELECT COUNT(*) FROM orders WHERE order_status = 'Delivered'")->fetchColumn();

// Low Stock Products (< 20 items)
$stmt_low_stock = $db->query("SELECT * FROM products WHERE stock <= 20 ORDER BY stock ASC");
$low_stock_products = $stmt_low_stock->fetchAll();

// Recent Orders
$stmt_recent_orders = $db->query("SELECT * FROM orders ORDER BY id DESC LIMIT 5");
$recent_orders = $stmt_recent_orders->fetchAll();

// Recent Customers
$stmt_recent_customers = $db->query("SELECT * FROM users ORDER BY id DESC LIMIT 5");
$recent_customers = $stmt_recent_customers->fetchAll();

require_once __DIR__ . '/includes/admin_header.php';
?>

<style>
/* DASHBOARD SEARCH COMPONENT STYLES */
.dashboard-search-card {
  background: #ffffff;
  border: 1px solid #E2E8F0;
  border-radius: 14px;
  padding: 14px 18px;
  margin-bottom: 16px;
  box-shadow: 0 4px 14px rgba(23, 36, 58, 0.05);
  position: relative;
}
.dashboard-hero {
  position: relative;
  isolation: isolate;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
  padding: 22px 24px;
  margin-bottom: 16px;
  border-radius: 16px;
  color: #ffffff;
  background: linear-gradient(120deg, #17243A 0%, #243957 68%, #315078 100%);
  box-shadow: 0 8px 22px rgba(23, 36, 58, 0.16);
}
.dashboard-hero::after {
  content: "";
  position: absolute;
  z-index: -1;
  width: 250px;
  height: 250px;
  top: -165px;
  right: -54px;
  border-radius: 50%;
  background: rgba(232, 199, 106, 0.13);
}
.dashboard-hero-content,
.dashboard-hero-actions {
  position: relative;
  z-index: 1;
}
.dashboard-hero-kicker {
  color: #E8C76A;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 1.1px;
  text-transform: uppercase;
  margin-bottom: 6px;
}
.dashboard-hero-title {
  color: #ffffff;
  font-size: clamp(1.35rem, 2vw, 1.8rem);
  font-weight: 700;
  margin: 0;
}
.dashboard-hero-copy {
  color: rgba(255, 255, 255, 0.76);
  font-size: 0.88rem;
  margin: 5px 0 0;
}
.dashboard-hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.dashboard-hero-actions .btn {
  min-height: 38px;
}
.dashboard-metric-card {
  position: relative;
  min-height: 96px;
  padding: 15px !important;
  border: 1px solid #E2E8F0;
  border-left: 3px solid var(--metric-accent, #C89B3C);
  border-radius: 12px;
  background: #ffffff;
  box-shadow: 0 3px 12px rgba(23, 36, 58, 0.04);
  transition: transform 0.18s ease, box-shadow 0.18s ease;
}
.dashboard-metric-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 18px rgba(23, 36, 58, 0.09);
}
.dashboard-metric-icon {
  width: 44px;
  height: 44px;
  min-width: 44px;
  display: grid;
  place-items: center;
  border-radius: 12px;
  color: var(--metric-accent, #C89B3C);
  background: var(--metric-tint, #FCF8EF);
  font-size: 1.2rem;
}
.dashboard-metric-label {
  color: #64748B;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.45px;
  text-transform: uppercase;
}
.dashboard-metric-value {
  color: #17243A;
  font-size: 1.45rem;
  font-weight: 700;
  line-height: 1.2;
  margin-top: 3px;
  overflow-wrap: anywhere;
}
.metric-revenue .dashboard-metric-value { font-size: clamp(1rem, 1.7vw, 1.35rem); }
.metric-products { --metric-accent: #2563EB; --metric-tint: #EFF6FF; }
.metric-categories { --metric-accent: #7C3AED; --metric-tint: #F5F3FF; }
.metric-orders { --metric-accent: #0891B2; --metric-tint: #ECFEFF; }
.metric-revenue { --metric-accent: #15803D; --metric-tint: #F0FDF4; }
.metric-customers { --metric-accent: #D97706; --metric-tint: #FFFBEB; }
.dashboard-status-card {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 76px;
  padding: 13px 15px;
  border: 1px solid var(--status-border, #E2E8F0);
  border-radius: 12px;
  background: var(--status-bg, #ffffff);
}
.dashboard-status-icon {
  width: 38px;
  height: 38px;
  min-width: 38px;
  display: grid;
  place-items: center;
  border-radius: 10px;
  color: var(--status-color, #475569);
  background: var(--status-icon-bg, #F1F5F9);
  font-size: 1rem;
}
.dashboard-status-label {
  color: #64748B;
  font-size: 0.76rem;
  font-weight: 600;
}
.dashboard-status-value {
  color: var(--status-color, #17243A);
  font-size: 1.25rem;
  font-weight: 700;
  line-height: 1.15;
}
.status-pending { --status-color: #B45309; --status-bg: #FFFBEB; --status-border: #FDE68A; --status-icon-bg: #FEF3C7; }
.status-processing { --status-color: #0369A1; --status-bg: #F0F9FF; --status-border: #BAE6FD; --status-icon-bg: #E0F2FE; }
.status-delivered { --status-color: #15803D; --status-bg: #F0FDF4; --status-border: #BBF7D0; --status-icon-bg: #DCFCE7; }
.status-customers { --status-color: #4338CA; --status-bg: #EEF2FF; --status-border: #C7D2FE; --status-icon-bg: #E0E7FF; }
.dashboard-data-card {
  height: 100%;
  overflow: hidden;
  border: 1px solid #E2E8F0;
  border-radius: 14px;
  background: #ffffff;
  box-shadow: 0 4px 14px rgba(23, 36, 58, 0.05);
}
.dashboard-data-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  padding: 15px 18px;
  border-bottom: 1px solid #E2E8F0;
}
.dashboard-data-card-header h5 {
  color: #17243A;
  font-size: 0.95rem;
  font-weight: 700;
  margin: 0;
}
.dashboard-data-card-body {
  padding: 4px 18px 12px;
}
.dashboard-data-card .table {
  margin-bottom: 0;
}
.dashboard-data-card .table thead th {
  color: #64748B;
  font-size: 0.68rem;
  font-weight: 700;
  letter-spacing: 0.45px;
  text-transform: uppercase;
  border-bottom-color: #E2E8F0;
  white-space: nowrap;
}
.dashboard-data-card .table tbody td {
  color: #334155;
  font-size: 0.82rem;
}
.dashboard-empty-state {
  padding: 24px 12px;
  color: #64748B;
  font-size: 0.85rem;
  text-align: center;
}
.dashboard-low-stock {
  color: #7F1D1D;
  background: #FFFAFA;
  border: 1px solid #FECACA;
  border-left: 4px solid #DC2626;
  border-radius: 12px;
  padding: 14px 16px;
}
.dashboard-low-stock .badge {
  padding: 6px 9px;
  font-weight: 600;
}
.search-input-group {
  position: relative;
  display: flex;
  align-items: center;
}
.search-input-group .form-control {
  height: 48px;
  font-size: 0.95rem;
  border-radius: 8px 0 0 8px;
  border: 1.5px solid #CBD5E1;
  border-right: none;
  padding-left: 45px;
  padding-right: 40px;
  transition: all 0.2s ease;
}
.search-input-group .form-control:focus {
  border-color: #C89B3C;
  box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.15);
}
.search-icon-left {
  position: absolute;
  left: 16px;
  top: 50%;
  transform: translateY(-50%);
  color: #94A3B8;
  font-size: 1.15rem;
  z-index: 5;
  pointer-events: none;
}
.search-clear-btn {
  position: absolute;
  right: 145px;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: #94A3B8;
  font-size: 1.15rem;
  padding: 4px;
  cursor: pointer;
  z-index: 5;
  display: <?php echo ($search !== '') ? 'block' : 'none'; ?>;
  line-height: 1;
}
.search-clear-btn:hover {
  color: #dc2626;
}
.search-submit-btn {
  height: 48px;
  padding: 0 26px;
  font-weight: 700;
  font-size: 0.92rem;
  background-color: #C89B3C;
  border: 1.5px solid #C89B3C;
  color: #ffffff;
  border-radius: 0 8px 8px 0;
  transition: all 0.2s ease;
  white-space: nowrap;
}
.search-submit-btn:hover {
  background-color: #b0842e;
  border-color: #b0842e;
  color: #ffffff;
}

@media (max-width: 576px) {
  .search-submit-btn {
    padding: 0 16px;
  }
  .search-clear-btn {
    right: 58px;
  }
}

/* Instant AJAX Dropdown Preview */
.ajax-search-results {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  background: #ffffff;
  border: 1px solid #CBD5E1;
  border-radius: 10px;
  box-shadow: 0 12px 28px rgba(23, 36, 58, 0.12);
  margin-top: 6px;
  max-height: 440px;
  overflow-y: auto;
  z-index: 1050;
  display: none;
}
.ajax-search-item {
  padding: 10px 16px;
  border-bottom: 1px solid #F1F5F9;
  display: flex;
  align-items: center;
  justify-content: space-between;
  text-decoration: none;
  color: #17243A;
  transition: background 0.15s ease;
}
.ajax-search-item:hover {
  background-color: #F8FAFC;
}
.ajax-category-header {
  background: #F8FAFC;
  padding: 6px 16px;
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.5px;
  color: #64748B;
  text-transform: uppercase;
  border-bottom: 1px solid #E2E8F0;
}

@media (max-width: 767.98px) {
  .dashboard-hero {
    align-items: flex-start;
    flex-direction: column;
    padding: 18px;
  }
  .dashboard-hero-actions {
    width: 100%;
  }
  .dashboard-hero-actions .btn {
    flex: 1 1 auto;
  }
  .dashboard-data-card-header,
  .dashboard-data-card-body {
    padding-left: 12px;
    padding-right: 12px;
  }
}
</style>

<!-- Dashboard Welcome Header -->
<div class="dashboard-hero">
  <div class="dashboard-hero-content">
    <div class="dashboard-hero-kicker">Store dashboard · <?php echo date('l, d M Y'); ?></div>
    <h2 class="dashboard-hero-title">Welcome back, <?php echo htmlspecialchars($admin['name'] ?? 'Admin'); ?></h2>
    <p class="dashboard-hero-copy">Here is your latest store activity and a quick way to manage orders and products.</p>
  </div>
  <div class="dashboard-hero-actions">
    <a href="orders.php" class="btn btn-warning fw-semibold"><i class="bi bi-box-seam me-1"></i> Manage Orders</a>
    <a href="products.php" class="btn btn-outline-light fw-semibold"><i class="bi bi-journal-text me-1"></i> Products</a>
  </div>
</div>

<!-- ═════════════════════════════════════════════════════════════════════════ -->
<!-- UNIFIED DASHBOARD SEARCH BAR (Products, Categories, Customers & Orders)   -->
<!-- ═════════════════════════════════════════════════════════════════════════ -->
<div class="dashboard-search-card">
  <form action="index.php" method="GET" id="dashboardSearchForm" autocomplete="off">
    <div class="search-input-group">
      <i class="bi bi-search search-icon-left"></i>
      <input type="text" name="search" id="dashboardSearchInput" class="form-control" 
             placeholder="Search products, brands (Popular, Economi), categories, customers or orders..." 
             value="<?php echo htmlspecialchars($search); ?>">
      
      <button type="button" class="search-clear-btn" id="dashboardSearchClear" title="Clear Search">
        <i class="bi bi-x-circle-fill"></i>
      </button>

      <button type="submit" class="btn search-submit-btn">
        <i class="bi bi-search me-sm-1.5"></i><span class="d-none d-sm-inline"> Search Store</span>
      </button>

      <!-- Live AJAX Dropdown Preview -->
      <div class="ajax-search-results" id="ajaxSearchResults"></div>
    </div>
    
    <div class="d-flex justify-content-between align-items-center mt-2 px-1">
      <small class="text-muted" style="font-size: 0.78rem;">
        <i class="bi bi-lightning-charge-fill text-gold me-1"></i> Quick Search covers: <strong>Products & Codes</strong>, <strong>Brands</strong>, <strong>Categories</strong>, <strong>Customers & Phones</strong>, <strong>Orders & Invoices</strong>.
      </small>
      <?php if ($search !== ''): ?>
        <a href="index.php" class="text-danger small fw-semibold text-decoration-none">
          <i class="bi bi-x-lg me-1"></i> Reset Search Results
        </a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- ═════════════════════════════════════════════════════════════════════════ -->
<!-- FULL SEARCH RESULTS VIEW (IF SEARCH QUERY SUBMITTED)                      -->
<!-- ═════════════════════════════════════════════════════════════════════════ -->
<?php if ($search !== ''): ?>
  <div class="mb-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h5 class="fw-bold text-navy mb-0">
        <i class="bi bi-search text-gold me-2"></i> Search Results for: "<span class="text-gold"><?php echo htmlspecialchars($search); ?></span>"
        <span class="badge bg-light text-navy border ms-2"><?php echo $total_matches; ?> total matches</span>
      </h5>
      <a href="index.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i> Back to Full Dashboard
      </a>
    </div>

    <?php if ($total_matches === 0): ?>
      <!-- No Results Found Alert -->
      <div class="bg-white p-5 rounded-3 border text-center shadow-xs">
        <div class="text-muted mb-3"><i class="bi bi-search fs-1"></i></div>
        <h5 class="fw-bold text-navy mb-1">No Results Found</h5>
        <p class="text-secondary small mb-3">No products, categories, customers, or orders matched your keyword "<strong><?php echo htmlspecialchars($search); ?></strong>".</p>
        <a href="index.php" class="btn btn-gold btn-sm px-4 fw-bold">Clear Search & View All Stats</a>
      </div>
    <?php else: ?>
      <!-- Results Found Layout -->
      <div class="row g-4">
        
        <!-- 1. Matching Products -->
        <?php if (!empty($matched_products)): ?>
          <div class="col-12">
            <div class="bg-white p-4 rounded-3 border shadow-xs">
              <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                <h6 class="fw-bold text-navy mb-0">
                  <i class="bi bi-journal-album text-gold me-1.5"></i> Products Matched (<?php echo count($matched_products); ?>)
                </h6>
                <a href="products.php?search=<?php echo urlencode($search); ?>" class="small text-gold fw-semibold">View All in Products →</a>
              </div>
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                  <thead class="table-light small">
                    <tr>
                      <th style="width: 48px;">Image</th>
                      <th>Product Name</th>
                      <th>Brand</th>
                      <th>SKU</th>
                      <th>Category</th>
                      <th>Price</th>
                      <th>Stock</th>
                      <th class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($matched_products as $p): ?>
                      <tr>
                        <td>
                          <img src="../<?php echo htmlspecialchars($p['image']); ?>" alt="" style="width: 36px; height: 36px; object-fit: contain;" class="rounded border">
                        </td>
                        <td>
                          <strong class="text-navy"><?php echo htmlspecialchars($p['name']); ?></strong>
                          <?php if (!empty($p['pages'])): ?><small class="text-muted d-block"><?php echo $p['pages']; ?> Pages</small><?php endif; ?>
                        </td>
                        <td>
                          <?php if (!empty($p['brand_name'])): ?>
                            <span class="badge bg-light text-navy border fw-bold"><?php echo htmlspecialchars($p['brand_name']); ?></span>
                          <?php else: ?>
                            <span class="text-muted small">-</span>
                          <?php endif; ?>
                        </td>
                        <td class="font-monospace small fw-bold"><?php echo htmlspecialchars($p['sku']); ?></td>
                        <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($p['category_name'] ?? '-'); ?></span></td>
                        <td class="fw-semibold text-navy"><?php echo format_price($p['sale_price'] ?: $p['price']); ?></td>
                        <td>
                          <?php if ($p['stock'] > 20): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle"><?php echo $p['stock']; ?> in stock</span>
                          <?php elseif ($p['stock'] > 0): ?>
                            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><?php echo $p['stock']; ?> Low</span>
                          <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">0 Out</span>
                          <?php endif; ?>
                        </td>
                        <td class="text-end">
                          <a href="products.php?search=<?php echo urlencode($p['name']); ?>" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 0.75rem;">Manage</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- 2. Matching Orders & Invoices -->
        <?php if (!empty($matched_orders)): ?>
          <div class="col-lg-7">
            <div class="bg-white p-4 rounded-3 border shadow-xs h-100">
              <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                <h6 class="fw-bold text-navy mb-0">
                  <i class="bi bi-box-seam text-gold me-1.5"></i> Orders & Tax Bills Matched (<?php echo count($matched_orders); ?>)
                </h6>
                <a href="orders.php?search=<?php echo urlencode($search); ?>" class="small text-gold fw-semibold">View All in Orders →</a>
              </div>
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                  <thead class="table-light small">
                    <tr>
                      <th>Order # / Bill</th>
                      <th>Customer</th>
                      <th>Amount</th>
                      <th>Status</th>
                      <th class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($matched_orders as $ord): ?>
                      <tr>
                        <td class="fw-bold text-navy"><?php echo htmlspecialchars($ord['order_number']); ?></td>
                        <td>
                          <div class="fw-semibold text-navy small"><?php echo htmlspecialchars($ord['customer_name']); ?></div>
                          <div class="text-muted" style="font-size: 0.75rem;"><?php echo htmlspecialchars($ord['customer_phone']); ?></div>
                        </td>
                        <td class="fw-bold text-navy"><?php echo format_price($ord['grand_total']); ?></td>
                        <td><?php echo render_order_status_badge($ord['order_status']); ?></td>
                        <td class="text-end">
                          <a href="quotation.php?order_id=<?php echo $ord['id']; ?>" class="btn btn-sm btn-outline-navy py-0 px-2 fw-semibold me-1" style="font-size: 0.75rem;" title="Quotation">Quotation</a>
                          <a href="orders.php?view=<?php echo $ord['id']; ?>" class="btn btn-sm btn-gold py-0 px-2 fw-semibold" style="font-size: 0.75rem;">Invoice</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- 3. Matching Customers & Categories Column -->
        <div class="col-lg-5">
          <!-- Customers -->
          <?php if (!empty($matched_customers)): ?>
            <div class="bg-white p-4 rounded-3 border shadow-xs mb-4">
              <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                <h6 class="fw-bold text-navy mb-0">
                  <i class="bi bi-people text-gold me-1.5"></i> Customers Matched (<?php echo count($matched_customers); ?>)
                </h6>
                <a href="customers.php?search=<?php echo urlencode($search); ?>" class="small text-gold fw-semibold">View All Customers →</a>
              </div>
              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                  <thead class="table-light small">
                    <tr>
                      <th>Customer ID</th>
                      <th>Name & Phone</th>
                      <th>City</th>
                      <th class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($matched_customers as $cust): 
                      $cCode = !empty($cust['customer_code']) ? $cust['customer_code'] : 'CUST' . str_pad($cust['id'], 5, '0', STR_PAD_LEFT);
                    ?>
                      <tr>
                        <td>
                          <span class="badge bg-navy text-gold fw-bold"><?php echo htmlspecialchars($cCode); ?></span>
                          <div class="mt-0.5">
                            <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-semibold" style="font-size: 0.65rem;">
                              <?php echo htmlspecialchars($cust['customer_type'] ?? 'Retailer'); ?>
                            </span>
                          </div>
                        </td>
                        <td>
                          <strong class="text-navy d-block small"><?php echo htmlspecialchars($cust['name']); ?></strong>
                          <span class="text-muted" style="font-size: 0.75rem;"><?php echo htmlspecialchars($cust['phone']); ?></span>
                        </td>
                        <td class="small text-secondary"><?php echo htmlspecialchars($cust['city'] ?? '-'); ?></td>
                        <td class="text-end">
                          <a href="customers.php?view=<?php echo urlencode($cCode); ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size: 0.75rem;">Profile</a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          <?php endif; ?>

          <!-- Categories -->
          <?php if (!empty($matched_categories)): ?>
            <div class="bg-white p-4 rounded-3 border shadow-xs">
              <div class="d-flex justify-content-between align-items-center border-bottom pb-2 mb-3">
                <h6 class="fw-bold text-navy mb-0">
                  <i class="bi bi-tags text-gold me-1.5"></i> Categories Matched (<?php echo count($matched_categories); ?>)
                </h6>
                <a href="categories.php?search=<?php echo urlencode($search); ?>" class="small text-gold fw-semibold">Manage Categories →</a>
              </div>
              <div class="list-group list-group-flush">
                <?php foreach ($matched_categories as $cat): ?>
                  <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
                    <div class="d-flex align-items-center gap-2">
                      <img src="../<?php echo htmlspecialchars($cat['image']); ?>" alt="" style="width: 32px; height: 32px; object-fit: contain;" class="rounded border">
                      <div>
                        <strong class="text-navy small d-block"><?php echo htmlspecialchars($cat['name']); ?></strong>
                        <span class="badge bg-light text-dark border" style="font-size: 0.7rem;"><?php echo $cat['product_count']; ?> Notebooks</span>
                      </div>
                    </div>
                    <a href="products.php?search=<?php echo urlencode($cat['name']); ?>" class="btn btn-sm btn-outline-dark py-0 px-2" style="font-size: 0.75rem;">View Products</a>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php endif; ?>

        </div>
      </div>
    <?php endif; ?>

    <hr class="my-4 text-muted">
  </div>
<?php endif; ?>

<!-- ═════════════════════════════════════════════════════════════════════════ -->
<!-- REGULAR DASHBOARD METRICS & RECENT ACTIVITIES                             -->
<!-- ═════════════════════════════════════════════════════════════════════════ -->

<!-- Key Stat Cards -->
<div class="row g-2 g-lg-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card dashboard-metric-card metric-products d-flex align-items-center gap-3">
      <div class="dashboard-metric-icon"><i class="bi bi-journal-album"></i></div>
      <div class="flex-grow-1">
        <div class="dashboard-metric-label">Total products</div>
        <div class="dashboard-metric-value"><?php echo number_format((int)$total_products); ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card dashboard-metric-card metric-categories d-flex align-items-center gap-3">
      <div class="dashboard-metric-icon"><i class="bi bi-grid-3x3-gap"></i></div>
      <div class="flex-grow-1">
        <div class="dashboard-metric-label">Categories</div>
        <div class="dashboard-metric-value"><?php echo number_format((int)$total_categories); ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card dashboard-metric-card metric-orders d-flex align-items-center gap-3">
      <div class="dashboard-metric-icon"><i class="bi bi-bag-check-fill"></i></div>
      <div class="flex-grow-1">
        <div class="dashboard-metric-label">Total orders</div>
        <div class="dashboard-metric-value"><?php echo number_format((int)$total_orders); ?></div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card dashboard-metric-card metric-revenue d-flex align-items-center gap-3">
      <div class="dashboard-metric-icon"><i class="bi bi-currency-rupee"></i></div>
      <div class="flex-grow-1">
        <div class="dashboard-metric-label">Total revenue</div>
        <div class="dashboard-metric-value"><?php echo format_price($total_sales); ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Order Status Breakdowns -->
<div class="row g-2 g-lg-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="dashboard-status-card status-pending">
      <div class="dashboard-status-icon"><i class="bi bi-hourglass-split"></i></div>
      <div><div class="dashboard-status-label">Pending orders</div><div class="dashboard-status-value"><?php echo number_format((int)$pending_orders); ?></div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="dashboard-status-card status-processing">
      <div class="dashboard-status-icon"><i class="bi bi-arrow-repeat"></i></div>
      <div><div class="dashboard-status-label">Processing</div><div class="dashboard-status-value"><?php echo number_format((int)$processing_orders); ?></div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="dashboard-status-card status-delivered">
      <div class="dashboard-status-icon"><i class="bi bi-check2-circle"></i></div>
      <div><div class="dashboard-status-label">Delivered</div><div class="dashboard-status-value"><?php echo number_format((int)$delivered_orders); ?></div></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="dashboard-status-card status-customers">
      <div class="dashboard-status-icon"><i class="bi bi-people"></i></div>
      <div><div class="dashboard-status-label">Customers</div><div class="dashboard-status-value"><?php echo number_format((int)$total_customers); ?></div></div>
    </div>
  </div>
</div>

<!-- Low Stock Warning Alert Cards -->
<?php if (!empty($low_stock_products)): ?>
  <div class="alert alert-danger dashboard-low-stock mb-4 shadow-sm">
    <h5 class="fw-bold mb-2"><i class="bi bi-exclamation-triangle-fill me-2"></i> Low Stock Warning Alert (<?php echo count($low_stock_products); ?> Items)</h5>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($low_stock_products as $lsp): ?>
        <span class="badge bg-white text-danger border border-danger p-2">
          <strong><?php echo htmlspecialchars($lsp['name']); ?></strong> - Only <?php echo $lsp['stock']; ?> left!
        </span>
      <?php endforeach; ?>
    </div>
    <div class="mt-2"><a href="stock.php" class="small text-danger fw-bold">Manage Stock Levels →</a></div>
  </div>
<?php endif; ?>

<!-- Recent Tables Row -->
<div class="row g-3">
  <!-- Recent Orders -->
  <div class="col-lg-7">
    <div class="dashboard-data-card">
      <div class="dashboard-data-card-header">
        <h5><i class="bi bi-receipt-cutoff text-gold me-2"></i>Recent customer orders</h5>
        <a href="orders.php" class="small text-gold fw-semibold text-nowrap">View all <i class="bi bi-arrow-up-right"></i></a>
      </div>
      <div class="dashboard-data-card-body">
        <div class="table-responsive">
          <table class="table table-hover table-sm align-middle">
            <thead>
              <tr class="table-light">
                <th>Order ID</th>
                <th>Customer</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recent_orders)): ?>
                <tr><td colspan="5"><div class="dashboard-empty-state">No orders have been placed yet.</div></td></tr>
              <?php else: ?>
                <?php foreach ($recent_orders as $ord): ?>
                  <tr>
                    <td class="fw-bold"><?php echo htmlspecialchars($ord['order_number']); ?></td>
                    <td><?php echo htmlspecialchars($ord['customer_name']); ?></td>
                    <td class="fw-semibold"><?php echo format_price($ord['grand_total']); ?></td>
                    <td><?php echo render_order_status_badge($ord['order_status']); ?></td>
                    <td class="text-nowrap">
                      <a href="quotation.php?order_id=<?php echo $ord['id']; ?>" class="btn btn-sm btn-outline-navy me-1" title="Send Quotation">
                        <i class="bi bi-file-earmark-text text-gold"></i> Quotation
                      </a>
                      <a href="orders.php?view=<?php echo $ord['id']; ?>" class="btn btn-sm btn-outline-dark">Details</a>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Recent Customers -->
  <div class="col-lg-5">
    <div class="dashboard-data-card">
      <div class="dashboard-data-card-header">
        <h5><i class="bi bi-people text-gold me-2"></i>Registered customers</h5>
        <a href="customers.php" class="small text-gold fw-semibold text-nowrap">View all <i class="bi bi-arrow-up-right"></i></a>
      </div>
      <div class="dashboard-data-card-body">
        <div class="table-responsive">
          <table class="table table-hover table-sm align-middle">
            <thead>
              <tr class="table-light">
                <th>Customer</th>
                <th>Phone</th>
                <th>City</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recent_customers)): ?>
                <tr><td colspan="3"><div class="dashboard-empty-state">No customers registered yet.</div></td></tr>
              <?php else: ?>
                <?php foreach ($recent_customers as $cust): ?>
                  <tr>
                    <td class="fw-bold"><?php echo htmlspecialchars($cust['name']); ?></td>
                    <td><?php echo htmlspecialchars($cust['phone']); ?></td>
                    <td><?php echo htmlspecialchars($cust['city'] ?? 'N/A'); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Dashboard Interactive Search with Live AJAX Dropdown
(function() {
  const input = document.getElementById('dashboardSearchInput');
  const clearBtn = document.getElementById('dashboardSearchClear');
  const resultsBox = document.getElementById('ajaxSearchResults');
  let debounceTimer = null;

  if (!input || !clearBtn || !resultsBox) return;

  function toggleClearBtn() {
    clearBtn.style.display = input.value.trim() !== '' ? 'block' : 'none';
  }

  clearBtn.addEventListener('click', function() {
    input.value = '';
    toggleClearBtn();
    resultsBox.style.display = 'none';
    input.focus();
    // If search parameter exists in URL, reload to clear
    if (window.location.search.includes('search=')) {
      window.location.href = 'index.php';
    }
  });

  input.addEventListener('input', function() {
    toggleClearBtn();
    const query = input.value.trim();
    clearTimeout(debounceTimer);

    if (query.length < 2) {
      resultsBox.style.display = 'none';
      resultsBox.innerHTML = '';
      return;
    }

    debounceTimer = setTimeout(() => {
      fetch('index.php?search=' + encodeURIComponent(query) + '&ajax=1', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(res => res.json())
      .then(data => {
        if (!data || data.total === 0) {
          resultsBox.innerHTML = '<div class="p-3 text-muted text-center small"><i class="bi bi-info-circle me-1"></i> No matching products, categories, customers or orders found.</div>';
          resultsBox.style.display = 'block';
          return;
        }

        let html = '';

        // Products
        if (data.products && data.products.length > 0) {
          html += '<div class="ajax-category-header"><i class="bi bi-journal-album text-gold me-1"></i> Products</div>';
          data.products.forEach(p => {
            html += `
              <a href="products.php?search=${encodeURIComponent(p.name)}" class="ajax-search-item">
                <div class="d-flex align-items-center gap-2">
                  <img src="../${p.image}" style="width:28px;height:28px;object-fit:contain;" class="rounded border">
                  <div>
                    <strong class="small d-block text-navy">${escapeHtml(p.name)}</strong>
                    <span class="text-muted" style="font-size:0.75rem;">SKU: ${escapeHtml(p.sku)} ${p.brand_name ? '| ' + escapeHtml(p.brand_name) : ''}</span>
                  </div>
                </div>
                <span class="badge bg-gold-subtle text-navy border fw-bold">₹${parseFloat(p.sale_price || p.price).toFixed(2)}</span>
              </a>
            `;
          });
        }

        // Orders
        if (data.orders && data.orders.length > 0) {
          html += '<div class="ajax-category-header"><i class="bi bi-box-seam text-gold me-1"></i> Orders & Invoices</div>';
          data.orders.forEach(o => {
            html += `
              <a href="orders.php?view=${o.id}" class="ajax-search-item">
                <div>
                  <strong class="small text-navy d-block">${escapeHtml(o.order_number)}</strong>
                  <span class="text-muted" style="font-size:0.75rem;">${escapeHtml(o.customer_name)} &bull; ${escapeHtml(o.customer_phone)}</span>
                </div>
                <div class="text-end">
                  <span class="small fw-bold text-navy d-block">₹${parseFloat(o.grand_total).toFixed(2)}</span>
                  <span class="badge bg-light text-secondary border" style="font-size:0.68rem;">${escapeHtml(o.order_status)}</span>
                </div>
              </a>
            `;
          });
        }

        // Customers
        if (data.customers && data.customers.length > 0) {
          html += '<div class="ajax-category-header"><i class="bi bi-people text-gold me-1"></i> Customers</div>';
          data.customers.forEach(c => {
            const code = c.customer_code || ('CUST' + String(c.id).padStart(5, '0'));
            html += `
              <a href="customers.php?view=${encodeURIComponent(code)}" class="ajax-search-item">
                <div>
                  <strong class="small text-navy d-block">${escapeHtml(c.name)}</strong>
                  <span class="text-muted" style="font-size:0.75rem;">${escapeHtml(c.phone)} ${c.city ? '&bull; ' + escapeHtml(c.city) : ''}</span>
                </div>
                <span class="badge bg-navy text-gold fw-bold" style="font-size:0.72rem;">${escapeHtml(code)}</span>
              </a>
            `;
          });
        }

        // Categories
        if (data.categories && data.categories.length > 0) {
          html += '<div class="ajax-category-header"><i class="bi bi-tags text-gold me-1"></i> Categories</div>';
          data.categories.forEach(cat => {
            html += `
              <a href="categories.php?search=${encodeURIComponent(cat.name)}" class="ajax-search-item">
                <div class="d-flex align-items-center gap-2">
                  <img src="../${cat.image}" style="width:24px;height:24px;object-fit:contain;" class="rounded border">
                  <span class="small fw-bold text-navy">${escapeHtml(cat.name)}</span>
                </div>
                <span class="badge bg-light text-dark border" style="font-size:0.7rem;">${cat.product_count} Items</span>
              </a>
            `;
          });
        }

        resultsBox.innerHTML = html;
        resultsBox.style.display = 'block';
      })
      .catch(err => {
        console.error('Search error:', err);
      });
    }, 250);
  });

  document.addEventListener('click', function(e) {
    if (!input.contains(e.target) && !resultsBox.contains(e.target)) {
      resultsBox.style.display = 'none';
    }
  });

  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
})();
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
