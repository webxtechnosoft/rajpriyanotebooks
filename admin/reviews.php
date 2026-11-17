<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();

// Handle Approve / Reject / Delete Action
if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $act = $_GET['action'];

    if ($act === 'approve') {
        $stmt = $db->prepare("UPDATE reviews SET status = 'approved' WHERE id = ?");
        $stmt->execute([$id]);
        set_flash('success', 'Review approved and published publicly.');
    } elseif ($act === 'reject') {
        $stmt = $db->prepare("UPDATE reviews SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$id]);
        set_flash('success', 'Review rejected.');
    } elseif ($act === 'delete') {
        $stmt = $db->prepare("DELETE FROM reviews WHERE id = ?");
        $stmt->execute([$id]);
        set_flash('success', 'Review deleted.');
    }
    header("Location: reviews.php");
    exit();
}

$stmt = $db->query("SELECT r.*, p.name as product_name FROM reviews r JOIN products p ON r.product_id = p.id ORDER BY r.id DESC");
$reviews = $stmt->fetchAll();
$review_stats = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'rating_total' => 0];
foreach ($reviews as $review) {
    if (isset($review_stats[$review['status']])) {
        $review_stats[$review['status']]++;
    }
    $review_stats['rating_total'] += (int)$review['rating'];
}
$average_rating = count($reviews) > 0 ? $review_stats['rating_total'] / count($reviews) : 0;

require_once __DIR__ . '/includes/admin_header.php';
?>

<style>
  .reviews-page { --review-ink: #17243A; --review-muted: #64748B; --review-border: #E7ECF2; }
  .reviews-intro {
    position: relative; overflow: hidden; display: flex; align-items: center; justify-content: space-between;
    gap: 20px; padding: 22px 24px; margin-bottom: 18px; border: 1px solid var(--review-border);
    border-radius: 16px; background: linear-gradient(115deg, #fff 0%, #FBF8F0 100%);
  }
  .reviews-intro:after {
    content: ""; position: absolute; width: 170px; height: 170px; right: 12%; top: -110px;
    border: 24px solid rgba(200, 155, 60, .08); border-radius: 50%; pointer-events: none;
  }
  .reviews-eyebrow { color: #A77D28; font-size: .72rem; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; }
  .reviews-intro h3 { margin: 4px 0 5px; color: var(--review-ink); font-size: 1.35rem; font-weight: 750; }
  .reviews-intro p { margin: 0; color: var(--review-muted); font-size: .88rem; }
  .reviews-total { z-index: 1; min-width: 116px; padding: 12px 16px; border: 1px solid #EADDBD; border-radius: 12px; background: rgba(255,255,255,.82); text-align: center; }
  .reviews-total strong { display: block; color: var(--review-ink); font-size: 1.45rem; line-height: 1.2; }
  .reviews-total span { color: var(--review-muted); font-size: .72rem; font-weight: 700; }
  .review-stat { height: 100%; padding: 16px; border: 1px solid var(--review-border); border-radius: 13px; background: #fff; box-shadow: 0 3px 12px rgba(23,36,58,.035); }
  .review-stat-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
  .review-stat-label { color: var(--review-muted); font-size: .77rem; font-weight: 700; }
  .review-stat-value { margin-top: 8px; color: var(--review-ink); font-size: 1.55rem; font-weight: 750; line-height: 1.1; }
  .review-stat-icon { display: grid; width: 38px; height: 38px; place-items: center; border-radius: 11px; font-size: 1.05rem; }
  .review-stat-icon.pending { color: #A76A06; background: #FFF4D8; }
  .review-stat-icon.approved { color: #16835D; background: #E8F7F0; }
  .review-stat-icon.rejected { color: #C2414B; background: #FFF0F0; }
  .review-stat-icon.rating { color: #A77D28; background: #FBF5E7; }
  .review-stat-note { margin-top: 6px; color: #94A3B8; font-size: .7rem; }
  .reviews-panel { overflow: hidden; border: 1px solid var(--review-border); border-radius: 15px; background: #fff; box-shadow: 0 4px 15px rgba(23,36,58,.045); }
  .reviews-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 14px; padding: 16px 18px; border-bottom: 1px solid var(--review-border); }
  .reviews-toolbar-title { color: var(--review-ink); font-size: .97rem; font-weight: 750; }
  .reviews-toolbar-subtitle { margin-top: 2px; color: var(--review-muted); font-size: .76rem; }
  .reviews-controls { display: flex; align-items: center; justify-content: flex-end; gap: 10px; }
  .reviews-search { position: relative; width: min(250px, 30vw); }
  .reviews-search i { position: absolute; top: 50%; left: 12px; color: #94A3B8; transform: translateY(-50%); }
  .reviews-search input { height: 38px; padding-left: 34px; border: 1px solid #DCE3EB; border-radius: 9px; font-size: .82rem; }
  .review-filter { display: flex; gap: 4px; padding: 4px; border: 1px solid #E8EDF3; border-radius: 10px; background: #F7F9FC; }
  .review-filter button { padding: 6px 10px; border: 0; border-radius: 7px; background: transparent; color: #64748B; font-size: .76rem; font-weight: 700; white-space: nowrap; }
  .review-filter button.active { background: #fff; color: var(--review-ink); box-shadow: 0 1px 4px rgba(23,36,58,.12); }
  .reviews-table { min-width: 820px; margin: 0; }
  .reviews-table thead th { padding: 11px 16px !important; border-bottom: 1px solid var(--review-border); }
  .reviews-table tbody td { padding: 14px 16px !important; }
  .review-product { max-width: 205px; color: var(--review-ink); font-size: .84rem; font-weight: 700; line-height: 1.4; }
  .review-customer { display: flex; align-items: center; gap: 9px; color: #334155; font-size: .82rem; font-weight: 650; white-space: nowrap; }
  .review-avatar { display: grid; width: 32px; height: 32px; flex: 0 0 32px; place-items: center; border-radius: 10px; background: #EEF2F7; color: #475569; font-size: .75rem; font-weight: 800; text-transform: uppercase; }
  .review-stars { display: flex; gap: 2px; color: #D99B20; font-size: .8rem; white-space: nowrap; }
  .review-stars .empty { color: #D9E0E8; }
  .review-rating-number { margin-left: 5px; color: #64748B; font-size: .72rem; font-weight: 700; }
  .review-comment { max-width: 320px; min-width: 160px; color: #64748B; font-size: .8rem; line-height: 1.5; }
  .review-status { display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border-radius: 20px; font-size: .7rem; font-weight: 750; white-space: nowrap; }
  .review-status:before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
  .review-status.approved { background: #EAF7F0; color: #16835D; }
  .review-status.pending { background: #FFF5DB; color: #A76A06; }
  .review-status.rejected { background: #FFF0F0; color: #C2414B; }
  .review-actions { display: flex; justify-content: flex-end; gap: 6px; white-space: nowrap; }
  .review-actions .btn { padding: .38rem .62rem !important; font-size: .75rem; }
  .reviews-no-results { display: none; padding: 42px 18px; color: var(--review-muted); text-align: center; }
  .reviews-no-results i { display: block; margin-bottom: 9px; color: #A8B3C2; font-size: 1.8rem; }
  @media (max-width: 767.98px) {
    .reviews-intro { padding: 17px; }
    .reviews-total { min-width: 86px; padding: 9px; }
    .reviews-toolbar { align-items: stretch; flex-direction: column; }
    .reviews-controls { align-items: stretch; flex-direction: column; }
    .reviews-search { width: 100%; }
    .review-filter { overflow-x: auto; }
    .review-filter button { flex: 1 0 auto; }
  }
</style>

<main class="reviews-page">
  <section class="reviews-intro">
    <div>
      <div class="reviews-eyebrow"><i class="bi bi-chat-square-heart me-1"></i> Customer experience</div>
      <h3>Reviews &amp; ratings</h3>
      <p>Keep customer feedback helpful and up to date across your store.</p>
    </div>
    <div class="reviews-total">
      <strong><?php echo number_format(count($reviews)); ?></strong>
      <span>TOTAL REVIEWS</span>
    </div>
  </section>

  <div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
      <div class="review-stat">
        <div class="review-stat-top"><span class="review-stat-label">Needs review</span><span class="review-stat-icon pending"><i class="bi bi-hourglass-split"></i></span></div>
        <div class="review-stat-value"><?php echo number_format($review_stats['pending']); ?></div>
        <div class="review-stat-note">Waiting for moderation</div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="review-stat">
        <div class="review-stat-top"><span class="review-stat-label">Published</span><span class="review-stat-icon approved"><i class="bi bi-check2-circle"></i></span></div>
        <div class="review-stat-value"><?php echo number_format($review_stats['approved']); ?></div>
        <div class="review-stat-note">Visible to your customers</div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="review-stat">
        <div class="review-stat-top"><span class="review-stat-label">Rejected</span><span class="review-stat-icon rejected"><i class="bi bi-slash-circle"></i></span></div>
        <div class="review-stat-value"><?php echo number_format($review_stats['rejected']); ?></div>
        <div class="review-stat-note">Not published</div>
      </div>
    </div>
    <div class="col-6 col-xl-3">
      <div class="review-stat">
        <div class="review-stat-top"><span class="review-stat-label">Average rating</span><span class="review-stat-icon rating"><i class="bi bi-star-fill"></i></span></div>
        <div class="review-stat-value"><?php echo number_format($average_rating, 1); ?><span class="fs-6 text-secondary fw-semibold"> / 5</span></div>
        <div class="review-stat-note">Across all customer reviews</div>
      </div>
    </div>
  </div>

  <section class="reviews-panel" aria-label="Customer reviews">
    <div class="reviews-toolbar">
      <div>
        <div class="reviews-toolbar-title">All feedback</div>
        <div class="reviews-toolbar-subtitle">Search reviews or filter by moderation status.</div>
      </div>
      <div class="reviews-controls">
        <label class="reviews-search">
          <i class="bi bi-search" aria-hidden="true"></i>
          <input type="search" class="form-control" id="reviewSearch" placeholder="Search reviews..." aria-label="Search reviews">
        </label>
        <div class="review-filter" role="group" aria-label="Filter reviews by status">
          <button type="button" class="active" data-review-filter="all" aria-pressed="true">All</button>
          <button type="button" data-review-filter="pending" aria-pressed="false">Pending</button>
          <button type="button" data-review-filter="approved" aria-pressed="false">Approved</button>
          <button type="button" data-review-filter="rejected" aria-pressed="false">Rejected</button>
        </div>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle reviews-table">
        <thead>
          <tr>
            <th>PRODUCT</th>
            <th>CUSTOMER</th>
            <th>RATING</th>
            <th>COMMENT</th>
            <th>STATUS</th>
            <th class="text-end">ACTIONS</th>
          </tr>
        </thead>
        <tbody id="reviewsTableBody">
          <?php foreach ($reviews as $r): ?>
            <?php
              $status = in_array($r['status'], ['approved', 'pending', 'rejected'], true) ? $r['status'] : 'rejected';
              $rating = max(0, min(5, (int)$r['rating']));
              $customerName = (string)$r['customer_name'];
              $initial = function_exists('mb_substr') ? mb_substr($customerName, 0, 1, 'UTF-8') : substr($customerName, 0, 1);
            ?>
            <tr data-review-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
              <td><div class="review-product"><?php echo htmlspecialchars($r['product_name']); ?></div></td>
              <td>
                <div class="review-customer">
                  <span class="review-avatar"><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></span>
                  <span><?php echo htmlspecialchars($customerName); ?></span>
                </div>
              </td>
              <td>
                <div class="review-stars" aria-label="<?php echo $rating; ?> out of 5 stars">
                  <?php for ($i = 1; $i <= 5; $i++): ?>
                    <i class="bi bi-star<?php echo $i <= $rating ? '-fill' : ' empty'; ?>" aria-hidden="true"></i>
                  <?php endfor; ?>
                  <span class="review-rating-number"><?php echo $rating; ?>.0</span>
                </div>
              </td>
              <td><div class="review-comment"><?php echo htmlspecialchars($r['review_text']); ?></div></td>
              <td><span class="review-status <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"><?php echo ucfirst($status); ?></span></td>
              <td>
                <div class="review-actions">
                  <?php if ($status !== 'approved'): ?>
                    <a href="reviews.php?action=approve&amp;id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Approve</a>
                  <?php endif; ?>
                  <?php if ($status !== 'rejected'): ?>
                    <a href="reviews.php?action=reject&amp;id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-secondary">Reject</a>
                  <?php endif; ?>
                  <a href="reviews.php?action=delete&amp;id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-outline-danger" aria-label="Delete review" title="Delete review" onclick="return confirm('Delete review?')"><i class="bi bi-trash"></i></a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="reviews-no-results" id="reviewsNoResults">
        <i class="bi bi-search"></i>
        <strong class="d-block text-navy">No matching reviews</strong>
        <span class="small">Try another search or status filter.</span>
      </div>
      <?php if (empty($reviews)): ?>
        <div class="reviews-no-results" style="display:block">
          <i class="bi bi-chat-square-text"></i>
          <strong class="d-block text-navy">No customer reviews yet</strong>
          <span class="small">New product reviews will appear here for moderation.</span>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>

<script>
  (function () {
    var searchInput = document.getElementById('reviewSearch');
    var filterButtons = document.querySelectorAll('[data-review-filter]');
    var rows = document.querySelectorAll('#reviewsTableBody tr');
    var noResults = document.getElementById('reviewsNoResults');
    var activeFilter = 'all';

    function updateReviews() {
      var query = searchInput.value.trim().toLowerCase();
      var visibleCount = 0;
      rows.forEach(function (row) {
        var matchesStatus = activeFilter === 'all' || row.getAttribute('data-review-status') === activeFilter;
        var matchesSearch = row.textContent.toLowerCase().indexOf(query) !== -1;
        var visible = matchesStatus && matchesSearch;
        row.style.display = visible ? '' : 'none';
        if (visible) visibleCount++;
      });
      noResults.style.display = rows.length > 0 && visibleCount === 0 ? 'block' : 'none';
    }

    searchInput.addEventListener('input', updateReviews);
    filterButtons.forEach(function (button) {
      button.addEventListener('click', function () {
        activeFilter = button.getAttribute('data-review-filter');
        filterButtons.forEach(function (item) {
          var selected = item === button;
          item.classList.toggle('active', selected);
          item.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        updateReviews();
      });
    });
  })();
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
