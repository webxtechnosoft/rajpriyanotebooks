<?php
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

$stmt = $db->query("SELECT * FROM categories WHERE status = 1 ORDER BY id ASC");
$categories = $stmt->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<section class="home-hero" aria-label="Rajpriya notebooks featured collection">
  <div id="homeHeroCarousel" class="carousel slide carousel-fade" data-bs-ride="carousel" data-bs-interval="4500" data-bs-pause="false" data-bs-touch="true">
    <div class="home-hero-indicators carousel-indicators">
      <button type="button" data-bs-target="#homeHeroCarousel" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Slide 1"></button>
      <button type="button" data-bs-target="#homeHeroCarousel" data-bs-slide-to="1" aria-label="Slide 2"></button>
      <button type="button" data-bs-target="#homeHeroCarousel" data-bs-slide-to="2" aria-label="Slide 3"></button>
    </div>
    <div class="carousel-inner">
      <div class="carousel-item active">
        <div class="home-hero-slide home-hero-slide-1">
          <div class="home-hero-shade"></div>
          <div class="container home-hero-inner"><div class="home-hero-copy">
            <span class="home-eyebrow"><i class="bi bi-stars"></i> Made for every bright idea</span>
            <h1 id="home-hero-title">A good idea<br>deserves a <em>great page.</em></h1>
            <p>Thoughtfully made notebooks for school days, big plans and everything in between.</p>
            <div class="home-hero-actions"><a class="home-button home-button-gold" href="products.php">Explore notebooks <i class="bi bi-arrow-up-right"></i></a><a class="home-text-link" href="categories.php">Find your kind of notebook <i class="bi bi-arrow-right"></i></a></div>
            <div class="home-hero-note"><span class="home-note-stars">★★★★★</span><span>Made for learning, writing & creating</span></div>
          </div><div class="home-hero-side-note"><span>RAJPRIYA</span><i></i><span>Since every idea matters</span></div></div>
        </div>
      </div>
      <div class="carousel-item">
        <div class="home-hero-slide home-hero-slide-2">
          <div class="home-hero-shade"></div>
          <div class="container home-hero-inner"><div class="home-hero-copy">
            <span class="home-eyebrow"><i class="bi bi-award"></i> Smooth paper, happy writing</span>
            <h2 class="home-hero-title">Make every<br><em>word flow.</em></h2>
            <p>Enjoy a smoother writing experience with paper made for lessons, notes and everyday plans.</p>
            <div class="home-hero-actions"><a class="home-button home-button-gold" href="products.php">Find your favourite <i class="bi bi-arrow-up-right"></i></a><a class="home-text-link" href="about.php">Why Rajpriya <i class="bi bi-arrow-right"></i></a></div>
            <div class="home-hero-note"><span class="home-note-stars">★★★★★</span><span>Comfortable writing, page after page</span></div>
          </div><div class="home-hero-side-note"><span>RAJPRIYA</span><i></i><span>Paper for every plan</span></div></div>
        </div>
      </div>
      <div class="carousel-item">
        <div class="home-hero-slide home-hero-slide-3">
          <div class="home-hero-shade"></div>
          <div class="container home-hero-inner"><div class="home-hero-copy">
            <span class="home-eyebrow"><i class="bi bi-journal-richtext"></i> Find your kind of notebook</span>
            <h2 class="home-hero-title">Big plans.<br><em>Fresh pages.</em></h2>
            <p>Explore colourful covers, trusted everyday favourites and notebooks for every subject.</p>
            <div class="home-hero-actions"><a class="home-button home-button-gold" href="categories.php">Explore categories <i class="bi bi-arrow-up-right"></i></a><a class="home-text-link" href="products.php">Shop all notebooks <i class="bi bi-arrow-right"></i></a></div>
            <div class="home-hero-note"><span class="home-note-stars">★★★★★</span><span>Made for learning, writing & creating</span></div>
          </div><div class="home-hero-side-note"><span>RAJPRIYA</span><i></i><span>Since every idea matters</span></div></div>
        </div>
      </div>
    </div>
    <button class="home-hero-control home-hero-prev" type="button" data-bs-target="#homeHeroCarousel" data-bs-slide="prev" aria-label="Previous slide"><i class="bi bi-arrow-left"></i></button>
    <button class="home-hero-control home-hero-next" type="button" data-bs-target="#homeHeroCarousel" data-bs-slide="next" aria-label="Next slide"><i class="bi bi-arrow-right"></i></button>
    <a href="#home-categories" class="home-scroll-cue" aria-label="Scroll to notebook categories"><i class="bi bi-arrow-down"></i></a>
  </div>
</section>

<!-- ==========================================================================
     SECTION 1 — SHOP BY CATEGORY ("Explore Our Categories")
     ========================================================================== -->
<section class="home-section home-categories" id="home-categories">
  <div class="container">
    <div class="home-section-header">
      <div class="home-section-header-left">
        <span class="home-section-kicker"><i class="bi bi-grid-3x3-gap-fill me-1"></i> Stationery Range</span>
        <h2 class="home-section-title">Explore Our Categories</h2>
        <p class="home-section-desc">Discover notebooks and stationery collections designed for different everyday needs.</p>
      </div>
      <div class="home-section-header-right">
        <a class="home-section-link" href="categories.php">View All Categories <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>

    <div class="row g-3 g-md-4 home-category-tiles">
      <?php foreach ($categories as $index => $cat): ?>
        <div class="col-6 col-md-4 col-xl-3 d-flex">
          <a href="products.php?category=<?php echo urlencode($cat['slug']); ?>" class="home-category-tile w-100">
            <div class="home-tile-image-box">
              <img src="<?php echo htmlspecialchars(get_category_image_url($cat['image'], $cat['slug'] ?? '')); ?>" alt="<?php echo htmlspecialchars($cat['name']); ?>" loading="lazy" class="home-tile-img">
              <span class="home-tile-number"><?php echo str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
            </div>
            <div class="home-tile-content">
              <h3 class="home-tile-title"><?php echo htmlspecialchars($cat['name']); ?></h3>
              <span class="home-tile-action">Explore <i class="bi bi-arrow-right ms-1"></i></span>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ==========================================================================
     SECTION 2 — COLLECTIONS / PRODUCT FAMILIES
     ========================================================================== -->
<section class="home-section home-collections" id="home-collections">
  <div class="container">
    <div class="home-section-header">
      <div class="home-section-header-left">
        <span class="home-section-kicker"><i class="bi bi-journals me-1"></i> Core Product Lines</span>
        <h2 class="home-section-title">Featured Notebook Families</h2>
        <p class="home-section-desc">Explore our signature wholesale product series designed for schools, universities, offices, and commercial establishments.</p>
      </div>
      <div class="home-section-header-right">
        <a class="home-section-link" href="products.php">Full Catalogue <i class="bi bi-arrow-right"></i></a>
      </div>
    </div>

    <div class="row g-4 home-collections-grid">
      <!-- Collection 1: Long Exercise Books -->
      <div class="col-12 col-lg-6 d-flex">
        <div class="home-collection-card w-100">
          <div class="home-collection-media">
            <img src="assets/images/categories/cat_long_laminated.webp" alt="Long Exercise Books" loading="lazy">
            <span class="home-collection-tag">School &amp; College</span>
          </div>
          <div class="home-collection-body">
            <span class="home-collection-meta">70–80 GSM Mill Paper • Precision Rulings</span>
            <h3 class="home-collection-title">Long Exercise Books</h3>
            <p class="home-collection-desc">High-demand student exercise books crafted with premium smooth paper, vibrant laminated covers, and sturdy center binding for everyday lessons.</p>
            <a href="products.php?category=long-exercise-book-laminated" class="home-collection-btn">Explore Collection <i class="bi bi-arrow-right ms-1"></i></a>
          </div>
        </div>
      </div>

      <!-- Collection 2: Hard Bind Notebooks -->
      <div class="col-12 col-lg-6 d-flex">
        <div class="home-collection-card w-100">
          <div class="home-collection-media">
            <img src="assets/images/categories/cat_long_hardbind.webp" alt="Hard Bind Notebooks" loading="lazy">
            <span class="home-collection-tag">Heavy-Duty Binding</span>
          </div>
          <div class="home-collection-body">
            <span class="home-collection-meta">Calico Binding • 120–400 Pages</span>
            <h3 class="home-collection-title">Hard Bind Notebooks</h3>
            <p class="home-collection-desc">Engineered for university coursework, administrative logging, and long-term writing durability with reinforced board covers and durable binding.</p>
            <a href="products.php?category=long-exercise-book-hard-bind" class="home-collection-btn">Explore Collection <i class="bi bi-arrow-right ms-1"></i></a>
          </div>
        </div>
      </div>

      <!-- Collection 3: Registers & Practical Records -->
      <div class="col-12 col-lg-6 d-flex">
        <div class="home-collection-card w-100">
          <div class="home-collection-media">
            <img src="assets/images/categories/cat_registers.webp" alt="Registers and Records" loading="lazy">
            <span class="home-collection-tag">Institutions &amp; Labs</span>
          </div>
          <div class="home-collection-body">
            <span class="home-collection-meta">Attendance • Practical Records • Graph Papers</span>
            <h3 class="home-collection-title">Registers &amp; Records</h3>
            <p class="home-collection-desc">Standardized student and staff attendance registers, scientific practical record books, and specialized graph layouts built to institutional standards.</p>
            <a href="products.php?category=registers" class="home-collection-btn">Explore Collection <i class="bi bi-arrow-right ms-1"></i></a>
          </div>
        </div>
      </div>

      <!-- Collection 4: Ledgers & Accounts Books -->
      <div class="col-12 col-lg-6 d-flex">
        <div class="home-collection-card w-100">
          <div class="home-collection-media">
            <img src="assets/images/categories/cat_ledgers_accounts.webp" alt="Ledgers and Accounts" loading="lazy">
            <span class="home-collection-tag">Commercial &amp; Trade</span>
          </div>
          <div class="home-collection-body">
            <span class="home-collection-meta">Accountancy Rulings • Cash Memos • Day Books</span>
            <h3 class="home-collection-title">Ledgers &amp; Accounts</h3>
            <p class="home-collection-desc">Comprehensive commercial stationery featuring multi-column money rulings, bill books, and accounts ledgers tailored for retail shopkeepers and trade businesses.</p>
            <a href="products.php?category=ledgers-accounts" class="home-collection-btn">Explore Collection <i class="bi bi-arrow-right ms-1"></i></a>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ==========================================================================
     SECTION 3 — WHY RAJPRIYA ("Why Choose Rajpriya?")
     ========================================================================== -->
<section class="home-section home-why-rajpriya" id="home-why-rajpriya">
  <div class="container">
    <div class="home-section-header text-center mx-auto" style="max-width: 720px;">
      <span class="home-section-kicker justify-content-center"><i class="bi bi-patch-check-fill me-1"></i> Manufacturing Excellence</span>
      <h2 class="home-section-title">Why Choose Rajpriya?</h2>
      <p class="home-section-desc mx-auto">Built on consistent paper standards, dependable manufacturing capacity, and long-standing trust with retail partners.</p>
    </div>

    <div class="row g-3 g-lg-4 home-why-grid">
      <div class="col-12 col-sm-6 col-lg-3 d-flex">
        <div class="home-why-card w-100">
          <span class="home-why-num">01</span>
          <div class="home-why-icon"><i class="bi bi-collection-fill"></i></div>
          <h3 class="home-why-title">Wide Product Range</h3>
          <p class="home-why-desc">Full spectrum coverage including student exercise books, university registers, practical records, and commercial ledgers.</p>
        </div>
      </div>

      <div class="col-12 col-sm-6 col-lg-3 d-flex">
        <div class="home-why-card w-100">
          <span class="home-why-num">02</span>
          <div class="home-why-icon"><i class="bi bi-percent"></i></div>
          <h3 class="home-why-title">Wholesale-Friendly Pricing</h3>
          <p class="home-why-desc">Direct factory pricing with transparent bundle rates, low minimums, and healthy profit margins for stationery retailers.</p>
        </div>
      </div>

      <div class="col-12 col-sm-6 col-lg-3 d-flex">
        <div class="home-why-card w-100">
          <span class="home-why-num">03</span>
          <div class="home-why-icon"><i class="bi bi-layers-fill"></i></div>
          <h3 class="home-why-title">Multiple Product Varieties</h3>
          <p class="home-why-desc">Comprehensive variety options from 50 to 400 pages, laminated and calico bindings, and specialized rulings.</p>
        </div>
      </div>

      <div class="col-12 col-sm-6 col-lg-3 d-flex">
        <div class="home-why-card w-100">
          <span class="home-why-num">04</span>
          <div class="home-why-icon"><i class="bi bi-truck-front-fill"></i></div>
          <h3 class="home-why-title">Easy Bulk Ordering</h3>
          <p class="home-why-desc">Real-time bundle stock visibility, standardized master pack packaging, and reliable shipping dispatch support.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ==========================================================================
     SECTION 4 — HOW WHOLESALE ORDERING WORKS ("Simple Wholesale Ordering")
     ========================================================================== -->
<section class="home-section home-ordering-process" id="home-ordering-guide">
  <div class="container">
    <div class="home-section-header text-center mx-auto" style="max-width: 720px;">
      <span class="home-section-kicker justify-content-center"><i class="bi bi-diagram-3-fill me-1"></i> Streamlined Procurement</span>
      <h2 class="home-section-title">Simple Wholesale Ordering</h2>
      <p class="home-section-desc mx-auto">A straightforward four-step process designed for stationery merchants, institutional suppliers, and wholesale buyers.</p>
    </div>

    <div class="home-process-horizontal">
      <div class="row g-3 g-lg-4 home-process-grid">
        <!-- Step 1: Browse -->
        <div class="col-12 col-sm-6 col-lg-3 d-flex">
          <div class="home-process-card w-100">
            <div class="home-process-header">
              <span class="home-process-step">01</span>
              <div class="home-process-icon"><i class="bi bi-journal-text"></i></div>
            </div>
            <h3 class="home-process-title">Browse</h3>
            <span class="home-process-subtitle">Explore the available catalogue.</span>
            <p class="home-process-desc">Review our comprehensive range of exercise books, registers, accounts books, and writing supplies.</p>
          </div>
        </div>

        <!-- Step 2: Select -->
        <div class="col-12 col-sm-6 col-lg-3 d-flex">
          <div class="home-process-card w-100">
            <div class="home-process-header">
              <span class="home-process-step">02</span>
              <div class="home-process-icon"><i class="bi bi-sliders2-vertical"></i></div>
            </div>
            <h3 class="home-process-title">Select</h3>
            <span class="home-process-subtitle">Choose the required product and variety.</span>
            <p class="home-process-desc">Specify page counts, rulings (Ruled, Unruled, Accountancy), and cover binding styles for your market.</p>
          </div>
        </div>

        <!-- Step 3: Quantity -->
        <div class="col-12 col-sm-6 col-lg-3 d-flex">
          <div class="home-process-card w-100">
            <div class="home-process-header">
              <span class="home-process-step">03</span>
              <div class="home-process-icon"><i class="bi bi-boxes"></i></div>
            </div>
            <h3 class="home-process-title">Quantity</h3>
            <span class="home-process-subtitle">Select the required quantity.</span>
            <p class="home-process-desc">Order in factory-sealed bundles (10–20 pcs/bundle) or master carton counts according to your business needs.</p>
          </div>
        </div>

        <!-- Step 4: Order -->
        <div class="col-12 col-sm-6 col-lg-3 d-flex">
          <div class="home-process-card w-100">
            <div class="home-process-header">
              <span class="home-process-step">04</span>
              <div class="home-process-icon"><i class="bi bi-cart-check-fill"></i></div>
            </div>
            <h3 class="home-process-title">Order</h3>
            <span class="home-process-subtitle">Add to cart and place your order.</span>
            <p class="home-process-desc">Submit your wholesale order effortlessly with verified commercial billing and rapid logistics dispatch.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ==========================================================================
     SECTION 5 — BUSINESS / BRAND STORY ("Built Around Better Notebook Solutions")
     ========================================================================== -->
<section class="home-section home-brand-story" id="home-story">
  <div class="container">
    <div class="row align-items-center g-4 g-lg-5">
      <!-- Left: Brand / Company Content -->
      <div class="col-12 col-lg-6">
        <div class="home-story-content">
          <span class="home-section-kicker"><i class="bi bi-award-fill me-1"></i> Quality is Our Motto</span>
          <h2 class="home-section-title mb-3">Built Around Better Notebook Solutions</h2>
          <p class="home-story-lead">
            Established in <strong>1994</strong> by <strong>PRABU TRADER, Salem</strong>, <strong>RAJPRIYA NOTEBOOKS</strong> has been a trusted stationery manufacturing brand delivering high-quality notebooks across India for over 25 years.
          </p>
          <p class="home-story-text">
            Crafted from reputed mill papers, our comprehensive portfolio includes Long Exercise Books, Student Notebooks, Hard Bind Records, Practical Journals, Registers, and Accounts Ledgers. Every product is engineered with precision ruling, reinforced binding, and smooth ink-resistant paper.
          </p>
          
          <div class="home-story-pillars row g-3 mt-2 mb-4">
            <div class="col-sm-4">
              <div class="home-pillar-item">
                <i class="bi bi-file-earmark-check text-gold"></i>
                <strong>100% Mill Paper</strong>
                <span>Smooth ink-bleed control</span>
              </div>
            </div>
            <div class="col-sm-4">
              <div class="home-pillar-item">
                <i class="bi bi-rulers text-gold"></i>
                <strong>Precision Ruling</strong>
                <span>Neat, consistent lines</span>
              </div>
            </div>
            <div class="col-sm-4">
              <div class="home-pillar-item">
                <i class="bi bi-journal-check text-gold"></i>
                <strong>Sturdy Binding</strong>
                <span>Built for daily handling</span>
              </div>
            </div>
          </div>

          <div class="home-story-cta">
            <a href="about.php" class="home-btn-outline">Read Our Full Story <i class="bi bi-arrow-right ms-1"></i></a>
          </div>
        </div>
      </div>

      <!-- Right: Relevant Image Card -->
      <div class="col-12 col-lg-6">
        <div class="home-story-visual">
          <div class="home-story-frame">
            <img src="assets/images/banner_open_notebook.webp" alt="Rajpriya Notebook Craftsmanship" loading="lazy" class="home-story-img">
            <div class="home-story-badge">
              <span class="home-badge-title">RAJPRIYA</span>
              <span class="home-badge-sub">Since 1994 • Salem, India</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ==========================================================================
     SECTION 6 — WHOLESALE CTA
     ========================================================================== -->
<section class="home-section home-wholesale-cta" id="home-wholesale-cta">
  <div class="container">
    <div class="home-wholesale-cta-box">
      <div class="home-wholesale-cta-content text-center mx-auto">
        <span class="home-cta-kicker"><i class="bi bi-buildings-fill me-1"></i> Partner with Rajpriya</span>
        <h2 class="home-cta-title">Looking for the Right Products for Your Business?</h2>
        <p class="home-cta-desc">Explore the complete Rajpriya Notebook catalogue and find the products and varieties that match your requirements.</p>
        <div class="home-cta-actions d-flex align-items-center justify-content-center flex-wrap gap-3">
          <a class="home-btn-primary home-btn-lg" href="products.php">
            <i class="bi bi-journal-bookmark-fill me-2"></i>Explore Catalogue <i class="bi bi-arrow-right ms-1"></i>
          </a>
          <a class="home-btn-outline-light home-btn-lg" href="contact.php">
            <i class="bi bi-telephone-fill me-2"></i>Contact Us
          </a>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ==========================================================================
     SECTION 7 — CATALOGUE CTA (Minimal final catalogue section before footer)
     ========================================================================== -->
<section class="home-section home-final-catalogue-cta">
  <div class="container">
    <div class="home-final-box text-center mx-auto" style="max-width: 680px;">
      <h2 class="home-section-title mb-2">Explore the Complete Catalogue</h2>
      <p class="home-section-desc mx-auto mb-4">Browse our complete range of notebook and stationery products.</p>
      <a href="products.php" class="home-btn-primary">
        View All Products <i class="bi bi-arrow-right ms-1"></i>
      </a>
    </div>
  </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
