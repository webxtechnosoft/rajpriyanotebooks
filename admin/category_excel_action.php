<?php
/**
 * Category Excel Action Handler
 * Handles: template download, export existing categories, preview upload, commit import
 */
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

require_once __DIR__ . '/includes/excel_parser.php';

$db = getDB();
$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

// ─────────────────────────────────────────────────────────────────────────────
// 1) Download Category Excel Template
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rajpriya_category_import_template.csv');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel compatibility

    fputcsv($output, [
        'Category ID',
        'Category Name',
        'Description',
        'Status (1=Active 0=Disabled)'
    ]);

    // Add example rows
    fputcsv($output, ['', 'Single Line', 'Classic single ruled notebooks for everyday use.', '1']);
    fputcsv($output, ['', 'Double Line', 'Double-ruled notebooks ideal for primary school students.', '1']);
    fputcsv($output, ['', 'Four Line', 'Four-line notebooks perfect for handwriting practice.', '1']);

    fclose($output);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 2) Export Existing Categories as Excel/CSV
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'export') {
    $stmt = $db->query("SELECT c.id, c.name, c.description, c.status, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) as product_count FROM categories c ORDER BY c.id ASC");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=rajpriya_categories_export_' . date('Y-m-d') . '.csv');

    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");

    fputcsv($output, [
        'Category ID',
        'Category Name',
        'Description',
        'Status (1=Active 0=Disabled)',
        'Product Count'
    ]);

    foreach ($categories as $cat) {
        fputcsv($output, [
            $cat['id'],
            $cat['name'],
            $cat['description'],
            $cat['status'],
            $cat['product_count']
        ]);
    }

    fclose($output);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 3) Preview Uploaded Category Excel/CSV
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'preview') {
    header('Content-Type: application/json');

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['cat_excel_file'])) {
        echo json_encode(['success' => false, 'message' => 'No file was uploaded.']);
        exit();
    }

    $file = $_FILES['cat_excel_file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'File upload error code: ' . $file['error']]);
        exit();
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'xlsx', 'xls', 'txt'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid file format. Please upload .xlsx or .csv files.']);
        exit();
    }

    $rawRows = parse_excel_or_csv_data($file['tmp_name']);

    if (empty($rawRows) || count($rawRows) < 2) {
        echo json_encode(['success' => false, 'message' => 'The uploaded file is empty or contains no data rows.']);
        exit();
    }

    $headers = array_shift($rawRows);

    // Map headers
    $fieldMap = [];
    foreach ($headers as $idx => $hName) {
        $clean = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $hName)));
        $mappings = [
            'id'                    => 'id',
            'category_id'           => 'id',
            'cat_id'                => 'id',
            'name'                  => 'name',
            'category_name'         => 'name',
            'category'              => 'name',
            'description'           => 'description',
            'desc'                  => 'description',
            'status'                => 'status',
            'status_1_active_0_disabled' => 'status',
            'active'                => 'status',
            'product_count'         => null, // read-only, ignore
        ];
        $fieldMap[$idx] = isset($mappings[$clean]) ? $mappings[$clean] : $clean;
    }

    // Fetch existing categories for duplicate checking
    $stmtCats = $db->query("SELECT id, name FROM categories");
    $existingCats = [];
    while ($c = $stmtCats->fetch()) {
        $existingCats[(int)$c['id']] = strtolower(trim($c['name']));
    }

    $analyzedItems = [];
    $seenNames = [];
    $newCount = 0;
    $updateCount = 0;
    $unchangedCount = 0;
    $errorCount = 0;

    foreach ($rawRows as $rowIndex => $rowValues) {
        $rowNum = $rowIndex + 2;

        // Skip empty rows
        $nonEmpty = array_filter($rowValues, fn($v) => trim($v) !== '');
        if (empty($nonEmpty)) continue;

        $row = [];
        foreach ($fieldMap as $colIdx => $fieldName) {
            if ($fieldName === null) continue;
            $row[$fieldName] = isset($rowValues[$colIdx]) ? trim($rowValues[$colIdx]) : '';
        }

        $errors = [];
        $catId   = isset($row['id']) && is_numeric($row['id']) && (int)$row['id'] > 0 ? (int)$row['id'] : 0;
        $name    = isset($row['name']) ? trim($row['name']) : '';
        $desc    = isset($row['description']) ? trim($row['description']) : '';
        $status  = isset($row['status']) && is_numeric($row['status']) ? (int)$row['status'] : 1;

        // Validate: name is required
        if (empty($name)) {
            $errors[] = "Row {$rowNum}: Category Name is required.";
        }

        // Validate status value
        if (!in_array($status, [0, 1])) {
            $errors[] = "Row {$rowNum}: Invalid status value '{$row['status']}'. Must be 0 or 1.";
            $status = 1;
        }

        // Duplicate name in same file
        $nameLower = strtolower($name);
        if (!empty($nameLower) && isset($seenNames[$nameLower])) {
            $errors[] = "Row {$rowNum}: Duplicate category name '{$name}' already appears in Row " . $seenNames[$nameLower];
        } elseif (!empty($nameLower)) {
            $seenNames[$nameLower] = $rowNum;
        }

        // Find existing category
        $matchedCat = null;
        if ($catId > 0) {
            $stmt = $db->prepare("SELECT * FROM categories WHERE id = ?");
            $stmt->execute([$catId]);
            $matchedCat = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        if (!$matchedCat && !empty($name)) {
            $stmt = $db->prepare("SELECT * FROM categories WHERE LOWER(name) = LOWER(?) LIMIT 1");
            $stmt->execute([$name]);
            $matchedCat = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($matchedCat) {
            // Existing category – check for changes
            $diffs = [];
            if (!empty($name) && strtolower($name) !== strtolower($matchedCat['name'])) {
                $diffs['name'] = ['label' => 'Name', 'old' => $matchedCat['name'], 'new' => $name];
            }
            if (!empty($desc) && $desc !== $matchedCat['description']) {
                $diffs['description'] = ['label' => 'Description', 'old' => $matchedCat['description'], 'new' => $desc];
            }
            if ((int)$status !== (int)$matchedCat['status']) {
                $diffs['status'] = ['label' => 'Status', 'old' => $matchedCat['status'] ? 'Active' : 'Disabled', 'new' => $status ? 'Active' : 'Disabled'];
            }

            $action_type = !empty($errors) ? 'error' : (!empty($diffs) ? 'update' : 'unchanged');
            if ($action_type === 'update') $updateCount++;
            elseif ($action_type === 'unchanged') $unchangedCount++;
            else $errorCount++;

            $analyzedItems[] = [
                'row_num'      => $rowNum,
                'action'       => $action_type,
                'matched_id'   => $matchedCat['id'],
                'cat_name'     => $matchedCat['name'],
                'diffs'        => $diffs,
                'update_payload' => ['id' => $matchedCat['id'], 'name' => $name ?: $matchedCat['name'], 'description' => $desc ?: $matchedCat['description'], 'status' => $status],
                'errors'       => $errors
            ];
        } else {
            // New category
            if (empty($name)) {
                $errors[] = "Row {$rowNum}: Category Name is required to create a new category.";
            }
            $action_type = !empty($errors) ? 'error' : 'new';
            if ($action_type === 'new') $newCount++;
            else $errorCount++;

            $analyzedItems[] = [
                'row_num'       => $rowNum,
                'action'        => $action_type,
                'matched_id'    => 0,
                'cat_name'      => $name,
                'diffs'         => [],
                'insert_payload' => ['name' => $name, 'description' => $desc, 'status' => $status],
                'errors'        => $errors
            ];
        }
    }

    $token = 'cat_' . md5(uniqid('cat_excel_import_', true));
    $_SESSION['cat_excel_import_preview_' . $token] = $analyzedItems;

    echo json_encode([
        'success'         => true,
        'token'           => $token,
        'total'           => count($analyzedItems),
        'updated_count'   => $updateCount,
        'new_count'       => $newCount,
        'unchanged_count' => $unchangedCount,
        'error_count'     => $errorCount,
        'items'           => $analyzedItems
    ]);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 4) Commit Category Import
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'commit') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['import_token'])) {
        set_flash('error', 'Invalid import confirmation request.');
        header('Location: excel-manager.php');
        exit();
    }

    $token = $_POST['import_token'];
    $sessionKey = 'cat_excel_import_preview_' . $token;

    if (empty($_SESSION[$sessionKey])) {
        set_flash('error', 'Import session expired or invalid. Please re-upload the file.');
        header('Location: excel-manager.php');
        exit();
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
                $p = $item['update_payload'];
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $p['name'])));
                $stmt = $db->prepare("UPDATE categories SET name=?, slug=?, description=?, status=? WHERE id=?");
                $stmt->execute([$p['name'], $slug, $p['description'], $p['status'], $p['id']]);
                $updatedCount++;
            } elseif ($item['action'] === 'new' && !empty($item['insert_payload'])) {
                $p = $item['insert_payload'];
                $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $p['name'])));

                // Ensure unique slug
                $stmtSlug = $db->prepare("SELECT COUNT(*) FROM categories WHERE slug = ?");
                $stmtSlug->execute([$slug]);
                if ($stmtSlug->fetchColumn() > 0) {
                    $slug .= '-' . rand(100, 999);
                }

                $stmt = $db->prepare("INSERT INTO categories (name, slug, description, status, image) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$p['name'], $slug, $p['description'], $p['status'], 'assets/images/categories/cat_single_line.webp']);
                $insertedCount++;
            } else {
                $skippedCount++;
            }
        }

        $db->commit();
        unset($_SESSION[$sessionKey]);

        $msg = "Category import completed! Updated: {$updatedCount}, New: {$insertedCount}, Unchanged: {$skippedCount}.";
        set_flash('success', $msg);
    } catch (Exception $e) {
        $db->rollBack();
        set_flash('error', 'Database error during import: ' . $e->getMessage());
    }

    header('Location: excel-manager.php');
    exit();
}

// Fallback
header('Location: excel-manager.php');
exit();
