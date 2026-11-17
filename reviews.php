<?php
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

// Active rating filter
$selected_star = isset($_GET['star']) ? (int)$_GET['star'] : 0;

// Fetch active products for the Write Review dropdown
$stmt_prods = $db->query("SELECT id, name FROM products WHERE active = 1 ORDER BY name ASC");
$all_products = $stmt_prods->fetchAll(PDO::FETCH_ASSOC);

// Fetch summary statistics
$stmt_stats = $db->query("
    SELECT 
        COUNT(*) as total_reviews,
        AVG(rating) as avg_rating,
        SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as star_5,
        SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as star_4,
        SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as star_3,
        SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as star_2,
        SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as star_1
    FROM reviews 
    WHERE status = 'approved'
");
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

$total_reviews = (int)($stats['total_reviews'] ?: 0);
$avg_rating = round((float)($stats['avg_rating'] ?: 0), 1);
$star_5 = (int)($stats['star_5'] ?: 0);
$star_4 = (int)($stats['star_4'] ?: 0);
$star_3 = (int)($stats['star_3'] ?: 0);
$star_2 = (int)($stats['star_2'] ?: 0);
$star_1 = (int)($stats['star_1'] ?: 0);

// Build query for approved reviews
$sql = "
    SELECT r.*, p.name as product_name, p.image as product_image 
    FROM reviews r 
    LEFT JOIN products p ON r.product_id = p.id 
    WHERE r.status = 'approved'
";
$params = [];

if ($selected_star >= 1 && $selected_star <= 5) {
    $sql .= " AND r.rating = ?";
    $params[] = $selected_star;
}

$sql .= " ORDER BY r.id DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Customer Reviews & Testimonials';
$page_subtitle = 'Read verified experiences and feedback from students, teachers, schools, and stationery buyers.';
$breadcrumbs = [
    ['title' => 'Home', 'url' => 'index.php'],
    ['title' => 'Customer Reviews', 'url' => '']
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section-padding py-4">
  <?php echo render_page_navigation('Customer Reviews & Testimonials', ['Reviews' => '']); ?>

  <section class="reviews-hero mb-4">
    <div class="reviews-hero-copy">
      <span class="reviews-eyebrow"><i class="bi bi-stars"></i> THE RAJPRIYA COMMUNITY</span>
      <h1><?php echo htmlspecialchars($page_title); ?></h1>
      <p><?php echo htmlspecialchars($page_subtitle); ?></p>
    </div>
    <button class="btn reviews-write-button" data-bs-toggle="modal" data-bs-target="#writeReviewModal">
      <i class="bi bi-pencil-square"></i>
      <span>Write a Review</span>
    </button>
    <span class="reviews-hero-mark" aria-hidden="true"><i class="bi bi-chat-quote-fill"></i></span>
  </section>

  <div class="reviews-summary row g-3 mb-4">
    <div class="col-lg-4">
      <section class="reviews-score-panel h-100">
        <div class="reviews-score-value"><?php echo number_format($avg_rating, 1); ?></div>
        <div class="reviews-stars" aria-label="<?php echo number_format($avg_rating, 1); ?> out of 5 stars">
          <?php
            $full_stars = (int)floor($avg_rating);
            $has_half = ($avg_rating - $full_stars) >= 0.5;
            for ($i = 1; $i <= 5; $i++) {
                if ($i <= $full_stars) {
                    echo '<i class="bi bi-star-fill"></i>';
                } elseif ($i === $full_stars + 1 && $has_half) {
                    echo '<i class="bi bi-star-half"></i>';
                } else {
                    echo '<i class="bi bi-star"></i>';
                }
            }
          ?>
        </div>
        <div class="reviews-score-label">Customer rating</div>
        <p class="reviews-score-caption mb-0">
          <?php echo number_format($total_reviews); ?> approved <?php echo $total_reviews === 1 ? 'review' : 'reviews'; ?>
        </p>
      </section>
    </div>
    <div class="col-lg-8">
      <section class="reviews-breakdown h-100">
        <div class="reviews-section-heading">
          <div>
            <span class="reviews-section-kicker">AT A GLANCE</span>
            <h2>Rating breakdown</h2>
          </div>
          <i class="bi bi-bar-chart-line" aria-hidden="true"></i>
        </div>
        <?php
          $stars_data = [
              5 => $star_5,
              4 => $star_4,
              3 => $star_3,
              2 => $star_2,
              1 => $star_1
          ];

          foreach ($stars_data as $sNum => $sCount):
              $pct = $total_reviews > 0 ? round(($sCount / $total_reviews) * 100) : 0;
        ?>
          <div class="reviews-rating-row">
            <span class="reviews-rating-label"><?php echo $sNum; ?> <i class="bi bi-star-fill"></i></span>
            <div class="progress reviews-progress" role="progressbar" aria-label="<?php echo $sNum; ?> star reviews" aria-valuenow="<?php echo $pct; ?>" aria-valuemin="0" aria-valuemax="100">
              <div class="progress-bar" style="width: <?php echo $pct; ?>%"></div>
            </div>
            <span class="reviews-rating-count"><?php echo number_format($sCount); ?> <span>(<?php echo $pct; ?>%)</span></span>
          </div>
        <?php endforeach; ?>
      </section>
    </div>
  </div>

  <nav class="reviews-filter mb-4" aria-label="Filter customer reviews">
    <span class="reviews-filter-label"><i class="bi bi-sliders"></i> Filter by rating</span>
    <div class="reviews-filter-options">
      <a href="reviews.php" class="reviews-filter-chip <?php echo $selected_star === 0 ? 'is-active' : ''; ?>" <?php echo $selected_star === 0 ? 'aria-current="page"' : ''; ?>>
        All <span><?php echo number_format($total_reviews); ?></span>
      </a>
      <?php for ($s = 5; $s >= 1; $s--): ?>
        <a href="reviews.php?star=<?php echo $s; ?>" class="reviews-filter-chip <?php echo $selected_star === $s ? 'is-active' : ''; ?>" <?php echo $selected_star === $s ? 'aria-current="page"' : ''; ?>>
          <?php echo $s; ?> <i class="bi bi-star-fill"></i> <span><?php echo number_format($stars_data[$s]); ?></span>
        </a>
      <?php endfor; ?>
    </div>
  </nav>

  <div class="reviews-list-heading">
    <div>
      <span class="reviews-section-kicker">REAL WORDS, REAL EXPERIENCES</span>
      <h2><?php echo $selected_star >= 1 && $selected_star <= 5 ? $selected_star . '-star reviews' : 'What customers are saying'; ?></h2>
    </div>
    <span class="reviews-list-count"><?php echo number_format(count($reviews)); ?> <?php echo count($reviews) === 1 ? 'review' : 'reviews'; ?></span>
  </div>

  <div class="reviews-list-wrap mb-5">
    <?php if (empty($reviews)): ?>
      <div class="reviews-empty-state">
        <span class="reviews-empty-icon"><i class="bi bi-chat-square-heart"></i></span>
        <h3>No reviews here just yet</h3>
        <p><?php echo $selected_star > 0 ? "There aren't any {$selected_star}-star reviews yet. Try another rating or share your experience." : 'Be the first to share your experience with Rajpriya Notebooks.'; ?></p>
        <button class="btn reviews-write-button" data-bs-toggle="modal" data-bs-target="#writeReviewModal">
          <i class="bi bi-pencil-square"></i> Write the first review
        </button>
      </div>
    <?php else: ?>
      <div class="row g-3">
        <?php foreach ($reviews as $rev): 
          $cName = !empty($rev['customer_name']) ? $rev['customer_name'] : 'Customer';
          $initial = strtoupper(substr($cName, 0, 1));
          $ratingVal = max(1, min(5, (int)$rev['rating']));
          $dateStr = !empty($rev['created_at']) ? format_date($rev['created_at']) : 'Recent';
        ?>
          <div class="col-md-6 d-flex">
            <article class="review-card w-100">
              <div class="review-card-top">
                <div class="review-customer">
                  <span class="review-avatar"><?php echo htmlspecialchars($initial); ?></span>
                  <div class="review-customer-info">
                    <h3><?php echo htmlspecialchars($cName); ?></h3>
                    <span class="review-date"><i class="bi bi-calendar3"></i> <?php echo htmlspecialchars($dateStr); ?></span>
                  </div>
                </div>
                <span class="review-rating-badge" aria-label="<?php echo $ratingVal; ?> out of 5 stars">
                  <?php echo $ratingVal; ?><i class="bi bi-star-fill"></i>
                </span>
              </div>
              <div class="review-card-stars" aria-hidden="true">
                <?php for ($i = 0; $i < $ratingVal; $i++): ?><i class="bi bi-star-fill"></i><?php endfor; ?>
              </div>
              <?php if (!empty($rev['product_name'])): ?>
                <a href="product-details.php?id=<?php echo (int)$rev['product_id']; ?>" class="review-product">
                  <i class="bi bi-journal-bookmark"></i>
                  <span><?php echo htmlspecialchars($rev['product_name']); ?></span>
                  <i class="bi bi-arrow-up-right"></i>
                </a>
              <?php endif; ?>
              <blockquote><?php echo htmlspecialchars($rev['review_text']); ?></blockquote>
              <div class="review-card-footer"><i class="bi bi-patch-check-fill"></i> Approved customer review</div>
            </article>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Write Review Modal -->
<div class="modal fade" id="writeReviewModal" tabindex="-1" aria-labelledby="writeReviewModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content reviews-modal">
      <div class="modal-header reviews-modal-header">
        <h5 class="modal-title fw-bold fs-6" id="writeReviewModalLabel">
          <i class="bi bi-pencil-square text-gold me-2"></i> Write a Customer Review
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form action="review-action.php" method="POST">
        <div class="modal-body p-4">
          <p class="text-secondary small mb-3">
            Share your experience writing in Rajpriya Notebooks. Your feedback helps other students and stationery buyers make informed choices.
          </p>

          <div class="mb-3">
            <label for="reviewProduct" class="form-label small fw-semibold text-navy">Select Notebook Product</label>
            <select id="reviewProduct" name="product_id" class="form-select" required>
              <option value="">-- Choose a Product --</option>
              <?php foreach ($all_products as $pItem): ?>
                <option value="<?php echo $pItem['id']; ?>"><?php echo htmlspecialchars($pItem['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label for="reviewCustomerName" class="form-label small fw-semibold text-navy">Your Name</label>
              <input id="reviewCustomerName" type="text" name="customer_name" class="form-control" required value="<?php echo is_customer_logged_in() ? htmlspecialchars(get_current_customer()['name']) : ''; ?>" placeholder="Enter your full name">
            </div>
            <div class="col-md-6">
              <label for="reviewRating" class="form-label small fw-semibold text-navy">Rating</label>
              <select id="reviewRating" name="rating" class="form-select" required>
                <option value="5" selected>5 Stars - Excellent</option>
                <option value="4">4 Stars - Very Good</option>
                <option value="3">3 Stars - Good</option>
                <option value="2">2 Stars - Fair</option>
                <option value="1">1 Star - Poor</option>
              </select>
            </div>
          </div>

          <div class="mb-3">
            <label for="reviewText" class="form-label small fw-semibold text-navy">Your Review Experience</label>
            <textarea id="reviewText" name="review_text" rows="4" class="form-control" placeholder="Tell us about the paper quality, ruling, smoothness, and binding..." required></textarea>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn reviews-write-button">Submit for approval</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
