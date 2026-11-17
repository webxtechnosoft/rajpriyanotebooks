<?php
/**
 * Admin Stock Excel Action Handler — Wholesale Bundle & Piece Stock Architecture
 * 
 * Formula: Total Piece Stock = (Bundle Stock × Pieces Per Bundle) + Loose Piece Stock
 * 
 * Handles:
 * 1. Download Stock Excel Template (pre-populated with wholesale columns)
 * 2. Download Current Stock (exports real-time bundles, pieces/bundle, loose, and total pieces)
 * 3. Preview Excel Upload (strict row validation, auto-calculates Total Piece Stock, duplicate checks)
 * 4. Commit Stock Update (transactional commit, updates bundle_stock, loose_stock, stock + audit log)
 * 5. Cancel Preview
 */

require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

require_once __DIR__ . '/includes/excel_parser.php';

$db = getDB();
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';

// Variety Alias Lookup Helper
function get_variety_candidates($varietyRaw) {
    $cleanVar = trim($varietyRaw);
    $upperVar = strtoupper($cleanVar);
    
    $varAliases = [
        'SR' => ['SR', 'RULED', 'SINGLE RULED', 'SINGLE LINE'],
        'UR' => ['UR', 'UNRULED', 'PLAIN'],
        'AC' => ['AC', 'ACCOUNTANCY', 'ACC'],
        'COM' => ['COM', 'COMMON', 'COMPOSITION & GRAPH', 'COMPOSITION AND GRAPH'],
        'GRA' => ['GRA', 'GR', 'GRAPH'],
        'OSR' => ['OSR', 'ONE SIDE RULED', 'ONE SIDE'],
        'CM' => ['CM', 'CHE', 'CHEMISTRY', 'CHEM'],
        'DR' => ['DR', 'DOUBLE LINE', 'DOUBLE RULED'],
        'FR' => ['FR', 'FOUR LINE'],
        'FS' => ['FS', 'FIVE SUBJECT'],
        'MP' => ['MP', 'MATHS PLAIN', 'MICRO POINT'],
        'MR' => ['MR', 'MATHS RULED', 'MATHS'],
        'SQ' => ['SQ', 'SQUARE'],
        'STD' => ['STD', 'STANDARD']
    ];
    
    $candidates = [$cleanVar, $upperVar];
    foreach ($varAliases as $canonical => $aliases) {
        if ($upperVar === $canonical || in_array($upperVar, $aliases)) {
            $candidates[] = $canonical;
            foreach ($aliases as $al) {
                $candidates[] = $al;
            }
        }
    }
    return array_values(array_unique(array_filter($candidates)));
}

// Find Matching Variant in DB
function find_matching_variant_record($db, $prodCodeRaw, $varietyRaw) {
    $cleanCode = trim($prodCodeRaw);
    $upperCode = strtoupper($cleanCode);
    $candidates = get_variety_candidates($varietyRaw);
    $candPlaceholders = implode(',', array_fill(0, count($candidates), '?'));

    // 1. Match by product_variants.product_code + Variety
    $stmt1 = $db->prepare("
        SELECT pv.*, p.name as product_name, p.sku as product_sku, p.std_qty as prod_std_qty, p.pages as prod_pages 
        FROM product_variants pv 
        JOIN products p ON pv.product_id = p.id 
        WHERE (pv.product_code = ? OR UPPER(pv.product_code) = ?) 
          AND (UPPER(pv.variety) IN ($candPlaceholders) OR UPPER(pv.variety_display) IN ($candPlaceholders))
        LIMIT 1
    ");
    $params1 = array_merge([$cleanCode, $upperCode], array_map('strtoupper', $candidates), array_map('strtoupper', $candidates));
    $stmt1->execute($params1);
    $row = $stmt1->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    // 2. Match by products.sku (or products.id) + Variety
    $isNum = is_numeric($cleanCode);
    $stmt2 = $db->prepare("
        SELECT pv.*, p.name as product_name, p.sku as product_sku, p.std_qty as prod_std_qty, p.pages as prod_pages 
        FROM product_variants pv 
        JOIN products p ON pv.product_id = p.id 
        WHERE (p.sku = ? OR UPPER(p.sku) = ? OR p.id = ?) 
          AND (UPPER(pv.variety) IN ($candPlaceholders) OR UPPER(pv.variety_display) IN ($candPlaceholders))
        LIMIT 1
    ");
    $params2 = array_merge([$cleanCode, $upperCode, $isNum ? (int)$cleanCode : 0], array_map('strtoupper', $candidates), array_map('strtoupper', $candidates));
    $stmt2->execute($params2);
    $row = $stmt2->fetch(PDO::FETCH_ASSOC);
    if ($row) return $row;

    // 3. Match by product_variants.product_code alone (if code uniquely identifies variant)
    $stmt3 = $db->prepare("
        SELECT pv.*, p.name as product_name, p.sku as product_sku, p.std_qty as prod_std_qty, p.pages as prod_pages 
        FROM product_variants pv 
        JOIN products p ON pv.product_id = p.id 
        WHERE pv.product_code = ? OR UPPER(pv.product_code) = ?
        LIMIT 1
    ");
    $stmt3->execute([$cleanCode, $upperCode]);
    $row = $stmt3->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $varCandidates = array_map('strtoupper', $candidates);
        if (in_array(strtoupper($row['variety']), $varCandidates) || in_array(strtoupper($row['variety_display']), $varCandidates) || empty($varietyRaw)) {
            return $row;
        }
    }

    return null;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1) DOWNLOAD STOCK EXCEL TEMPLATE
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rajpriya_wholesale_stock_template.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    // Write UTF-8 BOM so Excel opens with proper character encoding
    fputs($output, "\xEF\xBB\xBF");

    // Import template columns: Product ID/SKU/Code, Product Name, Page Count, Variety, Stock Quantity (Total Pieces)
    fputcsv($output, [
        'Product Code',
        'Product Name',
        'Page Count',
        'Variety',
        'Stock Quantity'
    ]);

    // Fetch existing product variants pre-populated with current stock (expressed as total pieces)
    $stmt = $db->query("
        SELECT pv.product_code, p.name as product_name,
               COALESCE(pv.page_count, p.pages) as page_count,
               pv.variety, pv.bundle_stock, pv.bundle_qty, pv.loose_stock, pv.stock 
        FROM product_variants pv 
        JOIN products p ON pv.product_id = p.id 
        ORDER BY p.name ASC, pv.variety ASC
    ");
    $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($variants as $v) {
        $bStock = (int)$v['bundle_stock'];
        $bQty = max(1, (int)$v['bundle_qty']);
        $lStock = (int)$v['loose_stock'];
        $totPieces = ($bStock * $bQty) + $lStock;

        fputcsv($output, [
            $v['product_code'],
            $v['product_name'],
            $v['page_count'] !== null ? $v['page_count'] : '',
            $v['variety'],
            $totPieces
        ]);
    }

    fclose($output);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 2) DOWNLOAD CURRENT STOCK (EXPORT)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'export') {
    $filename = 'rajpriya_current_wholesale_stock_' . date('Y-m-d_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Product Code',
        'Product Name',
        'Page Count',
        'Variety',
        'Order Unit',
        'Bundle Stock',
        'Pieces Per Bundle',
        'Loose Piece Stock',
        'Total Piece Stock (Auto Calculated)'
    ]);

    $stmt = $db->query("
        SELECT pv.product_code, p.name as product_name,
               COALESCE(pv.page_count, p.pages) as page_count,
               pv.variety, pv.bundle_stock, pv.bundle_qty, pv.loose_stock, pv.stock 
        FROM product_variants pv 
        JOIN products p ON pv.product_id = p.id 
        ORDER BY p.name ASC, pv.variety ASC
    ");
    $variants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($variants as $v) {
        $bStock = (int)$v['bundle_stock'];
        $bQty = max(1, (int)$v['bundle_qty']);
        $lStock = (int)$v['loose_stock'];
        $totPieces = ($bStock * $bQty) + $lStock;

        fputcsv($output, [
            $v['product_code'],
            $v['product_name'],
            $v['page_count'] !== null ? $v['page_count'] : '',
            $v['variety'],
            'Bundle + Piece',
            $bStock,
            $bQty,
            $lStock,
            $totPieces
        ]);
    }

    fclose($output);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 3) PREVIEW UPLOADED EXCEL / CSV
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'preview') {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['stock_excel_file'])) {
        echo json_encode(['success' => false, 'message' => 'No Excel or CSV file was uploaded.']);
        exit();
    }

    $file = $_FILES['stock_excel_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error (Code: ' . $file['error'] . ').']);
        exit();
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx', 'xls', 'txt'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid file format. Please upload an Excel (.xlsx) or CSV (.csv) file.']);
        exit();
    }

    $rawRows = parse_excel_or_csv_data($file['tmp_name']);
    if (empty($rawRows) || count($rawRows) < 2) {
        echo json_encode(['success' => false, 'message' => 'The uploaded file is empty or does not contain any data rows.']);
        exit();
    }

    $headerRow = array_shift($rawRows);
    
    // Column indexes mapping
    $codeIdx = -1;
    $nameIdx = -1;
    $pageCountIdx = -1;
    $varietyIdx = -1;
    $bundleStockIdx = -1;
    $ppbIdx = -1;
    $looseStockIdx = -1;
    $legacyStockIdx = -1;

    foreach ($headerRow as $idx => $h) {
        $cleanH = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '', $h)));
        if (in_array($cleanH, ['productcode', 'code', 'sku', 'productsku', 'itemcode', 'productid', 'id'])) {
            $codeIdx = $idx;
        } elseif (in_array($cleanH, ['productname', 'product', 'name', 'itemname', 'notebook'])) {
            $nameIdx = $idx;
        } elseif (in_array($cleanH, ['pagecount', 'pages', 'page', 'pagenos', 'pageno'])) {
            $pageCountIdx = $idx;
        } elseif (in_array($cleanH, ['variety', 'variant', 'ruling', 'rulingtype'])) {
            $varietyIdx = $idx;
        } elseif (in_array($cleanH, ['bundlestock', 'bundles', 'bundleqty', 'bundlequantity', 'bundlesstock'])) {
            $bundleStockIdx = $idx;
        } elseif (in_array($cleanH, ['piecesperbundle', 'piecesbundle', 'ppb', 'packsize', 'pcsperbundle', 'bundlepack'])) {
            $ppbIdx = $idx;
        } elseif (in_array($cleanH, ['loosepiecestock', 'loosepieces', 'loosestock', 'singlepieces', 'loosepiece', 'loose'])) {
            $looseStockIdx = $idx;
        } elseif (in_array($cleanH, ['stock', 'currentstock', 'totalpieces', 'totalpiecestock', 'totalpiecestockautocalculated', 'stockquantity', 'quantity', 'qty', 'totalstock', 'availableqty'])) {
            $legacyStockIdx = $idx;
        }
    }

    // Required columns validation
    $missingCols = [];
    if ($codeIdx === -1) $missingCols[] = 'Product Code';
    if ($varietyIdx === -1) $missingCols[] = 'Variety';
    // Must have either Bundle Stock OR regular Stock
    if ($bundleStockIdx === -1 && $legacyStockIdx === -1) {
        $missingCols[] = 'Bundle Stock (or Stock/Stock Quantity)';
    }

    if (!empty($missingCols)) {
        echo json_encode([
            'success' => false,
            'message' => 'Required column(s) missing in Excel header: ' . implode(', ', $missingCols) . '. Header must have "Product Code", "Variety", and "Bundle Stock".'
        ]);
        exit();
    }

    // Validation Loop
    $errors = [];
    $seenEntries = []; // Duplicate detection
    $validatedRows = [];
    $reportRows = [];

    foreach ($rawRows as $rowIdx => $rowValues) {
        $rowNum = $rowIdx + 2; // 1-based, accounting for header

        // Skip completely empty rows
        $nonEmpty = array_filter($rowValues, fn($v) => trim((string)$v) !== '');
        if (empty($nonEmpty)) {
            continue;
        }

        $prodCode = isset($rowValues[$codeIdx]) ? trim((string)$rowValues[$codeIdx]) : '';
        $prodName = ($nameIdx !== -1 && isset($rowValues[$nameIdx])) ? trim((string)$rowValues[$nameIdx]) : '';
        $pageCountRaw = ($pageCountIdx !== -1 && isset($rowValues[$pageCountIdx])) ? trim((string)$rowValues[$pageCountIdx]) : '';
        $variety = isset($rowValues[$varietyIdx]) ? trim((string)$rowValues[$varietyIdx]) : '';
        
        $bundleStockRaw = ($bundleStockIdx !== -1 && isset($rowValues[$bundleStockIdx])) ? trim((string)$rowValues[$bundleStockIdx]) : '';
        $ppbRaw = ($ppbIdx !== -1 && isset($rowValues[$ppbIdx])) ? trim((string)$rowValues[$ppbIdx]) : '';
        $looseStockRaw = ($looseStockIdx !== -1 && isset($rowValues[$looseStockIdx])) ? trim((string)$rowValues[$looseStockIdx]) : '';
        $legacyStockRaw = ($legacyStockIdx !== -1 && isset($rowValues[$legacyStockIdx])) ? trim((string)$rowValues[$legacyStockIdx]) : '';

        $reportBase = [
            'row' => $rowNum,
            'code' => $prodCode,
            'name' => $prodName,
            'page_count' => $pageCountRaw,
            'variety' => $variety,
            'stock' => ''
        ];

        // 1. Basic empty checks
        if ($prodCode === '') {
            $msg = "Row $rowNum: Product Code is empty.";
            $errors[] = $msg;
            $reportRows[] = array_merge($reportBase, ['status' => 'Invalid', 'message' => 'Product Code is empty.']);
            continue;
        }
        if ($variety === '') {
            $msg = "Row $rowNum: Variety is empty for Product Code \"$prodCode\".";
            $errors[] = $msg;
            $reportRows[] = array_merge($reportBase, ['status' => 'Invalid', 'message' => 'Variety is empty.']);
            continue;
        }

        // Page count validation
        if ($pageCountRaw !== '') {
            if (!is_numeric($pageCountRaw) || floor((float)$pageCountRaw) != (float)$pageCountRaw || (int)$pageCountRaw <= 0) {
                $msg = "Row $rowNum: Page Count must be a valid positive whole number (received \"$pageCountRaw\").";
                $errors[] = $msg;
                $reportRows[] = array_merge($reportBase, ['status' => 'Invalid', 'message' => 'Invalid Page Count.']);
                continue;
            }
        }

        // 2. Duplicate row detection in Excel
        $entryKey = strtoupper($prodCode) . '|||' . strtoupper($variety);
        if (isset($seenEntries[$entryKey])) {
            $prevRowNum = $seenEntries[$entryKey];
            $msg = "Row $rowNum: Duplicate stock entry found for Product Code \"$prodCode\" + Variety \"$variety\" (previously defined in Row $prevRowNum).";
            $errors[] = $msg;
            $reportRows[] = array_merge($reportBase, ['status' => 'Duplicate', 'message' => "Duplicate of row $prevRowNum."]);
            continue;
        }
        $seenEntries[$entryKey] = $rowNum;

        // 3. Verify Product Code exists in Database
        $stmtCheckCode = $db->prepare("
            SELECT p.id, p.name 
            FROM products p 
            LEFT JOIN product_variants pv ON pv.product_id = p.id 
            WHERE pv.product_code = ? OR UPPER(pv.product_code) = ? 
               OR p.sku = ? OR UPPER(p.sku) = ? 
               OR p.id = ? 
            LIMIT 1
        ");
        $stmtCheckCode->execute([
            $prodCode, strtoupper($prodCode),
            $prodCode, strtoupper($prodCode),
            is_numeric($prodCode) ? (int)$prodCode : 0
        ]);
        $prodCheck = $stmtCheckCode->fetch(PDO::FETCH_ASSOC);

        if (!$prodCheck) {
            $msg = "Row $rowNum: Product Code \"$prodCode\" does not exist.";
            $errors[] = $msg;
            $reportRows[] = array_merge($reportBase, ['status' => 'Unmatched Product', 'message' => 'Product Code not found.']);
            continue;
        }

        // 4. Verify Variety exists for this Product
        $matchedVariant = find_matching_variant_record($db, $prodCode, $variety);
        if (!$matchedVariant) {
            $prodDisplayName = !empty($prodCheck['name']) ? $prodCheck['name'] : $prodCode;
            $msg = "Row $rowNum: Variety \"$variety\" does not exist for Product \"$prodDisplayName\" (Code: $prodCode).";
            $errors[] = $msg;
            $reportRows[] = array_merge($reportBase, ['status' => 'Unmatched Variety', 'message' => 'Variety not found for product.']);
            continue;
        }

        // Page count mismatch check
        $matchedPageCount = (int)($matchedVariant['page_count'] ?: ($matchedVariant['prod_pages'] ?? 0));
        if ($pageCountRaw !== '' && $matchedPageCount > 0 && (int)$pageCountRaw !== $matchedPageCount) {
            $msg = "Row $rowNum: Page Count \"$pageCountRaw\" does not match the page count ($matchedPageCount) of the matched product/variety.";
            $errors[] = $msg;
            $reportRows[] = array_merge($reportBase, ['status' => 'Mismatch', 'message' => "Page count mismatch (expected $matchedPageCount)."]);
            continue;
        }

        // Default Pieces Per Bundle from matched variant if not given
        $defaultPpb = max(1, (int)($matchedVariant['bundle_qty'] ?: ($matchedVariant['prod_std_qty'] ?: 10)));
        $newPpb = $defaultPpb;
        if ($ppbRaw !== '') {
            if (!is_numeric($ppbRaw) || (int)$ppbRaw <= 0) {
                $msg = "Row $rowNum: Pieces Per Bundle must be a valid positive whole number (received \"$ppbRaw\").";
                $errors[] = $msg;
                $reportRows[] = array_merge($reportBase, ['status' => 'Invalid', 'message' => 'Invalid Pieces Per Bundle.']);
                continue;
            }
            $newPpb = (int)$ppbRaw;
        }

        // Determine Bundle Stock and Loose Piece Stock
        $newBundleStock = 0;
        $newLooseStock = 0;

        if ($bundleStockRaw !== '') {
            // Admin entered Bundle Stock
            if (!is_numeric($bundleStockRaw) || floor((float)$bundleStockRaw) != (float)$bundleStockRaw) {
                $msg = "Row $rowNum: Bundle Stock must be a valid whole number (received \"$bundleStockRaw\").";
                $errors[] = $msg;
                $reportRows[] = array_merge($reportBase, ['status' => 'Invalid', 'message' => 'Invalid Bundle Stock.']);
                continue;
            }
            $newBundleStock = (int)$bundleStockRaw;
            if ($newBundleStock < 0) {
                $msg = "Row $rowNum: Bundle Stock cannot be negative (received $newBundleStock).";
                $errors[] = $msg;
                $reportRows[] = array_merge($reportBase, ['status' => 'Invalid', 'message' => 'Bundle Stock cannot be negative.']);
                continue;
            }

            // Loose Piece Stock
            if ($looseStockRaw !== '') {
                if (!is_numeric($looseStockRaw) || floor((float)$looseStockRaw) != (float)$looseStockRaw) {
                    $msg = "Row $rowNum: Loose Piece Stock must be a valid whole number (received \"$looseStockRaw\").";
                    $errors[] = $msg;
                    $reportRows[] = array_merge($reportBase, ['status' => 'Invalid', 'message' => 'Invalid Loose Piece Stock.']);
                    continue;
                }
                $newLooseStock = (int)$looseStockRaw;
                if ($newLooseStock < 0) {
                    $msg = "Row $rowNum: Loose Piece Stock cannot be negative (received $newLooseStock).";
                    $errors[] = $msg;
                    $reportRows[] = array_merge($reportBase, ['status' => 'Invalid', 'message' => 'Loose Piece Stock cannot be negative.']);
                    continue;
                }
            } else {
                $newLooseStock = 0;
            }
        } elseif ($legacyStockRaw !== '') {
            // Fallback: If only single stock column was provided, convert into bundles + loose
            if (!is_numeric($legacyStockRaw) || floor((float)$legacyStockRaw) != (float)$legacyStockRaw) {
                $msg = "Row $rowNum: Stock must be a valid whole number (received \"$legacyStockRaw\").";
                $errors[] = $msg;
                $reportRows[] = array_merge($reportBase, ['status' => 'Invalid', 'message' => 'Invalid Stock Quantity.']);
                continue;
            }
            $rawStock = (int)$legacyStockRaw;
            if ($rawStock < 0) {
                $msg = "Row $rowNum: Stock cannot be negative (received $rawStock).";
                $errors[] = $msg;
                $reportRows[] = array_merge($reportBase, ['status' => 'Invalid', 'message' => 'Stock cannot be negative.']);
                continue;
            }
            $newBundleStock = floor($rawStock / $newPpb);
            $newLooseStock = $rawStock % $newPpb;
        } else {
            $msg = "Row $rowNum: Stock or Bundle Stock is missing for Product Code \"$prodCode\" ($variety).";
            $errors[] = $msg;
            $reportRows[] = array_merge($reportBase, ['status' => 'Invalid', 'message' => 'Stock quantity missing.']);
            continue;
        }

        // Normalize inventory so bundles always equal floor(total pieces / pack size).
        $newTotalPieces = ($newBundleStock * $newPpb) + $newLooseStock;
        $stockBreakdown = calculate_stock_breakdown($newTotalPieces, $newPpb);
        $newTotalPieces = $stockBreakdown['total_pieces'];
        $newBundleStock = $stockBreakdown['bundle_stock'];
        $newLooseStock = $stockBreakdown['loose_stock'];
        $oldTotalPieces = (int)$matchedVariant['stock'];

        $validatedRows[] = [
            'row_num' => $rowNum,
            'variant_id' => (int)$matchedVariant['id'],
            'product_id' => (int)$matchedVariant['product_id'],
            'product_code' => $matchedVariant['product_code'],
            'product_name' => $matchedVariant['product_name'],
            'page_count' => $matchedPageCount,
            'variety' => $matchedVariant['variety'],
            'variety_display' => $matchedVariant['variety_display'] ?: $matchedVariant['variety'],
            'old_bundle_stock' => (int)$matchedVariant['bundle_stock'],
            'new_bundle_stock' => $newBundleStock,
            'pieces_per_bundle' => $newPpb,
            'old_loose_stock' => (int)$matchedVariant['loose_stock'],
            'new_loose_stock' => $newLooseStock,
            'old_total_pieces' => $oldTotalPieces,
            'new_total_pieces' => $newTotalPieces,
            'change_pieces' => $newTotalPieces - $oldTotalPieces
        ];

        $reportRows[] = array_merge($reportBase, [
            'status' => 'Ready (Validated)',
            'message' => "Total pieces → $newTotalPieces ($newBundleStock Bndl × $newPpb + $newLooseStock Lse), change: " . ($newTotalPieces - $oldTotalPieces),
            'stock' => $newTotalPieces
        ]);
    }

    // Store full report in session
    $_SESSION['pending_stock_report'] = [
        'timestamp' => time(),
        'rows' => $reportRows
    ];

    // If ANY row failed validation: DO NOT UPDATE, return all errors
    if (!empty($errors)) {
        echo json_encode([
            'success' => false,
            'message' => 'Excel validation failed with ' . count($errors) . ' error(s). Please review and correct your Excel file.',
            'errors' => $errors,
            'report' => $reportRows
        ]);
        exit();
    }

    if (empty($validatedRows)) {
        echo json_encode([
            'success' => false,
            'message' => 'No valid stock rows were found in the uploaded file.',
            'errors' => $errors,
            'report' => $reportRows
        ]);
        exit();
    }

    // Save validated rows in session for commit step
    $_SESSION['pending_stock_update'] = [
        'timestamp' => time(),
        'items' => $validatedRows
    ];

    echo json_encode([
        'success' => true,
        'count' => count($validatedRows),
        'preview' => $validatedRows,
        'report' => $reportRows,
        'message' => count($validatedRows) . ' products/varieties validated and ready to update.'
    ]);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 4) COMMIT STOCK UPDATE (CONFIRMED BY ADMIN)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'commit') {
    header('Content-Type: application/json');

    $items = [];
    if (!empty($_POST['items'])) {
        $decoded = is_array($_POST['items']) ? $_POST['items'] : json_decode($_POST['items'], true);
        if (is_array($decoded)) {
            $items = $decoded;
        }
    }
    if (empty($items) && !empty($_SESSION['pending_stock_update']['items'])) {
        $items = $_SESSION['pending_stock_update']['items'];
    }

    if (empty($items)) {
        echo json_encode([
            'success' => false,
            'message' => 'No validated stock update found in session or request. Please upload your Excel file again.'
        ]);
        exit();
    }

    $admin = get_current_admin();
    $adminUser = !empty($admin['username']) ? $admin['username'] : 'admin';

    $db->beginTransaction();
    try {
        $stmtUpVariant = $db->prepare("
            UPDATE product_variants 
            SET bundle_stock = ?, bundle_qty = ?, loose_stock = ?, stock = ?, updated_at = NOW() 
            WHERE id = ?
        ");
        $stmtLog = $db->prepare("
            INSERT INTO stock_history (product_id, previous_stock, new_stock, change_amount, reason, admin_username) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $affectedProductIds = [];

        foreach ($items as $item) {
            $variantId = (int)$item['variant_id'];
            $productId = (int)$item['product_id'];
            $newB = (int)$item['new_bundle_stock'];
            $newPPB = max(1, (int)$item['pieces_per_bundle']);
            $newL = (int)$item['new_loose_stock'];
            $newTotal = ($newB * $newPPB) + $newL;
            $oldTotal = (int)$item['old_total_pieces'];
            $change = $newTotal - $oldTotal;

            // 1. Update product_variants with bundles, ppb, loose, and total pieces
            $stmtUpVariant->execute([
                $newB,
                $newPPB,
                $newL,
                $newTotal,
                $variantId
            ]);

            // 2. Record in stock_history audit table
            $logReason = "Excel Wholesale Update: " . $item['product_code'] . " [" . $item['variety'] . "] (Bundles: " . $item['old_bundle_stock'] . "→" . $newB . ", Loose: " . $item['old_loose_stock'] . "→" . $newL . ", Total Pieces: " . $oldTotal . "→" . $newTotal . ")";
            $stmtLog->execute([
                $productId,
                $oldTotal,
                $newTotal,
                $change,
                $logReason,
                $adminUser
            ]);

            $affectedProductIds[$productId] = true;
        }

        // 3. Sync parent products bundle_stock and stock
        $stmtSyncProd = $db->prepare("
            UPDATE products 
            SET bundle_stock = (SELECT COALESCE(SUM(bundle_stock), 0) FROM product_variants WHERE product_id = ?),
                stock = (SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = ?) 
            WHERE id = ?
        ");
        foreach (array_keys($affectedProductIds) as $pid) {
            $stmtSyncProd->execute([$pid, $pid, $pid]);
        }

        $db->commit();
        unset($_SESSION['pending_stock_update']);

        unset($_SESSION['pending_stock_report']);
        set_flash('success', "Successfully updated wholesale stock for " . count($items) . " products/varieties via Excel!");

        echo json_encode([
            'success' => true,
            'count' => count($items),
            'message' => 'Wholesale stock updated successfully for ' . count($items) . ' products/varieties.'
        ]);
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Database error during stock update: ' . $e->getMessage()
        ]);
        exit();
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 5) CANCEL PREVIEW
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'cancel') {
    header('Content-Type: application/json');
    unset($_SESSION['pending_stock_update']);
    unset($_SESSION['pending_stock_report']);
    echo json_encode(['success' => true, 'message' => 'Stock update preview cancelled.']);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 6) DOWNLOAD IMPORT REPORT (ERROR/SUCCESS SUMMARY)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'report') {
    $rows = [];
    if (!empty($_SESSION['pending_stock_report']['rows'])) {
        $rows = $_SESSION['pending_stock_report']['rows'];
    }
    $filename = 'rajpriya_stock_import_report_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Row #', 'Product Code', 'Product Name', 'Page Count', 'Variety', 'Stock Quantity', 'Status', 'Details']);
    foreach ($rows as $r) {
        fputcsv($output, [
            isset($r['row']) ? $r['row'] : '',
            isset($r['code']) ? $r['code'] : '',
            isset($r['name']) ? $r['name'] : '',
            isset($r['page_count']) ? $r['page_count'] : '',
            isset($r['variety']) ? $r['variety'] : '',
            isset($r['stock']) ? $r['stock'] : '',
            isset($r['status']) ? $r['status'] : '',
            isset($r['message']) ? $r['message'] : ''
        ]);
    }
    fclose($output);
    exit();
}

// Fallback
header('Location: stock.php');
exit();
