<?php
require_once __DIR__ . '/includes/functions.php';

$items = get_cart_contents();
$totals = calculate_cart_totals();

// Pre-calculate wholesale piece count and bundle/piece breakdown for Cart Summary
$cart_total_pcs = 0;
$total_bundles_count = 0;
$total_pieces_count = 0;

foreach ($items as $item) {
    $vInfo = parse_variety_info($item['ruling_type'] ?? '', $item['pages'] ?? '');
    $orderTypeDisplay = !empty($vInfo['order_type']) ? $vInfo['order_type'] : 'Single Piece';
    $isBundle = (stripos($orderTypeDisplay, 'Bundle') !== false);
    
    $pcs_per_bundle = 1;
    if ($isBundle) {
        $pcs_per_bundle = get_product_pieces_per_bundle($item['id'], $item['ruling_type'] ?? '', $item);
    }
    
    $qty = max(1, (int)$item['quantity']);
    if ($isBundle) {
        $cart_total_pcs += ($pcs_per_bundle * $qty);
        $total_bundles_count += $qty;
    } else {
        $cart_total_pcs += $qty;
        $total_pieces_count += $qty;
    }
}
unset($item);

// Summary label for items / bundles
if ($total_bundles_count > 0 && $total_pieces_count > 0) {
    $items_summary_text = $total_bundles_count . ' ' . ($total_bundles_count === 1 ? 'Bundle' : 'Bundles') . ', ' . $total_pieces_count . ' ' . ($total_pieces_count === 1 ? 'Piece' : 'Pieces');
} elseif ($total_bundles_count > 0) {
    $items_summary_text = $total_bundles_count . ' ' . ($total_bundles_count === 1 ? 'Bundle' : 'Bundles');
} else {
    $items_summary_text = $total_pieces_count . ' ' . ($total_pieces_count === 1 ? 'Piece' : 'Pieces');
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
/* Scoped Wholesale Cart Page Styles */
.cart-page-wrap {
  color: var(--color-dark-brown, #2B2520);
}
.cart-item-card {
  background: #ffffff;
  border: 1px solid #E2E8F0;
  border-radius: 12px;
  padding: 1.25rem;
  margin-bottom: 1.25rem;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
  box-shadow: 0 1px 3px rgba(23, 36, 58, 0.04);
}
.cart-item-card:hover {
  border-color: #CBD5E1;
  box-shadow: 0 4px 12px rgba(23, 36, 58, 0.06);
}
.cart-item-card:last-child {
  margin-bottom: 0;
}
.cart-item-img {
  width: 72px;
  height: 72px;
  object-fit: contain;
  background-color: #FAF5EB;
  border: 1px solid #E6DCB8;
  border-radius: 8px;
  padding: 4px;
  flex-shrink: 0;
}
.cart-item-title {
  font-family: var(--font-heading, inherit);
  font-size: 1.05rem;
  font-weight: 700;
  color: #17243A;
  line-height: 1.35;
  transition: color 0.2s ease;
}
.cart-item-title:hover {
  color: #C89B3C;
}

/* Wholesale Pricing Breakdown Grid */
.wholesale-pricing-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
  gap: 0.75rem;
  background: #F8FAFC;
  border: 1px solid #E2E8F0;
  border-radius: 10px;
  padding: 0.85rem 1rem;
  margin-top: 1rem;
  align-items: center;
}
.pricing-metric-cell {
  display: flex;
  flex-direction: column;
}
.metric-col-label {
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: #64748B;
  margin-bottom: 0.25rem;
}
.metric-col-val {
  font-size: 0.975rem;
  font-weight: 700;
  color: #17243A;
  line-height: 1.25;
}
.metric-col-sub {
  font-size: 0.7rem;
  color: #94A3B8;
  margin-top: 0.15rem;
}

/* Quantity Control */
.cart-qty-control {
  max-width: 120px;
}
.cart-qty-btn {
  width: 28px;
  height: 28px;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 0.85rem;
  border-color: #CBD5E1;
  color: #17243A;
  background-color: #FFFFFF;
}
.cart-qty-btn:hover {
  background-color: #F1F5F9;
  border-color: #94A3B8;
  color: #17243A;
}
.cart-qty-input {
  height: 28px;
  font-size: 0.875rem;
  font-weight: 700;
  color: #17243A;
  border-color: #CBD5E1;
  padding: 0 4px;
}

/* Calculation Breakdown Strip */
.wholesale-calc-strip {
  background: #FCF8EF;
  border: 1px dashed #E6DCB8;
  border-radius: 8px;
  padding: 0.5rem 0.85rem;
  margin-top: 0.75rem;
  font-size: 0.775rem;
  color: #17243A;
}
.calc-pill {
  background: #FFFFFF;
  border: 1px solid #E2E8F0;
  border-radius: 4px;
  padding: 2px 6px;
  font-weight: 700;
  color: #17243A;
  display: inline-block;
}
.calc-operator {
  color: #C89B3C;
  font-weight: 800;
  margin: 0 2px;
}

/* Summary Card */
.cart-summary-card {
  background: #FFFFFF;
  border: 1px solid #E2E8F0;
  border-radius: 12px;
  padding: 1.5rem;
  box-shadow: 0 1px 3px rgba(23, 36, 58, 0.04);
}
.summary-stat-box {
  background: #F8FAFC;
  border: 1px solid #E2E8F0;
  border-radius: 8px;
  padding: 0.85rem 1rem;
}

/* Mobile Responsiveness */
@media (max-width: 767.98px) {
  .cart-item-card {
    padding: 1rem;
  }
  .cart-item-img {
    width: 60px;
    height: 60px;
  }
  .wholesale-pricing-grid {
    grid-template-columns: 1fr 1fr;
    gap: 0.85rem 0.65rem;
    padding: 0.75rem;
  }
  .wholesale-pricing-grid .pricing-metric-cell:last-child {
    grid-column: span 2;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 6px;
    padding: 0.5rem 0.75rem;
    flex-direction: row;
    justify-content: space-between;
    align-items: center;
  }
  .wholesale-calc-strip {
    font-size: 0.725rem;
    padding: 0.5rem 0.65rem;
  }
}

@media (max-width: 480px) {
  .cart-item-card {
    padding: 0.85rem;
  }
  .cart-item-title {
    font-size: 0.95rem;
  }
  .wholesale-pricing-grid {
    gap: 0.65rem 0.5rem;
    padding: 0.65rem;
  }
  .metric-col-val {
    font-size: 0.875rem;
  }
  .cart-qty-control {
    max-width: 105px;
  }
  .cart-qty-btn {
    width: 26px;
    height: 26px;
    font-size: 0.8rem;
  }
  .cart-qty-input {
    height: 26px;
    font-size: 0.8rem;
  }
}
</style>

<div class="container section-padding py-4 cart-page-wrap">
  <?php echo render_page_navigation('Shopping Cart (' . count($items) . ' Items)', ['Cart' => '']); ?>

  <?php if (empty($items)): ?>
    <div class="text-center py-5 bg-white rounded-3 border">
      <i class="bi bi-bag-x fs-1 text-muted mb-3 d-block"></i>
      <h4>Your Shopping Cart is Empty</h4>
      <p class="text-secondary">Explore our premium notebook collection and add items to your cart.</p>
      <a href="products.php" class="btn btn-gold mt-2">Explore Notebooks <i class="bi bi-arrow-right ms-1"></i></a>
    </div>
  <?php else: ?>
    <div class="row g-4">
      
      <!-- Cart Items List -->
      <div class="col-lg-8">
        <div class="mb-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
          <h5 class="fw-bold text-navy mb-0 js-cart-items-heading">
            <i class="bi bi-cart3 text-gold me-2"></i>Cart Items (<?php echo count($items); ?>)
          </h5>
          <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold px-2.5 py-1 js-total-pcs-header-badge">
            <i class="bi bi-stack me-1 text-gold"></i>Total <?php echo number_format($cart_total_pcs); ?> Physical Pieces
          </span>
        </div>

        <?php foreach ($items as $item): 
          $vInfo = parse_variety_info($item['ruling_type'] ?? '', $item['pages'] ?? '');
          
          $orderTypeDisplay = !empty($vInfo['order_type']) ? $vInfo['order_type'] : 'Single Piece';
          $isBundle = (stripos($orderTypeDisplay, 'Bundle') !== false);
          
          // 1. Single Piece Seller Price (Retrieved cleanly from product data / database)
          $seller_piece_price = get_product_single_piece_price(
              $item,
              $item['ruling_type'] ?? '',
              (int)($item['variant_id'] ?? 0),
              $item['cart_product_code'] ?? $item['product_code'] ?? ''
          );
          if ($seller_piece_price <= 0) {
            $seller_piece_price = !empty($item['price']) ? (float)$item['price'] : 0.0;
          }
          
          // 2. PCS per Bundle
          $pcs_per_bundle = 1;
          if ($isBundle) {
            $pcs_per_bundle = get_product_pieces_per_bundle($item['id'], $item['ruling_type'] ?? '', $item, (int)($item['variant_id'] ?? 0));
            if ($pcs_per_bundle < 1) {
              $pcs_per_bundle = 1;
            }
          }
          
          // 3. Quantity (Number of Bundles or Pieces)
          $quantity = max(1, (int)($item['quantity'] ?? 1));
          
          // 4. Total Physical Pieces
          $total_pcs = $isBundle ? ($pcs_per_bundle * $quantity) : $quantity;
          
          // 5. Wholesale / Pricing Calculations
          if ($isBundle) {
            // Bundle Price = Single Piece Seller Price × Pieces Per Bundle
            $bundle_price = round($seller_piece_price * $pcs_per_bundle, 2);
            $effective_price = $bundle_price;
            // Total Price = Single Piece Seller Price × Pieces Per Bundle × Bundle Quantity
            $item_total = round($bundle_price * $quantity, 2);
          } else {
            // Single-piece price = Single Piece Seller Price
            $single_piece_price = $seller_piece_price;
            $effective_price = $seller_piece_price;
            // Total Price = Seller Price per Piece × Piece Quantity
            $item_total = round($seller_piece_price * $quantity, 2);
          }
          
          // Ensure $item['effective_price'] is consistently set before any usage
          $item['effective_price'] = $effective_price;
          
          // Stock limit for +/- control
          $cart_stock_record = get_product_variant_stock_record(
            getDB(),
            $item['id'],
            $item['product_code'] ?? '',
            $item['ruling_type'] ?? '',
            $item,
            false,
            (int)($item['variant_id'] ?? 0)
          );
          $max_qty = max(0, (int)($cart_stock_record['stock'] ?? 0));
          if ($isBundle) {
            $max_qty = max(0, (int)($cart_stock_record['bundle_stock'] ?? 0));
          }
        ?>
          <div class="cart-item-card" id="cart-item-card-<?php echo $item['item_id']; ?>" data-item-id="<?php echo $item['item_id']; ?>">
            <!-- Product Header Row -->
            <div class="d-flex align-items-start justify-content-between gap-3">
              <div class="d-flex align-items-start gap-3 flex-grow-1">
                <img src="<?php echo htmlspecialchars($item['image']); ?>" alt="<?php echo htmlspecialchars($item['name']); ?>" class="cart-item-img">
                <div>
                  <a href="product-details.php?id=<?php echo $item['id']; ?>" class="cart-item-title text-decoration-none d-block">
                    <?php echo htmlspecialchars($item['name']); ?>
                  </a>

                  <!-- Product Meta Info (Pages & Size) -->
                  <div class="text-muted small mt-1 d-flex flex-wrap align-items-center gap-2">
                    <span class="fw-bold text-navy">
                      <i class="bi bi-file-earmark-text text-gold me-1"></i><?php echo htmlspecialchars(!empty($vInfo['page_count']) ? $vInfo['page_count'] : ($item['pages'] . ' Pages')); ?>
                    </span>
                    <?php if (!empty($item['size'])): ?>
                      <span class="text-muted">&bull;</span>
                      <span><?php echo htmlspecialchars($item['size']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($item['product_code'])): ?>
                      <span class="text-muted">&bull;</span>
                      <span class="font-monospace text-secondary"><i class="bi bi-barcode me-1 text-gold"></i><?php echo htmlspecialchars($item['product_code']); ?></span>
                    <?php endif; ?>
                  </div>

                  <!-- Variety & Order Type Badges -->
                  <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                    <span class="badge" style="background-color: #FEF3C7; color: #92400E; border: 1px solid #F59E0B; font-weight: 700; font-size: 0.775rem; padding: 4px 9px;">
                      Variety: <?php echo htmlspecialchars($vInfo['variety']); ?>
                    </span>
                    <?php if ($isBundle): ?>
                      <span class="badge" style="background-color: #E0F2FE; color: #0369A1; border: 1px solid #38BDF8; font-weight: 700; font-size: 0.775rem; padding: 4px 9px;">
                        <i class="bi bi-box-seam me-1"></i>Order Type: Bundle
                      </span>
                    <?php else: ?>
                      <span class="badge" style="background-color: #ECFDF5; color: #065F46; border: 1px solid #34D399; font-weight: 700; font-size: 0.775rem; padding: 4px 9px;">
                        <i class="bi bi-file-earmark me-1"></i>Order Type: Single Piece
                      </span>
                    <?php endif; ?>
                    <span class="badge" style="background-color: #F1F5F9; color: #475569; border: 1px solid #CBD5E1; font-weight: 700; font-size: 0.775rem; padding: 4px 9px;">
                      <i class="bi bi-percent me-1"></i>GST: <?php echo (float)($item['gst_rate'] ?? 18); ?>%
                    </span>
                  </div>
                </div>
              </div>

              <!-- Remove Item Button (Immediate removal, no popup or confirm dialog) -->
              <a href="cart-action.php?action=remove&item_id=<?php echo $item['item_id']; ?>" class="btn btn-sm btn-outline-danger border-0 p-1.5 js-remove-cart-item-btn" title="Remove Item" onclick="removeCartItemImmediately(event, this, <?php echo $item['item_id']; ?>);">
                <i class="bi bi-trash fs-5"></i>
              </a>
            </div>

            <!-- Wholesale Pricing Breakdown Grid -->
            <div class="wholesale-pricing-grid">
              
              <?php if ($isBundle): ?>
                <!-- 1. Seller Price (Per Piece) -->
                <div class="pricing-metric-cell">
                  <span class="metric-col-label">Seller Price (Per Piece)</span>
                  <span class="metric-col-val text-navy fw-bold">
                    <?php echo format_price($seller_piece_price); ?>
                  </span>
                  <span class="metric-col-sub">Per Piece</span>
                </div>

                <!-- 2. Pieces per Bundle -->
                <div class="pricing-metric-cell">
                  <span class="metric-col-label">Pieces per Bundle</span>
                  <span class="metric-col-val text-navy">
                    <?php echo number_format($pcs_per_bundle); ?> PCS
                  </span>
                  <span class="metric-col-sub">Pack Size</span>
                </div>

                <!-- 3. Bundle Quantity -->
                <div class="pricing-metric-cell">
                  <span class="metric-col-label">Bundle Quantity</span>
                  <form action="cart-action.php" method="POST" class="m-0 d-inline-block">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                    <div class="input-group input-group-sm cart-qty-control">
                      <button type="button" class="btn cart-qty-btn rounded-start" title="Decrease" onclick="let input=this.nextElementSibling; let cur=parseInt(input.value)||1; if(cur>1){ input.value=cur-1; this.form.submit(); }">-</button>
                      <input type="number" name="quantity" value="<?php echo $quantity; ?>" min="1" max="<?php echo max(1, $max_qty); ?>" class="form-control text-center cart-qty-input" onchange="this.form.submit()">
                      <button type="button" class="btn cart-qty-btn rounded-end" title="Increase" onclick="let input=this.previousElementSibling; let cur=parseInt(input.value)||1; let max=parseInt(input.max)||99999; if(cur<max){ input.value=cur+1; this.form.submit(); }">+</button>
                    </div>
                  </form>
                  <span class="metric-col-sub">[-] Bundles [+]</span>
                </div>

                <!-- 4. Total Pieces -->
                <div class="pricing-metric-cell">
                  <span class="metric-col-label">Total Pieces</span>
                  <span class="metric-col-val text-primary fw-extrabold">
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                      <?php echo number_format($total_pcs); ?> PCS
                    </span>
                  </span>
                  <span class="metric-col-sub">Physical Pieces</span>
                </div>

                <!-- 5. Total Price -->
                <div class="pricing-metric-cell">
                  <span class="metric-col-label">Total Price</span>
                  <span class="metric-col-val text-navy fs-6 fw-bold">
                    <?php echo format_price($item_total); ?>
                  </span>
                  <span class="metric-col-sub">Subtotal</span>
                </div>

              <?php else: ?>
                <!-- 1. Seller Price per Piece -->
                <div class="pricing-metric-cell">
                  <span class="metric-col-label">Seller Price (Per Piece)</span>
                  <span class="metric-col-val text-navy fw-bold">
                    <?php echo format_price($seller_piece_price); ?>
                  </span>
                  <span class="metric-col-sub">Per Piece</span>
                </div>

                <!-- 2. Single Piece Price -->
                <div class="pricing-metric-cell">
                  <span class="metric-col-label">Single Piece Price</span>
                  <span class="metric-col-val text-success fw-bold">
                    <?php echo format_price($seller_piece_price); ?>
                  </span>
                  <span class="metric-col-sub">Unit Price</span>
                </div>

                <!-- 3. Piece Quantity -->
                <div class="pricing-metric-cell">
                  <span class="metric-col-label">Piece Quantity</span>
                  <form action="cart-action.php" method="POST" class="m-0 d-inline-block">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="item_id" value="<?php echo $item['item_id']; ?>">
                    <div class="input-group input-group-sm cart-qty-control">
                      <button type="button" class="btn cart-qty-btn rounded-start" title="Decrease" onclick="let input=this.nextElementSibling; let cur=parseInt(input.value)||1; if(cur>1){ input.value=cur-1; this.form.submit(); }">-</button>
                      <input type="number" name="quantity" value="<?php echo $quantity; ?>" min="1" max="<?php echo max(1, $max_qty); ?>" class="form-control text-center cart-qty-input" onchange="this.form.submit()">
                      <button type="button" class="btn cart-qty-btn rounded-end" title="Increase" onclick="let input=this.previousElementSibling; let cur=parseInt(input.value)||1; let max=parseInt(input.max)||99999; if(cur<max){ input.value=cur+1; this.form.submit(); }">+</button>
                    </div>
                  </form>
                  <span class="metric-col-sub">[-] Pieces [+]</span>
                </div>

                <!-- 4. Total Pieces -->
                <div class="pricing-metric-cell">
                  <span class="metric-col-label">Total Pieces</span>
                  <span class="metric-col-val text-primary fw-extrabold">
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                      <?php echo number_format($total_pcs); ?> PCS
                    </span>
                  </span>
                  <span class="metric-col-sub">Physical Pieces</span>
                </div>

                <!-- 5. Total Price -->
                <div class="pricing-metric-cell">
                  <span class="metric-col-label">Total Price</span>
                  <span class="metric-col-val text-navy fs-6 fw-bold">
                    <?php echo format_price($item_total); ?>
                  </span>
                  <span class="metric-col-sub">Subtotal</span>
                </div>
              <?php endif; ?>

            </div>

            <!-- Clear Calculation Formula Ribbon -->
            <div class="wholesale-calc-strip d-flex flex-wrap align-items-center justify-content-between gap-2">
              <div class="d-flex align-items-center flex-wrap gap-1">
                <span class="text-secondary fw-semibold me-1">
                  <i class="bi bi-calculator text-gold me-1"></i>Calculation:
                </span>
                <?php if ($isBundle): ?>
                  <span class="calc-pill"><?php echo format_price($seller_piece_price); ?> / Piece</span>
                  <span class="calc-operator">&times;</span>
                  <span class="calc-pill"><?php echo number_format($pcs_per_bundle); ?> PCS/Bundle</span>
                  <?php if ($quantity > 1): ?>
                    <span class="calc-operator">&times;</span>
                    <span class="calc-pill"><?php echo $quantity; ?> Bundles</span>
                  <?php endif; ?>
                  <span class="calc-operator">=</span>
                  <span class="calc-pill text-primary" style="background: #EFF6FF; border-color: #BFDBFE;">
                    <strong><?php echo format_price($item_total); ?> Total</strong>
                  </span>
                <?php else: ?>
                  <span class="calc-pill"><?php echo format_price($seller_piece_price); ?> / Piece</span>
                  <span class="calc-operator">&times;</span>
                  <span class="calc-pill"><?php echo $quantity; ?> <?php echo $quantity === 1 ? 'Piece' : 'Pieces'; ?></span>
                  <span class="calc-operator">=</span>
                  <span class="calc-pill text-primary" style="background: #EFF6FF; border-color: #BFDBFE;">
                    <strong><?php echo format_price($item_total); ?> Total</strong>
                  </span>
                <?php endif; ?>
              </div>
              <div class="fw-bold text-navy">
                Item Total: <span class="text-gold fs-6 ms-1"><?php echo format_price($item_total); ?></span>
              </div>
            </div>

          </div>
        <?php endforeach; ?>
        <?php unset($item); ?>

        <div class="d-flex justify-content-between align-items-center mt-3">
          <a href="products.php" class="btn btn-outline-gold btn-sm"><i class="bi bi-arrow-left me-1"></i> Continue Shopping</a>
        </div>
      </div>

      <!-- Right Column: Cart Summary & Checkout -->
      <div class="col-lg-4">

        <!-- CART SUMMARY Card -->
        <div class="cart-summary-card">
          <div class="d-flex align-items-center justify-content-between border-bottom pb-3 mb-3">
            <h5 class="fw-bold text-navy mb-0">CART SUMMARY</h5>
            <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold px-2 py-1" style="font-size: 0.725rem;">
              <i class="bi bi-patch-check-fill text-gold me-1"></i>Wholesale Order
            </span>
          </div>

          <!-- Total Items & Total PCS Metric Box -->
          <div class="summary-stat-box mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="text-secondary small fw-semibold"><i class="bi bi-boxes text-navy me-1.5"></i>Total Items:</span>
              <strong class="text-navy js-summary-total-items"><?php echo htmlspecialchars($items_summary_text); ?></strong>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-2 border-top border-light-subtle">
              <span class="text-secondary small fw-semibold"><i class="bi bi-stack text-gold me-1.5"></i>Total PCS:</span>
              <span class="badge bg-navy text-white fw-bold px-2.5 py-1 fs-6 js-summary-total-pcs"><?php echo number_format($cart_total_pcs); ?> PCS</span>
            </div>
          </div>

          <!-- Financial Breakdown -->
          <div class="d-flex justify-content-between mb-2">
            <span class="text-secondary">Subtotal / Taxable Amount</span>
            <span class="fw-bold text-navy js-summary-subtotal"><?php echo format_price($totals['taxable_amount']); ?></span>
          </div>

          <div class="d-flex justify-content-between mb-2 text-success js-summary-discount-row" style="<?php echo ($totals['discount'] > 0) ? '' : 'display: none;'; ?>">
            <span><i class="bi bi-tag-fill me-1"></i>Coupon Discount</span>
            <span class="fw-bold js-summary-discount">-<?php echo format_price($totals['discount']); ?></span>
          </div>

          <!-- Dynamic GST Breakdown Container -->
          <div class="js-summary-gst-container">
            <?php if (!empty($totals['has_multiple_rates'])): ?>
              <div class="my-2 p-2.5 bg-light rounded border border-light-subtle">
                <div class="small fw-bold text-navy mb-1.5 border-bottom pb-1"><i class="bi bi-pie-chart text-gold me-1"></i> GST Breakdown:</div>
                <?php foreach ($totals['rates_breakdown'] as $r): ?>
                  <div class="d-flex justify-content-between small text-dark mb-1">
                    <span class="fw-semibold"><?php echo htmlspecialchars($r['rate_label']); ?></span>
                    <span class="fw-bold text-navy"><?php echo format_price($r['gst_amount']); ?></span>
                  </div>
                  <div class="d-flex justify-content-between text-muted ps-2 mb-1" style="font-size: 0.75rem;">
                    <span>&bull; <?php echo htmlspecialchars($r['cgst_rate_label']); ?> / <?php echo htmlspecialchars($r['sgst_rate_label']); ?></span>
                    <span><?php echo format_price($r['cgst_amount']); ?> + <?php echo format_price($r['sgst_amount']); ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="d-flex justify-content-between mb-2 py-1 px-2 rounded bg-light border border-light-subtle">
                <span class="text-secondary small fw-bold">Total GST</span>
                <span class="fw-bold text-navy js-summary-total-gst"><?php echo format_price($totals['total_gst']); ?></span>
              </div>
            <?php else: ?>
              <?php $single_rate = !empty($totals['rates_breakdown']) ? reset($totals['rates_breakdown']) : null; ?>
              <div class="d-flex justify-content-between mb-1.5 small">
                <span class="text-secondary">CGST (<span class="js-summary-cgst-pct"><?php echo $single_rate ? $single_rate['cgst_rate'] : 9; ?></span>%)</span>
                <span class="fw-semibold text-navy js-summary-cgst"><?php echo format_price($totals['cgst']); ?></span>
              </div>
              <div class="d-flex justify-content-between mb-1.5 small">
                <span class="text-secondary">SGST (<span class="js-summary-sgst-pct"><?php echo $single_rate ? $single_rate['sgst_rate'] : 9; ?></span>%)</span>
                <span class="fw-semibold text-navy js-summary-sgst"><?php echo format_price($totals['sgst']); ?></span>
              </div>
              <div class="d-flex justify-content-between mb-2 py-1 px-2 rounded bg-light border border-light-subtle">
                <span class="text-secondary small fw-bold">Total GST (<span class="js-summary-total-gst-pct"><?php echo $single_rate ? $single_rate['rate'] : 18; ?></span>%)</span>
                <span class="fw-bold text-navy js-summary-total-gst"><?php echo format_price($totals['total_gst']); ?></span>
              </div>
            <?php endif; ?>
          </div>

          <div class="d-flex justify-content-between mb-3">
            <span class="text-secondary">Delivery</span>
            <span class="fw-semibold text-navy js-summary-delivery">
              <?php echo ($totals['delivery_fee'] == 0) ? '<span class="badge bg-success-subtle text-success border border-success-subtle fw-bold px-2 py-0.5">FREE</span>' : format_price($totals['delivery_fee']); ?>
            </span>
          </div>

          <div class="alert alert-warning py-2 px-3 small mb-3 border border-warning-subtle rounded-3 js-summary-free-shipping-alert" style="<?php echo ($totals['subtotal'] < get_setting('free_shipping_threshold', 499) && $totals['subtotal'] > 0) ? '' : 'display: none;'; ?>">
            <i class="bi bi-truck text-gold me-1.5"></i> Add <strong class="js-free-shipping-remaining"><?php echo format_price(max(0, get_setting('free_shipping_threshold', 499) - $totals['subtotal'])); ?></strong> more for <strong>FREE Shipping!</strong>
          </div>

          <hr class="my-3">

          <div class="d-flex justify-content-between align-items-baseline mb-3">
            <span class="fw-bold text-navy fs-5">Grand Total</span>
            <span class="fw-extrabold text-gold fs-4 js-summary-grand-total"><?php echo format_price($totals['grand_total']); ?></span>
          </div>

          <div class="small text-muted py-1.5 px-2 bg-light rounded text-center mb-3 border border-light-subtle" style="font-size: 0.75rem;">
            <i class="bi bi-shield-check text-gold me-1"></i>GSTIN: <strong class="text-navy"><?php echo htmlspecialchars($totals['gstin']); ?></strong>
          </div>

          <?php if (is_admin_logged_in()): ?>
            <div class="alert alert-info py-2 px-3 mb-0 small text-center rounded-3 border">
              <i class="bi bi-shield-check text-gold me-1"></i> You are logged in as Admin. Customer checkout is reserved for customer accounts.
            </div>
          <?php elseif (is_customer_logged_in()): ?>
            <a href="checkout.php" class="btn btn-gold w-100 justify-content-center py-2.5 fs-6 fw-bold shadow-sm">
              Proceed to Checkout <i class="bi bi-arrow-right ms-1"></i>
            </a>
          <?php else: ?>
            <button type="button" class="btn btn-gold w-100 justify-content-center py-2.5 fs-6 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#loginRequiredModal">
              Proceed to Checkout <i class="bi bi-arrow-right ms-1"></i>
            </button>
          <?php endif; ?>

          <!-- Wholesale Trust Badges -->
          <div class="mt-4 pt-3 border-top text-muted small">
            <div class="d-flex align-items-center gap-2 mb-2">
              <i class="bi bi-shield-check text-success fs-5"></i>
              <span>Direct Mill Wholesale Pricing</span>
            </div>
            <div class="d-flex align-items-center gap-2">
              <i class="bi bi-check2-circle text-gold fs-5"></i>
              <span>100% Genuine Rajpriya Quality</span>
            </div>
          </div>

        </div>
      </div>

    </div>
  <?php endif; ?>
</div>

<?php if (!is_customer_logged_in() && !is_admin_logged_in()): ?>
<!-- Login Required Modal Popup -->
<div class="modal fade" id="loginRequiredModal" tabindex="-1" aria-labelledby="loginRequiredModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
      <!-- Modal Header -->
      <div class="modal-header border-0 text-white p-3.5 px-4" style="background: linear-gradient(135deg, #17243A 0%, #243552 100%);">
        <div class="d-flex align-items-center gap-2">
          <i class="bi bi-shield-lock-fill text-gold fs-5"></i>
          <h5 class="modal-title fw-bold text-white mb-0" id="loginRequiredModalLabel">Login Required</h5>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      
      <!-- Modal Body -->
      <div class="modal-body p-4 text-center">
        <div class="mx-auto mb-3 border border-gold border-2 rounded-circle d-flex align-items-center justify-content-center" style="width: 68px; height: 68px; background-color: #FCF8EF; color: #C89B3C; font-size: 1.85rem;">
          <i class="bi bi-person-lock"></i>
        </div>
        <h5 class="fw-bold text-navy mb-2 px-2" style="line-height: 1.4;">
          Please login to your account before proceeding to checkout.
        </h5>
        <p class="text-secondary small mb-0 px-3">
          Logging in allows you to save shipping addresses, view live delivery tracking, and manage your notebook orders effortlessly.
        </p>
      </div>
      
      <!-- Modal Footer -->
      <div class="modal-footer border-0 bg-light p-3 justify-content-center">
        <a href="login.php?redirect=checkout.php" class="btn btn-gold px-5 py-2 fw-bold rounded-pill shadow-sm text-uppercase" style="letter-spacing: 0.5px; min-width: 140px;">
          OK
        </a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function removeCartItemImmediately(event, btn, itemId) {
  if (event) {
    event.preventDefault();
  }
  
  const card = document.getElementById('cart-item-card-' + itemId) || (btn ? btn.closest('.cart-item-card') : null);
  
  if (card) {
    // Immediately collapse and fade out without any confirmation popup
    card.style.transition = 'opacity 0.2s ease, transform 0.2s ease, max-height 0.3s ease, margin 0.3s ease, padding 0.3s ease';
    card.style.opacity = '0';
    card.style.transform = 'scale(0.97)';
    card.style.maxHeight = card.offsetHeight + 'px';
    setTimeout(function() {
      card.style.maxHeight = '0';
      card.style.marginTop = '0';
      card.style.marginBottom = '0';
      card.style.paddingTop = '0';
      card.style.paddingBottom = '0';
      card.style.overflow = 'hidden';
      setTimeout(function() {
        if (card.parentNode) card.parentNode.removeChild(card);
      }, 300);
    }, 50);
  }

  // Asynchronous removal from backend cart
  fetch('cart-action.php?action=remove&item_id=' + encodeURIComponent(itemId) + '&ajax=1', {
    method: 'GET',
    headers: {
      'X-Requested-With': 'XMLHttpRequest'
    }
  })
  .then(function(res) {
    return res.json();
  })
  .then(function(data) {
    if (data && data.success) {
      if (data.items_count === 0) {
        // If all items removed, reload page to cleanly render empty cart state
        window.location.reload();
        return;
      }

      // 1. Update Cart Items count heading
      const heading = document.querySelector('.js-cart-items-heading');
      if (heading) {
        heading.innerHTML = '<i class="bi bi-cart3 text-gold me-2"></i>Cart Items (' + data.items_count + ')';
      }

      // 2. Update Header Total Physical Pieces Badge
      const headerPcsBadge = document.querySelector('.js-total-pcs-header-badge');
      if (headerPcsBadge) {
        headerPcsBadge.innerHTML = '<i class="bi bi-stack me-1 text-gold"></i>Total ' + Number(data.cart_total_pcs).toLocaleString() + ' Physical Pieces';
      }

      // 3. Update navbar cart badge counter
      const navCounters = document.querySelectorAll('.cart-count, #cartCountBadge, .badge-cart-count');
      navCounters.forEach(function(el) {
        el.textContent = data.cart_count;
      });

      // 4. Update Summary Total Items & Total PCS
      const sumItems = document.querySelector('.js-summary-total-items');
      if (sumItems) {
        sumItems.textContent = data.items_summary_text;
      }
      const sumPcs = document.querySelector('.js-summary-total-pcs');
      if (sumPcs) {
        sumPcs.textContent = Number(data.cart_total_pcs).toLocaleString() + ' PCS';
      }

      // 5. Update Subtotal / Taxable Amount
      const sumSubtotal = document.querySelector('.js-summary-subtotal');
      if (sumSubtotal) {
        sumSubtotal.textContent = data.taxable_amount_formatted || data.subtotal_formatted;
      }

      // Update Dynamic GST Container
      const gstContainer = document.querySelector('.js-summary-gst-container');
      if (gstContainer && data.rates_breakdown) {
        if (data.has_multiple_rates) {
          let rowsHtml = '<div class="my-2 p-2.5 bg-light rounded border border-light-subtle">';
          rowsHtml += '<div class="small fw-bold text-navy mb-1.5 border-bottom pb-1"><i class="bi bi-pie-chart text-gold me-1"></i> GST Breakdown:</div>';
          data.rates_breakdown.forEach(function(r) {
            rowsHtml += '<div class="d-flex justify-content-between small text-dark mb-1">';
            rowsHtml += '<span class="fw-semibold">' + r.rate_label + '</span>';
            rowsHtml += '<span class="fw-bold text-navy">' + r.gst_amount_formatted + '</span>';
            rowsHtml += '</div>';
            rowsHtml += '<div class="d-flex justify-content-between text-muted ps-2 mb-1" style="font-size: 0.75rem;">';
            rowsHtml += '<span>&bull; ' + r.cgst_rate_label + ' / ' + r.sgst_rate_label + '</span>';
            rowsHtml += '<span>' + r.cgst_amount_formatted + ' + ' + r.sgst_amount_formatted + '</span>';
            rowsHtml += '</div>';
          });
          rowsHtml += '</div>';
          rowsHtml += '<div class="d-flex justify-content-between mb-2 py-1 px-2 rounded bg-light border border-light-subtle">';
          rowsHtml += '<span class="text-secondary small fw-bold">Total GST</span>';
          rowsHtml += '<span class="fw-bold text-navy js-summary-total-gst">' + data.total_gst_formatted + '</span>';
          rowsHtml += '</div>';
          gstContainer.innerHTML = rowsHtml;
        } else {
          const single = (data.rates_breakdown.length > 0) ? data.rates_breakdown[0] : null;
          const rateVal = single ? single.rate : 18;
          const cgstRate = single ? single.cgst_rate : 9;
          const sgstRate = single ? single.sgst_rate : 9;
          let singleHtml = '<div class="d-flex justify-content-between mb-1.5 small">';
          singleHtml += '<span class="text-secondary">CGST (' + cgstRate + '%)</span>';
          singleHtml += '<span class="fw-semibold text-navy js-summary-cgst">' + data.cgst_formatted + '</span>';
          singleHtml += '</div>';
          singleHtml += '<div class="d-flex justify-content-between mb-1.5 small">';
          singleHtml += '<span class="text-secondary">SGST (' + sgstRate + '%)</span>';
          singleHtml += '<span class="fw-semibold text-navy js-summary-sgst">' + data.sgst_formatted + '</span>';
          singleHtml += '</div>';
          singleHtml += '<div class="d-flex justify-content-between mb-2 py-1 px-2 rounded bg-light border border-light-subtle">';
          singleHtml += '<span class="text-secondary small fw-bold">Total GST (' + rateVal + '%)</span>';
          singleHtml += '<span class="fw-bold text-navy js-summary-total-gst">' + data.total_gst_formatted + '</span>';
          singleHtml += '</div>';
          gstContainer.innerHTML = singleHtml;
        }
      }

      // 6. Update Delivery Fee
      const sumDelivery = document.querySelector('.js-summary-delivery');
      if (sumDelivery) {
        sumDelivery.innerHTML = (data.delivery_fee_formatted === 'FREE') 
          ? '<span class="badge bg-success-subtle text-success border border-success-subtle fw-bold px-2 py-0.5">FREE</span>' 
          : data.delivery_fee_formatted;
      }

      // 7. Update Free Shipping Alert
      const freeShipAlert = document.querySelector('.js-summary-free-shipping-alert');
      if (freeShipAlert) {
        if (data.free_shipping_remaining > 0 && data.subtotal > 0) {
          freeShipAlert.style.display = '';
          const remain = freeShipAlert.querySelector('.js-free-shipping-remaining');
          if (remain) remain.textContent = data.free_shipping_remaining_formatted;
        } else {
          freeShipAlert.style.display = 'none';
        }
      }

      // 8. Update Grand Total
      const sumGrand = document.querySelector('.js-summary-grand-total');
      if (sumGrand) {
        sumGrand.textContent = data.grand_total_formatted;
      }
    }
  })
  .catch(function(err) {
    // If network issue, fallback to standard link navigation without confirmation
    window.location.href = 'cart-action.php?action=remove&item_id=' + encodeURIComponent(itemId);
  });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
