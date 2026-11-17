<?php
// Core Helper Functions for RAJPRIYA Notebook E-commerce

require_once __DIR__ . '/../config/database.php';

// Ensure Indian Standard Time (IST, UTC+05:30) is centrally active
if (date_default_timezone_get() !== 'Asia/Kolkata') {
    date_default_timezone_set('Asia/Kolkata');
}

// WhatsApp Cloud API Configuration
require_once __DIR__ . '/../config/whatsapp.php';

// Sanitize user input
function sanitize($data) {
    if (is_array($data)) {
        return array_map('sanitize', $data);
    }
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Get setting value from database
function get_setting($key, $default = '') {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}

// Get sanitized Admin WhatsApp number for order invoices & alerts
function get_admin_whatsapp_number() {
    // 1. Check DB settings table
    $num = get_setting('admin_whatsapp');

    // 2. Fallback to constant if defined
    if (empty($num) && defined('ADMIN_WHATSAPP_NUMBER')) {
        $num = ADMIN_WHATSAPP_NUMBER;
    }

    // 3. Fallback to existing phone setting if still empty
    if (empty($num)) {
        $num = get_setting('phone');
    }

    if (empty($num)) {
        return '';
    }

    // Clean to digits only (strip +, spaces, hyphens, brackets)
    $clean = preg_replace('/[^0-9]/', '', (string)$num);

    // If 10-digit Indian mobile number, prefix with country code 91
    if (strlen($clean) === 10) {
        $clean = '91' . $clean;
    }

    return $clean;
}

// Get site base URL for generating accessible absolute links
function get_site_url() {
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || ((int)($_SERVER['SERVER_PORT'] ?? 80) === 443) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $doc_root = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
    $app_dir = str_replace('\\', '/', dirname(__DIR__));

    if (!empty($doc_root) && strpos($app_dir, $doc_root) === 0) {
        $rel = substr($app_dir, strlen($doc_root));
        $base = '/' . trim($rel, '/');
        return $protocol . '://' . $host . ($base && $base !== '/' ? $base : '');
    }

    $script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $script_name = preg_replace('/^[A-Za-z]:/', '', $script_name);
    if (strpos($script_name, '/htdocs/') !== false) {
        $script_name = substr($script_name, strpos($script_name, '/htdocs/') + 7);
    }
    $script_dir = dirname($script_name);
    $base = '/' . trim(str_replace(['/admin', '/scratch', '/api'], '', $script_dir), '/');
    return $protocol . '://' . $host . ($base && $base !== '/' ? $base : '');
}

if (!function_exists('resolve_image_file')) {
    function resolve_image_file($path, $fallback = '') {
        if (empty($path)) return $fallback;
        $clean = ltrim($path, '/\\');
        $baseDir = dirname(__DIR__);
        // 1. Direct path check
        if (file_exists($baseDir . '/' . $clean)) {
            return $clean;
        }
        // 2. Try swapping .png/.jpg to .webp
        $webp = preg_replace('/\.(png|jpg|jpeg)$/i', '.webp', $clean);
        if (file_exists($baseDir . '/' . $webp)) {
            return $webp;
        }
        // 3. Try swapping .webp to .png
        $png = preg_replace('/\.(webp|jpg|jpeg)$/i', '.png', $clean);
        if (file_exists($baseDir . '/' . $png)) {
            return $png;
        }
        // 4. Try swapping to .jpg
        $jpg = preg_replace('/\.(webp|png)$/i', '.jpg', $clean);
        if (file_exists($baseDir . '/' . $jpg)) {
            return $jpg;
        }
        return !empty($fallback) ? $fallback : $clean;
    }
}

if (!function_exists('get_category_image_url')) {
    function get_category_image_url($image_path, $cat_slug = '') {
        $resolved = resolve_image_file($image_path);
        $baseDir = dirname(__DIR__);
        if (!empty($resolved) && file_exists($baseDir . '/' . $resolved)) {
            return $resolved;
        }
        // Fallback by slug/name keywords
        $slug = strtolower($cat_slug);
        if (strpos($slug, 'hard-bind') !== false) return 'assets/images/categories/cat_long_hardbind.webp';
        if (strpos($slug, 'laminated') !== false && strpos($slug, 'student') === false) return 'assets/images/categories/cat_long_laminated.webp';
        if (strpos($slug, 'student') !== false) return 'assets/images/categories/cat_short.webp';
        if (strpos($slug, 'practical') !== false) return 'assets/images/categories/cat_practical.webp';
        if (strpos($slug, 'drawing') !== false) return 'assets/images/categories/cat_drawing.webp';
        if (strpos($slug, 'stationer') !== false) return 'assets/images/categories/cat_single_line.webp';
        if (strpos($slug, 'register') !== false) return 'assets/images/categories/cat_registers.webp';
        if (strpos($slug, 'ledger') !== false || strpos($slug, 'account') !== false) return 'assets/images/categories/cat_ledgers_accounts.webp';
        if (strpos($slug, 'paper') !== false) return 'assets/images/categories/cat_two_line.webp';

        return 'assets/images/categories/cat_long_laminated.webp';
    }
}

// Fetch active logos from DB with automatic fallback
function get_active_logos() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM logos WHERE status = 1");
    $logos = $stmt->fetchAll();
    
    $result = [
        'primary'   => resolve_image_file('uploads/logos/logo_primary.webp', 'uploads/logos/logo_primary.png'),
        'secondary' => resolve_image_file('uploads/logos/logo_secondary.webp', 'uploads/logos/logo_secondary.png'),
        'product'   => resolve_image_file('uploads/logos/logo_product.webp', 'uploads/logos/logo_product.png')
    ];
    
    foreach ($logos as $logo) {
        $resolved = resolve_image_file($logo['file_path'], $result[$logo['logo_type']] ?? $logo['file_path']);
        if ($logo['logo_type'] === 'primary')   $result['primary']   = $resolved;
        if ($logo['logo_type'] === 'secondary') $result['secondary'] = $resolved;
        if ($logo['logo_type'] === 'product')   $result['product']   = $resolved;
    }
    return $result;
}

// Cart session management
function get_cart_session_id() {
    if (empty($_SESSION['cart_session_id'])) {
        $_SESSION['cart_session_id'] = session_id();
    }
    return $_SESSION['cart_session_id'];
}

function sync_cart_on_login($user_id, $session_id) {
    if (!$user_id || !$session_id) return;
    $db = getDB();
    
    // Find session cart not associated with this user
    $stmt = $db->prepare("SELECT id FROM cart WHERE session_id = ? AND (user_id IS NULL OR user_id = 0 OR user_id != ?)");
    $stmt->execute([$session_id, $user_id]);
    $session_carts = $stmt->fetchAll();
    
    if (empty($session_carts)) return;
    
    // Find existing main cart for user
    $stmt = $db->prepare("SELECT id FROM cart WHERE user_id = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$user_id]);
    $user_cart = $stmt->fetch();
    
    if (!$user_cart) {
        $user_cart_id = $session_carts[0]['id'];
        $stmt = $db->prepare("UPDATE cart SET user_id = ? WHERE id = ?");
        $stmt->execute([$user_id, $user_cart_id]);
        $session_carts = array_slice($session_carts, 1);
    } else {
        $user_cart_id = $user_cart['id'];
        $stmt = $db->prepare("UPDATE cart SET session_id = ? WHERE id = ?");
        $stmt->execute([$session_id, $user_cart_id]);
    }
    
    foreach ($session_carts as $s_cart) {
        $sc_id = $s_cart['id'];
        $stmt = $db->prepare("SELECT product_id, variant_id, quantity, ruling_type, unit_price, product_code, pieces_per_bundle, total_pieces FROM cart_items WHERE cart_id = ?");
        $stmt->execute([$sc_id]);
        $items = $stmt->fetchAll();
        
        foreach ($items as $item) {
            $pid = $item['product_id'];
            $qty = $item['quantity'];
            $rtype = !empty($item['ruling_type']) ? $item['ruling_type'] : 'Unruled';
            $uprice = !empty($item['unit_price']) ? $item['unit_price'] : 0;
            
            $variantId = (int)($item['variant_id'] ?? 0);
            $stmt_chk = $db->prepare("
                SELECT id, quantity FROM cart_items
                WHERE cart_id = ? AND product_id = ? AND ruling_type = ?
                  AND (variant_id = ? OR variant_id IS NULL)
                ORDER BY (variant_id = ?) DESC
                LIMIT 1
            ");
            $stmt_chk->execute([$user_cart_id, $pid, $rtype, $variantId ?: null, $variantId]);
            $existing = $stmt_chk->fetch();
            
            if ($existing) {
                $stmt_up = $db->prepare("
                    UPDATE cart_items
                    SET quantity = quantity + ?, variant_id = COALESCE(variant_id, ?),
                        product_code = COALESCE(product_code, ?),
                        pieces_per_bundle = ?, total_pieces = total_pieces + ?
                    WHERE id = ?
                ");
                $stmt_up->execute([
                    $qty, $variantId ?: null, $item['product_code'] ?? null,
                    max(1, (int)($item['pieces_per_bundle'] ?? 1)),
                    max(1, (int)($item['total_pieces'] ?? $qty)),
                    $existing['id']
                ]);
            } else {
                $stmt_ins = $db->prepare("
                    INSERT INTO cart_items
                        (cart_id, product_id, variant_id, quantity, ruling_type, unit_price,
                         product_code, pieces_per_bundle, total_pieces)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_ins->execute([
                    $user_cart_id, $pid, $variantId ?: null, $qty, $rtype, $uprice,
                    $item['product_code'] ?? null,
                    max(1, (int)($item['pieces_per_bundle'] ?? 1)),
                    max(1, (int)($item['total_pieces'] ?? $qty))
                ]);
            }
        }
        
        $stmt = $db->prepare("DELETE FROM cart_items WHERE cart_id = ?");
        $stmt->execute([$sc_id]);
        $stmt = $db->prepare("DELETE FROM cart WHERE id = ?");
        $stmt->execute([$sc_id]);
    }
}

if (!function_exists('format_variant_page_label')) {
    function format_variant_page_label($v) {
        if (!empty($v['pages'])) {
            $p = trim((string)$v['pages']);
            if (is_numeric($p)) {
                return $p . ' Pages';
            }
            if (preg_match('/^\d+\s*pages$/i', $p)) {
                return preg_replace('/pages/i', 'Pages', $p);
            }
            if (preg_match('/\d+/', $p)) {
                if (stripos($p, 'LMT') !== false) {
                    return $p;
                }
                if (stripos($p, 'Pages') === false) {
                    return $p . ' Pages';
                }
                return $p;
            }
            return $p;
        }

        $vLabel = !empty($v['variant_label']) ? $v['variant_label'] : (is_array($v) ? ($v['name'] ?? '') : (string)$v);
        $vLabel = preg_replace('/\s*\([^)]*\)/', '', $vLabel);
        $vLabel = preg_replace('/\s*\(\s*Both\s*\)/i', '', $vLabel);
        $vLabel = preg_replace('/\s+\bBoth\b/i', '', $vLabel);
        return trim($vLabel);
    }
}

if (!function_exists('extract_product_variety')) {
    function extract_product_variety($v) {
        $desc = !empty($v['description']) ? $v['description'] : '';
        if (preg_match('/Varieties:\s*([^|]+)/i', $desc, $m)) {
            return trim($m[1]);
        }
        if (!empty($v['ruling_option'])) {
            if (strcasecmp($v['ruling_option'], 'Both') === 0) {
                return 'Unruled, Ruled';
            }
            if (strcasecmp($v['ruling_option'], 'Micro Point') === 0) {
                return 'Maths Plain';
            }
            return $v['ruling_option'];
        }
        return 'Standard';
    }
}

if (!function_exists('get_product_variety_list')) {
    function get_product_variety_list($v) {
        $product_id = is_array($v) ? ($v['id'] ?? 0) : 0;
        if ($product_id > 0) {
            $db = getDB();
            $stmt = $db->prepare("SELECT DISTINCT variety, variety_display FROM product_variants WHERE product_id = ? ORDER BY id ASC");
            $stmt->execute([$product_id]);
            $rows = $stmt->fetchAll();
            if (!empty($rows)) {
                $vars = [];
                foreach ($rows as $r) {
                    $vLabel = !empty($r['variety_display']) ? $r['variety_display'] : $r['variety'];
                    if (strcasecmp($vLabel, 'Micro Point') === 0) {
                        $vLabel = 'Maths Plain';
                    }
                    if (!in_array($vLabel, $vars)) {
                        $vars[] = $vLabel;
                    }
                }
                return $vars;
            }

            $managedStmt = $db->prepare("SELECT varieties_managed FROM products WHERE id = ?");
            $managedStmt->execute([(int)$product_id]);
            if ((int)$managedStmt->fetchColumn() === 1) {
                return [];
            }
        }

        // 1. Explicit ruling_option column on the product takes precedence over generic static catalogue map
        if (!empty($v['ruling_option'])) {
            $ruling = trim($v['ruling_option']);
            if (strcasecmp($ruling, 'Both') === 0) {
                return ['Unruled', 'Ruled'];
            }
            if (strcasecmp($ruling, 'Unruled') === 0) {
                return ['Unruled'];
            }
            if (strcasecmp($ruling, 'Ruled') === 0) {
                return ['Ruled'];
            }
            return [$ruling];
        }

        $brand = strtoupper(trim($v['brand_name'] ?? ''));
        $name = strtoupper(trim($v['name'] ?? ''));
        $desc = !empty($v['description']) ? $v['description'] : '';

        // 2. Check description for explicit "Varieties: ..."
        if (preg_match('/Varieties:\s*([^|]+)/i', $desc, $m)) {
            $raw = trim($m[1]);
            $normalized = str_ireplace(['Unruled & Ruled', 'Unruled and Ruled', 'Unruled / Ruled'], 'Unruled, Ruled', $raw);
            $parts = preg_split('/[,|\/]+/', $normalized);
            $result = [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '' && !in_array($p, $result)) {
                    $result[] = $p;
                }
            }
            if (!empty($result)) return $result;
        }

        // 3. Fallback Catalogue Lookup Map when no ruling_option or variants exist
        $catalogMap = [
            'APOLLO' => ['Unruled', 'Ruled'],
            'RAJDHANI PLUS' => ['Unruled', 'Ruled', 'Accountancy'],
            'RAJDHANI' => ['Unruled', 'Ruled', 'Accountancy', 'Composition & Graph'],
            'SMART' => ['Ruled', 'Unruled', 'Accountancy'],
            'ARROW' => ['Five Subject', 'Unruled', 'Ruled'],
            'KING' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            'G+' => ['Ruled', 'Unruled', 'Accountancy'],
            'G PLUS' => ['Ruled', 'Unruled', 'Accountancy'],
            'YES BOSS' => ['Unruled', 'Ruled', 'Accountancy'],
            'BOSS' => ['Unruled', 'Ruled', 'Accountancy'],
            'KARNA EXECUTIVE' => ['Unruled', 'Ruled'],
            'LOTUS' => ['Ruled', 'Unruled', 'Accountancy'],
            'OXFORD' => ['Unruled', 'Ruled', 'Accountancy'],
            'PRIYA HAND WRITING' => ['Unruled'],
            'BRAIN' => ['OSR', 'UR', 'Chemistry'],
            'SCIENTIST' => ['OSR', 'UR', 'Chemistry', 'Accountancy'],
            'DRAWING' => ['UR'],
            'SMS' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            'COLLEGE SPECIAL' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            'SPEED' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            'SPM' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            'STYLE' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            'POWER' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            'WINGS' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            'SCHOOL DIARY' => ['Printed'],
            'POCKET NOTE BOOKS' => ['Ruled', 'Unruled'],
            'CASH MEMO' => ['1+1 Printed Bill Book'],
            'MEETING PAD' => ['Ruled', 'Unruled', 'Accountancy'],
            'MAPS' => ['Green Graph', 'Red Graph', 'Map', 'Border'],
            'ATTENDANCE' => ['Student', 'Staff', 'Master'],
            'SUYA UTHAVI KULU' => ['Minutes', 'Rokkam', 'Pothu', 'Semippu', 'Kadan'],
            'LS, FC, 3-FOLD' => ['Plain Rule', '2 Columns', 'Money Columns'],
            'POCKET NOTE' => ['Plain Ruled with Column'],
            'FILES' => ['Cobra Files', 'Lever Files', 'Ring Files', 'Tag Files', 'Box Files'],
            'WRITING PAPER' => ['Ruled', 'Unruled', 'Accountancy', 'Graph'],
            'COLOUR BOARD' => ['Green', 'Pink', 'Yellow', 'Blue', 'White']
        ];

        foreach ($catalogMap as $key => $vars) {
            if ($brand === $key || strpos($name, $key) !== false) {
                return $vars;
            }
        }

        return ['Unruled', 'Ruled'];
    }
}

if (!function_exists('calculate_stock_breakdown')) {
    function calculate_stock_breakdown($total_pieces, $pieces_per_bundle) {
        $total_pieces = max(0, (int)$total_pieces);
        $pieces_per_bundle = max(1, (int)$pieces_per_bundle);

        return [
            'total_pieces' => $total_pieces,
            'pieces_per_bundle' => $pieces_per_bundle,
            'bundle_stock' => intdiv($total_pieces, $pieces_per_bundle),
            'loose_stock' => $total_pieces % $pieces_per_bundle
        ];
    }
}

if (!function_exists('sync_product_variant_record')) {
    function sync_product_variant_record($db, $productId, $sku, $pages, $ruling_option, $std_qty, $bundle_stock, $stock) {
        $bundle_qty = max(1, (int)$std_qty);
        $stock_breakdown = calculate_stock_breakdown($stock, $bundle_qty);
        $total_pcs = $stock_breakdown['total_pieces'];
        $existingCountStmt = $db->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id = ?");
        $existingCountStmt->execute([(int)$productId]);
        $hadVariantRows = (int)$existingCountStmt->fetchColumn() > 0;

        $variants_to_ensure = [];
        $ruling = trim($ruling_option ?: 'Both');

        if (strcasecmp($ruling, 'Unruled') === 0) {
            $variants_to_ensure[] = [
                'suffix' => '-UR',
                'variety' => 'UR',
                'variety_display' => 'Unruled'
            ];
        } elseif (strcasecmp($ruling, 'Ruled') === 0) {
            $variants_to_ensure[] = [
                'suffix' => '-SR',
                'variety' => 'SR',
                'variety_display' => 'Ruled'
            ];
        } elseif (strcasecmp($ruling, 'Both') === 0) {
            $variants_to_ensure[] = [
                'suffix' => '-UR',
                'variety' => 'UR',
                'variety_display' => 'Unruled'
            ];
            $variants_to_ensure[] = [
                'suffix' => '-SR',
                'variety' => 'SR',
                'variety_display' => 'Ruled'
            ];
        } else {
            $cleanV = preg_replace('/[^A-Za-z0-9]/', '', $ruling);
            $vCode = strtoupper(substr($cleanV, 0, 4));
            $variants_to_ensure[] = [
                'suffix' => '-' . $vCode,
                'variety' => $vCode,
                'variety_display' => $ruling
            ];
        }

        foreach ($variants_to_ensure as $v) {
            $p_code = $sku;
            if (!preg_match('/' . preg_quote($v['suffix'], '/') . '$/i', $sku) && !preg_match('/-' . preg_quote($v['variety'], '/') . '$/i', $sku)) {
                $p_code = $sku . $v['suffix'];
            }

            // Check if this specific variety already exists for this product
            $stmt_check = $db->prepare("SELECT id FROM product_variants WHERE product_id = ? AND (UPPER(variety) = ? OR UPPER(variety_display) = ?)");
            $stmt_check->execute([$productId, strtoupper($v['variety']), strtoupper($v['variety_display'])]);
            $existingId = $stmt_check->fetchColumn();

            if ($existingId) {
                $stmt_up = $db->prepare("
                    UPDATE product_variants 
                    SET product_code = ?, page_count = ? 
                    WHERE id = ?
                ");
                $stmt_up->execute([$p_code, $pages, $existingId]);
            } else {
                $stmt_in = $db->prepare("
                    INSERT INTO product_variants (product_id, product_code, page_count, variety, variety_display, bundle_qty, bundle_stock, loose_stock, stock) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt_in->execute([$productId, $p_code, $pages, $v['variety'], $v['variety_display'], $bundle_qty, 0, 0, 0]);
            }
        }

        // Safeguard: Never delete existing variants on established products during background sync.
        // Intentional deletions are handled exclusively through the admin variety manager.
        if (!$hadVariantRows) {
            if (strcasecmp($ruling, 'Unruled') === 0) {
                $db->prepare("DELETE FROM product_variants WHERE product_id = ? AND UPPER(variety) = 'SR'")->execute([$productId]);
            } elseif (strcasecmp($ruling, 'Ruled') === 0) {
                $db->prepare("DELETE FROM product_variants WHERE product_id = ? AND UPPER(variety) = 'UR'")->execute([$productId]);
            }
        }

        if (!$hadVariantRows) {
            $stmt_variant_rows = $db->prepare("SELECT id, bundle_qty FROM product_variants WHERE product_id = ? ORDER BY id");
            $stmt_variant_rows->execute([$productId]);
            $variant_rows = $stmt_variant_rows->fetchAll(PDO::FETCH_ASSOC);
            $variant_count = count($variant_rows);
            if ($variant_count > 0) {
                $stock_base = intdiv($total_pcs, $variant_count);
                $stock_remainder = $total_pcs % $variant_count;
                $stmt_update_stock = $db->prepare("
                    UPDATE product_variants
                    SET bundle_stock = ?, loose_stock = ?, stock = ?, updated_at = NOW()
                    WHERE id = ?
                ");

                foreach ($variant_rows as $index => $variant_row) {
                    $variant_pieces = $stock_base + ($index < $stock_remainder ? 1 : 0);
                    $variant_stock = calculate_stock_breakdown($variant_pieces, max(1, (int)$variant_row['bundle_qty']));
                    $stmt_update_stock->execute([
                        $variant_stock['bundle_stock'],
                        $variant_stock['loose_stock'],
                        $variant_stock['total_pieces'],
                        $variant_row['id']
                    ]);
                }
            }
        } else {
            // When product already has variants, if exactly 1 variant exists, sync the direct stock to it
            $stmt_variant_rows = $db->prepare("SELECT id, bundle_qty, bundle_stock, loose_stock, stock, product_code, variety FROM product_variants WHERE product_id = ? ORDER BY id");
            $stmt_variant_rows->execute([$productId]);
            $variant_rows = $stmt_variant_rows->fetchAll(PDO::FETCH_ASSOC);
            if (count($variant_rows) === 1) {
                $onlyVar = $variant_rows[0];
                $vBundleQty = max(1, (int)($onlyVar['bundle_qty'] ?: $bundle_qty));
                $vStockBreakdown = calculate_stock_breakdown($stock, $vBundleQty);
                $prevStock = (int)$onlyVar['stock'];
                $prevBundle = (int)$onlyVar['bundle_stock'];
                $newStock = $vStockBreakdown['total_pieces'];
                $newBundle = $vStockBreakdown['bundle_stock'];
                $newLoose = $vStockBreakdown['loose_stock'];

                $stmt_update_stock = $db->prepare("
                    UPDATE product_variants
                    SET bundle_stock = ?, loose_stock = ?, stock = ?, bundle_qty = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt_update_stock->execute([$newBundle, $newLoose, $newStock, $vBundleQty, $onlyVar['id']]);

                if ($newStock !== $prevStock || $newBundle !== $prevBundle) {
                    $change = $newStock - $prevStock;
                    $admin = function_exists('get_current_admin') ? get_current_admin() : null;
                    $adminUser = $admin['username'] ?? 'admin';
                    $reason = "Product Management Direct Update: " . $onlyVar['product_code'] . " [" . $onlyVar['variety'] . "] (Bundles: {$prevBundle}→{$newBundle}, Total: {$prevStock}→{$newStock})";
                    $stmtLog = $db->prepare("
                        INSERT INTO stock_history (product_id, previous_stock, new_stock, change_amount, reason, admin_username) 
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmtLog->execute([$productId, $prevStock, $newStock, $change, $reason, $adminUser]);
                }
            }
        }

        // Sync total product stock
        $db->prepare("
            UPDATE products 
            SET bundle_stock = (SELECT COALESCE(SUM(bundle_stock), 0) FROM product_variants WHERE product_id = ?),
                stock = (SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = ?) 
            WHERE id = ?
        ")->execute([$productId, $productId, $productId]);
    }
}

function save_product_variant_records($db, $product_id, $sku, $pages, $std_qty, $total_product_stock, $variant_rows) {
    $product_id = (int)$product_id;
    if ($product_id <= 0 || !is_array($variant_rows)) {
        throw new InvalidArgumentException('Product variety data is invalid.');
    }

    $existingStmt = $db->prepare("
        SELECT id, product_code, variety, variety_display, bundle_qty, bundle_stock, loose_stock, stock,
               mrp, price, sale_price, gst_rate, selling_price, discounted_price
        FROM product_variants
        WHERE product_id = ?
        ORDER BY id
        FOR UPDATE
    ");
    $existingStmt->execute([$product_id]);
    $existingRows = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
    $existingById = [];
    foreach ($existingRows as $existingRow) {
        $existingById[(int)$existingRow['id']] = $existingRow;
    }

    $normalizedRows = [];
    $seenIds = [];
    $seenCodes = [];
    $seenLabels = [];
    $defaultBundleQty = max(1, (int)$std_qty);
    foreach ($variant_rows as $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('A product variety entry is invalid.');
        }

        $id = max(0, (int)($row['id'] ?? 0));
        $isDeleted = !empty($row['delete']);
        if ($id > 0) {
            if (!isset($existingById[$id]) || isset($seenIds[$id])) {
                throw new InvalidArgumentException('A product variety does not belong to this product.');
            }
            $seenIds[$id] = true;
        }
        if ($isDeleted) {
            if ($id <= 0) {
                continue;
            }
            $normalizedRows[] = ['id' => $id, 'delete' => true];
            continue;
        }

        $code = strtoupper(trim((string)($row['variety'] ?? '')));
        $label = trim((string)($row['variety_display'] ?? ''));
        if ($code === '' || $label === '' || strlen($code) > 40 || strlen($label) > 100) {
            throw new InvalidArgumentException('Each variety needs a code (up to 40 characters) and a display name (up to 100 characters).');
        }
        if (!preg_match('/^[A-Z0-9_-]+$/', $code)) {
            throw new InvalidArgumentException('Variety codes may contain only letters, numbers, hyphens, and underscores.');
        }

        $codeKey = strtoupper($code);
        $labelKey = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
        if (isset($seenCodes[$codeKey]) || isset($seenLabels[$labelKey])) {
            throw new InvalidArgumentException('Variety codes and display names must be unique within a product.');
        }
        $seenCodes[$codeKey] = true;
        $seenLabels[$labelKey] = true;

        $bundleQtyRaw = trim((string)($row['bundle_qty'] ?? ''));
        if ($bundleQtyRaw === '') {
            $bundleQtyRaw = (string)($id > 0
                ? ($existingById[$id]['bundle_qty'] ?? 1)
                : $defaultBundleQty);
        }
        if ($bundleQtyRaw === '' || !ctype_digit($bundleQtyRaw) || (int)$bundleQtyRaw < 1) {
            throw new InvalidArgumentException('Each variety needs a bundle quantity of at least one piece.');
        }

        $prices = [];
        foreach (['mrp', 'price', 'sale_price', 'selling_price', 'discounted_price'] as $priceField) {
            $rawPrice = trim((string)($row[$priceField] ?? ''));
            if ($rawPrice === '') {
                $prices[$priceField] = null;
                continue;
            }
            if (!is_numeric($rawPrice) || (float)$rawPrice <= 0) {
                throw new InvalidArgumentException('Variety prices must be greater than zero, or left blank to inherit product pricing.');
            }
            $prices[$priceField] = round((float)$rawPrice, 2);
        }
        $gstRateRaw = trim((string)($row['gst_rate'] ?? ''));
        if ($gstRateRaw === '') {
            $gstRate = null;
        } elseif (!is_numeric($gstRateRaw) || (float)$gstRateRaw < 0 || (float)$gstRateRaw > 100) {
            throw new InvalidArgumentException('Variety GST must be between 0 and 100 percent, or left blank to inherit product GST.');
        } else {
            $gstRate = round((float)$gstRateRaw, 2);
        }

        $discountPercentRaw = trim((string)($row['discount_percent'] ?? ''));
        if ($discountPercentRaw === '') {
            $discountPercent = null;
        } elseif (!is_numeric($discountPercentRaw) || (float)$discountPercentRaw < 0 || (float)$discountPercentRaw >= 100) {
            throw new InvalidArgumentException('Discount must be between 0 and 99.99 percent.');
        } else {
            $discountPercent = round((float)$discountPercentRaw, 2);
        }

        $salePrice = $prices['mrp'] !== null
            ? round($prices['mrp'] / 2, 2)
            : ($prices['sale_price'] ?? ($id > 0 ? ($existingById[$id]['price'] ?? null) : null));
        if ($salePrice !== null) {
            $prices['price'] = $salePrice;
            $prices['sale_price'] = $salePrice;
            $prices['selling_price'] = round($salePrice * (1 + ($gstRate ?? 18) / 100), 2);
            $prices['discounted_price'] = ($discountPercent !== null && $discountPercent > 0)
                ? round($salePrice * (1 - $discountPercent / 100), 2)
                : null;
        }

        $bundleStockRaw = isset($row['bundle_stock']) ? trim((string)$row['bundle_stock']) : '';
        $looseStockRaw = isset($row['loose_stock']) ? trim((string)$row['loose_stock']) : '';
        $stockRaw = isset($row['stock']) ? trim((string)$row['stock']) : '';

        $hasExplicitStock = ($bundleStockRaw !== '' || $looseStockRaw !== '' || $stockRaw !== '');
        $submittedBundleStock = ($bundleStockRaw !== '' && is_numeric($bundleStockRaw)) ? max(0, (int)$bundleStockRaw) : null;
        $submittedLooseStock = ($looseStockRaw !== '' && is_numeric($looseStockRaw)) ? max(0, (int)$looseStockRaw) : null;
        $submittedStock = ($stockRaw !== '' && is_numeric($stockRaw)) ? max(0, (int)$stockRaw) : null;

        $normalizedRows[] = [
            'id' => $id,
            'delete' => false,
            'product_code' => trim((string)($row['product_code'] ?? '')),
            'variety' => $code,
            'variety_display' => $label,
            'bundle_qty' => (int)$bundleQtyRaw,
            'mrp' => $prices['mrp'],
            'price' => $prices['price'],
            'sale_price' => $prices['sale_price'],
            'gst_rate' => $gstRate,
            'selling_price' => $prices['selling_price'],
            'discounted_price' => $prices['discounted_price'],
            'bundle_stock' => $submittedBundleStock,
            'loose_stock' => $submittedLooseStock,
            'stock' => $submittedStock,
            'has_explicit_stock' => $hasExplicitStock
        ];
    }

    $activeRows = array_filter($normalizedRows, function($row) {
        return empty($row['delete']);
    });

    $deletedCartItems = 0;
    $hadVariantRows = !empty($existingRows);
    $newVariantCount = count(array_filter($activeRows, function($row) {
        return (int)$row['id'] === 0;
    }));
    $initialStock = !$hadVariantRows && $newVariantCount > 0
        ? calculate_stock_breakdown($total_product_stock, max(1, (int)$std_qty))['total_pieces']
        : 0;
    $initialStockBase = $newVariantCount > 0 ? intdiv($initialStock, $newVariantCount) : 0;
    $initialStockRemainder = $newVariantCount > 0 ? $initialStock % $newVariantCount : 0;
    $newIndex = 0;

    foreach ($normalizedRows as $row) {
        if (empty($row['delete'])) continue;

        $oldRow = $existingById[$row['id']];
        $cartStmt = $db->prepare("SELECT id, variant_id, product_code, ruling_type FROM cart_items WHERE product_id = ?");
        $cartStmt->execute([$product_id]);
        $deleteCartStmt = $db->prepare("DELETE FROM cart_items WHERE id = ? AND product_id = ?");
        foreach ($cartStmt->fetchAll(PDO::FETCH_ASSOC) as $cartRow) {
            $cartVariety = parse_variety_info($cartRow['ruling_type'] ?? '')['variety'] ?? '';
            $matchesId = (int)($cartRow['variant_id'] ?? 0) === (int)$oldRow['id'];
            $matchesCode = (string)($cartRow['product_code'] ?? '') === (string)$oldRow['product_code'];
            $matchesLegacyName = empty($cartRow['product_code'])
                && (strcasecmp($cartVariety, (string)$oldRow['variety']) === 0
                    || strcasecmp($cartVariety, (string)$oldRow['variety_display']) === 0);
            if ($matchesId || $matchesCode || $matchesLegacyName) {
                $deleteCartStmt->execute([$cartRow['id'], $product_id]);
                $deletedCartItems += $deleteCartStmt->rowCount();
            }
        }
        $db->prepare("DELETE FROM product_variants WHERE id = ? AND product_id = ?")
            ->execute([$row['id'], $product_id]);
    }

    if (empty($activeRows)) {
        $cartStmt = $db->prepare("DELETE FROM cart_items WHERE product_id = ?");
        $cartStmt->execute([$product_id]);
        $deletedCartItems += $cartStmt->rowCount();
    }

    foreach ($normalizedRows as $row) {
        if (!empty($row['delete'])) continue;
        if ((int)$row['id'] > 0) {
            $oldRow = $existingById[(int)$row['id']];
            $codeChanged = strcasecmp((string)$oldRow['variety'], $row['variety']) !== 0;
            $skuChanged = strcasecmp((string)$oldRow['product_code'], (string)$sku) !== 0
                && strpos(strtoupper((string)$oldRow['product_code']), strtoupper((string)$sku) . '-') !== 0;
            $newProductCode = (string)$oldRow['product_code'];
            if ($codeChanged || $skuChanged) {
                $suffix = $row['variety'];
                $baseSku = preg_replace('/-' . preg_quote($suffix, '/') . '$/i', '', (string)$sku);
                $newProductCode = $baseSku . '-' . $suffix;
            }

            $duplicateCodeStmt = $db->prepare("
                SELECT COUNT(*) FROM product_variants
                WHERE product_id = ? AND product_code = ? AND id <> ?
            ");
            $duplicateCodeStmt->execute([$product_id, $newProductCode, $row['id']]);
            if ((int)$duplicateCodeStmt->fetchColumn() > 0) {
                throw new InvalidArgumentException('The generated product code conflicts with another variety.');
            }

            $variantBundleQty = $row['bundle_qty'];
            $bundleQtyChanged = (int)($oldRow['bundle_qty'] ?? 1) !== $variantBundleQty;
            $variantPriceChanged = (string)($oldRow['selling_price'] ?? '') !== (string)($row['selling_price'] ?? '')
                || (string)($oldRow['discounted_price'] ?? '') !== (string)($row['discounted_price'] ?? '')
                || (string)($oldRow['mrp'] ?? '') !== (string)($row['mrp'] ?? '')
                || (string)($oldRow['price'] ?? '') !== (string)($row['price'] ?? '')
                || (string)($oldRow['sale_price'] ?? '') !== (string)($row['sale_price'] ?? '')
                || (string)($oldRow['gst_rate'] ?? '') !== (string)($row['gst_rate'] ?? '');
            
            $storedBundleStock = max(0, (int)($oldRow['bundle_stock'] ?? 0));
            $storedLooseStock = max(0, (int)($oldRow['loose_stock'] ?? 0));
            $storedStock = max(0, (int)($oldRow['stock'] ?? 0));

            if (!empty($row['has_explicit_stock'])) {
                $targetBundleStock = ($row['bundle_stock'] !== null) ? $row['bundle_stock'] : $storedBundleStock;
                $targetLooseStock = ($row['loose_stock'] !== null) ? $row['loose_stock'] : $storedLooseStock;

                if ($row['bundle_stock'] === null && $row['loose_stock'] === null && $row['stock'] !== null) {
                    $targetBundleStock = intdiv($row['stock'], $variantBundleQty);
                    $targetLooseStock = $row['stock'] % $variantBundleQty;
                }

                $rawTotalPieces = ($targetBundleStock * $variantBundleQty) + $targetLooseStock;
                $stockBreakdown = calculate_stock_breakdown($rawTotalPieces, $variantBundleQty);
                $effectiveBundleStock = $stockBreakdown['bundle_stock'];
                $effectiveLooseStock = $stockBreakdown['loose_stock'];
                $effectiveTotalPieces = $stockBreakdown['total_pieces'];
            } else {
                $stockBreakdown = calculate_stock_breakdown($storedStock, $variantBundleQty);
                $effectiveBundleStock = max($storedBundleStock, $stockBreakdown['bundle_stock']);
                $effectiveLooseStock = ($effectiveBundleStock > $stockBreakdown['bundle_stock'])
                    ? $storedLooseStock
                    : $stockBreakdown['loose_stock'];
                $effectiveTotalPieces = ($effectiveBundleStock * $variantBundleQty) + $effectiveLooseStock;
            }

            $db->prepare("
                UPDATE product_variants
                SET product_code = ?, page_count = ?, variety = ?, variety_display = ?,
                    bundle_qty = ?, bundle_stock = ?, loose_stock = ?, stock = ?,
                    mrp = ?, price = ?, sale_price = ?, gst_rate = ?,
                    selling_price = ?, discounted_price = ?, updated_at = NOW()
                WHERE id = ? AND product_id = ?
            ")->execute([
                $newProductCode, $pages, $row['variety'], $row['variety_display'],
                $variantBundleQty, $effectiveBundleStock, $effectiveLooseStock,
                $effectiveTotalPieces, $row['mrp'], $row['price'], $row['sale_price'],
                $row['gst_rate'], $row['selling_price'], $row['discounted_price'],
                $row['id'], $product_id
            ]);

            $prevTotalPieces = (int)$oldRow['stock'];
            $prevBundleStock = (int)$oldRow['bundle_stock'];
            $prevLooseStock = (int)$oldRow['loose_stock'];
            if ($effectiveTotalPieces !== $prevTotalPieces || $effectiveBundleStock !== $prevBundleStock || $effectiveLooseStock !== $prevLooseStock) {
                $change = $effectiveTotalPieces - $prevTotalPieces;
                $admin = function_exists('get_current_admin') ? get_current_admin() : null;
                $adminUser = $admin['username'] ?? 'admin';
                $reason = "Product Management Update: " . $newProductCode . " [" . $row['variety'] . "] (Bundles: {$prevBundleStock}→{$effectiveBundleStock}, Loose: {$prevLooseStock}→{$effectiveLooseStock}, Total: {$prevTotalPieces}→{$effectiveTotalPieces})";
                $stmtLog = $db->prepare("
                    INSERT INTO stock_history (product_id, previous_stock, new_stock, change_amount, reason, admin_username) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmtLog->execute([$product_id, $prevTotalPieces, $effectiveTotalPieces, $change, $reason, $adminUser]);
            }

            if ($newProductCode !== (string)$oldRow['product_code']
                || strcasecmp((string)$oldRow['variety_display'], $row['variety_display']) !== 0
                || $bundleQtyChanged
                || $variantPriceChanged) {
                $cartStmt = $db->prepare("
                    SELECT id, variant_id, product_code, ruling_type, quantity,
                           unit_price, pieces_per_bundle, total_pieces
                    FROM cart_items
                    WHERE product_id = ? AND
                          (variant_id = ? OR product_code = ? OR product_code IS NULL OR product_code = '')
                ");
                $cartStmt->execute([$product_id, (int)$oldRow['id'], $oldRow['product_code']]);
                $cartRows = $cartStmt->fetchAll(PDO::FETCH_ASSOC);
                $updateCartStmt = $db->prepare("
                    UPDATE cart_items
                    SET product_code = ?, variant_id = ?, ruling_type = ?,
                        pieces_per_bundle = ?, total_pieces = ?, unit_price = ?
                    WHERE id = ? AND product_id = ?
                ");
                $cartProduct = null;
                if ($bundleQtyChanged || $variantPriceChanged) {
                    $cartProductStmt = $db->prepare("SELECT * FROM products WHERE id = ?");
                    $cartProductStmt->execute([$product_id]);
                    $cartProduct = $cartProductStmt->fetch(PDO::FETCH_ASSOC);
                }
                foreach ($cartRows as $cartRow) {
                    $info = parse_variety_info($cartRow['ruling_type'] ?? '', $pages);
                    $cartVariety = (string)($info['variety'] ?? '');
                    $matchesId = (int)($cartRow['variant_id'] ?? 0) === (int)$oldRow['id'];
                    $matchesCode = (string)($cartRow['product_code'] ?? '') === (string)$oldRow['product_code'];
                    $matchesLegacyName = empty($cartRow['product_code'])
                        && (strcasecmp($cartVariety, (string)$oldRow['variety']) === 0
                            || strcasecmp($cartVariety, (string)$oldRow['variety_display']) === 0);
                    if (!$matchesId && !$matchesCode && !$matchesLegacyName) {
                        continue;
                    }
                    $pageLabel = trim((string)($info['page_count'] ?? ''));
                    $newRuling = ($pageLabel !== '' ? $pageLabel . ' - ' : '') . $row['variety_display'];
                    $cartOrderType = (string)($info['order_type'] ?? '');
                    $piecesPerBundle = max(1, (int)($cartRow['pieces_per_bundle'] ?? 1));
                    $totalPieces = max(1, (int)($cartRow['total_pieces'] ?? $cartRow['quantity']));
                    $unitPrice = $cartRow['unit_price'];
                    $isBundleCartLine = preg_match('/^Bundle(?:\s|$)/i', $cartOrderType)
                        || (int)($cartRow['pieces_per_bundle'] ?? 1) > 1;
                    if ($isBundleCartLine) {
                        $piecesPerBundle = max(1, (int)$variantBundleQty);
                        $totalPieces = $piecesPerBundle * max(1, (int)$cartRow['quantity']);
                        $bundleTag = $piecesPerBundle > 1 ? 'Bundle - ' . $piecesPerBundle . ' Pcs' : 'Bundle';
                        $newRuling .= ' (' . $bundleTag . ')';
                        if (($bundleQtyChanged || $variantPriceChanged) && $cartProduct) {
                            $unitPrice = round(
                                get_product_single_piece_price($cartProduct, $row['variety_display'], (int)$row['id']) * $piecesPerBundle,
                                2
                            );
                        }
                    } else {
                        if ($cartOrderType !== '') {
                            $newRuling .= ' (' . $cartOrderType . ')';
                        }
                        if ($variantPriceChanged && $cartProduct) {
                            $unitPrice = get_product_single_piece_price(
                                $cartProduct,
                                $row['variety_display'],
                                (int)$row['id']
                            );
                        }
                    }
                    $updateCartStmt->execute([
                        $newProductCode,
                        (int)$row['id'],
                        $newRuling,
                        $piecesPerBundle,
                        $totalPieces,
                        $unitPrice,
                        $cartRow['id'],
                        $product_id
                    ]);
                }
            }
        } else {
            $suffix = $row['variety'];
            $baseSku = preg_replace('/-' . preg_quote($suffix, '/') . '$/i', '', (string)$sku);
            $generatedProductCode = $baseSku . '-' . $suffix;
            $productCode = trim((string)($row['product_code'] ?? ''));
            if ($productCode === '') {
                $productCode = $generatedProductCode;
            }
            if (strlen($productCode) > 100) {
                throw new InvalidArgumentException('Variant codes must be 100 characters or fewer.');
            }

            $duplicateCodeStmt = $db->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id = ? AND LOWER(TRIM(product_code)) = LOWER(TRIM(?))");
            $duplicateCodeStmt->execute([$product_id, $productCode]);
            if ((int)$duplicateCodeStmt->fetchColumn() > 0) {
                throw new InvalidArgumentException('The variant code conflicts with an existing variety.');
            }
            $duplicateVarietyStmt = $db->prepare("
                SELECT COUNT(*) FROM product_variants
                WHERE product_id = ? AND
                    (LOWER(TRIM(variety)) = LOWER(TRIM(?))
                     OR LOWER(TRIM(variety_display)) = LOWER(TRIM(?)))
            ");
            $duplicateVarietyStmt->execute([$product_id, $row['variety'], $row['variety_display']]);
            if ((int)$duplicateVarietyStmt->fetchColumn() > 0) {
                throw new InvalidArgumentException('The variety code or display name already exists for this product.');
            }

            if (!empty($row['has_explicit_stock'])) {
                $targetBundleStock = ($row['bundle_stock'] !== null) ? $row['bundle_stock'] : 0;
                $targetLooseStock = ($row['loose_stock'] !== null) ? $row['loose_stock'] : 0;
                if ($row['bundle_stock'] === null && $row['loose_stock'] === null && $row['stock'] !== null) {
                    $targetBundleStock = intdiv($row['stock'], $row['bundle_qty']);
                    $targetLooseStock = $row['stock'] % $row['bundle_qty'];
                }
                $rawTotalPieces = ($targetBundleStock * $row['bundle_qty']) + $targetLooseStock;
                $stockBreakdown = calculate_stock_breakdown($rawTotalPieces, $row['bundle_qty']);
                $stockPieces = $stockBreakdown['total_pieces'];
            } else {
                $stockPieces = $initialStockBase + ($newIndex < $initialStockRemainder ? 1 : 0);
                $stockBreakdown = calculate_stock_breakdown($stockPieces, $row['bundle_qty']);
            }

            $db->prepare("
                INSERT INTO product_variants
                    (product_id, product_code, page_count, variety, variety_display,
                     bundle_qty, bundle_stock, loose_stock, stock, mrp, price, sale_price, gst_rate,
                     selling_price, discounted_price)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $product_id, $productCode, $pages, $row['variety'], $row['variety_display'],
                $row['bundle_qty'], $stockBreakdown['bundle_stock'], $stockBreakdown['loose_stock'],
                $stockPieces, $row['mrp'], $row['price'], $row['sale_price'], $row['gst_rate'],
                $row['selling_price'], $row['discounted_price']
            ]);

            if ($stockPieces > 0) {
                $admin = function_exists('get_current_admin') ? get_current_admin() : null;
                $adminUser = $admin['username'] ?? 'admin';
                $reason = "New Variety Added: " . $productCode . " [" . $row['variety'] . "] (Bundles: {$stockBreakdown['bundle_stock']}, Loose: {$stockBreakdown['loose_stock']}, Total: {$stockPieces})";
                $stmtLog = $db->prepare("
                    INSERT INTO stock_history (product_id, previous_stock, new_stock, change_amount, reason, admin_username) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmtLog->execute([$product_id, 0, $stockPieces, $stockPieces, $reason, $adminUser]);
            }
            $newIndex++;
        }
    }

    $db->prepare("
        UPDATE products
        SET bundle_stock = (SELECT COALESCE(SUM(bundle_stock), 0) FROM product_variants WHERE product_id = ?),
            stock = (SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = ?),
            std_qty = COALESCE((SELECT bundle_qty FROM product_variants WHERE product_id = ? AND bundle_qty > 0 ORDER BY id ASC LIMIT 1), std_qty)
        WHERE id = ?
    ")->execute([$product_id, $product_id, $product_id, $product_id]);

    return $deletedCartItems;
}

function get_product_variant_stock_record($db, $product_id, $product_code, $ruling_type, $product = null, $for_update = false, $variant_id = 0) {
    $lock_clause = $for_update ? ' FOR UPDATE' : '';
    $variant = false;
    if ((int)$variant_id > 0) {
        $stmt = $db->prepare("SELECT id, bundle_stock, bundle_qty, loose_stock, stock FROM product_variants WHERE product_id = ? AND id = ? LIMIT 1" . $lock_clause);
        $stmt->execute([(int)$product_id, (int)$variant_id]);
        $variant = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if (!$variant && trim((string)$product_code) !== '') {
        $stmt = $db->prepare("SELECT id, bundle_stock, bundle_qty, loose_stock, stock FROM product_variants WHERE product_id = ? AND product_code = ? LIMIT 1" . $lock_clause);
        $stmt->execute([(int)$product_id, (string)$product_code]);
        $variant = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$variant) {
        $variety_info = parse_variety_info($ruling_type);
        $variety = strtoupper(trim($variety_info['variety'] ?? ''));
        if ($variety !== '') {
            $stmt = $db->prepare("
                SELECT id, bundle_stock, bundle_qty, loose_stock, stock
                FROM product_variants
                WHERE product_id = ? AND (UPPER(variety) = ? OR UPPER(variety_display) = ?)
                LIMIT 1" . $lock_clause);
            $stmt->execute([(int)$product_id, $variety, $variety]);
            $variant = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }

    if ($variant) {
        $variant['bundle_qty'] = max(1, (int)$variant['bundle_qty']);
        $storedBundleStock = max(0, (int)($variant['bundle_stock'] ?? 0));
        $storedStock = max(0, (int)($variant['stock'] ?? 0));
        $calculatedBreakdown = calculate_stock_breakdown($storedStock, $variant['bundle_qty']);
        $variant['bundle_stock'] = max($storedBundleStock, $calculatedBreakdown['bundle_stock']);
        $variant['loose_stock'] = ($variant['bundle_stock'] > $calculatedBreakdown['bundle_stock'])
            ? max(0, (int)($variant['loose_stock'] ?? 0))
            : $calculatedBreakdown['loose_stock'];
        $variant['stock'] = ($variant['bundle_stock'] * $variant['bundle_qty']) + $variant['loose_stock'];
        return $variant;
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id = ?");
    $stmt->execute([(int)$product_id]);
    if ((int)$stmt->fetchColumn() > 0) {
        return null;
    }

    if ($for_update || !is_array($product) || !isset($product['stock'])) {
        $stmt = $db->prepare("SELECT stock, bundle_stock, std_qty FROM products WHERE id = ?" . $lock_clause);
        $stmt->execute([(int)$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if (!$product) {
        return null;
    }

    $bundle_qty = max(1, (int)($product['std_qty'] ?? 1));
    $storedBundleStock = max(0, (int)($product['bundle_stock'] ?? 0));
    $storedStock = max(0, (int)($product['stock'] ?? 0));
    $calculatedBreakdown = calculate_stock_breakdown($storedStock, $bundle_qty);
    $effectiveBundleStock = max($storedBundleStock, $calculatedBreakdown['bundle_stock']);
    $effectiveLooseStock = ($effectiveBundleStock > $calculatedBreakdown['bundle_stock'])
        ? max(0, $storedStock - ($effectiveBundleStock * $bundle_qty))
        : $calculatedBreakdown['loose_stock'];
    return [
        'id' => null,
        'bundle_stock' => $effectiveBundleStock,
        'bundle_qty' => $bundle_qty,
        'loose_stock' => $effectiveLooseStock,
        'stock' => ($effectiveBundleStock * $bundle_qty) + $effectiveLooseStock
    ];
}

function get_cart_count() {
    $db = getDB();
    $session_id = get_cart_session_id();
    $user_id = is_customer_logged_in() ? $_SESSION['customer_id'] : null;
    
    if ($user_id) {
        $stmt = $db->prepare("
            SELECT COUNT(ci.id) AS total_count 
            FROM cart_items ci 
            JOIN cart c ON ci.cart_id = c.id 
            WHERE c.user_id = ?
        ");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $db->prepare("
            SELECT COUNT(ci.id) AS total_count 
            FROM cart_items ci 
            JOIN cart c ON ci.cart_id = c.id 
            WHERE c.session_id = ?
        ");
        $stmt->execute([$session_id]);
    }
    $res = $stmt->fetch();
    return $res && $res['total_count'] ? (int)$res['total_count'] : 0;
}

function get_variant_code_for_variety($variety) {
    $cleanVariety = strtoupper(trim((string)$variety));
    $varietyMap = [
        'RULED' => 'SR',
        'UNRULED' => 'UR',
        'ACCOUNTANCY' => 'AC',
        'COMMON' => 'COM',
        'GRAPH' => 'GRA',
        'ONE SIDE RULED' => 'OSR',
        'CHEMISTRY' => 'CM',
        'CHEM' => 'CM',
        'GENERAL' => 'GENERAL',
        'PRINTED' => 'PRINTED',
        'MATHS PLAIN' => 'MP',
        'MICRO POINT' => 'MP',
        'MATHS RULED' => 'MR',
        'FOUR LINE' => 'FR',
        'DOUBLE LINE' => 'DR',
        'SQUARE' => 'SQ',
        'FIVE SUBJECT' => 'FS'
    ];
    return $varietyMap[$cleanVariety] ?? $cleanVariety;
}

/**
 * Classifies notebook varieties into Ruled (lined/practice/check) vs Unruled (plain/drawing).
 * Note: BR / Brown is preserved as unruled / default fallback per existing production behavior.
 */
function is_notebook_variety_ruled($variety_str, $variant_code = '') {
    $str = strtoupper(trim((string)$variety_str));
    $code = strtoupper(trim((string)$variant_code));

    // 1. Explicit unruled / plain varieties (checked first to prevent "Unruled" matching "Ruled")
    if (
        strpos($str, 'UNRULED') !== false ||
        strpos($str, 'MATHS PLAIN') !== false ||
        strpos($str, 'DRAWING') !== false ||
        strpos($str, 'PLAIN') !== false ||
        $str === 'UR' || $code === 'UR' ||
        $str === 'MP' || $code === 'MP' ||
        $str === 'BR' || $code === 'BR' ||
        strpos($str, 'BROWN') !== false
    ) {
        return false;
    }

    // 2. Ruled / lined / practice / check varieties
    if (
        (strpos($str, 'RULED') !== false && strpos($str, 'UNRULED') === false) ||
        strpos($str, 'DOUBLE LINE') !== false ||
        strpos($str, 'FOUR LINE') !== false ||
        strpos($str, 'SQUARE') !== false ||
        strpos($str, 'ONE SIDE') !== false ||
        in_array($str, ['SR', 'DR', 'FR', 'MR', 'SQ', 'OS', 'OSR']) ||
        in_array($code, ['SR', 'DR', 'FR', 'MR', 'SQ', 'OS', 'OSR'])
    ) {
        return true;
    }

    return false;
}

function get_product_single_piece_price($product, $ruling_type = 'Unruled', $variant_id = 0, $product_code = '') {
    if (!$product || !is_array($product)) return 0.0;
    
    $clean_ruling = trim(preg_replace('/\s*\((?:Bundle|Single Piece)[^)]*\)$/i', '', (string)$ruling_type));
    $variety_info = parse_variety_info($clean_ruling);
    $variety = trim((string)($variety_info['variety'] ?? ''));
    $product_id = (int)($product['id'] ?? 0);

    if ($product_id > 0 && $variety !== '') {
        $db = getDB();
        $variantCode = get_variant_code_for_variety($variety);
        if ((int)$variant_id > 0) {
            $stmt = $db->prepare("
                SELECT price, sale_price, selling_price, discounted_price
                FROM product_variants
                WHERE product_id = ? AND id = ?
                LIMIT 1
            ");
            $stmt->execute([$product_id, (int)$variant_id]);
        } elseif (trim((string)$product_code) !== '') {
            $stmt = $db->prepare("
                SELECT price, sale_price, selling_price, discounted_price
                FROM product_variants
                WHERE product_id = ? AND product_code = ?
                LIMIT 1
            ");
            $stmt->execute([$product_id, trim((string)$product_code)]);
        } else {
            $stmt = $db->prepare("
                SELECT price, sale_price, selling_price, discounted_price
                FROM product_variants
                WHERE product_id = ?
                  AND (UPPER(variety) = UPPER(?) OR UPPER(variety_display) = UPPER(?) OR UPPER(variety) = UPPER(?))
                ORDER BY
                    CASE
                        WHEN UPPER(variety_display) = UPPER(?) THEN 0
                        WHEN UPPER(variety) = UPPER(?) THEN 1
                        WHEN UPPER(variety) = UPPER(?) THEN 2
                        ELSE 3
                    END,
                    id
                LIMIT 1
            ");
            $stmt->execute([
                $product_id,
                $variety,
                $variety,
                $variantCode,
                $variety,
                $variety,
                $variantCode
            ]);
        }
        $variant_price = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($variant_price) {
            if (!empty($variant_price['discounted_price']) && (float)$variant_price['discounted_price'] > 0) {
                return (float)$variant_price['discounted_price'];
            }
            $hasVariantRegularPrice = !empty($variant_price['price']) && (float)$variant_price['price'] > 0;
            $hasVariantSalePrice = !empty($variant_price['sale_price']) && (float)$variant_price['sale_price'] > 0;
            if ($hasVariantRegularPrice || $hasVariantSalePrice) {
                $regularPrice = $hasVariantRegularPrice
                    ? (float)$variant_price['price']
                    : (float)($product['price'] ?? 0);
                $salePrice = $hasVariantSalePrice
                    ? (float)$variant_price['sale_price']
                    : (float)($product['sale_price'] ?? 0);
                if ($salePrice > 0 && $regularPrice > 0 && $salePrice < $regularPrice) {
                    return $salePrice;
                }
                if ($regularPrice > 0) {
                    return $regularPrice;
                }
                if ($salePrice > 0) {
                    return $salePrice;
                }
            }
            if (!empty($variant_price['selling_price']) && (float)$variant_price['selling_price'] > 0) {
                return (float)$variant_price['selling_price'];
            }
        }
    }

    $is_ruled = is_notebook_variety_ruled($clean_ruling, $variantCode ?? '');

    if ($is_ruled) {
        if (!empty($product['discounted_price_ruled']) && (float)$product['discounted_price_ruled'] > 0) {
            return (float)$product['discounted_price_ruled'];
        }
        if (!empty($product['selling_price_ruled']) && (float)$product['selling_price_ruled'] > 0) {
            return (float)$product['selling_price_ruled'];
        }
    } else {
        if (!empty($product['discounted_price_unruled']) && (float)$product['discounted_price_unruled'] > 0) {
            return (float)$product['discounted_price_unruled'];
        }
        if (!empty($product['selling_price_unruled']) && (float)$product['selling_price_unruled'] > 0) {
            return (float)$product['selling_price_unruled'];
        }
    }

    if (!empty($product['sale_price']) && (float)$product['sale_price'] > 0 && (float)$product['sale_price'] < (float)$product['price']) {
        return (float)$product['sale_price'];
    }
    if (!empty($product['price']) && (float)$product['price'] > 0) {
        return (float)$product['price'];
    }
    return 0.0;
}

function get_product_pieces_per_bundle($product_id, $ruling_str = '', $prod = null, $variant_id = 0) {
    // 1. If ruling_str explicitly contains "Bundle - X Pcs", extract X
    if (preg_match('/Bundle\s*-\s*(\d+)\s*Pcs/i', (string)$ruling_str, $m)) {
        $val = (int)$m[1];
        if ($val > 1) return $val;
    }

    $db = getDB();

    // 2. Check product_variants table matching by product_id and variety
    if ($product_id) {
        if ((int)$variant_id > 0) {
            $stmt = $db->prepare("
                SELECT bundle_qty
                FROM product_variants
                WHERE product_id = ? AND id = ?
                LIMIT 1
            ");
            $stmt->execute([(int)$product_id, (int)$variant_id]);
            $row = $stmt->fetch();
            if ($row && (int)$row['bundle_qty'] > 0) {
                return (int)$row['bundle_qty'];
            }
        }

        $vInfo = parse_variety_info($ruling_str);
        $vName = !empty($vInfo['variety']) ? $vInfo['variety'] : $ruling_str;
        $cleanV = strtoupper(trim($vName));

        if ($cleanV !== '') {
            $stmt = $db->prepare("
                SELECT bundle_qty FROM product_variants 
                WHERE product_id = ? 
                  AND (UPPER(variety) = ? OR UPPER(variety_display) = ? OR UPPER(variety) = ?)
                ORDER BY
                    CASE
                        WHEN UPPER(variety_display) = ? THEN 0
                        WHEN UPPER(variety) = ? THEN 1
                        ELSE 2
                    END,
                    id
                LIMIT 1
            ");
            $stmt->execute([
                $product_id,
                $cleanV,
                $cleanV,
                strtoupper(get_variant_code_for_variety($vName)),
                $cleanV,
                $cleanV
            ]);
            $row = $stmt->fetch();
            if ($row && (int)$row['bundle_qty'] > 0) {
                return (int)$row['bundle_qty'];
            }
        }

        $stmtFallback = $db->prepare("SELECT bundle_qty FROM product_variants WHERE product_id = ? AND bundle_qty > 0 ORDER BY id ASC LIMIT 1");
        $stmtFallback->execute([$product_id]);
        $fbRow = $stmtFallback->fetch();
        if ($fbRow && (int)$fbRow['bundle_qty'] > 0) {
            return (int)$fbRow['bundle_qty'];
        }
    }

    // 3. Check products.std_qty
    if ($prod && is_array($prod) && !empty($prod['std_qty']) && (int)$prod['std_qty'] > 0) {
        return (int)$prod['std_qty'];
    } elseif ($product_id) {
        $stmt = $db->prepare("SELECT std_qty FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $pRow = $stmt->fetch();
        if ($pRow && !empty($pRow['std_qty']) && (int)$pRow['std_qty'] > 0) {
            return (int)$pRow['std_qty'];
        }
    }

    return 350; // Fallback standard bundle size
}

/**
 * Resolves the genuine standard pieces per bundle (std quantity) for a product variety.
 */
function get_standard_bundle_size($product_id, $variant_id = 0, $ruling_str = '', $prod_std_qty = null) {
    $db = getDB();

    // 1. Check if variant_id passed and has bundle_qty > 1
    if ((int)$variant_id > 0) {
        $stmt = $db->prepare("SELECT bundle_qty FROM product_variants WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$variant_id]);
        $bQty = (int)$stmt->fetchColumn();
        if ($bQty > 1) return $bQty;
    }

    // 2. Check if ruling string has explicit "Bundle - X Pcs"
    if (preg_match('/Bundle\s*-\s*(\d+)\s*Pcs/i', (string)$ruling_str, $m)) {
        $val = (int)$m[1];
        if ($val > 1) return $val;
    }

    // 3. Match variety in product_variants
    if ($product_id) {
        $vInfo = parse_variety_info($ruling_str);
        $vName = !empty($vInfo['variety']) ? $vInfo['variety'] : $ruling_str;
        $cleanV = strtoupper(trim((string)$vName));

        if ($cleanV !== '') {
            $stmt = $db->prepare("
                SELECT bundle_qty FROM product_variants 
                WHERE product_id = ? 
                  AND (UPPER(variety) = ? OR UPPER(variety_display) = ? OR UPPER(variety) = ?)
                  AND bundle_qty > 1
                ORDER BY CASE WHEN UPPER(variety_display) = ? THEN 0 ELSE 1 END, id ASC
                LIMIT 1
            ");
            $vCode = function_exists('get_variant_code_for_variety') ? get_variant_code_for_variety($vName) : '';
            $stmt->execute([(int)$product_id, $cleanV, $cleanV, strtoupper($vCode), $cleanV]);
            $bQty = (int)$stmt->fetchColumn();
            if ($bQty > 1) return $bQty;
        }

        // 4. Any variant of this product with bundle_qty > 1
        $stmt = $db->prepare("SELECT bundle_qty FROM product_variants WHERE product_id = ? AND bundle_qty > 1 ORDER BY id ASC LIMIT 1");
        $stmt->execute([(int)$product_id]);
        $bQty = (int)$stmt->fetchColumn();
        if ($bQty > 1) return $bQty;
    }

    // 5. Check products table std_qty
    if ($prod_std_qty !== null && (int)$prod_std_qty > 1) {
        return (int)$prod_std_qty;
    }
    if ($product_id) {
        $stmt = $db->prepare("SELECT std_qty FROM products WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$product_id]);
        $stdQty = (int)$stmt->fetchColumn();
        if ($stdQty > 1) return $stdQty;
    }

    return 350; // Fallback standard bundle size
}

/**
 * Computes exact piece, bundle, remaining, and packed unit calculations for an order item.
 *
 * Follows exact wholesale rules:
 * - Full bundles = floor(ordered pieces ÷ standard bundle size)
 * - Remaining pieces = ordered pieces modulo standard bundle size
 * - If remaining pieces > 0, add one partial bundle / packed unit.
 * - If ordered pieces < standard bundle size, packable as one partial bundle.
 */
function calculate_order_item_packing($item, $custom_qty = null) {
    $std_bundle_size = get_standard_bundle_size(
        $item['product_id'] ?? 0, 
        $item['variant_id'] ?? 0, 
        $item['ruling_type'] ?? '', 
        $item['prod_std_qty'] ?? ($item['std_qty'] ?? null)
    );
    if ($std_bundle_size <= 0) $std_bundle_size = 350;

    $is_explicit_bundle = (stripos($item['order_type'] ?? '', 'Bundle') !== false) || (stripos($item['ruling_type'] ?? '', 'Bundle') !== false);

    if ($custom_qty !== null) {
        $qty_val = max(0, (int)$custom_qty);
        if ($is_explicit_bundle) {
            $ordered_pieces = $qty_val * $std_bundle_size;
        } else {
            $ordered_pieces = $qty_val;
        }
    } elseif (!empty($item['total_pieces']) && (int)$item['total_pieces'] > 0) {
        $ordered_pieces = (int)$item['total_pieces'];
    } elseif ($is_explicit_bundle) {
        $ordered_pieces = (int)($item['quantity'] ?? 1) * $std_bundle_size;
    } else {
        $ordered_pieces = (int)($item['quantity'] ?? 0);
    }

    $full_bundles = (int)floor($ordered_pieces / $std_bundle_size);
    $remaining_pieces = $ordered_pieces % $std_bundle_size;
    $partial_bundles = ($remaining_pieces > 0) ? 1 : 0;
    $packed_units = $full_bundles + $partial_bundles;

    return [
        'ordered_pieces' => $ordered_pieces,
        'standard_bundle_size' => $std_bundle_size,
        'full_bundles' => $full_bundles,
        'remaining_pieces' => $remaining_pieces,
        'partial_bundles' => $partial_bundles,
        'packed_units' => $packed_units,
        'is_explicit_bundle' => $is_explicit_bundle
    ];
}


/**
 * Computes aggregated packing details for an order across all line items.
 */
function get_order_packing_details($order_id, $items = null) {
    $db = getDB();
    if ($items === null) {
        $stmt = $db->prepare("
            SELECT oi.*, p.pages AS prod_pages, p.std_qty AS prod_std_qty, p.name AS prod_name 
            FROM order_items oi 
            LEFT JOIN products p ON oi.product_id = p.id 
            WHERE oi.order_id = ? 
            ORDER BY oi.id ASC
        ");
        $stmt->execute([(int)$order_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $total_ordered_pieces = 0;
    $total_full_bundles = 0;
    $total_remaining_pieces = 0;
    $total_partial_bundles = 0;
    $total_packed_units = 0;
    $item_details = [];

    foreach ($items as $it) {
        $calc = calculate_order_item_packing($it);
        $total_ordered_pieces += $calc['ordered_pieces'];
        $total_full_bundles += $calc['full_bundles'];
        $total_remaining_pieces += $calc['remaining_pieces'];
        $total_partial_bundles += $calc['partial_bundles'];
        $total_packed_units += $calc['packed_units'];

        $item_details[] = array_merge($it, $calc);
    }

    // Determine parcel count:
    $existing_parcels = function_exists('get_order_parcels') ? get_order_parcels($order_id) : [];
    $logged_parcel_count = count($existing_parcels);

    $stmt_o = $db->prepare("SELECT expected_parcels, actual_parcels FROM orders WHERE id = ? LIMIT 1");
    $stmt_o->execute([(int)$order_id]);
    $order_row = $stmt_o->fetch(PDO::FETCH_ASSOC) ?: [];

    $actual_parcels = !empty($order_row['actual_parcels']) ? (int)$order_row['actual_parcels'] : null;
    $expected_parcels = !empty($order_row['expected_parcels']) ? (int)$order_row['expected_parcels'] : null;

    if ($logged_parcel_count > 0) {
        $parcel_count = $logged_parcel_count;
    } elseif ($actual_parcels !== null && $actual_parcels > 0) {
        $parcel_count = $actual_parcels;
    } elseif ($expected_parcels !== null && $expected_parcels > 0) {
        $parcel_count = $expected_parcels;
    } else {
        $parcel_count = 1; // Default to 1 parcel unless configured otherwise
    }

    return [
        'items' => $item_details,
        'total_ordered_pieces' => $total_ordered_pieces,
        'total_full_bundles' => $total_full_bundles,
        'total_remaining_pieces' => $total_remaining_pieces,
        'total_partial_bundles' => $total_partial_bundles,
        'total_packed_units' => $total_packed_units,
        'logged_parcel_count' => $logged_parcel_count,
        'expected_parcels' => $expected_parcels,
        'actual_parcels' => $actual_parcels,
        'parcel_count' => $parcel_count,
        'existing_parcels' => $existing_parcels
    ];
}

function get_product_bundle_price($product, $ruling_str = '') {
    $pid = is_array($product) ? ($product['id'] ?? 0) : (int)$product;
    $single_piece_price = get_product_single_piece_price($product, $ruling_str);
    $pieces_per_bundle = get_product_pieces_per_bundle($pid, $ruling_str, $product);
    return round($single_piece_price * max(1, $pieces_per_bundle), 2);
}

function get_product_effective_price($product, $ruling_type = 'Unruled') {
    if (!empty($product['cart_unit_price']) && $product['cart_unit_price'] > 0) {
        return (float)$product['cart_unit_price'];
    }

    return get_product_single_piece_price($product, $ruling_type);
}

function get_cart_contents() {
    $db = getDB();
    $session_id = get_cart_session_id();
    $user_id = is_customer_logged_in() ? $_SESSION['customer_id'] : null;
    
    if ($user_id) {
        sync_cart_on_login($user_id, $session_id);
    }
    
    $stmt = $db->prepare("
        SELECT ci.id AS item_id, ci.quantity, ci.cart_id, ci.variant_id,
               ci.ruling_type, ci.product_code AS cart_product_code,
               ci.unit_price AS cart_unit_price, ci.pieces_per_bundle AS cart_pieces_per_bundle,
               ci.total_pieces AS cart_total_pieces, p.*, c.name AS category_name
        FROM cart_items ci
        JOIN cart crt ON ci.cart_id = crt.id
        JOIN products p ON ci.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE " . ($user_id ? "crt.user_id = ?" : "crt.session_id = ?") . "
        ORDER BY ci.id ASC
    ");
    $stmt->execute([$user_id ? $user_id : $session_id]);
    $items = $stmt->fetchAll();

    foreach ($items as &$item) {
        $r_type = !empty($item['ruling_type']) ? $item['ruling_type'] : 'Unruled';
        $vInfo = parse_variety_info($r_type, $item['pages'] ?? '');
        $isBundle = (stripos($vInfo['order_type'] ?? '', 'Bundle') !== false);
        
        $itemProductCode = !empty($item['cart_product_code'])
            ? $item['cart_product_code']
            : get_variant_product_code($item['id'], $r_type, $item['pages'] ?? null);
        if (empty($item['variant_id'])) {
            $resolvedVariant = get_product_variant_stock_record(
                $db,
                $item['id'],
                $itemProductCode,
                $r_type,
                $item
            );
            if (!empty($resolvedVariant['id'])) {
                $item['variant_id'] = (int)$resolvedVariant['id'];
                $db->prepare("UPDATE cart_items SET variant_id = ? WHERE id = ? AND product_id = ? AND variant_id IS NULL")
                    ->execute([$item['variant_id'], $item['item_id'], $item['id']]);
            }
        }
        if (!empty($item['variant_id'])) {
            $variantGstStmt = getDB()->prepare("
                SELECT gst_rate FROM product_variants
                WHERE product_id = ? AND id = ?
                LIMIT 1
            ");
            $variantGstStmt->execute([(int)$item['id'], (int)$item['variant_id']]);
            $variantGstRate = $variantGstStmt->fetchColumn();
            if ($variantGstRate !== false && $variantGstRate !== null && $variantGstRate !== '') {
                $item['gst_rate'] = (float)$variantGstRate;
            }
        }
        $base_piece_price = get_product_single_piece_price($item, $r_type, (int)($item['variant_id'] ?? 0), $itemProductCode);
        if ($base_piece_price <= 0) {
            $base_piece_price = !empty($item['price']) ? (float)$item['price'] : 0.0;
        }
        $pcs_per_bundle = get_product_pieces_per_bundle($item['id'], $r_type, $item, (int)($item['variant_id'] ?? 0));
        if ($pcs_per_bundle < 1) {
            $pcs_per_bundle = 1;
        }

        if ($isBundle) {
            $actual_bundle_price = round($base_piece_price * max(1, $pcs_per_bundle), 2);
            $item['effective_price'] = $actual_bundle_price;
            $item['cart_unit_price'] = $actual_bundle_price;
            $clean_r = trim(preg_replace('/\s*\((?:Bundle|Single Piece)[^)]*\)$/i', '', $r_type));
            $new_ruling = $clean_r . ' (Bundle - ' . $pcs_per_bundle . ' Pcs)';
            if (empty($item['cart_unit_price']) || (float)$item['cart_unit_price'] != $actual_bundle_price || $item['ruling_type'] !== $new_ruling) {
                $db->prepare("UPDATE cart_items SET unit_price = ?, ruling_type = ? WHERE id = ?")
                   ->execute([$actual_bundle_price, $new_ruling, $item['item_id']]);
                $item['ruling_type'] = $new_ruling;
            }
        } else {
            $item['effective_price'] = (float)$base_piece_price;
            $item['cart_unit_price'] = (float)$base_piece_price;
        }

        if ($isBundle) {
            $item['pieces_per_bundle'] = $pcs_per_bundle;
            $item['total_pieces'] = $pcs_per_bundle * (int)$item['quantity'];
        } else {
            $item['pieces_per_bundle'] = 1;
            $item['total_pieces'] = (int)$item['quantity'];
        }

        $item['product_code'] = $itemProductCode;
    }
    unset($item);
    return $items;
}


if (!function_exists('get_variant_product_code')) {
    function get_variant_product_code($product_id, $ruling_type_str = '', $pages = null) {
        if (!$product_id) return '';
        $db = getDB();
        
        $vInfo = parse_variety_info($ruling_type_str);
        $vName = !empty($vInfo['variety']) ? $vInfo['variety'] : $ruling_type_str;
        $cleanV = strtoupper(trim($vName));
        
        $codeSearch = get_variant_code_for_variety($cleanV);
        
        $stmt = $db->prepare("
            SELECT product_code 
            FROM product_variants 
            WHERE product_id = ? 
              AND (UPPER(variety) = ? OR UPPER(variety_display) = ? OR UPPER(variety) = ?)
            LIMIT 1
        ");
        $stmt->execute([$product_id, $cleanV, $cleanV, $codeSearch]);
        $row = $stmt->fetch();
        
        if ($row && !empty($row['product_code'])) {
            return $row['product_code'];
        }
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM product_variants WHERE product_id = ?");
        $stmt->execute([$product_id]);
        if ((int)$stmt->fetchColumn() > 0) {
            return '';
        }

        $stmt = $db->prepare("SELECT sku FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $row = $stmt->fetch();
        return $row ? $row['sku'] : '';
    }
}

if (!function_exists('render_page_navigation')) {
    function render_page_navigation($page_title, $crumbs = [], $show_back_button = true) {
        if (empty($crumbs)) {
            $crumbs = [$page_title => ''];
        }
        
        $current_script = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
        $no_back_pages = ['index.php', 'about.php', 'categories.php', 'reviews.php', 'contact.php', 'products.php'];
        
        if (in_array($current_script, $no_back_pages)) {
            $show_back_button = false;
        }

        ob_start();
        ?>
        <div class="page-top-nav-wrap mb-4 d-flex flex-wrap align-items-center justify-content-between gap-3 no-print">
          <div class="d-flex align-items-center gap-3">
            <?php if ($show_back_button): ?>
              <button type="button" onclick="if(window.history.length > 1){ window.history.back(); } else { window.location.href='index.php'; }" class="btn btn-outline-dark btn-sm rounded-pill px-3 py-1.5 shadow-sm fw-semibold">
                <i class="bi bi-arrow-left me-1 text-gold"></i> Back
              </button>
            <?php endif; ?>
            <h2 class="fw-bold text-navy mb-0 fs-3"><?php echo htmlspecialchars($page_title); ?></h2>
          </div>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0 bg-light px-3 py-1.5 rounded-pill border">
              <li class="breadcrumb-item"><a href="index.php" class="text-secondary text-decoration-none small"><i class="bi bi-house-door me-1 text-gold"></i> Home</a></li>
              <?php foreach ($crumbs as $label => $link): ?>
                <?php if (!empty($link)): ?>
                  <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars($link); ?>" class="text-secondary text-decoration-none small"><?php echo htmlspecialchars($label); ?></a></li>
                <?php else: ?>
                  <li class="breadcrumb-item active text-gold fw-bold small" aria-current="page"><?php echo htmlspecialchars($label); ?></li>
                <?php endif; ?>
              <?php endforeach; ?>
            </ol>
          </nav>
        </div>
        <?php
        return ob_get_clean();
    }
}

if (!function_exists('parse_variety_info')) {
    function parse_variety_info($ruling_type_str, $fallback_pages = '') {
        $raw = !empty($ruling_type_str) ? trim($ruling_type_str) : '';
        
        $orderTypeTag = '';
        if (preg_match('/\(([^)]+)\)$/', $raw, $m)) {
            $orderTypeTag = trim($m[1]);
            $raw = trim(preg_replace('/\s*\([^)]+\)$/', '', $raw));
        }
        
        $pageCount = !empty($fallback_pages) ? (trim($fallback_pages) . ' Pages') : '';
        $variety = '';

        if (strpos($raw, ' - ') !== false) {
            $parts = explode(' - ', $raw, 2);
            $pageCount = trim($parts[0]);
            $variety = trim($parts[1]);
        } elseif (!empty($raw)) {
            $variety = $raw;
        }

        if (empty($variety) || strcasecmp(trim($variety), trim($pageCount)) === 0 || preg_match('/^\d+\s*pages$/i', trim($variety))) {
            if (empty($pageCount) && preg_match('/^\d+\s*pages$/i', trim($variety))) {
                $pageCount = trim($variety);
            }
            $variety = 'Standard';
        }

        if (strcasecmp(trim($variety), 'Micro Point') === 0) {
            $variety = 'Maths Plain';
        }

        return [
            'page_count' => $pageCount,
            'variety' => $variety,
            'order_type' => $orderTypeTag
        ];
    }
}

if (!defined('COMPANY_GSTIN')) {
    define('COMPANY_GSTIN', '33AHNPM8502B1ZM');
}

function get_company_gstin() {
    return get_setting('company_gstin', COMPANY_GSTIN);
}

function calculate_items_gst($items, $discount = 0, $delivery_fee = 0) {
    $discount = max(0, (float)$discount);
    $delivery_fee = max(0, (float)$delivery_fee);
    
    // First calculate raw taxable subtotals per line item
    $raw_lines = [];
    $total_raw_subtotal = 0.0;
    
    foreach ($items as $item) {
        $price = isset($item['effective_price']) ? (float)$item['effective_price'] : (float)($item['price'] ?? 0);
        $qty = max(1, (int)($item['quantity'] ?? 1));
        $line_subtotal = round($price * $qty, 2);
        
        $rate = 18.00;
        if (isset($item['gst_rate']) && $item['gst_rate'] !== null && $item['gst_rate'] !== '') {
            $rate = (float)$item['gst_rate'];
        }
        
        $raw_lines[] = [
            'raw_subtotal' => $line_subtotal,
            'rate'         => $rate,
            'item'         => $item
        ];
        $total_raw_subtotal += $line_subtotal;
    }
    
    $rates_breakdown = [];
    $processed_items = [];
    $total_taxable = 0.0;
    $total_gst = 0.0;
    $total_cgst = 0.0;
    $total_sgst = 0.0;
    
    foreach ($raw_lines as $line) {
        $line_subtotal = $line['raw_subtotal'];
        $rate = $line['rate'];
        
        // Prorate coupon discount proportionally across lines if applicable
        if ($discount > 0 && $total_raw_subtotal > 0) {
            $line_disc = round(($line_subtotal / $total_raw_subtotal) * $discount, 2);
            $line_taxable = max(0, round($line_subtotal - $line_disc, 2));
        } else {
            $line_taxable = $line_subtotal;
        }
        
        // If historical order item already has exact saved taxable and GST amounts, use them
        if (isset($line['item']['gst_amount']) && (float)$line['item']['gst_amount'] > 0 && $discount <= 0 && isset($line['item']['taxable_amount']) && (float)$line['item']['taxable_amount'] > 0) {
            $line_taxable = (float)$line['item']['taxable_amount'];
            $line_gst     = (float)$line['item']['gst_amount'];
            $line_cgst    = isset($line['item']['cgst_amount']) ? (float)$line['item']['cgst_amount'] : round($line_gst / 2, 2);
            $line_sgst    = isset($line['item']['sgst_amount']) ? (float)$line['item']['sgst_amount'] : round($line_gst - $line_cgst, 2);
        } else {
            // Standard dynamic calculation:
            // lineTaxableAmount = finalProductPrice * quantity
            // lineGST = round(lineTaxableAmount * productGSTRate / 100, 2)
            // CGST = round(lineGST / 2, 2)
            // SGST = round(lineGST - CGST, 2)
            $line_gst  = round(($line_taxable * $rate) / 100, 2);
            $line_cgst = round($line_gst / 2, 2);
            $line_sgst = round($line_gst - $line_cgst, 2);
        }
        
        $rate_key = number_format($rate, 2, '.', '');
        if (!isset($rates_breakdown[$rate_key])) {
            $cgst_pct = $rate / 2;
            $sgst_pct = $rate / 2;
            $rates_breakdown[$rate_key] = [
                'rate'            => $rate,
                'rate_label'      => ((float)$rate == (int)$rate ? (int)$rate : $rate) . '% GST',
                'cgst_rate'       => $cgst_pct,
                'sgst_rate'       => $sgst_pct,
                'cgst_rate_label' => ((float)$cgst_pct == (int)$cgst_pct ? (int)$cgst_pct : $cgst_pct) . '% CGST',
                'sgst_rate_label' => ((float)$sgst_pct == (int)$sgst_pct ? (int)$sgst_pct : $sgst_pct) . '% SGST',
                'taxable_amount'  => 0.0,
                'gst_amount'      => 0.0,
                'cgst_amount'     => 0.0,
                'sgst_amount'     => 0.0
            ];
        }
        
        $rates_breakdown[$rate_key]['taxable_amount'] = round($rates_breakdown[$rate_key]['taxable_amount'] + $line_taxable, 2);
        $rates_breakdown[$rate_key]['gst_amount']     = round($rates_breakdown[$rate_key]['gst_amount'] + $line_gst, 2);
        $rates_breakdown[$rate_key]['cgst_amount']    = round($rates_breakdown[$rate_key]['cgst_amount'] + $line_cgst, 2);
        $rates_breakdown[$rate_key]['sgst_amount']    = round($rates_breakdown[$rate_key]['sgst_amount'] + $line_sgst, 2);
        
        $total_taxable += $line_taxable;
        $total_gst     += $line_gst;
        $total_cgst    += $line_cgst;
        $total_sgst    += $line_sgst;
        
        $item_with_gst = $line['item'];
        $item_with_gst['gst_rate']       = $rate;
        $item_with_gst['taxable_amount'] = $line_taxable;
        $item_with_gst['gst_amount']     = $line_gst;
        $item_with_gst['cgst_amount']    = $line_cgst;
        $item_with_gst['sgst_amount']    = $line_sgst;
        $processed_items[] = $item_with_gst;
    }
    
    // Sort rates descending (e.g. 18%, 12%, 5%, 0%)
    krsort($rates_breakdown);
    
    $grand_total = round($total_taxable + $total_gst + $delivery_fee, 2);
    
    return [
        'subtotal'           => $total_raw_subtotal,
        'discount'           => $discount,
        'taxable_amount'     => $total_taxable,
        'taxableAmount'      => $total_taxable,
        'total_gst'          => $total_gst,
        'totalGst'           => $total_gst,
        'cgst'               => $total_cgst,
        'sgst'               => $total_sgst,
        'delivery_fee'       => $delivery_fee,
        'grand_total'        => $grand_total,
        'grandTotal'         => $grand_total,
        'gstin'              => get_company_gstin(),
        'rates_breakdown'    => $rates_breakdown,
        'has_multiple_rates' => count($rates_breakdown) > 1,
        'items'              => $processed_items
    ];
}

function calculate_order_gst($subtotal, $discount = 0, $delivery_fee = 0, $default_rate = 18.00) {
    // Fallback when only totals are passed without line items
    $taxable_amount = max(0, round((float)$subtotal - (float)$discount, 2));
    $rate = (float)$default_rate;
    $total_gst = round(($taxable_amount * $rate) / 100, 2);
    $cgst = round($total_gst / 2, 2);
    $sgst = round($total_gst - $cgst, 2);
    $grand_total = max(0, round($taxable_amount + $total_gst + (float)$delivery_fee, 2));
    
    $rate_key = number_format($rate, 2, '.', '');
    $cgst_pct = $rate / 2;
    $sgst_pct = $rate / 2;
    $rates_breakdown = [
        $rate_key => [
            'rate'            => $rate,
            'rate_label'      => ((float)$rate == (int)$rate ? (int)$rate : $rate) . '% GST',
            'cgst_rate'       => $cgst_pct,
            'sgst_rate'       => $sgst_pct,
            'cgst_rate_label' => ((float)$cgst_pct == (int)$cgst_pct ? (int)$cgst_pct : $cgst_pct) . '% CGST',
            'sgst_rate_label' => ((float)$sgst_pct == (int)$sgst_pct ? (int)$sgst_pct : $sgst_pct) . '% SGST',
            'taxable_amount'  => $taxable_amount,
            'gst_amount'      => $total_gst,
            'cgst_amount'     => $cgst,
            'sgst_amount'     => $sgst
        ]
    ];

    return [
        'subtotal'           => (float)$subtotal,
        'discount'           => (float)$discount,
        'taxable_amount'     => $taxable_amount,
        'taxableAmount'      => $taxable_amount,
        'cgst'               => $cgst,
        'sgst'               => $sgst,
        'total_gst'          => $total_gst,
        'totalGst'           => $total_gst,
        'gst_rate'           => $rate,
        'cgst_rate'          => $cgst_pct,
        'sgst_rate'          => $sgst_pct,
        'gstin'              => get_company_gstin(),
        'delivery_fee'       => (float)$delivery_fee,
        'grand_total'        => $grand_total,
        'grandTotal'         => $grand_total,
        'rates_breakdown'    => $rates_breakdown,
        'has_multiple_rates' => false
    ];
}

function get_order_gst_summary($order_or_id, $items = null) {
    $db = getDB();
    if (is_numeric($order_or_id)) {
        $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([(int)$order_or_id]);
        $order = $stmt->fetch();
    } else {
        $order = $order_or_id;
    }
    
    if (!$order) {
        return calculate_order_gst(0, 0, 0);
    }
    
    if ($items === null && !empty($order['id'])) {
        $stmt_items = $db->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
        $stmt_items->execute([(int)$order['id']]);
        $items = $stmt_items->fetchAll();
    }
    
    if (!empty($items)) {
        return calculate_items_gst($items, $order['discount'] ?? 0, $order['delivery_fee'] ?? 0);
    }
    
    return calculate_order_gst($order['subtotal'] ?? 0, $order['discount'] ?? 0, $order['delivery_fee'] ?? 0);
}

function calculate_cart_totals() {
    $items = get_cart_contents();
    $subtotal = 0;
    foreach ($items as $item) {
        $effective_price = isset($item['effective_price']) ? (float)$item['effective_price'] : (!empty($item['cart_unit_price']) ? (float)$item['cart_unit_price'] : (float)($item['price'] ?? 0));
        $subtotal += $effective_price * $item['quantity'];
    }
    
    $shipping_threshold = (float)get_setting('free_shipping_threshold', 499);
    $delivery_fee = ($subtotal >= $shipping_threshold || $subtotal == 0) ? 0.00 : 40.00;
    
    unset($_SESSION['applied_coupon']);
    $discount = 0.00;
    
    $gst_data = calculate_items_gst($items, $discount, $delivery_fee);
    
    return array_merge([
        'subtotal'     => $subtotal,
        'discount'     => $discount,
        'delivery_fee' => $delivery_fee
    ], $gst_data);
}

// Format price with INR currency symbol
function format_price($amount) {
    return '₹' . number_format((float)$amount, 2);
}

// Customer Authentication Helpers
function is_customer_logged_in() {
    return isset($_SESSION['customer_id']) && !empty($_SESSION['customer_id']);
}

function get_current_customer() {
    if (!is_customer_logged_in()) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['customer_id']]);
    return $stmt->fetch();
}

// GSTIN Format Validation (15-character Indian GSTIN structure)
function is_valid_gst_number($gst, $allow_empty = false) {
    $gst = strtoupper(trim((string)$gst));
    if ($gst === '') return (bool)$allow_empty;
    return (bool)preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/', $gst);
}

// Format Address cleanly (handles users or orders records)
function format_display_address($data, $multiline = false) {
    if (empty($data) || !is_array($data)) return 'Not Provided';
    $line1 = !empty($data['shipping_address']) ? trim($data['shipping_address']) : (!empty($data['address']) ? trim($data['address']) : '');
    $line2 = !empty($data['address_line2']) ? trim($data['address_line2']) : '';
    $area  = !empty($data['area']) ? trim($data['area']) : '';
    $city  = !empty($data['city']) ? trim($data['city']) : '';
    $dist  = !empty($data['district']) ? trim($data['district']) : '';
    $state = !empty($data['state']) ? trim($data['state']) : '';
    $pin   = !empty($data['pincode']) ? trim($data['pincode']) : '';
    $land  = !empty($data['landmark']) ? trim($data['landmark']) : '';

    if (empty($line1) && empty($city) && empty($pin)) {
        return 'Not Provided';
    }

    $lines = [];
    if (!empty($line1)) $lines[] = $line1;
    
    $loc = [];
    if (!empty($line2)) $loc[] = $line2;
    if (!empty($area)) $loc[] = $area;
    if (!empty($loc)) $lines[] = implode(', ', $loc);

    $city_dist = [];
    if (!empty($city)) $city_dist[] = $city;
    if (!empty($dist) && strcasecmp($dist, $city) !== 0) $city_dist[] = $dist;
    if (!empty($city_dist)) $lines[] = implode(', ', $city_dist);

    $region = [];
    if (!empty($state)) $region[] = $state;
    if (!empty($pin)) $region[] = $pin;
    if (!empty($region)) $lines[] = implode(' - ', $region);

    if ($multiline) {
        $out = implode("<br>\n", array_map('htmlspecialchars', $lines));
        if (!empty($land)) {
            $out .= "<br>\n<small class=\"text-muted\"><em>Landmark: " . htmlspecialchars($land) . "</em></small>";
        }
        return $out;
    }
    if (!empty($land)) {
        $lines[] = '(Landmark: ' . $land . ')';
    }
    return implode(', ', $lines);
}

// Admin Authentication Helpers
function is_admin_logged_in() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

function get_current_admin() {
    if (!is_admin_logged_in()) return null;
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    return $stmt->fetch();
}

function require_admin_login() {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    if (!is_admin_logged_in()) {
        header('Location: ' . get_site_url() . '/login.php');
        exit();
    }
}

// Flash Messages
function set_flash($type, $message) {
    $_SESSION['flash'][$type] = $message;
}

function display_flash() {
    if (!empty($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $type => $msg) {
            $alertClass = ($type === 'success') ? 'alert-success' : (($type === 'error') ? 'alert-danger' : 'alert-info');
            echo '<div class="alert ' . $alertClass . ' alert-dismissible fade show" role="alert">';
            echo htmlspecialchars($msg);
            echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            echo '</div>';
        }
        unset($_SESSION['flash']);
    }
}

// Order Number Generator
function generate_order_number() {
    return 'ORD-' . date('Ymd') . '-' . rand(1000, 9999);
}


// Build URL for filter parameter updates
if (!function_exists('build_filter_url')) {
    function build_filter_url($param_to_remove, $value_to_remove) {
        $params = isset($_GET) ? $_GET : [];
        if ($param_to_remove === 'category' || $param_to_remove === 'cat') {
            unset($params['category']);
            unset($params['cat']);
        } elseif (isset($params[$param_to_remove])) {
            if (is_array($params[$param_to_remove])) {
                $params[$param_to_remove] = array_values(array_diff($params[$param_to_remove], [$value_to_remove]));
                if (empty($params[$param_to_remove])) {
                    unset($params[$param_to_remove]);
                }
            } else {
                unset($params[$param_to_remove]);
            }
        }
        return 'products.php' . (!empty($params) ? '?' . http_build_query($params) : '');
    }
}

// ==========================================================================
// PRODUCT VARIANT GROUPING & CATALOGUE RENDERING HELPERS
// ==========================================================================

function get_pdf_catalogue_map() {
    return [
        'RAJDHANI' => [
            '60 Pages' => ['Ruled', 'Unruled', 'Accountancy', 'Composition', 'Graph'],
            '128 Pages' => ['Ruled', 'Unruled']
        ],
        'RAJDHANI PLUS' => [
            '132 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '144 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '160 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '192 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '240 Pages' => ['Ruled', 'Unruled', 'Accountancy']
        ],
        'APOLLO' => [
            '120 Pages' => ['Ruled', 'Unruled']
        ],
        'SMART' => [
            '72 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '132 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '144 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '160 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '240 Pages' => ['Ruled', 'Unruled', 'Accountancy']
        ],
        'ARROW' => [
            '240 Pages' => ['Five Subject Unruled', 'Ruled'],
            '320 Pages' => ['Five Subject Unruled', 'Ruled']
        ],
        'KING' => [
            '80 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            '120 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            '132 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            '160 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS']
        ],
        'G+' => [
            '72 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '92 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '132 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '144 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '160 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '192 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '240 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '288 Pages' => ['Ruled', 'Unruled', 'Accountancy']
        ],
        'YES BOSS' => [
            '144 Pages' => ['Unruled', 'Ruled', 'Accountancy'],
            '160 Pages' => ['Unruled', 'Ruled', 'Accountancy'],
            '192 Pages' => ['Unruled', 'Ruled', 'Accountancy'],
            '240 Pages' => ['Unruled', 'Ruled', 'Accountancy'],
            '288 Pages' => ['Unruled', 'Ruled', 'Accountancy'],
            '320 Pages' => ['Unruled', 'Ruled', 'Accountancy']
        ],
        'KARNA EXECUTIVE' => [
            '144 Pages' => ['Unruled', 'Ruled'],
            '240 Pages' => ['Unruled', 'Ruled'],
            '320 Pages' => ['Unruled', 'Ruled']
        ],
        'LOTUS' => [
            '136 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '240 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '288 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '320 Pages' => ['Ruled', 'Unruled', 'Accountancy'],
            '480 Pages' => ['Ruled', 'Unruled', 'Accountancy']
        ],
        'OXFORD' => [
            '160 Pages' => ['Unruled', 'Ruled', 'Accountancy'],
            '192 Pages' => ['Unruled', 'Ruled', 'Accountancy'],
            '240 Pages' => ['Unruled', 'Ruled', 'Accountancy']
        ],
        'PRIYA HAND WRITING' => [
            '120 Pages' => ['White Rough Unruled'],
            '160 Pages' => ['Unruled'],
            '192 Pages' => ['Unruled'],
            '232 Pages' => ['Unruled'],
            '288 Pages' => ['Unruled'],
            '320 Pages' => ['Unruled'],
            '384 Pages' => ['Unruled']
        ],
        'BRAIN' => [
            '124 Pages' => ['OSR', 'UR', 'Chemistry'],
            '144 Pages' => ['OSR', 'UR', 'Chemistry']
        ],
        'SCIENTIST' => [
            '148 Pages' => ['OSR', 'UR', 'Chemistry', 'Accountancy'],
            '160 Pages' => ['OSR', 'UR', 'Chemistry', 'Accountancy'],
            '192 Pages' => ['OSR', 'UR', 'Chemistry', 'Accountancy']
        ],
        'DRAWING' => [
            '36 Pages' => ['UR'],
            '60 Pages' => ['UR']
        ],
        'SMS' => [
            '60 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            '120 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            '160 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS']
        ],
        'COLLEGE SPECIAL' => [
            '136 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            '160 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS']
        ],
        'SPM' => [
            '136 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            '160 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS']
        ],
        'STYLE' => [
            '136 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            '160 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            '192 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS']
        ],
        'POWER' => [
            '28 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            '72 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            '132 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS'],
            '160 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS']
        ],
        'WINGS - VISA' => [
            '160 Pages' => ['SR', 'UR', 'DR', 'FR', 'MP', 'MR', 'SQ', 'OS']
        ],
        'SCHOOL DIARY' => [
            '144 Pages' => ['Printed']
        ],
        'POCKET NOTE BOOKS' => [
            '100 Pages' => ['Ruled', 'Unruled']
        ],
        'CASH MEMO' => [
            '140 Pages' => ['1+1 Printed Bill Book']
        ],
        'MEETING PAD' => [
            '10 Sheets' => ['Ruled', 'Unruled', 'Accountancy'],
            '20 Sheets' => ['Ruled', 'Unruled', 'Accountancy'],
            '40 Sheets' => ['Ruled', 'Unruled', 'Accountancy'],
            '80 Sheets' => ['Ruled', 'Unruled', 'Accountancy'],
            '100 Sheets' => ['Ruled', 'Unruled', 'Accountancy']
        ],
        'ATTENDANCE' => [
            '36 Pages' => ['Student', 'Staff', 'Master'],
            '72 Pages' => ['Student', 'Staff', 'Master'],
            '160 Pages' => ['Student', 'Staff', 'Master']
        ],
        'SUYA UTHAVI KULU' => [
            '160 Pages' => ['Minutes', 'Rokkam', 'Pothu', 'Semippu', 'Kadan']
        ],
        'LS, FC, 3-FOLD' => [
            '300 Pages' => ['Plain Rule', '2 Columns', 'Money Columns']
        ],
        'POCKET NOTE' => [
            '15 Pages' => ['Plain Ruled with Column']
        ],
        'FILES' => [
            'Standard' => ['Cobra Files', 'Lever Files', 'Ring Files', 'Tag Files', 'Box Files']
        ],
        'WRITING PAPER' => [
            '480 Sheets' => ['Ruled', 'Accountancy', 'Graph'],
            '500 Sheets' => ['Unruled']
        ],
        'COPIERS & EXECUTIVE BOND PAPER' => [
            '500 Sheets' => ['65 gsm', '70 gsm', '75 gsm', '80 gsm', 'TNPL', 'SPB', 'JK', 'BPM']
        ],
        'COLOUR BOARD' => [
            '100 Sheets' => ['Green', 'Pink', 'Yellow', 'Blue', 'White']
        ],
        'MAPS, GRAPHS, BORDERS' => [
            '80 Sheets' => ['Green Graph', 'Red Graph', 'Single Colour Map', 'Double Colour Map', 'Single Colour Border'],
            '100 Sheets' => ['Green Graph', 'Red Graph', 'Single Colour Map', 'Double Colour Map', 'Single Colour Border']
        ]
    ];
}

function get_varieties_for_page_count_from_map($pageCountStr) {
    $map = get_pdf_catalogue_map();
    $cleanTarget = preg_replace('/[^\d]/', '', $pageCountStr);
    $results = [];
    
    foreach ($map as $prod => $pages) {
        foreach ($pages as $pKey => $vars) {
            $cleanPKey = preg_replace('/[^\d]/', '', $pKey);
            if ($cleanPKey === $cleanTarget || stripos($pKey, $pageCountStr) !== false) {
                foreach ($vars as $v) {
                    if (!in_array($v, $results)) {
                        $results[] = $v;
                    }
                }
            }
        }
    }
    return !empty($results) ? $results : ['Ruled', 'Unruled', 'Accountancy'];
}

function get_product_catalogue_structure($baseName, $variants) {
    $pdf_map = get_pdf_catalogue_map();
    $upperBase = strtoupper(trim($baseName));
    
    // 1. Exact match first (case-insensitive)
    foreach ($pdf_map as $key => $data) {
        if ($upperBase === strtoupper(trim($key))) {
            return $data;
        }
    }

    // 2. Longer keys first to prevent "RAJDHANI" from matching "RAJDHANI PLUS"
    $keys = array_keys($pdf_map);
    usort($keys, function($a, $b) {
        return strlen($b) <=> strlen($a);
    });

    foreach ($keys as $key) {
        $upperKey = strtoupper(trim($key));
        if ($upperBase === $upperKey || stripos($upperBase, $upperKey) !== false || stripos($upperKey, $upperBase) !== false) {
            return $pdf_map[$key];
        }
    }

    // Fallback: build dynamically from variants array
    $structure = [];
    foreach ($variants as $v) {
        $pCount = !empty($v['pages']) ? $v['pages'] . " Pages" : (!empty($v['variant_label']) ? $v['variant_label'] : "Standard");
        $ruling = !empty($v['ruling_option']) ? $v['ruling_option'] : 'Ruled';
        
        if (!isset($structure[$pCount])) {
            $structure[$pCount] = [];
        }
        
        $r_parts = explode(',', $ruling);
        foreach ($r_parts as $rp) {
            $rp_clean = trim($rp);
            if ($rp_clean && !in_array($rp_clean, $structure[$pCount])) {
                $structure[$pCount][] = $rp_clean;
            }
        }
        if (empty($structure[$pCount])) {
            $structure[$pCount] = ['Ruled', 'Unruled'];
        }
    }
    
    return $structure;
}

if (!function_exists('extract_product_group_base_name')) {
    function extract_product_group_base_name($product) {
        $rawName = is_array($product) ? ($product['name'] ?? '') : (string)$product;
        $rawName = trim($rawName);
        if ($rawName === '') {
            $brand = is_array($product) && !empty($product['brand_name']) ? trim($product['brand_name']) : '';
            return !empty($brand) ? $brand : 'Notebook';
        }

        $base = $rawName;

        // 1. If explicit page number exists, strip trailing mention of that page count
        if (is_array($product) && !empty($product['pages'])) {
            $pNum = (int)$product['pages'];
            if ($pNum > 0) {
                $base = preg_replace('/\s*[-–—\/]?\s*\(?\s*' . $pNum . '\s*(?:Pages|Pgs|LMT|L|Pg|Sheets|Sheet|pcs|p)?\s*\)?\s*$/i', '', $base);
            }
        }

        // 2. Remove standard trailing page / sheet specifications
        $base = preg_replace('/\s*[-–—\/]?\s*\(?\s*\d+\s*(?:Pages|Pgs|LMT|L|Pg|Sheets|Sheet|pcs|p)\b.*?\)?\s*$/i', '', $base);

        // 3. Clean up edge characters
        $base = trim(preg_replace('/\s*\(\s*[-–—]?\s*\)\s*$/', '', $base));
        $base = trim($base, " \t\n\r\0\x0B-–—/,");

        if ($base === '') {
            $base = $rawName;
        }

        return $base;
    }
}

if (!function_exists('get_product_group_key')) {
    function get_product_group_key($product) {
        $baseName = extract_product_group_base_name($product);
        $catId = is_array($product) && isset($product['category_id']) ? (int)$product['category_id'] : 0;
        $brand = is_array($product) && !empty($product['brand_name']) ? strtoupper(trim($product['brand_name'])) : '';
        $normalizedBase = strtoupper(trim(preg_replace('/\s+/', ' ', $baseName)));
        return 'CAT_' . $catId . '_' . ($brand ? $brand . '_' : '') . $normalizedBase;
    }
}

function get_main_product_for_group($db, $product) {
    static $mainProductsByCategory = [];

    $categoryId = isset($product['category_id']) ? (int)$product['category_id'] : 0;
    $groupKey = get_product_group_key($product);
    if (!isset($mainProductsByCategory[$categoryId])) {
        $stmt = $db->prepare("SELECT * FROM products WHERE category_id = ? ORDER BY id ASC");
        $stmt->execute([$categoryId]);

        $mainProductsByCategory[$categoryId] = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            $candidateKey = get_product_group_key($candidate);
            if (!isset($mainProductsByCategory[$categoryId][$candidateKey])) {
                $mainProductsByCategory[$categoryId][$candidateKey] = $candidate;
            }
        }
    }

    return $mainProductsByCategory[$categoryId][$groupKey] ?? $product;
}

function get_product_group_image($db, $product) {
    $mainProduct = get_main_product_for_group($db, $product);
    $image = !empty($mainProduct['image']) ? $mainProduct['image'] : '';
    return resolve_image_file($image, 'uploads/products/prod_power.webp');
}

if (!function_exists('extractCleanProductName')) {
    function extractCleanProductName($rawName, $brandName = '') {
        $name = trim((string)$rawName);
        if ($name === '') {
            return !empty($brandName) ? trim($brandName) : 'Notebook';
        }
        return $name;
    }
}

function parseProductVariant($product) {
    $brand = is_array($product) && !empty($product['brand_name']) ? trim($product['brand_name']) : 'Raj Priya';
    $rawName = is_array($product) ? ($product['name'] ?? '') : (string)$product;
    $productTitle = trim($rawName);
    if ($productTitle === '') {
        $productTitle = !empty($brand) ? $brand : 'Notebook';
    }

    $variant = is_array($product) ? format_variant_page_label($product) : "Standard";
    $groupKey = get_product_group_key($product);
    $baseName = extract_product_group_base_name($product);

    return [
        0 => $productTitle,
        1 => $variant,
        'brand_name' => $brand,
        'product_name' => $productTitle,
        'base_name' => $baseName,
        'group_key' => $groupKey,
        'variant_label' => $variant
    ];
}

function group_products_by_base($products) {
    if (empty($products)) return [];
    
    $grouped = [];
    foreach ($products as $p) {
        $r_type = !empty($p['ruling_option']) ? $p['ruling_option'] : 'Unruled';
        $p['effective_price'] = get_product_effective_price($p, $r_type);
        $p['formatted_price'] = format_price($p['effective_price']);
        
        $parsed = parseProductVariant($p);
        $baseGroupKey = $parsed['group_key'];
        $brandName = $parsed['brand_name'];
        $productName = $parsed['product_name'];
        
        $p['variant_label'] = $parsed['variant_label'];
        $p['clean_product_name'] = $productName;
        
        // Find existing card group with this baseGroupKey that has fewer than 9 variants
        $targetGroupKey = $baseGroupKey;
        $chunkIndex = 1;
        while (isset($grouped[$targetGroupKey]) && count($grouped[$targetGroupKey]['variants']) >= 9) {
            $chunkIndex++;
            $targetGroupKey = $baseGroupKey . '_GRP_' . $chunkIndex;
        }
        
        if (!isset($grouped[$targetGroupKey])) {
            $grouped[$targetGroupKey] = [
                'id' => $p['id'],
                'group_key' => $targetGroupKey,
                'brand_name' => $brandName,
                'product_name' => $productName,
                'base_name' => $parsed['base_name'],
                'name' => $productName,
                'category_id' => isset($p['category_id']) ? $p['category_id'] : 0,
                'category_name' => isset($p['category_name']) ? $p['category_name'] : '',
                'description' => isset($p['description']) ? $p['description'] : '',
                'image' => get_product_group_image(getDB(), $p),
                'size' => isset($p['size']) ? $p['size'] : '',
                'pages' => isset($p['pages']) ? $p['pages'] : '',
                'price' => isset($p['price']) ? $p['price'] : 0,
                'sale_price' => isset($p['sale_price']) ? $p['sale_price'] : 0,
                'effective_price' => $p['effective_price'],
                'formatted_price' => $p['formatted_price'],
                'variants' => []
            ];
        }
        
        $grouped[$targetGroupKey]['variants'][] = $p;
    }
    
    return array_values($grouped);
}

function render_product_card($item) {
    $variants = !empty($item['variants']) ? $item['variants'] : [$item];
    
    usort($variants, function($a, $b) {
        $labelA = !empty($a['variant_label']) ? $a['variant_label'] : (!empty($a['pages']) ? $a['pages'] : $a['name']);
        $labelB = !empty($b['variant_label']) ? $b['variant_label'] : (!empty($b['pages']) ? $b['pages'] : $b['name']);
        
        preg_match('/(\d+)/', $labelA, $matchA);
        preg_match('/(\d+)/', $labelB, $matchB);
        
        $valA = isset($matchA[1]) ? (int)$matchA[1] : 0;
        $valB = isset($matchB[1]) ? (int)$matchB[1] : 0;
        
        if ($valA === $valB) {
            return strcmp($labelA, $labelB);
        }
        return $valA <=> $valB;
    });

    $activeVariant = $variants[0];
    
    $rawBrand = !empty($item['brand_name']) ? $item['brand_name'] : (!empty($activeVariant['brand_name']) ? $activeVariant['brand_name'] : 'Raj Priya');
    $brandName = $rawBrand;
    $productTitle = !empty($activeVariant['name']) ? $activeVariant['name'] : (!empty($item['name']) ? $item['name'] : (!empty($item['product_name']) ? $item['product_name'] : ''));
    $baseName = $productTitle;
    
    $activeId = $activeVariant['id'];
    $activeImage = !empty($item['image']) ? $item['image'] : 'uploads/products/prod_power.webp';
    
    // Wholesale price is effective price
    $activeWholesalePrice = get_product_effective_price($activeVariant);
    
    // Seller / Original MRP price
    $activeSellerPrice = null;
    if (!empty($activeVariant['mrp']) && $activeVariant['mrp'] > $activeWholesalePrice) {
        $activeSellerPrice = $activeVariant['mrp'];
    } elseif (!empty($activeVariant['price']) && $activeVariant['price'] > $activeWholesalePrice) {
        $activeSellerPrice = $activeVariant['price'];
    } else {
        $activeSellerPrice = round($activeWholesalePrice * 1.25, 2);
    }
    
    $rawDesc = !empty($item['description']) ? $item['description'] : (!empty($activeVariant['description']) ? $activeVariant['description'] : 'High quality paper notebook crafted for durability.');
    $cleanDesc = preg_replace('/Varieties:\s*[^|]+\|?\s*/i', '', $rawDesc);
    $cleanDesc = trim(preg_replace('/\s+/', ' ', strip_tags($cleanDesc)));
    if (empty($cleanDesc)) {
        $cleanDesc = 'High quality paper notebook crafted for durability and performance.';
    }
    $shortDesc = mb_strimwidth($cleanDesc, 0, 110, '...');

    $activeStock = isset($activeVariant['stock']) ? (int)$activeVariant['stock'] : 0;
    $stdQty = get_product_pieces_per_bundle($activeId, '', $activeVariant);
    $availableBundles = isset($activeVariant['bundle_stock']) ? (int)$activeVariant['bundle_stock'] : 0;
    $activeGsm = !empty($activeVariant['gsm']) ? (int)$activeVariant['gsm'] : 70;

    $sizeVal = !empty($activeVariant['size']) ? $activeVariant['size'] : (!empty($item['size']) ? $item['size'] : '18 × 24 cm (Standard)');

    // Group variants by UNIQUE page count label
    $pageMap = [];
    $uniquePageCounts = [];

    foreach ($variants as $idx => $v) {
        $pLabel = format_variant_page_label($v);
        if (!isset($pageMap[$pLabel])) {
            $pageMap[$pLabel] = [
                'label' => $pLabel,
                'default_variant_id' => $v['id'],
                'varieties' => []
            ];
            $uniquePageCounts[] = $pLabel;
        }
        
        $vStock = isset($v['stock']) ? (int)$v['stock'] : 0;
        $vBundles = isset($v['bundle_stock']) ? (int)$v['bundle_stock'] : 0;
        $vGsm = !empty($v['gsm']) ? (int)$v['gsm'] : 70;
        $vRawBrand = !empty($v['brand_name']) ? $v['brand_name'] : $rawBrand;
        $vSize = !empty($v['size']) ? $v['size'] : $sizeVal;
        $vImage = $activeImage;
        
        $varList = get_product_variety_list($v);
        if (empty($varList) && empty($v['varieties_managed'])) {
            $varList = ['Standard'];
        }
        $variantIdsByLabel = [];
        $variantRowsByLabel = [];
        $variantRowsStmt = getDB()->prepare("
            SELECT id, product_code, variety, variety_display, bundle_qty, bundle_stock, stock,
                   mrp, price, sale_price, gst_rate
            FROM product_variants
            WHERE product_id = ?
            ORDER BY id
        ");
        $variantRowsStmt->execute([(int)$v['id']]);
        foreach ($variantRowsStmt->fetchAll(PDO::FETCH_ASSOC) as $variantRow) {
            $variantLabel = trim((string)($variantRow['variety_display'] ?: $variantRow['variety']));
            $variantIdsByLabel[strtolower($variantLabel)] = (int)$variantRow['id'];
            $variantIdsByLabel[strtolower((string)$variantRow['variety'])] = (int)$variantRow['id'];
            $variantRowsByLabel[strtolower($variantLabel)] = $variantRow;
            $variantRowsByLabel[strtolower((string)$variantRow['variety'])] = $variantRow;
        }

        foreach ($varList as $varName) {
            $varName = trim($varName);
            if ($varName === '') continue;

            $variantId = $variantIdsByLabel[strtolower($varName)]
                ?? $variantIdsByLabel[strtolower(get_variant_code_for_variety($varName))]
                ?? 0;
            $variantRow = $variantRowsByLabel[strtolower($varName)]
                ?? $variantRowsByLabel[strtolower(get_variant_code_for_variety($varName))]
                ?? null;
            $variantStock = $variantRow
                ? max(0, (int)$variantRow['stock'])
                : $vStock;
            $variantBundles = $variantRow
                ? max(0, (int)$variantRow['bundle_stock'])
                : $vBundles;
            $vStdQty = get_product_pieces_per_bundle($v['id'], $varName, $v, $variantId);
            $vWholesalePrice = get_product_single_piece_price($v, $varName, $variantId);
            $vMrp = $variantRow['mrp'] ?? $v['mrp'] ?? null;
            $vSellerPrice = (!empty($vMrp) && $vMrp > $vWholesalePrice)
                ? $vMrp
                : ((!empty($v['price']) && $v['price'] > $vWholesalePrice) ? $v['price'] : round($vWholesalePrice * 1.25, 2));

            if (!isset($pageMap[$pLabel]['varieties'][$varName]) || ($vStock > 0 && $pageMap[$pLabel]['varieties'][$varName]['stock'] <= 0)) {
                $pageMap[$pLabel]['varieties'][$varName] = [
                    'id' => $v['id'],
                    'variety_id' => $variantId,
                    'label' => $pLabel,
                    'variety' => $varName,
                    'name' => $v['name'],
                    'brand' => $vRawBrand,
                    'mrp' => $vMrp,
                    'wholesale_price' => format_price($vWholesalePrice),
                    'seller_price' => format_price($vSellerPrice),
                    'stock' => $variantStock,
                    'std_qty' => $vStdQty,
                    'bundles' => $variantBundles,
                    'gsm' => $vGsm,
                    'size' => $vSize,
                    'image' => $vImage
                ];
            }
        }
    }

    // Intelligently default active page count and variety:
    // 1. Prefer variant with available bundles (bundles > 0)
    // 2. Fall back to variant with loose stock (stock > 0)
    // 3. Fall back to first available variant
    $activePageLabel = format_variant_page_label($activeVariant);
    $activeVarName = 'Standard';

    $chosenPage = null;
    $chosenVar = null;

    // First scan for bundle stock
    foreach ($uniquePageCounts as $pLbl) {
        if (!empty($pageMap[$pLbl]['varieties'])) {
            foreach ($pageMap[$pLbl]['varieties'] as $vN => $vD) {
                if (($vD['bundles'] ?? 0) > 0) {
                    $chosenPage = $pLbl;
                    $chosenVar = $vN;
                    break 2;
                }
            }
        }
    }

    // If no bundle stock, scan for loose piece stock
    if ($chosenPage === null) {
        foreach ($uniquePageCounts as $pLbl) {
            if (!empty($pageMap[$pLbl]['varieties'])) {
                foreach ($pageMap[$pLbl]['varieties'] as $vN => $vD) {
                    if (($vD['stock'] ?? 0) > 0) {
                        $chosenPage = $pLbl;
                        $chosenVar = $vN;
                        break 2;
                    }
                }
            }
        }
    }

    if ($chosenPage !== null && $chosenVar !== null) {
        $activePageLabel = $chosenPage;
        $activeVarName = $chosenVar;
    } else {
        if (!isset($pageMap[$activePageLabel]) && !empty($uniquePageCounts)) {
            $activePageLabel = $uniquePageCounts[0];
        }
        $activeVarieties = array_keys($pageMap[$activePageLabel]['varieties'] ?? []);
        $activeVarName = !empty($activeVarieties) ? $activeVarieties[0] : 'Standard';
    }

    $activeVarieties = array_keys($pageMap[$activePageLabel]['varieties'] ?? []);
    $activeVarData = $pageMap[$activePageLabel]['varieties'][$activeVarName] ?? null;

    if ($activeVarData) {
        $activeId = $activeVarData['id'];
        $activeStock = $activeVarData['stock'];
        $availableBundles = $activeVarData['bundles'];
        $stdQty = $activeVarData['std_qty'];
        $activeGsm = $activeVarData['gsm'];
        $sizeVal = $activeVarData['size'];
        $activeImage = $activeVarData['image'];
        $brandName = $activeVarData['brand'];
        $activeWholesalePrice = get_product_effective_price($activeVariant, $activeVarName);
        $activeMrp = $activeVarData['mrp'] ?? $activeVariant['mrp'] ?? null;
        $activeSellerPrice = (!empty($activeMrp) && $activeMrp > $activeWholesalePrice)
            ? $activeMrp
            : ((!empty($activeVariant['price']) && $activeVariant['price'] > $activeWholesalePrice)
                ? $activeVariant['price']
                : round($activeWholesalePrice * 1.25, 2));
        if (!empty($activeVarData['wholesale_price'])) {
            $activeWholesalePriceStr = $activeVarData['wholesale_price'];
        }
        if (!empty($activeVarData['name'])) {
            $productTitle = $activeVarData['name'];
            $baseName = $productTitle;
        }
    }

    ob_start();
    ?>
    <div class="product-card d-flex flex-column h-100 position-relative bg-white border rounded-3 p-3.5 shadow-sm js-product-card" id="card-prod-<?php echo $activeId; ?>" data-active-id="<?php echo $activeId; ?>" data-active-page="<?php echo htmlspecialchars($activePageLabel); ?>" data-ppb="<?php echo $stdQty; ?>" data-page-map="<?php echo htmlspecialchars(json_encode($pageMap), ENT_QUOTES, 'UTF-8'); ?>">

      <!-- 1. Product Image -->
      <a href="product-details.php?id=<?php echo $activeId; ?>" class="product-img-box mb-3 js-product-link text-center d-block position-relative bg-light-subtle rounded p-2 overflow-hidden" title="<?php echo htmlspecialchars($baseName); ?>">
        <img src="<?php echo htmlspecialchars($activeImage); ?>" alt="<?php echo htmlspecialchars($baseName); ?>" class="js-product-img img-fluid" style="max-height: 220px; width: 100%; object-fit: contain; transition: transform 0.3s ease;">
      </a>

      <!-- Product Information Body -->
      <div class="product-card-body d-flex flex-column justify-content-between flex-grow-1">
        <div>
          <!-- 2. Brand Name -->
          <div class="product-brand-wrap mb-1">
            <span class="badge bg-gold-subtle text-navy border border-warning-subtle fw-bold text-uppercase px-2.5 py-1 js-product-brand" style="font-size: 0.7rem; letter-spacing: 0.5px;">
              <i class="bi bi-patch-check-fill text-gold me-1"></i><?php echo htmlspecialchars($brandName); ?>
            </span>
          </div>

          <!-- 3. Product Name -->
          <div class="product-title-wrap mb-1.5">
            <a href="product-details.php?id=<?php echo $activeId; ?>" class="text-decoration-none js-product-link">
              <h5 class="product-title js-product-title mb-0 text-navy fw-bold fs-6" style="line-height: 1.35;" title="<?php echo htmlspecialchars($baseName); ?>"><?php echo htmlspecialchars($baseName); ?></h5>
            </a>
          </div>
          
          <!-- 4. Short Description -->
          <div class="product-desc-wrap mb-2.5">
            <p class="product-desc text-muted small mb-0 js-product-desc" style="font-size: 0.8rem; line-height: 1.4;">
              <?php echo htmlspecialchars($shortDesc); ?>
            </p>
          </div>

          <!-- 5. Variety / Size Selection -->
          <div class="product-variety-section mb-3">
            <div class="product-variety-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
              <span class="small fw-bold text-navy" style="font-size: 0.775rem;">Select Page Count:</span>
              <div class="d-flex align-items-center gap-1.5 flex-wrap">
                <span class="badge js-product-gsm-badge">GSM: <?php echo htmlspecialchars($activeGsm); ?> GSM</span>
                <span class="badge js-product-size-badge"><?php echo htmlspecialchars($sizeVal); ?></span>
              </div>
            </div>

            <!-- Page Count Buttons -->
            <?php if (!empty($uniquePageCounts)): ?>
              <div class="d-flex flex-wrap gap-2 js-variety-chips-wrap mb-2">
                <?php foreach ($uniquePageCounts as $pLabel): ?>
                  <button type="button" 
                          class="btn btn-sm variety-chip-btn js-card-variety-btn <?php echo $pLabel === $activePageLabel ? 'btn-navy text-white fw-bold active' : 'btn-outline-secondary'; ?>" 
                          data-page-label="<?php echo htmlspecialchars($pLabel); ?>"
                          onclick="selectCardPageCount(this, '<?php echo htmlspecialchars(addslashes($pLabel)); ?>')">
                    <?php echo htmlspecialchars($pLabel); ?>
                  </button>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <!-- Available Varieties Box Cards (Shown for active page count) -->
            <?php if (!empty($activeVarieties)): ?>
            <div class="product-varieties-drawer mt-2 p-2.5 bg-light rounded-3 border border-light-subtle js-card-varieties-drawer">
              <div class="small fw-bold text-navy mb-1.5 d-flex align-items-center justify-content-between" style="font-size: 0.725rem;">
                <span><i class="bi bi-journals text-gold me-1"></i>Available Varieties for <strong class="text-navy js-card-active-page-title"><?php echo htmlspecialchars($activePageLabel); ?></strong>:</span>
              </div>
              <div class="d-flex flex-wrap gap-2 js-card-variety-boxes">
                <?php 
                foreach ($activeVarieties as $vIdx => $vName): 
                  if (empty($vName)) continue;
                ?>
                  <button type="button" 
                          class="variety-box-card js-card-variety-box-btn <?php echo $vName === $activeVarName ? 'active' : ''; ?>"
                          data-variety="<?php echo htmlspecialchars($vName); ?>"
                          onclick="selectCardVarietyBox(this, this.getAttribute('data-variety'))">
                    <?php echo htmlspecialchars($vName); ?>
                  </button>
                <?php endforeach; ?>
              </div>
            </div>
            <?php else: ?>
            <div class="mt-2 p-2.5 bg-light rounded-3 border border-light-subtle small text-secondary">
              <i class="bi bi-info-circle text-gold me-1"></i>No varieties are currently available.
            </div>
            <?php endif; ?>
          </div>

          <!-- 6. Available Stock -->
          <div class="product-stock-section mb-2.5 p-2 bg-light rounded border border-light-subtle d-flex align-items-center justify-content-between">
            <span class="small fw-semibold text-secondary" style="font-size: 0.775rem;"><i class="bi bi-box-seam me-1 text-gold"></i>Available Stock:</span>
            <span class="badge <?php echo $activeStock > 0 ? 'bg-warning-subtle text-dark border border-warning-subtle' : 'bg-success-subtle text-success border border-success-subtle'; ?> fw-bold px-2.5 py-1 js-card-stock-display" style="font-size: 0.75rem;">
              <?php echo empty($activeVarieties) ? 'No varieties available' : ($activeStock > 0 ? 'In Stock (' . $activeStock . ' Pcs)' : 'Available for order'); ?>
            </span>
          </div>

          <!-- 7. Wholesale Information -->
          <div class="product-wholesale-info mb-3 small text-muted d-flex align-items-center gap-1.5" style="font-size: 0.75rem;">
            <i class="bi bi-info-circle text-navy"></i>
            <span>Wholesale Pack: <strong class="text-navy js-card-std-qty"><?php echo $stdQty; ?> Pieces / Bundle</strong></span>
          </div>

          <!-- 8 & 9. Pricing Box (Seller Price & Wholesale Price) -->
          <div class="pricing-box p-2.5 mb-3 bg-light-subtle border border-warning-subtle rounded-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
              <div class="text-muted small text-decoration-line-through" style="font-size: 0.75rem;">
                Seller Price: <span class="js-card-seller-price fw-semibold"><?php echo format_price($activeSellerPrice); ?></span>
              </div>
              <div class="fw-extrabold text-success fs-5 mb-0 d-flex align-items-center gap-1.5">
                <span class="text-navy small fw-bold" style="font-size: 0.8rem;">Wholesale Price:</span>
                <span class="js-card-wholesale-price text-success fw-bold"><?php echo format_price($activeWholesalePrice); ?></span>
              </div>
            </div>
            <span class="badge bg-gold text-navy fw-bold px-2 py-1 rounded-pill" style="font-size: 0.685rem;">
              <i class="bi bi-tags-fill me-1"></i>Wholesale Savings
            </span>
          </div>

          <!-- 10. Order Type Selection: [ Bundle ] [ Single Piece ] -->
          <?php $initialOrderType = ($availableBundles > 0 || $activeStock <= 0) ? 'Bundle' : 'Single Piece'; ?>
          <div class="order-type-section mb-3">
            <label class="form-label small fw-bold text-navy mb-1.5" style="font-size: 0.775rem;">Select Order Type:</label>
            <div class="btn-group w-100" role="group">
              <button type="button" 
                      class="btn btn-outline-navy fw-bold btn-sm py-2 <?php echo $initialOrderType === 'Bundle' ? 'active' : ''; ?> js-order-type-btn" 
                      data-order-type="Bundle" 
                      onclick="selectCardOrderType(this, 'Bundle')">
                <i class="bi bi-box-seam me-1"></i>Bundle
              </button>
              <button type="button" 
                      class="btn btn-outline-navy fw-bold btn-sm py-2 <?php echo $initialOrderType === 'Single Piece' ? 'active' : ''; ?> js-order-type-btn" 
                      data-order-type="Single Piece" 
                      onclick="selectCardOrderType(this, 'Single Piece')">
                <i class="bi bi-file-earmark-text me-1"></i>Single Piece
              </button>
            </div>
          </div>

          <!-- 11. Quantity Controls -->
          <div class="quantity-section mb-3">
            <!-- Bundle Quantity Panel -->
            <div class="js-qty-panel-bundle p-2.5 bg-light rounded border <?php echo $initialOrderType === 'Bundle' ? '' : 'd-none'; ?>">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="small fw-bold text-navy" style="font-size: 0.775rem;">Bundle Quantity:</span>
              </div>
              <div class="input-group input-group-sm quantity-input-group justify-content-center" style="max-width: 140px; margin: 0 auto;">
                <button type="button" class="btn btn-outline-navy px-3 fw-bold" onclick="adjustCardQty(this, -1, 'Bundle')">-</button>
                <input type="number" class="form-control text-center fw-bold js-bundle-qty-input" value="1" min="1" max="999999" oninput="syncCardFormQty(this, 'Bundle')" onchange="syncCardFormQty(this, 'Bundle')">
                <button type="button" class="btn btn-outline-navy px-3 fw-bold" onclick="adjustCardQty(this, 1, 'Bundle')">+</button>
              </div>

              <!-- Dynamic Bundle Quantity & Total Pieces Calculation Box -->
              <div class="bundle-calc-box mt-2 p-2 rounded bg-white border border-warning-subtle text-center">
                <div class="d-flex justify-content-between align-items-center small mb-1" style="font-size: 0.75rem;">
                  <span class="text-secondary">Pieces per Bundle: <strong class="text-navy js-card-ppb-display"><?php echo $stdQty; ?></strong></span>
                  <span class="text-secondary">Bundles Ordered: <strong class="text-navy js-card-bundles-display">1</strong></span>
                </div>
                <div class="fw-bold text-navy small py-1 px-2 rounded" style="background: #FCF8EF; border: 1px dashed #C89B3C; font-size: 0.8rem;">
                  Total Quantity: <span class="text-primary fw-extrabold js-card-total-pcs-display"><?php echo $stdQty; ?> Pieces</span>
                  <span class="text-muted fw-normal ms-1">(Total Bundles: <span class="js-card-total-bdl-display">1</span>)</span>
                </div>
                <div class="text-muted text-center mt-1 js-card-calc-formula" style="font-size: 0.68rem;">
                  Formula: <?php echo $stdQty; ?> Pcs &times; 1 Bundle = <?php echo $stdQty; ?> Pieces
                </div>
              </div>
            </div>

            <!-- Single Piece Quantity Panel (Hidden in Bundle Mode) -->
            <div class="js-qty-panel-piece p-2.5 bg-light rounded border <?php echo $initialOrderType === 'Single Piece' ? '' : 'd-none'; ?>">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="small fw-bold text-navy" style="font-size: 0.775rem;">Piece Quantity:</span>
                <span class="small text-muted" style="font-size: 0.725rem;">Available Pieces: <strong class="text-navy js-card-available-pieces"><?php echo $activeStock; ?></strong></span>
              </div>
              <div class="input-group input-group-sm quantity-input-group justify-content-center" style="max-width: 140px; margin: 0 auto;">
                <button type="button" class="btn btn-outline-navy px-3 fw-bold" onclick="adjustCardQty(this, -1, 'Single Piece')">-</button>
                <input type="number" class="form-control text-center fw-bold js-piece-qty-input" value="1" min="1" max="999999" oninput="syncCardFormQty(this, 'Single Piece')" onchange="syncCardFormQty(this, 'Single Piece')">
                <button type="button" class="btn btn-outline-navy px-3 fw-bold" onclick="adjustCardQty(this, 1, 'Single Piece')">+</button>
              </div>
            </div>
          </div>
        </div>

        <!-- 12. Large Centered Add to Cart Button -->
        <div class="product-card-actions-row pt-2 border-top mt-auto d-flex align-items-center justify-content-center text-center">
          <form action="cart-action.php" method="POST" class="add-to-cart-form m-0 js-card-cart-form w-100 d-flex justify-content-center">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="product_id" value="<?php echo $activeId; ?>" class="js-card-product-id">
            <input type="hidden" name="order_type" value="<?php echo htmlspecialchars($initialOrderType); ?>" class="js-card-order-type">
            <input type="hidden" name="quantity" value="1" class="js-card-qty-field">
            <input type="hidden" name="variant_id" value="<?php echo (int)($activeVarData['variety_id'] ?? 0); ?>" class="js-card-variety-id">
            <?php 
              $defaultInitPageLabel = format_variant_page_label($activeVariant);
              $defaultInitVariety = (!empty($activeVarName) && $activeVarName !== 'Standard') ? $activeVarName : ((!empty($activeVarieties[0]) && $activeVarieties[0] !== 'Standard') ? $activeVarieties[0] : '');
              $defaultInitRuling = $defaultInitPageLabel . ($defaultInitVariety ? ' - ' . $defaultInitVariety : '');
            ?>
            <input type="hidden" name="ruling_type" value="<?php echo htmlspecialchars($defaultInitRuling); ?>" class="js-card-ruling-type">
            
            <?php
              $canOrder = !empty($activeVarieties);
            ?>
            <button type="submit" 
                    class="btn btn-gold btn-lg w-100 fw-bold js-add-to-cart-btn d-inline-flex align-items-center justify-content-center gap-2" 
                    title="Add to Cart" 
                    <?php echo !$canOrder ? 'disabled' : ''; ?>>
              <i class="bi bi-cart-plus-fill fs-5"></i>
              <span><?php echo $canOrder ? 'ADD TO CART' : 'NO VARIETIES AVAILABLE'; ?></span>
            </button>
          </form>
        </div>

      </div>
    </div>
    <?php
    return ob_get_clean();
}

if (!function_exists('build_filter_url')) {
    function build_filter_url($param_to_remove, $value_to_remove) {
        $params = $_GET;
        if ($param_to_remove === 'category' || $param_to_remove === 'cat') {
            unset($params['category']);
            unset($params['cat']);
        } elseif (isset($params[$param_to_remove])) {
            if (is_array($params[$param_to_remove])) {
                $params[$param_to_remove] = array_values(array_diff($params[$param_to_remove], [$value_to_remove]));
                if (empty($params[$param_to_remove])) {
                    unset($params[$param_to_remove]);
                }
            } else {
                unset($params[$param_to_remove]);
            }
        }
        $query = http_build_query($params);
        return 'products.php' . ($query ? '?' . $query : '');
    }
}

if (!function_exists('build_pagination_url')) {
    function build_pagination_url($page_num) {
        $params = $_GET;
        $params['p'] = $page_num;
        return 'products.php?' . http_build_query($params);
    }
}

if (!function_exists('render_order_status_badge')) {
    function render_order_status_badge($status) {
        $status_clean = trim((string)$status);
        $st = strtolower($status_clean);
        
        switch ($st) {
            case 'pending':
                $style = 'background-color: #FFF3CD; color: #856404; border: 1px solid #FFEEBA;';
                break;
            case 'confirmed':
                $style = 'background-color: #CCE5FF; color: #004085; border: 1px solid #B8DAFF;';
                break;
            case 'processing':
                $style = 'background-color: #D1ECF1; color: #0C5460; border: 1px solid #BEE5EB;';
                break;
            case 'packed':
                $style = 'background-color: #E2D9F3; color: #381462; border: 1px solid #D0C2EC;';
                break;
            case 'shipped':
                $style = 'background-color: #D0F2F1; color: #005A5B; border: 1px solid #B2E5E4;';
                break;
            case 'delivered':
                $style = 'background-color: #D4EDDA; color: #155724; border: 1px solid #C3E6CB;';
                break;
            case 'cancelled':
                $style = 'background-color: #F8D7DA; color: #721C24; border: 1px solid #F5C6CB;';
                break;
            default:
                $style = 'background-color: #E2E3E5; color: #383D41; border: 1px solid #D6D8DB;';
                break;
        }
        
        return '<span class="badge fw-bold px-2.5 py-1.5" style="' . $style . ' font-size: 0.785rem; letter-spacing: 0.3px; display: inline-block;">' . htmlspecialchars($status_clean) . '</span>';
    }
}

/* ==========================================================================
   PARCEL QR CODE & PACKING MANAGEMENT SYSTEM FUNCTIONS
   ========================================================================== */

if (!function_exists('generate_parcel_number')) {
    function generate_parcel_number() {
        $db = getDB();
        $dateStr = date('Ymd');
        $prefix = "PCL-" . $dateStr . "-";
        
        $stmt = $db->prepare("SELECT parcel_number FROM order_parcels WHERE parcel_number LIKE ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $row = $stmt->fetch();
        
        if ($row && !empty($row['parcel_number'])) {
            $lastNum = (int)substr($row['parcel_number'], -4);
            $nextNum = $lastNum + 1;
        } else {
            $nextNum = 1;
        }
        return $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('get_order_parcels')) {
    function get_order_parcels($order_id) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM order_parcels WHERE order_id = ? ORDER BY id ASC");
        $stmt->execute([(int)$order_id]);
        $parcels = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($parcels as &$p) {
            $stmt_items = $db->prepare("
                SELECT opi.*, oi.product_name, oi.ruling_type, oi.product_code, oi.price, oi.quantity as total_ordered,
                       oi.pieces_per_bundle, oi.total_pieces, oi.page_count, oi.variety
                FROM order_parcel_items opi
                JOIN order_items oi ON opi.order_item_id = oi.id
                WHERE opi.parcel_id = ?
            ");
            $stmt_items->execute([$p['id']]);
            $p['items'] = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        }
        return $parcels;
    }
}

if (!function_exists('get_order_item_packing_summary')) {
    function get_order_item_packing_summary($order_id) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->execute([(int)$order_id]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as &$itm) {
            $calc = calculate_order_item_packing($itm);
            $itm['ordered_pieces'] = $calc['ordered_pieces'];
            $itm['standard_bundle_size'] = $calc['standard_bundle_size'];
            $itm['full_bundles'] = $calc['full_bundles'];
            $itm['remaining_pieces'] = $calc['remaining_pieces'];
            $itm['partial_bundles'] = $calc['partial_bundles'];
            $itm['packed_units'] = $calc['packed_units'];

            $stmt_packed = $db->prepare("SELECT SUM(quantity) as packed_qty FROM order_parcel_items WHERE order_item_id = ?");
            $stmt_packed->execute([$itm['id']]);
            $packed = $stmt_packed->fetch(PDO::FETCH_ASSOC);
            $itm['packed_quantity'] = (int)($packed['packed_qty'] ?? 0);
            $itm['remaining_quantity'] = max(0, $itm['quantity'] - $itm['packed_quantity']);
        }
        return $items;
    }
}

if (!function_exists('create_order_parcel')) {
    function create_order_parcel($order_id, $items_data, $parcel_weight = 0, $box_dimensions = '', $remarks = '') {
        $db = getDB();
        
        $stmt_ord = $db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt_ord->execute([(int)$order_id]);
        $order = $stmt_ord->fetch(PDO::FETCH_ASSOC);
        if (!$order) return false;
        
        $parcel_number = generate_parcel_number();
        
        // Build robust QR tracking URL using centralized helper
        $qr_url = get_parcel_tracking_url($parcel_number);
        
        $dispatch_date = !empty($order['dispatch_date']) ? $order['dispatch_date'] : (!empty($order['transport_date']) ? $order['transport_date'] : null);

        $stmt_p = $db->prepare("
            INSERT INTO order_parcels (
                parcel_number, order_id, parcel_weight, box_dimensions, 
                transport_name, lr_number, transport_from, transport_to, 
                vehicle_number, transport_contact, dispatch_date, remarks, qr_data
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt_p->execute([
            $parcel_number,
            (int)$order_id,
            (float)$parcel_weight,
            $box_dimensions,
            $order['transport_name'] ?? '',
            $order['transport_lr_number'] ?? '',
            $order['transport_from'] ?? '',
            $order['transport_to'] ?? '',
            $order['transport_vehicle_number'] ?? '',
            $order['transport_contact'] ?? '',
            $dispatch_date,
            $remarks,
            $qr_url
        ]);
        $parcel_id = $db->lastInsertId();
        
        $stmt_pi = $db->prepare("INSERT INTO order_parcel_items (parcel_id, order_item_id, quantity) VALUES (?, ?, ?)");
        $has_packed_item = false;
        foreach ($items_data as $order_item_id => $qty) {
            $qty = (int)$qty;
            if ($qty > 0) {
                $stmt_pi->execute([$parcel_id, (int)$order_item_id, $qty]);
                $has_packed_item = true;
            }
        }
        
        if (!$has_packed_item) {
            // Delete empty parcel
            $db->prepare("DELETE FROM order_parcels WHERE id = ?")->execute([$parcel_id]);
            return false;
        }
        
        // Auto update order status
        $summary = get_order_item_packing_summary($order_id);
        $total_needed = 0;
        $total_packed = 0;
        foreach ($summary as $s) {
            $total_needed += $s['quantity'];
            $total_packed += $s['packed_quantity'];
        }
        
        if ($total_packed >= $total_needed && $total_needed > 0) {
            $db->prepare("UPDATE orders SET order_status = 'Packed' WHERE id = ?")->execute([$order_id]);
        } elseif ($total_packed > 0) {
            $db->prepare("UPDATE orders SET order_status = 'Processing' WHERE id = ?")->execute([$order_id]);
        }
        
        return $parcel_id;
    }
}

if (!function_exists('delete_order_parcel')) {
    function delete_order_parcel($parcel_id) {
        $db = getDB();
        $stmt = $db->prepare("SELECT order_id FROM order_parcels WHERE id = ?");
        $stmt->execute([(int)$parcel_id]);
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) return false;
        
        $order_id = $p['order_id'];
        
        $db->prepare("DELETE FROM order_parcels WHERE id = ?")->execute([(int)$parcel_id]);
        
        // Re-evaluate order status
        $summary = get_order_item_packing_summary($order_id);
        $total_needed = 0;
        $total_packed = 0;
        foreach ($summary as $s) {
            $total_needed += $s['quantity'];
            $total_packed += $s['packed_quantity'];
        }
        
        if ($total_packed >= $total_needed && $total_needed > 0) {
            $db->prepare("UPDATE orders SET order_status = 'Packed' WHERE id = ?")->execute([$order_id]);
        } elseif ($total_packed > 0) {
            $db->prepare("UPDATE orders SET order_status = 'Processing' WHERE id = ?")->execute([$order_id]);
        } else {
            $db->prepare("UPDATE orders SET order_status = 'Confirmed' WHERE id = ?")->execute([$order_id]);
        }
        
        return true;
    }
}

if (!function_exists('get_parcel_by_number')) {
    function get_parcel_by_number($parcel_identifier) {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT p.*, o.order_number, o.customer_name, o.customer_phone, o.customer_email, 
                   o.shipping_address, o.city, o.state, o.pincode, o.landmark, o.created_at as order_date,
                   o.transport_date as order_transport_date, o.dispatch_date as order_dispatch_date,
                   o.expected_parcels, o.actual_parcels
            FROM order_parcels p 
            JOIN orders o ON p.order_id = o.id 
            WHERE p.parcel_number = ? OR p.id = ?
        ");
        $stmt->execute([$parcel_identifier, is_numeric($parcel_identifier) ? (int)$parcel_identifier : 0]);
        $parcel = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($parcel) {
            $stmt_items = $db->prepare("
                SELECT opi.*, oi.product_name, oi.ruling_type, oi.product_code, oi.price, oi.quantity as total_ordered,
                       oi.pieces_per_bundle, oi.total_pieces, oi.page_count, oi.variety, oi.order_type,
                       p.std_qty, p.pages as prod_pages
                FROM order_parcel_items opi
                JOIN order_items oi ON opi.order_item_id = oi.id
                LEFT JOIN products p ON oi.product_id = p.id
                WHERE opi.parcel_id = ?
            ");
            $stmt_items->execute([$parcel['id']]);
            $raw_items = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
            
            // Get total parcels count & box index for this order
            $stmt_all = $db->prepare("SELECT id FROM order_parcels WHERE order_id = ? ORDER BY id ASC");
            $stmt_all->execute([$parcel['order_id']]);
            $all_pids = $stmt_all->fetchAll(PDO::FETCH_COLUMN);
            $actualParcels = !empty($parcel['actual_parcels']) && (int)$parcel['actual_parcels'] > 0 ? (int)$parcel['actual_parcels'] : count($all_pids);
            $parcel['total_parcels_count'] = max(1, $actualParcels);
            $parcel['box_index'] = (array_search($parcel['id'], $all_pids) !== false) ? (array_search($parcel['id'], $all_pids) + 1) : 1;

            $parcel['total_products'] = count($raw_items);
            $total_qty = 0;
            $total_parcel_pieces = 0;

            foreach ($raw_items as &$itm) {
                $vInfo = parse_variety_info($itm['ruling_type'] ?? '');
                $itm['parsed_variety'] = !empty($itm['variety']) ? $itm['variety'] : (!empty($vInfo['variety']) ? $vInfo['variety'] : 'Standard');
                $itm['parsed_pages'] = !empty($itm['page_count']) ? ($itm['page_count'] . ' Pages') : (!empty($vInfo['page_count']) ? $vInfo['page_count'] : (!empty($itm['prod_pages']) ? ($itm['prod_pages'] . ' Pages') : '192 Pages'));
                
                $ppb = !empty($itm['pieces_per_bundle']) && (int)$itm['pieces_per_bundle'] > 0 ? (int)$itm['pieces_per_bundle'] : (!empty($itm['std_qty']) ? (int)$itm['std_qty'] : 1);
                if ($ppb <= 1 && preg_match('/Bundle\s*-\s*(\d+)\s*Pcs/i', $itm['ruling_type'] ?? '', $m)) {
                    $ppb = (int)$m[1];
                }
                $itm['pieces_per_bundle'] = max(1, $ppb);
                $itm['bundles_ordered'] = (int)$itm['total_ordered'];
                
                $isBundle = (stripos($itm['order_type'] ?? '', 'Bundle') !== false) || (stripos($itm['ruling_type'] ?? '', 'Bundle') !== false);
                if ($isBundle) {
                    $itm['item_total_pieces'] = (int)$itm['pieces_per_bundle'] * (int)$itm['bundles_ordered'];
                    $itm['parcel_total_pieces'] = (int)$itm['pieces_per_bundle'] * (int)$itm['quantity'];
                } else {
                    $itm['item_total_pieces'] = (int)$itm['bundles_ordered'];
                    $itm['parcel_total_pieces'] = (int)$itm['quantity'];
                }
                $total_qty += (int)$itm['quantity'];
                $total_parcel_pieces += (int)$itm['parcel_total_pieces'];
            }
            unset($itm);
            $parcel['items'] = $raw_items;
            $parcel['total_quantity'] = $total_qty;
            $parcel['total_pieces_in_parcel'] = $total_parcel_pieces;
        }
        return $parcel;
    }
}

if (!function_exists('get_app_base_url')) {
    /**
     * Centralized Base URL Resolver.
     * Works seamlessly across:
     * 1. Production domain (configured in settings 'app_url')
     * 2. Environment variable (APP_URL)
     * 3. Localhost / XAMPP development environment
     * 4. LAN IP for mobile phone camera QR testing
     */
    function get_app_base_url() {
        // 1. Database Setting: 'app_url' (Configurable in Admin Settings)
        $dbAppUrl = get_setting('app_url', '');
        if (!empty($dbAppUrl)) {
            return rtrim(trim($dbAppUrl), '/');
        }

        // 2. Defined Constant
        if (defined('APP_URL') && !empty(constant('APP_URL'))) {
            return rtrim(trim(constant('APP_URL')), '/');
        }

        // 3. Environment Variable
        $envAppUrl = getenv('APP_URL');
        if (!empty($envAppUrl)) {
            return rtrim(trim($envAppUrl), '/');
        }

        // 4. Dynamic Server Detection
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        $protocol = $isHttps ? "https" : "http";

        $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

        // Detect project subfolder relative to document root or folder name
        $projDir = str_replace('\\', '/', realpath(dirname(__DIR__)));
        $subFolder = '/' . basename($projDir); // Defaults to '/rajpriya'
        if (!empty($_SERVER['DOCUMENT_ROOT'])) {
            $docRoot = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
            if ($docRoot && str_starts_with($projDir, $docRoot)) {
                $subFolder = substr($projDir, strlen($docRoot));
            }
        }
        $subFolder = '/' . trim($subFolder, '/');
        if ($subFolder === '/') {
            $subFolder = '';
        }

        return "{$protocol}://{$host}" . $subFolder;
    }
}

if (!function_exists('get_order_tracking_url')) {
    /**
     * Generate secure, clean tracking URL for an Order (for QR Code scanning)
     */
    function get_order_tracking_url($order_number_or_id) {
        $base = get_app_base_url();
        return "{$base}/track_order.php?ord=" . urlencode($order_number_or_id);
    }
}

if (!function_exists('get_parcel_tracking_url')) {
    /**
     * Generate secure, clean tracking URL for a Parcel Box (for QR Code scanning)
     */
    function get_parcel_tracking_url($parcel_number) {
        $base = get_app_base_url();
        return "{$base}/track_order.php?pcl=" . urlencode($parcel_number);
    }
}

if (!function_exists('get_customer_tracking_url')) {
    /**
     * Generate secure, clean tracking URL for Customer history (for QR Code scanning)
     */
    function get_customer_tracking_url($customer_code) {
        $base = get_app_base_url();
        return "{$base}/track_order.php?cust=" . urlencode($customer_code);
    }
}

if (!function_exists('get_order_full_details_for_tracking')) {
    /**
     * Retrieve complete verified order snapshot for public QR scan tracking
     * Supports matching by order_number, id, or parcel_number.
     * Returns full customer details, line items, price snapshots, and parcels.
     */
    function get_order_full_details_for_tracking($identifier) {
        $db = getDB();
        $identifier = trim((string)$identifier);
        if (empty($identifier)) {
            return null;
        }

        $order = null;

        // 1. Check if identifier is a parcel number
        if (str_starts_with(strtoupper($identifier), 'PCL-')) {
            $stmt_pcl = $db->prepare("SELECT order_id FROM order_parcels WHERE parcel_number = ? LIMIT 1");
            $stmt_pcl->execute([$identifier]);
            $ordId = $stmt_pcl->fetchColumn();
            if ($ordId) {
                $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
                $stmt->execute([(int)$ordId]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }

        // 2. Search by order_number
        if (!$order) {
            $stmt = $db->prepare("SELECT * FROM orders WHERE order_number = ? LIMIT 1");
            $stmt->execute([$identifier]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 3. Search by numeric order ID
        if (!$order && is_numeric($identifier)) {
            $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$identifier]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$order) {
            return null;
        }

        // Load order items
        $stmt_items = $db->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
        $stmt_items->execute([(int)$order['id']]);
        $order['items'] = $stmt_items->fetchAll(PDO::FETCH_ASSOC);

        // Load parcels for this order
        $stmt_pcls = $db->prepare("SELECT * FROM order_parcels WHERE order_id = ? ORDER BY id ASC");
        $stmt_pcls->execute([(int)$order['id']]);
        $parcels = $stmt_pcls->fetchAll(PDO::FETCH_ASSOC);

        foreach ($parcels as &$pcl) {
            $stmt_pi = $db->prepare("
                SELECT pi.*, oi.product_name, oi.ruling_type, oi.product_code 
                FROM order_parcel_items pi
                JOIN order_items oi ON pi.order_item_id = oi.id
                WHERE pi.parcel_id = ?
            ");
            $stmt_pi->execute([$pcl['id']]);
            $pcl['items'] = $stmt_pi->fetchAll(PDO::FETCH_ASSOC);
        }
        $order['parcels'] = $parcels;

        return $order;
    }
}

if (!function_exists('generate_qr_code_image_url')) {
    function generate_qr_code_image_url($data, $size = 200) {
        return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&margin=6&data=" . urlencode($data);
    }
}

// =========================================================================
// CUSTOMER & ORDER HISTORY SYSTEM HELPERS
// =========================================================================

if (!function_exists('get_customer_qr_image_url')) {
    /**
     * Generate customer QR code image URL encoding verified tracking link
     */
    function get_customer_qr_image_url($customer_code, $size = 200) {
        $trackingUrl = get_customer_tracking_url($customer_code);
        return generate_qr_code_image_url($trackingUrl, $size);
    }
}

if (!function_exists('get_valid_customer_types')) {
    /**
     * Return list of permitted Customer Types
     */
    function get_valid_customer_types() {
        return ['Wholesaler', 'Distributor', 'Retailer', 'Other'];
    }
}

if (!function_exists('is_valid_customer_type')) {
    /**
     * Validate Customer Type against allowed options
     */
    function is_valid_customer_type($type) {
        if (!is_string($type)) return false;
        return in_array(trim($type), get_valid_customer_types(), true);
    }
}

if (!function_exists('get_customer_type_short_code')) {
    /**
     * Return 3-letter Customer Type short code:
     * Wholesaler  => WHO
     * Distributor => DIS
     * Retailer    => RET
     * Other       => OTH
     */
    function get_customer_type_short_code($customer_type) {
        $clean = trim((string)$customer_type);
        $map = [
            'Wholesaler'  => 'WHO',
            'Distributor' => 'DIS',
            'Retailer'    => 'RET',
            'Other'       => 'OTH',
        ];
        foreach ($map as $key => $code) {
            if (strcasecmp($clean, $key) === 0) {
                return $code;
            }
        }
        return 'OTH';
    }
}

if (!function_exists('generate_next_customer_code')) {
    /**
     * Generate unique Customer ID formatted as:
     * [First 3 letters of District]-[First 3 letters of Customer Name]-[Customer Type Short Code]-[Sequential Number]
     *
     * Examples:
     * - District: Madurai, Name: Babu Husain, Type: Wholesaler -> MAD-BAB-WHO-0001
     * - District: Tirunelveli, Name: Rajesh, Type: Distributor -> TIR-RAJ-DIS-0002
     * - District: Thoothukudi, Name: Kumar, Type: Retailer     -> THO-KUM-RET-0003
     *
     * Rules:
     * - District code: First 3 alphabetic characters of district (or city fallback), uppercase, spaces/special chars removed.
     * - Customer name code: First 3 alphabetic characters of customer name, uppercase, spaces/special chars removed.
     * - Customer type short code: WHO, DIS, RET, OTH.
     * - Sequential number: 4-digit zero-padded number (0001, 0002, ...).
     * - Concurrency-safe via MySQL GET_LOCK to prevent duplicate IDs.
     */
    function generate_next_customer_code($district_or_city = '', $customer_name = '', $customer_type = 'Other') {
        $db = getDB();

        // 1. Extract District Code (first 3 alphabetic letters uppercase)
        $cleanDist = trim((string)$district_or_city);
        $alphaDist = preg_replace('/[^a-zA-Z]/', '', $cleanDist);
        if (strlen($alphaDist) >= 3) {
            $districtCode = strtoupper(substr($alphaDist, 0, 3));
        } elseif (strlen($alphaDist) > 0) {
            $districtCode = str_pad(strtoupper($alphaDist), 3, 'X');
        } else {
            $districtCode = 'CHE'; // Default fallback (e.g. Chennai)
        }

        // 2. Extract Customer Name Code (first 3 alphabetic letters uppercase, removing spaces and special characters)
        $cleanName = trim((string)$customer_name);
        $alphaName = preg_replace('/[^a-zA-Z]/', '', $cleanName);
        if (strlen($alphaName) >= 3) {
            $nameCode = strtoupper(substr($alphaName, 0, 3));
        } elseif (strlen($alphaName) > 0) {
            $nameCode = str_pad(strtoupper($alphaName), 3, 'X');
        } else {
            $nameCode = 'CUS';
        }

        // 3. Customer Type Short Code (WHO, DIS, RET, OTH)
        $typeCode = get_customer_type_short_code($customer_type);

        $prefix = "{$districtCode}-{$nameCode}-{$typeCode}-";

        // 4. Concurrency-safe lock using MySQL GET_LOCK
        $lockAcquired = false;
        try {
            $stmt_lock = $db->query("SELECT GET_LOCK('customer_code_lock', 10)");
            $lockAcquired = ($stmt_lock && (int)$stmt_lock->fetchColumn() === 1);
        } catch (Exception $e) {
            // Lock failure fallback
        }

        try {
            // Find highest existing sequential suffix across users (both current and historical) and orders
            $stmt_u = $db->query("SELECT customer_code FROM users WHERE customer_code IS NOT NULL AND customer_code != ''");
            $codes_u = $stmt_u->fetchAll(PDO::FETCH_COLUMN);

            $stmt_prev = $db->query("SELECT previous_customer_codes FROM users WHERE previous_customer_codes IS NOT NULL AND previous_customer_codes != ''");
            $codes_prev_raw = $stmt_prev->fetchAll(PDO::FETCH_COLUMN);
            $codes_prev = [];
            foreach ($codes_prev_raw as $praw) {
                foreach (explode(',', (string)$praw) as $item) {
                    $item = trim($item);
                    if ($item !== '') {
                        $codes_prev[] = $item;
                    }
                }
            }

            $stmt_o = $db->query("SELECT customer_code FROM orders WHERE customer_code IS NOT NULL AND customer_code != ''");
            $codes_o = $stmt_o->fetchAll(PDO::FETCH_COLUMN);

            $allCodes = array_merge($codes_u, $codes_prev, $codes_o);

            $maxNum = 0;
            foreach ($allCodes as $cd) {
                $cd = trim((string)$cd);
                // Matches standard suffix numbers at end of code, e.g. -0001, -001, -12
                if (preg_match('/-(\d+)$/', $cd, $m)) {
                    $maxNum = max($maxNum, (int)$m[1]);
                }
            }

            // Sequential numbering starting from 0001
            $nextNum = $maxNum + 1;
            if ($nextNum < 1) {
                $nextNum = 1;
            }

            // 5. Collision safeguard loop checking users, previous_customer_codes and orders
            while (true) {
                $candidate = $prefix . str_pad($nextNum, 4, '0', STR_PAD_LEFT);
                $stmt_check = $db->prepare("
                    SELECT (SELECT COUNT(*) FROM users WHERE customer_code = ?) 
                         + (SELECT COUNT(*) FROM orders WHERE customer_code = ?)
                ");
                $stmt_check->execute([$candidate, $candidate]);
                if ((int)$stmt_check->fetchColumn() === 0) {
                    break;
                }
                $nextNum++;
            }

            return $candidate;
        } finally {
            if ($lockAcquired) {
                try {
                    $db->query("SELECT RELEASE_LOCK('customer_code_lock')");
                } catch (Exception $e) {
                    // Ignore release errors
                }
            }
        }
    }
}

if (!function_exists('regenerate_customer_code')) {
    /**
     * Regenerate Customer ID for an existing customer based on updated name, district, or customer type.
     * Preserves historical orders, invoices, and old QR code resolution safely.
     */
    function regenerate_customer_code($user_id, $new_district = null, $new_name = null, $new_customer_type = null) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$user_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$customer) {
            return false;
        }

        $dist = ($new_district !== null && trim($new_district) !== '') 
            ? trim($new_district) 
            : (!empty($customer['district']) ? $customer['district'] : (!empty($customer['city']) ? $customer['city'] : 'CHE'));
        $name = ($new_name !== null && trim($new_name) !== '') ? trim($new_name) : $customer['name'];
        $type = ($new_customer_type !== null && is_valid_customer_type($new_customer_type)) 
            ? trim($new_customer_type) 
            : (!empty($customer['customer_type']) ? $customer['customer_type'] : 'Retailer');

        $newCode = generate_next_customer_code($dist, $name, $type);
        $newQr = generate_qr_code_image_url(get_customer_tracking_url($newCode), 200);

        // Keep old customer_code in previous_customer_codes for backwards compatibility
        $prevList = [];
        if (!empty($customer['previous_customer_codes'])) {
            $prevList = array_filter(array_map('trim', explode(',', $customer['previous_customer_codes'])));
        }
        if (!empty($customer['customer_code']) && !in_array($customer['customer_code'], $prevList, true)) {
            $prevList[] = $customer['customer_code'];
        }
        $prevStr = implode(',', $prevList);

        $stmt_up = $db->prepare("
            UPDATE users SET 
                customer_code = ?,
                qr_code = ?,
                previous_customer_codes = ?,
                name = ?,
                district = ?,
                customer_type = ?
            WHERE id = ?
        ");
        $stmt_up->execute([$newCode, $newQr, $prevStr, $name, $dist, $type, (int)$user_id]);

        return $newCode;
    }
}

if (!function_exists('get_or_create_customer_id')) {
    /**
     * Match existing customer or create a new unique Customer ID.
     * Guarantees repeat orders attach to the existing Customer ID without regenerating code.
     */
    function get_or_create_customer_id($customer_data, $user_id = null) {
        $db = getDB();

        $name = trim($customer_data['name'] ?? $customer_data['customer_name'] ?? '');
        $phone = trim($customer_data['phone'] ?? $customer_data['customer_phone'] ?? '');
        $email = !empty($customer_data['email']) ? trim($customer_data['email']) : (!empty($customer_data['customer_email']) ? trim($customer_data['customer_email']) : null);
        $address = $customer_data['address'] ?? $customer_data['shipping_address'] ?? '';
        $address_line2 = $customer_data['address_line2'] ?? '';
        $area = $customer_data['area'] ?? '';
        $city = $customer_data['city'] ?? '';
        $district = $customer_data['district'] ?? '';
        $state = !empty($customer_data['state']) ? $customer_data['state'] : 'Tamil Nadu';
        $pincode = $customer_data['pincode'] ?? '';
        $landmark = $customer_data['landmark'] ?? '';
        $gst_status = !empty($customer_data['gst_status']) ? trim($customer_data['gst_status']) : (!empty($customer_data['gst_number']) ? 'Registered' : 'Not Registered');
        $gst_number = ($gst_status === 'Registered' && !empty($customer_data['gst_number'])) ? strtoupper(trim($customer_data['gst_number'])) : null;
        $customer_type = !empty($customer_data['customer_type']) && is_valid_customer_type($customer_data['customer_type']) 
            ? trim($customer_data['customer_type']) 
            : 'Retailer';

        $matchedUser = null;

        // 1. If explicit user_id is provided (e.g. logged-in customer)
        if (!empty($user_id)) {
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([(int)$user_id]);
            $matchedUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 2. Match by phone number (clean mobile digits)
        if (!$matchedUser && !empty($phone)) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
            $stmt = $db->prepare("SELECT * FROM users WHERE phone = ? OR REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+91', '') = ? LIMIT 1");
            $stmt->execute([$phone, $cleanPhone]);
            $matchedUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // 3. Match by email if phone did not match
        if (!$matchedUser && !empty($email)) {
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $matchedUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        // Customer already exists: reuse existing Customer ID permanently
        if ($matchedUser) {
            $customerId = $matchedUser['id'];
            $customerCode = !empty($matchedUser['customer_code']) ? $matchedUser['customer_code'] : null;
            $custTypeToUse = !empty($matchedUser['customer_type']) ? $matchedUser['customer_type'] : $customer_type;

            if (empty($customerCode)) {
                $userDist = !empty($district) ? $district : (!empty($city) ? $city : (!empty($matchedUser['district']) ? $matchedUser['district'] : (!empty($matchedUser['city']) ? $matchedUser['city'] : 'CHE')));
                $customerCode = generate_next_customer_code($userDist, $matchedUser['name'] ?: $name, $custTypeToUse);
                $qrCode = generate_qr_code_image_url(get_customer_tracking_url($customerCode), 200);
                $stmt_up = $db->prepare("UPDATE users SET customer_code = ?, qr_code = ?, customer_type = ? WHERE id = ?");
                $stmt_up->execute([$customerCode, $qrCode, $custTypeToUse, $customerId]);
            } elseif (empty($matchedUser['qr_code'])) {
                $qrCode = generate_qr_code_image_url(get_customer_tracking_url($customerCode), 200);
                $stmt_up_qr = $db->prepare("UPDATE users SET qr_code = ? WHERE id = ?");
                $stmt_up_qr->execute([$qrCode, $customerId]);
            }

            // Update address details if user profile address was empty or outdated
            if (!empty($address) && (empty($matchedUser['address']) || empty($matchedUser['city']))) {
                $stmt_up_addr = $db->prepare("
                    UPDATE users SET 
                        address = COALESCE(NULLIF(address, ''), ?),
                        address_line2 = COALESCE(NULLIF(address_line2, ''), ?),
                        area = COALESCE(NULLIF(area, ''), ?),
                        city = COALESCE(NULLIF(city, ''), ?),
                        district = COALESCE(NULLIF(district, ''), ?),
                        state = COALESCE(NULLIF(state, ''), ?),
                        pincode = COALESCE(NULLIF(pincode, ''), ?),
                        landmark = COALESCE(NULLIF(landmark, ''), ?),
                        gst_status = ?,
                        gst_number = ?
                    WHERE id = ?
                ");
                $stmt_up_addr->execute([
                    $address, $address_line2, $area, $city, $district, $state, $pincode, $landmark, $gst_status, $gst_number, $customerId
                ]);
            }

            return [
                'user_id'       => (int)$customerId,
                'customer_code' => $customerCode,
                'customer_type' => $custTypeToUse,
                'is_new'        => false
            ];
        }

        // Brand New Customer: generate unique Customer ID and save profile
        $distToUse = !empty($district) ? $district : (!empty($city) ? $city : 'CHE');
        $customerCode = generate_next_customer_code($distToUse, $name, $customer_type);
        $qrCode = generate_qr_code_image_url(get_customer_tracking_url($customerCode), 200);
        $dummyPassword = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);

        $stmt_ins = $db->prepare("
            INSERT INTO users (
                customer_code, qr_code, name, phone, email, 
                address, address_line2, area, city, district, customer_type, state, pincode, landmark, gst_status, gst_number,
                password, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt_ins->execute([
            $customerCode, $qrCode, $name, $phone, $email,
            $address, $address_line2, $area, $city, $district, $customer_type, $state, $pincode, $landmark, $gst_status, $gst_number,
            $dummyPassword
        ]);
        $newUserId = (int)$db->lastInsertId();

        return [
            'user_id'       => $newUserId,
            'customer_code' => $customerCode,
            'customer_type' => $customer_type,
            'is_new'        => true
        ];
    }
}

if (!function_exists('get_customer_by_code')) {
    /**
     * Retrieve full customer profile and lifetime order statistics by Customer ID, User ID,
     * or any historical previous Customer ID.
     */
    function get_customer_by_code($identifier) {
        $db = getDB();
        $cleanId = trim((string)$identifier);
        if ($cleanId === '') {
            return null;
        }

        // 1. Direct match on current customer_code or primary id
        $stmt = $db->prepare("SELECT * FROM users WHERE customer_code = ? OR id = ? LIMIT 1");
        $stmt->execute([$cleanId, is_numeric($cleanId) ? (int)$cleanId : 0]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2. Match on historical previous_customer_codes
        if (!$customer) {
            $stmt_prev = $db->prepare("SELECT * FROM users WHERE FIND_IN_SET(?, REPLACE(previous_customer_codes, ' ', '')) > 0 LIMIT 1");
            $stmt_prev->execute([$cleanId]);
            $customer = $stmt_prev->fetch(PDO::FETCH_ASSOC);
        }

        // 3. Match from orders table (if customer ID belongs to a historical order)
        if (!$customer) {
            $stmt_ord = $db->prepare("SELECT u.* FROM users u JOIN orders o ON o.user_id = u.id WHERE o.customer_code = ? LIMIT 1");
            $stmt_ord->execute([$cleanId]);
            $customer = $stmt_ord->fetch(PDO::FETCH_ASSOC);
        }

        if (!$customer) {
            return null;
        }

        // Build list of all identifiers (current and previous) for order history lookup
        $allIdentWhere = ["user_id = ?"];
        $allIdentParams = [(int)$customer['id']];
        if (!empty($customer['customer_code'])) {
            $allIdentWhere[] = "customer_code = ?";
            $allIdentParams[] = $customer['customer_code'];
        }
        if (!empty($customer['previous_customer_codes'])) {
            foreach (explode(',', $customer['previous_customer_codes']) as $pc) {
                $pc = trim($pc);
                if ($pc !== '') {
                    $allIdentWhere[] = "customer_code = ?";
                    $allIdentParams[] = $pc;
                }
            }
        }

        // Compute lifetime statistics from orders
        $stmt_stats = $db->prepare("
            SELECT 
                COUNT(*) as total_orders,
                COALESCE(SUM(grand_total), 0) as total_spent,
                MAX(created_at) as latest_order_date
            FROM orders 
            WHERE " . implode(" OR ", $allIdentWhere) . "
        ");
        $stmt_stats->execute($allIdentParams);
        $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

        $customer['total_orders'] = (int)($stats['total_orders'] ?? 0);
        $customer['total_spent'] = (float)($stats['total_spent'] ?? 0);
        $customer['latest_order_date'] = $stats['latest_order_date'] ?? null;
        $customer['avg_order_value'] = $customer['total_orders'] > 0 
            ? round($customer['total_spent'] / $customer['total_orders'], 2) 
            : 0;

        // Auto-generate and cache QR code if not already saved
        if (empty($customer['qr_code']) && !empty($customer['customer_code'])) {
            $customer['qr_code'] = get_customer_qr_image_url($customer['customer_code'], 200);
            try {
                $db->prepare("UPDATE users SET qr_code = ? WHERE id = ?")->execute([$customer['qr_code'], $customer['id']]);
            } catch (Exception $e) {}
        }

        return $customer;
    }
}

if (!function_exists('get_customer_orders_history')) {
    /**
     * Fetch complete order history for a customer across both current Customer ID and historical IDs.
     */
    function get_customer_orders_history($customer_code_or_user_id) {
        $db = getDB();
        $customer = get_customer_by_code($customer_code_or_user_id);
        $userId = $customer ? (int)$customer['id'] : (is_numeric($customer_code_or_user_id) ? (int)$customer_code_or_user_id : 0);
        
        $whereParts = ["o.user_id = ?"];
        $params = [$userId];

        if ($customer) {
            if (!empty($customer['customer_code'])) {
                $whereParts[] = "o.customer_code = ?";
                $params[] = $customer['customer_code'];
            }
            if (!empty($customer['previous_customer_codes'])) {
                foreach (explode(',', $customer['previous_customer_codes']) as $pc) {
                    $pc = trim($pc);
                    if ($pc !== '') {
                        $whereParts[] = "o.customer_code = ?";
                        $params[] = $pc;
                    }
                }
            }
        } elseif (!empty($customer_code_or_user_id)) {
            $whereParts[] = "o.customer_code = ?";
            $params[] = (string)$customer_code_or_user_id;
        }

        $stmt = $db->prepare("
            SELECT o.* 
            FROM orders o 
            WHERE " . implode(" OR ", $whereParts) . " 
            ORDER BY o.id DESC
        ");
        $stmt->execute($params);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($orders as &$ord) {
            $stmt_items = $db->prepare("
                SELECT oi.* 
                FROM order_items oi 
                WHERE oi.order_id = ? 
                ORDER BY oi.id ASC
            ");
            $stmt_items->execute([(int)$ord['id']]);
            $ord['items'] = $stmt_items->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($ord);

        return $orders;
    }
}

// =========================================================================
// BUNDLE LABEL & BARCODE PRINTING SYSTEM (REFERENCE IMAGE IMPLEMENTATION)
// =========================================================================

if (!function_exists('get_code128_patterns')) {
    function get_code128_patterns() {
        return [
            0 => '212222', 1 => '222122', 2 => '222221', 3 => '121223', 4 => '121322',
            5 => '131222', 6 => '122213', 7 => '122312', 8 => '132212', 9 => '221213',
            10 => '221312', 11 => '231212', 12 => '112232', 13 => '122132', 14 => '122231',
            15 => '113222', 16 => '123122', 17 => '123221', 18 => '223211', 19 => '221132',
            20 => '221231', 21 => '213212', 22 => '223112', 23 => '312131', 24 => '311222',
            25 => '321122', 26 => '321221', 27 => '312212', 28 => '322112', 29 => '322211',
            30 => '212123', 31 => '212321', 32 => '232121', 33 => '111323', 34 => '131123',
            35 => '131321', 36 => '112313', 37 => '132113', 38 => '132311', 39 => '211313',
            40 => '231113', 41 => '231311', 42 => '112133', 43 => '112331', 44 => '132131',
            45 => '113123', 46 => '113321', 47 => '133121', 48 => '313121', 49 => '211331',
            50 => '231131', 51 => '213113', 52 => '213311', 53 => '213131', 54 => '311123',
            55 => '311321', 56 => '331121', 57 => '312113', 58 => '312311', 59 => '332111',
            60 => '314111', 61 => '221411', 62 => '431111', 63 => '111224', 64 => '111422',
            65 => '121124', 66 => '121421', 67 => '141122', 68 => '141221', 69 => '112214',
            70 => '112412', 71 => '122114', 72 => '122411', 73 => '142112', 74 => '142211',
            75 => '241211', 76 => '221114', 77 => '413111', 78 => '241112', 79 => '134111',
            80 => '111242', 81 => '121142', 82 => '121241', 83 => '114212', 84 => '124112',
            85 => '124211', 86 => '411212', 87 => '421112', 88 => '421211', 89 => '212141',
            90 => '214121', 91 => '412121', 92 => '111143', 93 => '111341', 94 => '131141',
            95 => '114113', 96 => '114311', 97 => '411113', 98 => '411311', 99 => '113141',
            100 => '114131', 101 => '311141', 102 => '411131', 103 => '211412', 104 => '211214',
            105 => '211232'
        ];
    }
}

if (!function_exists('generate_code128_svg')) {
    /**
     * Generate standard Code 128 (Subset B) Vector SVG
     * Zero external dependencies, razor-sharp on 300+ DPI physical sticker printers and PDFs
     */
    function generate_code128_svg($code, $height = 42) {
        $code = trim((string)$code);
        if ($code === '') $code = '0';

        $patterns = get_code128_patterns();
        $startB = $patterns[104];
        $stop   = '2331112';

        $checksum = 104;
        $sequence = [$startB];
        $len = strlen($code);

        for ($i = 0; $i < $len; $i++) {
            $val = ord($code[$i]) - 32;
            if ($val < 0 || $val > 94) {
                $val = 31; // fallback to '?'
            }
            $checksum += ($i + 1) * $val;
            $sequence[] = $patterns[$val];
        }

        $checkVal = $checksum % 103;
        $sequence[] = $patterns[$checkVal];
        $sequence[] = $stop;

        $totalModules = 0;
        $widths = [];
        foreach ($sequence as $pat) {
            $patLen = strlen($pat);
            for ($j = 0; $j < $patLen; $j++) {
                $w = (int)$pat[$j];
                $isBar = ($j % 2 === 0);
                $widths[] = [$w, $isBar];
                $totalModules += $w;
            }
        }

        $x = 0;
        $rects = [];
        foreach ($widths as $item) {
            list($w, $isBar) = $item;
            if ($isBar) {
                $rects[] = "<rect x=\"{$x}\" y=\"0\" width=\"{$w}\" height=\"{$height}\" fill=\"#000000\" />";
            }
            $x += $w;
        }

        return "<svg viewBox=\"0 0 {$totalModules} {$height}\" preserveAspectRatio=\"none\" style=\"width:100%; height:{$height}px; display:block;\">" . implode('', $rects) . "</svg>";
    }
}

if (!function_exists('format_bundle_label_product_title')) {
    /**
     * Formats the prominent dark blue product title bar exactly like the reference:
     * Example: POWER 192P RULED (SR)
     */
    function format_bundle_label_product_title($product_name, $pages, $variety, $variety_code = '') {
        $name = strtoupper(trim((string)$product_name));

        // Format pages as number with P (e.g. 192P, 60P, 24P)
        $cleanPages = preg_replace('/[^0-9]/', '', (string)$pages);
        $pagesSegment = !empty($cleanPages) ? ($cleanPages . 'P') : '192P';

        $varStr = strtoupper(trim((string)$variety));
        $codeStr = strtoupper(trim((string)$variety_code));

        $varietyMap = [
            'SR' => 'RULED',
            'UR' => 'UNRULED',
            'DR' => 'DOUBLE LINE',
            'FR' => 'FOUR LINE',
            'MP' => 'MATHS PLAIN',
            'MR' => 'MATHS RULED',
            'SQ' => 'SQUARE',
            'CR' => 'CHECK RULED',
            'OS' => 'ONE SIDE RULED',
            'BL' => 'BLANK'
        ];
        $nameToCode = [
            'RULED' => 'SR',
            'UNRULED' => 'UR',
            'DOUBLE LINE' => 'DR',
            'FOUR LINE' => 'FR',
            'MATHS PLAIN' => 'MP',
            'MATHS RULED' => 'MR',
            'CHECK RULED' => 'CR',
            'ONE SIDE RULED' => 'OS',
            'SQUARE' => 'SQ',
            'BLANK' => 'BL'
        ];

        if (isset($varietyMap[$varStr]) && empty($codeStr)) {
            $codeStr = $varStr;
            $varStr = $varietyMap[$codeStr];
        } elseif (isset($varietyMap[$codeStr]) && (empty($varStr) || $varStr === 'STANDARD')) {
            $varStr = $varietyMap[$codeStr];
        }

        if (empty($codeStr) && isset($nameToCode[$varStr])) {
            $codeStr = $nameToCode[$varStr];
        }

        if (preg_match('/^(.*?)\s*\(([A-Z0-9]+)\)$/i', $varStr, $m)) {
            $varStr = trim($m[1]);
            if (empty($codeStr)) {
                $codeStr = trim($m[2]);
            }
        }

        if (!empty($varStr)) {
            if (!empty($codeStr) && $codeStr !== $varStr) {
                $rulingPart = "{$varStr} ({$codeStr})";
            } else {
                $rulingPart = $varStr;
            }
        } else {
            $rulingPart = !empty($codeStr) ? "RULED ({$codeStr})" : 'RULED (SR)';
        }

        $cleanName = preg_replace('/\s*\d+\s*(pages?|p)\b/i', '', $name);
        return trim("{$cleanName} {$pagesSegment} {$rulingPart}");
    }
}

if (!function_exists('format_bundle_identifier')) {
    /**
     * Formats Bundle ID based on Product ID/Code and Bundle Quantity
     * Example: PWR-192-SR-400 or RP-OCT26-POW-192-SR-320
     */
    function format_bundle_identifier($product_code, $bundle_qty = 400) {
        $pCode = trim((string)$product_code);
        if (empty($pCode)) $pCode = 'RP-PROD-001';
        $bQty = (int)$bundle_qty > 0 ? (int)$bundle_qty : 400;
        return "{$pCode}-{$bQty}";
    }
}

if (!function_exists('format_bundle_barcode_value')) {
    /**
     * Deterministically generates barcode string
     * Example: PWR-192-SR with 400 -> PWR192SR40001
     */
    function format_bundle_barcode_value($product_code, $bundle_qty = 400, $seq = 1) {
        $pCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$product_code));
        if (empty($pCode)) $pCode = 'RP001';
        $bQty = (int)$bundle_qty > 0 ? (int)$bundle_qty : 400;
        $seqPad = str_pad((string)$seq, 2, '0', STR_PAD_LEFT);
        return "{$pCode}{$bQty}{$seqPad}";
    }
}

if (!function_exists('get_variant_shorthand_code')) {
    /**
     * Formats clean shorthand variant code matching the Raj Priya reference design
     * e.g. RP-OCT26-POW-192-SR -> PWR-192-SR
     */
    function get_variant_shorthand_code($product_code, $product_name = '', $pages = '', $variety_code = '') {
        $pCode = trim((string)$product_code);

        // Check if already in shorthand format (e.g. PWR-192-SR, G+-144-SR, APL-112-UR)
        if (preg_match('/^[A-Z0-9+]{2,6}-\d+-[A-Z0-9]+$/i', $pCode)) {
            return strtoupper($pCode);
        }

        // Pattern: RP-OCT26-POW-192-SR or RP-POW-192-SR -> PWR-192-SR
        if (preg_match('/(?:^|-)(?:POW|POWER|PWR)-(\d+)-([A-Z0-9]+)$/i', $pCode, $m)) {
            return "PWR-{$m[1]}-" . strtoupper($m[2]);
        }

        // Pattern: Generic SKU format: RP-OCT26-XXX-192-SR or RP-XXX-192-SR
        if (preg_match('/(?:^RP-[A-Z0-9]+-)?([A-Z0-9+]{2,6})-(\d+)-([A-Z0-9]+)$/i', $pCode, $m)) {
            $prefix = strtoupper($m[1]);
            if ($prefix === 'POW' || $prefix === 'POWER') $prefix = 'PWR';
            return "{$prefix}-{$m[2]}-" . strtoupper($m[3]);
        }

        // Preserve non-empty, non-placeholder product codes (e.g. G+144p-SR, POW-24P-1x1000-SR)
        $isGenericPlaceholder = empty($pCode)
            || stripos($pCode, 'RP-PROD-') === 0
            || stripos($pCode, 'RP-ORD-') === 0
            || stripos($pCode, 'CODE MISSING') !== false
            || $pCode === 'RP001';

        if (!$isGenericPlaceholder) {
            return $pCode;
        }

        // Derivation from parameters ONLY if code is generic or missing
        if (!empty($product_name) || !empty($pages)) {
            $pNameUpper = strtoupper(trim((string)$product_name));
            $prefix = 'RP';
            if (str_contains($pNameUpper, 'POWER')) {
                $prefix = 'PWR';
            } elseif (str_contains($pNameUpper, 'PRIME')) {
                $prefix = 'PRM';
            } elseif (str_contains($pNameUpper, 'CLASS')) {
                $prefix = 'CLS';
            } elseif (str_contains($pNameUpper, 'APOLLO')) {
                $prefix = 'APL';
            } elseif (!empty($pNameUpper)) {
                $cleanPrefix = preg_replace('/[^A-Z0-9+]/', '', $pNameUpper);
                if (strlen($cleanPrefix) > 0) {
                    $prefix = substr($cleanPrefix, 0, 4);
                }
            }
            $cleanP = preg_replace('/[^0-9]/', '', (string)$pages);
            $vC = strtoupper(trim((string)$variety_code));

            $parts = array_filter([$prefix, $cleanP, $vC]);
            return implode('-', $parts);
        }

        return $pCode !== '' ? $pCode : 'RP-ITEM';
    }
}

if (!function_exists('generate_dispatch_security_token')) {
    /**
     * Generate secure unguessable HMAC token for verified dispatch QR destination
     */
    function generate_dispatch_security_token($order_id, $order_number, $parcel_number = '') {
        $salt = 'RAJ_PRIYA_DISPATCH_SALT_OCT2026';
        $payload = (int)$order_id . '|' . trim((string)$order_number) . '|' . trim((string)$parcel_number);
        return substr(hash_hmac('sha256', $payload, $salt), 0, 16);
    }
}

if (!function_exists('verify_dispatch_security_token')) {
    /**
     * Validate dispatch token with constant-time comparison
     */
    function verify_dispatch_security_token($order_id, $order_number, $parcel_number, $token) {
        if (empty($token) || strlen($token) !== 16) {
            return false;
        }
        $expected = generate_dispatch_security_token($order_id, $order_number, $parcel_number);
        return hash_equals($expected, $token);
    }
}

if (!function_exists('format_clean_dispatch_item_code')) {
    /**
     * Format clean shorthand Product ID matching reference design:
     * Examples: PWR-192-SR, APL-112-UR, RJP-200-SL
     */
    function format_clean_dispatch_item_code($product_code, $product_name = '', $pages = '', $variety = '') {
        $clean = trim((string)$product_code);
        
        // Already shorthand: e.g. PWR-192-SR, APL-112-UR, RJP-200-SL
        if (preg_match('/^[A-Z0-9]{2,5}-\d{2,4}-[A-Z0-9]+$/i', $clean)) {
            return strtoupper($clean);
        }

        // Shorthand from SKU: RP-OCT26-POW-192-SR -> PWR-192-SR
        if (preg_match('/(?:RP-[A-Z0-9]+-)?([A-Z]{3,4})-(\d+)-([A-Z0-9]+)/i', $clean, $m)) {
            $prefix = strtoupper($m[1]);
            if ($prefix === 'POW') $prefix = 'PWR';
            return $prefix . '-' . $m[2] . '-' . strtoupper($m[3]);
        }

        // Construct from Product Name, Pages, and Variety
        $pNameUpper = strtoupper(trim((string)$product_name));
        $prefix = 'RJP';
        if (str_contains($pNameUpper, 'POWER')) $prefix = 'PWR';
        elseif (str_contains($pNameUpper, 'APOLLO')) $prefix = 'APL';
        elseif (str_contains($pNameUpper, 'POPULAR')) $prefix = 'POP';
        elseif (str_contains($pNameUpper, 'ECONOMI')) $prefix = 'ECO';
        elseif (strlen($pNameUpper) >= 3) $prefix = substr(preg_replace('/[^A-Z]/', '', $pNameUpper), 0, 3);

        $cleanP = preg_replace('/[^0-9]/', '', (string)$pages);
        if (empty($cleanP)) $cleanP = '192';

        $vUpper = strtoupper(trim((string)$variety));
        $vCode = 'SR';
        if (str_contains($vUpper, 'UNRULED') || str_contains($vUpper, 'PLAIN') || str_contains($vUpper, 'UR') || str_contains($vUpper, 'PL')) $vCode = 'UR';
        elseif (str_contains($vUpper, 'DOUBLE') || str_contains($vUpper, 'DR')) $vCode = 'DR';
        elseif (str_contains($vUpper, 'FOUR') || str_contains($vUpper, 'FR')) $vCode = 'FR';
        elseif (str_contains($vUpper, 'MATH') || str_contains($vUpper, 'SQUARE') || str_contains($vUpper, 'SQ')) $vCode = 'SQ';
        elseif (str_contains($vUpper, 'SINGLE LINE') || str_contains($vUpper, 'SL')) $vCode = 'SL';
        elseif (!empty($clean)) {
            $parts = explode('-', $clean);
            $last = end($parts);
            if (strlen($last) <= 4) $vCode = strtoupper($last);
        }

        return "{$prefix}-{$cleanP}-{$vCode}";
    }
}

if (!function_exists('get_dispatch_tracking_url')) {
    /**
     * Resolves the full dynamic URL for Dispatch Items QR Code
     * Works seamlessly in local development, Wi-Fi LAN testing, and production deployment
     */
    function get_dispatch_tracking_url($order_number, $parcel_number = '', $token = '', $mode = 'auto') {
        $params = ['ord' => $order_number];
        if (!empty($parcel_number)) {
            $params['pcl'] = $parcel_number;
        }
        if (!empty($token)) {
            $params['token'] = $token;
        }

        if ($mode === 'wifi') {
            $ip = function_exists('get_server_local_ip') ? get_server_local_ip() : '10.246.34.88';
            return "http://{$ip}/rajpriya/dispatch-details.php?" . http_build_query($params);
        }

        if ($mode === 'local') {
            return "http://localhost/rajpriya/dispatch-details.php?" . http_build_query($params);
        }

        // Live / Deployed mode or auto
        $dbAppUrl = function_exists('get_setting') ? get_setting('app_url', '') : '';
        if (!empty($dbAppUrl) && stripos($dbAppUrl, 'localhost') === false && stripos($dbAppUrl, '127.0.0.1') === false) {
            $baseUrl = rtrim(trim($dbAppUrl), '/');
        } else {
            $baseUrl = 'https://www.rajpriyanotebooks.com';
        }

        return "{$baseUrl}/dispatch-details.php?" . http_build_query($params);
    }
}

if (!function_exists('format_dispatch_date_display')) {
    /**
     * Safely formats dispatch date as DD-MM-YYYY without timezone shift.
     * Returns '—' when empty or unset without substituting order/today's date.
     */
    function format_dispatch_date_display($dateStr) {
        if (empty($dateStr)) {
            return '—';
        }
        $cleanDate = trim((string)$dateStr);
        if ($cleanDate === '' || $cleanDate === '0000-00-00' || $cleanDate === '0000-00-00 00:00:00') {
            return '—';
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $cleanDate, $m)) {
            return $m[3] . '-' . $m[2] . '-' . $m[1];
        }
        $timestamp = strtotime($cleanDate);
        if ($timestamp !== false && $timestamp > 0) {
            return date('d-m-Y', $timestamp);
        }
        return '—';
    }
}

if (!function_exists('format_datetime')) {
    /**
     * Standardized Date and Time Formatter for RAJPRIYA Notebooks.
     * Formats database timestamps/datetime strings to Indian Standard Time (IST, UTC+05:30).
     * Output format example: '22-10-2026 04:30 PM'
     * Returns '—' for empty or zero dates.
     */
    function format_datetime($datetime, $format = 'd-m-Y h:i A') {
        if (empty($datetime) || $datetime === '0000-00-00 00:00:00' || $datetime === '0000-00-00' || $datetime === '—') {
            return '—';
        }
        $ts = is_numeric($datetime) ? (int)$datetime : strtotime($datetime);
        if ($ts === false || $ts <= 0) {
            return htmlspecialchars((string)$datetime);
        }
        return date($format, $ts);
    }
}

if (!function_exists('format_date')) {
    /**
     * Standardized Date Formatter for RAJPRIYA Notebooks.
     * Formats date strings to Indian Standard format (e.g. '22-10-2026').
     * Returns '—' for empty or zero dates.
     */
    function format_date($date, $format = 'd-m-Y') {
        if (empty($date) || $date === '0000-00-00 00:00:00' || $date === '0000-00-00' || $date === '—') {
            return '—';
        }
        $ts = is_numeric($date) ? (int)$date : strtotime($date);
        if ($ts === false || $ts <= 0) {
            return htmlspecialchars((string)$date);
        }
        return date($format, $ts);
    }
}

// =========================================================================
// ORDER QUOTATION SYSTEM HELPER FUNCTIONS
// =========================================================================

if (!function_exists('generate_quotation_number')) {
    /**
     * Generate unique quotation number formatted as QTN-YYYYMMDD-[OrderID or Seq]
     */
    function generate_quotation_number($order_id = 0) {
        $db = getDB();
        $dateStr = date('Ymd');
        $base = 'QTN-' . $dateStr . '-' . str_pad($order_id > 0 ? (int)$order_id : 1, 4, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare("SELECT id FROM quotations WHERE quotation_number = ?");
        $stmt->execute([$base]);
        if (!$stmt->fetch()) {
            return $base;
        }

        for ($i = 2; $i <= 99; $i++) {
            $candidate = $base . '-V' . $i;
            $stmt->execute([$candidate]);
            if (!$stmt->fetch()) {
                return $candidate;
            }
        }
        return 'QTN-' . $dateStr . '-' . rand(1000, 9999);
    }
}

if (!function_exists('render_quotation_status_badge')) {
    /**
     * Render consistent HTML badge for quotation status
     */
    function render_quotation_status_badge($status) {
        switch ($status) {
            case 'Accepted':
                return '<span class="badge bg-success text-white px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i> Accepted</span>';
            case 'Declined':
                return '<span class="badge bg-danger text-white px-2 py-1"><i class="bi bi-x-circle-fill me-1"></i> Declined</span>';
            case 'Change Requested':
                return '<span class="badge bg-warning text-dark px-2 py-1"><i class="bi bi-pencil-square me-1"></i> Change Requested</span>';
            case 'Sent':
                return '<span class="badge bg-primary text-white px-2 py-1"><i class="bi bi-send-check me-1"></i> Sent / Pending Response</span>';
            case 'Draft':
            default:
                return '<span class="badge bg-secondary text-white px-2 py-1"><i class="bi bi-pencil-square me-1"></i> Draft</span>';
        }
    }
}

if (!function_exists('render_quotation_item_status_badge')) {
    /**
     * Renders badge for individual quotation item status
     */
    function render_quotation_item_status_badge($status, $is_customer_removed = 0) {
        if ($is_customer_removed) {
            return '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 fw-bold"><i class="bi bi-trash me-1"></i> Customer Removed</span>';
        }
        switch ($status) {
            case 'Accepted':
                return '<span class="badge bg-success text-white px-2 py-1 fw-bold"><i class="bi bi-check-circle-fill me-1"></i> Accepted</span>';
            case 'Declined':
                return '<span class="badge bg-danger text-white px-2 py-1 fw-bold"><i class="bi bi-x-circle-fill me-1"></i> Declined</span>';
            case 'Change Requested':
                return '<span class="badge bg-warning text-dark px-2 py-1 fw-bold"><i class="bi bi-arrow-repeat me-1"></i> Change Requested</span>';
            case 'Pending':
            default:
                return '<span class="badge bg-secondary text-white px-2 py-1 fw-bold"><i class="bi bi-clock-history me-1"></i> Pending Review</span>';
        }
    }
}

if (!function_exists('get_order_quotation')) {
    /**
     * Get the latest quotation for a given order
     */
    function get_order_quotation($order_id) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM quotations WHERE order_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int)$order_id]);
        return $stmt->fetch();
    }
}

if (!function_exists('get_quotation_by_id')) {
    /**
     * Get quotation by ID with optional customer ownership authorization check
     */
    function get_quotation_by_id($quotation_id, $customer_id = null) {
        $db = getDB();
        $qid = (int)$quotation_id;
        if ($qid <= 0) return null;

        if ($customer_id !== null && $customer_id > 0) {
            $cId = (int)$customer_id;
            $stmtUser = $db->prepare("SELECT customer_code FROM users WHERE id = ?");
            $stmtUser->execute([$cId]);
            $uRow = $stmtUser->fetch();
            $cCode = $uRow['customer_code'] ?? '';

            $stmt = $db->prepare("
                SELECT * FROM quotations 
                WHERE id = ? 
                  AND (user_id = ? OR (customer_code = ? AND customer_code IS NOT NULL AND customer_code != ''))
                LIMIT 1
            ");
            $stmt->execute([$qid, $cId, $cCode]);
            $row = $stmt->fetch();
            return $row ? $row : null;
        }

        $stmt = $db->prepare("SELECT * FROM quotations WHERE id = ? LIMIT 1");
        $stmt->execute([$qid]);
        $row = $stmt->fetch();
        return $row ? $row : null;
    }
}

if (!function_exists('get_quotation_items')) {
    /**
     * Get line items for a quotation
     */
    function get_quotation_items($quotation_id) {
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id ASC");
        $stmt->execute([(int)$quotation_id]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('get_customer_quotations')) {
    /**
     * Get all quotations for a customer
     */
    function get_customer_quotations($customer_id) {
        $db = getDB();
        $cId = (int)$customer_id;
        if ($cId <= 0) return [];

        $stmtUser = $db->prepare("SELECT customer_code FROM users WHERE id = ?");
        $stmtUser->execute([$cId]);
        $uRow = $stmtUser->fetch();
        $cCode = $uRow['customer_code'] ?? '';

        $stmt = $db->prepare("
            SELECT q.*, 
                   COUNT(qi.id) as item_count,
                   GROUP_CONCAT(CONCAT(qi.product_name, ' (', qi.quantity, ' ', qi.order_type, ')') SEPARATOR ', ') as items_summary
            FROM quotations q
            LEFT JOIN quotation_items qi ON qi.quotation_id = q.id
            WHERE (q.user_id = ? OR (q.customer_code = ? AND q.customer_code IS NOT NULL AND q.customer_code != ''))
              AND q.status != 'Draft'
            GROUP BY q.id
            ORDER BY q.id DESC
        ");
        $stmt->execute([$cId, $cCode]);
        return $stmt->fetchAll();
    }
}

if (!function_exists('get_customer_pending_quotation_count')) {
    /**
     * Count of pending ('Sent') quotations for customer notification
     */
    function get_customer_pending_quotation_count($customer_id) {
        $db = getDB();
        $cId = (int)$customer_id;
        if ($cId <= 0) return 0;

        $stmtUser = $db->prepare("SELECT customer_code FROM users WHERE id = ?");
        $stmtUser->execute([$cId]);
        $uRow = $stmtUser->fetch();
        $cCode = $uRow['customer_code'] ?? '';

        $stmt = $db->prepare("
            SELECT COUNT(*) FROM quotations
            WHERE (user_id = ? OR (customer_code = ? AND customer_code IS NOT NULL AND customer_code != ''))
              AND status = 'Sent'
        ");
        $stmt->execute([$cId, $cCode]);
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('calculate_quotation_totals')) {
    /**
     * Accurately calculate quotation subtotals, GST breakdown, and grand total.
     * Uses each item's specific gst_rate without hardcoding 18%.
     */
    function calculate_quotation_totals($items, $discount = 0.0, $delivery_fee = 0.0) {
        $discount = max(0.0, (float)$discount);
        $delivery_fee = max(0.0, (float)$delivery_fee);
        
        $raw_subtotal = 0.0;
        $total_taxable = 0.0;
        $total_gst = 0.0;
        $total_cgst = 0.0;
        $total_sgst = 0.0;
        $rates_breakdown = [];
        $processed_items = [];

        // 1. Calculate raw line subtotals
        foreach ($items as $item) {
            $unit_price = max(0.0, (float)($item['unit_price'] ?? ($item['price'] ?? 0.0)));
            $qty = max(1, (int)($item['quantity'] ?? 1));
            $line_disc = max(0.0, (float)($item['discount'] ?? 0.0));
            $line_subtotal = round(($unit_price * $qty) - $line_disc, 2);
            $line_subtotal = max(0.0, $line_subtotal);
            $raw_subtotal += $line_subtotal;
        }

        // 2. Prorate overall discount across lines if applicable
        foreach ($items as $item) {
            $unit_price = max(0.0, (float)($item['unit_price'] ?? ($item['price'] ?? 0.0)));
            $qty = max(1, (int)($item['quantity'] ?? 1));
            $line_disc = max(0.0, (float)($item['discount'] ?? 0.0));
            $line_subtotal = round(($unit_price * $qty) - $line_disc, 2);
            $line_subtotal = max(0.0, $line_subtotal);

            if ($discount > 0 && $raw_subtotal > 0) {
                $prorated_disc = round(($line_subtotal / $raw_subtotal) * $discount, 2);
                $line_taxable = max(0.0, round($line_subtotal - $prorated_disc, 2));
            } else {
                $line_taxable = $line_subtotal;
            }

            $rate = 18.00;
            if (isset($item['gst_rate']) && $item['gst_rate'] !== null && $item['gst_rate'] !== '') {
                $rate = (float)$item['gst_rate'];
            }

            $line_gst  = round(($line_taxable * $rate) / 100, 2);
            $line_cgst = round($line_gst / 2, 2);
            $line_sgst = round($line_gst - $line_cgst, 2);

            $rate_key = number_format($rate, 2, '.', '');
            if (!isset($rates_breakdown[$rate_key])) {
                $rates_breakdown[$rate_key] = [
                    'rate' => $rate,
                    'rate_label' => ((float)$rate == (int)$rate ? (int)$rate : $rate) . '% GST',
                    'cgst_rate' => $rate / 2,
                    'sgst_rate' => $rate / 2,
                    'taxable_amount' => 0.0,
                    'gst_amount' => 0.0,
                    'cgst_amount' => 0.0,
                    'sgst_amount' => 0.0,
                ];
            }

            $rates_breakdown[$rate_key]['taxable_amount'] = round($rates_breakdown[$rate_key]['taxable_amount'] + $line_taxable, 2);
            $rates_breakdown[$rate_key]['gst_amount']     = round($rates_breakdown[$rate_key]['gst_amount'] + $line_gst, 2);
            $rates_breakdown[$rate_key]['cgst_amount']    = round($rates_breakdown[$rate_key]['cgst_amount'] + $line_cgst, 2);
            $rates_breakdown[$rate_key]['sgst_amount']    = round($rates_breakdown[$rate_key]['sgst_amount'] + $line_sgst, 2);

            $total_taxable += $line_taxable;
            $total_gst     += $line_gst;
            $total_cgst    += $line_cgst;
            $total_sgst    += $line_sgst;

            $item_copy = $item;
            $item_copy['total_price']    = $line_subtotal;
            $item_copy['taxable_amount'] = $line_taxable;
            $item_copy['gst_rate']       = $rate;
            $item_copy['gst_amount']     = $line_gst;
            $item_copy['cgst_amount']    = $line_cgst;
            $item_copy['sgst_amount']    = $line_sgst;
            $processed_items[] = $item_copy;
        }

        $grand_total = round($total_taxable + $total_gst + $delivery_fee, 2);

        return [
            'subtotal'        => round($raw_subtotal, 2),
            'discount'        => round($discount, 2),
            'delivery_fee'    => round($delivery_fee, 2),
            'taxable_amount'  => round($total_taxable, 2),
            'total_gst'       => round($total_gst, 2),
            'total_cgst'      => round($total_cgst, 2),
            'total_sgst'      => round($total_sgst, 2),
            'grand_total'     => round($grand_total, 2),
            'rates_breakdown' => $rates_breakdown,
            'items'           => $processed_items
        ];
    }
}

if (!function_exists('record_quotation_revision')) {
    /**
     * Snapshots the quotation state and all line items into quotation_revisions for audit history.
     */
    function record_quotation_revision($quotation_id, $revision_type, $role, $user_id = null, $notes = '') {
        $db = getDB();
        $qid = (int)$quotation_id;
        if ($qid <= 0) return false;

        $stmtQ = $db->prepare("SELECT * FROM quotations WHERE id = ?");
        $stmtQ->execute([$qid]);
        $q = $stmtQ->fetch();
        if (!$q) return false;

        $items = get_quotation_items($qid);

        $stmtRev = $db->prepare("SELECT COALESCE(MAX(revision_number), 0) + 1 FROM quotation_revisions WHERE quotation_id = ?");
        $stmtRev->execute([$qid]);
        $revNum = (int)$stmtRev->fetchColumn();

        $totals_snapshot = json_encode([
            'subtotal'       => (float)$q['subtotal'],
            'discount'       => (float)$q['discount'],
            'delivery_fee'   => (float)$q['delivery_fee'],
            'taxable_amount' => (float)$q['taxable_amount'],
            'total_gst'      => (float)$q['total_gst'],
            'total_cgst'     => (float)$q['total_cgst'],
            'total_sgst'     => (float)$q['total_sgst'],
            'grand_total'    => (float)$q['grand_total']
        ], JSON_UNESCAPED_UNICODE);

        $items_snapshot = json_encode($items, JSON_UNESCAPED_UNICODE);

        $stmtIns = $db->prepare("
            INSERT INTO quotation_revisions (
                quotation_id, revision_number, revision_type, status,
                created_by_role, created_by_id, notes,
                items_snapshot, totals_snapshot, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        return $stmtIns->execute([
            $qid,
            $revNum,
            $revision_type,
            $q['status'],
            $role,
            $user_id,
            $notes,
            $items_snapshot,
            $totals_snapshot
        ]);
    }
}

if (!function_exists('get_quotation_revisions')) {
    /**
     * Get revision audit history for a quotation
     */
    function get_quotation_revisions($quotation_id) {
        $db = getDB();
        $qid = (int)$quotation_id;
        $stmt = $db->prepare("SELECT * FROM quotation_revisions WHERE quotation_id = ? ORDER BY revision_number DESC, id DESC");
        $stmt->execute([$qid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('update_quotation_item_status')) {
    /**
     * Updates an individual quotation line item status (Accepted / Declined / Pending / Change Requested)
     */
    function update_quotation_item_status($quotation_id, $item_id, $new_status, $admin_comment = '', $admin_id = null) {
        $db = getDB();
        $qid = (int)$quotation_id;
        $iid = (int)$item_id;

        $valid_statuses = ['Pending', 'Accepted', 'Declined', 'Change Requested'];
        if (!in_array($new_status, $valid_statuses)) {
            return ['success' => false, 'message' => 'Invalid status: ' . $new_status];
        }

        $stmt = $db->prepare("
            UPDATE quotation_items SET
                item_status = ?,
                admin_comment = ?,
                reviewed_at = NOW()
            WHERE id = ? AND quotation_id = ?
        ");
        $stmt->execute([$new_status, $admin_comment, $iid, $qid]);

        return ['success' => true, 'item_id' => $iid, 'new_status' => $new_status];
    }
}

if (!function_exists('submit_customer_quotation_change_request')) {
    /**
     * Handles customer-side request to remove items, alter quantities, and submit revision remarks.
     * Preserves previous state in quotation_revisions and marks affected items.
     */
    function submit_customer_quotation_change_request($quotation_id, $customer_id, $items_data, $customer_notes = '') {
        $db = getDB();
        $qid = (int)$quotation_id;
        $cid = (int)$customer_id;

        $q = get_quotation_by_id($qid, $cid);
        if (!$q) {
            return ['success' => false, 'message' => 'Quotation not found or access denied.'];
        }

        if (in_array($q['status'], ['Accepted', 'Declined']) && !empty($q['is_final_approved'])) {
            return ['success' => false, 'message' => 'This quotation has already received final approval and cannot be modified.'];
        }

        $existing_items = get_quotation_items($qid);
        if (empty($existing_items)) {
            return ['success' => false, 'message' => 'No items found in quotation.'];
        }

        $db->beginTransaction();
        try {
            // Snapshot state before change
            record_quotation_revision($qid, 'customer_change_request', 'customer', $cid, 'Pre-change request snapshot. Customer remarks: ' . $customer_notes);

            $active_items_for_calc = [];

            foreach ($existing_items as $item) {
                $itemId = (int)$item['id'];
                $itemChange = $items_data[$itemId] ?? null;

                $origQty = ($item['original_quantity'] !== null) ? (int)$item['original_quantity'] : (int)$item['quantity'];
                $unitPrice = (float)$item['unit_price'];
                $ppb = max(1, (int)$item['pieces_per_bundle']);
                $orderType = $item['order_type'];
                $isBundle = (strcasecmp($orderType, 'Bundle') === 0);

                if ($itemChange && !empty($itemChange['remove'])) {
                    // Item marked as removed by customer
                    $stmtUp = $db->prepare("
                        UPDATE quotation_items SET
                            is_customer_removed = 1,
                            customer_action = 'removed',
                            item_status = 'Change Requested',
                            original_quantity = ?
                        WHERE id = ? AND quotation_id = ?
                    ");
                    $stmtUp->execute([$origQty, $itemId, $qid]);

                } elseif ($itemChange && isset($itemChange['quantity'])) {
                    $newQty = max(1, (int)$itemChange['quantity']);
                    $custAction = ($newQty != $origQty) ? 'modified' : 'keep';
                    $newTotPcs = $isBundle ? ($newQty * $ppb) : $newQty;
                    $lineTotal = round($unitPrice * $newQty, 2);

                    $gstRate = (float)$item['gst_rate'];
                    $taxable = $lineTotal;
                    $gstAmt = round(($taxable * $gstRate) / 100, 2);
                    $cgstAmt = round($gstAmt / 2, 2);
                    $sgstAmt = round($gstAmt - $cgstAmt, 2);

                    $stmtUp = $db->prepare("
                        UPDATE quotation_items SET
                            quantity = ?,
                            customer_requested_quantity = ?,
                            total_pieces = ?,
                            total_price = ?,
                            taxable_amount = ?,
                            gst_amount = ?,
                            cgst_amount = ?,
                            sgst_amount = ?,
                            is_customer_removed = 0,
                            customer_action = ?,
                            item_status = 'Change Requested',
                            original_quantity = ?
                        WHERE id = ? AND quotation_id = ?
                    ");
                    $stmtUp->execute([
                        $newQty,
                        $newQty,
                        $newTotPcs,
                        $lineTotal,
                        $taxable,
                        $gstAmt,
                        $cgstAmt,
                        $sgstAmt,
                        $custAction,
                        $origQty,
                        $itemId,
                        $qid
                    ]);

                    $active_items_for_calc[] = [
                        'unit_price' => $unitPrice,
                        'quantity'   => $newQty,
                        'discount'   => (float)$item['discount'],
                        'gst_rate'   => $gstRate
                    ];
                } else {
                    // Unchanged item
                    if (empty($item['is_customer_removed'])) {
                        $active_items_for_calc[] = [
                            'unit_price' => $unitPrice,
                            'quantity'   => (int)$item['quantity'],
                            'discount'   => (float)$item['discount'],
                            'gst_rate'   => (float)$item['gst_rate']
                        ];
                    }
                }
            }

            if (empty($active_items_for_calc)) {
                $db->rollBack();
                return ['success' => false, 'message' => 'You cannot remove all products from the quotation. At least one product must remain.'];
            }

            $discount = (float)$q['discount'];
            $delivery_fee = (float)$q['delivery_fee'];
            $calculated = calculate_quotation_totals($active_items_for_calc, $discount, $delivery_fee);

            $newVersion = (int)$q['version'] + 1;
            $stmtQUp = $db->prepare("
                UPDATE quotations SET
                    subtotal = ?,
                    taxable_amount = ?,
                    total_gst = ?,
                    total_cgst = ?,
                    total_sgst = ?,
                    grand_total = ?,
                    status = 'Change Requested',
                    customer_notes = ?,
                    change_requested_at = NOW(),
                    version = ?
                WHERE id = ?
            ");
            $stmtQUp->execute([
                $calculated['subtotal'],
                $calculated['taxable_amount'],
                $calculated['total_gst'],
                $calculated['total_cgst'],
                $calculated['total_sgst'],
                $calculated['grand_total'],
                $customer_notes,
                $newVersion,
                $qid
            ]);

            record_quotation_revision($qid, 'customer_change_request', 'customer', $cid, 'Customer submitted change request: ' . $customer_notes);

            $db->commit();
            return ['success' => true, 'quotation_id' => $qid, 'version' => $newVersion];

        } catch (Exception $e) {
            $db->rollBack();
            return ['success' => false, 'message' => 'Failed to process change request: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('sync_final_quotation_to_invoice')) {
    /**
     * Validates and synchronizes the final approved quotation products into the Order and Invoice.
     * Only Accepted items are included. Excludes declined and customer-removed items.
     * Prevents approval if any items remain Pending or Change Requested.
     */
    function sync_final_quotation_to_invoice($quotation_id, $admin_id = null) {
        $db = getDB();
        $qid = (int)$quotation_id;

        $stmtQ = $db->prepare("SELECT * FROM quotations WHERE id = ?");
        $stmtQ->execute([$qid]);
        $quotation = $stmtQ->fetch();
        if (!$quotation) {
            return ['success' => false, 'message' => 'Quotation not found.'];
        }
        if (($quotation['status'] ?? '') !== 'Accepted') {
            return [
                'success' => false,
                'message' => 'Customer approval is required for the current quotation version before the final order and invoice can be confirmed.'
            ];
        }

        $order_id = (int)$quotation['order_id'];
        $stmtOrd = $db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmtOrd->execute([$order_id]);
        $order = $stmtOrd->fetch();
        if (!$order) {
            return ['success' => false, 'message' => 'Associated order not found.'];
        }

        $allItems = get_quotation_items($qid);
        if (empty($allItems)) {
            return ['success' => false, 'message' => 'Quotation has no items.'];
        }

        $pendingCount = 0;
        $changeReqCount = 0;
        $acceptedItems = [];
        $declinedCount = 0;

        foreach ($allItems as $it) {
            if (!empty($it['is_customer_removed'])) {
                continue;
            }

            if ($it['item_status'] === 'Pending') {
                $pendingCount++;
            } elseif ($it['item_status'] === 'Change Requested') {
                $changeReqCount++;
            } elseif ($it['item_status'] === 'Accepted') {
                $acceptedItems[] = $it;
            } elseif ($it['item_status'] === 'Declined') {
                $declinedCount++;
            }
        }

        if ($pendingCount > 0) {
            return ['success' => false, 'message' => "Cannot complete final approval: {$pendingCount} product(s) are still Pending. Please Accept or Decline each product first."];
        }

        if ($changeReqCount > 0) {
            return ['success' => false, 'message' => "Cannot complete final approval: {$changeReqCount} product(s) have unreviewed Change Requests. Please review them before approval."];
        }

        if (empty($acceptedItems)) {
            return ['success' => false, 'message' => "Cannot generate invoice: No products have been Accepted. At least one product must be approved to create an invoice."];
        }

        // Server-side recalculation of totals for ONLY the Accepted products
        $calc_items = [];
        foreach ($acceptedItems as $ai) {
            $calc_items[] = [
                'unit_price' => (float)$ai['unit_price'],
                'quantity'   => (int)$ai['quantity'],
                'discount'   => (float)$ai['discount'],
                'gst_rate'   => (float)$ai['gst_rate']
            ];
        }

        $discount = (float)$quotation['discount'];
        $delivery_fee = (float)$quotation['delivery_fee'];
        $recalculated = calculate_quotation_totals($calc_items, $discount, $delivery_fee);

        $db->beginTransaction();
        try {
            // 1. Clear previous order_items and insert final accepted products only
            $stmtDelOI = $db->prepare("DELETE FROM order_items WHERE order_id = ?");
            $stmtDelOI->execute([$order_id]);

            $stmtInsOI = $db->prepare("
                INSERT INTO order_items (
                    order_id, product_id, variant_id, product_name, product_code, ruling_type, 
                    page_count, variety, pieces_per_bundle, total_pieces, order_type,
                    price, quantity, total_price, gst_rate, taxable_amount, gst_amount, cgst_amount, sgst_amount
                ) VALUES (
                    ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?
                )
            ");

            foreach ($acceptedItems as $idx => $ai) {
                $recItm = $recalculated['items'][$idx];
                $stmtInsOI->execute([
                    $order_id,
                    $ai['product_id'],
                    $ai['variant_id'],
                    $ai['product_name'],
                    $ai['product_code'],
                    $ai['ruling_type'],
                    $ai['page_count'],
                    $ai['variety'],
                    $ai['pieces_per_bundle'],
                    $ai['total_pieces'],
                    $ai['order_type'],
                    $ai['unit_price'],
                    $ai['quantity'],
                    $recItm['total_price'],
                    $recItm['gst_rate'],
                    $recItm['taxable_amount'],
                    $recItm['gst_amount'],
                    $recItm['cgst_amount'],
                    $recItm['sgst_amount']
                ]);
            }

            // 2. Update orders table with exact recalculated invoice totals
            $stmtOrdUp = $db->prepare("
                UPDATE orders SET
                    subtotal = ?,
                    discount = ?,
                    delivery_fee = ?,
                    total_gst = ?,
                    total_cgst = ?,
                    total_sgst = ?,
                    grand_total = ?,
                    order_status = CASE WHEN order_status = 'Pending' THEN 'Confirmed' ELSE order_status END
                WHERE id = ?
            ");
            $stmtOrdUp->execute([
                $recalculated['subtotal'],
                $recalculated['discount'],
                $recalculated['delivery_fee'],
                $recalculated['total_gst'],
                $recalculated['total_cgst'],
                $recalculated['total_sgst'],
                $recalculated['grand_total'],
                $order_id
            ]);

            // 3. Update quotations table with final approved state
            $stmtQUp = $db->prepare("
                UPDATE quotations SET
                    subtotal = ?,
                    discount = ?,
                    delivery_fee = ?,
                    taxable_amount = ?,
                    total_gst = ?,
                    total_cgst = ?,
                    total_sgst = ?,
                    grand_total = ?,
                    status = 'Accepted',
                    is_final_approved = 1,
                    invoice_synced_at = NOW()
                WHERE id = ?
            ");
            $stmtQUp->execute([
                $recalculated['subtotal'],
                $recalculated['discount'],
                $recalculated['delivery_fee'],
                $recalculated['taxable_amount'],
                $recalculated['total_gst'],
                $recalculated['total_cgst'],
                $recalculated['total_sgst'],
                $recalculated['grand_total'],
                $qid
            ]);

            // 4. Invalidate any stale cached invoice PDF on disk so fresh PDF generates with approved items
            $safe_order_num = preg_replace('/[^A-Za-z0-9_-]/', '', $order['order_number']);
            $old_pdf_path = __DIR__ . '/../uploads/invoices/Invoice-' . $safe_order_num . '.pdf';
            if (file_exists($old_pdf_path)) {
                @unlink($old_pdf_path);
            }

            // 5. Save final approval revision snapshot
            record_quotation_revision($qid, 'final_approval', 'admin', $admin_id, 'Admin completed final quotation approval and synchronized invoice.');

            $db->commit();

            // 6. Generate fresh final invoice PDF and trigger automatic WhatsApp delivery if enabled
            if (defined('WHATSAPP_AUTO_SEND') && WHATSAPP_AUTO_SEND) {
                try {
                    if (function_exists('generate_order_invoice_pdf')) {
                        generate_order_invoice_pdf($order_id, true);
                    }
                    if (function_exists('send_order_invoice_whatsapp')) {
                        send_order_invoice_whatsapp($order_id, false);
                    }
                } catch (Throwable $waErr) {
                    error_log("Automatic WhatsApp Invoice dispatch error for Order #{$order_id}: " . $waErr->getMessage());
                }
            }

            return [
                'success' => true,
                'order_id' => $order_id,
                'quotation_id' => $qid,
                'approved_count' => count($acceptedItems),
                'declined_count' => $declinedCount,
                'grand_total' => $recalculated['grand_total']
            ];

        } catch (Exception $e) {
            $db->rollBack();
            return ['success' => false, 'message' => 'Failed to sync final invoice: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('render_customer_tax_invoice_block')) {
    function render_customer_tax_invoice_block($order, $order_items, $logos, $order_gst, $has_transport) {
        ob_start();
        
        // Embed primary logo as base64 data URI if file exists locally so offline invoices display logo properly
        $primaryLogo = !empty($logos['primary']) ? $logos['primary'] : 'uploads/logos/logo_primary.webp';
        $fullLogoPath = __DIR__ . '/../' . ltrim($primaryLogo, '/');
        if (file_exists($fullLogoPath) && is_file($fullLogoPath)) {
            $mime = mime_content_type($fullLogoPath) ?: 'image/png';
            $logoSrc = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullLogoPath));
        } else {
            $logoSrc = $primaryLogo;
        }

        // Embed authorized signature image as base64 data URI (prefer transparent PNG)
        $sigPath = __DIR__ . '/../assets/images/signature.webp';
        if (!file_exists($sigPath) || !is_file($sigPath)) {
            $sigPath = __DIR__ . '/../uploads/signature.webp';
        }
        if (!file_exists($sigPath) || !is_file($sigPath)) {
            $sigPath = __DIR__ . '/../assets/images/signature_jpg.webp';
        }
        if (!file_exists($sigPath) || !is_file($sigPath)) {
            $sigPath = __DIR__ . '/../uploads/signature_jpg.webp';
        }
        if (file_exists($sigPath) && is_file($sigPath)) {
            $sigMime = mime_content_type($sigPath) ?: 'image/png';
            $signatureSrc = 'data:' . $sigMime . ';base64,' . base64_encode(file_get_contents($sigPath));
        } else {
            $signatureSrc = 'assets/images/signature.webp';
        }
        ?>
      <!-- 1. Header & Brand Lockup -->
      <div style="display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2.5px solid #17243A; padding-bottom: 14px; margin-bottom: 16px;">
        <div style="flex: 1; padding-right: 20px;">
          <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 6px;">
            <img src="<?php echo htmlspecialchars($logoSrc); ?>" alt="Raj Priya Notebooks" style="height: 48px; object-fit: contain;">
            <div>
              <h2 style="font-weight: 800; color: #17243A; margin: 0; font-size: 1.35rem; letter-spacing: 0.5px; line-height: 1.2;">RAJ PRIYA NOTEBOOKS</h2>
              <div style="font-size: 0.725rem; color: #C89B3C; font-weight: 700; text-transform: uppercase; letter-spacing: 1px;">Write Every Idea. Keep Every Thought.</div>
            </div>
          </div>
          <div style="font-size: 0.76rem; color: #475569; line-height: 1.45; margin-top: 4px;">
            <strong>Mfd. by: Prabu Trader</strong>, SALEM, Tamil Nadu, India &bull; <strong>GSTIN:</strong> <?php echo htmlspecialchars(COMPANY_GSTIN); ?><br>
            WhatsApp: 98427 46472 | Sales: 94433 46472 | Email: sales@rajpriyanotebooks.com | Web: www.rajpriyanotebooks.com
          </div>
        </div>
        
        <div style="text-align: right; flex-shrink: 0; white-space: nowrap; display: flex; align-items: flex-start; gap: 10px; justify-content: flex-end;">
          <div>
            <span style="background: #17243A; color: #C89B3C; font-weight: 800; font-size: 0.75rem; padding: 4px 14px; border-radius: 20px; text-transform: uppercase; display: inline-block; margin-bottom: 6px; letter-spacing: 0.5px;">
              OFFICIAL ORDER RECEIPT
            </span>
            <div style="font-weight: 800; color: #17243A; font-size: 1.05rem; white-space: nowrap; line-height: 1.2;">
              Order #: <?php echo htmlspecialchars($order['order_number']); ?>
            </div>
            <div style="font-size: 0.775rem; color: #64748B; margin-top: 3px; white-space: nowrap;">
              Date: <?php echo format_datetime($order['created_at']); ?>
            </div>
            <div style="font-size: 0.775rem; color: #17243A; font-weight: 600; margin-top: 3px; white-space: nowrap;">
              Payment: <?php echo htmlspecialchars($order['payment_method'] ?? 'Wholesale Credit / Bank'); ?> 
              <span style="color: #92400E; font-weight: 700;">(<?php echo htmlspecialchars($order['payment_status'] ?? 'Pending'); ?>)</span>
            </div>
          </div>
          <?php 
          $receipt_qr_link = get_order_tracking_url($order['order_number']);
          $receipt_qr_img = generate_qr_code_image_url($receipt_qr_link, 130);
          ?>
          <div style="text-align: center;">
            <img src="<?php echo $receipt_qr_img; ?>" alt="QR" style="width: 66px; height: 66px; border: 1px solid #CBD5E1; border-radius: 4px; padding: 2px; background: #fff;">
            <div style="font-size: 0.525rem; color: #64748B; font-weight: 700; margin-top: 2px;">SCAN TO VERIFY</div>
          </div>
        </div>
      </div>

      <!-- 2. Customer & Shipping Address Grid -->
      <div style="display: flex; gap: 14px; margin-bottom: 16px; align-items: stretch;">
        <div style="flex: 1; background: #F8FAFC; border: 1.5px solid #CBD5E1; border-radius: 8px; padding: 12px 14px;">
          <div style="font-weight: 800; color: #17243A; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #CBD5E1; padding-bottom: 5px; margin-bottom: 8px;">
            Billed Customer Details
          </div>
          <div style="font-weight: 800; color: #17243A; font-size: 0.95rem; margin-bottom: 3px;">
            <?php echo htmlspecialchars($order['customer_name']); ?>
          </div>
          <?php if (!empty($order['customer_code'])): ?>
          <div style="font-size: 0.8rem; color: #17243A; font-weight: 700; margin-bottom: 2px;">
            <strong>Customer ID:</strong> <span style="color: #92400E;"><?php echo htmlspecialchars($order['customer_code']); ?></span>
            <?php if (!empty($order['customer_type'])): ?>
              <span style="font-size: 0.72rem; background: #FEF3C7; color: #92400E; padding: 1px 6px; border-radius: 4px; font-weight: 700; border: 1px solid #FCD34D; margin-left: 4px;"><?php echo htmlspecialchars($order['customer_type']); ?></span>
            <?php endif; ?>
          </div>
          <?php endif; ?>
          <div style="font-size: 0.8rem; color: #475569; margin-bottom: 2px;">
            <strong>Phone:</strong> <?php echo htmlspecialchars($order['customer_phone']); ?>
          </div>
          <div style="font-size: 0.8rem; color: #475569; margin-bottom: 2px;">
            <strong>Email:</strong> <?php echo !empty($order['customer_email']) ? htmlspecialchars($order['customer_email']) : 'Not Provided'; ?>
          </div>
          <div style="font-size: 0.8rem; color: #17243A; font-weight: 600; margin-top: 4px;">
            <strong>GST Number:</strong> <?php echo !empty($order['gst_number']) ? htmlspecialchars($order['gst_number']) : 'Not Provided'; ?>
          </div>
        </div>

        <div style="flex: 1; background: #F8FAFC; border: 1.5px solid #CBD5E1; border-radius: 8px; padding: 12px 14px;">
          <div style="font-weight: 800; color: #17243A; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #CBD5E1; padding-bottom: 5px; margin-bottom: 8px;">
            Shipping Address
          </div>
          <div style="font-size: 0.8rem; color: #1E293B; line-height: 1.45;">
            <div style="font-weight: 600; color: #17243A;"><?php echo htmlspecialchars($order['shipping_address']); ?></div>
            <?php if (!empty($order['address_line2'])): ?>
              <div><?php echo htmlspecialchars($order['address_line2']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['area'])): ?>
              <div>Area: <?php echo htmlspecialchars($order['area']); ?></div>
            <?php endif; ?>
            <div style="font-weight: 600;">
              <?php echo htmlspecialchars($order['city'] ?? ''); ?>
              <?php if (!empty($order['district']) && strcasecmp($order['district'], $order['city'] ?? '') !== 0): ?>
                , <?php echo htmlspecialchars($order['district']); ?>
              <?php endif; ?>
              , <?php echo htmlspecialchars(($order['state'] ?? '') . ' - ' . ($order['pincode'] ?? '')); ?>
            </div>
            <?php if (!empty($order['landmark'])): ?>
              <div style="font-style: italic; color: #64748B; margin-top: 3px;">Landmark: <?php echo htmlspecialchars($order['landmark']); ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <?php if ($has_transport): ?>
        <!-- 2.5 Transport / Parcel Reference Block (Print Only) -->
        <div style="background: #F8FAFC; border: 1.5px solid #CBD5E1; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px;">
          <div style="font-weight: 800; color: #17243A; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #CBD5E1; padding-bottom: 5px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center;">
            <span>TRANSPORT / PARCEL DETAILS</span>
            <span style="font-size: 0.7rem; background: #17243A; color: #C89B3C; font-weight: 700; padding: 2px 8px; border-radius: 4px;">DISPATCH REF</span>
          </div>
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 6px; font-size: 0.8rem; color: #1E293B;">
            <?php if (!empty($order['transport_from'])): ?>
              <div><strong>From Location:</strong> <?php echo htmlspecialchars($order['transport_from']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_to'])): ?>
              <div><strong>To Location:</strong> <?php echo htmlspecialchars($order['transport_to']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_name'])): ?>
              <div><strong>Transport Service:</strong> <?php echo htmlspecialchars($order['transport_name']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_branch'])): ?>
              <div><strong>Branch Location:</strong> <?php echo htmlspecialchars($order['transport_branch']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_contact'])): ?>
              <div><strong>Contact Phone:</strong> <?php echo htmlspecialchars($order['transport_contact']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_lr_number'])): ?>
              <div><strong>LR / Parcel No:</strong> <span style="font-family: monospace; font-weight: 700; color: #17243A;"><?php echo htmlspecialchars($order['transport_lr_number']); ?></span></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_vehicle_number'])): ?>
              <div><strong>Vehicle No:</strong> <?php echo htmlspecialchars($order['transport_vehicle_number']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_date'])): ?>
              <div><strong>Transport Date:</strong> <?php echo format_date($order['transport_date']); ?></div>
            <?php endif; ?>
            <?php if (!empty($order['transport_remarks'])): ?>
              <div style="grid-column: span 2; border-top: 1px dashed #CBD5E1; padding-top: 4px; margin-top: 2px;"><strong>Remarks:</strong> <?php echo htmlspecialchars($order['transport_remarks']); ?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- 3. Ordered Items Table -->
      <div style="font-weight: 800; color: #17243A; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">Order Items Summary</div>
      <table style="width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 0.825rem;">
        <thead>
          <tr style="text-align: left; font-size: 0.76rem; text-transform: uppercase; letter-spacing: 0.5px; background: #17243A; color: #FFFFFF;">
            <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 28px; text-align: center; vertical-align: middle;">#</th>
            <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 8px; vertical-align: middle; line-height: 1.25;">Notebook Item &amp; Variety</th>
            <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 100px; text-align: center; vertical-align: middle;">Product Code</th>
            <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 85px; text-align: center; vertical-align: middle;">Pcs / Bundle</th>
            <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 75px; text-align: center; vertical-align: middle;">Bundles</th>
            <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 85px; text-align: center; vertical-align: middle;">Total Pieces</th>
            <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 85px; text-align: right; vertical-align: middle;">Unit Price</th>
            <th style="background: #17243A; color: #FFFFFF; border: 1px solid #17243A; padding: 7px 6px; width: 95px; text-align: right; vertical-align: middle;">Total Price</th>
          </tr>
        </thead>
        <tbody>
          <?php 
          $p_sn = 1;
          foreach ($order_items as $itm): 
            $itmVInfo = parse_variety_info($itm['ruling_type'] ?? '');
            $itemCode = !empty($itm['product_code']) ? $itm['product_code'] : get_variant_product_code($itm['product_id'], $itm['ruling_type'] ?? '');
            $itmIsBundle = (stripos($itmVInfo['order_type'] ?? '', 'Bundle') !== false) || (stripos($itm['ruling_type'] ?? '', 'Bundle') !== false);
            $itmQty = (int)$itm['quantity'];
            $itmPpb = !empty($itm['pieces_per_bundle']) ? (int)$itm['pieces_per_bundle'] : get_product_pieces_per_bundle($itm['product_id'], $itm['ruling_type'] ?? '', null);
            $itmTotalPieces = !empty($itm['total_pieces']) ? (int)$itm['total_pieces'] : ($itmIsBundle ? ($itmQty * $itmPpb) : $itmQty);
          ?>
            <tr>
              <td style="border: 1px solid #CBD5E1; padding: 7px 8px; text-align: center; color: #64748B; font-weight: 600; vertical-align: middle;"><?php echo $p_sn++; ?></td>
              <td style="border: 1px solid #CBD5E1; padding: 7px 10px; vertical-align: middle;">
                <strong style="color: #17243A; font-size: 0.85rem; display: block; line-height: 1.25;"><?php echo htmlspecialchars($itm['product_name']); ?></strong>
                <div style="color: #64748B; font-size: 0.74rem; margin-top: 2px;">
                  <?php if (!empty($itmVInfo['page_count'])): ?>
                    <span><?php echo htmlspecialchars($itmVInfo['page_count']); ?></span> &bull;
                  <?php endif; ?>
                  <span>Variety: <?php echo htmlspecialchars($itmVInfo['variety'] ?: 'Unruled'); ?></span>
                  <?php if (!empty($itmVInfo['order_type'])): ?>
                    &bull; <span><?php echo htmlspecialchars($itmVInfo['order_type']); ?></span>
                  <?php endif; ?>
                </div>
              </td>
              <td style="border: 1px solid #CBD5E1; padding: 7px 8px; font-family: monospace; font-weight: 700; color: #17243A; text-align: center; font-size: 0.78rem; vertical-align: middle;">
                <?php echo htmlspecialchars($itemCode ?: '-'); ?>
              </td>
              <td style="border: 1px solid #CBD5E1; padding: 7px 8px; text-align: center; vertical-align: middle; color: #475569; font-size: 0.85rem;">
                <?php echo $itmIsBundle ? ($itmPpb . ' Pcs') : '1 (Loose)'; ?>
              </td>
              <td style="border: 1px solid #CBD5E1; padding: 7px 8px; text-align: center; vertical-align: middle; font-weight: 700; color: #17243A; font-size: 0.88rem;">
                <?php echo $itmIsBundle ? $itmQty : '&mdash;'; ?>
              </td>
              <td style="border: 1px solid #CBD5E1; padding: 7px 8px; text-align: center; vertical-align: middle; font-weight: 700; color: #0F172A; font-size: 0.88rem; background: #F8FAFC;">
                <?php echo $itmTotalPieces; ?> Pcs
              </td>
              <td style="border: 1px solid #CBD5E1; padding: 7px 8px; text-align: right; vertical-align: middle;"><?php echo format_price($itm['price'] ?? 0); ?></td>
              <td style="border: 1px solid #CBD5E1; padding: 7px 8px; text-align: right; font-weight: 700; color: #17243A; vertical-align: middle;"><?php echo format_price($itm['total_price'] ?? 0); ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 600; color: #475569;">Taxable Amount:</td>
            <td style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 700; color: #17243A;"><?php echo format_price($order_gst['taxable_amount'] ?? 0); ?></td>
          </tr>
          <?php if (($order['discount'] ?? 0) > 0): ?>
            <tr>
              <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; color: #16A34A; font-weight: 600;">Discount Applied:</td>
              <td style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; color: #16A34A; font-weight: 700;">-<?php echo format_price($order['discount']); ?></td>
            </tr>
          <?php endif; ?>
          <?php if (!empty($order_gst['has_multiple_rates'])): ?>
            <?php foreach ($order_gst['rates_breakdown'] as $r): ?>
              <tr>
                <td colspan="7" style="border: 1px solid #CBD5E1; padding: 5px 10px; text-align: right; color: #64748B; font-size: 0.78rem;"><?php echo htmlspecialchars($r['rate_label']); ?>:</td>
                <td style="border: 1px solid #CBD5E1; padding: 5px 10px; text-align: right; color: #475569;"><?php echo format_price($r['gst_amount']); ?></td>
              </tr>
            <?php endforeach; ?>
            <tr>
              <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 600; color: #17243A;">Total GST:</td>
              <td style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 700; color: #17243A;"><?php echo format_price($order_gst['total_gst']); ?></td>
            </tr>
          <?php else: ?>
            <?php $single_rate = !empty($order_gst['rates_breakdown']) ? reset($order_gst['rates_breakdown']) : null; ?>
            <tr>
              <td colspan="7" style="border: 1px solid #CBD5E1; padding: 5px 10px; text-align: right; color: #64748B; font-size: 0.78rem;">CGST (<?php echo $single_rate ? $single_rate['cgst_rate'] : 9; ?>%):</td>
              <td style="border: 1px solid #CBD5E1; padding: 5px 10px; text-align: right; color: #475569;"><?php echo format_price($order_gst['cgst'] ?? 0); ?></td>
            </tr>
            <tr>
              <td colspan="7" style="border: 1px solid #CBD5E1; padding: 5px 10px; text-align: right; color: #64748B; font-size: 0.78rem;">SGST (<?php echo $single_rate ? $single_rate['sgst_rate'] : 9; ?>%):</td>
              <td style="border: 1px solid #CBD5E1; padding: 5px 10px; text-align: right; color: #475569;"><?php echo format_price($order_gst['sgst'] ?? 0); ?></td>
            </tr>
            <tr>
              <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 600; color: #17243A;">Total GST (<?php echo $single_rate ? $single_rate['rate'] : 18; ?>%):</td>
              <td style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 700; color: #17243A;"><?php echo format_price($order_gst['total_gst'] ?? 0); ?></td>
            </tr>
          <?php endif; ?>
          <?php if (($order['delivery_fee'] ?? 0) > 0): ?>
            <tr>
              <td colspan="7" style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 600; color: #475569;">Delivery Fee:</td>
              <td style="border: 1px solid #CBD5E1; padding: 6px 10px; text-align: right; font-weight: 700; color: #17243A;"><?php echo format_price($order['delivery_fee']); ?></td>
            </tr>
          <?php endif; ?>
          <tr style="background: #17243A; color: #FFFFFF; font-size: 0.95rem;">
            <td colspan="7" style="border: 1px solid #17243A; padding: 9px 10px; text-align: right; font-weight: 800; text-transform: uppercase;">Grand Total:</td>
            <td style="border: 1px solid #17243A; padding: 9px 10px; text-align: right; font-weight: 800; color: #C89B3C;"><?php echo format_price($order['grand_total'] ?? 0); ?></td>
          </tr>
        </tfoot>
      </table>

      <!-- 4. Footer & Sign-off -->
      <div style="display: flex; justify-content: space-between; align-items: flex-end; padding-top: 14px; border-top: 1.5px solid #CBD5E1; font-size: 0.775rem; margin-top: 20px;">
        <div style="color: #64748B; max-width: 65%; line-height: 1.45;">
          <strong style="color: #17243A;">Thank you for your business with Raj Priya Notebooks!</strong><br>
          This is a computer generated order receipt. For any questions, contact sales@rajpriyanotebooks.com.
        </div>
        <div style="text-align: center; color: #17243A; min-width: 150px;">
          <div style="font-weight: 700; margin-bottom: 4px; font-size: 0.85rem; color: #17243A;">Prabu Traders</div>
          <div style="height: 65px; display: flex; align-items: center; justify-content: center; margin: 2px 0;">
            <img src="<?php echo htmlspecialchars($signatureSrc); ?>" alt="Authorized Signature" style="max-height: 65px; max-width: 140px; width: auto; height: auto; object-fit: contain; display: block; margin: 0 auto;">
          </div>
          <div style="font-size: 0.7rem; color: #64748B;">(Authorized Signatory)</div>
        </div>
      </div>
        <?php
        return ob_get_clean();
    }
}

// =========================================================================
// SERVER-SIDE INVOICE PDF & WHATSAPP DISPATCH HELPER FUNCTIONS
// =========================================================================

if (!function_exists('generate_order_invoice_pdf')) {
    /**
     * Generates or retrieves the official, final Tax Invoice PDF for an order on the server using Dompdf.
     * Contains only approved products, GST calculations, discounts, and totals.
     * Saves to uploads/invoices/Invoice-{ORDER_NUMBER}.pdf.
     */
    function generate_order_invoice_pdf($order_id, $force_regenerate = false) {
        $db = getDB();
        $oid = (int)$order_id;

        $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$oid]);
        $order = $stmt->fetch();
        if (!$order) {
            return ['success' => false, 'message' => "Order #{$oid} not found."];
        }

        $safeOrderNum = preg_replace('/[^A-Za-z0-9_-]/', '', $order['order_number']);
        $invoicesDir = __DIR__ . '/../uploads/invoices';
        if (!is_dir($invoicesDir)) {
            @mkdir($invoicesDir, 0755, true);
        }

        $filename = 'Invoice-' . $safeOrderNum . '.pdf';
        $targetPath = $invoicesDir . '/' . $filename;
        $fileUrl = (function_exists('get_site_url') ? get_site_url() : '') . '/uploads/invoices/' . $filename;

        // Return existing PDF if already present and force regeneration is not requested
        if (!$force_regenerate && file_exists($targetPath) && filesize($targetPath) > 1024) {
            return [
                'success'       => true,
                'file_path'     => $targetPath,
                'relative_path' => 'uploads/invoices/' . $filename,
                'file_url'      => $fileUrl,
                'filename'      => $filename,
                'cached'        => true
            ];
        }

        // Fetch invoice items (strictly approved items in order_items)
        $stmt_items = $db->prepare("
            SELECT oi.*, p.image, p.pages, p.size 
            FROM order_items oi 
            LEFT JOIN products p ON oi.product_id = p.id 
            WHERE oi.order_id = ?
        ");
        $stmt_items->execute([$oid]);
        $order_items = $stmt_items->fetchAll();

        $logos = get_active_logos();
        $order_gst = get_order_gst_summary($order, $order_items);
        $has_transport = !empty($order['transport_from']) || 
                         !empty($order['transport_to']) || 
                         !empty($order['transport_name']) || 
                         !empty($order['transport_branch']) || 
                         !empty($order['transport_contact']) || 
                         !empty($order['transport_lr_number']) || 
                         !empty($order['transport_vehicle_number']) || 
                         !empty($order['transport_date']) || 
                         !empty($order['transport_remarks']);

        // Render the exact same official invoice block
        $invoiceHtml = render_customer_tax_invoice_block($order, $order_items, $logos, $order_gst, $has_transport);

        $docHtml = '<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Tax Invoice - ' . htmlspecialchars($order['order_number']) . '</title>
<style>
  * { box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: #ffffff;
    color: #17243A;
    margin: 0;
    padding: 10px 14px;
    font-size: 11px;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
</style>
</head>
<body>
' . $invoiceHtml . '
</body>
</html>';

        try {
            require_once __DIR__ . '/../vendor/autoload.php';
            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', true);
            $options->set('defaultFont', 'DejaVu Sans');

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($docHtml);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $pdfBytes = $dompdf->output();
            if (empty($pdfBytes)) {
                return ['success' => false, 'message' => 'Dompdf generated an empty document.'];
            }

            file_put_contents($targetPath, $pdfBytes);

            return [
                'success'       => true,
                'file_path'     => $targetPath,
                'relative_path' => 'uploads/invoices/' . $filename,
                'file_url'      => $fileUrl,
                'filename'      => $filename,
                'filesize'      => strlen($pdfBytes),
                'cached'        => false
            ];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'PDF generation failed: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('send_order_invoice_whatsapp')) {
    /**
     * Dispatches order invoice PDF to customer's WhatsApp number using Meta WhatsApp Cloud API.
     * Safely wraps all execution so WhatsApp failures never block invoice or order operations.
     */
    function send_order_invoice_whatsapp($order_id, $force_retry = false) {
        require_once __DIR__ . '/WhatsAppService.php';
        try {
            $service = new WhatsAppService();
            return $service->sendInvoicePdf($order_id, ['force_retry' => $force_retry]);
        } catch (Throwable $e) {
            error_log("send_order_invoice_whatsapp Exception for Order #{$order_id}: " . $e->getMessage());
            return ['success' => false, 'message' => 'WhatsApp dispatch error: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('get_order_whatsapp_delivery_status')) {
    /**
     * Retrieves the latest WhatsApp delivery status record for an order.
     */
    function get_order_whatsapp_delivery_status($order_id) {
        $db = getDB();
        try {
            $stmt = $db->prepare("SELECT * FROM whatsapp_delivery_logs WHERE order_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([(int)$order_id]);
            return $stmt->fetch() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
}
