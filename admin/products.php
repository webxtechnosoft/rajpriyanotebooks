<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();

// AJAX Endpoints
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'rename_brand' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        $oldBrand = trim(sanitize($_POST['old_name'] ?? ''));
        $newBrand = trim(sanitize($_POST['new_name'] ?? ''));
        if ($oldBrand === '' || $newBrand === '') {
            echo json_encode(['success' => false, 'message' => 'Select the current brand and enter its new name.']);
            exit();
        }
        if (strlen($newBrand) > 100) {
            echo json_encode(['success' => false, 'message' => 'Brand name must be 100 characters or fewer.']);
            exit();
        }

        try {
            $existingStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE LOWER(TRIM(brand_name)) = LOWER(TRIM(?))");
            $existingStmt->execute([$oldBrand]);
            if ((int)$existingStmt->fetchColumn() === 0) {
                echo json_encode(['success' => false, 'message' => 'The selected brand no longer exists. Refresh and try again.']);
                exit();
            }
            if (strcasecmp($oldBrand, $newBrand) !== 0) {
                $duplicateStmt = $db->prepare("SELECT COUNT(*) FROM products WHERE LOWER(TRIM(brand_name)) = LOWER(TRIM(?)) AND LOWER(TRIM(brand_name)) != LOWER(TRIM(?))");
                $duplicateStmt->execute([$newBrand, $oldBrand]);
                if ((int)$duplicateStmt->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'message' => 'A different brand already uses that name.']);
                    exit();
                }
            }

            $updateStmt = $db->prepare("UPDATE products SET brand_name = ? WHERE LOWER(TRIM(brand_name)) = LOWER(TRIM(?))");
            $updateStmt->execute([$newBrand, $oldBrand]);
            $brandNames = $db->query("SELECT DISTINCT brand_name FROM products WHERE brand_name IS NOT NULL AND TRIM(brand_name) != '' ORDER BY brand_name ASC")->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode([
                'success' => true,
                'old_name' => $oldBrand,
                'new_name' => $newBrand,
                'updated_count' => $updateStmt->rowCount(),
                'brands' => $brandNames,
                'message' => 'Brand name updated for all matching products.'
            ]);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Could not update brand name: ' . $e->getMessage()]);
        }
        exit();
    }

    if ($_GET['action'] === 'quick_add_category' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        $cat_name = isset($_POST['name']) ? trim(sanitize($_POST['name'])) : '';
        $cat_desc = isset($_POST['description']) ? trim(sanitize($_POST['description'])) : '';
        if (empty($cat_name)) {
            echo json_encode(['success' => false, 'message' => 'Category name cannot be empty.']);
            exit();
        }

        // Case-insensitive duplicate check
        $stmtChk = $db->prepare("SELECT id, name FROM categories WHERE LOWER(TRIM(name)) = LOWER(TRIM(?)) LIMIT 1");
        $stmtChk->execute([$cat_name]);
        $existing = $stmtChk->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            echo json_encode([
                'success' => false,
                'message' => 'Category "' . htmlspecialchars($existing['name']) . '" already exists.',
                'existing_id' => $existing['id'],
                'existing_name' => $existing['name']
            ]);
            exit();
        }

        $cat_slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $cat_name)));
        $chkSlug = $db->prepare("SELECT COUNT(*) FROM categories WHERE slug = ?");
        $chkSlug->execute([$cat_slug]);
        if ($chkSlug->fetchColumn() > 0) {
            $cat_slug .= '-' . rand(100, 999);
        }

        $img = 'assets/images/categories/cat_single_line.webp';
        $ins = $db->prepare("INSERT INTO categories (name, slug, description, image, status) VALUES (?, ?, ?, ?, 1)");
        $ins->execute([$cat_name, $cat_slug, $cat_desc, $img]);
        $new_id = (int)$db->lastInsertId();

        echo json_encode([
            'success' => true,
            'id' => $new_id,
            'name' => $cat_name,
            'message' => 'Category created successfully!'
        ]);
        exit();
    }

    if ($_GET['action'] === 'get_product_json' && isset($_GET['id'])) {
        header('Content-Type: application/json');
        $pId = (int)$_GET['id'];
        $stmtP = $db->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.id = ?");
        $stmtP->execute([$pId]);
        $prod = $stmtP->fetch(PDO::FETCH_ASSOC);
        if ($prod) {
            $mainProduct = get_main_product_for_group($db, $prod);
            $prod['image'] = get_product_group_image($db, $prod);
            $prod['main_product_id'] = (int)$mainProduct['id'];
            $variantStmt = $db->prepare("
                SELECT id, product_code, page_count, variety, variety_display,
                       bundle_qty, bundle_stock, loose_stock, stock,
                       mrp, price, sale_price, gst_rate, selling_price, discounted_price
                FROM product_variants
                WHERE product_id = ?
                ORDER BY id
            ");
            $variantStmt->execute([$pId]);
            $prod['variants'] = $variantStmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($prod['variants']) && empty($prod['varieties_managed'])) {
                foreach (get_product_variety_list($prod) as $varietyLabel) {
                    $code = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $varietyLabel));
                    if ($code === '') continue;
                    $prod['variants'][] = [
                        'id' => 0,
                        'product_code' => '',
                        'variety' => $code,
                        'variety_display' => $varietyLabel,
                        'bundle_qty' => max(1, (int)($prod['std_qty'] ?? 1)),
                        'bundle_stock' => 0,
                        'loose_stock' => 0,
                        'stock' => 0,
                        'mrp' => null,
                        'price' => null,
                        'sale_price' => null,
                        'gst_rate' => null,
                        'selling_price' => null,
                        'discounted_price' => null,
                        'effective_price' => get_product_single_piece_price($prod, $varietyLabel)
                    ];
                }
            } else {
                foreach ($prod['variants'] as &$variantRow) {
                    $variantRow['effective_price'] = get_product_single_piece_price(
                        $prod,
                        $variantRow['variety_display'] ?: $variantRow['variety'],
                        (int)$variantRow['id']
                    );
                }
                unset($variantRow);
            }
            echo json_encode(['success' => true, 'product' => $prod]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Product not found.']);
        }
        exit();
    }
}

// Prevent browser from caching product admin state
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

function delete_product_record($db, $productId) {
    $checkStmt = $db->prepare("SELECT id, name, sku, image FROM products WHERE id = ?");
    $checkStmt->execute([$productId]);
    $targetProduct = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if (!$targetProduct) {
        throw new RuntimeException("Product #{$productId} does not exist or has already been deleted.");
    }

    $db->prepare("DELETE FROM product_variants WHERE product_id = ?")->execute([$productId]);
    $db->prepare("DELETE FROM product_images WHERE product_id = ?")->execute([$productId]);
    $db->prepare("DELETE FROM cart_items WHERE product_id = ?")->execute([$productId]);
    $db->prepare("DELETE FROM reviews WHERE product_id = ?")->execute([$productId]);
    $db->prepare("DELETE FROM stock_history WHERE product_id = ?")->execute([$productId]);
    $db->prepare("UPDATE ai_designs SET created_product_id = NULL WHERE created_product_id = ?")->execute([$productId]);
    $db->prepare("UPDATE order_items SET product_id = NULL, variant_id = NULL WHERE product_id = ?")->execute([$productId]);
    $db->prepare("UPDATE quotation_items SET product_id = NULL, variant_id = NULL WHERE product_id = ?")->execute([$productId]);

    $stmtDel = $db->prepare("DELETE FROM products WHERE id = ?");
    $stmtDel->execute([$productId]);
    if ($stmtDel->rowCount() <= 0) {
        throw new RuntimeException("Product deletion query did not affect product #{$productId}.");
    }

    return $targetProduct;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete') {
    $rawIds = $_POST['product_ids'] ?? [];
    $productIds = is_array($rawIds)
        ? array_values(array_unique(array_filter(array_map('intval', $rawIds), static function ($id) { return $id > 0; })))
        : [];
    if (empty($productIds)) {
        set_flash('warning', 'Select at least one product to delete.');
        header("Location: products.php");
        exit();
    }

    try {
        $db->beginTransaction();
        foreach ($productIds as $productId) {
            delete_product_record($db, $productId);
        }
        $db->commit();
        set_flash('success', count($productIds) . ' selected product(s) were permanently deleted.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        set_flash('danger', 'Bulk product deletion failed; no selected products were deleted. ' . $e->getMessage());
    }
    header("Location: products.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_delete_all') {
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM product_variants WHERE product_id IN (SELECT id FROM products)")->execute();
        $db->prepare("DELETE FROM product_images WHERE product_id IN (SELECT id FROM products)")->execute();
        $db->prepare("DELETE FROM cart_items WHERE product_id IN (SELECT id FROM products)")->execute();
        $db->prepare("DELETE FROM reviews WHERE product_id IN (SELECT id FROM products)")->execute();
        $db->prepare("DELETE FROM stock_history WHERE product_id IN (SELECT id FROM products)")->execute();
        $db->prepare("UPDATE ai_designs SET created_product_id = NULL WHERE created_product_id IN (SELECT id FROM products)")->execute();
        $db->prepare("UPDATE order_items SET product_id = NULL, variant_id = NULL WHERE product_id IN (SELECT id FROM products)")->execute();
        $db->prepare("UPDATE quotation_items SET product_id = NULL, variant_id = NULL WHERE product_id IN (SELECT id FROM products)")->execute();
        $deletedCount = $db->exec("DELETE FROM products");
        $db->commit();
        set_flash('success', $deletedCount . ' product(s) and their related catalogue data were permanently deleted. Past orders were preserved.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        set_flash('danger', 'Delete all products failed; the catalogue was not changed. ' . $e->getMessage());
    }
    header("Location: products.php");
    exit();
}

// Handle Delete Action (POST or GET)
$isDeleteAction = false;
$deleteId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $isDeleteAction = true;
    $deleteId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
} elseif (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $isDeleteAction = true;
    $deleteId = (int)$_GET['id'];
}

if ($isDeleteAction) {
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (isset($_GET['format']) && $_GET['format'] === 'json')
              || (isset($_POST['format']) && $_POST['format'] === 'json');

    if ($deleteId <= 0) {
        $errMsg = 'Invalid product ID specified for deletion.';
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $errMsg]);
            exit();
        }
        set_flash('danger', $errMsg);
        header("Location: products.php");
        exit();
    }

    try {
        $db->beginTransaction();
        $targetProduct = delete_product_record($db, $deleteId);
        $db->commit();

        $successMsg = 'Product #' . $deleteId . ' ("' . htmlspecialchars($targetProduct['name']) . '") was permanently deleted from the database.';

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'id' => $deleteId, 'message' => $successMsg]);
            exit();
        }

        set_flash('success', $successMsg);
        header("Location: products.php");
        exit();

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $errMsg = 'Failed to delete product #' . $deleteId . ': ' . $e->getMessage();
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $errMsg]);
            exit();
        }
        set_flash('danger', $errMsg);
        header("Location: products.php");
        exit();
    }
}


// Handle Add / Edit POST Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['action'])) {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $existingProduct = null;
    $mainProductId = $id;
    if ($id > 0) {
        $existingStmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $existingStmt->execute([$id]);
        $existingProduct = $existingStmt->fetch(PDO::FETCH_ASSOC);
    }
    $manageVariantsSubmitted = isset($_POST['manage_variants']) && $_POST['manage_variants'] === '1';
    $name = sanitize($_POST['name']);
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
    $sku = sanitize($_POST['sku']);
    $category_id = (int)$_POST['category_id'];
    $brand_name = !empty($_POST['brand_name']) ? sanitize($_POST['brand_name']) : null;
    $description = sanitize($_POST['description']);
    $variantDefaults = [];
    foreach (($_POST['variants'] ?? []) as $submittedVariant) {
        if (!is_array($submittedVariant) || !empty($submittedVariant['delete'])) {
            continue;
        }
        foreach (['price', 'sale_price', 'mrp', 'gst_rate'] as $field) {
            if (!array_key_exists($field, $variantDefaults) && ($submittedVariant[$field] ?? '') !== '') {
                $variantDefaults[$field] = $submittedVariant[$field];
            }
        }
    }
    $mrp = isset($_POST['mrp']) && $_POST['mrp'] !== ''
        ? (float)$_POST['mrp']
        : ($existingProduct
            ? $existingProduct['mrp']
            : ($variantDefaults['mrp'] ?? null));
    $sale_price = !empty($mrp)
        ? round((float)$mrp / 2, 2)
        : (isset($_POST['sale_price']) && $_POST['sale_price'] !== ''
            ? (float)$_POST['sale_price']
            : ($existingProduct
                ? ($existingProduct['sale_price'] ?? null)
                : ($variantDefaults['sale_price'] ?? null)));
    $price = isset($_POST['price']) && $_POST['price'] !== ''
        ? (float)$_POST['price']
        : (float)($sale_price
            ?: ($existingProduct['price'] ?? ($variantDefaults['price'] ?? 0)));
    $gst_rate = (isset($_POST['gst_rate']) && $_POST['gst_rate'] !== '')
        ? max(0, (float)$_POST['gst_rate'])
        : (float)($existingProduct
            ? ($existingProduct['gst_rate'] ?? 18.00)
            : ($variantDefaults['gst_rate'] ?? 18.00));
    if (!$existingProduct && $price <= 0) {
        set_flash('danger', 'Enter an MRP for at least one variety.');
        header("Location: products.php");
        exit();
    }
    $selling_price_unruled = !empty($_POST['selling_price_unruled'])
        ? (float)$_POST['selling_price_unruled']
        : ($existingProduct['selling_price_unruled'] ?? null);
    $discounted_price_unruled = !empty($_POST['discounted_price_unruled'])
        ? (float)$_POST['discounted_price_unruled']
        : ($existingProduct['discounted_price_unruled'] ?? null);
    $selling_price_ruled = !empty($_POST['selling_price_ruled'])
        ? (float)$_POST['selling_price_ruled']
        : ($existingProduct['selling_price_ruled'] ?? null);
    $discounted_price_ruled = !empty($_POST['discounted_price_ruled'])
        ? (float)$_POST['discounted_price_ruled']
        : ($existingProduct['discounted_price_ruled'] ?? null);
    // Ruling Option field has been removed from the form; varieties are now managed
    // exclusively through the Product Varieties section. Keep the existing column value
    // for saved products (defaulting to 'Both') so no data is lost.
    $ruling_option = ($existingProduct && !empty($existingProduct['ruling_option']))
        ? $existingProduct['ruling_option']
        : 'Both';
    $std_qty = !empty($_POST['std_qty']) ? max(1, (int)$_POST['std_qty']) : null;
    if (empty($std_qty) && !empty($_POST['variants']) && is_array($_POST['variants'])) {
        foreach ($_POST['variants'] as $v) {
            if (!empty($v['bundle_qty']) && (int)$v['bundle_qty'] > 0 && empty($v['delete'])) {
                $std_qty = (int)$v['bundle_qty'];
                break;
            }
        }
    }
    if (empty($std_qty) && $existingProduct && !empty($existingProduct['std_qty'])) {
        $std_qty = (int)$existingProduct['std_qty'];
    }
    $stock = max(0, (int)($_POST['stock'] ?? 0));
    $bundle_stock = (isset($_POST['bundle_stock']) && $_POST['bundle_stock'] !== '')
        ? max(0, (int)$_POST['bundle_stock'])
        : calculate_stock_breakdown($stock, $std_qty)['bundle_stock'];
    $pages = (int)$_POST['pages'];
    if ($existingProduct) {
        $productForImageGroup = $existingProduct;
        $productForImageGroup['name'] = $name;
        $productForImageGroup['category_id'] = $category_id;
        $productForImageGroup['brand_name'] = $brand_name;
        $productForImageGroup['pages'] = $pages;
        $mainProductId = (int)get_main_product_for_group($db, $productForImageGroup)['id'];
    }
    $size = sanitize($_POST['size']);
    $paper_type = sanitize($_POST['paper_type']);
    $gsm = (int)$_POST['gsm'];
    $binding = sanitize($_POST['binding']);
    $cover_type = sanitize($_POST['cover_type']);
    $featured = isset($_POST['featured']) ? 1 : 0;
    $bestseller = isset($_POST['bestseller']) ? 1 : 0;
    $new_arrival = isset($_POST['new_arrival']) ? 1 : 0;
    $active = isset($_POST['active']) ? 1 : 0;
    $manual_verification_flag = isset($_POST['manual_verification_flag']) ? 1 : 0;
    $manual_verification_notes = !empty($_POST['manual_verification_notes']) ? sanitize($_POST['manual_verification_notes']) : null;

    $image_path = $existingProduct && !empty($existingProduct['image'])
        ? $existingProduct['image']
        : 'uploads/products/prod_navy_single.webp';
    $uploaded_image_path = null;

    // File Upload Handling
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $new_filename = 'prod_' . time() . '_' . rand(100,999) . '.' . $ext;
            $upload_dest = __DIR__ . '/../uploads/products/' . $new_filename;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dest)) {
                $image_path = 'uploads/products/' . $new_filename;
                $uploaded_image_path = $image_path;
            }
        }
    }

    if ($id > 0) {
        // UPDATE existing product
        // 1. Verify existence
        $checkExists = $db->prepare("SELECT id FROM products WHERE id = ?");
        $checkExists->execute([$id]);
        if (!$checkExists->fetchColumn()) {
            set_flash('danger', 'Error: Target product #' . $id . ' does not exist.');
            header("Location: products.php");
            exit();
        }

        // 2. Prevent SKU collision with other products
        $stmtSku = $db->prepare("SELECT COUNT(*) FROM products WHERE sku = ? AND id != ?");
        $stmtSku->execute([$sku, $id]);
        if ($stmtSku->fetchColumn() > 0) {
            set_flash('danger', 'Error: SKU "' . htmlspecialchars($sku) . '" is already in use by another product. Existing product IDs and SKUs must remain distinct.');
            header("Location: products.php");
            exit();
        }

        // 3. Unique slug check excluding current product
        $stmtSlug = $db->prepare("SELECT COUNT(*) FROM products WHERE slug = ? AND id != ?");
        $stmtSlug->execute([$slug, $id]);
        if ($stmtSlug->fetchColumn() > 0) {
            $slug .= '-' . $id;
        }

        $product_image_path = ($uploaded_image_path !== null && $id !== $mainProductId)
            ? $existingProduct['image']
            : $image_path;
        $stmt = $db->prepare("
            UPDATE products SET 
                name=?, slug=?, sku=?, category_id=?, brand_name=?, std_qty=?, mrp=?, 
                selling_price_unruled=?, discounted_price_unruled=?, selling_price_ruled=?, discounted_price_ruled=?, ruling_option=?, varieties_managed=?,
                description=?, price=?, sale_price=?, gst_rate=?, stock=?, bundle_stock=?, pages=?, size=?, paper_type=?, gsm=?, binding=?, cover_type=?, 
                image=?, featured=?, bestseller=?, new_arrival=?, active=?, manual_verification_flag=?, manual_verification_notes=?
            WHERE id=?
        ");
        $db->beginTransaction();
        try {
            $stmt->execute([
                $name, $slug, $sku, $category_id, $brand_name, $std_qty, $mrp,
                $selling_price_unruled, $discounted_price_unruled, $selling_price_ruled, $discounted_price_ruled, $ruling_option,
                $manageVariantsSubmitted ? 1 : (int)($existingProduct['varieties_managed'] ?? 0),
                $description, $price, $sale_price, $gst_rate, $stock, $bundle_stock, $pages, $size, $paper_type, $gsm, $binding, $cover_type,
                $product_image_path, $featured, $bestseller, $new_arrival, $active, $manual_verification_flag, $manual_verification_notes, $id
            ]);
            if ($uploaded_image_path !== null && $id !== $mainProductId) {
                $imageStmt = $db->prepare("UPDATE products SET image = ? WHERE id = ?");
                $imageStmt->execute([$uploaded_image_path, $mainProductId]);
            }
            if ($manageVariantsSubmitted) {
                $deletedCartItems = save_product_variant_records(
                    $db,
                    $id,
                    $sku,
                    $pages,
                    $std_qty,
                    $stock,
                    $_POST['variants'] ?? []
                );
            } else {
                sync_product_variant_record($db, $id, $sku, $pages, $ruling_option, $std_qty, $bundle_stock, $stock);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            set_flash('danger', 'Product update failed: ' . $e->getMessage());
            header("Location: products.php");
            exit();
        }
        $successMessage = 'Product #' . $id . ' (' . htmlspecialchars($name) . ') updated successfully!';
        if (!empty($deletedCartItems)) {
            $successMessage .= ' ' . $deletedCartItems . ' cart item(s) for removed varieties were cleared.';
        }
        set_flash('success', $successMessage);
    } else {
        // INSERT new product
        // 1. Check duplicate SKU
        $stmtSku = $db->prepare("SELECT COUNT(*) FROM products WHERE sku = ?");
        $stmtSku->execute([$sku]);
        if ($stmtSku->fetchColumn() > 0) {
            set_flash('danger', 'Error: SKU "' . htmlspecialchars($sku) . '" already exists. Please specify a unique SKU for new products.');
            header("Location: products.php");
            exit();
        }

        // 2. Ensure unique slug
        $stmtSlug = $db->prepare("SELECT COUNT(*) FROM products WHERE slug = ?");
        $stmtSlug->execute([$slug]);
        if ($stmtSlug->fetchColumn() > 0) {
            $slug .= '-' . rand(100, 999);
        }

        $stmt = $db->prepare("
            INSERT INTO products (
                name, slug, sku, category_id, brand_name, std_qty, mrp, 
                selling_price_unruled, discounted_price_unruled, selling_price_ruled, discounted_price_ruled, ruling_option, varieties_managed,
                description, price, sale_price, gst_rate, stock, bundle_stock, pages, size, paper_type, gsm, binding, cover_type, 
                image, featured, bestseller, new_arrival, active, manual_verification_flag, manual_verification_notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $db->beginTransaction();
        try {
            $stmt->execute([
                $name, $slug, $sku, $category_id, $brand_name, $std_qty, $mrp,
                $selling_price_unruled, $discounted_price_unruled, $selling_price_ruled, $discounted_price_ruled, $ruling_option,
                $manageVariantsSubmitted ? 1 : 0,
                $description, $price, $sale_price, $gst_rate, $stock, $bundle_stock, $pages, $size, $paper_type, $gsm, $binding, $cover_type,
                $image_path, $featured, $bestseller, $new_arrival, $active, $manual_verification_flag, $manual_verification_notes
            ]);
            $newProdId = (int)$db->lastInsertId();
            if ($newProdId <= 0) {
                throw new Exception("Database failed to generate a new Product ID.");
            }
            if ($manageVariantsSubmitted) {
                save_product_variant_records($db, $newProdId, $sku, $pages, $std_qty, $stock, $_POST['variants'] ?? []);
            } else {
                sync_product_variant_record($db, $newProdId, $sku, $pages, $ruling_option, $std_qty, $bundle_stock, $stock);
            }
            // Double check insertion exists in database
            $stmtVerify = $db->prepare("SELECT id FROM products WHERE id = ?");
            $stmtVerify->execute([$newProdId]);
            if (!$stmtVerify->fetchColumn()) {
                throw new Exception("Product insertion verification failed: Record was not found in database.");
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            set_flash('danger', 'Product creation failed: ' . $e->getMessage());
            header("Location: products.php");
            exit();
        }
        set_flash('success', 'New notebook product created successfully with ID #' . $newProdId . '!');
    }
    header("Location: products.php");
    exit();
}

// Filter & Search Parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$brand_filter = isset($_GET['brand']) ? trim($_GET['brand']) : '';
$category_filter = isset($_GET['category']) ? (int)$_GET['category'] : 0;
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 25;
$offset = ($page - 1) * $per_page;

$whereClauses = ["1=1"];
$queryParams = [];

if ($search !== '') {
    $term = "%{$search}%";
    
    // Check if user entered page count number (e.g. "192", "192p", "192 pages")
    $page_number = null;
    if (preg_match('/^\s*(\d+)\s*(?:p|pages)?\s*$/i', $search, $m_pg)) {
        $page_number = (int)$m_pg[1];
    }
    
    $searchParts = [
        "p.name LIKE ?",
        "p.sku LIKE ?",
        "p.brand_name LIKE ?",
        "c.name LIKE ?",
        "p.ruling_option LIKE ?",
        "p.description LIKE ?",
        "p.size LIKE ?",
        "EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND (pv.product_code LIKE ? OR pv.variety LIKE ? OR pv.variety_display LIKE ?))"
    ];
    $queryParams[] = $term; // name
    $queryParams[] = $term; // sku
    $queryParams[] = $term; // brand_name
    $queryParams[] = $term; // category
    $queryParams[] = $term; // ruling_option
    $queryParams[] = $term; // description
    $queryParams[] = $term; // size
    $queryParams[] = $term; // pv.product_code
    $queryParams[] = $term; // pv.variety
    $queryParams[] = $term; // pv.variety_display
    
    if ($page_number !== null) {
        $searchParts[] = "p.pages = ?";
        $searchParts[] = "EXISTS (SELECT 1 FROM product_variants pv WHERE pv.product_id = p.id AND pv.page_count = ?)";
        $queryParams[] = $page_number;
        $queryParams[] = $page_number;
    } else {
        $searchParts[] = "p.pages LIKE ?";
        $queryParams[] = $term;
    }
    
    $whereClauses[] = "(" . implode(" OR ", $searchParts) . ")";
}

if ($brand_filter !== '') {
    $whereClauses[] = "p.brand_name = ?";
    $queryParams[] = $brand_filter;
}

if ($category_filter > 0) {
    $whereClauses[] = "p.category_id = ?";
    $queryParams[] = $category_filter;
}

$whereSql = implode(" AND ", $whereClauses);

// Count total matching products for pagination
$countStmt = $db->prepare("
    SELECT COUNT(DISTINCT p.id) 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE $whereSql
");
$countStmt->execute($queryParams);
$total_products_count = (int)$countStmt->fetchColumn();
$total_pages = max(1, ceil($total_products_count / $per_page));

// Fetch paginated products
$querySql = "
    SELECT p.*, c.name as category_name 
    FROM products p 
    LEFT JOIN categories c ON p.category_id = c.id 
    WHERE $whereSql 
    ORDER BY p.id DESC 
    LIMIT $per_page OFFSET $offset
";
$stmt = $db->prepare($querySql);
$stmt->execute($queryParams);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($products as &$product) {
    $product['image'] = get_product_group_image($db, $product);
}
unset($product);

// Fetch categories for form select & filter
$stmt_cats = $db->query("SELECT * FROM categories ORDER BY name ASC");
$categories = $stmt_cats->fetchAll(PDO::FETCH_ASSOC);

// Fetch distinct brand names for form select & filter
$distinct_brands = $db->query("
    SELECT DISTINCT brand_name 
    FROM products 
    WHERE brand_name IS NOT NULL AND TRIM(brand_name) != '' 
    ORDER BY brand_name ASC
")->fetchAll(PDO::FETCH_COLUMN);

// Fetch distinct notebook sizes dynamically from database
$distinct_sizes = $db->query("
    SELECT DISTINCT size 
    FROM products 
    WHERE size IS NOT NULL AND TRIM(size) != '' 
    ORDER BY size ASC
")->fetchAll(PDO::FETCH_COLUMN);
$standard_sizes = ['A4 Size', 'Long Size (WB)', 'Long Size (GB)', 'Long Hard Bind', 'Student Size', 'King Size', 'FC Size', 'Practical LMT'];
$all_sizes = array_values(array_unique(array_filter(array_merge($standard_sizes, $distinct_sizes))));

// Fetch distinct bindings from database: primary two are 'Soft Binding' and 'Hard Binding'
$primary_bindings = ['Soft Binding', 'Hard Binding'];
$distinct_bindings = $db->query("
    SELECT DISTINCT binding 
    FROM products 
    WHERE binding IS NOT NULL AND TRIM(binding) != '' 
    ORDER BY binding ASC
")->fetchAll(PDO::FETCH_COLUMN);

$other_existing_bindings = [];
foreach ($distinct_bindings as $b) {
    $trimmed = trim($b);
    $lower = strtolower($trimmed);
    if (!empty($trimmed) && $lower !== 'soft binding' && $lower !== 'hard binding' && $lower !== 'hard bind' && $lower !== 'soft bind') {
        $other_existing_bindings[] = $trimmed;
    }
}
$other_existing_bindings = array_values(array_unique($other_existing_bindings));
sort($other_existing_bindings);

// Fetch all distinct ruling options from database and standard notebook variety types
$base_rulings = [
    'Both',
    'Ruled',
    'Unruled',
    'Maths Plain',
    'Maths Ruled',
    'Double Line',
    'Four Line',
    'Square',
    'One Side Ruled',
    'Accountancy',
    'Graph',
    'Chemistry',
    'Five Subject',
    'Standard'
];

$distinct_prod_rulings = $db->query("
    SELECT DISTINCT ruling_option 
    FROM products 
    WHERE ruling_option IS NOT NULL AND TRIM(ruling_option) != '' 
    ORDER BY ruling_option ASC
")->fetchAll(PDO::FETCH_COLUMN);

$distinct_var_rulings = $db->query("
    SELECT DISTINCT variety_display 
    FROM product_variants 
    WHERE variety_display IS NOT NULL AND TRIM(variety_display) != '' 
    ORDER BY variety_display ASC
")->fetchAll(PDO::FETCH_COLUMN);

$all_available_rulings = [];
foreach ($base_rulings as $br) {
    $all_available_rulings[] = $br;
}
foreach (array_merge($distinct_prod_rulings, $distinct_var_rulings) as $r) {
    $trimmed = trim($r);
    if (empty($trimmed)) continue;
    if (strcasecmp($trimmed, 'Micro Point') === 0) {
        $trimmed = 'Maths Plain';
    }
    $exists = false;
    foreach ($all_available_rulings as $ex) {
        if (strcasecmp($ex, $trimmed) === 0) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $all_available_rulings[] = $trimmed;
    }
}

// Fetch paper types & cover types for datalists
$distinct_paper_types = $db->query("
    SELECT DISTINCT paper_type 
    FROM products 
    WHERE paper_type IS NOT NULL AND TRIM(paper_type) != '' 
    ORDER BY paper_type ASC
")->fetchAll(PDO::FETCH_COLUMN);

$distinct_cover_types = $db->query("
    SELECT DISTINCT cover_type 
    FROM products 
    WHERE cover_type IS NOT NULL AND TRIM(cover_type) != '' 
    ORDER BY cover_type ASC
")->fetchAll(PDO::FETCH_COLUMN);

// Fetch all products for the Product Selector dropdown (edit mode switcher)
$all_products_selector = $db->query("
    SELECT id, name, sku, brand_name, category_id, mrp, price, sale_price, gst_rate,
           selling_price_unruled, discounted_price_unruled, selling_price_ruled, discounted_price_ruled,
           ruling_option, std_qty, stock, bundle_stock, pages, size, paper_type, gsm,
           binding, cover_type, description, image, featured, bestseller, new_arrival, active,
           manual_verification_flag, manual_verification_notes
    FROM products 
    ORDER BY name ASC, id ASC
")->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/admin_header.php';
?>

<style>
/* ADMIN PRODUCT MANAGEMENT PAGE REDESIGN STYLES */
.admin-products-header {
  margin-bottom: 1.75rem;
}
.admin-products-title {
  font-size: 1.5rem;
  font-weight: 700;
  color: #17243A;
  margin-bottom: 0.25rem;
  letter-spacing: -0.3px;
}
.admin-products-subtitle {
  color: #64748B;
  font-size: 0.875rem;
}
.btn-add-product {
  background-color: #C89B3C;
  color: #ffffff;
  border: none;
  padding: 0.55rem 1.15rem;
  font-size: 0.85rem;
  font-weight: 600;
  border-radius: 8px;
  box-shadow: 0 2px 6px rgba(200, 155, 60, 0.25);
  transition: all 0.2s ease-in-out;
}
.btn-add-product:hover, .btn-add-product:focus {
  background-color: #b0842e;
  color: #ffffff;
  box-shadow: 0 4px 12px rgba(200, 155, 60, 0.35);
  transform: translateY(-1px);
}

.admin-card {
  background: #ffffff;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  overflow: hidden;
}

.admin-table {
  margin-bottom: 0;
  width: 100%;
}
.admin-table thead th {
  background-color: #f8fafc;
  color: #475569;
  font-size: 0.775rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  padding: 12px 16px;
  border-bottom: 2px solid #e2e8f0;
  border-top: none;
  white-space: nowrap;
  vertical-align: middle;
}
.admin-table tbody td {
  padding: 12px 16px;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
  color: #334155;
  font-size: 0.875rem;
}
.admin-table tbody tr:last-child td {
  border-bottom: none;
}
.admin-table tbody tr:hover {
  background-color: #f8fafc;
}

/* 3. Product Image Container */
.product-img-box-admin {
  width: 44px;
  height: 44px;
  min-width: 44px;
  border-radius: 8px;
  background-color: #f8fafc;
  border: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 2px;
  overflow: hidden;
}
.admin-product-img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

/* 4. Product Name & Title */
.product-title-wrap {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-width: 280px;
}
.product-name-title {
  font-weight: 700;
  color: #17243A;
  font-size: 0.9rem;
  line-height: 1.3;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 270px;
  display: block;
}
.product-sub-info {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
  font-size: 0.775rem;
  color: #64748b;
}

/* 5. SKU & Std Qty */
.sku-code-badge {
  font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
  font-size: 0.8rem;
  font-weight: 600;
  color: #1e293b;
  background-color: #f1f5f9;
  padding: 2px 7px;
  border-radius: 4px;
  display: inline-block;
  border: 1px solid #e2e8f0;
  white-space: nowrap;
}
.std-qty-text {
  font-size: 0.75rem;
  color: #64748b;
  margin-top: 3px;
  white-space: nowrap;
}

/* 6, 8, 9 Badges */
.bg-cream-gold {
  background-color: #fef9c3;
  color: #854d0e;
  border: 1px solid #fef08a;
  font-size: 0.68rem;
  font-weight: 600;
  padding: 2px 6px;
}
.badge-category {
  background-color: #f1f5f9;
  color: #334155;
  border: 1px solid #e2e8f0;
  font-size: 0.75rem;
  font-weight: 500;
  padding: 4px 10px;
  border-radius: 50px;
  white-space: nowrap;
}
.badge-status-active {
  background-color: #dcfce7;
  color: #166534;
  border: 1px solid #bbf7d0;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 50px;
}
.badge-status-disabled {
  background-color: #f1f5f9;
  color: #475569;
  border: 1px solid #e2e8f0;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 4px 10px;
  border-radius: 50px;
}
.badge-stock-normal {
  background-color: #dcfce7;
  color: #166534;
  border: 1px solid #bbf7d0;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 4px;
  line-height: 1.2;
  display: inline-block;
}
.badge-stock-low {
  background-color: #fee2e2;
  color: #991b1b;
  border: 1px solid #fecaca;
  font-size: 0.75rem;
  font-weight: 600;
  padding: 3px 8px;
  border-radius: 4px;
  line-height: 1.2;
  display: inline-block;
}

/* 7. Price Column */
.price-line {
  font-size: 0.825rem;
  line-height: 1.35;
  white-space: nowrap;
}
.price-label {
  font-size: 0.725rem;
  font-weight: 700;
  color: #64748b;
  width: 24px;
  display: inline-block;
}
.price-val-navy {
  font-weight: 600;
  color: #17243A;
}
.price-val-gold {
  font-weight: 600;
  color: #C89B3C;
}

/* 10. ACTIONS CONTAINER (STRICT EQUAL WIDTH ALIGNMENT) */
.admin-actions-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 5px;
  width: 100%;
}
.btn-action-edit,
.btn-action-delete,
.btn-action-print {
  width: 76px;
  height: 28px;
  padding: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.725rem;
  font-weight: 600;
  border-radius: 6px;
  transition: all 0.15s ease-in-out;
  text-decoration: none;
  border: 1px solid transparent;
  box-sizing: border-box;
}
.btn-action-print {
  background-color: #fef9c3;
  color: #854d0e;
  border-color: #fef08a;
}
.btn-action-print:hover {
  background-color: #C89B3C;
  color: #17243A;
  border-color: #C89B3C;
}
.btn-action-edit {
  background-color: #eff6ff;
  color: #2563eb;
  border-color: #bfdbfe;
}
.btn-action-edit:hover {
  background-color: #2563eb;
  color: #ffffff;
  border-color: #2563eb;
}
.btn-action-delete {
  background-color: #fef2f2;
  color: #dc2626;
  border-color: #fecaca;
}
.btn-action-delete:hover {
  background-color: #dc2626;
  color: #ffffff;
  border-color: #dc2626;
}

/* 14. RESPONSIVE TABLE */
@media (max-width: 991.98px) {
  .admin-table thead th,
  .admin-table tbody td {
    padding: 10px 12px;
  }
  .product-name-title {
    max-width: 200px;
  }
}

/* 15. MODAL VIEWPORT CONSTRAINT & VERTICAL SCROLLING */
#productModal .modal-dialog {
  max-width: 1240px !important;
  width: 96vw !important;
  max-height: calc(100vh - 40px) !important;
  height: calc(100vh - 40px) !important;
  margin: 20px auto !important;
  display: flex !important;
  flex-direction: column !important;
}
#productModal .modal-content {
  max-height: 100% !important;
  height: 100% !important;
  display: flex !important;
  flex-direction: column !important;
  border-radius: 12px;
  overflow: hidden !important;
}
#productModal form#mainProductForm {
  display: flex !important;
  flex-direction: column !important;
  height: 100% !important;
  max-height: 100% !important;
  overflow: hidden !important;
  margin-bottom: 0 !important;
}
#productModal .modal-header {
  flex-shrink: 0;
  background-color: #17243A;
  border-bottom: 1px solid rgba(255, 255, 255, 0.15);
  z-index: 1055;
  padding: 14px 20px;
}
#productModal .modal-body {
  flex: 1 1 auto;
  overflow-y: auto !important;
  overscroll-behavior: contain;
  padding: 20px;
  max-height: calc(100vh - 150px);
}
#productModal .product-variants-scroll {
  width: 100%;
  max-height: 320px;
  overflow-x: auto !important;
  overflow-y: auto !important;
  overscroll-behavior-x: contain;
  -webkit-overflow-scrolling: touch;
}
#productModal #productVariantsTable {
  width: 100%;
  min-width: 980px;
  table-layout: fixed;
}
#productModal #productVariantsTable th,
#productModal #productVariantsTable td {
  padding: 0.25rem;
}
#productModal #productVariantsTable .form-control {
  min-width: 0;
  padding: 0.25rem 0.3rem;
  font-size: 0.75rem;
}
#productModal .modal-footer {
  flex-shrink: 0;
  background-color: #f8fafc;
  border-top: 1px solid #e2e8f0;
  z-index: 1055;
  padding: 12px 20px;
}
.prod-combobox-item {
  transition: background-color 0.15s ease-in-out;
  cursor: pointer;
  border-left: 3px solid transparent;
  width: 100%;
  border-radius: 4px;
}
.prod-combobox-item:hover, .prod-combobox-item:focus {
  background-color: #f1f5f9;
  border-left-color: #C89B3C;
}
</style>

<div class="d-flex justify-content-between align-items-center admin-products-header admin-page-header">
  <div>
    <h3 class="admin-products-title">Product Management</h3>
    <p class="admin-products-subtitle mb-0">Manage products, October 2026 Price List entries, ruling variants, and stock.</p>
  </div>
  <div class="d-flex align-items-center">
    <button class="btn btn-add-product" data-bs-toggle="modal" data-bs-target="#productModal" onclick="resetForm()">
      <i class="bi bi-plus-lg me-1"></i> Add New Product
    </button>
    <button class="btn text-white border-0 py-2 px-3 fw-semibold fs-7 ms-2 d-flex align-items-center" data-bs-toggle="modal" data-bs-target="#excelImportModal" style="background-color: #17243A; border-radius: 8px; box-shadow: 0 2px 6px rgba(23, 36, 58, 0.25);">
      <i class="bi bi-file-earmark-excel text-gold me-1.5 fs-6"></i> Add Excel
    </button>
  </div>
</div>

<!-- Search & Filter Controls Bar -->
<div class="bg-white p-3 rounded-3 border mb-3 shadow-xs">
  <form action="products.php" method="GET" class="row g-2 align-items-center" id="productSearchForm">
    <div class="col-lg-5 col-md-12">
      <div class="input-group">
        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
        <input type="text" name="search" id="productSearchInput" class="form-control border-start-0 ps-0" 
               placeholder="Search by Product Name, Code/SKU, Brand, Category, Variety, Pages..." 
               value="<?php echo htmlspecialchars($search); ?>">
        <?php if ($search !== ''): ?>
          <button type="button" class="btn btn-outline-secondary border-start-0" id="productClearInputBtn" title="Clear keyword">
            <i class="bi bi-x-lg"></i>
          </button>
        <?php endif; ?>
      </div>
    </div>

    <div class="col-lg-3 col-md-4 col-sm-6">
      <select name="brand" class="form-select form-select-sm py-2" onchange="this.form.submit()">
        <option value="">-- All Brands (Popular, Economi, etc.) --</option>
        <?php foreach ($distinct_brands as $b): ?>
          <option value="<?php echo htmlspecialchars($b); ?>" <?php echo ($brand_filter === $b) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($b); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-lg-2 col-md-4 col-sm-6">
      <select name="category" class="form-select form-select-sm py-2" onchange="this.form.submit()">
        <option value="0">-- All Categories --</option>
        <?php foreach ($categories as $cat): ?>
          <option value="<?php echo $cat['id']; ?>" <?php echo ($category_filter == $cat['id']) ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($cat['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-lg-2 col-md-4 col-12 d-flex gap-2">
      <button type="submit" class="btn btn-gold btn-sm w-100 fw-bold py-2">
        <i class="bi bi-funnel-fill me-1"></i> Filter
      </button>
      <?php if ($search !== '' || $brand_filter !== '' || $category_filter > 0): ?>
        <a href="products.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center" title="Reset all filters" style="min-width: 38px;">
          <i class="bi bi-arrow-counterclockwise"></i>
        </a>
      <?php endif; ?>
    </div>
  </form>
</div>

<!-- Products Table Card -->
<div class="admin-card">
  <div class="p-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2 bg-light">
    <div class="fw-bold text-navy d-flex align-items-center">
      <i class="bi bi-journal-album text-gold me-2 fs-5"></i>
      <span>Products Inventory</span>
      <span class="badge bg-white text-navy border ms-2"><?php echo number_format($total_products_count); ?> products found</span>
      <?php if ($search !== ''): ?>
        <span class="badge bg-gold-subtle text-navy border border-warning-subtle ms-2">Keyword: "<?php echo htmlspecialchars($search); ?>"</span>
      <?php endif; ?>
      <?php if ($brand_filter !== ''): ?>
        <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle ms-2">Brand: <?php echo htmlspecialchars($brand_filter); ?></span>
      <?php endif; ?>
    </div>
    <div class="d-flex align-items-center gap-3">
      <div class="small text-muted fw-semibold">
        Showing page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo count($products); ?> displayed)
      </div>
      <button type="button" class="btn btn-sm btn-outline-danger fw-semibold" id="deleteSelectedProductsBtn" onclick="deleteSelectedProducts()" disabled>
        <i class="bi bi-trash me-1"></i> Delete Selected <span class="badge bg-danger ms-1" id="selectedProductCount">0</span>
      </button>
      <button type="button" class="btn btn-sm btn-danger fw-semibold" onclick="deleteAllProducts()">
        <i class="bi bi-trash3-fill me-1"></i> Delete All Products
      </button>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table admin-table align-middle">
      <thead>
        <tr>
          <th class="text-center" style="width: 42px;">
            <input type="checkbox" class="form-check-input" id="selectAllProductsOnPage" aria-label="Select all products on this page" title="Select all products on this page">
          </th>
          <th style="width: 56px;">Image</th>
          <th>Product Name & Brand</th>
          <th>SKU / Std Qty</th>
          <th>Category</th>
          <th>Price</th>
          <th>Stock</th>
          <th>Status</th>
          <th class="text-center" style="width: 95px; min-width: 95px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($products)): ?>
          <tr>
            <td colspan="9" class="text-center py-5 text-muted">
              <div class="py-4">
                <i class="bi bi-search fs-1 text-muted d-block mb-2"></i>
                <h6 class="fw-bold text-navy mb-1">No products found matching your search</h6>
                <p class="small text-secondary mb-3">Try searching with a different product name, brand (Popular, Economi), SKU, variety, or clear your filters.</p>
                <a href="products.php" class="btn btn-gold btn-sm px-3 fw-bold">Reset Filters & Show All Products</a>
              </div>
            </td>
          </tr>
        <?php else: ?>
        <?php foreach ($products as $prod): ?>
          <tr data-product-id="<?php echo (int)$prod['id']; ?>">
            <td class="text-center">
              <input type="checkbox" class="form-check-input js-product-select" value="<?php echo (int)$prod['id']; ?>" aria-label="Select <?php echo htmlspecialchars($prod['name']); ?>">
            </td>
            <!-- Image -->
            <td>
              <div class="product-img-box-admin">
                <img src="../<?php echo htmlspecialchars($prod['image']); ?>" alt="<?php echo htmlspecialchars($prod['name']); ?>" class="admin-product-img">
              </div>
            </td>

            <!-- Product Name & Brand -->
            <td>
              <div class="product-title-wrap">
                <span class="product-name-title" title="<?php echo htmlspecialchars($prod['name']); ?>">
                  <?php echo htmlspecialchars($prod['name']); ?>
                </span>
                <div class="product-sub-info">
                  <?php if (!empty($prod['brand_name'])): ?>
                    <span class="badge bg-cream-gold rounded-1 me-1"><?php echo htmlspecialchars($prod['brand_name']); ?></span>
                  <?php endif; ?>
                  <span>
                    <?php if (!empty($prod['pages'])): ?><?php echo htmlspecialchars($prod['pages']); ?> Pages<?php endif; ?>
                    <?php if (!empty($prod['pages']) && !empty($prod['size'])): ?> | <?php endif; ?>
                    <?php if (!empty($prod['size'])): ?><?php echo htmlspecialchars($prod['size']); ?><?php endif; ?>
                  </span>
                </div>
                <?php if (!empty($prod['manual_verification_flag'])): ?>
                  <div class="badge bg-warning text-dark d-inline-flex align-items-center mt-1" style="font-size: 0.68rem;" title="<?php echo htmlspecialchars($prod['manual_verification_notes']); ?>">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i> Manual Check
                  </div>
                <?php endif; ?>
              </div>
            </td>

            <!-- SKU / Std Qty -->
            <td class="text-nowrap">
              <div class="sku-code-badge"><?php echo htmlspecialchars($prod['sku']); ?></div>
              <?php if (!empty($prod['std_qty'])): ?>
                <div class="std-qty-text">Std Bundle: <?php echo $prod['std_qty']; ?> pcs</div>
              <?php endif; ?>
            </td>

            <!-- Category -->
            <td class="text-nowrap">
              <span class="badge-category"><?php echo htmlspecialchars($prod['category_name']); ?></span>
            </td>

            <!-- Price -->
            <td class="text-nowrap">
              <?php if ($prod['selling_price_unruled'] || $prod['selling_price_ruled']): ?>
                <div class="price-line">
                  <span class="price-label">UR:</span>
                  <span class="price-val-navy"><?php echo format_price($prod['discounted_price_unruled'] ? $prod['discounted_price_unruled'] : $prod['selling_price_unruled']); ?></span>
                </div>
                <div class="price-line">
                  <span class="price-label">SR:</span>
                  <span class="price-val-gold"><?php echo format_price($prod['discounted_price_ruled'] ? $prod['discounted_price_ruled'] : $prod['selling_price_ruled']); ?></span>
                </div>
              <?php else: ?>
                <span class="fw-bold price-val-navy"><?php echo format_price($prod['sale_price'] ? $prod['sale_price'] : $prod['price']); ?></span>
              <?php endif; ?>
              <div class="mt-0.5"><span class="badge bg-light text-secondary border fw-bold" style="font-size: 0.68rem;"><?php echo (float)($prod['gst_rate'] ?? 18); ?>% GST</span></div>
            </td>

            <!-- Stock -->
            <td class="text-nowrap">
              <div>
                <?php if ($prod['stock'] <= 20): ?>
                  <span class="badge-stock-low">Pcs: <?php echo $prod['stock']; ?></span>
                <?php else: ?>
                  <span class="badge-stock-normal">Pcs: <?php echo $prod['stock']; ?></span>
                <?php endif; ?>
              </div>
              <div class="mt-1">
                <?php if (($prod['bundle_stock'] ?? 0) <= 0): ?>
                  <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-1.5 py-0.5" style="font-size: 0.7rem;">Bundle: Out of Stock (0)</span>
                <?php else: ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle px-1.5 py-0.5" style="font-size: 0.7rem;">Bundle: <?php echo $prod['bundle_stock']; ?> Bundles</span>
                <?php endif; ?>
              </div>
            </td>

            <!-- Status -->
            <td class="text-nowrap">
              <?php if ($prod['active']): ?>
                <span class="badge-status-active">Active</span>
              <?php else: ?>
                <span class="badge-status-disabled">Disabled</span>
              <?php endif; ?>
            </td>

            <!-- Actions (Strictly Aligned Equal Width Buttons) -->
            <td class="text-center align-middle" style="width: 95px; min-width: 95px;">
              <div class="admin-actions-wrap">
                <a href="print_label.php?product_id=<?php echo $prod['id']; ?>" target="_blank" class="btn btn-action-print" title="Print Bundle Label Sticker">
                  <i class="bi bi-printer me-1"></i> Label
                </a>
                <button type="button" class="btn btn-action-edit" onclick='editProduct(<?php echo json_encode($prod); ?>)' title="Edit Product">
                  <i class="bi bi-pencil-square me-1"></i> Edit
                </button>
                <button type="button" class="btn btn-action-delete" onclick="confirmDeleteProduct(<?php echo $prod['id']; ?>, <?php echo htmlspecialchars(json_encode($prod['name']), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode($prod['sku']), ENT_QUOTES, 'UTF-8'); ?>)" title="Delete Product Permanently">
                  <i class="bi bi-trash me-1"></i> Delete
                </button>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination Controls -->
  <?php if ($total_pages > 1): ?>
    <div class="p-3 border-top d-flex justify-content-between align-items-center flex-wrap gap-2 bg-light">
      <div class="small text-muted">
        Showing <strong><?php echo min($total_products_count, $offset + 1); ?></strong> to <strong><?php echo min($total_products_count, $offset + count($products)); ?></strong> of <strong><?php echo $total_products_count; ?></strong> products
      </div>
      <nav aria-label="Products pagination">
        <ul class="pagination pagination-sm mb-0">
          <?php
            $pagination_query = $_GET;
            unset($pagination_query['page']);
            $base_url = 'products.php?' . (empty($pagination_query) ? '' : http_build_query($pagination_query) . '&');
          ?>
          <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?php echo $base_url . 'page=' . ($page - 1); ?>"><i class="bi bi-chevron-left"></i> Previous</a>
          </li>
          
          <?php 
            $start_p = max(1, $page - 2);
            $end_p = min($total_pages, $page + 2);
            if ($start_p > 1) {
              echo '<li class="page-item"><a class="page-link" href="' . $base_url . 'page=1">1</a></li>';
              if ($start_p > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            for ($i = $start_p; $i <= $end_p; $i++):
          ?>
            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
              <a class="page-link <?php echo ($page == $i) ? 'bg-gold border-0 text-white' : ''; ?>" href="<?php echo $base_url . 'page=' . $i; ?>"><?php echo $i; ?></a>
            </li>
          <?php endfor; ?>
          
          <?php 
            if ($end_p < $total_pages) {
              if ($end_p < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
              echo '<li class="page-item"><a class="page-link" href="' . $base_url . 'page=' . $total_pages . '">' . $total_pages . '</a></li>';
            }
          ?>

          <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
            <a class="page-link" href="<?php echo $base_url . 'page=' . ($page + 1); ?>">Next <i class="bi bi-chevron-right"></i></a>
          </li>
        </ul>
      </nav>
    </div>
  <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const selectAll = document.getElementById('selectAllProductsOnPage');
  const deleteSelectedButton = document.getElementById('deleteSelectedProductsBtn');
  const selectedCount = document.getElementById('selectedProductCount');
  const productCheckboxes = Array.from(document.querySelectorAll('.js-product-select'));

  function updateBulkDeleteControls() {
    const checkedCount = productCheckboxes.filter(function(checkbox) {
      return checkbox.checked;
    }).length;
    if (selectedCount) selectedCount.textContent = String(checkedCount);
    if (deleteSelectedButton) deleteSelectedButton.disabled = checkedCount === 0;
    if (selectAll) {
      selectAll.checked = productCheckboxes.length > 0 && checkedCount === productCheckboxes.length;
      selectAll.indeterminate = checkedCount > 0 && checkedCount < productCheckboxes.length;
    }
  }

  if (selectAll) {
    selectAll.addEventListener('change', function() {
      productCheckboxes.forEach(function(checkbox) {
        checkbox.checked = selectAll.checked;
      });
      updateBulkDeleteControls();
    });
  }
  productCheckboxes.forEach(function(checkbox) {
    checkbox.addEventListener('change', updateBulkDeleteControls);
  });
  updateBulkDeleteControls();

  const clearBtn = document.getElementById('productClearInputBtn');
  const input = document.getElementById('productSearchInput');
  if (clearBtn && input) {
    clearBtn.addEventListener('click', function() {
      input.value = '';
      window.location.href = 'products.php';
    });
  }
});

function deleteSelectedProducts() {
  const selectedIds = Array.from(document.querySelectorAll('.js-product-select:checked'))
    .map(function(checkbox) { return checkbox.value; });
  if (selectedIds.length === 0) return;

  showProductDeleteConfirmation(
    'bulk_delete',
    selectedIds,
    'Delete ' + selectedIds.length + ' selected product(s)?',
    'Their varieties, stock history, reviews, and cart entries will also be deleted. Past orders will remain but lose their product links.'
  );
}

function deleteAllProducts() {
  showProductDeleteConfirmation(
    'bulk_delete_all',
    [],
    'Delete all products?',
    'Every product, variety, stock history, review, and cart entry will be permanently deleted. Past orders will remain but lose their product links.'
  );
}

function showProductDeleteConfirmation(action, productIds, title, description) {
  const modalElement = document.getElementById('productDeleteConfirmModal');
  if (!modalElement || !window.bootstrap || !bootstrap.Modal) {
    window.alert('Delete confirmation popup is unavailable. No products were deleted.');
    return;
  }
  modalElement.dataset.action = action;
  modalElement.dataset.productIds = JSON.stringify(productIds);
  document.getElementById('productDeleteConfirmTitle').textContent = title;
  document.getElementById('productDeleteConfirmDescription').textContent =
    description + ' This action cannot be undone.';
  bootstrap.Modal.getOrCreateInstance(modalElement).show();
}

function submitConfirmedProductDelete() {
  const modalElement = document.getElementById('productDeleteConfirmModal');
  if (!modalElement) return;
  const action = modalElement.dataset.action;
  if (action !== 'bulk_delete' && action !== 'bulk_delete_all') return;
  const selectedIds = action === 'bulk_delete'
    ? JSON.parse(modalElement.dataset.productIds || '[]')
    : [];
  if (action === 'bulk_delete' && selectedIds.length === 0) return;

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'products.php';
  const actionInput = document.createElement('input');
  actionInput.type = 'hidden';
  actionInput.name = 'action';
  actionInput.value = action;
  form.appendChild(actionInput);
  selectedIds.forEach(function(id) {
    const idInput = document.createElement('input');
    idInput.type = 'hidden';
    idInput.name = 'product_ids[]';
    idInput.value = id;
    form.appendChild(idInput);
  });
  document.body.appendChild(form);
  form.submit();
}

let reopenProductModalAfterBrandEdit = false;

function openRenameBrandModal() {
  const brandSelect = document.getElementById('prod_brand_name');
  const currentBrandSelect = document.getElementById('renameBrandCurrent');
  const newBrandInput = document.getElementById('renameBrandNew');
  const feedback = document.getElementById('renameBrandFeedback');
  currentBrandSelect.value = brandSelect.value;
  newBrandInput.value = brandSelect.value;
  feedback.className = 'small mt-2 d-none';
  feedback.textContent = '';

  const productModalElement = document.getElementById('productModal');
  const productModal = bootstrap.Modal.getInstance(productModalElement);
  reopenProductModalAfterBrandEdit = productModalElement.classList.contains('show');
  if (reopenProductModalAfterBrandEdit && productModal) {
    productModalElement.addEventListener('hidden.bs.modal', function showRenameModal() {
      productModalElement.removeEventListener('hidden.bs.modal', showRenameModal);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('renameBrandModal')).show();
    });
    productModal.hide();
  } else {
    bootstrap.Modal.getOrCreateInstance(document.getElementById('renameBrandModal')).show();
  }
}

document.addEventListener('DOMContentLoaded', function() {
  document.getElementById('renameBrandModal').addEventListener('hidden.bs.modal', function() {
    if (reopenProductModalAfterBrandEdit) {
      reopenProductModalAfterBrandEdit = false;
      bootstrap.Modal.getOrCreateInstance(document.getElementById('productModal')).show();
    }
  });
});

function submitRenameBrand(event) {
  event.preventDefault();
  const currentBrand = document.getElementById('renameBrandCurrent').value;
  const newBrand = document.getElementById('renameBrandNew').value.trim();
  const feedback = document.getElementById('renameBrandFeedback');
  const submitButton = document.getElementById('renameBrandSubmit');
  if (!currentBrand || !newBrand) return;

  submitButton.disabled = true;
  feedback.className = 'small mt-2 text-muted';
  feedback.textContent = 'Saving brand name...';
  const formData = new FormData();
  formData.append('old_name', currentBrand);
  formData.append('new_name', newBrand);

  fetch('products.php?action=rename_brand', {method: 'POST', body: formData})
    .then(function(response) {
      return response.json().then(function(data) {
        if (!response.ok) throw new Error(data.message || 'Could not update brand name.');
        return data;
      });
    })
    .then(function(data) {
      if (!data.success) throw new Error(data.message || 'Could not update brand name.');

      const oldName = data.old_name.toLowerCase();
      const brandSelect = document.getElementById('prod_brand_name');
      const selectedBrand = brandSelect.value;
      const brandFilter = document.querySelector('select[name="brand"]');
      const selectedFilterBrand = brandFilter ? brandFilter.value : '';
      if (selectedFilterBrand.toLowerCase() === oldName) {
        const filterBadge = document.querySelector('.admin-card .badge.bg-info-subtle');
        if (filterBadge) filterBadge.textContent = 'Brand: ' + data.new_name;
        const currentUrl = new URL(window.location.href);
        currentUrl.searchParams.set('brand', data.new_name);
        window.history.replaceState({}, '', currentUrl.toString());
      }
      const refreshBrandOptions = function(select, selectedValue, placeholder) {
        if (!select) return;
        select.replaceChildren();
        if (placeholder) {
          const option = document.createElement('option');
          option.value = '';
          option.textContent = placeholder;
          select.appendChild(option);
        }
        data.brands.forEach(function(name) {
          const option = document.createElement('option');
          option.value = name;
          option.textContent = name;
          select.appendChild(option);
        });
        const keepValue = selectedValue.toLowerCase() === oldName ? data.new_name : selectedValue;
        select.value = keepValue;
      };
      refreshBrandOptions(brandSelect, selectedBrand, '-- Select / Generic --');
      refreshBrandOptions(brandFilter, selectedFilterBrand, '-- All Brands (Popular, Economi, etc.) --');

      (window.allProductsData || []).forEach(function(product) {
        if (String(product.brand_name || '').trim().toLowerCase() === oldName) {
          product.brand_name = data.new_name;
          if (window.allProductsMap) window.allProductsMap[product.id] = product;
          const row = document.querySelector('tr[data-product-id="' + product.id + '"]');
          const brandBadge = row && row.querySelector('.product-sub-info .badge.bg-cream-gold');
          if (brandBadge) brandBadge.textContent = data.new_name;
        }
      });
      document.querySelectorAll('.prod-combobox-item[data-brand]').forEach(function(item) {
        if ((item.dataset.brand || '').trim().toLowerCase() === oldName) {
          item.dataset.brand = data.new_name;
          const badge = item.querySelector('.badge.bg-info-subtle');
          if (badge) badge.textContent = data.new_name;
        }
      });
      document.getElementById('renameBrandCurrent').value = data.new_name;
      document.getElementById('renameBrandNew').value = data.new_name;
      feedback.className = 'small mt-2 text-success fw-semibold';
      feedback.textContent = data.message + ' (' + data.updated_count + ' products updated.)';
    })
    .catch(function(error) {
      feedback.className = 'small mt-2 text-danger fw-semibold';
      feedback.textContent = error.message || 'Network or server error updating brand name.';
    })
    .finally(function() {
      submitButton.disabled = false;
    });
}
</script>

<!-- Centered confirmation popup for bulk product deletion -->
<div class="modal fade" id="productDeleteConfirmModal" tabindex="-1" aria-labelledby="productDeleteConfirmTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-3">
      <div class="modal-header bg-danger text-white">
        <h5 class="modal-title fw-bold fs-6" id="productDeleteConfirmTitle">
          Confirm product deletion
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cancel"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-3 align-items-start">
          <i class="bi bi-exclamation-triangle-fill text-danger fs-3"></i>
          <p class="mb-0" id="productDeleteConfirmDescription"></p>
        </div>
      </div>
      <div class="modal-footer bg-light">
        <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">No, Cancel</button>
        <button type="button" class="btn btn-danger btn-sm px-3 fw-semibold" onclick="submitConfirmedProductDelete()">
          <i class="bi bi-trash me-1"></i> Yes, Delete
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="productModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg rounded-3">
      <form action="products.php" method="POST" enctype="multipart/form-data" id="mainProductForm">
        <div class="modal-header text-white" style="background-color: #17243A;">
          <h5 class="modal-title fw-bold fs-6 d-flex align-items-center" id="modalTitle">
            <i class="bi bi-journal-plus text-gold me-2 fs-5"></i>
            <span>Add New Product</span>
          </h5>
          <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-light py-0.5 px-2 fs-7" onclick="switchToNewProductMode()" title="Reset to add new product">
              <i class="bi bi-plus-circle me-1"></i> New Product
            </button>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
        </div>
        <div class="modal-body p-4">
          <input type="hidden" name="id" id="prod_id" value="0">
          <input type="hidden" name="current_image" id="prod_current_image" value="">
          <input type="hidden" name="manage_variants" id="manage_variants" value="0">

          <!-- PRODUCT SPECIFICATIONS & FIELDS -->
          <div class="row g-3">
            <!-- 1. Searchable Product Name Combobox -->
            <div class="col-md-6 position-relative" id="product_name_combobox_wrap">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label small fw-semibold mb-0" for="prod_name">Product Name *</label>
                <span id="product_mode_indicator" class="badge bg-success-subtle text-success border border-success-subtle fw-semibold px-2 py-0.5" style="font-size: 0.72rem;">
                  <i class="bi bi-plus-circle me-1"></i> New Product Mode
                </span>
              </div>
              <div class="input-group">
                <input type="text" name="name" id="prod_name" class="form-control" required placeholder="Enter notebook product name..." autocomplete="off">
                <button class="btn btn-outline-secondary dropdown-toggle d-flex align-items-center gap-1" type="button" id="btn_product_selector_toggle" data-bs-toggle="dropdown" data-bs-auto-close="true" aria-expanded="false" title="Search & select existing product to edit">
                  <i class="bi bi-search text-gold"></i> <span class="small fw-semibold">Select Existing</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-lg p-2" id="prod_name_dropdown_menu" style="width: min(460px, 92vw); max-height: 320px; overflow-y: auto; z-index: 1060;">
                  <div class="p-1 mb-2 border-bottom">
                    <input type="text" class="form-control form-control-sm" id="prod_name_search_filter" placeholder="🔍 Search existing products by Name, SKU, Brand..." autocomplete="off">
                  </div>
                  <div class="d-flex justify-content-between align-items-center px-2 py-1 mb-1 border-bottom bg-light rounded">
                    <span class="small text-muted fw-semibold" id="prod_combobox_count"><?php echo count($all_products_selector); ?> products</span>
                    <button type="button" class="btn btn-link btn-sm p-0 text-success text-decoration-none fw-bold small" onclick="switchToNewProductMode(); bootstrap.Dropdown.getInstance(document.getElementById('btn_product_selector_toggle'))?.hide();">
                      <i class="bi bi-plus-circle me-1"></i> + New Product (Blank)
                    </button>
                  </div>
                  <div id="prod_name_items_list" style="max-height: 220px; overflow-y: auto;">
                    <?php foreach ($all_products_selector as $ap): ?>
                      <button type="button" 
                              class="dropdown-item py-1.5 px-2 border-bottom d-flex flex-column prod-combobox-item text-wrap text-start"
                              data-id="<?php echo $ap['id']; ?>"
                              data-name="<?php echo htmlspecialchars($ap['name']); ?>"
                              data-sku="<?php echo htmlspecialchars($ap['sku']); ?>"
                              data-brand="<?php echo htmlspecialchars($ap['brand_name'] ?? ''); ?>"
                              onclick="selectProductToEdit(<?php echo $ap['id']; ?>)">
                        <span class="fw-bold text-navy" style="font-size: 0.85rem;"><?php echo htmlspecialchars($ap['name']); ?></span>
                        <span class="text-muted" style="font-size: 0.75rem;">
                          <span class="badge bg-light text-dark border me-1">SKU: <?php echo htmlspecialchars($ap['sku']); ?></span>
                          <?php if (!empty($ap['brand_name'])): ?>
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle me-1"><?php echo htmlspecialchars($ap['brand_name']); ?></span>
                          <?php endif; ?>
                          <span>ID: #<?php echo $ap['id']; ?></span>
                        </span>
                      </button>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
              <div class="form-text small text-muted">
                Type product name directly, or click <strong>Select Existing</strong> to choose a product to edit.
              </div>
            </div>

            <!-- 2. SKU Code -->
            <div class="col-md-3">
              <label class="form-label small fw-semibold">SKU Code *</label>
              <input type="text" name="sku" id="prod_sku" class="form-control" required placeholder="RP-OCT26-001">
              <div class="form-text small text-muted">Unique inventory SKU.</div>
            </div>

            <!-- 3. Brand Name Dropdown -->
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Brand Name</label>
              <div class="input-group">
                <select name="brand_name" id="prod_brand_name" class="form-select">
                  <option value="">-- Select / Generic --</option>
                  <?php foreach ($distinct_brands as $b): ?>
                    <option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-secondary" onclick="promptAddNewBrand()" title="Add New Brand Name">
                  <i class="bi bi-plus-lg"></i>
                </button>
                <button type="button" class="btn btn-outline-primary" onclick="openRenameBrandModal()" title="Edit an existing brand name">
                  <i class="bi bi-pencil-square"></i>
                </button>
              </div>
              <div class="form-text small text-muted">Select or click + to add brand.</div>
            </div>

            <!-- 4. Category Dropdown -->
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Category *</label>
              <div class="input-group">
                <select name="category_id" id="prod_category_id" class="form-select" required>
                  <?php foreach ($categories as $c): ?>
                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-secondary" onclick="openAddCategoryModal()" title="Add New Category">
                  <i class="bi bi-plus-lg"></i>
                </button>
              </div>
              <div class="form-text small text-muted">Select or click + to add category.</div>
            </div>

            <!-- 5. Notebook Size Dropdown -->
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Notebook Size *</label>
              <div class="input-group">
                <select name="size" id="prod_size" class="form-select" required>
                  <?php foreach ($all_sizes as $s): ?>
                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo ($s === 'A4 Size') ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-secondary" onclick="promptAddNewSize()" title="Add New Notebook Size">
                  <i class="bi bi-plus-lg"></i>
                </button>
              </div>
              <div class="form-text small text-muted">Select size or click + to add.</div>
            </div>

            <!-- 6. Binding Dropdown (Soft Binding & Hard Binding) -->
            <div class="col-md-5">
              <label class="form-label small fw-semibold">Binding Option *</label>
              <div class="input-group">
                <select name="binding" id="prod_binding" class="form-select" required>
                  <option value="Soft Binding">Soft Binding</option>
                  <option value="Hard Binding">Hard Binding</option>
                  <?php foreach ($other_existing_bindings as $bnd): ?>
                    <option value="<?php echo htmlspecialchars($bnd); ?>"><?php echo htmlspecialchars($bnd); ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-outline-secondary" onclick="promptAddNewBinding()" title="Add New Binding Option">
                  <i class="bi bi-plus-lg"></i>
                </button>
              </div>
              <div class="form-text small text-muted">Soft Binding or Hard Binding.</div>
            </div>

            <div class="col-12" id="productVariantManager">
              <div class="border rounded-3 p-2 bg-white shadow-xs">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2 pb-2 border-bottom">
                  <div>
                    <h6 class="fw-bold text-navy mb-0 d-flex align-items-center">
                      <i class="bi bi-diagram-3-fill text-gold me-2 fs-5"></i>
                      <span>Product Varieties, Individual Pricing &amp; Bundle Stock</span>
                    </h6>
                    <div class="small text-muted mt-0.5">
                      Each variety maintains its own bundle count, stock, MRP, GST, selling price, and discount.
                    </div>
                  </div>
                  <button type="button" class="btn btn-sm text-white fw-semibold px-3 shadow-xs" style="background-color: #0F2C59;" onclick="addProductVariantRow()">
                    <i class="bi bi-plus-circle me-1 text-gold"></i> Add Variety
                  </button>
                </div>

                <!-- Varieties Table Container -->
                <div class="table-responsive product-variants-scroll border rounded-2">
                  <table class="table table-sm table-hover align-middle mb-0" id="productVariantsTable">
                    <thead class="table-light text-navy text-nowrap sticky-top border-bottom" style="font-size: 0.78rem; z-index: 5;">
                      <tr>
                        <th style="width: 7%;">Variety Code *</th>
                        <th style="width: 9%;">Variety Name *</th>
                        <th style="width: 7%;" class="text-center" title="Individual bundle count (pieces per bundle) for this variety">Bundle Count *</th>
                        <th style="width: 7%;" class="text-center text-primary" title="Available bundles in stock">Bundle Stock</th>
                        <th style="width: 6%;" class="text-center" title="Loose individual pieces in stock">Loose Pcs</th>
                        <th style="width: 7%;" class="text-center text-success" title="Total pieces = (Bundles × Bundle Count) + Loose Pcs">Total Stock</th>
                        <th style="width: 6%;" title="Maximum retail price per piece">MRP (₹)</th>
                        <th style="width: 7%;" title="Calculated as half of MRP">Sale (₹)</th>
                        <th style="width: 5%;" title="GST percentage; blank inherits product GST">GST (%)</th>
                        <th style="width: 7%;" title="Sale price including GST">Sell Price (₹)</th>
                        <th style="width: 6%;" title="Discount percentage">Discount (%)</th>
                        <th style="width: 7%;" title="Final price after discount, including GST">Disc Price (₹)</th>
                        <th style="width: 9%;">SKU / Code</th>
                        <th style="width: 4%;" class="text-center">Action</th>
                      </tr>
                    </thead>
                    <tbody id="productVariantRows">
                      <!-- Populated dynamically via JS -->
                    </tbody>
                  </table>
                </div>

                <div id="productVariantEmptyNotice" class="text-center py-2 text-muted d-none border rounded-2 bg-light-subtle mt-2">
                  <i class="bi bi-diagram-2 fs-3 text-secondary d-block mb-1"></i>
                  <span class="small fw-semibold">No varieties added yet for this notebook.</span>
                  <p class="small text-muted mb-2">Add varieties like Ruled (SR), Unruled (UR), Maths Plain (MP) with their individual prices and bundle counts.</p>
                  <button type="button" class="btn btn-sm btn-outline-primary" onclick="addProductVariantRow()">
                    <i class="bi bi-plus-lg me-1"></i> Add First Variety
                  </button>
                </div>

                <div class="form-text small text-muted mt-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                  <span><i class="bi bi-info-circle me-1 text-primary"></i> Sale Price is half of MRP; Sell Price includes GST.</span>
                  <span class="badge bg-light text-dark border"><i class="bi bi-arrow-repeat me-1 text-success"></i> Stock and totals auto-calculate in real time</span>
                </div>
              </div>
            </div>

            <!-- Hidden std_qty preserves backend calculations without showing duplicate field in UI -->
            <input type="hidden" name="std_qty" id="prod_std_qty" value="">
            <div class="col-md-4">
              <label class="form-label small fw-semibold"> Stock In Hand (Total Pcs) *</label>
              <input type="number" name="stock" id="prod_stock" class="form-control" required value="50" min="0">
              <div class="form-text small text-muted" id="prod_stock_help">Product stock is calculated from its variety inventory.</div>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Available Bundles *</label>
              <input type="number" name="bundle_stock" id="prod_bundle_stock" class="form-control" required value="0" min="0" readonly>
              <div class="form-text" id="prod_loose_stock_help">0 loose pieces remaining</div>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Page Count *</label>
              <input type="number" name="pages" id="prod_pages" class="form-control" required value="192">
            </div>

            <div class="col-md-3">
              <label class="form-label small fw-semibold">GSM * (e.g. 58, 70 GSM)</label>
              <input type="number" name="gsm" id="prod_gsm" class="form-control" required value="70" placeholder="e.g. 58">
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Paper Type</label>
              <input type="text" name="paper_type" id="prod_paper_type" class="form-control" list="paper_type_datalist" value="Smooth Bright Paper" placeholder="Select or type paper grade">
              <datalist id="paper_type_datalist">
                <?php foreach ($distinct_paper_types as $pt): ?>
                  <option value="<?php echo htmlspecialchars($pt); ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-5">
              <label class="form-label small fw-semibold">Cover Type</label>
              <input type="text" name="cover_type" id="prod_cover_type" class="form-control" list="cover_type_datalist" value="Gloss Laminated" placeholder="Select or type cover finish">
              <datalist id="cover_type_datalist">
                <?php foreach ($distinct_cover_types as $ct): ?>
                  <option value="<?php echo htmlspecialchars($ct); ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>

            <div class="col-12">
              <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label small fw-semibold mb-0">Description *</label>
                <button type="button" class="btn btn-sm btn-gold-subtle text-navy border border-warning-subtle fw-bold shadow-sm" onclick="generateAIDescription()" title="Generate natural description based on entered specs">
                  <i class="bi bi-magic text-gold me-1"></i> AI Generate Description
                </button>
              </div>
              <textarea name="description" id="prod_description" rows="3" class="form-control" required placeholder="Enter description or click AI Generate Description..."></textarea>
              <div id="ai_desc_notice" class="form-text text-success d-none fw-semibold"><i class="bi bi-check-circle-fill me-1"></i> Description generated by AI! You can review and edit it before saving.</div>
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-semibold">Main Image (JPG, PNG, WEBP)</label>
              <input type="file" name="image" class="form-control">
              <div id="prod_image_preview_box" class="mt-1 small text-muted d-none">
                <span>Current: </span><code id="prod_image_preview_name" class="text-navy"></code>
              </div>
            </div>

            <div class="col-md-6">
              <div class="pt-2">
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="checkbox" name="featured" id="prod_featured" value="1">
                  <label class="form-check-label small" for="prod_featured">Featured</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="checkbox" name="bestseller" id="prod_bestseller" value="1">
                  <label class="form-check-label small" for="prod_bestseller">Bestseller</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="checkbox" name="new_arrival" id="prod_new_arrival" value="1">
                  <label class="form-check-label small" for="prod_new_arrival">New Arrival</label>
                </div>
                <div class="form-check form-check-inline">
                  <input class="form-check-input" type="checkbox" name="active" id="prod_active" value="1" checked>
                  <label class="form-check-label small" for="prod_active">Active</label>
                </div>
              </div>
            </div>

            <div class="col-12 border-top pt-2">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="manual_verification_flag" id="prod_manual_verification_flag" value="1">
                <label class="form-check-label small text-danger fw-semibold" for="prod_manual_verification_flag">
                  Flag for Manual Price Verification (e.g. PDF text/price unclear)
                </label>
              </div>
              <input type="text" name="manual_verification_notes" id="prod_manual_verification_notes" class="form-control form-control-sm mt-1" placeholder="Enter reason for manual verification...">
            </div>

          </div>

        </div>
        <div class="modal-footer bg-light d-flex justify-content-between align-items-center">
          <div>
            <button type="button" class="btn btn-outline-danger btn-sm px-3 d-none" id="modalDeleteProductBtn" onclick="deleteFromModal()" title="Permanently delete this product from database">
              <i class="bi bi-trash me-1"></i> Delete Product
            </button>
          </div>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-gold btn-sm px-4 fw-bold" id="saveProductSubmitBtn">
              <i class="bi bi-check-circle-fill me-1"></i> Save Product
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Rename an existing product brand from Product Management -->
<div class="modal fade" id="renameBrandModal" tabindex="-1" aria-labelledby="renameBrandModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-3">
      <div class="modal-header text-white" style="background-color: #17243A;">
        <h5 class="modal-title fw-bold fs-6" id="renameBrandModalLabel">
          <i class="bi bi-pencil-square text-gold me-2"></i> Edit Brand Name
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="renameBrandForm" onsubmit="submitRenameBrand(event)">
        <div class="modal-body">
          <div class="mb-3">
            <label for="renameBrandCurrent" class="form-label small fw-semibold">Existing Brand</label>
            <select id="renameBrandCurrent" class="form-select" required>
              <option value="">-- Select Brand --</option>
              <?php foreach ($distinct_brands as $b): ?>
                <option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="renameBrandNew" class="form-label small fw-semibold">Correct Brand Name</label>
            <input type="text" id="renameBrandNew" class="form-control" maxlength="100" required>
            <div class="form-text">This renames the brand for every product currently using it.</div>
          </div>
          <div id="renameBrandFeedback" class="small mt-2 d-none" role="status"></div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm fw-semibold" id="renameBrandSubmit">
            <i class="bi bi-check-circle me-1"></i> Save Brand Name
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Quick Add Category Modal (Safe, Intentional Workflow) -->
<div class="modal fade" id="quickCategoryModal" tabindex="-1" aria-labelledby="quickCategoryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-3">
      <div class="modal-header text-white" style="background-color: #17243A;">
        <h5 class="modal-title fw-bold fs-6" id="quickCategoryModalLabel">
          <i class="bi bi-folder-plus text-gold me-2"></i> Add New Category
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="quickCategoryForm" onsubmit="handleQuickAddCategory(event)">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label small fw-bold">Category Name *</label>
            <input type="text" class="form-control" id="quick_cat_name" required placeholder="e.g. Drawing Books, College Registers">
            <div class="form-text small text-muted">Checked for uniqueness. Avoids duplicate categories.</div>
          </div>
          <div class="mb-2">
            <label class="form-label small fw-bold">Description (Optional)</label>
            <textarea class="form-control" id="quick_cat_desc" rows="2" placeholder="Brief description for category..."></textarea>
          </div>
          <div id="quick_cat_feedback" class="small mt-2 d-none"></div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-gold btn-sm px-4 fw-bold" id="btnSubmitQuickCat">
            <i class="bi bi-check-circle-fill me-1"></i> Create Category
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
// Complete in-memory product index for fast client-side switching without latency
window.allProductsData = <?php echo json_encode($all_products_selector, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.allProductsMap = {};
if (Array.isArray(window.allProductsData)) {
  window.allProductsData.forEach(function(item) {
    window.allProductsMap[item.id] = item;
  });
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/[&<>"']/g, function(m) {
    return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'}[m];
  });
}

// Ensure option exists in select, or add dynamically so no custom data is lost
function ensureSelectOption(selectEl, value, label) {
  if (!selectEl || !value) return;
  const strVal = String(value).trim();
  if (!strVal) return;
  for (let i = 0; i < selectEl.options.length; i++) {
    if (selectEl.options[i].value.toLowerCase() === strVal.toLowerCase()) {
      selectEl.selectedIndex = i;
      return;
    }
  }
  const opt = document.createElement('option');
  opt.value = strVal;
  opt.textContent = label || strVal;
  selectEl.appendChild(opt);
  selectEl.value = strVal;
}

// Normalize and set Binding dropdown value
function setBindingValue(val) {
  const select = document.getElementById('prod_binding');
  if (!select) return;
  if (!val) {
    select.value = 'Soft Binding';
    return;
  }
  const raw = String(val).trim();
  const lower = raw.toLowerCase();

  // 1. Direct case/whitespace insensitive match
  for (let i = 0; i < select.options.length; i++) {
    if (select.options[i].value.trim().toLowerCase() === lower) {
      select.selectedIndex = i;
      return;
    }
  }

  // 2. Handle aliases: "Hard Bind" <-> "Hard Binding"
  if (lower === 'hard bind' || lower === 'hard binding') {
    for (let i = 0; i < select.options.length; i++) {
      const v = select.options[i].value.toLowerCase();
      if (v === 'hard binding' || v === 'hard bind') {
        select.selectedIndex = i;
        return;
      }
    }
  }

  // 3. Handle aliases: "Soft Bind" <-> "Soft Binding"
  if (lower === 'soft bind' || lower === 'soft binding') {
    for (let i = 0; i < select.options.length; i++) {
      const v = select.options[i].value.toLowerCase();
      if (v === 'soft binding' || v === 'soft bind') {
        select.selectedIndex = i;
        return;
      }
    }
  }

  // 4. Preserve any other existing valid binding from database
  ensureSelectOption(select, raw, raw);
}

let productVariantRowIndex = 0;

function checkProductVariantEmptyNotice() {
  const tbody = document.getElementById('productVariantRows');
  const emptyNotice = document.getElementById('productVariantEmptyNotice');
  if (!tbody || !emptyNotice) return;
  const activeCount = tbody.querySelectorAll('.js-product-variant-row:not(.d-none)').length;
  if (activeCount === 0) {
    emptyNotice.classList.remove('d-none');
  } else {
    emptyNotice.classList.add('d-none');
  }
}

function renderProductVariants(variants) {
  const manager = document.getElementById('productVariantManager');
  const rows = document.getElementById('productVariantRows');
  const manageInput = document.getElementById('manage_variants');
  if (!manager || !rows || !manageInput) return;

  manager.classList.remove('d-none');
  rows.innerHTML = '';
  productVariantRowIndex = 0;
  const list = variants || [];
  list.forEach(function(variant) {
    appendProductVariantRow(variant);
  });
  checkProductVariantEmptyNotice();
  updateManageVariantsFlag();
  syncProductTotalStockFromVariants();
}

// Keep the manage_variants flag in sync with variety rows.
function updateManageVariantsFlag() {
  const rows = document.getElementById('productVariantRows');
  const manageInput = document.getElementById('manage_variants');
  if (!rows || !manageInput) return;
  const allRows = rows.querySelectorAll('.js-product-variant-row').length;
  if (allRows > 0) manageInput.dataset.active = '1';
  manageInput.value = allRows > 0 || manageInput.dataset.active === '1' ? '1' : '0';
}

function syncProductTotalStockFromVariants() {
  const rows = document.getElementById('productVariantRows');
  const stockInput = document.getElementById('prod_stock');
  const bundleStockInput = document.getElementById('prod_bundle_stock');
  const stockHelp = document.getElementById('prod_stock_help');
  const stdQtyHidden = document.getElementById('prod_std_qty');
  if (!rows) return;

  const activeRows = rows.querySelectorAll('.js-product-variant-row:not(.d-none)');
  if (activeRows.length > 0) {
    let sumBundles = 0;
    let sumTotalPieces = 0;
    let firstBundleQty = 0;

    activeRows.forEach(function(rowEl, idx) {
      const bQty = Math.max(1, parseInt(rowEl.querySelector('.js-variant-bundle-qty')?.value, 10) || 1);
      const bStock = Math.max(0, parseInt(rowEl.querySelector('.js-variant-bundle-stock')?.value, 10) || 0);
      const lStock = Math.max(0, parseInt(rowEl.querySelector('.js-variant-loose-stock')?.value, 10) || 0);
      const totPcs = (bStock * bQty) + lStock;
      sumBundles += bStock;
      sumTotalPieces += totPcs;
      if (idx === 0) firstBundleQty = bQty;
    });

    if (stockInput) {
      stockInput.value = sumTotalPieces;
      stockInput.readOnly = true;
    }
    if (bundleStockInput) {
      bundleStockInput.value = sumBundles;
    }
    if (stdQtyHidden && firstBundleQty > 0) {
      stdQtyHidden.value = firstBundleQty;
    }
    if (stockHelp) {
      stockHelp.innerHTML = '<span class="text-primary fw-semibold"><i class="bi bi-arrow-repeat me-1"></i>Auto-synced from varieties (' + sumBundles + ' bdls, ' + sumTotalPieces + ' total pcs)</span>';
    }
    updateBundleStockCalculation(true);
  } else {
    if (stockInput) {
      stockInput.readOnly = false;
    }
    if (stockHelp) {
      stockHelp.textContent = 'Enter piece stock in hand, or add product varieties above.';
    }
  }
}

function appendProductVariantRow(variant) {
  const tbody = document.getElementById('productVariantRows');
  if (!tbody) return;
  const emptyNotice = document.getElementById('productVariantEmptyNotice');
  if (emptyNotice) emptyNotice.classList.add('d-none');

  const row = variant || {};
  const index = productVariantRowIndex++;
  const tr = document.createElement('tr');
  tr.className = 'js-product-variant-row';
  tr.dataset.variantId = String(parseInt(row.id, 10) || 0);

  const fallbackBundle = Math.max(1, parseInt(document.getElementById('prod_std_qty')?.value, 10) || 400);
  const bundleQty = Math.max(1, parseInt(row.bundle_qty, 10) || fallbackBundle);
  const bundleStock = Math.max(0, parseInt(row.bundle_stock, 10) || 0);
  const looseStock = Math.max(0, parseInt(row.loose_stock, 10) || 0);
  const totalStock = (bundleStock * bundleQty) + looseStock;

  tr.innerHTML = `
    <input type="hidden" name="variants[${index}][id]" value="${parseInt(row.id, 10) || 0}">
    <input type="hidden" name="variants[${index}][delete]" value="0" class="js-variant-delete-flag">
    <td>
      <input type="text" name="variants[${index}][variety]" class="form-control form-control-sm text-uppercase fw-semibold" placeholder="e.g. SR" maxlength="40" required value="${escapeHtml(row.variety || '')}">
    </td>
    <td>
      <input type="text" name="variants[${index}][variety_display]" class="form-control form-control-sm" placeholder="e.g. Ruled" maxlength="100" required value="${escapeHtml(row.variety_display || '')}">
    </td>
    <td class="text-center">
      <input type="number" name="variants[${index}][bundle_qty]" class="form-control form-control-sm text-center fw-bold js-variant-bundle-qty" min="1" step="1" required value="${bundleQty}" title="Bundle Count (Pcs per Bundle)">
    </td>
    <td class="text-center">
      <input type="number" name="variants[${index}][bundle_stock]" class="form-control form-control-sm text-center fw-semibold text-primary border-primary-subtle js-variant-bundle-stock" min="0" step="1" value="${bundleStock}" title="Bundles in stock">
    </td>
    <td class="text-center">
      <input type="number" name="variants[${index}][loose_stock]" class="form-control form-control-sm text-center js-variant-loose-stock" min="0" step="1" value="${looseStock}" title="Loose pieces in stock">
    </td>
    <td class="text-center">
      <input type="number" name="variants[${index}][stock]" class="form-control form-control-sm text-center bg-light fw-bold text-success border-success-subtle js-variant-total-stock" readonly value="${totalStock}" title="Total Pieces">
    </td>
    <td>
      <input type="number" name="variants[${index}][mrp]" class="form-control form-control-sm text-end" min="0.01" step="0.01" placeholder="Optional" value="${row.mrp == null ? '' : escapeHtml(row.mrp)}" title="Maximum retail price per piece">
    </td>
    <td>
      <input type="number" name="variants[${index}][sale_price]" class="form-control form-control-sm text-end js-variant-sale-price" min="0.01" step="0.01" readonly value="${row.sale_price == null ? (row.price == null ? '' : escapeHtml(row.price)) : escapeHtml(row.sale_price)}" title="Calculated as half of MRP">
    </td>
    <td>
      <input type="number" name="variants[${index}][gst_rate]" class="form-control form-control-sm text-end js-variant-gst-rate" min="0" max="100" step="0.01" placeholder="18" value="${row.gst_rate == null ? '' : escapeHtml(row.gst_rate)}" title="GST percentage; blank uses 18%">
    </td>
    <td>
      <input type="number" class="form-control form-control-sm text-end js-variant-sell-price" readonly title="Sale price including GST">
    </td>
    <td>
      <input type="number" name="variants[${index}][discount_percent]" class="form-control form-control-sm text-end js-variant-discount-percent" min="0" max="99.99" step="0.01" placeholder="0" title="Discount percentage">
    </td>
    <td>
      <input type="hidden" name="variants[${index}][selling_price]" class="js-variant-selling-price-base">
      <input type="hidden" name="variants[${index}][discounted_price]" class="js-variant-discounted-price-base">
      <input type="number" class="form-control form-control-sm text-end js-variant-discounted-price" readonly title="Final price after discount, including GST">
    </td>
    <td>
      <span class="badge bg-light text-secondary border font-monospace text-truncate d-block text-start py-1" style="max-width: 140px; font-size: 0.72rem;" title="${escapeHtml(row.product_code || '')}">${row.product_code ? escapeHtml(row.product_code) : '<span class="text-muted fst-italic">Auto-gen</span>'}</span>
    </td>
    <td class="text-center">
      <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1.5 js-remove-product-variant" title="Remove this variety">
        <i class="bi bi-trash"></i>
      </button>
    </td>`;

  const recalcRowStock = function() {
    const bQty = Math.max(1, parseInt(tr.querySelector('.js-variant-bundle-qty').value, 10) || 1);
    const bStock = Math.max(0, parseInt(tr.querySelector('.js-variant-bundle-stock').value, 10) || 0);
    const lStock = Math.max(0, parseInt(tr.querySelector('.js-variant-loose-stock').value, 10) || 0);
    const tot = (bStock * bQty) + lStock;
    tr.querySelector('.js-variant-total-stock').value = tot;
    syncProductTotalStockFromVariants();
  };

  tr.querySelector('.js-variant-bundle-qty').addEventListener('input', recalcRowStock);
  tr.querySelector('.js-variant-bundle-stock').addEventListener('input', recalcRowStock);
  tr.querySelector('.js-variant-loose-stock').addEventListener('input', recalcRowStock);

  const mrpInput = tr.querySelector('input[name$="[mrp]"]');
  const salePriceInput = tr.querySelector('.js-variant-sale-price');
  const gstRateInput = tr.querySelector('.js-variant-gst-rate');
  const sellPriceInput = tr.querySelector('.js-variant-sell-price');
  const discountPercentInput = tr.querySelector('.js-variant-discount-percent');
  const sellingPriceBaseInput = tr.querySelector('.js-variant-selling-price-base');
  const discountedPriceBaseInput = tr.querySelector('.js-variant-discounted-price-base');
  const discountedPriceInput = tr.querySelector('.js-variant-discounted-price');
  const originalSalePrice = parseFloat(row.sale_price || row.price) || 0;
  const originalSellingPrice = parseFloat(row.selling_price) || 0;
  const originalDiscountedPrice = parseFloat(row.discounted_price) || 0;
  if (originalDiscountedPrice > 0 && originalSalePrice > 0) {
    discountPercentInput.value = Math.max(0, Math.min(100,
      (1 - (originalDiscountedPrice / originalSalePrice)) * 100
    )).toFixed(2).replace(/\.?0+$/, '');
  }

  const recalculatePrices = function() {
    const mrp = parseFloat(mrpInput.value) || 0;
    const salePrice = mrp > 0 ? Math.round((mrp / 2) * 100) / 100 : originalSalePrice;
    const gstRate = gstRateInput.value === '' ? 18 : Math.max(0, parseFloat(gstRateInput.value) || 0);
    const discountPercent = Math.max(0, Math.min(99.99, parseFloat(discountPercentInput.value) || 0));
    const sellPrice = salePrice > 0
      ? Math.round(salePrice * (1 + gstRate / 100) * 100) / 100
      : (originalSellingPrice > 0 ? originalSellingPrice : 0);
    const discountedBasePrice = salePrice > 0
      ? Math.round(salePrice * (1 - discountPercent / 100) * 100) / 100
      : 0;
    const discountedSellPrice = discountedBasePrice > 0
      ? Math.round(discountedBasePrice * (1 + gstRate / 100) * 100) / 100
      : 0;

    salePriceInput.value = salePrice > 0 ? salePrice.toFixed(2) : '';
    sellPriceInput.value = sellPrice > 0 ? sellPrice.toFixed(2) : '';
    sellingPriceBaseInput.value = salePrice > 0 ? sellPrice.toFixed(2) : '';
    discountedPriceInput.value = discountedSellPrice > 0 ? discountedSellPrice.toFixed(2) : '';
    discountedPriceBaseInput.value = discountPercent > 0 && salePrice > 0
      ? discountedBasePrice.toFixed(2)
      : '';
  };
  mrpInput.addEventListener('input', recalculatePrices);
  gstRateInput.addEventListener('input', recalculatePrices);
  discountPercentInput.addEventListener('input', recalculatePrices);
  recalculatePrices();

  tr.querySelector('.js-remove-product-variant').addEventListener('click', function() {
    const id = parseInt(tr.dataset.variantId, 10) || 0;
    if (id > 0) {
      tr.querySelector('.js-variant-delete-flag').value = '1';
      tr.classList.add('d-none');
    } else {
      tr.remove();
    }
    updateManageVariantsFlag();
    syncProductTotalStockFromVariants();
    checkProductVariantEmptyNotice();
  });

  tbody.appendChild(tr);
  updateManageVariantsFlag();
}

function addProductVariantRow() {
  const currentStd = Math.max(1, parseInt(document.getElementById('prod_std_qty')?.value, 10) || 400);
  appendProductVariantRow({
    id: 0,
    variety: '',
    variety_display: '',
    bundle_qty: currentStd,
    bundle_stock: 0,
    loose_stock: 0,
    stock: 0,
    mrp: null,
    price: null,
    sale_price: null,
    gst_rate: null,
    selling_price: null,
    discounted_price: null
  });
  syncProductTotalStockFromVariants();
  checkProductVariantEmptyNotice();
}

// Populate product form with an existing product record
function populateProductForm(p) {
  if (!p) return;

  // Set ID and Hidden Fields
  document.getElementById('prod_id').value = p.id;
  document.getElementById('prod_current_image').value = p.image || '';

  // Show Delete button in modal footer for edit mode
  const modalDelBtn = document.getElementById('modalDeleteProductBtn');
  if (modalDelBtn) {
    modalDelBtn.classList.remove('d-none');
    modalDelBtn.dataset.id = p.id;
    modalDelBtn.dataset.name = p.name || '';
    modalDelBtn.dataset.sku = p.sku || '';
  }

  // Update Modal Title and Badges
  document.getElementById('modalTitle').innerHTML = '<i class="bi bi-pencil-square text-gold me-2 fs-5"></i><span>Edit Product #' + p.id + ' &mdash; ' + escapeHtml(p.name) + '</span>';
  
  const modeIndicator = document.getElementById('product_mode_indicator');
  if (modeIndicator) {
    modeIndicator.className = 'badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold px-2 py-0.5';
    modeIndicator.innerHTML = '<i class="bi bi-pencil-square me-1"></i> Editing #' + p.id + ' (' + escapeHtml(p.sku) + ')';
  }

  const submitBtn = document.getElementById('saveProductSubmitBtn');
  if (submitBtn) {
    submitBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Update Product #' + p.id;
  }

  // Populate Name and SKU
  document.getElementById('prod_name').value = p.name || '';
  document.getElementById('prod_sku').value = p.sku || '';

  // Brand Name Dropdown
  const brandSelect = document.getElementById('prod_brand_name');
  if (p.brand_name) {
    ensureSelectOption(brandSelect, p.brand_name, p.brand_name);
  } else {
    brandSelect.value = '';
  }

  // Category Dropdown
  const catSelect = document.getElementById('prod_category_id');
  if (catSelect) {
    catSelect.value = p.category_id;
  }

  // Size Dropdown
  const sizeSelect = document.getElementById('prod_size');
  if (p.size) {
    ensureSelectOption(sizeSelect, p.size, p.size);
  }

  // Set Binding Option (Soft Binding / Hard Binding)
  setBindingValue(p.binding);

  const manageInput = document.getElementById('manage_variants');
  if (manageInput) {
    manageInput.dataset.active = p.varieties_managed == 1 ? '1' : '0';
  }
  renderProductVariants(p.variants || []);

  // Quantities
  document.getElementById('prod_std_qty').value = p.std_qty || '';
  if (!p.variants || p.variants.length === 0) {
    document.getElementById('prod_stock').value = (p.stock !== undefined) ? p.stock : 50;
    document.getElementById('prod_stock').readOnly = false;
    document.getElementById('prod_bundle_stock').value = (p.bundle_stock !== undefined) ? p.bundle_stock : 0;
    const stockHelp = document.getElementById('prod_stock_help');
    if (stockHelp) {
      stockHelp.textContent = 'Enter piece stock in hand.';
    }
  } else {
    syncProductTotalStockFromVariants();
  }
  document.getElementById('prod_pages').value = p.pages || 192;
  document.getElementById('prod_gsm').value = p.gsm || 70;
  document.getElementById('prod_paper_type').value = p.paper_type || 'Smooth Bright Paper';
  document.getElementById('prod_cover_type').value = p.cover_type || 'Gloss Laminated';
  document.getElementById('prod_description').value = p.description || '';

  // Image info preview
  const imgBox = document.getElementById('prod_image_preview_box');
  const imgName = document.getElementById('prod_image_preview_name');
  if (p.image) {
    if (imgBox) imgBox.classList.remove('d-none');
    if (imgName) imgName.textContent = p.image;
  } else {
    if (imgBox) imgBox.classList.add('d-none');
  }

  // Checkboxes
  document.getElementById('prod_featured').checked = (p.featured == 1);
  document.getElementById('prod_bestseller').checked = (p.bestseller == 1);
  document.getElementById('prod_new_arrival').checked = (p.new_arrival == 1);
  document.getElementById('prod_active').checked = (p.active == 1);
  document.getElementById('prod_manual_verification_flag').checked = (p.manual_verification_flag == 1);
  document.getElementById('prod_manual_verification_notes').value = p.manual_verification_notes || '';
  updateBundleStockCalculation(true);
}

function updateBundleStockCalculation(preserveExistingBundle = false) {
  const standardQuantity = Math.max(1, parseInt(document.getElementById('prod_std_qty')?.value, 10) || 400);
  const stockInHand = Math.max(0, parseInt(document.getElementById('prod_stock')?.value, 10) || 0);
  if (!preserveExistingBundle) {
    const bundles = Math.floor(stockInHand / standardQuantity);
    const bundleStockEl = document.getElementById('prod_bundle_stock');
    if (bundleStockEl) bundleStockEl.value = bundles;
  }
  const effectiveBundles = parseInt(document.getElementById('prod_bundle_stock')?.value, 10) || 0;
  const loosePieces = Math.max(0, stockInHand - (effectiveBundles * standardQuantity));
  const looseHelp = document.getElementById('prod_loose_stock_help');
  if (looseHelp) {
    looseHelp.textContent = loosePieces + ' loose piece' + (loosePieces === 1 ? '' : 's') + ' remaining';
  }
}

// Intentionally select another existing product to load from combobox
function selectProductToEdit(prodId) {
  const pId = parseInt(prodId, 10);
  if (!pId) return;

  // Close dropdown menu
  const dropdownToggleBtn = document.getElementById('btn_product_selector_toggle');
  if (dropdownToggleBtn && window.bootstrap) {
    const inst = bootstrap.Dropdown.getInstance(dropdownToggleBtn);
    if (inst) inst.hide();
  }

  fetch('products.php?action=get_product_json&id=' + pId)
    .then(res => res.json())
    .then(data => {
      if (data.success && data.product) {
        window.allProductsMap[pId] = data.product;
        populateProductForm(data.product);
      } else {
        alert(data.message || 'Could not load product details.');
      }
    })
    .catch(err => {
      console.error(err);
      alert('Network error loading product details.');
    });
}

// Switch to Add New Product Mode safely
function switchToNewProductMode() {
  document.getElementById('modalTitle').innerHTML = '<i class="bi bi-journal-plus text-gold me-2 fs-5"></i><span>Add New Product</span>';
  document.getElementById('prod_id').value = '0';
  document.getElementById('prod_current_image').value = '';
  document.getElementById('productVariantManager').classList.remove('d-none');
  document.getElementById('manage_variants').dataset.active = '1';
  
  // Initialize with 1 clean default variety row ready for immediate editing
  productVariantRowIndex = 0;
  document.getElementById('productVariantRows').innerHTML = '';
  appendProductVariantRow({
    id: 0,
    variety: 'SR',
    variety_display: 'Single Line (Ruled)',
    bundle_qty: 400,
    bundle_stock: 0,
    loose_stock: 0,
    stock: 0,
    selling_price: null,
    discounted_price: null
  });
  checkProductVariantEmptyNotice();
  updateManageVariantsFlag();

  const modeIndicator = document.getElementById('product_mode_indicator');
  if (modeIndicator) {
    modeIndicator.className = 'badge bg-success-subtle text-success border border-success-subtle fw-semibold px-2 py-0.5';
    modeIndicator.innerHTML = '<i class="bi bi-plus-circle me-1"></i> New Product Mode';
  }

  const submitBtn = document.getElementById('saveProductSubmitBtn');
  if (submitBtn) {
    submitBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Save New Product';
  }

  // Clear fields to defaults
  document.getElementById('prod_name').value = '';
  document.getElementById('prod_sku').value = '';
  document.getElementById('prod_brand_name').value = '';
  document.getElementById('prod_std_qty').value = '400';
  document.getElementById('prod_size').value = 'A4 Size';
  document.getElementById('prod_binding').value = 'Soft Binding';
  document.getElementById('prod_paper_type').value = 'Smooth Bright Paper';
  document.getElementById('prod_cover_type').value = 'Gloss Laminated';
  document.getElementById('prod_description').value = '';
  document.getElementById('prod_stock').value = '0';
  document.getElementById('prod_bundle_stock').value = '0';
  document.getElementById('prod_pages').value = '192';
  document.getElementById('prod_gsm').value = '70';

  syncProductTotalStockFromVariants();

  const imgBox = document.getElementById('prod_image_preview_box');
  if (imgBox) imgBox.classList.add('d-none');

  document.getElementById('prod_featured').checked = false;
  document.getElementById('prod_bestseller').checked = false;
  document.getElementById('prod_new_arrival').checked = false;
  document.getElementById('prod_active').checked = true;
  document.getElementById('prod_manual_verification_flag').checked = false;
  document.getElementById('prod_manual_verification_notes').value = '';
  const modalDelBtn = document.getElementById('modalDeleteProductBtn');
  if (modalDelBtn) {
    modalDelBtn.classList.add('d-none');
    modalDelBtn.dataset.id = '0';
    modalDelBtn.dataset.name = '';
    modalDelBtn.dataset.sku = '';
  }

  updateBundleStockCalculation();
}

function resetForm() {
  switchToNewProductMode();
}

// Permanently delete product with confirmation and secure POST form
function confirmDeleteProduct(id, name, sku) {
  const prodId = parseInt(id, 10);
  if (!prodId) return false;
  const label = (name ? name : 'Product #' + prodId) + (sku ? ' (' + sku + ')' : '');
  if (!confirm('Are you sure you want to permanently delete ' + label + ' from the database?\n\nThis will permanently remove the product record and its variety entries from the database.')) {
    return false;
  }

  const form = document.createElement('form');
  form.method = 'POST';
  form.action = 'products.php';

  const actInput = document.createElement('input');
  actInput.type = 'hidden';
  actInput.name = 'action';
  actInput.value = 'delete';
  form.appendChild(actInput);

  const idInput = document.createElement('input');
  idInput.type = 'hidden';
  idInput.name = 'id';
  idInput.value = prodId;
  form.appendChild(idInput);

  document.body.appendChild(form);
  form.submit();
  return false;
}

function deleteFromModal() {
  const btn = document.getElementById('modalDeleteProductBtn');
  if (!btn) return;
  const id = parseInt(btn.dataset.id, 10);
  const name = btn.dataset.name || '';
  const sku = btn.dataset.sku || '';
  if (id > 0) {
    const modalEl = document.getElementById('productModal');
    if (modalEl && window.bootstrap) {
      const modalInst = bootstrap.Modal.getInstance(modalEl);
      if (modalInst) modalInst.hide();
    }
    confirmDeleteProduct(id, name, sku);
  }
}

function editProduct(p) {
  const productId = parseInt(p.id, 10);
  fetch('products.php?action=get_product_json&id=' + productId)
    .then(res => res.json())
    .then(data => {
      if (!data.success || !data.product) {
        alert(data.message || 'Could not load product varieties.');
        return;
      }
      window.allProductsMap[productId] = data.product;
      populateProductForm(data.product);
      const modal = new bootstrap.Modal(document.getElementById('productModal'));
      modal.show();
    })
    .catch(err => {
      console.error(err);
      alert('Network error loading product varieties.');
    });
}

document.getElementById('prod_std_qty').addEventListener('input', updateBundleStockCalculation);
document.getElementById('prod_stock').addEventListener('input', updateBundleStockCalculation);

// Live search filter inside Product Name combobox
document.addEventListener('DOMContentLoaded', function() {
  const filterInput = document.getElementById('prod_name_search_filter');
  const itemsContainer = document.getElementById('prod_name_items_list');
  const countEl = document.getElementById('prod_combobox_count');

  if (filterInput && itemsContainer) {
    filterInput.addEventListener('input', function() {
      const q = this.value.toLowerCase().trim();
      const items = itemsContainer.querySelectorAll('.prod-combobox-item');
      let matches = 0;

      items.forEach(function(item) {
        const name = (item.getAttribute('data-name') || '').toLowerCase();
        const sku = (item.getAttribute('data-sku') || '').toLowerCase();
        const brand = (item.getAttribute('data-brand') || '').toLowerCase();
        const id = (item.getAttribute('data-id') || '').toLowerCase();

        if (q === '' || name.includes(q) || sku.includes(q) || brand.includes(q) || id.includes(q)) {
          item.style.setProperty('display', 'flex', 'important');
          matches++;
        } else {
          item.style.setProperty('display', 'none', 'important');
        }
      });

      if (countEl) {
        countEl.textContent = (q === '') ? `${items.length} products` : `${matches} matches`;
      }
    });

    // Auto-focus search input when dropdown opens
    const toggleBtn = document.getElementById('btn_product_selector_toggle');
    if (toggleBtn) {
      toggleBtn.addEventListener('shown.bs.dropdown', function() {
        filterInput.focus();
      });
    }
  }
});

// Safe intentional workflow: Add New Brand Name
function promptAddNewBrand() {
  const brandSelect = document.getElementById('prod_brand_name');
  const input = prompt('Enter New Brand Name (e.g. Popular, Economi, Deluxe, Classmate):');
  if (input === null) return;
  const brandName = input.trim();
  if (!brandName) {
    alert('Brand name cannot be empty.');
    return;
  }

  // Check duplicate case-insensitively
  for (let i = 0; i < brandSelect.options.length; i++) {
    if (brandSelect.options[i].value.toLowerCase() === brandName.toLowerCase()) {
      brandSelect.selectedIndex = i;
      alert('Brand "' + brandSelect.options[i].value + '" already exists in the list! Selected it for you.');
      return;
    }
  }

  // Append new brand
  const opt = document.createElement('option');
  opt.value = brandName;
  opt.textContent = brandName;
  brandSelect.appendChild(opt);
  brandSelect.value = brandName;
}

// Safe intentional workflow: Add New Notebook Size
function promptAddNewSize() {
  const sizeSelect = document.getElementById('prod_size');
  const input = prompt('Enter New Notebook Size (e.g. Crown Size, Student Size (Platinum), A5 Size):');
  if (input === null) return;
  const sizeName = input.trim();
  if (!sizeName) {
    alert('Notebook size cannot be empty.');
    return;
  }

  // Check duplicate case-insensitively
  for (let i = 0; i < sizeSelect.options.length; i++) {
    if (sizeSelect.options[i].value.toLowerCase() === sizeName.toLowerCase()) {
      sizeSelect.selectedIndex = i;
      alert('Size "' + sizeSelect.options[i].value + '" already exists in the list! Selected it for you.');
      return;
    }
  }

  const opt = document.createElement('option');
  opt.value = sizeName;
  opt.textContent = sizeName;
  sizeSelect.appendChild(opt);
  sizeSelect.value = sizeName;
}

// Safe intentional workflow: Add New Binding
function promptAddNewBinding() {
  const bindingSelect = document.getElementById('prod_binding');
  const input = prompt('Enter New Binding Option (e.g. Hard Bound Section Sewn, Spiral Wire-O, Perfect Binding):');
  if (input === null) return;
  const bindingName = input.trim();
  if (!bindingName) {
    alert('Binding option cannot be empty.');
    return;
  }

  // Check duplicate case-insensitively
  for (let i = 0; i < bindingSelect.options.length; i++) {
    if (bindingSelect.options[i].value.toLowerCase() === bindingName.toLowerCase()) {
      bindingSelect.selectedIndex = i;
      alert('Binding "' + bindingSelect.options[i].value + '" already exists in the list! Selected it for you.');
      return;
    }
  }

  const opt = document.createElement('option');
  opt.value = bindingName;
  opt.textContent = bindingName;
  bindingSelect.appendChild(opt);
  bindingSelect.value = bindingName;
}

// Safe intentional workflow: Open Add Category Modal
function openAddCategoryModal() {
  document.getElementById('quick_cat_name').value = '';
  document.getElementById('quick_cat_desc').value = '';
  const fb = document.getElementById('quick_cat_feedback');
  if (fb) {
    fb.className = 'small mt-2 d-none';
    fb.innerHTML = '';
  }
  const modal = new bootstrap.Modal(document.getElementById('quickCategoryModal'));
  modal.show();
}

// Handle Quick Add Category AJAX submission
function handleQuickAddCategory(e) {
  e.preventDefault();
  const name = document.getElementById('quick_cat_name').value.trim();
  const desc = document.getElementById('quick_cat_desc').value.trim();
  const fb = document.getElementById('quick_cat_feedback');
  const submitBtn = document.getElementById('btnSubmitQuickCat');

  if (!name) return;

  submitBtn.disabled = true;
  submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

  const formData = new FormData();
  formData.append('name', name);
  formData.append('description', desc);

  fetch('products.php?action=quick_add_category', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Create Category';

    if (data.success) {
      // Append new category to Category select in product form
      const catSelect = document.getElementById('prod_category_id');
      const opt = document.createElement('option');
      opt.value = data.id;
      opt.textContent = data.name;
      catSelect.appendChild(opt);
      catSelect.value = data.id;

      // Close modal
      const modalEl = document.getElementById('quickCategoryModal');
      const modal = bootstrap.Modal.getInstance(modalEl);
      if (modal) modal.hide();

      // Show alert in main form or toast
      alert('Category "' + data.name + '" added successfully and selected!');
    } else {
      if (fb) {
        fb.className = 'small mt-2 text-danger fw-semibold';
        fb.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-1"></i> ' + (data.message || 'Error creating category.');
      }
      if (data.existing_id) {
        const catSelect = document.getElementById('prod_category_id');
        catSelect.value = data.existing_id;
      }
    }
  })
  .catch(err => {
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Create Category';
    console.error(err);
    if (fb) {
      fb.className = 'small mt-2 text-danger fw-semibold';
      fb.textContent = 'Network or server error creating category.';
    }
  });
}

function generateAIDescription() {
  const brand = document.getElementById('prod_brand_name').value.trim();
  const name = document.getElementById('prod_name').value.trim() || 'Notebook';
  const pages = document.getElementById('prod_pages').value.trim();
  const size = document.getElementById('prod_size').value.trim();
  const gsm = document.getElementById('prod_gsm').value.trim() || '70';
  const stdQty = document.getElementById('prod_std_qty').value.trim();
  const paperType = document.getElementById('prod_paper_type').value.trim();
  const binding = document.getElementById('prod_binding').value.trim();
  const coverType = document.getElementById('prod_cover_type').value.trim();
  const catSelect = document.getElementById('prod_category_id');
  const categoryText = (catSelect && catSelect.options[catSelect.selectedIndex]) ? catSelect.options[catSelect.selectedIndex].text : '';

  let fullName = brand ? (brand + ' ' + name) : name;
  let parts = [];
  
  parts.push(`The ${fullName} is a professional quality notebook designed for smooth, high-grade writing performance.`);
  
  let specDetails = [];
  if (pages) specDetails.push(`${pages} pages`);
  if (size) specDetails.push(`in ${size}`);
  
  specDetails.push(`crafted with ${gsm} GSM ${paperType || 'paper'}`);
  parts.push(`Featuring ` + specDetails.join(', ') + `, it provides a premium writing experience with excellent sheet durability.`);
  
  let buildDetails = [];
  if (binding) buildDetails.push(`${binding} binding`);
  if (coverType) buildDetails.push(`${coverType} cover`);
  
  if (buildDetails.length > 0) {
    parts.push(`Constructed with ` + buildDetails.join(' and ') + `.`);
  }
  
  if (stdQty && parseInt(stdQty, 10) > 0) {
    parts.push(`Standard wholesale pack consists of ${stdQty} pieces per bundle.`);
  }
  
  let generatedText = parts.join(' ');
  if (!generatedText.includes(`${gsm} GSM`) && !generatedText.includes('GSM')) {
    generatedText += ` (GSM: ${gsm} GSM).`;
  }
  
  document.getElementById('prod_description').value = generatedText;
  
  const notice = document.getElementById('ai_desc_notice');
  if (notice) {
    notice.classList.remove('d-none');
    setTimeout(() => { notice.classList.add('d-none'); }, 6000);
  }
}

// Excel Upload Form Submission & AJAX Preview Handler
document.addEventListener('submit', function(e) {
  const form = e.target;
  if (!(form instanceof HTMLFormElement) || form.id !== 'excelUploadForm') return;

  e.preventDefault();
  const fileInput = document.getElementById('excel_file_input');
  if (!fileInput.files || fileInput.files.length === 0) {
    alert('Please select an Excel or CSV file first.');
    return;
  }

  const spinner = document.getElementById('upload_spinner');
  if (spinner) spinner.classList.remove('d-none');

  const formData = new FormData(form);

  fetch('excel_action.php?action=preview', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (spinner) spinner.classList.add('d-none');

    if (!data.success) {
      alert(data.message || 'Error parsing Excel file.');
      return;
    }

    // Update Counters
    document.getElementById('prev_cnt_update').textContent = data.updated_count;
    document.getElementById('prev_cnt_new').textContent = data.new_count;
    document.getElementById('prev_cnt_unchanged').textContent = data.unchanged_count;
    document.getElementById('prev_cnt_errors').textContent = data.error_count;
    document.getElementById('import_token_input').value = data.token;

    // Render Preview Table Rows
    const tbody = document.getElementById('excelPreviewTbody');
    tbody.innerHTML = '';

    if (data.items && data.items.length > 0) {
      data.items.forEach(item => {
        const tr = document.createElement('tr');

        let actionBadge = '';
        if (item.action === 'update') {
          actionBadge = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold">UPDATE</span>';
        } else if (item.action === 'new') {
          actionBadge = '<span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold">NEW</span>';
        } else if (item.action === 'unchanged') {
          actionBadge = '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">UNCHANGED</span>';
        } else {
          actionBadge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-semibold">ERROR</span>';
        }

        let diffsHtml = '';
        if (item.errors && item.errors.length > 0) {
          diffsHtml = '<div class="text-danger small fw-semibold">' + item.errors.join('<br>') + '</div>';
        } else if (item.action === 'update' && item.diffs) {
          const diffList = [];
          for (let key in item.diffs) {
            const d = item.diffs[key];
            diffList.push(`<span class="fw-semibold text-dark">${d.label}:</span> <span class="text-muted text-decoration-line-through">${d.old}</span> &rarr; <span class="text-success fw-bold">${d.new}</span>`);
          }
          diffsHtml = diffList.join('<br>');
        } else if (item.action === 'new') {
          diffsHtml = '<span class="text-success small fw-semibold"><i class="bi bi-plus-circle me-1"></i>New product will be inserted into database</span>';
        } else {
          diffsHtml = '<span class="text-muted small">No changes detected in Excel row</span>';
        }

        tr.innerHTML = `
          <td class="fw-bold text-secondary">${item.row_num}</td>
          <td class="fw-bold text-navy">${item.sku || ('ID: ' + (item.matched_id || '-'))}</td>
          <td class="fw-semibold text-dark">${item.product_name}</td>
          <td>${actionBadge}</td>
          <td>${diffsHtml}</td>
        `;

        tbody.appendChild(tr);
      });
    }

    // Close Upload Modal and Show Preview Modal
    const uploadModalEl = document.getElementById('excelImportModal');
    const uploadModal = bootstrap.Modal.getInstance(uploadModalEl);
    const previewModalEl = document.getElementById('excelPreviewModal');
    const previewModal = bootstrap.Modal.getInstance(previewModalEl) || new bootstrap.Modal(previewModalEl);
    if (uploadModal) {
      uploadModalEl.addEventListener('hidden.bs.modal', () => previewModal.show(), { once: true });
      uploadModal.hide();
    } else {
      previewModal.show();
    }
  })
  .catch(err => {
    if (spinner) spinner.classList.add('d-none');
    console.error(err);
    alert('An error occurred while uploading/parsing the Excel file.');
  });
});

function handleExcelFileSelect(input) {
  if (input.files && input.files[0]) {
    document.getElementById('selected_filename').textContent = 'Selected: ' + input.files[0].name;
  }
}
</script>

<!-- Excel Upload Modal -->
<div class="modal fade" id="excelImportModal" tabindex="-1" aria-labelledby="excelImportModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg rounded-3">
      <div class="modal-header text-white" style="background-color: #17243A;">
        <h5 class="modal-title fw-bold fs-6" id="excelImportModalLabel">
          <i class="bi bi-file-earmark-excel text-gold me-2"></i> Import / Update Products via Excel
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form id="excelUploadForm" enctype="multipart/form-data">
        <div class="modal-body p-4">
          <p class="text-secondary small mb-3">
            Upload an Excel (.xlsx, .xls) or CSV file to bulk update rates, prices, stock, page counts, and variants or insert new products.
          </p>

          <div class="mb-3 p-3 bg-light rounded border text-center">
            <i class="bi bi-cloud-arrow-up fs-2 text-gold d-block mb-2"></i>
            <label for="excel_file_input" class="form-label fw-bold text-dark mb-1 small">Select Excel / CSV File</label>
            <input class="form-control form-control-sm" type="file" id="excel_file_input" name="excel_file" accept=".xlsx, .xls, .csv" required onchange="handleExcelFileSelect(this)">
            <div id="selected_filename" class="small fw-semibold text-navy mt-2"></div>
          </div>

          <div class="d-flex justify-content-between align-items-center pt-2">
            <a href="excel_action.php?action=template" class="btn btn-link btn-sm p-0 text-navy fw-semibold text-decoration-none" title="Download sample CSV template">
              <i class="bi bi-download text-gold me-1"></i> Download Excel Template
            </a>
            <span class="badge bg-secondary-subtle text-secondary" style="font-size: 0.7rem;">Matches by Product ID / SKU</span>
          </div>

          <div id="upload_spinner" class="text-center mt-3 d-none">
            <span class="spinner-border spinner-border-sm text-warning me-1" role="status" aria-hidden="true"></span>
            <span class="small fw-semibold text-navy">Parsing and validating Excel data...</span>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-gold btn-sm px-4 fw-bold">Upload & Preview Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Excel Preview & Confirmation Modal -->
<div class="modal fade" id="excelPreviewModal" tabindex="-1" aria-labelledby="excelPreviewModalLabel" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg rounded-3">
      <div class="modal-header text-white" style="background-color: #17243A;">
        <h5 class="modal-title fw-bold fs-6" id="excelPreviewModalLabel">
          <i class="bi bi-eye-fill text-gold me-2"></i> Excel Import Preview & Verification
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body p-4">
        <!-- Summary Cards Row -->
        <div class="row g-3 mb-4">
          <div class="col-6 col-md-3">
            <div class="p-2.5 rounded border bg-primary-subtle border-primary-subtle text-center">
              <div class="fs-4 fw-bold text-primary" id="prev_cnt_update">0</div>
              <div class="small text-primary fw-semibold" style="font-size: 0.75rem;">Updated Products</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-2.5 rounded border bg-success-subtle border-success-subtle text-center">
              <div class="fs-4 fw-bold text-success" id="prev_cnt_new">0</div>
              <div class="small text-success fw-semibold" style="font-size: 0.75rem;">New Products</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-2.5 rounded border bg-light border-secondary-subtle text-center">
              <div class="fs-4 fw-bold text-secondary" id="prev_cnt_unchanged">0</div>
              <div class="small text-secondary fw-semibold" style="font-size: 0.75rem;">Unchanged</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-2.5 rounded border bg-danger-subtle border-danger-subtle text-center">
              <div class="fs-4 fw-bold text-danger" id="prev_cnt_errors">0</div>
              <div class="small text-danger fw-semibold" style="font-size: 0.75rem;">Validation Errors</div>
            </div>
          </div>
        </div>

        <div class="table-responsive border rounded" style="max-height: 380px;">
          <table class="table table-hover align-middle mb-0" style="font-size: 0.825rem;">
            <thead class="table-light sticky-top">
              <tr>
                <th style="width: 60px;">Row #</th>
                <th>Identifier / SKU</th>
                <th>Product Name</th>
                <th>Action</th>
                <th>Field Changes / Diffs</th>
              </tr>
            </thead>
            <tbody id="excelPreviewTbody">
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer bg-light">
        <form action="excel_action.php" method="POST" id="excelCommitForm">
          <input type="hidden" name="action" value="commit">
          <input type="hidden" name="import_token" id="import_token_input" value="">
          <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-gold btn-sm px-4 fw-bold" id="confirmCommitBtn">
            <i class="bi bi-check-circle-fill me-1"></i> Confirm & Apply Import
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
