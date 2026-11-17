<?php
require_once __DIR__ . '/includes/functions.php';

$db = getDB();
$stmt = $db->query("SELECT * FROM categories WHERE status = 1 ORDER BY name ASC");
$categories = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<style>
  .category-page {
    padding-top: 2rem;
    padding-bottom: 4rem;
  }
  .category-hero {
    position: relative;
    overflow: hidden;
    padding: clamp(1.6rem, 4vw, 2.8rem);
    border: 1px solid rgba(200, 155, 60, 0.3);
    border-radius: 1.5rem;
    background: linear-gradient(120deg, #17243A 0%, #243552 70%, #344463 100%);
    box-shadow: 0 16px 36px rgba(23, 36, 58, 0.13);
    color: #fff;
  }
  .category-hero::after {
    position: absolute;
    top: -110px;
    right: -55px;
    width: 290px;
    height: 290px;
    border: 1px solid rgba(232, 199, 106, 0.22);
    border-radius: 50%;
    box-shadow: 0 0 0 28px rgba(232, 199, 106, 0.05), 0 0 0 58px rgba(232, 199, 106, 0.035);
    content: "";
    pointer-events: none;
  }
  .category-hero-content {
    position: relative;
    z-index: 1;
  }
  .category-eyebrow {
    color: #E8C76A;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.16em;
  }
  .category-hero h1 {
    margin: 0.45rem 0 0.5rem;
    color: #fff;
    font-family: var(--font-heading, Georgia, serif);
    font-size: clamp(1.85rem, 4vw, 2.7rem);
  }
  .category-hero p {
    max-width: 570px;
    margin: 0;
    color: rgba(255, 255, 255, 0.76);
    font-size: 0.92rem;
  }
  .category-count {
    min-width: 135px;
    padding: 1rem 1.25rem;
    border: 1px solid rgba(232, 199, 106, 0.3);
    border-radius: 1rem;
    background: rgba(255, 255, 255, 0.08);
    text-align: center;
    backdrop-filter: blur(6px);
  }
  .category-count strong {
    display: block;
    color: #E8C76A;
    font-size: 1.65rem;
    line-height: 1.1;
  }
  .category-count span {
    display: block;
    margin-top: 0.3rem;
    color: rgba(255, 255, 255, 0.78);
    font-size: 0.72rem;
  }
  .category-section-heading {
    margin: 2rem 0 1rem;
  }
  .category-section-heading h2 {
    margin: 0;
    color: #17243A;
    font-size: 1.1rem;
    font-weight: 700;
  }
  .category-section-heading p {
    margin: 0.25rem 0 0;
    color: #6F6A63;
    font-size: 0.82rem;
  }
  .category-tile {
    display: flex;
    height: 100%;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid #EAE2D3;
    border-radius: 1.1rem;
    background: #fff;
    box-shadow: 0 6px 20px rgba(23, 36, 58, 0.045);
    color: inherit;
    text-decoration: none;
    transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease;
  }
  .category-tile:hover {
    transform: translateY(-5px);
    border-color: rgba(200, 155, 60, 0.65);
    box-shadow: 0 16px 30px rgba(23, 36, 58, 0.11);
    color: inherit;
  }
  .category-tile-image {
    position: relative;
    height: 185px;
    overflow: hidden;
    background: linear-gradient(145deg, #F8F3E9, #ECE5D6);
  }
  .category-tile-image::after {
    position: absolute;
    inset: 45% 0 0;
    background: linear-gradient(transparent, rgba(23, 36, 58, 0.12));
    content: "";
    pointer-events: none;
  }
  .category-tile-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.45s ease;
  }
  .category-tile:hover .category-tile-image img {
    transform: scale(1.045);
  }
  .category-tile-number {
    position: absolute;
    top: 0.85rem;
    left: 0.85rem;
    z-index: 1;
    padding: 0.35rem 0.6rem;
    border: 1px solid rgba(255, 255, 255, 0.65);
    border-radius: 999px;
    background: rgba(23, 36, 58, 0.72);
    color: #fff;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    backdrop-filter: blur(5px);
  }
  .category-tile-body {
    display: flex;
    flex: 1;
    flex-direction: column;
    padding: 1.1rem 1.15rem 1.2rem;
  }
  .category-tile-title {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.6rem;
    margin: 0 0 0.5rem;
    color: #17243A;
    font-size: 1.02rem;
    font-weight: 700;
    line-height: 1.35;
  }
  .category-tile-arrow {
    display: inline-flex;
    width: 30px;
    height: 30px;
    flex: 0 0 30px;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #FCF8EF;
    color: #A98232;
    transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
  }
  .category-tile:hover .category-tile-arrow {
    transform: translateX(2px);
    background: #C89B3C;
    color: #17243A;
  }
  .category-tile-description {
    display: -webkit-box;
    overflow: hidden;
    margin: 0 0 1rem;
    color: #6F6A63;
    font-size: 0.82rem;
    line-height: 1.65;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 3;
  }
  .category-tile-link {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin-top: auto;
    color: #A98232;
    font-size: 0.78rem;
    font-weight: 700;
  }
  .category-empty-state {
    padding: 3rem 1rem;
    border: 1px dashed #DCCDA9;
    border-radius: 1rem;
    background: #fff;
    text-align: center;
  }
  @media (max-width: 575.98px) {
    .category-page {
      padding-top: 1.25rem;
      padding-bottom: 2.5rem;
    }
    .category-hero {
      border-radius: 1.1rem;
    }
    .category-count {
      min-width: 105px;
      padding: 0.75rem;
    }
    .category-tile-image {
      height: 175px;
    }
  }
</style>

<div class="container category-page">
  <section class="category-hero">
    <div class="category-hero-content d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
      <div>
        <div class="category-eyebrow"><i class="bi bi-stars me-1"></i> EXPLORE RAJPRIYA</div>
        <h1>Shop by Category</h1>
        <p>From everyday notes to special projects, find the right notebook collection for every idea.</p>
      </div>
      <div class="category-count">
        <strong><?php echo number_format(count($categories)); ?></strong>
        <span>Notebook categories</span>
      </div>
    </div>
  </section>

  <div class="category-section-heading">
    <h2>Browse our collections</h2>
    <p>Choose a category to explore the notebooks available.</p>
  </div>

  <?php if (empty($categories)): ?>
    <div class="category-empty-state">
      <i class="bi bi-journal-x fs-1 text-gold d-block mb-3"></i>
      <h2 class="h5 fw-bold text-navy mb-2">No categories available</h2>
      <p class="text-secondary mb-0">Please check back soon for our notebook collections.</p>
    </div>
  <?php else: ?>
    <div class="row g-3 g-lg-4 row-cols-1 row-cols-sm-2 row-cols-lg-3">
      <?php foreach ($categories as $index => $cat): ?>
        <div class="col">
          <a href="products.php?category=<?php echo rawurlencode($cat['slug']); ?>" class="category-tile">
            <div class="category-tile-image">
              <img src="<?php echo htmlspecialchars(get_category_image_url($cat['image'], $cat['slug'] ?? '')); ?>" alt="<?php echo htmlspecialchars($cat['name']); ?>" loading="lazy">
              <span class="category-tile-number"><?php echo str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
            </div>
            <div class="category-tile-body">
              <h3 class="category-tile-title">
                <span><?php echo htmlspecialchars($cat['name']); ?></span>
                <span class="category-tile-arrow" aria-hidden="true"><i class="bi bi-arrow-up-right"></i></span>
              </h3>
              <p class="category-tile-description"><?php echo htmlspecialchars($cat['description'] ?? 'Explore notebooks in this collection.'); ?></p>
              <span class="category-tile-link">Explore collection <i class="bi bi-arrow-right"></i></span>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
