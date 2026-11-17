<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();

// Handle Quick Wholesale Stock Update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'quick_update') {
    $variant_id = (int)$_POST['variant_id'];
    $new_bundle_stock = max(0, (int)($_POST['new_bundle_stock'] ?? 0));
    $pieces_per_bundle_input = trim((string)($_POST['pieces_per_bundle'] ?? ''));
    $new_loose_stock = max(0, (int)($_POST['new_loose_stock'] ?? 0));
    $admin = get_current_admin();
    $adminUser = $admin['username'] ?? 'admin';

    $stmt = $db->prepare("
        SELECT pv.*, p.name as product_name 
        FROM product_variants pv 
        JOIN products p ON pv.product_id = p.id 
        WHERE pv.id = ?
    ");
    $stmt->execute([$variant_id]);
    $var = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($var) {
        // Use the variant's actual pack size when the request omits it.
        $storedPiecesPerBundle = max(1, (int)($var['bundle_qty'] ?? 1));
        $pieces_per_bundle = ($pieces_per_bundle_input !== '' && (int)$pieces_per_bundle_input > 0)
            ? (int)$pieces_per_bundle_input
            : $storedPiecesPerBundle;

        // Normalize inventory so bundles always equal floor(total pieces / pack size).
        $new_total_pieces = ($new_bundle_stock * $pieces_per_bundle) + $new_loose_stock;
        $stock_breakdown = calculate_stock_breakdown($new_total_pieces, $pieces_per_bundle);
        $new_total_pieces = $stock_breakdown['total_pieces'];
        $new_bundle_stock = $stock_breakdown['bundle_stock'];
        $new_loose_stock = $stock_breakdown['loose_stock'];

        $prev_total = (int)$var['stock'];
        $change = $new_total_pieces - $prev_total;

        // 1. Update variant wholesale stock
        $stmtUp = $db->prepare("
            UPDATE product_variants 
            SET bundle_stock = ?, bundle_qty = ?, loose_stock = ?, stock = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmtUp->execute([$new_bundle_stock, $pieces_per_bundle, $new_loose_stock, $new_total_pieces, $variant_id]);

        // 2. Sync parent product total bundle_stock, stock, and std_qty
        $stmtSync = $db->prepare("
            UPDATE products 
            SET bundle_stock = (SELECT COALESCE(SUM(bundle_stock), 0) FROM product_variants WHERE product_id = ?),
                stock = (SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = ?),
                std_qty = COALESCE((SELECT bundle_qty FROM product_variants WHERE product_id = ? AND bundle_qty > 0 ORDER BY id ASC LIMIT 1), std_qty)
            WHERE id = ?
        ");
        $stmtSync->execute([$var['product_id'], $var['product_id'], $var['product_id'], $var['product_id']]);

        // 3. Log to stock_history
        $reason = "Manual Wholesale Update: " . $var['product_code'] . " [" . $var['variety'] . "] (Bundles: " . $var['bundle_stock'] . "→$new_bundle_stock, Loose: " . $var['loose_stock'] . "→$new_loose_stock, Total: {$prev_total}→{$new_total_pieces})";
        $stmtLog = $db->prepare("
            INSERT INTO stock_history (product_id, previous_stock, new_stock, change_amount, reason, admin_username) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmtLog->execute([$var['product_id'], $prev_total, $new_total_pieces, $change, $reason, $adminUser]);

        set_flash('success', "Stock for " . htmlspecialchars($var['product_name']) . " (" . htmlspecialchars($var['variety']) . ") updated: $new_bundle_stock Bundles + $new_loose_stock Loose = $new_total_pieces Total Pieces.");
    }
    header("Location: stock.php");
    exit();
}

// ─── Filter & Search Parameters ───
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$varietyFilter = isset($_GET['variety']) ? trim($_GET['variety']) : '';
$statusFilter = isset($_GET['status']) ? trim($_GET['status']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 40;
$offset = ($page - 1) * $perPage;

// ─── Summary Counts for Stats Cards ───
$totalVariants = (int)$db->query("SELECT COUNT(*) FROM product_variants")->fetchColumn();
$totalBundles = (int)$db->query("SELECT COALESCE(SUM(bundle_stock), 0) FROM product_variants")->fetchColumn();
$totalLoose = (int)$db->query("SELECT COALESCE(SUM(loose_stock), 0) FROM product_variants")->fetchColumn();
$totalPieces = (int)$db->query("SELECT COALESCE(SUM(stock), 0) FROM product_variants")->fetchColumn();

// ─── Build Query for Stock Table ───
$whereClauses = ["1=1"];
$queryParams = [];

if ($search !== '') {
    $sTerm = "%$search%";
    $searchParts = [
        "p.name LIKE ?",
        "pv.product_code LIKE ?",
        "p.sku LIKE ?",
        "p.brand_name LIKE ?",
        "pv.variety LIKE ?",
        "pv.variety_display LIKE ?"
    ];
    $queryParams[] = $sTerm;
    $queryParams[] = $sTerm;
    $queryParams[] = $sTerm;
    $queryParams[] = $sTerm;
    $queryParams[] = $sTerm;
    $queryParams[] = $sTerm;
    
    // Stock availability matching (e.g. "in stock", "low stock", "out of stock")
    $lowerSearch = strtolower($search);
    if (strpos($lowerSearch, 'out') !== false) {
        $searchParts[] = "pv.stock = 0";
    }
    if (strpos($lowerSearch, 'low') !== false) {
        $searchParts[] = "(pv.stock > 0 AND pv.stock <= 20)";
    }
    if (strpos($lowerSearch, 'in stock') !== false || strpos($lowerSearch, 'instock') !== false) {
        $searchParts[] = "pv.stock > 20";
    }
    if (is_numeric($search)) {
        $searchParts[] = "pv.stock = ?";
        $queryParams[] = (int)$search;
    }
    
    $whereClauses[] = "(" . implode(" OR ", $searchParts) . ")";
}

if ($varietyFilter !== '') {
    $whereClauses[] = "(pv.variety = ? OR pv.variety_display = ?)";
    $queryParams[] = $varietyFilter;
    $queryParams[] = $varietyFilter;
}

if ($statusFilter === 'in_stock') {
    $whereClauses[] = "pv.stock > 20";
} elseif ($statusFilter === 'low_stock') {
    $whereClauses[] = "(pv.stock > 0 AND pv.stock <= 20)";
} elseif ($statusFilter === 'out_of_stock') {
    $whereClauses[] = "pv.stock = 0";
}

$whereSql = implode(' AND ', $whereClauses);

// Count matching records for pagination
$countStmt = $db->prepare("
    SELECT COUNT(*) 
    FROM product_variants pv 
    JOIN products p ON pv.product_id = p.id 
    WHERE $whereSql
");
$countStmt->execute($queryParams);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalRecords / $perPage));

// Fetch page items with wholesale fields
$querySql = "
    SELECT 
        pv.id as variant_id,
        pv.product_id,
        pv.product_code,
        pv.page_count,
        pv.variety,
        pv.variety_display,
        pv.bundle_qty as pieces_per_bundle,
        pv.bundle_stock,
        pv.loose_stock,
        pv.stock as total_piece_stock,
        pv.updated_at,
        p.name as product_name,
        p.sku as product_sku,
        p.stock as total_product_stock,
        p.bundle_stock as total_product_bundles,
        c.name as category_name
    FROM product_variants pv
    JOIN products p ON pv.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE $whereSql
    ORDER BY pv.stock ASC, p.name ASC, pv.variety ASC
    LIMIT $perPage OFFSET $offset
";
$stmt = $db->prepare($querySql);
$stmt->execute($queryParams);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch distinct varieties for dropdown filter
$distinctVarieties = $db->query("SELECT DISTINCT variety FROM product_variants ORDER BY variety ASC")->fetchAll(PDO::FETCH_COLUMN);

// Fetch Stock Change History Audit Log (Recent 15)
$stmt_history = $db->query("
    SELECT sh.*, p.name as product_name, p.sku 
    FROM stock_history sh 
    JOIN products p ON sh.product_id = p.id 
    ORDER BY sh.id DESC LIMIT 15
");
$stock_history = $stmt_history->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/admin_header.php';
?>

<style>
/* ─── Theme Colors ─── */
.bg-navy { background-color: #17243A !important; }
.text-navy { color: #17243A !important; }
.btn-navy { background-color: #17243A !important; color: #ffffff !important; border-color: #17243A !important; }
.btn-navy:hover { background-color: #0f172a !important; color: #ffffff !important; }
.text-gold { color: #C89B3C !important; }
.btn-gold { background-color: #C89B3C !important; color: #ffffff !important; border-color: #C89B3C !important; }
.btn-gold:hover { background-color: #b5892f !important; color: #ffffff !important; }

/* ─── Modern Stock Management Styles ─── */
.stock-header-title {
  font-size: 1.5rem;
  font-weight: 700;
  color: #17243A;
  letter-spacing: -0.3px;
  margin: 0;
}
.stock-header-sub {
  color: #64748B;
  font-size: 0.875rem;
  margin-top: 2px;
}
.stock-stat-card {
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  padding: 16px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  height: 100%;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.stock-stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0,0,0,0.06);
}
.stock-stat-icon {
  width: 44px;
  height: 44px;
  min-width: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.25rem;
}
.stock-stat-val {
  font-size: 1.4rem;
  font-weight: 700;
  line-height: 1.2;
  color: #17243A;
}
.stock-stat-lbl {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #64748B;
}

/* Stock Action Buttons Bar */
.btn-stock-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 0 15px;
  font-size: 0.85rem;
  font-weight: 600;
  border-radius: 8px;
  white-space: nowrap;
  transition: all 0.2s ease;
  height: 38px;
}

/* Filter Box */
.stock-filter-box {
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  padding: 16px 20px;
  margin-bottom: 20px;
}
.stock-filter-box .form-control,
.stock-filter-box .form-select,
.stock-filter-box .input-group-text,
.stock-filter-box .btn {
  height: 38px;
  font-size: 0.85rem;
  border-radius: 8px;
}

/* Stock Table Alignment */
.stock-table-card {
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  overflow: hidden;
}
.stock-table {
  width: 100%;
  margin-bottom: 0;
  border-collapse: separate;
  border-spacing: 0;
}
.stock-table th {
  background: #f8fafc;
  color: #475569;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  padding: 13px 14px;
  border-bottom: 2px solid #e2e8f0;
  white-space: nowrap;
  vertical-align: middle;
}
.stock-table td {
  padding: 12px 14px;
  font-size: 0.85rem;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
  white-space: nowrap;
}
.stock-table td.cell-wrap {
  white-space: normal;
}
.stock-table tr:hover td {
  background-color: #f8fafc;
}
.stock-code-pill {
  background: #f1f5f9;
  color: #17243A;
  padding: 4px 8px;
  border-radius: 6px;
  font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.775rem;
  font-weight: 600;
  border: 1px solid #e2e8f0;
  display: inline-block;
  white-space: nowrap;
}
.stock-badge {
  padding: 5px 10px;
  border-radius: 6px;
  font-size: 0.75rem;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
}
.formula-callout {
  background: #f8fafc;
  border-left: 3px solid #C89B3C;
  border-radius: 6px;
  padding: 8px 12px;
  font-size: 0.8rem;
  color: #334155;
  margin-bottom: 12px;
}

/* Upload Modal Styles */
.dropzone-box {
  border: 2px dashed #cbd5e1;
  background: #f8fafc;
  border-radius: 12px;
  padding: 28px 20px;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s ease;
}
.dropzone-box:hover, .dropzone-box.dragover {
  border-color: #C89B3C;
  background: #fefce8;
}
.dropzone-icon {
  font-size: 2.25rem;
  color: #C89B3C;
  margin-bottom: 8px;
}
.preview-table-wrapper {
  max-height: 400px;
  overflow-y: auto;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}
</style>

<!-- ─── 1. Header Section ─── -->
<div class="d-flex flex-column flex-xl-row justify-content-between align-items-start align-items-xl-center gap-3 mb-4 admin-page-header">
  <div>
    <h3 class="stock-header-title">Wholesale Stock Management</h3>
    <p class="stock-header-sub mb-0">
      Manage Bundle & Loose Piece Stock in bulk via Excel. 
      <span class="text-gold fw-semibold">Total Pieces = (Bundle Stock × Pieces Per Bundle) + Loose Pieces</span>
    </p>
  </div>
  
  <!-- Action Buttons in Clean Row -->
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <a href="stock_excel_action.php?action=template" class="btn btn-outline-success btn-stock-action" title="Download Excel template pre-populated with wholesale columns">
      <i class="bi bi-file-earmark-spreadsheet me-1"></i> Stock Template
    </a>
    <a href="stock_excel_action.php?action=export" class="btn btn-outline-primary btn-stock-action" title="Export current wholesale stock from database">
      <i class="bi bi-download me-1"></i> Export Stock
    </a>
    <button type="button" class="btn btn-gold btn-stock-action fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#uploadExcelModal">
      <i class="bi bi-cloud-arrow-up-fill me-1"></i> Upload Excel
    </button>
  </div>
</div>

<!-- ─── 2. Metric Stat Cards ─── -->
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stock-stat-card">
      <div class="stock-stat-icon bg-primary-subtle text-primary">
        <i class="bi bi-boxes"></i>
      </div>
      <div>
        <div class="stock-stat-val"><?php echo number_format($totalVariants); ?></div>
        <div class="stock-stat-lbl">Total Varieties</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stock-stat-card">
      <div class="stock-stat-icon bg-info-subtle text-info">
        <i class="bi bi-box-seam"></i>
      </div>
      <div>
        <div class="stock-stat-val"><?php echo number_format($totalBundles); ?></div>
        <div class="stock-stat-lbl">Bundle Stock</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stock-stat-card">
      <div class="stock-stat-icon bg-secondary-subtle text-secondary">
        <i class="bi bi-grid-3x3"></i>
      </div>
      <div>
        <div class="stock-stat-val"><?php echo number_format($totalLoose); ?></div>
        <div class="stock-stat-lbl">Loose Piece Stock</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stock-stat-card">
      <div class="stock-stat-icon bg-success-subtle text-success">
        <i class="bi bi-layers-fill"></i>
      </div>
      <div>
        <div class="stock-stat-val"><?php echo number_format($totalPieces); ?></div>
        <div class="stock-stat-lbl">Total Piece Stock</div>
      </div>
    </div>
  </div>
</div>

<!-- ─── 3. Search & Filters ─── -->
<div class="stock-filter-box">
  <form action="stock.php" method="GET" class="row g-2 align-items-center">
    <div class="col-md-5">
      <div class="input-group">
        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
        <input type="text" name="search" id="stockSearchInput" value="<?php echo htmlspecialchars($search); ?>" class="form-control border-start-0 ps-0" placeholder="Search by Product Name, Code, Brand, or Stock Availability...">
        <?php if ($search !== ''): ?>
          <button type="button" class="btn btn-outline-secondary border-start-0" id="stockClearInputBtn" title="Clear search keyword">
            <i class="bi bi-x-lg"></i>
          </button>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-6 col-md-3">
      <select name="variety" class="form-select">
        <option value="">-- All Varieties --</option>
        <?php foreach ($distinctVarieties as $v): ?>
          <option value="<?php echo htmlspecialchars($v); ?>" <?php echo ($varietyFilter === $v) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($v); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-6 col-md-2">
      <select name="status" class="form-select">
        <option value="">-- All Status --</option>
        <option value="in_stock" <?php echo ($statusFilter === 'in_stock') ? 'selected' : ''; ?>>In Stock</option>
        <option value="low_stock" <?php echo ($statusFilter === 'low_stock') ? 'selected' : ''; ?>>Low Stock</option>
        <option value="out_of_stock" <?php echo ($statusFilter === 'out_of_stock') ? 'selected' : ''; ?>>Out of Stock</option>
      </select>
    </div>

    <div class="col-12 col-md-2 d-flex gap-2">
      <button type="submit" class="btn btn-navy text-white w-100 fw-semibold">
        <i class="bi bi-funnel me-1"></i> Filter
      </button>
      <?php if ($search || $varietyFilter || $statusFilter): ?>
        <a href="stock.php" class="btn btn-outline-secondary" title="Reset Filters" style="width: 38px;">
          <i class="bi bi-x-lg"></i>
        </a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- ─── 4. Wholesale Stock Table & Quick Update ─── -->
<div class="stock-table-card mb-4">
  <div class="p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div class="fw-bold text-navy d-flex align-items-center">
      <i class="bi bi-table text-gold me-2 fs-5"></i>
      <span>Product Variety Stock Records</span>
      <span class="badge bg-light text-secondary ms-2 border"><?php echo number_format($totalRecords); ?> items found</span>
    </div>
    <div class="small text-muted fw-semibold">
      Showing page <?php echo $page; ?> of <?php echo $totalPages; ?>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table stock-table align-middle mb-0">
      <thead>
        <tr>
          <th style="min-width: 160px;">Product Code</th>
          <th style="min-width: 240px;">Product Name</th>
          <th style="min-width: 100px;" class="text-center">Variety</th>
          <th style="min-width: 110px;" class="text-center">Pack Size</th>
          <th style="min-width: 130px;" class="text-center">Bundle Stock</th>
          <th style="min-width: 120px;" class="text-center">Loose Pieces</th>
          <th style="min-width: 160px;" class="text-center">Total Piece Stock</th>
          <th style="min-width: 115px;" class="text-center">Status</th>
          <th style="min-width: 125px;" class="text-center">Last Updated</th>
          <th style="min-width: 90px;" class="text-center">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($items)): ?>
          <tr>
            <td colspan="10" class="text-center py-5 text-muted">
              <i class="bi bi-inbox fs-2 d-block mb-2 text-secondary"></i>
              No products or varieties match the selected filters.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($items as $it): ?>
            <?php 
              $ppb = max(1, (int)$it['pieces_per_bundle']);
              $bndl = (int)$it['bundle_stock'];
              $loose = (int)$it['loose_stock'];
              $totPcs = ($bndl * $ppb) + $loose;
            ?>
            <tr>
              <td>
                <span class="stock-code-pill"><?php echo htmlspecialchars($it['product_code']); ?></span>
              </td>
              <td class="cell-wrap">
                <div class="fw-bold text-navy lh-sm"><?php echo htmlspecialchars($it['product_name']); ?></div>
                <div class="small text-muted" style="font-size: 0.75rem;">
                  SKU: <?php echo htmlspecialchars($it['product_sku']); ?>
                  <?php if (!empty($it['page_count'])): ?>
                    • <?php echo $it['page_count']; ?> Pgs
                  <?php endif; ?>
                </div>
              </td>
              <td class="text-center">
                <span class="badge bg-secondary-subtle text-secondary border fw-bold" style="font-size: 0.75rem; padding: 5px 9px;">
                  <?php echo htmlspecialchars($it['variety']); ?>
                </span>
                <?php if (!empty($it['variety_display']) && $it['variety_display'] !== $it['variety']): ?>
                  <div class="text-muted text-truncate" style="font-size: 0.7rem; max-width: 95px; margin: 2px auto 0;"><?php echo htmlspecialchars($it['variety_display']); ?></div>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <span class="badge bg-light text-dark border fw-normal" style="font-size: 0.75rem; padding: 5px 9px;">
                  <?php echo $ppb; ?> pcs/bndl
                </span>
              </td>
              <td class="text-center">
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-bold" style="font-size: 0.8rem; padding: 5px 10px;">
                  <?php echo number_format($bndl); ?> Bundles
                </span>
              </td>
              <td class="text-center">
                <span class="badge bg-secondary-subtle text-secondary border fw-semibold" style="font-size: 0.8rem; padding: 5px 10px;">
                  <?php echo number_format($loose); ?> Pcs
                </span>
              </td>
              <td class="text-center">
                <div class="fw-bold text-navy" style="font-size: 0.95rem; line-height: 1.2;"><?php echo number_format($totPcs); ?> Pcs</div>
                <div class="text-muted" style="font-size: 0.72rem; font-family: SFMono-Regular, Menlo, monospace;">(<?php echo $bndl; ?> × <?php echo $ppb; ?>) + <?php echo $loose; ?></div>
              </td>
              <td class="text-center">
                <?php if ($totPcs == 0): ?>
                  <span class="stock-badge bg-danger-subtle text-danger border border-danger-subtle">
                    <i class="bi bi-x-circle-fill"></i> Out
                  </span>
                <?php elseif ($totPcs <= 20): ?>
                  <span class="stock-badge bg-warning-subtle text-dark border border-warning-subtle">
                    <i class="bi bi-exclamation-triangle-fill"></i> Low
                  </span>
                <?php else: ?>
                  <span class="stock-badge bg-success-subtle text-success border border-success-subtle">
                    <i class="bi bi-check-circle-fill"></i> In Stock
                  </span>
                <?php endif; ?>
              </td>
              <td class="text-center text-muted" style="font-size: 0.8rem;">
                <?php echo format_datetime($it['updated_at']); ?>
              </td>
              <td class="text-center">
                <div class="d-flex justify-content-center gap-1">
                  <a href="print_label.php?variant_id=<?php echo $it['variant_id']; ?>" target="_blank" class="btn btn-outline-primary btn-sm fw-semibold px-2 py-1" title="Print Bundle Label">
                    <i class="bi bi-printer me-1"></i> Label
                  </a>
                  <button type="button" class="btn btn-outline-dark btn-sm fw-semibold px-2 py-1" 
                          onclick="openWholesaleEditModal(<?php echo $it['variant_id']; ?>, '<?php echo addslashes(htmlspecialchars($it['product_name'])); ?>', '<?php echo addslashes(htmlspecialchars($it['variety'])); ?>', <?php echo $bndl; ?>, <?php echo $ppb; ?>, <?php echo $loose; ?>)">
                    <i class="bi bi-pencil-square me-1"></i> Edit
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
    <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="small text-muted">
        Showing <?php echo $offset + 1; ?> to <?php echo min($totalRecords, $offset + $perPage); ?> of <?php echo $totalRecords; ?> entries
      </div>
      <ul class="pagination pagination-sm mb-0">
        <?php
          $qParams = $_GET;
          $makePageUrl = function($pNum) use ($qParams) {
              $qParams['page'] = $pNum;
              return 'stock.php?' . http_build_query($qParams);
          };
        ?>
        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
          <a class="page-link" href="<?php echo $makePageUrl($page - 1); ?>">Previous</a>
        </li>
        <?php
          $startPage = max(1, $page - 2);
          $endPage = min($totalPages, $page + 2);
          for ($i = $startPage; $i <= $endPage; $i++):
        ?>
          <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
            <a class="page-link" href="<?php echo $makePageUrl($i); ?>"><?php echo $i; ?></a>
          </li>
        <?php endfor; ?>
        <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
          <a class="page-link" href="<?php echo $makePageUrl($page + 1); ?>">Next</a>
        </li>
      </ul>
    </div>
  <?php endif; ?>
</div>

<!-- ─── 5. Stock Change Audit History Log ─── -->
<div class="bg-white p-4 rounded-3 border mb-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold text-navy mb-0">
      <i class="bi bi-clock-history text-gold me-2"></i> Stock Change History Log
    </h5>
    <span class="small text-muted">Recent 15 audit events</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead>
        <tr class="table-light">
          <th>Product / Target</th>
          <th class="text-center" style="width: 180px;">Change (Previous → New)</th>
          <th>Reason / Reference</th>
          <th>Admin User</th>
          <th style="width: 140px;">Timestamp</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($stock_history)): ?>
          <tr><td colspan="5" class="text-center py-3 text-muted">No stock history recorded yet.</td></tr>
        <?php else: ?>
          <?php foreach ($stock_history as $sh): ?>
            <tr>
              <td>
                <strong class="text-navy small"><?php echo htmlspecialchars($sh['product_name']); ?></strong>
                <span class="small text-muted ms-1">(<?php echo htmlspecialchars($sh['sku']); ?>)</span>
              </td>
              <td class="text-center">
                <?php if ($sh['change_amount'] > 0): ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle">
                    +<?php echo $sh['change_amount']; ?> (<?php echo $sh['previous_stock']; ?> → <?php echo $sh['new_stock']; ?>)
                  </span>
                <?php elseif ($sh['change_amount'] < 0): ?>
                  <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                    <?php echo $sh['change_amount']; ?> (<?php echo $sh['previous_stock']; ?> → <?php echo $sh['new_stock']; ?>)
                  </span>
                <?php else: ?>
                  <span class="badge bg-secondary-subtle text-secondary border">
                    0 (<?php echo $sh['new_stock']; ?>)
                  </span>
                <?php endif; ?>
              </td>
              <td class="small text-muted"><?php echo htmlspecialchars($sh['reason']); ?></td>
              <td class="small text-muted"><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($sh['admin_username'] ?? 'admin'); ?></span></td>
              <td class="small text-muted"><?php echo format_datetime($sh['created_at']); ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: Quick Edit Wholesale Stock for Single Variant -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="wholesaleEditModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-navy text-white">
        <h5 class="modal-title fs-6 fw-bold"><i class="bi bi-pencil-square text-gold me-2"></i> Update Wholesale Stock</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form action="stock.php" method="POST">
        <input type="hidden" name="action" value="quick_update">
        <input type="hidden" name="variant_id" id="editVariantId">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label text-muted small fw-semibold mb-0">Product & Variety</label>
            <div class="fw-bold text-navy fs-6" id="editProdTitle">—</div>
          </div>

          <div class="formula-callout mb-3">
            <div class="fw-bold text-dark"><i class="bi bi-calculator me-1"></i> Auto-Calculated Formula:</div>
            <code>Total Pieces = (Bundle Stock × Pieces Per Bundle) + Loose Pieces</code>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-6">
              <label class="form-label text-dark fw-bold small">Bundle Stock (Bundles)</label>
              <input type="number" name="new_bundle_stock" id="editBundleStock" min="0" class="form-control fw-bold" required oninput="calculateModalTotal()">
            </div>
            <div class="col-6">
              <label class="form-label text-dark fw-bold small">Pieces Per Bundle</label>
              <input type="number" name="pieces_per_bundle" id="editPpb" min="1" class="form-control fw-bold" required oninput="calculateModalTotal()">
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label text-dark fw-bold small">Loose Piece Stock (Pcs)</label>
            <input type="number" name="new_loose_stock" id="editLooseStock" min="0" class="form-control fw-bold" required oninput="calculateModalTotal()">
          </div>

          <!-- Live Formula Output Box -->
          <div class="bg-light p-3 rounded border text-center">
            <div class="small text-muted mb-1 text-uppercase fw-semibold">Resulting Total Piece Stock:</div>
            <div class="fs-4 fw-bold text-navy" id="editLiveTotal">0 Pieces</div>
            <div class="small text-muted font-monospace" id="editLiveFormula">(0 × 10) + 0 = 0</div>
          </div>
        </div>
        <div class="modal-footer bg-light p-3">
          <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-gold fw-bold px-4">Save Wholesale Stock</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- MODAL: Bulk Excel Stock Upload & Validation Preview -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="uploadExcelModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg">
      <div class="modal-header bg-navy text-white py-3">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-file-earmark-spreadsheet text-gold fs-4"></i>
          <div>
            <h5 class="modal-title fw-bold fs-6 mb-0">Upload Stock Excel → Bulk Wholesale Update</h5>
            <div class="small text-light opacity-75" style="font-size: 0.75rem;">Fast, validated bulk update for Bundle Stock & Loose Pieces</div>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" id="modalCloseBtn"></button>
      </div>

      <div class="modal-body p-4">
        <!-- Step 1: Upload Form State -->
        <div id="uploadFormState">
          <div class="alert alert-light border d-flex align-items-center justify-content-between p-3 mb-3">
            <div class="d-flex align-items-center gap-2 small">
              <i class="bi bi-info-circle-fill text-primary fs-5"></i>
              <div>
                <strong>Excel Columns (Import):</strong> 
                <code>Product Code</code>, <code>Product Name</code>, <code>Page Count</code>, <code>Variety</code>, <code>Stock Quantity</code> (Total Pieces). 
                <span class="text-muted">Advanced export may include bundle columns. A simple sheet with <code>Stock Quantity</code> is accepted.</span>
              </div>
            </div>
            <a href="stock_excel_action.php?action=template" class="btn btn-outline-success btn-sm fw-semibold">
              <i class="bi bi-download me-1"></i> Get Template
            </a>
          </div>

          <div class="formula-callout mb-3">
            <strong><i class="bi bi-magic me-1"></i> Automatic Calculation:</strong> Total Piece Stock is calculated automatically from the stock value you provide. If using bundle columns, Total = <code>(Bundle Stock × Pieces Per Bundle) + Loose Piece Stock</code>; if using <code>Stock Quantity</code> alone, it is converted to bundles+loose using the variant's pack size.
          </div>

          <form id="excelStockForm" enctype="multipart/form-data">
            <div class="dropzone-box mb-3" id="dropzoneBox" onclick="document.getElementById('excelFileInput').click()">
              <input type="file" name="stock_excel_file" id="excelFileInput" accept=".xlsx,.xls,.csv" class="d-none" onchange="handleFileSelected(this)">
              <div class="dropzone-icon"><i class="bi bi-cloud-arrow-up-fill"></i></div>
              <h6 class="fw-bold text-dark mb-1">Click to browse or drag & drop Excel / CSV file here</h6>
              <p class="text-muted small mb-0">Accepts .xlsx or .csv files with wholesale columns</p>
              <div id="selectedFileBadge" class="mt-2 text-primary fw-bold small d-none">
                <i class="bi bi-file-earmark-check me-1"></i> <span id="selectedFileName"></span>
              </div>
            </div>

            <div class="d-flex justify-content-end gap-2">
              <button type="button" class="btn btn-secondary px-3" data-bs-dismiss="modal">Cancel</button>
              <button type="button" class="btn btn-warning fw-bold px-4" id="validateBtn" onclick="submitExcelPreview()" disabled>
                <i class="bi bi-search me-1"></i> Validate & Preview
              </button>
            </div>
          </form>
        </div>

        <!-- Step 2: Loading State -->
        <div id="uploadLoadingState" class="text-center py-5 d-none">
          <div class="spinner-border text-gold mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
          <h6 class="fw-bold text-navy" id="loadingTitle">Validating Wholesale Excel File...</h6>
          <p class="text-muted small mb-0" id="loadingDesc">Verifying product codes, varieties, bundle stock, pack sizes, and loose piece values...</p>
        </div>

        <!-- Step 3: Error State -->
        <div id="uploadErrorState" class="d-none">
          <div class="alert alert-danger border-danger-subtle p-3 mb-3">
            <h6 class="fw-bold text-danger mb-1"><i class="bi bi-exclamation-octagon-fill me-1"></i> Excel Upload Failed</h6>
            <p class="small mb-0" id="errorMessageText">The file contains errors. No changes were made to the database.</p>
          </div>
          
          <div class="bg-light p-3 rounded border mb-3" style="max-height: 250px; overflow-y: auto;">
            <div class="fw-bold small text-muted mb-2 text-uppercase">Detailed Error Report:</div>
            <ul class="list-unstyled mb-0 small text-danger font-monospace" id="errorListUl"></ul>
          </div>

          <div class="d-flex justify-content-between flex-wrap gap-2">
            <div class="d-flex gap-2">
              <a href="stock_excel_action.php?action=template" class="btn btn-outline-success btn-sm">
                <i class="bi bi-download me-1"></i> Download Correct Template
              </a>
              <a href="stock_excel_action.php?action=report" class="btn btn-outline-danger btn-sm" target="_blank">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Download Import Report
              </a>
            </div>
            <button type="button" class="btn btn-secondary px-4" onclick="resetUploadModal()">
              <i class="bi bi-arrow-left me-1"></i> Upload Corrected File
            </button>
          </div>
        </div>

        <!-- Step 4: Preview State (All valid) -->
        <div id="uploadPreviewState" class="d-none">
          <div class="alert alert-success border-success-subtle d-flex align-items-center justify-content-between p-3 mb-3">
            <div>
              <h6 class="fw-bold text-success mb-0"><i class="bi bi-check-circle-fill me-1"></i> Validation Successful!</h6>
              <div class="small text-muted" id="previewCountSummary">0 products/varieties will be updated.</div>
            </div>
            <span class="badge bg-success fs-7">Wholesale Calculation Validated</span>
          </div>

          <div class="preview-table-wrapper mb-3">
            <table class="table table-sm align-middle table-hover mb-0">
              <thead class="table-light sticky-top">
                <tr>
                  <th style="width: 140px;">Product Code</th>
                  <th>Product</th>
                  <th style="width: 80px;">Page Count</th>
                  <th style="width: 80px;">Variety</th>
                  <th class="text-center" style="width: 90px;">Pack Size</th>
                  <th class="text-center" style="width: 120px;">Bundle Stock</th>
                  <th class="text-center" style="width: 110px;">Loose Pieces</th>
                  <th class="text-center" style="width: 150px;">Total Piece Stock</th>
                  <th class="text-center" style="width: 90px;">Change</th>
                </tr>
              </thead>
              <tbody id="previewTableBody"></tbody>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
              <button type="button" class="btn btn-outline-secondary px-3" onclick="cancelPreview()">
                Cancel
              </button>
              <a href="stock_excel_action.php?action=report" class="btn btn-outline-primary px-3" target="_blank">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> Download Import Report
              </a>
            </div>
            <button type="button" class="btn btn-success fw-bold px-4" id="confirmCommitBtn" onclick="confirmStockUpdate()">
              <i class="bi bi-check-circle-fill me-1"></i> Confirm Stock Update
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
// Open Quick Edit Modal for Single Variant
function openWholesaleEditModal(variantId, prodName, variety, bundleStock, ppb, looseStock) {
  document.getElementById('editVariantId').value = variantId;
  document.getElementById('editProdTitle').textContent = prodName + ' (' + variety + ')';
  document.getElementById('editBundleStock').value = bundleStock;
  document.getElementById('editPpb').value = ppb;
  document.getElementById('editLooseStock').value = looseStock;
  calculateModalTotal();
  new bootstrap.Modal(document.getElementById('wholesaleEditModal')).show();
}

function calculateModalTotal() {
  const b = Math.max(0, parseInt(document.getElementById('editBundleStock').value) || 0);
  const ppb = Math.max(1, parseInt(document.getElementById('editPpb').value) || 1);
  const l = Math.max(0, parseInt(document.getElementById('editLooseStock').value) || 0);
  const total = (b * ppb) + l;

  document.getElementById('editLiveTotal').textContent = total.toLocaleString() + ' Pieces';
  document.getElementById('editLiveFormula').textContent = `(${b} × ${ppb}) + ${l} = ${total}`;
}

// File Drag & Drop Handling
const dropzone = document.getElementById('dropzoneBox');
const fileInput = document.getElementById('excelFileInput');
const validateBtn = document.getElementById('validateBtn');

['dragenter', 'dragover'].forEach(eventName => {
  dropzone.addEventListener(eventName, (e) => {
    e.preventDefault(); e.stopPropagation();
    dropzone.classList.add('dragover');
  }, false);
});
['dragleave', 'drop'].forEach(eventName => {
  dropzone.addEventListener(eventName, (e) => {
    e.preventDefault(); e.stopPropagation();
    dropzone.classList.remove('dragover');
  }, false);
});
dropzone.addEventListener('drop', (e) => {
  const dt = e.dataTransfer;
  if (dt.files && dt.files.length) {
    fileInput.files = dt.files;
    handleFileSelected(fileInput);
  }
});

function handleFileSelected(input) {
  if (input.files && input.files.length > 0) {
    const fileName = input.files[0].name;
    document.getElementById('selectedFileName').textContent = fileName;
    document.getElementById('selectedFileBadge').classList.remove('d-none');
    validateBtn.disabled = false;
  } else {
    document.getElementById('selectedFileBadge').classList.add('d-none');
    validateBtn.disabled = true;
  }
}

function showModalState(stateName) {
  document.getElementById('uploadFormState').classList.toggle('d-none', stateName !== 'form');
  document.getElementById('uploadLoadingState').classList.toggle('d-none', stateName !== 'loading');
  document.getElementById('uploadErrorState').classList.toggle('d-none', stateName !== 'error');
  document.getElementById('uploadPreviewState').classList.toggle('d-none', stateName !== 'preview');
}

function resetUploadModal() {
  fileInput.value = '';
  document.getElementById('selectedFileBadge').classList.add('d-none');
  validateBtn.disabled = true;
  showModalState('form');
}

// Submit Excel File for Validation & Preview
function submitExcelPreview() {
  if (!fileInput.files || fileInput.files.length === 0) {
    alert('Please select an Excel or CSV file to upload.');
    return;
  }

  const formData = new FormData(document.getElementById('excelStockForm'));
  showModalState('loading');
  document.getElementById('loadingTitle').textContent = 'Validating Wholesale Excel File...';
  document.getElementById('loadingDesc').textContent = 'Checking product codes, varieties, bundle stock, and calculating piece totals...';

  fetch('stock_excel_action.php?action=preview', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      renderPreviewTable(data.preview, data.count);
      showModalState('preview');
    } else {
      renderErrorState(data.message, data.errors || []);
      showModalState('error');
    }
  })
  .catch(err => {
    renderErrorState('An unexpected network or server error occurred: ' + err.message, []);
    showModalState('error');
  });
}

function renderErrorState(message, errors) {
  document.getElementById('errorMessageText').textContent = message;
  const listEl = document.getElementById('errorListUl');
  listEl.innerHTML = '';
  if (errors && errors.length > 0) {
    errors.forEach(err => {
      const li = document.createElement('li');
      li.className = 'mb-1 text-danger';
      li.innerHTML = '<i class="bi bi-x-circle me-1"></i> ' + escapeHtml(err);
      listEl.appendChild(li);
    });
  } else {
    const li = document.createElement('li');
    li.textContent = message;
    listEl.appendChild(li);
  }
}

function renderPreviewTable(items, count) {
  window.currentPreviewItems = items;
  document.getElementById('previewCountSummary').textContent = `${count} products/varieties will be updated.`;
  const tbody = document.getElementById('previewTableBody');
  tbody.innerHTML = '';

  items.forEach(it => {
    const tr = document.createElement('tr');
    const chg = it.change_pieces;
    let chgBadge = `<span class="badge bg-secondary-subtle text-secondary">0</span>`;
    if (chg > 0) {
      chgBadge = `<span class="badge bg-success-subtle text-success">+${chg}</span>`;
    } else if (chg < 0) {
      chgBadge = `<span class="badge bg-danger-subtle text-danger">${chg}</span>`;
    }

    tr.innerHTML = `
      <td><code>${escapeHtml(it.product_code)}</code></td>
      <td><strong>${escapeHtml(it.product_name)}</strong></td>
      <td class="text-center text-muted">${escapeHtml(it.page_count ?? '')}</td>
      <td><span class="badge bg-light text-dark border">${escapeHtml(it.variety)}</span></td>
      <td class="text-center text-muted">${it.pieces_per_bundle} pcs</td>
      <td class="text-center">
        <span class="text-muted text-decoration-line-through">${it.old_bundle_stock}</span> 
        &rarr; <strong class="text-primary">${it.new_bundle_stock} Bndl</strong>
      </td>
      <td class="text-center">
        <span class="text-muted text-decoration-line-through">${it.old_loose_stock}</span> 
        &rarr; <strong class="text-secondary">${it.new_loose_stock} Pcs</strong>
      </td>
      <td class="text-center">
        <div class="fw-bold text-navy">${it.new_total_pieces} Pcs</div>
        <div class="small text-muted" style="font-size: 0.68rem; font-family: monospace;">(${it.new_bundle_stock} × ${it.pieces_per_bundle}) + ${it.new_loose_stock}</div>
      </td>
      <td class="text-center">${chgBadge}</td>
    `;
    tbody.appendChild(tr);
  });
}

// Cancel Preview
function cancelPreview() {
  window.currentPreviewItems = null;
  fetch('stock_excel_action.php?action=cancel', { method: 'POST' })
    .finally(() => {
      resetUploadModal();
    });
}

// Confirm Stock Update
function confirmStockUpdate() {
  const commitBtn = document.getElementById('confirmCommitBtn');
  commitBtn.disabled = true;
  commitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Updating Database...';

  const formData = new FormData();
  if (window.currentPreviewItems && window.currentPreviewItems.length > 0) {
    formData.append('items', JSON.stringify(window.currentPreviewItems));
  }

  fetch('stock_excel_action.php?action=commit', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      // Reload page to reflect updated stocks and success flash
      window.location.href = 'stock.php';
    } else {
      alert('Error updating stock: ' + data.message);
      commitBtn.disabled = false;
      commitBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Confirm Stock Update';
    }
  })
  .catch(err => {
    alert('Network error while committing update: ' + err.message);
    commitBtn.disabled = false;
    commitBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Confirm Stock Update';
  });
}

function escapeHtml(str) {
  if (!str) return '';
  return str.toString()
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

document.addEventListener('DOMContentLoaded', function() {
  const clearBtn = document.getElementById('stockClearInputBtn');
  const input = document.getElementById('stockSearchInput');
  if (clearBtn && input) {
    clearBtn.addEventListener('click', function() {
      input.value = '';
      window.location.href = 'stock.php';
    });
  }

  // Auto-open the bulk Excel upload modal when launched via Excel Manager (?upload=1)
  const params = new URLSearchParams(window.location.search);
  if (params.get('upload') === '1') {
    const modalEl = document.getElementById('uploadExcelModal');
    if (modalEl && window.bootstrap) {
      bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
  }
});
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
