<?php
require_once __DIR__ . '/includes/functions.php';

$db = getDB();
$session_id = get_cart_session_id();
$user_id = is_customer_logged_in() ? $_SESSION['customer_id'] : null;

if ($user_id) {
    sync_cart_on_login($user_id, $session_id);
}

// Find or create cart row
if ($user_id) {
    $stmt = $db->prepare("SELECT id FROM cart WHERE user_id = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$user_id]);
} else {
    $stmt = $db->prepare("SELECT id FROM cart WHERE session_id = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$session_id]);
}
$cart = $stmt->fetch();

if (!$cart) {
    $stmt = $db->prepare("INSERT INTO cart (session_id, user_id) VALUES (?, ?)");
    $stmt->execute([$session_id, $user_id]);
    $cart_id = $db->lastInsertId();
} else {
    $cart_id = $cart['id'];
    $stmt = $db->prepare("UPDATE cart SET session_id = ?, user_id = ? WHERE id = ?");
    $stmt->execute([$session_id, $user_id, $cart_id]);
}

$action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');

if ($action === 'add') {
    $items_to_add = [];

    if (!empty($_POST['items']) && is_array($_POST['items'])) {
        foreach ($_POST['items'] as $it) {
            if (!empty($it['product_id']) && !empty($it['quantity'])) {
                $items_to_add[] = [
                    'product_id' => (int)$it['product_id'],
                    'variant_id' => max(0, (int)($it['variant_id'] ?? 0)),
                    'quantity' => max(1, (int)$it['quantity']),
                    'ruling_type' => !empty($it['ruling_type']) ? sanitize($it['ruling_type']) : 'Unruled',
                    'order_type' => !empty($it['order_type']) ? sanitize($it['order_type']) : 'Single Piece'
                ];
            }
        }
    } elseif (!empty($_POST['product_id'])) {
        $items_to_add[] = [
            'product_id' => (int)$_POST['product_id'],
            'variant_id' => max(0, (int)($_POST['variant_id'] ?? 0)),
            'quantity' => isset($_POST['quantity']) ? max(1, (int)$_POST['quantity']) : 1,
            'ruling_type' => isset($_POST['ruling_type']) ? sanitize($_POST['ruling_type']) : 'Unruled',
            'order_type' => isset($_POST['order_type']) ? sanitize($_POST['order_type']) : 'Single Piece'
        ];
    }

    if (empty($items_to_add)) {
        set_flash('error', 'Please select at least one page count variant.');
        header("Location: products.php");
        exit();
    }

    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || !empty($_POST['ajax']);
    $added_count = 0;
    $db->beginTransaction();
    try {
      foreach ($items_to_add as $item_data) {
        $product_id = $item_data['product_id'];
        $variant_id = $item_data['variant_id'];
        $quantity = $item_data['quantity'];
        $ruling_raw = $item_data['ruling_type'];
        $order_type = ($item_data['order_type'] === 'Bundle') ? 'Bundle' : 'Single Piece';

        // Fetch product details to calculate unit price
        $stmt_p = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt_p->execute([$product_id]);
        $prod = $stmt_p->fetch();
        if (!$prod) {
            throw new RuntimeException('The selected product is no longer available.');
        }

        if (!empty($prod['varieties_managed']) && $variant_id <= 0) {
            $variantCountStmt = $db->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id = ?");
            $variantCountStmt->execute([$product_id]);
            if ((int)$variantCountStmt->fetchColumn() === 0) {
                throw new RuntimeException('This product currently has no available varieties.');
            }
        }

        $clean_ruling = trim(preg_replace('/\s*\((?:Bundle|Single Piece)[^)]*\)$/i', '', $ruling_raw));
        if ($variant_id > 0) {
            $variantStmt = $db->prepare("
                SELECT id, product_code, variety_display, variety, bundle_qty
                FROM product_variants
                WHERE id = ? AND product_id = ?
                LIMIT 1
            ");
            $variantStmt->execute([$variant_id, $product_id]);
            $selectedVariant = $variantStmt->fetch(PDO::FETCH_ASSOC);
            if (!$selectedVariant) {
                throw new RuntimeException('The selected product variety is no longer available.');
            }
            $rulingInfo = parse_variety_info($clean_ruling, $prod['pages'] ?? '');
            $varietyName = trim((string)($selectedVariant['variety_display'] ?: $selectedVariant['variety']));
            $clean_ruling = (!empty($rulingInfo['page_count']) ? $rulingInfo['page_count'] . ' - ' : '') . $varietyName;
            $product_code = (string)$selectedVariant['product_code'];
        } else {
            $product_code = get_variant_product_code($product_id, $clean_ruling, $prod['pages'] ?? null);
        }
        $stock_record = get_product_variant_stock_record(
            $db,
            $product_id,
            $product_code,
            $clean_ruling,
            $prod,
            true,
            $variant_id
        );
        $piece_price = $prod
            ? get_product_single_piece_price($prod, $clean_ruling, (int)($stock_record['id'] ?? $variant_id), $product_code)
            : 0;
        $std_qty = get_product_pieces_per_bundle($product_id, $clean_ruling, $prod, (int)($stock_record['id'] ?? $variant_id));

        if ($order_type === 'Bundle') {
            $pieces_per_bundle = max(1, $std_qty);
            $total_pieces = $pieces_per_bundle * $quantity;
            if ($std_qty > 1) {
                $unit_price = round($piece_price * $std_qty, 2);
                $ruling_type = $clean_ruling . ' (Bundle - ' . $std_qty . ' Pcs)';
            } else {
                $unit_price = $piece_price;
                $ruling_type = $clean_ruling . ' (Bundle)';
            }
        } else {
            $pieces_per_bundle = 1;
            $total_pieces = $quantity;
            $unit_price = $piece_price;
            $ruling_type = $clean_ruling . ' (Single Piece)';
        }

        // Check if item already exists in cart with EXACT same product_id and ruling_type
        $stmt = $db->prepare("
            SELECT id, quantity FROM cart_items
            WHERE cart_id = ? AND product_id = ? AND ruling_type = ?
              AND (variant_id = ? OR variant_id IS NULL)
            ORDER BY (variant_id = ?) DESC
            LIMIT 1
        ");
        $stmt->execute([$cart_id, $product_id, $ruling_type, $variant_id ?: null, $variant_id]);
        $existing = $stmt->fetch();
        $requested_quantity = $quantity + ($existing ? (int)$existing['quantity'] : 0);
        $available_quantity = $order_type === 'Bundle'
            ? (int)($stock_record['bundle_stock'] ?? 0)
            : (int)($stock_record['stock'] ?? 0);
        // Cart accepts backorders, including zero-stock and quantities above current stock.
        // Stock remains visible in the catalogue and is managed separately by Admin.
        if ($existing) {
            $new_qty = (int)$existing['quantity'] + $quantity;
            $new_total_pieces = $pieces_per_bundle * $new_qty;
            $stmt_up = $db->prepare("UPDATE cart_items SET quantity = ?, unit_price = ?, product_code = ?, variant_id = ?, pieces_per_bundle = ?, total_pieces = ? WHERE id = ?");
            $stmt_up->execute([$new_qty, $unit_price, $product_code, $variant_id ?: null, $pieces_per_bundle, $new_total_pieces, $existing['id']]);
        } else {
            $stmt_ins = $db->prepare("INSERT INTO cart_items (cart_id, product_id, variant_id, quantity, ruling_type, unit_price, product_code, pieces_per_bundle, total_pieces) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$cart_id, $product_id, $variant_id ?: null, $quantity, $ruling_type, $unit_price, $product_code, $pieces_per_bundle, $total_pieces]);
        }
        $added_count++;
      }
      $db->commit();
    } catch (RuntimeException $e) {
        $db->rollBack();
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit();
        }
        set_flash('error', $e->getMessage());
        $redirect = !empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'products.php';
        if (strpos($redirect, 'cart-action.php') !== false) {
            $redirect = 'products.php';
        }
        header("Location: " . $redirect);
        exit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    if ($is_ajax) {
        $cart_count = get_cart_count();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => ($added_count > 1 ? $added_count . ' items' : 'Product') . ' added to cart!',
            'cart_count' => $cart_count
        ]);
        exit();
    }

    set_flash('success', ($added_count > 1 ? $added_count . ' items' : 'Product') . ' added to cart!');
    $redirect = !empty($_POST['redirect_url']) ? sanitize($_POST['redirect_url']) : (!empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : 'products.php');
    if (empty($redirect) || strpos($redirect, 'cart-action.php') !== false) {
        $redirect = 'products.php';
    }
    header("Location: " . $redirect);
    exit();
}

if ($action === 'update') {
    $item_id = (int)$_POST['item_id'];
    $quantity = max(1, (int)$_POST['quantity']);

    $stmt_it = $db->prepare("SELECT ci.*, p.std_qty FROM cart_items ci JOIN products p ON ci.product_id = p.id WHERE ci.id = ? AND ci.cart_id = ?");
    $stmt_it->execute([$item_id, $cart_id]);
    $it_row = $stmt_it->fetch();

    if (!$it_row) {
        set_flash('error', 'Cart item was not found.');
        header("Location: cart.php");
        exit();
    }

    $ppb = !empty($it_row['pieces_per_bundle']) && (int)$it_row['pieces_per_bundle'] > 0 ? (int)$it_row['pieces_per_bundle'] : get_product_pieces_per_bundle($it_row['product_id'] ?? 0, $it_row['ruling_type'] ?? '', $it_row, (int)($it_row['variant_id'] ?? 0));
    $isBundle = (stripos($it_row['ruling_type'] ?? '', 'Bundle') !== false);
    $total_pieces = $isBundle ? ($ppb * $quantity) : $quantity;
    $product_code = !empty($it_row['product_code'])
        ? $it_row['product_code']
        : get_variant_product_code($it_row['product_id'], $it_row['ruling_type'], null);
    $stock_record = get_product_variant_stock_record($db, $it_row['product_id'], $product_code, $it_row['ruling_type'], $it_row, false, (int)($it_row['variant_id'] ?? 0));
    if ($isBundle) {
        $ppb = max(1, (int)($stock_record['bundle_qty'] ?? $ppb));
        $total_pieces = $ppb * $quantity;
    }
    $max_quantity = $isBundle
        ? (int)($stock_record['bundle_stock'] ?? 0)
        : (int)($stock_record['stock'] ?? 0);
    // Allow cart quantity edits even when inventory is zero or insufficient.
    // Checkout/business rules can handle fulfilment separately.
    $stmt = $db->prepare("UPDATE cart_items SET quantity = ?, pieces_per_bundle = ?, total_pieces = ? WHERE id = ? AND cart_id = ?");
    $stmt->execute([$quantity, $ppb, $total_pieces, $item_id, $cart_id]);

    set_flash('success', 'Cart updated successfully.');
    header("Location: cart.php");
    exit();
}

if ($action === 'remove') {
    $item_id = (int)$_GET['item_id'];

    $stmt = $db->prepare("DELETE FROM cart_items WHERE id = ? AND cart_id = ?");
    $stmt->execute([$item_id, $cart_id]);

    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || !empty($_GET['ajax']) || !empty($_POST['ajax']);

    if ($is_ajax) {
        $totals = calculate_cart_totals();
        $cart_count = get_cart_count();
        $items = get_cart_contents();

        $cart_total_pcs = 0;
        $total_bundles_count = 0;
        $total_pieces_count = 0;

        foreach ($items as $item) {
            $vInfo = parse_variety_info($item['ruling_type'] ?? '', $item['pages'] ?? '');
            $isB = (stripos($vInfo['order_type'] ?? '', 'Bundle') !== false);
            $ppb = 1;
            if ($isB) {
                $ppb = get_product_pieces_per_bundle($item['id'] ?? $item['product_id'] ?? 0, $item['ruling_type'] ?? '', $item);
            }
            $q = max(1, (int)$item['quantity']);
            if ($isB) {
                $cart_total_pcs += ($ppb * $q);
                $total_bundles_count += $q;
            } else {
                $cart_total_pcs += $q;
                $total_pieces_count += $q;
            }
        }

        if ($total_bundles_count > 0 && $total_pieces_count > 0) {
            $items_summary_text = $total_bundles_count . ' ' . ($total_bundles_count === 1 ? 'Bundle' : 'Bundles') . ', ' . $total_pieces_count . ' ' . ($total_pieces_count === 1 ? 'Piece' : 'Pieces');
        } elseif ($total_bundles_count > 0) {
            $items_summary_text = $total_bundles_count . ' ' . ($total_bundles_count === 1 ? 'Bundle' : 'Bundles');
        } else {
            $items_summary_text = $total_pieces_count . ' ' . ($total_pieces_count === 1 ? 'Piece' : 'Pieces');
        }

        $rates_data = [];
        if (!empty($totals['rates_breakdown'])) {
            foreach ($totals['rates_breakdown'] as $r) {
                $rates_data[] = [
                    'rate'                      => $r['rate'],
                    'rate_label'                => $r['rate_label'],
                    'cgst_rate'                 => $r['cgst_rate'],
                    'sgst_rate'                 => $r['sgst_rate'],
                    'cgst_rate_label'           => $r['cgst_rate_label'],
                    'sgst_rate_label'           => $r['sgst_rate_label'],
                    'taxable_amount'            => $r['taxable_amount'],
                    'taxable_amount_formatted'  => format_price($r['taxable_amount']),
                    'gst_amount'                => $r['gst_amount'],
                    'gst_amount_formatted'      => format_price($r['gst_amount']),
                    'cgst_amount'               => $r['cgst_amount'],
                    'cgst_amount_formatted'     => format_price($r['cgst_amount']),
                    'sgst_amount'               => $r['sgst_amount'],
                    'sgst_amount_formatted'     => format_price($r['sgst_amount'])
                ];
            }
        }

        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'cart_count' => $cart_count,
            'items_count' => count($items),
            'cart_total_pcs' => $cart_total_pcs,
            'items_summary_text' => $items_summary_text,
            'subtotal' => $totals['subtotal'],
            'subtotal_formatted' => format_price($totals['subtotal']),
            'taxable_amount' => $totals['taxable_amount'],
            'taxable_amount_formatted' => format_price($totals['taxable_amount']),
            'has_multiple_rates' => !empty($totals['has_multiple_rates']),
            'rates_breakdown' => $rates_data,
            'cgst' => $totals['cgst'],
            'cgst_formatted' => format_price($totals['cgst']),
            'sgst' => $totals['sgst'],
            'sgst_formatted' => format_price($totals['sgst']),
            'total_gst' => $totals['total_gst'],
            'total_gst_formatted' => format_price($totals['total_gst']),
            'company_gstin' => $totals['gstin'],
            'discount' => $totals['discount'],
            'discount_formatted' => format_price($totals['discount']),
            'delivery_fee' => $totals['delivery_fee'],
            'delivery_fee_formatted' => ($totals['delivery_fee'] == 0) ? 'FREE' : format_price($totals['delivery_fee']),
            'grand_total' => $totals['grand_total'],
            'grand_total_formatted' => format_price($totals['grand_total']),
            'free_shipping_threshold' => (float)get_setting('free_shipping_threshold', 499),
            'free_shipping_remaining' => max(0, (float)get_setting('free_shipping_threshold', 499) - $totals['subtotal']),
            'free_shipping_remaining_formatted' => format_price(max(0, (float)get_setting('free_shipping_threshold', 499) - $totals['subtotal']))
        ]);
        exit();
    }

    header("Location: cart.php");
    exit();
}

if ($action === 'apply_coupon') {
    unset($_SESSION['applied_coupon']);
    set_flash('info', 'Coupon codes are currently unavailable.');
    header("Location: cart.php");
    exit();
}

header("Location: cart.php");
exit();
