<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();
$admin = get_current_admin();

$status_filter = isset($_GET['status']) ? sanitize($_GET['status']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$whereClauses = ["1=1"];
$params = [];

if (!empty($status_filter)) {
    $whereClauses[] = "q.status = ?";
    $params[] = $status_filter;
}

if ($search !== '') {
    $term = "%{$search}%";
    $whereClauses[] = "(
        q.quotation_number LIKE ? 
        OR q.order_number LIKE ? 
        OR q.customer_name LIKE ? 
        OR q.customer_phone LIKE ? 
        OR q.customer_code LIKE ?
    )";
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$whereSql = implode(" AND ", $whereClauses);

// Count
$countStmt = $db->prepare("SELECT COUNT(*) FROM quotations q WHERE $whereSql");
$countStmt->execute($params);
$total_quotations_count = (int)$countStmt->fetchColumn();
$total_pages = max(1, ceil($total_quotations_count / $per_page));

// Fetch
$querySql = "
    SELECT q.*, 
           COUNT(qi.id) as item_count,
           SUM(qi.total_pieces) as total_pieces_sum
    FROM quotations q
    LEFT JOIN quotation_items qi ON qi.quotation_id = q.id
    WHERE $whereSql
    GROUP BY q.id
    ORDER BY q.id DESC
    LIMIT $per_page OFFSET $offset
";
$stmt = $db->prepare($querySql);
$stmt->execute($params);
$quotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Overall Statistics
$stats = [
    'total'            => (int)$db->query("SELECT COUNT(*) FROM quotations")->fetchColumn(),
    'pending'          => (int)$db->query("SELECT COUNT(*) FROM quotations WHERE status = 'Sent'")->fetchColumn(),
    'change_requested' => (int)$db->query("SELECT COUNT(*) FROM quotations WHERE status = 'Change Requested'")->fetchColumn(),
    'accepted'         => (int)$db->query("SELECT COUNT(*) FROM quotations WHERE status = 'Accepted'")->fetchColumn(),
    'declined'         => (int)$db->query("SELECT COUNT(*) FROM quotations WHERE status = 'Declined'")->fetchColumn(),
    'draft'            => (int)$db->query("SELECT COUNT(*) FROM quotations WHERE status = 'Draft'")->fetchColumn(),
];

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 admin-page-header">
  <div>
    <h3 class="fw-bold text-navy mb-0">
      <i class="bi bi-file-earmark-text text-gold me-1"></i> Order Quotation Management
    </h3>
    <small class="text-secondary">Track, review, edit, and send wholesale price quotations to customers.</small>
  </div>
  <div>
    <a href="orders.php" class="btn btn-gold btn-sm px-3 fw-bold">
      <i class="bi bi-box-seam me-1"></i> View All Orders to Send Quotations
    </a>
  </div>
</div>

<!-- Stats Overview Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-md-4 col-xl">
    <div class="stat-card border-0 shadow-sm d-flex align-items-center gap-3">
      <div class="stat-icon bg-navy text-gold">
        <i class="bi bi-file-earmark-text"></i>
      </div>
      <div>
        <div class="text-muted small fw-semibold">Total Quotations</div>
        <h4 class="fw-bold text-navy mb-0"><?php echo number_format($stats['total']); ?></h4>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-md-4 col-xl">
    <div class="stat-card border-0 shadow-sm d-flex align-items-center gap-3">
      <div class="stat-icon bg-primary-subtle text-primary">
        <i class="bi bi-clock-history"></i>
      </div>
      <div>
        <div class="text-muted small fw-semibold">Pending Response</div>
        <h4 class="fw-bold text-primary mb-0"><?php echo number_format($stats['pending']); ?></h4>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-md-4 col-xl">
    <div class="stat-card border-0 shadow-sm d-flex align-items-center gap-3">
      <div class="stat-icon bg-warning-subtle text-dark">
        <i class="bi bi-pencil-square text-warning"></i>
      </div>
      <div>
        <div class="text-muted small fw-semibold">Change Requested</div>
        <h4 class="fw-bold text-dark mb-0"><?php echo number_format($stats['change_requested']); ?></h4>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-md-4 col-xl">
    <div class="stat-card border-0 shadow-sm d-flex align-items-center gap-3">
      <div class="stat-icon bg-success-subtle text-success">
        <i class="bi bi-check-circle"></i>
      </div>
      <div>
        <div class="text-muted small fw-semibold">Accepted</div>
        <h4 class="fw-bold text-success mb-0"><?php echo number_format($stats['accepted']); ?></h4>
      </div>
    </div>
  </div>

  <div class="col-sm-6 col-md-4 col-xl">
    <div class="stat-card border-0 shadow-sm d-flex align-items-center gap-3">
      <div class="stat-icon bg-danger-subtle text-danger">
        <i class="bi bi-x-circle"></i>
      </div>
      <div>
        <div class="text-muted small fw-semibold">Declined</div>
        <h4 class="fw-bold text-danger mb-0"><?php echo number_format($stats['declined']); ?></h4>
      </div>
    </div>
  </div>
</div>

<!-- Filters & Search Toolbar -->
<div class="bg-white p-3 rounded-3 border shadow-sm mb-4">
  <form action="quotations.php" method="GET" class="row g-2 align-items-center">
    <div class="col-md-5">
      <div class="input-group">
        <span class="input-group-text bg-light text-navy"><i class="bi bi-search"></i></span>
        <input type="text" name="search" class="form-control" placeholder="Search by Quotation #, Order #, Customer, Phone..." value="<?php echo htmlspecialchars($search); ?>">
      </div>
    </div>

    <div class="col-md-3">
      <select name="status" class="form-select" onchange="this.form.submit()">
        <option value="">All Statuses (<?php echo $stats['total']; ?>)</option>
        <option value="Sent" <?php echo ($status_filter === 'Sent') ? 'selected' : ''; ?>>Pending Response (<?php echo $stats['pending']; ?>)</option>
        <option value="Change Requested" <?php echo ($status_filter === 'Change Requested') ? 'selected' : ''; ?>>Customer Changes (<?php echo $stats['change_requested']; ?>)</option>
        <option value="Accepted" <?php echo ($status_filter === 'Accepted') ? 'selected' : ''; ?>>Accepted (<?php echo $stats['accepted']; ?>)</option>
        <option value="Declined" <?php echo ($status_filter === 'Declined') ? 'selected' : ''; ?>>Declined (<?php echo $stats['declined']; ?>)</option>
        <option value="Draft" <?php echo ($status_filter === 'Draft') ? 'selected' : ''; ?>>Draft (<?php echo $stats['draft']; ?>)</option>
      </select>
    </div>

    <div class="col-md-4 d-flex gap-2">
      <button type="submit" class="btn btn-navy fw-semibold px-3"><i class="bi bi-funnel me-1"></i> Filter</button>
      <?php if (!empty($search) || !empty($status_filter)): ?>
        <a href="quotations.php" class="btn btn-outline-secondary px-3">Reset</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- Quotations List Table -->
<div class="bg-white rounded-3 border shadow-sm p-4">
  <div class="table-responsive">
    <table class="table align-middle table-hover">
      <thead class="table-light text-navy">
        <tr>
          <th>Quotation #</th>
          <th>Order Reference</th>
          <th>Customer</th>
          <th>Grand Total</th>
          <th>Status</th>
          <th>Date Sent / Created</th>
          <th>Customer Response</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($quotations)): ?>
          <tr>
            <td colspan="8" class="text-center py-5 text-muted">
              <i class="bi bi-file-earmark-text fs-1 text-muted d-block mb-2"></i>
              <h6 class="fw-bold text-navy mb-1">No quotations found</h6>
              <p class="small text-secondary mb-3">No quotations matched your current filters.</p>
              <a href="orders.php" class="btn btn-gold btn-sm px-3 fw-bold">Select an Order to Create a Quotation</a>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($quotations as $q): ?>
            <tr>
              <td>
                <div class="fw-bold text-navy">
                  <a href="quotation.php?id=<?php echo $q['id']; ?>" class="text-decoration-none text-navy">
                    <?php echo htmlspecialchars($q['quotation_number']); ?>
                  </a>
                </div>
                <div class="small text-muted">
                  <?php echo (int)$q['item_count']; ?> Products (<?php echo number_format((int)($q['total_pieces_sum'] ?? 0)); ?> Pcs)
                  <?php if ((int)$q['version'] > 1): ?>
                    <span class="badge bg-light text-dark border ms-1">v<?php echo $q['version']; ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <a href="orders.php?view=<?php echo $q['order_id']; ?>" class="fw-semibold text-decoration-none text-navy">
                  <?php echo htmlspecialchars($q['order_number']); ?>
                </a>
              </td>
              <td>
                <div class="fw-semibold text-navy"><?php echo htmlspecialchars($q['customer_name']); ?></div>
                <div class="small d-flex align-items-center gap-1.5 flex-wrap">
                  <span class="text-muted"><?php echo htmlspecialchars($q['customer_phone']); ?></span>
                  <?php if (!empty($q['customer_code'])): ?>
                    <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold" style="font-size: 0.7rem;">
                      <?php echo htmlspecialchars($q['customer_code']); ?>
                    </span>
                  <?php endif; ?>
                </div>
              </td>
              <td class="fw-bold text-navy fs-6">
                <?php echo format_price($q['grand_total']); ?>
              </td>
              <td>
                <?php echo render_quotation_status_badge($q['status']); ?>
              </td>
              <td class="small text-muted">
                <?php if (!empty($q['sent_at'])): ?>
                  <div><?php echo format_datetime($q['sent_at']); ?></div>
                <?php else: ?>
                  <div><?php echo format_datetime($q['created_at']); ?></div>
                  <span class="badge bg-light text-muted border">Draft</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($q['status'] === 'Accepted'): ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle fw-bold">
                    <i class="bi bi-check-circle me-1"></i> Accepted <?php echo !empty($q['customer_response_at']) ? format_datetime($q['customer_response_at']) : ''; ?>
                  </span>
                <?php elseif ($q['status'] === 'Declined'): ?>
                  <span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-bold" title="<?php echo htmlspecialchars($q['customer_decline_reason'] ?? 'No reason provided'); ?>">
                    <i class="bi bi-x-circle me-1"></i> Declined <?php echo !empty($q['customer_response_at']) ? format_datetime($q['customer_response_at']) : ''; ?>
                  </span>
                  <?php if (!empty($q['customer_decline_reason'])): ?>
                    <div class="text-truncate small text-danger mt-1" style="max-width: 180px;" title="<?php echo htmlspecialchars($q['customer_decline_reason']); ?>">
                      "<?php echo htmlspecialchars($q['customer_decline_reason']); ?>"
                    </div>
                  <?php endif; ?>
                <?php elseif ($q['status'] === 'Sent'): ?>
                  <span class="small text-muted fst-italic">Waiting for customer...</span>
                <?php else: ?>
                  <span class="small text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <a href="quotation.php?id=<?php echo $q['id']; ?>" class="btn btn-sm btn-gold fw-semibold">
                  <i class="bi bi-pencil-square me-1"></i> Review & Edit
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($total_pages > 1): ?>
    <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2 bg-light mt-3 rounded-2">
      <div class="small text-muted">
        Showing <strong><?php echo min($total_quotations_count, $offset + 1); ?></strong> to <strong><?php echo min($total_quotations_count, $offset + count($quotations)); ?></strong> of <strong><?php echo $total_quotations_count; ?></strong> quotations
      </div>
      <nav aria-label="Quotations pagination">
        <ul class="pagination pagination-sm mb-0">
          <?php
            $pagination_query = $_GET;
            unset($pagination_query['page']);
            $base_url = 'quotations.php?' . (empty($pagination_query) ? '' : http_build_query($pagination_query) . '&');
          ?>
          <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?php echo $base_url . 'page=' . ($page - 1); ?>"><i class="bi bi-chevron-left"></i> Previous</a>
          </li>
          <?php for ($i = 1; $i <= $total_pages; $i++): ?>
            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
              <a class="page-link <?php echo ($page == $i) ? 'bg-gold border-0 text-white' : ''; ?>" href="<?php echo $base_url . 'page=' . $i; ?>"><?php echo $i; ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?php echo $base_url . 'page=' . ($page + 1); ?>">Next <i class="bi bi-chevron-right"></i></a>
          </li>
        </ul>
      </nav>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
