<?php
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

if (is_customer_logged_in()) {
    $customer_id = $_SESSION['customer_id'];
    $cust = get_current_customer();
    $cCode = $cust['customer_code'] ?? '';
    $stmt = $db->prepare("SELECT * FROM orders WHERE user_id = ? OR (customer_code = ? AND customer_code IS NOT NULL) ORDER BY id DESC");
    $stmt->execute([$customer_id, $cCode]);
    $orders = $stmt->fetchAll();
} else {
    // Guest track order by order number, Customer ID, phone or email
    $order_num = isset($_GET['order_number']) ? sanitize($_GET['order_number']) : '';
    $orders = [];
    if ($order_num) {
        $stmt = $db->prepare("SELECT * FROM orders WHERE order_number = ? OR customer_code = ? OR customer_email = ? OR customer_phone = ? ORDER BY id DESC");
        $stmt->execute([$order_num, $order_num, $order_num, $order_num]);
        $orders = $stmt->fetchAll();
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section-padding py-4">
  <?php 
  $breadcrumb_crumbs = is_admin_logged_in() 
      ? ['Admin Dashboard' => 'admin/index.php', 'Orders' => ''] 
      : (is_customer_logged_in() ? ['My Account' => 'account.php', 'Orders' => ''] : ['Orders' => '']);
  echo render_page_navigation('Track & View Orders', $breadcrumb_crumbs); 
  ?>

  <?php if (!is_customer_logged_in() && empty($orders)): ?>
    <div class="mx-auto bg-white p-3.5 p-md-4 rounded-3 border mb-4 shadow-sm" style="max-width: 520px; width: 100%;">
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h5 class="fw-bold text-navy mb-0">Track Your Order</h5>
        <a href="track_order.php" class="small text-gold fw-bold text-decoration-none">
          <i class="bi bi-qr-code-scan me-1"></i> QR Scanner & Tracker
        </a>
      </div>
      <form action="track_order.php" method="GET">
        <div class="mb-3">
          <label class="form-label small fw-semibold">Enter Order #, Customer ID (e.g. AMB-SS-001), or Parcel #</label>
          <input type="text" name="ord" class="form-control" placeholder="e.g. ORD-20261005-0001 or AMB-SS-001" required>
        </div>
        <button type="submit" class="btn btn-gold w-100 justify-content-center">Track Order & Dispatch Status</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if (empty($orders)): ?>
    <?php if (is_customer_logged_in()): ?>
      <div class="text-center py-5 bg-white rounded-3 border">
        <i class="bi bi-box-seam fs-1 text-muted mb-3 d-block"></i>
        <h4>No Orders Found</h4>
        <p class="text-secondary">You haven't placed any orders yet.</p>
        <a href="products.php" class="btn btn-gold mt-2">Explore Notebooks</a>
      </div>
    <?php elseif (is_admin_logged_in()): ?>
      <div class="text-center py-5 bg-white rounded-3 border">
        <i class="bi bi-box-seam fs-1 text-muted mb-3 d-block"></i>
        <h4>Admin Order Management</h4>
        <p class="text-secondary">Use the search box above to track any customer order, or view full orders in the Admin Panel.</p>
        <a href="admin/orders.php" class="btn btn-gold mt-2"><i class="bi bi-box-seam me-1"></i> Open Admin Orders & Packing</a>
      </div>
    <?php endif; ?>
  <?php else: ?>
    <div class="bg-white p-4 rounded-3 border">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr class="table-light">
              <th>Order Number</th>
              <th>Date</th>
              <th>Customer</th>
              <th>Payment</th>
              <th>Amount</th>
              <th>Order Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $ord): ?>
              <tr>
                <td class="fw-bold text-navy"><?php echo htmlspecialchars($ord['order_number']); ?></td>
                <td class="small text-muted"><?php echo format_datetime($ord['created_at']); ?></td>
                <td><?php echo htmlspecialchars($ord['customer_name']); ?></td>
                <td><small class="badge bg-light text-dark border"><?php echo htmlspecialchars($ord['payment_method']); ?></small></td>
                <td class="fw-bold text-navy"><?php echo format_price($ord['grand_total']); ?></td>
                <td>
                  <?php echo render_order_status_badge($ord['order_status']); ?>
                </td>
                <td>
                  <div class="d-flex align-items-center gap-1.5 flex-wrap">
                    <a href="order-details.php?id=<?php echo $ord['id']; ?>" class="btn btn-outline-gold btn-sm">View Details</a>
                    <?php 
                    $ord_q = get_order_quotation($ord['id']);
                    if ($ord_q && $ord_q['status'] !== 'Draft' && is_customer_logged_in()): 
                    ?>
                      <a href="quotations.php?view=<?php echo $ord_q['id']; ?>" class="btn btn-sm <?php echo ($ord_q['status'] === 'Sent') ? 'btn-gold fw-bold' : 'btn-outline-navy'; ?>" title="View Wholesale Quotation">
                        <i class="bi bi-file-earmark-text me-1"></i> Quotation
                      </a>
                    <?php endif; ?>
                    <a href="track_order.php?ord=<?php echo urlencode($ord['order_number']); ?>" target="_blank" class="btn btn-outline-secondary btn-sm px-2" title="Track Live Status & QR">
                      <i class="bi bi-qr-code"></i>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
