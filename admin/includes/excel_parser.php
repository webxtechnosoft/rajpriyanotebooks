<?php
/**
 * Excel & CSV Import/Update Parser Helper for Rajpriya Notebooks
 */

require_once __DIR__ . '/../../includes/functions.php';

function parse_excel_or_csv_data($filePath) {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    if ($ext === 'csv' || $ext === 'txt') {
        return parse_csv_file_data($filePath);
    } elseif ($ext === 'xlsx') {
        return parse_xlsx_file_data($filePath);
    } else {
        $rows = parse_csv_file_data($filePath);
        if (empty($rows)) {
            $rows = parse_xlsx_file_data($filePath);
        }
        return $rows;
    }
}

function parse_csv_file_data($filePath) {
    $handle = @fopen($filePath, 'r');
    if (!$handle) return [];

    $rows = [];
    $delimiter = ',';
    
    $firstLine = fgets($handle);
    if ($firstLine !== false) {
        $hasBom = strncmp($firstLine, "\xEF\xBB\xBF", 3) === 0;
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
        if (substr_count($firstLine, "\t") > substr_count($firstLine, ",")) {
            $delimiter = "\t";
        } elseif (substr_count($firstLine, ";") > substr_count($firstLine, ",")) {
            $delimiter = ";";
        }
        if ($hasBom) {
            fseek($handle, 3, SEEK_SET);
        } else {
            rewind($handle);
        }
    }

    while (($data = fgetcsv($handle, 4096, $delimiter)) !== false) {
        if (isset($data[0])) {
            $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', $data[0]);
        }
        $rows[] = array_map('trim', $data);
    }
    fclose($handle);
    return $rows;
}

function parse_xlsx_file_data($filePath) {
    if (!class_exists('ZipArchive')) {
        return [];
    }
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== TRUE) {
        return [];
    }

    $sharedStrings = [];
    $sharedStringsContent = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsContent !== false) {
        $xml = @simplexml_load_string($sharedStringsContent);
        if ($xml && isset($xml->si)) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string)$si->t;
                } elseif (isset($si->r)) {
                    $str = '';
                    foreach ($si->r as $r) {
                        $str .= (string)$r->t;
                    }
                    $sharedStrings[] = $str;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    $sheetContent = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetContent === false) {
        $sheetContent = $zip->getFromName('xl/worksheets/sheet.xml');
    }
    $zip->close();

    if ($sheetContent === false) {
        return [];
    }

    $xml = @simplexml_load_string($sheetContent);
    if (!$xml || !isset($xml->sheetData)) {
        return [];
    }

    $rows = [];
    foreach ($xml->sheetData->row as $rowNode) {
        $rowCells = [];
        $maxColIndex = 0;

        foreach ($rowNode->c as $cell) {
            $cellRef = (string)$cell['r'];
            $colLetter = preg_replace('/[0-9]/', '', $cellRef);
            $colIndex = colLetterToIndexHelper($colLetter);

            $val = '';
            $cellType = (string)$cell['t'];

            if ($cellType === 's') {
                $ssIndex = (int)$cell->v;
                $val = isset($sharedStrings[$ssIndex]) ? $sharedStrings[$ssIndex] : '';
            } elseif ($cellType === 'inlineStr') {
                $val = (string)$cell->is->t;
            } else {
                $val = (string)$cell->v;
            }

            $rowCells[$colIndex] = trim($val);
            if ($colIndex > $maxColIndex) {
                $maxColIndex = $colIndex;
            }
        }

        $denseRow = [];
        for ($i = 0; $i <= $maxColIndex; $i++) {
            $denseRow[$i] = isset($rowCells[$i]) ? $rowCells[$i] : '';
        }
        $rows[] = $denseRow;
    }

    return $rows;
}

function colLetterToIndexHelper($col) {
    $col = strtoupper($col);
    $len = strlen($col);
    $index = 0;
    for ($i = 0; $i < $len; $i++) {
        $index = $index * 26 + (ord($col[$i]) - ord('A') + 1);
    }
    return $index - 1;
}

function map_excel_header_key($header) {
    $clean = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $header)));
    
    $mappings = [
        'id' => 'id',
        'product_id' => 'id',
        'prod_id' => 'id',
        'code' => 'sku',
        'product_code' => 'sku',
        'sku' => 'sku',
        'sku_code' => 'sku',
        'name' => 'name',
        'product' => 'name',
        'product_name' => 'name',
        'title' => 'name',
        'brand' => 'brand_name',
        'brand_name' => 'brand_name',
        'category' => 'category_name',
        'category_name' => 'category_name',
        'category_id' => 'category_id',
        'price' => 'price',
        'rate' => 'price',
        'rate_price' => 'price',
        'product_price' => 'price',
        'mrp' => 'mrp',
        'sale_price' => 'sale_price',
        'selling_price_unruled' => 'selling_price_unruled',
        'discounted_price_unruled' => 'discounted_price_unruled',
        'selling_price_ruled' => 'selling_price_ruled',
        'discounted_price_ruled' => 'discounted_price_ruled',
        'variant_id' => 'variant_id',
        'variant_code' => 'variant_code',
        'product_variant_code' => 'variant_code',
        'variety_code' => 'variety',
        'variety' => 'variety_display',
        'variant_page_count' => 'variant_page_count',
        'variant_bundle_qty' => 'variant_bundle_qty',
        'variant_bundle_stock' => 'variant_bundle_stock',
        'variant_loose_stock' => 'variant_loose_stock',
        'variant_stock' => 'variant_stock',
        'variant_mrp' => 'variant_mrp',
        'variant_regular_price' => 'variant_price',
        'variant_price' => 'variant_price',
        'variant_sale_price' => 'variant_sale_price',
        'variant_gst_rate' => 'variant_gst_rate',
        'variant_selling_price' => 'variant_selling_price',
        'variant_discounted_price' => 'variant_discounted_price',
        'stock' => 'stock',
        'available_stock' => 'stock',
        'quantity' => 'stock',
        'pages' => 'pages',
        'page' => 'pages',
        'page_count' => 'pages',
        'std_qty' => 'std_qty',
        'pack_qty' => 'std_qty',
        'pack_quantity' => 'std_qty',
        'bundle_qty' => 'std_qty',
        'size' => 'size',
        'paper_type' => 'paper_type',
        'gsm' => 'gsm',
        'binding' => 'binding',
        'cover_type' => 'cover_type',
        'description' => 'description',
        'desc' => 'description',
        'gst_rate' => 'gst_rate',
        'manual_verification_flag' => 'manual_verification_flag',
        'manual_verification_notes' => 'manual_verification_notes',
        'featured' => 'featured',
        'bestseller' => 'bestseller',
        'new_arrival' => 'new_arrival',
        'active' => 'active'
    ];

    return isset($mappings[$clean]) ? $mappings[$clean] : $clean;
}

function analyze_excel_import($db, $rawRows) {
    if (empty($rawRows) || count($rawRows) < 2) {
        return [
            'success' => false,
            'message' => 'The uploaded file is empty or missing data rows.'
        ];
    }

    $headers = array_shift($rawRows);
    $fieldMap = [];
    foreach ($headers as $index => $hName) {
        $fieldMap[$index] = map_excel_header_key($hName);
    }

    // Fetch existing categories for mapping category names to IDs
    $stmtCats = $db->query("SELECT id, name FROM categories");
    $catMap = [];
    while ($c = $stmtCats->fetch()) {
        $catMap[strtolower(trim($c['name']))] = (int)$c['id'];
    }
    $defaultCatId = !empty($catMap) ? reset($catMap) : 1;

    $analyzedItems = [];
    $seenIdentifiers = [];
    $updatedCount = 0;
    $newCount = 0;
    $unchangedCount = 0;
    $errorCount = 0;

    foreach ($rawRows as $rowIndex => $rowValues) {
        $rowNum = $rowIndex + 2; // Accounting for 1-based indexing + header row
        
        // Skip completely empty rows
        $nonEmptyValues = array_filter($rowValues, function($val) {
            return trim($val) !== '';
        });
        if (empty($nonEmptyValues)) {
            continue;
        }

        $rowObject = [];
        foreach ($fieldMap as $colIdx => $fieldName) {
            $rowObject[$fieldName] = isset($rowValues[$colIdx]) ? trim($rowValues[$colIdx]) : '';
        }

        $rowErrors = [];

        // Existing products must be identified by a stable ID or SKU. Names
        // are display data and are never a safe update key.
        $productId = isset($rowObject['id']) && is_numeric($rowObject['id']) && (int)$rowObject['id'] > 0 ? (int)$rowObject['id'] : 0;
        $sku = isset($rowObject['sku']) ? trim($rowObject['sku']) : '';
        $name = isset($rowObject['name']) ? trim($rowObject['name']) : '';

        if ($productId <= 0 && $sku === '') {
            $rowErrors[] = "Row {$rowNum}: Product ID or SKU is required to update an existing product.";
        }

        // Check for duplicate identifier in same file
        $identifierKey = $productId > 0 ? ("ID:" . $productId) : ($sku !== '' ? ("SKU:" . strtolower($sku)) : '');
        if (!empty($identifierKey) && isset($seenIdentifiers[$identifierKey])) {
            $rowErrors[] = "Row {$rowNum}: Duplicate product identifier ('{$identifierKey}') already appears in Row " . $seenIdentifiers[$identifierKey];
        } else if (!empty($identifierKey)) {
            $seenIdentifiers[$identifierKey] = $rowNum;
        }

        // Validate numeric fields if provided
        $decimalFields = ['price', 'mrp', 'sale_price', 'selling_price_unruled',
            'discounted_price_unruled', 'selling_price_ruled', 'discounted_price_ruled', 'gst_rate'];
        foreach ($decimalFields as $field) {
            if (isset($rowObject[$field]) && $rowObject[$field] !== ''
                && (!is_numeric($rowObject[$field]) || (float)$rowObject[$field] < 0)) {
                $rowErrors[] = "Row {$rowNum}: Invalid {$field} value ('{$rowObject[$field]}')";
            }
        }
        foreach (['stock', 'pages', 'std_qty', 'gsm', 'category_id'] as $field) {
            if (isset($rowObject[$field]) && $rowObject[$field] !== ''
                && (!ctype_digit((string)$rowObject[$field]) || (int)$rowObject[$field] < 0)) {
                $rowErrors[] = "Row {$rowNum}: Invalid {$field} value ('{$rowObject[$field]}')";
            }
        }

        // Search DB for existing product
        $matchedProduct = null;
        if ($productId > 0) {
            $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([$productId]);
            $matchedProduct = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$matchedProduct && !empty($sku)) {
            $stmt = $db->prepare("SELECT * FROM products WHERE sku = ?");
            $stmt->execute([$sku]);
            $matchedProduct = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        // Deliberately no name fallback: duplicate names are valid in this catalogue.

        if (isset($rowObject['variants_json']) && $rowObject['variants_json'] !== '') {
            $decodedVariants = json_decode($rowObject['variants_json'], true);
            if (!is_array($decodedVariants)) {
                $rowErrors[] = "Row {$rowNum}: Variants JSON is invalid.";
            } else {
                $rowObject['variants'] = $decodedVariants;
            }
        }

        // Excel users commonly enter Yes/No for boolean fields; normalize
        // before comparison/update while still treating blank as unchanged.
        foreach (['manual_verification_flag', 'featured', 'bestseller', 'new_arrival', 'active'] as $booleanField) {
            if (isset($rowObject[$booleanField]) && $rowObject[$booleanField] !== '') {
                $rowObject[$booleanField] = in_array(strtolower($rowObject[$booleanField]), ['1', 'yes', 'true'], true) ? 1 : 0;
            }
        }

        if ($matchedProduct) {
            // Existing Product -> UPDATE ONLY CHANGED DATA
            $diffs = [];
            $updatePayload = ['id' => $matchedProduct['id']];

            $fieldsToCompare = [
                'name' => 'Name',
                'sku' => 'SKU',
                'brand_name' => 'Brand',
                'description' => 'Description',
                'price' => 'Price / Rate',
                'mrp' => 'MRP',
                'sale_price' => 'Sale Price',
                'selling_price_unruled' => 'Selling Price Unruled',
                'discounted_price_unruled' => 'Discounted Price Unruled',
                'selling_price_ruled' => 'Selling Price Ruled',
                'discounted_price_ruled' => 'Discounted Price Ruled',
                'ruling_option' => 'Ruling / Variant',
                'stock' => 'Stock',
                'pages' => 'Pages',
                'std_qty' => 'Pack Qty',
                'size' => 'Size',
                'paper_type' => 'Paper Type',
                'gsm' => 'GSM',
                'binding' => 'Binding',
                'cover_type' => 'Cover Type'
            ];

            $fieldsToCompare += [
                'gst_rate' => 'GST Rate',
                'manual_verification_flag' => 'Manual Verification Flag',
                'manual_verification_notes' => 'Manual Verification Notes',
                'featured' => 'Featured',
                'bestseller' => 'Bestseller',
                'new_arrival' => 'New Arrival',
                'active' => 'Active'
            ];

            // Category mapping
            if (!empty($rowObject['category_name'])) {
                $catKey = strtolower(trim($rowObject['category_name']));
                if (isset($catMap[$catKey])) {
                    $rowObject['category_id'] = $catMap[$catKey];
                }
            }

            if (isset($rowObject['category_id']) && $rowObject['category_id'] !== '') {
                $fieldsToCompare['category_id'] = 'Category ID';
            }

            foreach ($fieldsToCompare as $fieldKey => $fieldLabel) {
                if (array_key_exists($fieldKey, $rowObject) && $rowObject[$fieldKey] !== '') {
                    $newVal = $rowObject[$fieldKey];
                    $oldVal = isset($matchedProduct[$fieldKey]) ? $matchedProduct[$fieldKey] : '';

                    // Normalize numeric comparisons
                    if (in_array($fieldKey, ['price', 'mrp', 'sale_price', 'selling_price_unruled', 'discounted_price_unruled', 'selling_price_ruled', 'discounted_price_ruled'])) {
                        $isDiff = (float)$newVal != (float)$oldVal;
                        $formattedOld = '₹' . number_format((float)$oldVal, 2);
                        $formattedNew = '₹' . number_format((float)$newVal, 2);
                    } elseif (in_array($fieldKey, ['stock', 'pages', 'std_qty', 'gsm', 'category_id', 'manual_verification_flag', 'featured', 'bestseller', 'new_arrival', 'active'])) {
                        $isDiff = (int)$newVal != (int)$oldVal;
                        $formattedOld = (string)$oldVal;
                        $formattedNew = (string)$newVal;
                    } else {
                        $isDiff = trim((string)$newVal) !== trim((string)$oldVal);
                        $formattedOld = (string)$oldVal;
                        $formattedNew = (string)$newVal;
                    }

                    if ($isDiff) {
                        $diffs[$fieldKey] = [
                            'label' => $fieldLabel,
                            'old' => $formattedOld,
                            'new' => $formattedNew,
                            'raw' => $newVal
                        ];
                        $updatePayload[$fieldKey] = $newVal;
                    }
                }
            }

            if (isset($rowObject['variants'])) {
                foreach ($rowObject['variants'] as $variant) {
                    if (!is_array($variant) || (int)($variant['id'] ?? 0) <= 0) {
                        $rowErrors[] = "Row {$rowNum}: Every imported variant must include its existing variant ID.";
                        break;
                    }
                }
                if (empty($rowErrors)) {
                    $updatePayload['variants'] = $rowObject['variants'];
                    $diffs['variants'] = ['label' => 'Variants', 'old' => 'Existing variant rows', 'new' => 'Imported variant rows', 'raw' => ''];
                }
            }

            $action = !empty($rowErrors) ? 'error' : (!empty($diffs) ? 'update' : 'unchanged');
            if ($action === 'update') $updatedCount++;
            elseif ($action === 'unchanged') $unchangedCount++;
            elseif ($action === 'error') $errorCount++;

            $analyzedItems[] = [
                'row_num' => $rowNum,
                'action' => $action,
                'matched_id' => $matchedProduct['id'],
                'product_name' => $matchedProduct['name'],
                'sku' => $matchedProduct['sku'],
                'diffs' => $diffs,
                'update_payload' => $updatePayload,
                'errors' => $rowErrors
            ];

        } else {
            $rowErrors[] = "Row {$rowNum}: Existing product was not found by Product ID or SKU; no new product will be created.";
            $errorCount++;
            $analyzedItems[] = [
                'row_num' => $rowNum, 'action' => 'error', 'matched_id' => 0,
                'product_name' => $name, 'sku' => $sku, 'errors' => $rowErrors
            ];
        }
    }

    $token = md5(uniqid('excel_import_', true));
    $_SESSION['excel_import_preview_' . $token] = $analyzedItems;

    return [
        'success' => true,
        'token' => $token,
        'total' => count($analyzedItems),
        'updated_count' => $updatedCount,
        'new_count' => $newCount,
        'unchanged_count' => $unchangedCount,
        'error_count' => $errorCount,
        'items' => $analyzedItems
    ];
}

function execute_excel_import($db, $token) {
    $sessionKey = 'excel_import_preview_' . $token;
    if (empty($_SESSION[$sessionKey])) {
        return [
            'success' => false,
            'message' => 'Import session expired or invalid. Please re-upload the Excel file.'
        ];
    }

    $items = $_SESSION[$sessionKey];
    $updatedCount = 0;
    $insertedCount = 0;
    $skippedCount = 0;
    $failedCount = 0;

    $db->beginTransaction();
    try {
        foreach ($items as $item) {
            if ($item['action'] === 'error') {
                $failedCount++;
                continue;
            }

            if ($item['action'] === 'update' && !empty($item['update_payload'])) {
                $payload = $item['update_payload'];
                $id = (int)$payload['id'];
                $variants = $payload['variants'] ?? null;
                unset($payload['id']);
                unset($payload['variants']);

                // Keep the SQL column list closed to fields supported by the
                // existing Product Management form.
                $allowedProductFields = [
                    'name', 'sku', 'category_id', 'brand_name', 'description',
                    'price', 'mrp', 'sale_price', 'selling_price_unruled',
                    'discounted_price_unruled', 'selling_price_ruled',
                    'discounted_price_ruled', 'ruling_option', 'stock', 'pages',
                    'std_qty', 'size', 'paper_type', 'gsm', 'binding',
                    'cover_type', 'gst_rate', 'manual_verification_flag',
                    'manual_verification_notes', 'featured', 'bestseller',
                    'new_arrival', 'active'
                ];
                $payload = array_intersect_key($payload, array_flip($allowedProductFields));

                if (!empty($payload)) {
                    $setClauses = [];
                    $params = [];
                    foreach ($payload as $col => $val) {
                        $setClauses[] = "`{$col}` = ?";
                        $params[] = $val;
                    }
                    $params[] = $id;

                    $sql = "UPDATE products SET " . implode(', ', $setClauses) . " WHERE id = ?";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                }

                if (is_array($variants)) {
                    // Reuse the same validated, ID-preserving code path as the
                    // Admin Product Management variety editor.
                    $productStmt = $db->prepare("SELECT sku, pages, std_qty, stock FROM products WHERE id = ? FOR UPDATE");
                    $productStmt->execute([$id]);
                    $product = $productStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$product) {
                        throw new RuntimeException("Product #{$id} no longer exists.");
                    }
                    save_product_variant_records(
                        $db,
                        $id,
                        $product['sku'],
                        (int)$product['pages'],
                        (int)$product['std_qty'],
                        (int)$product['stock'],
                        $variants
                    );
                }
                $updatedCount++;
            } elseif ($item['action'] === 'new' && !empty($item['insert_payload'])) {
                $payload = $item['insert_payload'];
                $payload['slug'] = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $payload['name'])));
                
                // Ensure unique slug
                $stmtSlug = $db->prepare("SELECT COUNT(*) FROM products WHERE slug = ?");
                $stmtSlug->execute([$payload['slug']]);
                if ($stmtSlug->fetchColumn() > 0) {
                    $payload['slug'] .= '-' . rand(100, 999);
                }

                $cols = array_keys($payload);
                $placeholders = array_fill(0, count($cols), '?');

                $sql = "INSERT INTO products (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $db->prepare($sql);
                $stmt->execute(array_values($payload));
                $insertedCount++;
            } else {
                $skippedCount++;
            }
        }

        $db->commit();
        unset($_SESSION[$sessionKey]);

        return [
            'success' => true,
            'updated' => $updatedCount,
            'inserted' => $insertedCount,
            'skipped' => $skippedCount,
            'failed' => $failedCount,
            'message' => "Excel import completed. Updated: {$updatedCount}, New: {$insertedCount}, Skipped: {$skippedCount}, Failed: {$failedCount}."
        ];
    } catch (Exception $e) {
        $db->rollBack();
        return [
            'success' => false,
            'message' => 'Database error during import: ' . $e->getMessage()
        ];
    }
}

/**
 * Flat product/variant import used by the client-facing export format.
 * One spreadsheet row represents one product variant, either existing or new.
 */
function analyze_flat_product_import($db, $rawRows) {
    if (empty($rawRows) || count($rawRows) < 2) {
        return ['success' => false, 'message' => 'The uploaded file is empty or missing data rows.'];
    }

    $headers = array_shift($rawRows);
    $fieldMap = [];
    foreach ($headers as $index => $header) {
        $fieldMap[$index] = map_excel_header_key($header);
    }

    $hasParentId = in_array('id', $fieldMap, true);
    $hasParentSku = in_array('sku', $fieldMap, true);
    if (!$hasParentId && !$hasParentSku) {
        return ['success' => false, 'message' => 'The file must contain Product ID or SKU.'];
    }

    $categoryMap = [];
    foreach ($db->query('SELECT id, name FROM categories')->fetchAll(PDO::FETCH_ASSOC) as $category) {
        $categoryMap[strtolower(trim($category['name']))] = (int)$category['id'];
    }
    $seen = [];
    $seenNewSkus = [];
    $seenNewVariantCodes = [];
    $seenNewVariantDetails = [];
    $items = [];
    $updated = 0;
    $newCount = 0;
    $unchanged = 0;
    $failed = 0;

    $parentFields = [
        'name', 'sku', 'brand_name', 'description', 'price', 'mrp', 'sale_price',
        'selling_price_unruled', 'discounted_price_unruled', 'selling_price_ruled',
        'discounted_price_ruled', 'size', 'paper_type', 'gsm', 'binding',
        'cover_type', 'gst_rate', 'manual_verification_flag',
        'manual_verification_notes', 'featured', 'bestseller', 'new_arrival', 'active'
    ];
    $decimalFields = [
        'price', 'mrp', 'sale_price', 'selling_price_unruled',
        'discounted_price_unruled', 'selling_price_ruled',
        'discounted_price_ruled', 'gst_rate', 'variant_mrp', 'variant_price',
        'variant_sale_price', 'variant_gst_rate', 'variant_selling_price',
        'variant_discounted_price'
    ];
    $integerFields = [
        'gsm', 'variant_id', 'variant_page_count', 'variant_bundle_qty',
        'variant_bundle_stock', 'variant_loose_stock', 'variant_stock'
    ];

    foreach ($rawRows as $rowIndex => $values) {
        $rowNumber = $rowIndex + 2;
        if (empty(array_filter($values, static function ($value) { return trim((string)$value) !== ''; }))) {
            continue;
        }

        $row = [];
        foreach ($fieldMap as $column => $field) {
            $row[$field] = isset($values[$column]) ? trim((string)$values[$column]) : '';
        }
        $errors = [];
        $rawProductId = trim((string)($row['id'] ?? ''));
        // Excel may round-trip integer IDs as "123.0". Accept only whole numeric IDs.
        $productId = ($rawProductId !== '' && is_numeric($rawProductId)
            && (float)$rawProductId > 0 && floor((float)$rawProductId) === (float)$rawProductId)
            ? (int)$rawProductId : 0;
        $sku = trim((string)($row['sku'] ?? ''));
        if ($productId <= 0 && $sku === '') {
            $errors[] = "Row {$rowNumber}: Product ID or SKU is required.";
        }

        $parent = null;
        if ($productId > 0) {
            $stmt = $db->prepare('SELECT * FROM products WHERE id = ?');
            $stmt->execute([$productId]);
            $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$parent && $sku !== '') {
            // SKU matching is case/whitespace tolerant because Excel edits often alter casing.
            $stmt = $db->prepare('SELECT * FROM products WHERE LOWER(TRIM(sku)) = LOWER(TRIM(?)) LIMIT 1');
            $stmt->execute([$sku]);
            $parent = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        $matchedByVariantCode = false;
        if (!$parent && $sku !== '') {
            $stmt = $db->prepare('SELECT p.* FROM products p INNER JOIN product_variants pv ON pv.product_id = p.id WHERE LOWER(TRIM(pv.product_code)) = LOWER(TRIM(?)) LIMIT 1');
            $stmt->execute([$sku]);
            $parent = $stmt->fetch(PDO::FETCH_ASSOC);
            $matchedByVariantCode = (bool)$parent;
        }
        if ($parent && $sku !== '' && strcasecmp(trim((string)($parent['sku'] ?? '')), $sku) !== 0) {
            $codeCheck = $db->prepare('SELECT product_code FROM product_variants WHERE product_id = ? AND LOWER(TRIM(product_code)) = LOWER(TRIM(?)) LIMIT 1');
            $codeCheck->execute([(int)$parent['id'], $sku]);
            if ($codeCheck->fetchColumn()) {
                $matchedByVariantCode = true;
                if (trim((string)($row['variant_code'] ?? '')) === '') {
                    $row['variant_code'] = $sku;
                }
            }
        }
        $isNewProduct = !$parent && $sku !== ''
            && trim((string)($row['name'] ?? '')) !== ''
            && trim((string)($row['category_name'] ?? '')) !== '';
        $isFirstNewSku = false;
        if ($isNewProduct) {
            $newSkuKey = strtolower(trim($sku));
            $newVariantKey = $newSkuKey . '|' . strtolower(trim((string)($row['variety'] ?? '')));
            if (isset($seenNewSkus[$newSkuKey])) {
                if (isset($seenNewVariantCodes[$newVariantKey])) {
                    $errors[] = "Row {$rowNumber}: Duplicate variety code for new SKU '{$sku}'.";
                }
            } else {
                $seenNewSkus[$newSkuKey] = $rowNumber;
                $isFirstNewSku = true;
            }
            $seenNewVariantCodes[$newVariantKey] = $rowNumber;
            if (trim((string)($row['price'] ?? '')) === '' || !is_numeric($row['price']) || (float)$row['price'] < 0) {
                $errors[] = "Row {$rowNumber}: A valid Rate / Price is required for a new product.";
            }
        }
        if (!$parent && !$isNewProduct) {
            $errors[] = "Row {$rowNumber}: Existing product was not found by Product ID or SKU. To create a new product, provide Product Name, a valid Category Name, SKU, and variant details.";
        }

        foreach ($decimalFields as $field) {
            if (($row[$field] ?? '') !== '' && (!is_numeric($row[$field]) || (float)$row[$field] < 0)) {
                $errors[] = "Row {$rowNumber}: Invalid {$field} value.";
            }
        }
        if (($row['variant_gst_rate'] ?? '') !== '' && (float)$row['variant_gst_rate'] > 100) {
            $errors[] = "Row {$rowNumber}: Variant GST Rate must be between 0 and 100.";
        }
        foreach ($integerFields as $field) {
            if (($row[$field] ?? '') !== '' && (!ctype_digit($row[$field]) || (int)$row[$field] < 0)) {
                $errors[] = "Row {$rowNumber}: Invalid {$field} value.";
            }
        }

        $variant = null;
        $isNewVariant = false;
        if ($parent) {
            $variantId = (int)($row['variant_id'] ?? 0);
            $variantCode = trim($row['variant_code'] ?? '');
            if ($variantCode === '' && $matchedByVariantCode) {
                $variantCode = $sku;
                $row['variant_code'] = $variantCode;
            }
            if ($variantId > 0) {
                $stmt = $db->prepare('SELECT * FROM product_variants WHERE id = ? AND product_id = ?');
                $stmt->execute([$variantId, $parent['id']]);
                $variant = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$variant && $variantCode !== '') {
                $stmt = $db->prepare('SELECT * FROM product_variants WHERE product_id = ? AND LOWER(TRIM(product_code)) = LOWER(TRIM(?)) LIMIT 1');
                $stmt->execute([$parent['id'], $variantCode]);
                $variant = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            if (!$variant && !$isNewProduct && ($variantId > 0 || $variantCode !== ''
                || trim((string)($row['variety'] ?? '')) !== ''
                || trim((string)($row['variety_display'] ?? '')) !== '')) {
                $isNewVariant = true;
                if ($variantCode === '') {
                    $errors[] = "Row {$rowNumber}: Variant Code is required to create a variant for an existing product.";
                } elseif (strlen($variantCode) > 100) {
                    $errors[] = "Row {$rowNumber}: Variant Code must be 100 characters or fewer.";
                }
                if (trim((string)($row['variety'] ?? '')) === '') {
                    $errors[] = "Row {$rowNumber}: Variety Code is required to create a variant for an existing product.";
                } elseif (strlen(trim((string)$row['variety'])) > 40
                    || !preg_match('/^[A-Z0-9_-]+$/i', trim((string)$row['variety']))) {
                    $errors[] = "Row {$rowNumber}: Variety Code may contain only letters, numbers, hyphens, and underscores, up to 40 characters.";
                }
                if (trim((string)($row['variety_display'] ?? '')) === '') {
                    $errors[] = "Row {$rowNumber}: Variety display name is required to create a variant for an existing product.";
                } elseif (strlen(trim((string)$row['variety_display'])) > 100) {
                    $errors[] = "Row {$rowNumber}: Variety display name must be 100 characters or fewer.";
                }
                if ($variantCode !== '' && trim((string)($row['variety'] ?? '')) !== ''
                    && trim((string)($row['variety_display'] ?? '')) !== '') {
                    $varietyKey = strtolower(trim((string)$row['variety']));
                    $displayKey = strtolower(trim((string)$row['variety_display']));
                    $detailKey = (int)$parent['id'] . '|' . $varietyKey;
                    if (isset($seenNewVariantDetails[$detailKey])) {
                        $errors[] = "Row {$rowNumber}: Duplicate new Variety Code; first seen on row {$seenNewVariantDetails[$detailKey]}.";
                    } else {
                        $seenNewVariantDetails[$detailKey] = $rowNumber;
                    }
                    $detailKey = (int)$parent['id'] . '|display|' . $displayKey;
                    if (isset($seenNewVariantDetails[$detailKey])) {
                        $errors[] = "Row {$rowNumber}: Duplicate new variety display name; first seen on row {$seenNewVariantDetails[$detailKey]}.";
                    } else {
                        $seenNewVariantDetails[$detailKey] = $rowNumber;
                    }

                    $siblingStmt = $db->prepare('SELECT id FROM product_variants WHERE product_id = ? AND (LOWER(TRIM(variety)) = LOWER(TRIM(?)) OR LOWER(TRIM(variety_display)) = LOWER(TRIM(?))) LIMIT 1');
                    $siblingStmt->execute([(int)$parent['id'], $row['variety'], $row['variety_display']]);
                    if ($siblingStmt->fetchColumn()) {
                        $errors[] = "Row {$rowNumber}: Variety Code or display name already exists for this product.";
                    }
                }
            }
            if ($isNewProduct && $variantCode === '') {
                $errors[] = "Row {$rowNumber}: Variant Code is required for a new product.";
            }
            if ($isNewProduct && trim((string)($row['variety'] ?? '')) === '') {
                $errors[] = "Row {$rowNumber}: Variety Code is required for a new product.";
            }
            if ($isNewProduct && trim((string)($row['variety_display'] ?? '')) === '') {
                $errors[] = "Row {$rowNumber}: Variety display name is required for a new product.";
            }
        }

        $identifier = $parent && $variant
            ? ('V:' . $variant['id'])
            : ($parent && $isNewVariant && $variantCode !== ''
                ? ('N:' . $parent['id'] . ':' . strtolower($variantCode))
                : ('R:' . $rowNumber));
        if (isset($seen[$identifier])) {
            $errors[] = "Row {$rowNumber}: Duplicate variant identifier; first seen on row {$seen[$identifier]}.";
        } else {
            $seen[$identifier] = $rowNumber;
        }

        $parentPayload = [];
        $parentDiffs = [];
        $parentFieldLabels = [
            'name' => 'Product Name',
            'sku' => 'SKU',
            'brand_name' => 'Brand',
            'description' => 'Description',
            'price' => 'Rate / Price',
            'mrp' => 'MRP',
            'sale_price' => 'Sale Price',
            'selling_price_unruled' => 'Selling Price Unruled',
            'discounted_price_unruled' => 'Discounted Price Unruled',
            'selling_price_ruled' => 'Selling Price Ruled',
            'discounted_price_ruled' => 'Discounted Price Ruled',
            'size' => 'Size',
            'paper_type' => 'Paper Type',
            'gsm' => 'GSM',
            'binding' => 'Binding',
            'cover_type' => 'Cover Type',
            'gst_rate' => 'GST Rate',
            'manual_verification_flag' => 'Manual Verification',
            'manual_verification_notes' => 'Manual Verification Notes',
            'featured' => 'Featured',
            'bestseller' => 'Bestseller',
            'new_arrival' => 'New Arrival',
            'active' => 'Active'
        ];
        $parentDecimalFields = [
            'price', 'mrp', 'sale_price', 'selling_price_unruled',
            'discounted_price_unruled', 'selling_price_ruled',
            'discounted_price_ruled', 'gst_rate'
        ];
        $parentIntegerFields = [
            'gsm', 'manual_verification_flag', 'featured', 'bestseller',
            'new_arrival', 'active'
        ];
        if (!empty($row['category_name'])) {
            $categoryKey = strtolower(trim($row['category_name']));
            if (isset($categoryMap[$categoryKey])) {
                $row['category_id'] = (string)$categoryMap[$categoryKey];
            } else {
                $errors[] = "Row {$rowNumber}: Category was not found.";
            }
        }
        if ($parent || $isNewProduct) {
            foreach ($parentFields as $field) {
                if ($matchedByVariantCode && $field === 'sku') {
                    continue;
                }
                if (array_key_exists($field, $row) && $row[$field] !== '') {
                    $value = $row[$field];
                    if (in_array($field, ['featured', 'bestseller', 'new_arrival', 'active', 'manual_verification_flag'], true)) {
                        $value = in_array(strtolower($value), ['1', 'yes', 'true'], true) ? 1 : 0;
                    }
                    $isChanged = !$parent || !array_key_exists($field, $parent);
                    if ($parent && array_key_exists($field, $parent)) {
                        if (in_array($field, $parentDecimalFields, true)) {
                            $isChanged = (float)$value != (float)$parent[$field];
                        } elseif (in_array($field, $parentIntegerFields, true)) {
                            $isChanged = (int)$value !== (int)$parent[$field];
                        } else {
                            $isChanged = trim((string)$value) !== trim((string)$parent[$field]);
                        }
                    }
                    if ($isChanged) {
                        $parentPayload[$field] = $value;
                        $parentDiffs[$field] = [
                            'label' => $parentFieldLabels[$field] ?? $field,
                            'old' => $parent[$field] ?? '',
                            'new' => $value
                        ];
                    }
                }
            }
            if (($row['category_id'] ?? '') !== '') {
                $categoryId = (int)$row['category_id'];
                if (!$parent || (int)($parent['category_id'] ?? 0) !== $categoryId) {
                    $parentPayload['category_id'] = $categoryId;
                    $parentDiffs['category_id'] = [
                        'label' => 'Category',
                        'old' => $parent['category_id'] ?? '',
                        'new' => $categoryId
                    ];
                }
            }
        }

        $variantPayload = null;
        if ($variant || $isNewProduct || $isNewVariant) {
            $variantPayload = [
                'id' => (int)($variant['id'] ?? 0),
                'variety' => ($row['variety'] ?? '') !== '' ? strtoupper($row['variety']) : ($variant['variety'] ?? ''),
                'variety_display' => ($row['variety_display'] ?? '') !== '' ? $row['variety_display'] : ($variant['variety_display'] ?? ''),
                'bundle_qty' => ($row['variant_bundle_qty'] ?? '') !== '' ? $row['variant_bundle_qty'] : ($variant['bundle_qty'] ?? ($row['std_qty'] ?? 400)),
                'bundle_stock' => ($row['variant_bundle_stock'] ?? '') !== '' ? $row['variant_bundle_stock'] : ($variant['bundle_stock'] ?? 0),
                'loose_stock' => ($row['variant_loose_stock'] ?? '') !== '' ? $row['variant_loose_stock'] : ($variant['loose_stock'] ?? 0),
                'stock' => ($row['variant_stock'] ?? '') !== '' ? $row['variant_stock'] : ($variant['stock'] ?? 0),
                'mrp' => ($row['variant_mrp'] ?? '') !== '' ? $row['variant_mrp'] : ($variant['mrp'] ?? null),
                'price' => ($row['variant_price'] ?? '') !== '' ? $row['variant_price'] : ($variant['price'] ?? null),
                'sale_price' => ($row['variant_sale_price'] ?? '') !== '' ? $row['variant_sale_price'] : ($variant['sale_price'] ?? null),
                'gst_rate' => ($row['variant_gst_rate'] ?? '') !== '' ? $row['variant_gst_rate'] : ($variant['gst_rate'] ?? null),
                'selling_price' => ($row['variant_selling_price'] ?? '') !== '' ? $row['variant_selling_price'] : ($variant['selling_price'] ?? null),
                'discounted_price' => ($row['variant_discounted_price'] ?? '') !== '' ? $row['variant_discounted_price'] : ($variant['discounted_price'] ?? null),
                'product_code' => ($row['variant_code'] ?? '')
            ];
            if (($row['variant_page_count'] ?? '') !== '') {
                $variantPayload['page_count'] = (int)$row['variant_page_count'];
            }
        }

        $action = empty($errors) ? ($isNewProduct ? 'new' : ((!empty($parentPayload) || $variantPayload !== null) ? 'update' : 'unchanged')) : 'error';
        if ($action === 'update') $updated++;
        if ($action === 'new' && $isFirstNewSku) $newCount++;
        if ($action === 'unchanged') $unchanged++;
        if ($action === 'error') $failed++;
        $diffs = $parentDiffs;
        if ($isNewVariant && empty($errors)) {
            $diffs['new_variant'] = [
                'label' => 'Variant',
                'old' => 'Not present',
                'new' => 'Will be created'
            ];
        }
        $items[] = [
            'row_num' => $rowNumber,
            'action' => $action,
            'matched_id' => $parent['id'] ?? 0,
            'product_name' => $parent['name'] ?? ($row['name'] ?? ''),
            'sku' => $parent['sku'] ?? $sku,
            'diffs' => $diffs,
            'update_payload' => ['id' => $parent['id'] ?? 0, 'parent' => $parentPayload, 'variant' => $variantPayload, 'new_product' => $isNewProduct, 'new_product_first' => $isFirstNewSku],
            'errors' => $errors
        ];
    }

    if (empty($items)) {
        return ['success' => false, 'message' => 'The uploaded file does not contain any product rows.'];
    }

    $matchedProductIds = [];
    foreach ($items as $item) {
        if ($item['action'] !== 'error' && (int)$item['matched_id'] > 0) {
            $matchedProductIds[(int)$item['matched_id']] = true;
        }
    }

    $productsToRemove = 0;
    if ($failed === 0) {
        $currentProductCount = (int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $productsToRemove = max(0, $currentProductCount - count($matchedProductIds));
    }

    $token = md5(uniqid('flat_excel_import_', true));
    $_SESSION['flat_excel_import_' . $token] = [
        'items' => $items,
        'replace_product_catalog' => true
    ];
    return [
        'success' => true, 'token' => $token, 'total' => count($items),
        'updated_count' => $updated, 'new_count' => $newCount,
        'unchanged_count' => $unchanged, 'error_count' => $failed,
        'products_to_remove' => $productsToRemove, 'items' => $items
    ];
}

function execute_flat_product_import($db, $token) {
    $key = 'flat_excel_import_' . $token;
    $preview = $_SESSION[$key] ?? null;
    if (!is_array($preview) || empty($preview['replace_product_catalog']) || !isset($preview['items'])) {
        return ['success' => false, 'message' => 'Import session expired or invalid. Please re-upload the file.'];
    }
    $items = $preview['items'];
    $failedRows = array_filter($items, static function ($item) {
        return ($item['action'] ?? '') === 'error';
    });
    if (!empty($failedRows)) {
        return [
            'success' => false,
            'message' => 'Import cancelled: fix all preview errors before replacing the product catalogue.'
        ];
    }

    $updated = 0; $inserted = 0; $skipped = 0; $failed = 0;
    $newProductIds = [];
    $retainedProductIds = [];
    $db->beginTransaction();
    try {
        $allowed = [
            'name', 'sku', 'category_id', 'brand_name', 'description', 'price', 'mrp',
            'sale_price', 'selling_price_unruled', 'discounted_price_unruled',
            'selling_price_ruled', 'discounted_price_ruled', 'size', 'paper_type',
            'gsm', 'binding', 'cover_type', 'gst_rate', 'manual_verification_flag',
            'manual_verification_notes', 'featured', 'bestseller', 'new_arrival', 'active'
        ];
        foreach ($items as $item) {
            $payload = $item['update_payload'];
            $id = (int)$payload['id'];
            if ($item['action'] === 'unchanged') {
                if ($id > 0) $retainedProductIds[$id] = $id;
                $skipped++;
                continue;
            }
            $parent = array_intersect_key($payload['parent'], array_flip($allowed));
            if (!empty($payload['new_product'])) {
                $name = trim((string)($parent['name'] ?? ''));
                $sku = trim((string)($parent['sku'] ?? ''));
                $newSkuKey = strtolower($sku);
                if (isset($newProductIds[$newSkuKey])) {
                    $id = (int)$newProductIds[$newSkuKey];
                } else {
                $categoryId = (int)($parent['category_id'] ?? 0);
                $price = isset($parent['price']) ? (float)$parent['price'] : -1;
                if ($name === '' || $sku === '' || $categoryId <= 0 || $price < 0) {
                    throw new RuntimeException('A new product requires Product Name, unique SKU, valid Category, and Rate / Price.');
                }
                $dupeSku = $db->prepare('SELECT id FROM products WHERE LOWER(TRIM(sku)) = LOWER(TRIM(?)) LIMIT 1');
                $dupeSku->execute([$sku]);
                if ($dupeSku->fetchColumn()) {
                    throw new RuntimeException("SKU '{$sku}' already exists. Re-upload the Excel file so it can be matched as an existing product.");
                }
                $slugBase = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
                if ($slugBase === '') $slugBase = 'notebook-product';
                $slug = substr($slugBase, 0, 140);
                $slugCheck = $db->prepare('SELECT COUNT(*) FROM products WHERE slug = ?');
                $slugCheck->execute([$slug]);
                if ((int)$slugCheck->fetchColumn() > 0) $slug .= '-' . substr(md5($sku), 0, 6);

                $variantData = $payload['variant'] ?? [];
                $pages = max(1, (int)($variantData['page_count'] ?? 192));
                $stdQty = max(1, (int)($variantData['bundle_qty'] ?? 400));
                $stockPieces = max(0, (int)($variantData['stock'] ?? 0));
                $bundleStock = max(0, (int)($variantData['bundle_stock'] ?? intdiv($stockPieces, $stdQty)));
                $insert = $db->prepare("INSERT INTO products
                    (name, slug, sku, category_id, brand_name, std_qty, mrp,
                     selling_price_unruled, discounted_price_unruled, selling_price_ruled, discounted_price_ruled,
                     ruling_option, description, price, sale_price, gst_rate, stock, bundle_stock, pages,
                     size, paper_type, gsm, binding, cover_type, image, featured, bestseller, new_arrival,
                     active, manual_verification_flag, manual_verification_notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert->execute([
                    $name, $slug, $sku, $categoryId, $parent['brand_name'] ?? '',
                    $stdQty, $parent['mrp'] ?? null,
                    $parent['selling_price_unruled'] ?? null, $parent['discounted_price_unruled'] ?? null,
                    $parent['selling_price_ruled'] ?? null, $parent['discounted_price_ruled'] ?? null,
                    $parent['ruling_option'] ?? 'Both', $parent['description'] ?? '',
                    $price, $parent['sale_price'] ?? null, $parent['gst_rate'] ?? 18,
                    $stockPieces, $bundleStock, $pages, $parent['size'] ?? 'A4 Size',
                    $parent['paper_type'] ?? 'High-grade Ivory Paper', $parent['gsm'] ?? 70,
                    $parent['binding'] ?? 'Softbound / Wire-O', $parent['cover_type'] ?? 'Laminated Hard Cover',
                    'assets/images/categories/cat_long_laminated.webp',
                    $parent['featured'] ?? 0, $parent['bestseller'] ?? 0, $parent['new_arrival'] ?? 0,
                    $parent['active'] ?? 1, $parent['manual_verification_flag'] ?? 0,
                    $parent['manual_verification_notes'] ?? ''
                ]);
                $id = (int)$db->lastInsertId();
                if ($id <= 0) throw new RuntimeException('Could not create the new product record.');
                $newProductIds[$newSkuKey] = $id;
                $inserted++;
                }
            } else {
                if (!empty($parent)) {
                    $sets = [];
                    $params = [];
                    foreach ($parent as $column => $value) {
                        $sets[] = "`{$column}` = ?";
                        $params[] = $value;
                    }
                    $params[] = $id;
                    $db->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
                }
            }
            if (!empty($payload['variant'])) {
                $productStmt = $db->prepare('SELECT sku, pages, std_qty, stock FROM products WHERE id = ? FOR UPDATE');
                $productStmt->execute([$id]);
                $product = $productStmt->fetch(PDO::FETCH_ASSOC);
                if (!$product) throw new RuntimeException("Product #{$id} no longer exists.");
                save_product_variant_records(
                    $db, $id, $product['sku'], (int)$product['pages'],
                    (int)$product['std_qty'], (int)$product['stock'], [$payload['variant']]
                );
                if (array_key_exists('page_count', $payload['variant']) && (int)($payload['variant']['id'] ?? 0) > 0) {
                    $db->prepare('UPDATE product_variants SET page_count = ? WHERE id = ? AND product_id = ?')
                        ->execute([(int)$payload['variant']['page_count'], (int)$payload['variant']['id'], $id]);
                }
                $db->prepare('UPDATE products SET bundle_stock = (SELECT COALESCE(SUM(bundle_stock), 0) FROM product_variants WHERE product_id = ?), stock = (SELECT COALESCE(SUM(stock), 0) FROM product_variants WHERE product_id = ?) WHERE id = ?')
                    ->execute([$id, $id, $id]);
            }
            if ($id > 0) $retainedProductIds[$id] = $id;
            if (empty($payload['new_product'])) $updated++;
        }

        if (empty($retainedProductIds)) {
            throw new RuntimeException('The uploaded file did not produce any valid products; the catalogue was not replaced.');
        }
        $retainedIds = array_values($retainedProductIds);
        $removedIdsStmt = $db->prepare(
            'SELECT id FROM products WHERE id NOT IN (' .
            implode(', ', array_fill(0, count($retainedIds), '?')) .
            ')'
        );
        $removedIdsStmt->execute($retainedIds);
        $removedIds = array_map('intval', $removedIdsStmt->fetchAll(PDO::FETCH_COLUMN));

        if (!empty($removedIds)) {
            $removedPlaceholders = implode(', ', array_fill(0, count($removedIds), '?'));
            $db->prepare("DELETE FROM product_variants WHERE product_id IN ($removedPlaceholders)")->execute($removedIds);
            $db->prepare("DELETE FROM product_images WHERE product_id IN ($removedPlaceholders)")->execute($removedIds);
            $db->prepare("DELETE FROM cart_items WHERE product_id IN ($removedPlaceholders)")->execute($removedIds);
            $db->prepare("DELETE FROM reviews WHERE product_id IN ($removedPlaceholders)")->execute($removedIds);
            $db->prepare("DELETE FROM stock_history WHERE product_id IN ($removedPlaceholders)")->execute($removedIds);
            $db->prepare("UPDATE ai_designs SET created_product_id = NULL WHERE created_product_id IN ($removedPlaceholders)")->execute($removedIds);
            $db->prepare("UPDATE order_items SET product_id = NULL, variant_id = NULL WHERE product_id IN ($removedPlaceholders)")->execute($removedIds);
            $db->prepare("UPDATE quotation_items SET product_id = NULL, variant_id = NULL WHERE product_id IN ($removedPlaceholders)")->execute($removedIds);
        }

        $deleteSql = 'DELETE FROM products WHERE id NOT IN (' . implode(', ', array_fill(0, count($retainedIds), '?')) . ')';
        $deleteStmt = $db->prepare($deleteSql);
        $deleteStmt->execute($retainedIds);
        $removed = $deleteStmt->rowCount();

        $db->commit();
        unset($_SESSION[$key]);
        return [
            'success' => true, 'updated' => $updated, 'inserted' => $inserted,
            'skipped' => $skipped, 'failed' => $failed,
            'removed' => $removed,
            'message' => "Excel import completed. New: {$inserted}, Updated: {$updated}, Removed: {$removed}, Skipped: {$skipped}, Failed: {$failed}."
        ];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['success' => false, 'message' => 'Database error during import: ' . $e->getMessage()];
    }
}
