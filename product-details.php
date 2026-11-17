<?php
require_once __DIR__ . '/includes/functions.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$db = getDB();

$stmt = $db->prepare("
    SELECT p.*, c.name as category_name, c.slug as category_slug 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE p.id = ? AND p.active = 1
");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    header("Location: products.php");
    exit();
}

$stmt_stock = $db->prepare("SELECT stock, bundle_stock, loose_stock, bundle_qty FROM product_variants WHERE product_id = ?");
$stmt_stock->execute([$product['id']]);
$variant_stock_rows = $stmt_stock->fetchAll(PDO::FETCH_ASSOC);
if (!empty($variant_stock_rows)) {
    $product['stock'] = 0;
    $product['bundle_stock'] = 0;
    foreach ($variant_stock_rows as $variant_stock_row) {
        $v_bqty = max(1, (int)$variant_stock_row['bundle_qty']);
        $stored_bundle = max(0, (int)($variant_stock_row['bundle_stock'] ?? 0));
        $stored_stock = max(0, (int)($variant_stock_row['stock'] ?? 0));
        $stock_breakdown = calculate_stock_breakdown($stored_stock, $v_bqty);
        $v_bundles = max($stored_bundle, $stock_breakdown['bundle_stock']);
        $v_loose = ($v_bundles > $stock_breakdown['bundle_stock']) ? max(0, (int)($variant_stock_row['loose_stock'] ?? 0)) : $stock_breakdown['loose_stock'];
        $product['stock'] += ($v_bundles * $v_bqty) + $v_loose;
        $product['bundle_stock'] += $v_bundles;
    }
} else {
    $fallback_ppb = max(1, (int)($product['std_qty'] ?? 1));
    $stored_bundle = max(0, (int)($product['bundle_stock'] ?? 0));
    $stored_stock = max(0, (int)($product['stock'] ?? 0));
    $stock_breakdown = calculate_stock_breakdown($stored_stock, $fallback_ppb);
    $v_bundles = max($stored_bundle, $stock_breakdown['bundle_stock']);
    $v_loose = ($v_bundles > $stock_breakdown['bundle_stock']) ? max(0, $stored_stock - ($v_bundles * $fallback_ppb)) : $stock_breakdown['loose_stock'];
    $product['stock'] = ($v_bundles * $fallback_ppb) + $v_loose;
    $product['bundle_stock'] = $v_bundles;
}

$detail_ruling_stocks = [];
$detail_variety_options = [];
$stmt_detail_variants = $db->prepare("
    SELECT id, product_code, variety, variety_display, bundle_qty, bundle_stock, loose_stock, stock,
           price, sale_price, selling_price, discounted_price
    FROM product_variants
    WHERE product_id = ?
    ORDER BY id
");
$stmt_detail_variants->execute([$product['id']]);
$detail_variant_rows = $stmt_detail_variants->fetchAll(PDO::FETCH_ASSOC);

$get_detail_price_breakdown = static function (array $product, string $variety, array $variant = []): array {
    $isRuled = is_notebook_variety_ruled($variety, get_variant_code_for_variety($variety));
    $suffix = $isRuled ? 'ruled' : 'unruled';
    $parentRegular = (float)($product['selling_price_' . $suffix] ?? 0);
    if ($parentRegular <= 0) {
        $parentRegular = (float)($product['price'] ?? 0);
    }

    $variantDiscount = (float)($variant['discounted_price'] ?? 0);
    $variantRegular = (float)($variant['selling_price'] ?? 0);
    $variantPrice = (float)($variant['price'] ?? 0);
    $variantSale = (float)($variant['sale_price'] ?? 0);

    if ($variantDiscount > 0) {
        $regular = $variantRegular > 0 ? $variantRegular : ($variantPrice > 0 ? $variantPrice : $parentRegular);
        $discount = $variantDiscount;
    } elseif ($variantPrice > 0 || $variantSale > 0) {
        $regular = $variantPrice > 0 ? $variantPrice : $parentRegular;
        $discount = $variantSale > 0 ? $variantSale : (float)($product['sale_price'] ?? 0);
    } elseif ($variantRegular > 0) {
        $regular = $variantRegular;
        $discount = 0.0;
    } else {
        $regular = $parentRegular;
        $discount = (float)($product['discounted_price_' . $suffix] ?? 0);
        if ($discount <= 0) {
            $discount = (float)($product['sale_price'] ?? 0);
        }
    }

    $effective = get_product_single_piece_price($product, $variety, (int)($variant['id'] ?? 0));
    if ($effective <= 0) {
        $effective = $discount > 0 && $discount < $regular ? $discount : $regular;
    }
    if ($regular <= 0) {
        $regular = $effective;
    }
    if ($discount <= 0 || $discount >= $regular) {
        $discount = 0.0;
    }

    return [
        'regular' => $regular,
        'discount' => $discount,
        'effective' => $effective
    ];
};

if (!empty($detail_variant_rows)) {
    foreach ($detail_variant_rows as $variant_row) {
        $detail_variety = trim((string)($variant_row['variety_display'] ?: $variant_row['variety']));
        if ($detail_variety === '' || stripos($detail_variety, 'GST') !== false || isset($detail_ruling_stocks[$detail_variety])) {
            continue;
        }
        $detail_ruling_string = (!empty($product['pages']) ? $product['pages'] . ' Pages - ' : '') . $detail_variety;
        $detail_stock_record = get_product_variant_stock_record(
            $db,
            $product['id'],
            $variant_row['product_code'],
            $detail_ruling_string,
            $product
        );
        $detail_ruling_stocks[$detail_variety] = $detail_stock_record ?: [
            'stock' => 0,
            'bundle_stock' => 0,
            'bundle_qty' => max(1, (int)($variant_row['bundle_qty'] ?? 1))
        ];
        $price_breakdown = $get_detail_price_breakdown($product, $detail_variety, $variant_row);
        $detail_variety_options[] = [
            'id' => (int)$variant_row['id'],
            'product_code' => (string)$variant_row['product_code'],
            'name' => $detail_variety,
            'ruling_type' => $detail_ruling_string,
            'price' => $price_breakdown['effective'],
            'regular_price' => $price_breakdown['regular'],
            'discounted_price' => $price_breakdown['discount'],
            'stock' => $detail_ruling_stocks[$detail_variety]
        ];
    }
}

$default_variety_index = 0;
if (!empty($detail_variety_options)) {
    // Intelligently default to first in-stock variety (bundles first, then pieces)
    $found_in_stock = false;
    foreach ($detail_variety_options as $optIdx => $opt) {
        if (($opt['stock']['bundle_stock'] ?? 0) > 0) {
            $default_variety_index = $optIdx;
            $found_in_stock = true;
            break;
        }
    }
    if (!$found_in_stock) {
        foreach ($detail_variety_options as $optIdx => $opt) {
            if (($opt['stock']['stock'] ?? 0) > 0) {
                $default_variety_index = $optIdx;
                break;
            }
        }
    }
    $detail_ruling_default = $detail_variety_options[$default_variety_index]['name'];
} elseif (empty($product['varieties_managed'])) {
    $detail_ruling_default = strcasecmp($product['ruling_option'] ?? '', 'Ruled') === 0
        ? 'Ruled'
        : (strcasecmp($product['ruling_option'] ?? '', 'Both') === 0 || empty($product['ruling_option'])
            ? 'Unruled'
            : (strcasecmp($product['ruling_option'], 'Unruled') === 0 ? 'Unruled' : $product['ruling_option']));
    $rulings_to_load = strcasecmp($product['ruling_option'] ?? '', 'Both') === 0
        ? ['Unruled', 'Ruled']
        : [$detail_ruling_default];
    foreach ($rulings_to_load as $detail_ruling) {
        $detail_ruling_string = (!empty($product['pages']) ? $product['pages'] . ' Pages - ' : '') . $detail_ruling;
        $detail_product_code = get_variant_product_code($product['id'], $detail_ruling_string, $product['pages'] ?? null);
        $detail_ruling_stocks[$detail_ruling] = get_product_variant_stock_record(
            $db,
            $product['id'],
            $detail_product_code,
            $detail_ruling_string,
            $product
        );
    }
} else {
    $detail_ruling_default = '';
}
$detail_default_stock = $detail_ruling_stocks[$detail_ruling_default] ?? [
    'stock' => $product['stock'],
    'bundle_stock' => $product['bundle_stock'],
    'bundle_qty' => max(1, (int)($product['std_qty'] ?? 1))
];

$product['image'] = get_product_group_image($db, $product);

$logos = get_active_logos();

// Fetch Sibling Product Variants for this Base Product Group
$current_group_key = get_product_group_key($product);
$stmt = $db->prepare("SELECT * FROM products WHERE active = 1 AND category_id = ?");
$stmt->execute([$product['category_id']]);
$category_products = $stmt->fetchAll();

$sibling_variants = [];
foreach ($category_products as $p) {
    if (get_product_group_key($p) === $current_group_key) {
        $sibling_variants[] = [
            'id' => $p['id'],
            'name' => $p['name'],
            'variant_label' => format_variant_page_label($p),
            'is_current' => ($p['id'] == $product['id'])
        ];
    }
}

$related_products = array_values(array_filter($category_products, function ($relatedProduct) use ($current_group_key, $product) {
    return (int)$relatedProduct['id'] !== (int)$product['id']
        && get_product_group_key($relatedProduct) !== $current_group_key;
}));
usort($related_products, function ($a, $b) {
    $featuredComparison = (int)($b['featured'] ?? 0) <=> (int)($a['featured'] ?? 0);
    return $featuredComparison !== 0 ? $featuredComparison : strcasecmp($a['name'], $b['name']);
});
$related_products = array_slice($related_products, 0, 8);

usort($sibling_variants, function($a, $b) {
    preg_match('/(\d+)/', $a['variant_label'], $matchA);
    preg_match('/(\d+)/', $b['variant_label'], $matchB);
    $valA = isset($matchA[1]) ? (int)$matchA[1] : 0;
    $valB = isset($matchB[1]) ? (int)$matchB[1] : 0;
    if ($valA === $valB) return strcmp($a['variant_label'], $b['variant_label']);
    return $valA <=> $valB;
});



require_once __DIR__ . '/includes/header.php';
?>

<style>
  .product-detail-page {
    --detail-navy: #17243A;
    --detail-gold: #C89B3C;
    --detail-line: #E8E2D7;
    --detail-muted: #64748B;
    padding-bottom: 4rem;
  }
  .product-detail-layout {
    align-items: start;
  }
  .product-gallery-card {
    position: sticky;
    top: 1rem;
    overflow: hidden;
    padding: clamp(0.65rem, 1.6vw, 1rem);
    border: 1px solid #E5DED0;
    border-radius: 1.35rem;
    background: linear-gradient(145deg, #fff 0%, #F8F4EA 100%);
    box-shadow: 0 18px 40px rgba(23, 36, 58, 0.1);
  }
  .product-gallery-image {
    display: block;
    width: 100%;
    aspect-ratio: 4 / 3;
    border-radius: 1rem;
    background: radial-gradient(ellipse at center, #fff 0%, #F8F5EF 100%);
    object-fit: cover;
  }
  .product-gallery-brand {
    position: absolute;
    top: 1rem;
    right: 1rem;
    display: grid;
    width: 3.25rem;
    height: 3.25rem;
    place-items: center;
    border: 1px solid rgba(232, 226, 215, 0.9);
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.92);
    box-shadow: 0 4px 14px rgba(23, 36, 58, 0.08);
  }
  .product-gallery-brand img {
    width: 2.2rem;
    height: 2.2rem;
    object-fit: contain;
  }
  .product-detail-content {
    padding-left: clamp(0rem, 1.5vw, 1.25rem);
  }
  .product-detail-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
  }
  .product-detail-badges .badge {
    margin: 0 !important;
    padding: 0.55rem 0.8rem !important;
    font-weight: 600;
  }
  .product-detail-badges .badge.bg-navy {
    background: var(--detail-navy) !important;
    color: #fff !important;
  }
  .product-detail-title {
    margin-bottom: 0.45rem;
    color: var(--detail-navy);
    font-size: clamp(2rem, 4vw, 3rem);
    font-weight: 800;
    letter-spacing: -0.035em;
    line-height: 1.05;
  }
  .product-detail-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem 1rem;
    margin-bottom: 1.15rem;
    color: var(--detail-muted);
    font-size: 0.84rem;
  }
  .product-detail-meta strong {
    color: var(--detail-navy);
  }
  .product-price-panel {
    padding: 1rem 1.15rem;
    border: 1px solid #EAD9AE;
    border-radius: 1rem;
    background: linear-gradient(120deg, #FCF8EF 0%, #FFFDF8 100%);
  }
  .product-price-current {
    color: var(--detail-navy);
    font-size: clamp(1.9rem, 3vw, 2.4rem);
    font-weight: 800;
    letter-spacing: -0.04em;
    line-height: 1;
    font-variant-numeric: tabular-nums;
  }
  .product-price-compare {
    color: #7B8491;
    font-size: 1.1rem;
    text-decoration: line-through;
    font-variant-numeric: tabular-nums;
  }
  .product-price-save {
    padding: 0.35rem 0.65rem;
    border: 1px solid #B7E4C7;
    border-radius: 999px;
    background: #ECFDF3;
    color: #167347;
    font-size: 0.78rem;
    font-weight: 700;
  }
  .product-price-unit {
    margin-top: 0.45rem;
    color: var(--detail-muted);
    font-size: 0.78rem;
  }
  .product-detail-description {
    margin: 0 0 1.35rem;
    color: #596579;
    line-height: 1.75;
  }
  .variant-selector-wrap,
  .product-selection-card,
  .product-order-card,
  .product-spec-card {
    border: 1px solid var(--detail-line) !important;
    border-radius: 1rem !important;
    background: #fff !important;
    box-shadow: 0 8px 24px rgba(23, 36, 58, 0.045);
  }
  .variant-selector-wrap,
  .product-selection-card,
  .product-order-card {
    padding: 1rem !important;
  }
  .variant-label-title,
  .product-selection-title {
    margin-bottom: 0.8rem !important;
    color: var(--detail-navy);
    font-size: 0.92rem !important;
    font-weight: 750;
  }
  .product-variant-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 185px), 1fr));
    gap: 0.65rem;
  }
  .product-variant-choice {
    position: relative;
    display: flex;
    min-width: 0;
    min-height: 3.6rem;
    align-items: center;
    gap: 0.6rem;
    margin: 0 !important;
    padding: 0.75rem;
    border: 1px solid var(--detail-line);
    border-radius: 0.75rem;
    background: #fff;
    transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
  }
  .product-variant-choice:has(.form-check-input:checked) {
    border-color: var(--detail-gold);
    background: #FFFCF4;
    box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.12);
  }
  .product-variant-choice .form-check-input {
    position: static;
    float: none;
    flex: 0 0 auto;
    margin: 0;
  }
  .product-variant-choice .form-check-label {
    display: flex;
    width: 100%;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    cursor: pointer;
    font-size: 0.84rem;
  }
  .product-variant-choice .product-variant-price {
    color: var(--detail-navy);
    font-weight: 750;
    white-space: nowrap;
  }
  .product-detail-page .btn-variant {
    border-radius: 999px;
    border-color: var(--detail-line);
    background: #fff;
  }
  .product-detail-page .btn-variant.active {
    border-color: var(--detail-gold);
    background: #FCF8EF;
    color: var(--detail-navy);
    font-weight: 700;
  }
  .product-order-card .btn-group > .btn {
    border-radius: 0.7rem !important;
  }
  .product-spec-card {
    padding: clamp(1rem, 2.5vw, 1.5rem) !important;
  }
  .product-spec-card .table {
    --bs-table-bg: transparent;
    margin-bottom: 0;
  }
  .product-spec-card .table th,
  .product-spec-card .table td {
    padding: 0.7rem 0.65rem;
    border-color: #EEEAE2;
    vertical-align: middle;
  }
  .product-spec-card .table th {
    width: 38%;
    color: #5D6878;
    font-weight: 600;
  }
  .product-cart-button {
    min-height: 3.25rem;
    border-radius: 0.85rem;
  }
  @media (max-width: 991.98px) {
    .product-gallery-card {
      position: relative;
      top: auto;
    }
    .product-detail-content {
      padding-left: 0;
    }
  }
  @media (max-width: 575.98px) {
    .product-detail-page {
      padding-top: 1rem !important;
    }
    .product-gallery-card {
      padding: 0.6rem;
      border-radius: 1rem;
    }
    .product-price-panel,
    .product-selection-card,
    .product-order-card {
      padding: 0.85rem !important;
    }
    .product-variant-grid {
      grid-template-columns: 1fr;
    }
    .product-spec-card .table th {
      width: 42%;
    }
  }
</style>

<div class="container section-padding py-4 product-detail-page">
  <?php echo render_page_navigation('Product Details', ['Notebooks' => 'products.php', $product['name'] => '']); ?>

  <div class="row g-4 g-xl-5 product-detail-layout">
    <!-- Product Image & Brand Overlay -->
    <div class="col-lg-5">
      <div class="product-gallery-card text-center position-relative">
        <!-- Logo Overlay Badge (Product Logo) -->
        <div class="product-gallery-brand">
          <img src="<?php echo htmlspecialchars($logos['product']); ?>" alt="Rajpriya Product Brand">
        </div>

        <img src="<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-gallery-image">
      </div>
    </div>

    <!-- Product Info -->
    <div class="col-lg-7">
      <div class="product-detail-content">
        <div class="product-detail-badges">
        <span class="badge bg-cream text-gold border px-3 py-2 rounded-pill mb-2"><?php echo htmlspecialchars($product['category_name']); ?></span>
        <?php if (!empty($product['brand_name'])): ?>
          <span class="badge bg-navy text-white px-3 py-2 rounded-pill mb-2 ms-1">Brand: <?php echo htmlspecialchars($product['brand_name']); ?></span>
        <?php endif; ?>
        <?php if (!empty($product['gsm'])): ?>
          <span class="badge bg-light text-navy border px-3 py-2 rounded-pill mb-2 ms-1"><i class="bi bi-file-text me-1 text-gold"></i>GSM: <?php echo htmlspecialchars($product['gsm']); ?> GSM</span>
        <?php endif; ?>
        <?php 
          $detail_ppb = get_product_pieces_per_bundle($product['id'], $detail_ruling_default, $product);
          $detail_bundle_stock = max(0, (int)($detail_default_stock['bundle_stock'] ?? 0));
          $detail_piece_stock = max(0, (int)($detail_default_stock['stock'] ?? 0));
          $initial_order_type = $detail_bundle_stock > 0 ? 'Bundle' : 'Single Piece';
        ?>
        <span id="detailPpbBadgeWrap" class="badge bg-gold-subtle text-navy border border-warning-subtle px-3 py-2 rounded-pill mb-2 ms-1 <?php echo $detail_ppb > 1 ? '' : 'd-none'; ?>"><i class="bi bi-box-seam me-1 text-gold"></i>Std Qty: <span id="detailPpbBadge"><?php echo $detail_ppb; ?></span> Pcs / Bundle</span>
        </div>

        <h1 class="product-detail-title"><?php echo htmlspecialchars($product['name']); ?></h1>
        <p class="product-detail-meta">
          <span>SKU: <strong id="detailSku"><?php echo htmlspecialchars($detail_variety_options[$default_variety_index]['product_code'] ?? $product['sku']); ?></strong></span>
          <span>Selected variety stock: <strong id="detailSingleStockStatus" class="text-success"><?php echo $detail_piece_stock > 0 ? 'In Stock (' . number_format($detail_piece_stock) . ' Pcs)' : 'Available for order'; ?></strong></span>
        </p>

        <?php if (!empty($product['manual_verification_flag'])): ?>
          <div class="alert alert-warning py-2 px-3 mb-3 small">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> <strong>Price Verification Flagged:</strong> <?php echo htmlspecialchars($product['manual_verification_notes']); ?>
          </div>
        <?php endif; ?>

        <?php if (count($sibling_variants) > 1): ?>
          <div class="variant-selector-wrap mb-4">
            <div class="variant-label-title fw-bold text-navy mb-2" style="font-size: 0.85rem;"><i class="bi bi-layers-fill text-gold me-1"></i> Available Variants / Pages:</div>
            <div class="variant-buttons-group d-flex flex-wrap gap-2">
              <?php foreach ($sibling_variants as $v): 
                $cleanLbl = format_variant_page_label($v);
              ?>
                <a href="product-details.php?id=<?php echo $v['id']; ?>" class="btn btn-variant <?php echo $v['is_current'] ? 'active' : ''; ?>">
                  <?php echo htmlspecialchars($cleanLbl); ?>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <!-- Price Display -->
        <?php
          $unruled_price_info = $get_detail_price_breakdown($product, 'Unruled');
          $ruled_price_info = $get_detail_price_breakdown($product, 'Ruled');
          $unruled_price = $unruled_price_info['effective'];
          $ruled_price = $ruled_price_info['effective'];
          $has_both = !empty($detail_variety_options)
              ? count($detail_variety_options) > 1
              : (empty($product['varieties_managed']) && ($product['ruling_option'] === 'Both' || (empty($product['ruling_option']) && !empty($product['selling_price_ruled']) && !empty($product['selling_price_unruled']))));
          $is_ruled_only = ($product['ruling_option'] === 'Ruled');
          if (!empty($detail_variety_options)) {
              $selected_option = $detail_variety_options[$default_variety_index];
              $default_price = (float)$selected_option['price'];
              $default_regular_price = (float)$selected_option['regular_price'];
              $default_disc = (float)$selected_option['discounted_price'];
          } else {
              $default_price_info = $is_ruled_only ? $ruled_price_info : $unruled_price_info;
              $default_price = (float)$default_price_info['effective'];
              $default_regular_price = (float)$default_price_info['regular'];
              $default_disc = (float)$default_price_info['discount'];
          }
          $disc_unruled = $unruled_price_info['discount'];
          $disc_ruled = $ruled_price_info['discount'];
          $regular_unruled = $unruled_price_info['regular'];
          $regular_ruled = $ruled_price_info['regular'];
          $compare_price = !empty($product['mrp']) && (float)$product['mrp'] > $default_price
              ? (float)$product['mrp']
              : $default_regular_price;
          $discount_percent = $compare_price > $default_price
              ? round((($compare_price - $default_price) / $compare_price) * 100)
              : 0;
        ?>
        <div class="product-price-panel mb-4" aria-live="polite">
          <div class="d-flex flex-wrap align-items-baseline gap-2">
            <span class="product-price-current" id="displayedPrice"><?php echo format_price($default_price); ?></span>
            <span class="product-price-compare <?php echo $compare_price > $default_price ? '' : 'd-none'; ?>" id="comparePrice"><?php echo format_price($compare_price); ?></span>
            <span class="product-price-save <?php echo $compare_price > $default_price ? '' : 'd-none'; ?>" id="discountBadge">
              <i class="bi bi-tag-fill me-1"></i><span id="discountText">Save <?php echo $discount_percent; ?>%</span>
            </span>
          </div>
          <div class="product-price-unit">Price per piece</div>
        </div>

        <p class="product-detail-description"><?php echo nl2br(htmlspecialchars($product['description'])); ?></p>

        <!-- Add to Cart Form -->
        <form action="cart-action.php" method="POST" class="mb-5 add-to-cart-form" id="productDetailsForm">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
          <input type="hidden" name="variant_id" id="detailVariantId" value="<?php echo (int)($detail_variety_options[$default_variety_index]['id'] ?? 0); ?>">

          <?php if (!empty($product['varieties_managed']) && empty($detail_variety_options)): ?>
            <div class="alert alert-secondary small mb-4">
              <i class="bi bi-info-circle me-1"></i>This product currently has no available varieties.
            </div>
          <?php endif; ?>

          <?php if (!empty($detail_variety_options) && count($detail_variety_options) > 1): ?>
            <div class="product-selection-card mb-4">
              <label class="product-selection-title d-block"><i class="bi bi-pencil-square me-1 text-gold"></i> Select Variety</label>
              <div class="product-variant-grid">
                <?php foreach ($detail_variety_options as $optionIndex => $option): ?>
                  <div class="form-check product-variant-choice">
                    <input class="form-check-input" type="radio" name="ruling_type" id="detailVariety<?php echo $optionIndex; ?>"
                           value="<?php echo htmlspecialchars($option['ruling_type']); ?>"
                           data-variety-name="<?php echo htmlspecialchars($option['name']); ?>"
                           data-variety-id="<?php echo (int)$option['id']; ?>"
                           data-variety-code="<?php echo htmlspecialchars($option['product_code'], ENT_QUOTES, 'UTF-8'); ?>"
                           data-variety-price="<?php echo (float)$option['regular_price']; ?>"
                           data-variety-discount="<?php echo (float)$option['discounted_price']; ?>"
                           data-variety-stock="<?php echo htmlspecialchars(json_encode($option['stock']), ENT_QUOTES, 'UTF-8'); ?>"
                           <?php echo $option['name'] === $detail_ruling_default ? 'checked' : ''; ?>
                           onchange="document.getElementById('detailVariantId').value = this.dataset.varietyId; document.getElementById('detailSku').textContent = this.dataset.varietyCode; switchRulingVariant(this.dataset.varietyName, Number(this.dataset.varietyPrice), Number(this.dataset.varietyDiscount), JSON.parse(this.dataset.varietyStock))">
                    <label class="form-check-label fw-semibold" for="detailVariety<?php echo $optionIndex; ?>">
                      <span><?php echo htmlspecialchars($option['name']); ?></span>
                      <span class="product-variant-price"><?php echo format_price($option['price']); ?></span>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php elseif (!empty($detail_variety_options)): ?>
            <?php $singleOption = $detail_variety_options[0]; ?>
            <input type="hidden" name="ruling_type" value="<?php echo htmlspecialchars($singleOption['ruling_type']); ?>">
          <?php elseif ($has_both): ?>
            <div class="product-selection-card mb-4">
              <label class="product-selection-title d-block"><i class="bi bi-pencil-square me-1 text-gold"></i> Select Ruling</label>
              <div class="product-variant-grid">
                <div class="form-check product-variant-choice">
                  <input class="form-check-input" type="radio" name="ruling_type" id="ruleUnruled" value="<?php echo (!empty($product['pages']) ? $product['pages'] . ' Pages - ' : '') . 'Unruled'; ?>" checked onchange="switchRulingVariant('Unruled', <?php echo $regular_unruled; ?>, <?php echo $disc_unruled; ?>, <?php echo json_encode($detail_ruling_stocks['Unruled'] ?? ['stock' => 0, 'bundle_stock' => 0, 'bundle_qty' => $detail_ppb]); ?>)">
                  <label class="form-check-label fw-semibold" for="ruleUnruled">
                    <span>Unruled</span>
                    <span class="product-variant-price"><?php echo format_price($unruled_price); ?></span>
                  </label>
                </div>
                <div class="form-check product-variant-choice">
                  <input class="form-check-input" type="radio" name="ruling_type" id="ruleRuled" value="<?php echo (!empty($product['pages']) ? $product['pages'] . ' Pages - ' : '') . 'Ruled'; ?>" onchange="switchRulingVariant('Ruled', <?php echo $regular_ruled; ?>, <?php echo $disc_ruled; ?>, <?php echo json_encode($detail_ruling_stocks['Ruled'] ?? ['stock' => 0, 'bundle_stock' => 0, 'bundle_qty' => $detail_ppb]); ?>)">
                  <label class="form-check-label fw-semibold" for="ruleRuled">
                    <span>Ruled</span>
                    <span class="product-variant-price"><?php echo format_price($ruled_price); ?></span>
                  </label>
                </div>
              </div>
            </div>
          <?php else: ?>
            <?php
              $single_ruling = !empty($product['ruling_option']) && $product['ruling_option'] !== 'Both' ? $product['ruling_option'] : 'Standard';
              if (strcasecmp($single_ruling, 'Micro Point') === 0) {
                  $single_ruling = 'Maths Plain';
              }
              $full_ruling_val = (!empty($product['pages']) ? $product['pages'] . ' Pages - ' : '') . $single_ruling;
            ?>
            <input type="hidden" name="ruling_type" value="<?php echo htmlspecialchars($full_ruling_val); ?>">
          <?php endif; ?>

          <!-- Order Type Selection: [ Bundle ] [ Single Piece ] -->
          <div class="product-order-card mb-3">
            <label class="form-label small fw-bold text-navy mb-2 d-block"><i class="bi bi-box-seam text-gold me-1"></i> Select Order Type:</label>
            <div class="btn-group w-100 mb-2" role="group">
              <button type="button" class="btn <?php echo $initial_order_type === 'Bundle' ? 'btn-navy text-white active' : 'btn-outline-navy'; ?> fw-bold btn-sm py-2" id="btnOrderTypeBundle" onclick="switchProductDetailOrderType('Bundle')">
                <i class="bi bi-box-seam me-1"></i> Bundle Order
              </button>
              <button type="button" class="btn <?php echo $initial_order_type === 'Single Piece' ? 'btn-navy text-white active' : 'btn-outline-navy'; ?> fw-bold btn-sm py-2" id="btnOrderTypePiece" onclick="switchProductDetailOrderType('Single Piece')">
                <i class="bi bi-file-earmark-text me-1"></i> Single Piece
              </button>
            </div>
            <input type="hidden" name="order_type" id="pdetailOrderTypeInput" value="<?php echo htmlspecialchars($initial_order_type); ?>">

            <!-- Bundle Quantity Panel with Dynamic Piece Calculation -->
            <div id="pdetailBundlePanel" class="p-2.5 bg-white rounded border <?php echo $initial_order_type === 'Bundle' ? '' : 'd-none'; ?>">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="small fw-bold text-navy">Bundle Quantity:</span>
              </div>
              <div class="d-flex align-items-center gap-2 mb-2">
                <div class="input-group input-group-sm" style="max-width: 140px;">
                  <button type="button" class="btn btn-outline-navy fw-bold px-2.5" onclick="adjustPdetailQty(-1)">-</button>
                  <input type="number" <?php echo $initial_order_type === 'Bundle' ? 'name="quantity"' : ''; ?> id="pdetailQtyInput" value="1" min="1" max="999999" class="form-control text-center fw-bold" oninput="recalcPdetailBundle()">
                  <button type="button" class="btn btn-outline-navy fw-bold px-2.5" onclick="adjustPdetailQty(1)">+</button>
                </div>
                <span class="small text-muted fw-semibold">Bundle(s)</span>
              </div>

              <!-- Dynamic Calculation Result Box -->
              <div class="bundle-calc-box p-2 rounded bg-light border border-warning-subtle text-center">
                <div class="d-flex justify-content-between align-items-center small mb-1" style="font-size: 0.775rem;">
                  <span class="text-secondary">Pieces per Bundle: <strong class="text-navy" id="pdetailPpbVal"><?php echo max(1, $detail_ppb); ?></strong></span>
                  <span class="text-secondary">Bundles Ordered: <strong class="text-navy" id="pdetailBundlesVal">1</strong></span>
                </div>
                <div class="fw-bold text-navy small py-1.5 px-2 rounded" style="background: #FCF8EF; border: 1px dashed #C89B3C;">
                  Total Quantity: <span class="text-primary fw-extrabold fs-6" id="pdetailTotalPiecesVal"><?php echo max(1, $detail_ppb); ?> Pieces</span>
                  <span class="text-muted fw-normal ms-1">(Total Bundles: <span id="pdetailTotalBundlesVal">1</span>)</span>
                </div>
                <div class="text-muted text-center mt-1 small" id="pdetailFormulaVal" style="font-size: 0.72rem;">
                  Formula: <?php echo max(1, $detail_ppb); ?> Pcs/Bundle &times; 1 Bundle = <?php echo max(1, $detail_ppb); ?> Pieces
                </div>
                <div class="text-navy text-center mt-1 fw-bold small" id="pdetailTotalBundlePriceVal">
                  Total Price: ₹<?php echo number_format($default_price * max(1, $detail_ppb), 2); ?>
                </div>
              </div>
            </div>

            <!-- Single Piece Quantity Panel (Hidden in Bundle Mode) -->
            <div id="pdetailPiecePanel" class="p-2.5 bg-white rounded border <?php echo $initial_order_type === 'Single Piece' ? '' : 'd-none'; ?>">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="small fw-bold text-navy">Piece Quantity:</span>
                <span class="small text-muted">Stock: <strong class="text-navy" id="pdetailPieceStock"><?php echo $detail_piece_stock; ?> Pieces</strong></span>
              </div>
              <div class="d-flex align-items-center gap-2">
                <div class="input-group input-group-sm" style="max-width: 140px;">
                  <button type="button" class="btn btn-outline-navy fw-bold px-2.5" onclick="adjustPdetailQty(-1)">-</button>
                  <input type="number" <?php echo $initial_order_type === 'Single Piece' ? 'name="quantity"' : ''; ?> id="pdetailPieceQtyInput" value="1" min="1" max="999999" class="form-control text-center fw-bold" oninput="recalcPdetailPiece()">
                  <button type="button" class="btn btn-outline-navy fw-bold px-2.5" onclick="adjustPdetailQty(1)">+</button>
                </div>
                <span class="small text-muted fw-semibold">Piece(s)</span>
              </div>
              <div class="text-navy text-center mt-2 fw-bold small" id="pdetailTotalSinglePriceVal">
                Total Price: ₹<?php echo number_format($default_price, 2); ?>
              </div>
            </div>
          </div>

          <div>
            <button type="submit" name="action" value="add" class="btn btn-gold btn-lg px-4 shadow-sm fw-bold product-cart-button js-add-to-cart-btn" <?php echo !empty($product['varieties_managed']) && empty($detail_variety_options) ? 'disabled' : ''; ?>><i class="bi bi-bag-plus me-2"></i> <?php echo !empty($product['varieties_managed']) && empty($detail_variety_options) ? 'No Varieties Available' : 'Add to Cart'; ?></button>
          </div>
        </form>

        <script>
        let pdetailQtyMemory = { 'Unruled': 1, 'Ruled': 1, 'Standard': 1 };
        let currentRulingKey = <?php echo json_encode($detail_ruling_default); ?>;
        let currentOrderType = '<?php echo $initial_order_type; ?>';
        let ppb = <?php echo max(1, (int)$detail_ppb); ?>;
        let activePiecePrice = <?php echo (float)$default_price; ?>;
        let activeStock = <?php echo json_encode($detail_default_stock); ?>;
        const productMrp = <?php echo (float)($product['mrp'] ?? 0); ?>;

        function switchProductDetailOrderType(type) {
            currentOrderType = type;
            const inputOrderType = document.getElementById('pdetailOrderTypeInput');
            if (inputOrderType) inputOrderType.value = type;

            const btnBundle = document.getElementById('btnOrderTypeBundle');
            const btnPiece = document.getElementById('btnOrderTypePiece');
            const bdlPanel = document.getElementById('pdetailBundlePanel');
            const pcsPanel = document.getElementById('pdetailPiecePanel');
            const qtyInput = document.getElementById('pdetailQtyInput');

            if (type === 'Bundle') {
                if (btnBundle) { btnBundle.className = 'btn btn-navy text-white fw-bold btn-sm py-2 active'; }
                if (btnPiece) { btnPiece.className = 'btn btn-outline-navy fw-bold btn-sm py-2'; }
                if (bdlPanel) bdlPanel.classList.remove('d-none');
                if (pcsPanel) pcsPanel.classList.add('d-none');
                if (qtyInput) qtyInput.name = 'quantity';
                const pInput = document.getElementById('pdetailPieceQtyInput');
                if (pInput) pInput.name = '';
                recalcPdetailBundle();
            } else {
                if (btnBundle) { btnBundle.className = 'btn btn-outline-navy fw-bold btn-sm py-2'; }
                if (btnPiece) { btnPiece.className = 'btn btn-navy text-white fw-bold btn-sm py-2 active'; }
                if (bdlPanel) bdlPanel.classList.add('d-none');
                if (pcsPanel) pcsPanel.classList.remove('d-none');
                if (qtyInput) qtyInput.name = '';
                const pInput = document.getElementById('pdetailPieceQtyInput');
                if (pInput) pInput.name = 'quantity';
                recalcPdetailPiece();
            }
        }

        function adjustPdetailQty(delta) {
            const input = (currentOrderType === 'Bundle') ? document.getElementById('pdetailQtyInput') : document.getElementById('pdetailPieceQtyInput');
            if (!input) return;
            let val = parseInt(input.value || '1', 10) + delta;
            val = Math.min(999999, Math.max(1, val));
            input.value = val;
            if (currentOrderType === 'Bundle') {
                recalcPdetailBundle();
            } else {
                recalcPdetailPiece();
            }
        }

        function recalcPdetailBundle() {
            const input = document.getElementById('pdetailQtyInput');
            const bundles = Math.min(999999, Math.max(1, parseInt(input?.value || '1', 10) || 1));
            if (input) input.value = bundles;

            const totalPieces = ppb * bundles;
            const bundlePrice = activePiecePrice * ppb;
            const totalPrice = bundlePrice * bundles;

            const bdlVal = document.getElementById('pdetailBundlesVal');
            if (bdlVal) bdlVal.textContent = bundles;

            const totPiecesVal = document.getElementById('pdetailTotalPiecesVal');
            if (totPiecesVal) totPiecesVal.textContent = totalPieces.toLocaleString() + ' Pieces';

            const totBdlVal = document.getElementById('pdetailTotalBundlesVal');
            if (totBdlVal) totBdlVal.textContent = bundles;

            const formulaVal = document.getElementById('pdetailFormulaVal');
            if (formulaVal) formulaVal.innerHTML = `Formula: ${ppb} Pcs/Bundle &times; ${bundles} Bundle${bundles > 1 ? 's' : ''} = ${totalPieces.toLocaleString()} Pieces`;

            const totPriceVal = document.getElementById('pdetailTotalBundlePriceVal');
            if (totPriceVal) totPriceVal.textContent = `Total Price: ₹${totalPrice.toFixed(2)} (${bundles} Bundle${bundles > 1 ? 's' : ''} @ ₹${bundlePrice.toFixed(2)}/Bdl)`;
        }

        function recalcPdetailPiece() {
            const input = document.getElementById('pdetailPieceQtyInput');
            const pieces = Math.min(999999, Math.max(1, parseInt(input?.value || '1', 10) || 1));
            if (input) input.value = pieces;
            const totalPrice = activePiecePrice * pieces;

            const totPriceVal = document.getElementById('pdetailTotalSinglePriceVal');
            if (totPriceVal) totPriceVal.textContent = `Total Price: ₹${totalPrice.toFixed(2)} (${pieces} Pc${pieces > 1 ? 's' : ''} @ ₹${activePiecePrice.toFixed(2)}/Pc)`;
        }

        function switchRulingVariant(rulingKey, price, discPrice, stockFromInput) {
            currentRulingKey = rulingKey;
            activePiecePrice = (discPrice && discPrice < price) ? discPrice : price;
            if (stockFromInput) {
                activeStock = stockFromInput;
                ppb = Math.max(1, parseInt(activeStock.bundle_qty || '1', 10));
                const ppbDisplay = document.getElementById('pdetailPpbVal');
                if (ppbDisplay) ppbDisplay.textContent = ppb;
                const ppbBadgeWrap = document.getElementById('detailPpbBadgeWrap');
                if (ppbBadgeWrap) ppbBadgeWrap.classList.toggle('d-none', ppb <= 1);
                const ppbBadge = document.getElementById('detailPpbBadge');
                if (ppbBadge) ppbBadge.textContent = ppb;
                const ppbSpecRow = document.getElementById('detailPpbSpecRow');
                if (ppbSpecRow) ppbSpecRow.classList.toggle('d-none', ppb <= 1);
                const ppbSpec = document.getElementById('detailPpbSpec');
                if (ppbSpec) ppbSpec.textContent = ppb + ' Pcs / Bundle';
            }
            updateDetailStock();
            updatePrice(price, discPrice);
            if (currentOrderType === 'Bundle') {
                recalcPdetailBundle();
            } else {
                recalcPdetailPiece();
            }
        }

        function updateDetailStock() {
            const bundles = Math.max(0, parseInt(activeStock.bundle_stock || '0', 10));
            const pieces = Math.max(0, parseInt(activeStock.stock || '0', 10));
            const bundleLabel = document.getElementById('pdetailBundleStock');
            const pieceLabel = document.getElementById('pdetailPieceStock');
            const bundleInput = document.getElementById('pdetailQtyInput');
            const pieceInput = document.getElementById('pdetailPieceQtyInput');
            const bundleButton = document.getElementById('btnOrderTypeBundle');
            const pieceButton = document.getElementById('btnOrderTypePiece');
            const addButton = document.querySelector('#productDetailsForm .js-add-to-cart-btn');
            const bundleStatus = document.getElementById('detailBundleStockStatus');
            const pieceStatus = document.getElementById('detailSingleStockStatus');
            const bundleSpec = document.getElementById('detailBundleStockSpec');

            if (bundleLabel) bundleLabel.textContent = bundles + ' Bundles';
            if (pieceLabel) pieceLabel.textContent = pieces + ' Pieces';
            if (bundleInput) {
                bundleInput.max = 999999;
                bundleInput.value = Math.min(999999, Math.max(1, parseInt(bundleInput.value || '1', 10)));
            }
            if (pieceInput) {
                pieceInput.max = 999999;
                pieceInput.value = Math.min(999999, Math.max(1, parseInt(pieceInput.value || '1', 10)));
            }
            if (bundleStatus) {
                bundleStatus.textContent = bundles > 0 ? 'In Stock (' + bundles + ' Bundles)' : 'Available for order';
                bundleStatus.className = 'text-success fw-bold';
            }
            if (pieceStatus) {
                pieceStatus.textContent = pieces > 0 ? 'In Stock (' + pieces + ' Pcs)' : 'Available for order';
                pieceStatus.className = 'text-success fw-bold';
            }
            if (bundleSpec) {
                bundleSpec.innerHTML = '<i class="bi bi-box-seam me-1"></i>' + (bundles > 0 ? 'In Stock (' + bundles + ' Bundles)' : 'Available for order');
                bundleSpec.className = 'badge bg-success-subtle text-success border border-success-subtle';
            }
            if (currentOrderType === 'Bundle' && bundles <= 0 && pieces > 0) {
                switchProductDetailOrderType('Single Piece');
            } else if (currentOrderType === 'Single Piece' && pieces <= 0 && bundles > 0) {
                switchProductDetailOrderType('Bundle');
            }
        }

        function updatePrice(price, discPrice) {
            const effectivePrice = discPrice && discPrice < price ? discPrice : price;
            const comparePrice = productMrp > effectivePrice ? productMrp : price;
            document.getElementById('displayedPrice').innerText = '₹' + effectivePrice.toFixed(2);
            const comparePriceElement = document.getElementById('comparePrice');
            if (comparePriceElement) {
                comparePriceElement.textContent = '₹' + comparePrice.toFixed(2);
                comparePriceElement.classList.toggle('d-none', comparePrice <= effectivePrice);
            }
            const discBadge = document.getElementById('discountBadge');
            if (discBadge) {
                discBadge.classList.toggle('d-none', comparePrice <= effectivePrice);
                const discountText = document.getElementById('discountText');
                if (discountText && comparePrice > effectivePrice) {
                    discountText.textContent = 'Save ' + Math.round(((comparePrice - effectivePrice) / comparePrice) * 100) + '%';
                }
            }
            const specRegularPrice = document.getElementById('specRegularPrice');
            if (specRegularPrice) specRegularPrice.textContent = '₹' + price.toFixed(2);
            const specDiscountPrice = document.getElementById('specDiscountPrice');
            if (specDiscountPrice) {
                specDiscountPrice.textContent = discPrice && discPrice < price ? 'Discounted: ₹' + discPrice.toFixed(2) : '';
                specDiscountPrice.classList.toggle('d-none', !(discPrice && discPrice < price));
            }
        }
        </script>

        <!-- Product Specifications Table -->
        <div class="product-spec-card mb-4">
          <h5 class="fw-bold text-navy mb-3 fs-6"><i class="bi bi-list-stars text-gold me-2"></i> Product Specifications & Price List</h5>
          <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0 align-middle">
              <tbody>
                <?php if (!empty($product['brand_name'])): ?>
                  <tr><th class="bg-light" style="width: 35%; min-width: 130px;">Brand Name</th><td><strong class="text-navy"><?php echo htmlspecialchars($product['brand_name']); ?></strong></td></tr>
                <?php endif; ?>
                <tr><th class="bg-light" style="width: 35%; min-width: 130px;">Pages</th><td><?php echo htmlspecialchars($product['pages']); ?> Pages</td></tr>
                <?php if ($detail_ppb > 1 || !empty($detail_variety_options)): ?>
                  <tr id="detailPpbSpecRow" class="<?php echo $detail_ppb > 1 ? '' : 'd-none'; ?>"><th class="bg-light">Selected Variety Bundle Qty</th><td><span class="badge bg-gold-subtle text-navy border border-warning-subtle"><i class="bi bi-box-seam me-1"></i><span id="detailPpbSpec"><?php echo $detail_ppb; ?> Pcs / Bundle</span></span></td></tr>
                <?php endif; ?>
                <?php if (!empty($product['mrp'])): ?>
                  <tr><th class="bg-light">MRP</th><td><span class="text-muted text-decoration-line-through fw-bold"><?php echo format_price($product['mrp']); ?></span></td></tr>
                <?php endif; ?>
                <tr>
                  <th class="bg-light">Selected Variety Price</th>
                  <td>
                    <strong class="text-navy" id="specRegularPrice"><?php echo format_price($default_regular_price); ?></strong>
                    <span class="badge bg-success-subtle text-success border border-success-subtle ms-1 <?php echo $default_disc > 0 && $default_disc < $default_regular_price ? '' : 'd-none'; ?>" id="specDiscountPrice"><?php echo $default_disc > 0 && $default_disc < $default_regular_price ? 'Discounted: ' . format_price($default_disc) : ''; ?></span>
                  </td>
                </tr>
                <?php
                  $specification_varieties = !empty($detail_variety_options)
                      ? array_column($detail_variety_options, 'name')
                      : explode(',', extract_product_variety($product));
                  $specification_varieties = array_filter(array_map('trim', $specification_varieties), static function ($variety) {
                      return $variety !== '' && stripos($variety, 'GST') === false;
                  });
                ?>
                <tr><th class="bg-light">Varieties</th><td><strong class="text-navy"><?php echo htmlspecialchars(implode(', ', $specification_varieties)); ?></strong></td></tr>
                <tr><th class="bg-light">Notebook Size</th><td><?php echo htmlspecialchars($product['size']); ?></td></tr>
                <tr><th class="bg-light">Paper Type</th><td><?php echo htmlspecialchars($product['paper_type']); ?></td></tr>
                <tr><th class="bg-light">GSM</th><td><strong><?php echo htmlspecialchars($product['gsm']); ?> GSM</strong></td></tr>
                <tr><th class="bg-light">Binding Type</th><td><?php echo htmlspecialchars($product['binding']); ?></td></tr>
                <tr><th class="bg-light">Cover Finish</th><td><?php echo htmlspecialchars($product['cover_type']); ?></td></tr>
              </tbody>
            </table>
          </div>
        </div>

      </div>
    </div>
  </div>



  <!-- Related Products -->
  <?php if (!empty($related_products)): ?>
    <div class="mt-5">
      <h4 class="fw-bold text-navy mb-4">Related Notebooks</h4>
      <div class="row g-3 row-cols-1 row-cols-sm-2 row-cols-md-4">
        <?php 
          $grouped_related = group_products_by_base($related_products);
          foreach ($grouped_related as $rel_item): 
        ?>
          <div class="col d-flex align-items-stretch">
            <?php echo render_product_card($rel_item); ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
