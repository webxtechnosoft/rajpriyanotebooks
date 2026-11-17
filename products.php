<?php
require_once __DIR__ . '/includes/functions.php';

$db = getDB();

// Get filter parameters
$category_slug = isset($_GET['category']) ? sanitize($_GET['category']) : '';
$selected_categories = isset($_GET['cat']) ? (array)$_GET['cat'] : ($category_slug !== '' ? [$category_slug] : []);
$selected_brands = isset($_GET['brand']) ? (array)$_GET['brand'] : [];
$selected_rulings = isset($_GET['ruling']) ? (array)$_GET['ruling'] : [];
$selected_sizes = isset($_GET['size']) ? (array)$_GET['size'] : [];
$selected_pages = isset($_GET['pages']) ? (array)$_GET['pages'] : [];
$selected_qualities = isset($_GET['quality']) ? (array)$_GET['quality'] : [];
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';
$sort = isset($_GET['sort']) ? sanitize($_GET['sort']) : 'latest';

// Fetch All Categories from DB
$stmt = $db->query("SELECT * FROM categories WHERE status = 1 ORDER BY name ASC");
$all_categories = $stmt->fetchAll();

// Fetch Base Products Query
$sql = "SELECT p.*, c.name as category_name, c.slug as category_slug 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.active = 1";
$params = [];


if ($sort === 'price_low') {
    $sql .= " ORDER BY COALESCE(p.sale_price, p.price) ASC";
} elseif ($sort === 'price_high') {
    $sql .= " ORDER BY COALESCE(p.sale_price, p.price) DESC";
} elseif ($sort === 'name') {
    $sql .= " ORDER BY p.name ASC";
} else {
    $sql .= " ORDER BY p.id DESC";
}

$stmt = $db->prepare($sql);
$stmt->execute($params);
$raw_products = $stmt->fetchAll();

// Perform In-Memory Filtering for Multi-attribute Catalogue Filters
$filtered_raw_products = array_filter($raw_products, function($p) use ($selected_categories, $selected_brands, $selected_rulings, $selected_sizes, $selected_pages, $selected_qualities, $search) {
    $matches = true;

    // 1. Category Filter
    if (!empty($selected_categories)) {
        $cMatch = false;
        foreach ($selected_categories as $sc) {
            if ($p['category_slug'] === $sc || stripos($p['category_name'], $sc) !== false) {
                $cMatch = true;
                break;
            }
        }
        if (!$cMatch) $matches = false;
    }

    // 2. Brand / Product Filter
    if ($matches && !empty($selected_brands)) {
        $bMatch = false;
        foreach ($selected_brands as $sb) {
            if (stripos($p['name'], $sb) !== false || (!empty($p['brand_name']) && stripos($p['brand_name'], $sb) !== false)) {
                $bMatch = true;
                break;
            }
        }
        if (!$bMatch) $matches = false;
    }

    // 3. Ruling / Variety Filter
    if ($matches && !empty($selected_rulings)) {
        $rMatch = false;
        $fullDesc = $p['description'] . ' ' . ($p['ruling_option'] ?? '') . ' ' . $p['name'];
        foreach ($selected_rulings as $sr) {
            $srClean = trim($sr);
            if (stripos($fullDesc, $srClean) !== false) {
                $rMatch = true;
                break;
            }
            if (strcasecmp($srClean, 'Maths Plain') === 0) {
                if (stripos($fullDesc, 'Maths Plain') !== false || stripos($fullDesc, 'Micro Point') !== false || stripos($fullDesc, 'MP') !== false) {
                    $rMatch = true;
                    break;
                }
            }
        }
        if (!$rMatch) $matches = false;
    }

    // 4. Size Filter
    if ($matches && !empty($selected_sizes)) {
        $sMatch = false;
        $fullSize = $p['size'] . ' ' . $p['description'];
        foreach ($selected_sizes as $ss) {
            if (stripos($fullSize, $ss) !== false) {
                $sMatch = true;
                break;
            }
        }
        if (!$sMatch) $matches = false;
    }

    // 5. Pages Filter
    if ($matches && !empty($selected_pages)) {
        $pMatch = false;
        $fullPages = $p['pages'] . ' ' . $p['description'] . ' ' . $p['name'];
        foreach ($selected_pages as $sp) {
            if (preg_match('/\b' . preg_quote($sp, '/') . '\b/i', $fullPages) || (int)$p['pages'] === (int)$sp) {
                $pMatch = true;
                break;
            }
        }
        if (!$pMatch) $matches = false;
    }

    // 6. Quality Filter
    if ($matches && !empty($selected_qualities)) {
        $qMatch = false;
        $fullQuality = ($p['paper_type'] ?? '') . ' ' . $p['description'];
        foreach ($selected_qualities as $sq) {
            if (stripos($fullQuality, $sq) !== false) {
                $qMatch = true;
                break;
            }
        }
        if (!$qMatch) $matches = false;
    }

    // 7. Search Filter
    if ($matches && !empty($search)) {
        $fullText = $p['name'] . ' ' . $p['description'] . ' ' . $p['sku'] . ' ' . ($p['paper_type'] ?? '') . ' ' . $p['size'];
        if (stripos($fullText, $search) === false) {
            $matches = false;
        }
    }

    return $matches;
});

// Group products by base product for catalogue display
$grouped_products = group_products_by_base(array_values($filtered_raw_products));

// Default display ordering: Student Exercise Book products FIRST on un-filtered view
if (empty($selected_categories) && empty($selected_brands) && empty($selected_rulings) && empty($selected_sizes) && empty($selected_pages) && empty($selected_qualities) && empty($search)) {
    usort($grouped_products, function($a, $b) {
        $catA = isset($a['variants'][0]['category_name']) ? $a['variants'][0]['category_name'] : '';
        $catB = isset($b['variants'][0]['category_name']) ? $b['variants'][0]['category_name'] : '';
        
        $isStudentA = (stripos($catA, 'Student Exercise') !== false) ? 0 : 1;
        $isStudentB = (stripos($catB, 'Student Exercise') !== false) ? 0 : 1;
        
        if ($isStudentA !== $isStudentB) {
            return $isStudentA <=> $isStudentB;
        }
        return 0;
    });
}

// Active Category Object
$active_category = null;
if (!empty($selected_categories)) {
    foreach ($all_categories as $cat) {
        if ($cat['slug'] === $selected_categories[0] || $cat['name'] === $selected_categories[0]) {
            $active_category = $cat;
            break;
        }
    }
}

// Page Titles & Breadcrumbs
$page_title = 'Product Catalogue & Notebooks';
$page_subtitle = 'Explore our complete PDF catalogue of Rajpriya notebooks, exercise books & wholesale stationery.';
$breadcrumbs = [['title' => 'Home', 'url' => 'index.php']];

if ($active_category) {
    $page_title = $active_category['name'];
    $page_subtitle = !empty($active_category['description']) ? $active_category['description'] : 'Explore our collection of ' . $active_category['name'] . '.';
    $breadcrumbs[] = ['title' => 'Products', 'url' => 'products.php'];
    $breadcrumbs[] = ['title' => $active_category['name'], 'url' => ''];
} elseif ($search) {
    $page_title = 'Search Results';
    $page_subtitle = 'Showing products matching "' . htmlspecialchars($search) . '"';
    $breadcrumbs[] = ['title' => 'Products', 'url' => 'products.php'];
    $breadcrumbs[] = ['title' => 'Search: ' . htmlspecialchars($search), 'url' => ''];
} else {
    $breadcrumbs[] = ['title' => 'Product Catalogue', 'url' => ''];
}

// Dynamic Brand List from Database
$stmt_b = $db->query("SELECT DISTINCT brand_name FROM products WHERE active = 1 AND brand_name IS NOT NULL AND brand_name != '' ORDER BY brand_name ASC");
$pdf_brands = $stmt_b->fetchAll(PDO::FETCH_COLUMN);

$view_mode = isset($_GET['view']) && $_GET['view'] === 'brand' ? 'brand' : 'grid';

$pdf_rulings = ['Ruled', 'Unruled', 'Maths Plain', 'Maths Ruled', 'Double Line', 'Four Line', 'Square', 'Accountancy', 'Graph', 'Chemistry', 'OSR', 'Five Subject', '1+1 Printed Bill Book'];

$pdf_sizes = ['Long Size', 'Crown Size', 'Student Size', 'A4 College Size', 'Demmy Size', 'FC', '1/8', '1/12', '1/16', '1/32'];

$pdf_pages = ['28', '36', '60', '72', '80', '92', '120', '124', '132', '136', '144', '148', '160', '192', '232', '240', '288', '320', '384', '480', '500'];

$pdf_qualities = ['A Grade', 'A Grade - Maplitho', 'B Grade (Recycled)', 'Semi-unbleached', 'Ledger Paper - A Grade', 'Smart', 'SPB'];

$has_active_filters = !empty($selected_categories) || !empty($selected_brands) || !empty($selected_rulings) || !empty($selected_sizes) || !empty($selected_pages) || !empty($selected_qualities) || !empty($search);

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section-padding py-4">
  <?php echo render_page_navigation($page_title, ['Notebook Catalogue' => '']); ?>

  <!-- Products Page Top Header Banner -->
  <div class="products-header-banner mb-3 p-3.5 p-md-4 rounded-3 text-white" style="background: linear-gradient(135deg, #17243A 0%, #243552 100%);">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
      <div>
        <span class="badge bg-gold text-navy fw-bold text-uppercase px-2.5 py-1 mb-1.5" style="font-size: 0.725rem; letter-spacing: 1px;">
          <i class="bi bi-patch-check-fill me-1"></i> Wholesale Notebook Catalogue
        </span>
        <h1 class="products-page-title fw-bold text-white mb-1 fs-3">
          <?php echo htmlspecialchars($page_title); ?>
        </h1>
        <p class="mb-0 text-light opacity-90 small"><?php echo htmlspecialchars($page_subtitle); ?></p>
      </div>
      <div class="text-md-end">
        <span class="badge bg-navy-subtle text-white border border-secondary px-3 py-2 fw-semibold fs-6">
          <i class="bi bi-journal-text text-gold me-1.5"></i><?php echo count($grouped_products); ?> Base Products
        </span>
      </div>
    </div>
  </div>

  <!-- SECTION 1, 2, 3 & 5: COMPACT TOP CONTROL BAR (Search + Category Dropdown + Filters Button + Search Button) -->
  <div class="products-control-bar bg-white p-3 rounded-3 border shadow-sm mb-4">
    <form action="products.php" method="GET" class="row g-2.5 align-items-center">
      <?php foreach ($selected_brands as $b): ?><input type="hidden" name="brand[]" value="<?php echo htmlspecialchars($b); ?>"><?php endforeach; ?>
      <?php foreach ($selected_pages as $p): ?><input type="hidden" name="pages[]" value="<?php echo htmlspecialchars($p); ?>"><?php endforeach; ?>
      <?php foreach ($selected_rulings as $r): ?><input type="hidden" name="ruling[]" value="<?php echo htmlspecialchars($r); ?>"><?php endforeach; ?>
      <?php foreach ($selected_sizes as $sz): ?><input type="hidden" name="size[]" value="<?php echo htmlspecialchars($sz); ?>"><?php endforeach; ?>
      <?php foreach ($selected_qualities as $q): ?><input type="hidden" name="quality[]" value="<?php echo htmlspecialchars($q); ?>"><?php endforeach; ?>
      <?php if ($sort): ?><input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>"><?php endif; ?>

      <!-- Search Input (Wide) -->
      <div class="col-12 col-md-5 col-lg-6">
        <div class="input-group">
          <span class="input-group-text bg-light border-end-0 text-secondary"><i class="bi bi-search"></i></span>
          <input type="text" 
                 name="search" 
                 class="form-control border-start-0 ps-0 shadow-none" 
                 placeholder="Search notebooks, products, SKU, paper, ruling..." 
                 value="<?php echo htmlspecialchars($search); ?>">
        </div>
      </div>

      <!-- Category Dropdown -->
      <div class="col-12 col-sm-6 col-md-4 col-lg-3">
        <?php 
          $current_cat_val = !empty($selected_categories) ? $selected_categories[0] : '';
        ?>
        <select name="category" class="form-select border-gold-subtle fw-semibold text-navy shadow-none" onchange="this.form.submit()">
          <option value="" <?php echo empty($current_cat_val) ? 'selected' : ''; ?>>Category ▼</option>
          <option value="" <?php echo empty($current_cat_val) ? 'selected' : ''; ?>>All Categories</option>
          <?php foreach ($all_categories as $cat): 
            $is_sel = ($cat['slug'] === $current_cat_val || $cat['name'] === $current_cat_val);
          ?>
            <option value="<?php echo htmlspecialchars($cat['slug']); ?>" <?php echo $is_sel ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($cat['name']); ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Filters Button & Search Action Button -->
      <div class="col-12 col-sm-6 col-md-3 col-lg-3 d-flex gap-2">
        <button class="btn btn-navy flex-fill d-flex align-items-center justify-content-center gap-2 text-nowrap shadow-none fw-semibold" 
                type="button" 
                data-bs-toggle="offcanvas" 
                data-bs-target="#compactFiltersDrawer">
          <i class="bi bi-sliders text-gold"></i>
          <span>Filters</span>
          <?php 
            $active_filter_count = count($selected_brands) + count($selected_pages) + count($selected_rulings) + count($selected_sizes) + count($selected_qualities);
            if ($active_filter_count > 0): 
          ?>
            <span class="badge bg-gold text-navy rounded-pill px-1.5 py-0.5" style="font-size: 0.7rem;"><?php echo $active_filter_count; ?></span>
          <?php endif; ?>
        </button>

        <button class="btn btn-gold px-3.5 d-flex align-items-center justify-content-center shadow-none" type="submit" title="Search Catalogue">
          <i class="bi bi-search fs-6"></i>
        </button>
      </div>
    </form>
  </div>

  <!-- TOOLBAR: Count, View Mode & Sorting -->
  <div class="products-toolbar bg-white p-2.5 p-md-3 rounded-3 border shadow-sm mb-3">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2.5">
      <div class="d-flex flex-wrap align-items-center justify-content-between justify-content-md-start gap-2.5">
        <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold fs-7 px-2.5 py-1.5">
          <i class="bi bi-journal-check text-gold me-1"></i><?php echo count($grouped_products); ?> Products
        </span>
        
        <!-- View Mode Buttons -->
        <div class="btn-group btn-group-sm" role="group" aria-label="View Mode">
          <a href="<?php $q = $_GET; $q['view'] = 'grid'; echo 'products.php?' . http_build_query($q); ?>" class="btn <?php echo $view_mode === 'grid' ? 'btn-navy active' : 'btn-outline-secondary'; ?> fw-semibold py-1 px-2.5">
            <i class="bi bi-grid-fill me-1"></i> Grid
          </a>
          <a href="<?php $q = $_GET; $q['view'] = 'brand'; echo 'products.php?' . http_build_query($q); ?>" class="btn <?php echo $view_mode === 'brand' ? 'btn-navy active' : 'btn-outline-secondary'; ?> fw-semibold py-1 px-2.5">
            <i class="bi bi-diagram-3-fill me-1"></i> By Brand
          </a>
        </div>
      </div>

      <!-- Sorting Selector -->
      <form action="products.php" method="GET" class="d-flex align-items-center gap-2 mb-0">
        <?php if ($view_mode !== 'grid'): ?><input type="hidden" name="view" value="<?php echo htmlspecialchars($view_mode); ?>"><?php endif; ?>
        <?php foreach ($selected_categories as $c): ?><input type="hidden" name="cat[]" value="<?php echo htmlspecialchars($c); ?>"><?php endforeach; ?>
        <?php foreach ($selected_brands as $b): ?><input type="hidden" name="brand[]" value="<?php echo htmlspecialchars($b); ?>"><?php endforeach; ?>
        <?php foreach ($selected_rulings as $r): ?><input type="hidden" name="ruling[]" value="<?php echo htmlspecialchars($r); ?>"><?php endforeach; ?>
        <?php foreach ($selected_pages as $p): ?><input type="hidden" name="pages[]" value="<?php echo htmlspecialchars($p); ?>"><?php endforeach; ?>
        <?php if ($search): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>"><?php endif; ?>

        <label class="small fw-semibold text-secondary mb-0 text-nowrap" for="sortSelect">Sort:</label>
        <select name="sort" id="sortSelect" class="form-select form-select-sm border-secondary-subtle fw-semibold shadow-none flex-grow-1" onchange="this.form.submit()" style="min-width: 150px;">
          <option value="latest" <?php echo ($sort === 'latest') ? 'selected' : ''; ?>>Latest Arrivals</option>
          <option value="name" <?php echo ($sort === 'name') ? 'selected' : ''; ?>>Name (A-Z)</option>
          <option value="price_low" <?php echo ($sort === 'price_low') ? 'selected' : ''; ?>>Price: Low to High</option>
          <option value="price_high" <?php echo ($sort === 'price_high') ? 'selected' : ''; ?>>Price: High to Low</option>
        </select>
      </form>
    </div>
  </div>

  <!-- ACTIVE FILTER DISPLAY -->
  <?php if ($has_active_filters): ?>
    <div class="active-filter-chips bg-white p-2.5 rounded-3 border shadow-sm d-flex flex-wrap align-items-center gap-2 mb-4">
      <span class="small fw-bold text-navy me-1"><i class="bi bi-funnel-fill text-gold me-1"></i>Active Filters:</span>
      
      <?php foreach ($selected_categories as $c): 
        $cat_disp_name = $c;
        foreach ($all_categories as $ac) {
          if ($ac['slug'] === $c || $ac['name'] === $c) { $cat_disp_name = $ac['name']; break; }
        }
      ?>
        <span class="badge bg-navy text-white border px-2.5 py-1.5 rounded-pill small d-inline-flex align-items-center">
          Cat: <?php echo htmlspecialchars($cat_disp_name); ?>
          <a href="<?php echo build_filter_url('cat', $c); ?>" class="text-gold ms-1.5 text-decoration-none fw-bold">×</a>
        </span>
      <?php endforeach; ?>

      <?php foreach ($selected_brands as $b): ?>
        <span class="badge bg-gold-subtle text-navy border border-gold-subtle px-2.5 py-1.5 rounded-pill small d-inline-flex align-items-center">
          Brand: <?php echo htmlspecialchars($b); ?>
          <a href="<?php echo build_filter_url('brand', $b); ?>" class="text-navy ms-1.5 text-decoration-none fw-bold">×</a>
        </span>
      <?php endforeach; ?>

      <?php foreach ($selected_pages as $p): ?>
        <span class="badge bg-light text-dark border px-2.5 py-1.5 rounded-pill small d-inline-flex align-items-center">
          Pages: <?php echo htmlspecialchars($p); ?>p
          <a href="<?php echo build_filter_url('pages', $p); ?>" class="text-dark ms-1.5 text-decoration-none fw-bold">×</a>
        </span>
      <?php endforeach; ?>

      <?php foreach ($selected_rulings as $r): ?>
        <span class="badge bg-warm-ivory text-navy border px-2.5 py-1.5 rounded-pill small d-inline-flex align-items-center">
          Variety: <?php echo htmlspecialchars($r); ?>
          <a href="<?php echo build_filter_url('ruling', $r); ?>" class="text-navy ms-1.5 text-decoration-none fw-bold">×</a>
        </span>
      <?php endforeach; ?>

      <?php foreach ($selected_sizes as $sz): ?>
        <span class="badge bg-light text-navy border px-2.5 py-1.5 rounded-pill small d-inline-flex align-items-center">
          Size: <?php echo htmlspecialchars($sz); ?>
          <a href="<?php echo build_filter_url('size', $sz); ?>" class="text-navy ms-1.5 text-decoration-none fw-bold">×</a>
        </span>
      <?php endforeach; ?>

      <?php foreach ($selected_qualities as $q): ?>
        <span class="badge bg-light text-navy border px-2.5 py-1.5 rounded-pill small d-inline-flex align-items-center">
          Paper: <?php echo htmlspecialchars($q); ?>
          <a href="<?php echo build_filter_url('quality', $q); ?>" class="text-navy ms-1.5 text-decoration-none fw-bold">×</a>
        </span>
      <?php endforeach; ?>

      <?php if ($search): ?>
        <span class="badge bg-secondary text-white px-2.5 py-1.5 rounded-pill small d-inline-flex align-items-center">
          Search: "<?php echo htmlspecialchars($search); ?>"
          <a href="<?php echo build_filter_url('search', $search); ?>" class="text-white ms-1.5 text-decoration-none fw-bold">×</a>
        </span>
      <?php endif; ?>

      <a href="products.php" class="btn btn-outline-danger btn-xs px-2.5 py-1 rounded-pill ms-auto small fw-semibold text-decoration-none" style="font-size: 0.75rem;">
        <i class="bi bi-x-circle me-1"></i>Clear All
      </a>
    </div>
  <?php endif; ?>

  <!-- PRODUCTS DISPLAY (GRID VS BRAND GROUPED) -->
  <?php if (empty($grouped_products)): ?>
    <div class="text-center py-5 bg-white rounded-3 border shadow-sm my-4">
      <i class="bi bi-search-heart fs-1 text-muted mb-3 d-block"></i>
      <h4>No Matching Notebooks Found</h4>
      <p class="text-secondary">No products match your selected filter criteria. Try clearing some filters or searching for another keyword.</p>
      <a href="products.php" class="btn btn-gold btn-sm mt-2"><i class="bi bi-arrow-counterclockwise me-1"></i> Clear All Filters</a>
    </div>
  <?php elseif ($view_mode === 'brand'): ?>
    <?php
      $products_by_brand = [];
      foreach ($grouped_products as $item) {
          $bName = !empty($item['brand_name']) ? $item['brand_name'] : 'Raj Priya';
          if (!isset($products_by_brand[$bName])) {
              $products_by_brand[$bName] = [];
          }
          $products_by_brand[$bName][] = $item;
      }
      ksort($products_by_brand);
    ?>
    <?php foreach ($products_by_brand as $brand_name => $brand_items): ?>
      <div class="brand-group-section mb-5 bg-white p-4 rounded-3 border shadow-sm">
        <div class="d-flex align-items-center justify-content-between border-bottom pb-3 mb-4 flex-wrap gap-2">
          <div class="d-flex align-items-center gap-2">
            <span class="badge bg-gold text-navy fw-bold fs-6 px-3 py-1.5 rounded-pill text-uppercase" style="letter-spacing: 0.5px;">
              <i class="bi bi-patch-check-fill me-1.5"></i> BRAND: <?php echo htmlspecialchars($brand_name); ?>
            </span>
            <span class="badge bg-light text-secondary border rounded-pill px-2.5 py-1 small">
              <?php echo count($brand_items); ?> Products
            </span>
          </div>
          <a href="<?php echo build_filter_url('brand', $brand_name); ?>" class="btn btn-outline-navy btn-xs rounded-pill px-3 fw-semibold">
            Filter Only <?php echo htmlspecialchars($brand_name); ?> <i class="bi bi-arrow-right me-0 ms-1"></i>
          </a>
        </div>

        <div class="row g-4">
          <?php foreach ($brand_items as $item): ?>
            <div class="col-xl-6 col-lg-6 col-md-6 col-12 d-flex align-items-stretch">
              <?php echo render_product_card($item); ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="row g-4 mb-4">
      <?php foreach ($grouped_products as $item): ?>
        <div class="col-xl-6 col-lg-6 col-md-6 col-12 d-flex align-items-stretch mb-4">
          <?php echo render_product_card($item); ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

</div>

<!-- COMPACT FILTERS DRAWER (OFFCANVAS) -->
<div class="offcanvas offcanvas-end d-flex flex-column" tabindex="-1" id="compactFiltersDrawer" aria-labelledby="compactFiltersDrawerLabel" style="width: 380px; max-width: 90vw;">
  <div class="offcanvas-header bg-navy text-white flex-shrink-0">
    <h5 class="offcanvas-title fw-bold text-white fs-6" id="compactFiltersDrawerLabel">
      <i class="bi bi-sliders text-gold me-2"></i>Filter Catalogue Options
    </h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
  </div>
  
  <form action="products.php" method="GET" id="compactFiltersForm" class="d-flex flex-column h-100 m-0 overflow-hidden">
    <div class="offcanvas-body p-3 flex-grow-1 overflow-auto">
      <?php if ($search): ?><input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>"><?php endif; ?>
      <?php if (!empty($selected_categories)): ?>
        <?php foreach ($selected_categories as $c): ?>
          <input type="hidden" name="cat[]" value="<?php echo htmlspecialchars($c); ?>">
        <?php endforeach; ?>
      <?php endif; ?>
      <?php if ($sort): ?><input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>"><?php endif; ?>

      <!-- 1. Product / Brand -->
      <div class="mb-4">
        <h6 class="compact-filter-group-title text-navy fw-bold fs-6 pb-1 border-bottom d-flex align-items-center justify-content-between">
          <span><i class="bi bi-journal-bookmark-fill text-gold me-1.5"></i>Product / Brand</span>
          <span class="badge bg-light text-secondary fw-normal" style="font-size: 0.7rem;"><?php echo count($pdf_brands); ?> brands</span>
        </h6>
        <div class="compact-filter-scroll" style="max-height: 180px; overflow-y: auto;">
          <?php foreach ($pdf_brands as $b): 
            $checked = in_array($b, $selected_brands);
          ?>
            <div class="form-check small mb-1.5">
              <input class="form-check-input" type="checkbox" name="brand[]" value="<?php echo htmlspecialchars($b); ?>" id="f_brand_<?php echo md5($b); ?>" <?php echo $checked ? 'checked' : ''; ?>>
              <label class="form-check-label text-dark fw-medium" for="f_brand_<?php echo md5($b); ?>">
                <?php echo htmlspecialchars($b); ?>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- 2. Page Count Filter (MULTI SELECT) -->
      <div class="mb-4">
        <h6 class="compact-filter-group-title text-navy fw-bold fs-6 pb-1 border-bottom d-flex align-items-center justify-content-between">
          <span><i class="bi bi-file-earmark-text-fill text-gold me-1.5"></i>Page Count</span>
          <span class="badge bg-light text-secondary fw-normal" style="font-size: 0.7rem;">Multi-select</span>
        </h6>
        <div class="compact-filter-scroll" style="max-height: 200px; overflow-y: auto;">
          <div class="row g-1">
            <?php 
            $all_pdf_pages = ['28', '36', '60', '72', '80', '92', '100', '120', '124', '128', '132', '136', '140', '148', '160', '192', '232', '240', '288', '320', '384', '480', '500'];
            foreach ($all_pdf_pages as $pg): 
              $pg_label = $pg . ' Pages';
              $checked = in_array($pg, $selected_pages) || in_array($pg_label, $selected_pages);
            ?>
              <div class="col-6">
                <div class="form-check small mb-1">
                  <input class="form-check-input" type="checkbox" name="pages[]" value="<?php echo htmlspecialchars($pg); ?>" id="f_pg_<?php echo htmlspecialchars($pg); ?>" <?php echo $checked ? 'checked' : ''; ?>>
                  <label class="form-check-label text-dark fw-medium" for="f_pg_<?php echo htmlspecialchars($pg); ?>">
                    <?php echo htmlspecialchars($pg); ?> Pages
                  </label>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- 3. Ruling / Variety -->
      <div class="mb-4">
        <h6 class="compact-filter-group-title text-navy fw-bold fs-6 pb-1 border-bottom">
          <i class="bi bi-pencil-ruler text-gold me-1.5"></i>Ruling / Variety
        </h6>
        <div class="compact-filter-scroll" style="max-height: 180px; overflow-y: auto;">
          <?php foreach ($pdf_rulings as $r): 
            $checked = in_array($r, $selected_rulings);
          ?>
            <div class="form-check small mb-1.5">
              <input class="form-check-input" type="checkbox" name="ruling[]" value="<?php echo htmlspecialchars($r); ?>" id="f_ruling_<?php echo md5($r); ?>" <?php echo $checked ? 'checked' : ''; ?>>
              <label class="form-check-label text-dark fw-medium" for="f_ruling_<?php echo md5($r); ?>">
                <?php echo htmlspecialchars($r); ?>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- 4. Size -->
      <div class="mb-4">
        <h6 class="compact-filter-group-title text-navy fw-bold fs-6 pb-1 border-bottom">
          <i class="bi bi-aspect-ratio text-gold me-1.5"></i>Size
        </h6>
        <div class="compact-filter-scroll" style="max-height: 160px; overflow-y: auto;">
          <?php foreach ($pdf_sizes as $sz): 
            $checked = in_array($sz, $selected_sizes);
          ?>
            <div class="form-check small mb-1.5">
              <input class="form-check-input" type="checkbox" name="size[]" value="<?php echo htmlspecialchars($sz); ?>" id="f_sz_<?php echo md5($sz); ?>" <?php echo $checked ? 'checked' : ''; ?>>
              <label class="form-check-label text-dark fw-medium" for="f_sz_<?php echo md5($sz); ?>">
                <?php echo htmlspecialchars($sz); ?>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- 5. Quality / Paper -->
      <div class="mb-4">
        <h6 class="compact-filter-group-title text-navy fw-bold fs-6 pb-1 border-bottom">
          <i class="bi bi-layers-fill text-gold me-1.5"></i>Quality / Paper
        </h6>
        <div class="compact-filter-scroll" style="max-height: 160px; overflow-y: auto;">
          <?php foreach ($pdf_qualities as $q): 
            $checked = in_array($q, $selected_qualities);
          ?>
            <div class="form-check small mb-1.5">
              <input class="form-check-input" type="checkbox" name="quality[]" value="<?php echo htmlspecialchars($q); ?>" id="f_q_<?php echo md5($q); ?>" <?php echo $checked ? 'checked' : ''; ?>>
              <label class="form-check-label text-dark fw-medium" for="f_q_<?php echo md5($q); ?>">
                <?php echo htmlspecialchars($q); ?>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- Sticky / Fixed Offcanvas Action Bar Footer -->
    <div class="offcanvas-footer p-3 bg-white border-top flex-shrink-0 shadow-lg">
      <div class="d-flex gap-2">
        <a href="products.php" class="btn btn-outline-secondary flex-fill fw-semibold py-2 text-nowrap d-flex align-items-center justify-content-center" style="border-radius: 8px;">
          Reset All
        </a>
        <button type="submit" class="btn btn-gold flex-fill fw-bold py-2 text-nowrap shadow-sm d-flex align-items-center justify-content-center gap-1.5" style="border-radius: 8px;">
          <i class="bi bi-check2-circle fs-6"></i> Apply Filters
        </button>
      </div>
    </div>
  </form>
</div>

<script>
// WHOLESALE PRODUCT CARD PAGE COUNT & VARIANT SELECTION HANDLERS

function selectCardPage(btn, pageLabel, event) {
    if (event) event.stopPropagation();
    const card = btn.closest('.product-card');
    if (!card) return;

    const variantsContainer = card.querySelector('.product-card-variants-container');
    const pageButtons = card.querySelectorAll('.js-card-page-btn');
    const isCurrentlyActive = btn.classList.contains('active');

    if (isCurrentlyActive) {
        btn.classList.remove('active');
        if (variantsContainer) variantsContainer.classList.add('d-none');
    } else {
        pageButtons.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        if (variantsContainer) {
            variantsContainer.classList.remove('d-none');

            const panels = variantsContainer.querySelectorAll('.js-variants-panel');
            panels.forEach(p => p.classList.add('d-none'));

            const targetPanel = variantsContainer.querySelector('.js-variants-panel[data-page="' + pageLabel + '"]');
            if (targetPanel) {
                targetPanel.classList.remove('d-none');

                const titleEl = variantsContainer.querySelector('.js-selected-page-title');
                if (titleEl) {
                    titleEl.textContent = pageLabel + ' Variants';
                }

                // If no variant row exists in targetPanel, auto-activate first variant chip
                const activeVBtns = targetPanel.querySelectorAll('.js-card-variant-btn.active');
                if (activeVBtns.length === 0) {
                    const firstVBtn = targetPanel.querySelector('.js-card-variant-btn');
                    if (firstVBtn) {
                        const vName = firstVBtn.getAttribute('data-variety');
                        toggleCardVariant(firstVBtn, pageLabel, vName, null);
                    }
                }
            }
        }
    }
}

function toggleCardVariant(btn, pageLabel, varietyName, event) {
    if (event) event.stopPropagation();
    const panel = btn.closest('.js-variants-panel');
    if (!panel) return;

    const isCurrentlyActive = btn.classList.contains('active');
    const icon = btn.querySelector('.js-vbtn-icon');
    const rowsList = panel.querySelector('.js-variant-rows-list');
    const prodId = panel.getAttribute('data-prod-id');
    const unitPrice = parseFloat(panel.getAttribute('data-unit-price')) || 0;
    const formattedPrice = '₹' + unitPrice.toFixed(2);

    if (isCurrentlyActive) {
        // Unselect variant
        btn.classList.remove('active');
        if (icon) icon.className = 'bi bi-plus-lg me-1 js-vbtn-icon';

        // Remove order row
        const existingRow = rowsList ? rowsList.querySelector('.js-vrow[data-variety="' + varietyName + '"]') : null;
        if (existingRow) existingRow.remove();
    } else {
        // Select variant
        btn.classList.add('active');
        if (icon) icon.className = 'bi bi-check-lg me-1 js-vbtn-icon';

        // Create independent quantity row if not existing
        let existingRow = rowsList ? rowsList.querySelector('.js-vrow[data-variety="' + varietyName + '"]') : null;
        if (!existingRow && rowsList) {
            const rowDiv = document.createElement('div');
            rowDiv.className = 'variant-order-item p-2 bg-white rounded border d-flex align-items-center justify-content-between gap-2 js-vrow';
            rowDiv.setAttribute('data-variety', varietyName);
            rowDiv.setAttribute('data-prod-id', prodId);
            rowDiv.setAttribute('data-unit-price', unitPrice);

            rowDiv.innerHTML = `
                <div>
                    <span class="fw-bold text-navy small d-block">${varietyName}</span>
                    <span class="text-muted" style="font-size: 0.7rem;">${formattedPrice} / pc</span>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <div class="input-group input-group-sm" style="width: 95px;">
                        <button type="button" class="btn btn-outline-secondary py-0 px-2" onclick="changeVRowQty(this, -1)">-</button>
                        <input type="number" class="form-control text-center py-0 px-1 js-vrow-qty" value="10" min="1" oninput="updateVRowSubtotal(this)">
                        <button type="button" class="btn btn-outline-secondary py-0 px-2" onclick="changeVRowQty(this, 1)">+</button>
                    </div>
                    <span class="fw-bold text-navy small js-vrow-subtotal" style="min-width: 55px; text-align: right;">₹${(unitPrice * 10).toFixed(2)}</span>
                </div>
            `;
            rowsList.appendChild(rowDiv);
        }
    }

    // Refresh batch submit button state
    refreshBatchSubmitState(panel);
}

function changeVRowQty(btn, change) {
    const input = btn.parentNode.querySelector('.js-vrow-qty');
    if (!input) return;
    let currentVal = parseInt(input.value) || 1;
    let newVal = Math.max(1, currentVal + change);
    input.value = newVal;
    updateVRowSubtotal(input);
}

function updateVRowSubtotal(input) {
    const vrow = input.closest('.js-vrow');
    if (!vrow) return;
    const unitPrice = parseFloat(vrow.getAttribute('data-unit-price')) || 0;
    const qty = Math.max(1, parseInt(input.value) || 1);
    const subtotalEl = vrow.querySelector('.js-vrow-subtotal');
    if (subtotalEl) {
        subtotalEl.textContent = '₹' + (unitPrice * qty).toFixed(2);
    }
}

function refreshBatchSubmitState(panel) {
    const activeRows = panel.querySelectorAll('.js-vrow');
    const submitBtn = panel.querySelector('.js-batch-submit-btn');
    const countSpan = panel.querySelector('.js-total-selected-count');

    if (countSpan) countSpan.textContent = activeRows.length;

    if (submitBtn) {
        if (activeRows.length > 0) {
            submitBtn.classList.remove('d-none');
        } else {
            submitBtn.classList.add('d-none');
        }
    }
}

function submitBatchVariants(btn, event) {
    if (event) event.preventDefault();
    const panel = btn.closest('.js-variants-panel');
    if (!panel) return;

    const form = panel.querySelector('.js-batch-cart-form');
    const inputsWrap = panel.querySelector('.js-hidden-inputs-wrap');
    const rows = panel.querySelectorAll('.js-vrow');

    if (!form || !inputsWrap || rows.length === 0) return;

    inputsWrap.innerHTML = '';
    rows.forEach((row, idx) => {
        const pId = row.getAttribute('data-prod-id');
        const ruling = row.getAttribute('data-variety');
        const qtyInput = row.querySelector('.js-vrow-qty');
        const qty = qtyInput ? Math.max(1, parseInt(qtyInput.value) || 1) : 1;

        inputsWrap.innerHTML += `
            <input type="hidden" name="items[${idx}][product_id]" value="${pId}">
            <input type="hidden" name="items[${idx}][ruling_type]" value="${ruling}">
            <input type="hidden" name="items[${idx}][quantity]" value="${qty}">
        `;
    });

    handleCartFormSubmit(form, btn);
}

// Interceptor for Batch Add-To-Cart Form ONLY
document.addEventListener('submit', function(e) {
    const form = e.target;
    if (form && form.classList.contains('js-batch-cart-form')) {
        e.preventDefault();
        const submitBtn = form.querySelector('.js-batch-submit-btn') || form.querySelector('button[type="submit"]');
        handleCartFormSubmit(form, submitBtn);
    }
});

function handleCartFormSubmit(form, submitBtn) {
    if (!form) return;
    const origBtnHtml = submitBtn ? submitBtn.innerHTML : '';
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Adding...';
    }

    const formData = new FormData(form);
    formData.append('ajax', '1');

    fetch('cart-action.php', {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origBtnHtml;
        }

        if (data.success) {
            // 1. Update Cart Badge Count in Header
            const badgeEls = document.querySelectorAll('.cart-badge-count');
            badgeEls.forEach(b => {
                b.textContent = data.cart_count;
                b.classList.add('badge-bounce');
                setTimeout(() => b.classList.remove('badge-bounce'), 400);
            });

            // 2. Show Success Notification Toast (User STAYS on Products Page!)
            showCartToast(data.message || 'Product added to cart!');
        } else if (data.message) {
            showCartToast(data.message, 'danger');
        }
    })
    .catch(err => {
        console.error('Cart add error:', err);
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = origBtnHtml;
        }
        showCartToast('Product added to cart!');
    });
}

function showCartToast(message, type = 'success') {
    let container = document.getElementById('cart-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'cart-toast-container';
        container.style.cssText = 'position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; pointer-events: none;';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    const isSuccess = type === 'success';
    const bgClass = isSuccess ? '#002060' : '#dc3545';
    const borderClass = isSuccess ? '#c89b3c' : '#dc3545';

    toast.style.cssText = `
        background-color: ${bgClass};
        color: #ffffff;
        border-left: 4px solid ${borderClass};
        padding: 12px 18px;
        border-radius: 8px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.25);
        font-weight: 600;
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        gap: 10px;
        pointer-events: auto;
        animation: toastIn 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275) forwards;
    `;

    toast.innerHTML = `
        <i class="bi ${isSuccess ? 'bi-check-circle-fill text-gold' : 'bi-exclamation-octagon-fill'} fs-5"></i>
        <span>${message}</span>
        <a href="cart.php" class="btn btn-xs btn-gold ms-2 py-0.5 px-2 text-nowrap" style="font-size: 0.75rem;">View Cart <i class="bi bi-arrow-right me-0"></i></a>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.animation = 'toastOut 0.3s forwards';
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

function increaseQty(btn) {
    const input = btn.parentNode.querySelector('.qty-input');
    if (input) {
        let val = parseInt(input.value) || 1;
        input.value = val + 1;
    }
}

function decreaseQty(btn) {
    const input = btn.parentNode.querySelector('.qty-input');
    if (input) {
        let val = parseInt(input.value) || 1;
        if (val > 1) {
            input.value = val - 1;
        }
    }
}

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

