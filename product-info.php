<?php
/**
 * Public Mobile-Friendly Product Verification & Information Page
 * Accessible via scanning product QR codes (No admin login required)
 */
require_once __DIR__ . '/includes/functions.php';
$logos = get_active_logos();

$code = isset($_GET['code']) ? trim(sanitize($_GET['code'])) : '';
$sku = isset($_GET['sku']) ? trim(sanitize($_GET['sku'])) : '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$lookup_code = !empty($code) ? $code : (!empty($sku) ? $sku : '');

$param_bundle_qty = isset($_GET['bundle_qty']) ? (int)$_GET['bundle_qty'] : 0;
$param_bundle_id  = isset($_GET['bundle_id']) ? trim(sanitize($_GET['bundle_id'])) : '';

$product_info = null;
$db = getDB();

if (!empty($lookup_code)) {
    $altLookup = str_replace(' ', '+', $lookup_code);
    // 1. Search in product_variants
    $stmt = $db->prepare("
        SELECT pv.*, 
               p.id as base_product_id,
               p.name AS product_name, 
               p.brand_name, 
               p.size, 
               p.pages AS prod_pages, 
               p.binding, 
               p.paper_type, 
               p.cover_type, 
               p.gsm, 
               p.image, 
               p.ruling_option
        FROM product_variants pv
        JOIN products p ON pv.product_id = p.id
        WHERE pv.product_code = ? OR UPPER(pv.product_code) = UPPER(?) OR pv.product_code = ? OR UPPER(pv.product_code) = UPPER(?)
        LIMIT 1
    ");
    $stmt->execute([$lookup_code, $lookup_code, $altLookup, $altLookup]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row && preg_match('/^PWR-(\d+)-([A-Z]+)$/i', $lookup_code, $mCodeMatch)) {
        $aliasStmt = $db->prepare("
            SELECT pv.*, 
                   p.id as base_product_id,
                   p.name AS product_name, 
                   p.brand_name, 
                   p.size, 
                   p.pages AS prod_pages, 
                   p.binding, 
                   p.paper_type, 
                   p.cover_type, 
                   p.gsm, 
                   p.image, 
                   p.ruling_option
            FROM product_variants pv
            JOIN products p ON pv.product_id = p.id
            WHERE pv.product_code LIKE ? AND pv.variety = ?
            LIMIT 1
        ");
        $aliasStmt->execute(['%POW%' . $mCodeMatch[1] . '%', strtoupper($mCodeMatch[2])]);
        $row = $aliasStmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['product_code'] = strtoupper($lookup_code);
        }
    }

    if ($row) {
        $pVariety = !empty($row['variety_display']) ? $row['variety_display'] : (!empty($row['variety']) ? $row['variety'] : 'Standard');
        if (strcasecmp($pVariety, 'Micro Point') === 0) {
            $pVariety = 'Maths Plain';
        }
        $vCode = !empty($row['variety']) ? strtoupper(trim($row['variety'])) : '';
        $varietyWithCode = ($vCode && strpos($pVariety, '(') === false && $vCode !== strtoupper($pVariety)) 
            ? "{$pVariety} ({$vCode})" 
            : $pVariety;

        $pPages = !empty($row['page_count']) ? $row['page_count'] : (!empty($row['prod_pages']) ? $row['prod_pages'] : '192');
        if (is_numeric($pPages)) {
            $pPagesDisplay = $pPages . ' Pages';
        } else {
            $pPagesDisplay = (stripos((string)$pPages, 'pages') === false) ? ($pPages . ' Pages') : $pPages;
        }

        $bQty = $param_bundle_qty > 0 ? $param_bundle_qty : (int)($row['bundle_qty'] ?? 0);
        $bId = !empty($param_bundle_id) ? $param_bundle_id : ($bQty > 0 && function_exists('format_bundle_identifier') ? format_bundle_identifier($row['product_code'], $bQty) : ($bQty > 0 ? ($row['product_code'] . '-' . $bQty) : ''));

        $product_info = [
            'product_code' => $row['product_code'],
            'product_name' => $row['product_name'],
            'variety' => $varietyWithCode,
            'page_count' => $pPagesDisplay,
            'bundle_qty' => $bQty,
            'bundle_id' => $bId,
            'brand_name' => $row['brand_name'] ?? 'RAJPRIYA',
            'size' => $row['size'] ?? '',
            'binding' => $row['binding'] ?? '',
            'gsm' => $row['gsm'] ?? '',
            'image' => !empty($row['image']) ? $row['image'] : 'uploads/products/prod_navy_single.webp',
            'product_id' => (int)($row['base_product_id'] ?? 0),
            'variant_id' => (int)($row['id'] ?? 0)
        ];
    } else {
        // 2. Search in products table by SKU
        $stmtP = $db->prepare("SELECT * FROM products WHERE sku = ? OR UPPER(sku) = UPPER(?) OR sku = ? OR UPPER(sku) = UPPER(?) LIMIT 1");
        $stmtP->execute([$lookup_code, $lookup_code, $altLookup, $altLookup]);
        $rowP = $stmtP->fetch(PDO::FETCH_ASSOC);
        if ($rowP) {
            $pVariety = !empty($rowP['ruling_option']) && $rowP['ruling_option'] !== 'Both' ? $rowP['ruling_option'] : 'Standard';
            if (strcasecmp($pVariety, 'Micro Point') === 0) {
                $pVariety = 'Maths Plain';
            }
            $pPages = !empty($rowP['pages']) ? $rowP['pages'] : '192';
            $pPagesDisplay = is_numeric($pPages) ? ($pPages . ' Pages') : $pPages;

            $product_info = [
                'product_code' => $rowP['sku'],
                'product_name' => $rowP['name'],
                'variety' => $pVariety,
                'page_count' => $pPagesDisplay,
                'bundle_qty' => $param_bundle_qty > 0 ? $param_bundle_qty : (int)($rowP['std_qty'] ?? 400),
                'bundle_id' => !empty($param_bundle_id) ? $param_bundle_id : '',
                'brand_name' => $rowP['brand_name'] ?? 'RAJPRIYA',
                'size' => $rowP['size'] ?? '',
                'binding' => $rowP['binding'] ?? '',
                'gsm' => $rowP['gsm'] ?? '',
                'image' => !empty($rowP['image']) ? $rowP['image'] : 'uploads/products/prod_navy_single.webp',
                'product_id' => (int)($rowP['id'] ?? 0),
                'variant_id' => 0
            ];
        }
    }
} elseif ($id > 0) {
    // Search by product ID
    $stmtP = $db->prepare("SELECT * FROM products WHERE id = ? LIMIT 1");
    $stmtP->execute([$id]);
    $rowP = $stmtP->fetch(PDO::FETCH_ASSOC);
    if ($rowP) {
        $pVariety = !empty($rowP['ruling_option']) && $rowP['ruling_option'] !== 'Both' ? $rowP['ruling_option'] : 'Standard';
        if (strcasecmp($pVariety, 'Micro Point') === 0) {
            $pVariety = 'Maths Plain';
        }
        $pPages = !empty($rowP['pages']) ? $rowP['pages'] : '192';
        $pPagesDisplay = is_numeric($pPages) ? ($pPages . ' Pages') : $pPages;

        $product_info = [
            'product_code' => !empty($rowP['sku']) ? $rowP['sku'] : ('RP-PROD-' . $rowP['id']),
            'product_name' => $rowP['name'],
            'variety' => $pVariety,
            'page_count' => $pPagesDisplay,
            'bundle_qty' => $param_bundle_qty > 0 ? $param_bundle_qty : (int)($rowP['std_qty'] ?? 400),
            'bundle_id' => !empty($param_bundle_id) ? $param_bundle_id : '',
            'brand_name' => $rowP['brand_name'] ?? 'RAJPRIYA',
            'size' => $rowP['size'] ?? '',
            'binding' => $rowP['binding'] ?? '',
            'gsm' => $rowP['gsm'] ?? '',
            'image' => !empty($rowP['image']) ? $rowP['image'] : 'uploads/products/prod_navy_single.webp',
            'product_id' => (int)($rowP['id'] ?? 0),
            'variant_id' => 0
        ];
    }
}

// Determine safe fallback return URL to Label Printer page
$backFallbackUrl = '';

// 1. Explicit return_url provided in query params (validated to point to print_label.php)
if (!empty($_GET['return_url'])) {
    $rawReturn = trim($_GET['return_url']);
    if (strpos($rawReturn, 'print_label.php') !== false && !preg_match('#^(https?:)?//#i', $rawReturn)) {
        $backFallbackUrl = $rawReturn;
    }
}

// 2. Referrer is available and points to print_label.php
if (empty($backFallbackUrl) && !empty($_SERVER['HTTP_REFERER'])) {
    $ref = $_SERVER['HTTP_REFERER'];
    if (strpos($ref, 'print_label.php') !== false) {
        $backFallbackUrl = $ref;
    }
}

// 3. Fallback: Reconstruct the exact Label Printer URL with preserved parameters
if (empty($backFallbackUrl)) {
    $printerParams = [];
    $pId = !empty($_GET['product_id']) ? (int)$_GET['product_id'] : ($product_info['product_id'] ?? 0);
    $vId = !empty($_GET['variant_id']) ? (int)$_GET['variant_id'] : ($product_info['variant_id'] ?? 0);
    $pCode = !empty($_GET['code']) ? trim($_GET['code']) : ($product_info['product_code'] ?? '');
    $bQty = !empty($param_bundle_qty) ? $param_bundle_qty : ($product_info['bundle_qty'] ?? 0);
    $bId = !empty($param_bundle_id) ? $param_bundle_id : ($product_info['bundle_id'] ?? '');

    if ($pId > 0) $printerParams['product_id'] = $pId;
    if ($vId > 0) $printerParams['variant_id'] = $vId;
    if (!empty($pCode)) $printerParams['code'] = $pCode;
    if ($bQty > 0) $printerParams['bundle_qty'] = $bQty;
    if (!empty($bId)) $printerParams['bundle_id'] = $bId;

    $backFallbackUrl = 'admin/print_label.php' . (!empty($printerParams) ? ('?' . http_build_query($printerParams)) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title><?php echo $product_info ? htmlspecialchars($product_info['product_name'] . ' (' . $product_info['product_code'] . ')') : 'Product Information'; ?> — RAJPRIYA NOTEBOOKS</title>
  <link rel="icon" href="<?php echo htmlspecialchars(get_site_url() . '/' . ltrim($logos['primary'], '/'), ENT_QUOTES, 'UTF-8'); ?>">
  
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  
  <style>
    :root {
      --navy: #17243A;
      --gold: #C89B3C;
      --bg: #F8FAFC;
    }
    body {
      background-color: var(--bg);
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
      color: #1E293B;
      padding: 20px 14px 40px;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-start;
    }
    .info-card {
      width: 100%;
      max-width: 480px;
      background: #FFFFFF;
      border: 1px solid #E2E8F0;
      border-radius: 16px;
      box-shadow: 0 10px 25px rgba(23, 36, 58, 0.08);
      overflow: hidden;
      margin: 0 auto;
    }
    .card-banner {
      background: linear-gradient(135deg, #17243A 0%, #203354 100%);
      color: #FFFFFF;
      padding: 16px 20px 18px;
      text-align: center;
      position: relative;
    }
    .banner-top-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
    }
    .btn-card-back {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(255, 255, 255, 0.12);
      color: #FFFFFF;
      border: 1px solid rgba(255, 255, 255, 0.28);
      padding: 5px 14px;
      border-radius: 20px;
      font-size: 0.82rem;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.2s ease;
      line-height: 1.2;
      backdrop-filter: blur(4px);
    }
    .btn-card-back:hover {
      background: rgba(255, 255, 255, 0.24);
      color: #FFFFFF;
      border-color: rgba(255, 255, 255, 0.5);
      transform: translateX(-2px);
    }
    .btn-card-back:active {
      transform: scale(0.97);
    }
    .verified-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      background: rgba(200, 155, 60, 0.2);
      border: 1px solid #C89B3C;
      color: #F8FAFC;
      font-size: 0.72rem;
      font-weight: 700;
      letter-spacing: 0.4px;
      padding: 4px 12px;
      border-radius: 20px;
      margin-bottom: 0;
      text-transform: uppercase;
      white-space: nowrap;
    }
    .product-code-banner {
      font-family: 'Courier New', Courier, monospace;
      font-size: 1.35rem;
      font-weight: 800;
      letter-spacing: 1.5px;
      color: #FFFFFF;
      word-break: break-all;
    }
    .card-body-content {
      padding: 24px 20px;
    }
    .spec-row {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      padding: 12px 0;
      border-bottom: 1px solid #F1F5F9;
    }
    .spec-row:last-child {
      border-bottom: none;
    }
    .spec-label {
      font-size: 0.85rem;
      font-weight: 600;
      color: #64748B;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .spec-val {
      font-size: 1.05rem;
      font-weight: 700;
      color: #17243A;
      text-align: right;
      max-width: 65%;
      word-break: break-word;
    }
    .highlight-val {
      color: #0F172A;
      font-size: 1.15rem;
    }
    .variety-pill {
      display: inline-block;
      background: #EFF6FF;
      color: #1D4ED8;
      border: 1px solid #BFDBFE;
      padding: 3px 10px;
      border-radius: 6px;
      font-weight: 700;
      font-size: 0.95rem;
    }
    .footer-brand {
      text-align: center;
      margin-top: 24px;
      font-size: 0.78rem;
      color: #94A3B8;
    }
    .card-action-buttons {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      justify-content: center;
      align-items: center;
    }
    .btn-print-details {
      background-color: var(--navy);
      color: #FFFFFF;
      border: 1px solid var(--navy);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
    }
    .btn-print-details:hover {
      background-color: #203354;
      color: #FFFFFF;
      border-color: #203354;
      box-shadow: 0 4px 12px rgba(23, 36, 58, 0.2);
      transform: translateY(-1px);
    }
    @media (max-width: 480px) {
      .card-banner {
        padding: 14px 14px 16px;
      }
      .banner-top-row {
        gap: 8px;
        margin-bottom: 10px;
      }
      .btn-card-back {
        padding: 4px 10px;
        font-size: 0.78rem;
      }
      .verified-badge {
        padding: 3px 8px;
        font-size: 0.68rem;
      }
      .card-action-buttons {
        flex-direction: column;
        width: 100%;
      }
      .card-action-buttons .btn {
        width: 100%;
      }
    }
    @media print {
      @page {
        size: auto;
        margin: 10mm;
      }
      .no-print,
      .btn-card-back,
      .card-action-buttons,
      .footer-brand,
      nav,
      header,
      footer {
        display: none !important;
      }
      body {
        background: #FFFFFF !important;
        padding: 0 !important;
        margin: 0 !important;
        color: #000000 !important;
        display: block !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
      .info-card {
        width: 100% !important;
        max-width: 520px !important;
        box-shadow: none !important;
        border: 2px solid #17243A !important;
        border-radius: 12px !important;
        margin: 0 auto !important;
        page-break-inside: avoid !important;
        break-inside: avoid !important;
        background: #FFFFFF !important;
      }
      .card-banner {
        background: #17243A !important;
        color: #FFFFFF !important;
        padding: 16px 20px !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
      .banner-top-row {
        display: flex !important;
        justify-content: center !important;
        margin-bottom: 8px !important;
      }
      .verified-badge {
        border: 1px solid #C89B3C !important;
        color: #FFFFFF !important;
        background: rgba(200, 155, 60, 0.25) !important;
        -webkit-print-color-adjust: exact !important;
      }
      .product-code-banner {
        color: #FFFFFF !important;
      }
      .card-body-content {
        padding: 16px 20px !important;
      }
      .spec-row {
        padding: 10px 0 !important;
        border-bottom: 1px solid #CBD5E1 !important;
      }
      .spec-row:last-child {
        border-bottom: none !important;
      }
      .spec-label {
        color: #475569 !important;
        font-size: 0.85rem !important;
      }
      .spec-val {
        color: #0F172A !important;
        font-size: 1rem !important;
      }
      .variety-pill {
        background: #EFF6FF !important;
        color: #1D4ED8 !important;
        border: 1px solid #BFDBFE !important;
        -webkit-print-color-adjust: exact !important;
      }
    }
  </style>
</head>
<body>

  <div class="info-card">
    <?php if ($product_info): ?>
      <div class="card-banner">
        <div class="banner-top-row">
          <a href="<?php echo htmlspecialchars($backFallbackUrl); ?>" class="btn-card-back no-print" onclick="handleGoBack(event)" title="Return to Label Printer">
            <i class="bi bi-arrow-left"></i>
            <span>Back</span>
          </a>
          <div class="verified-badge">
            <i class="bi bi-patch-check-fill text-warning"></i> Genuine Raj Priya Product
          </div>
        </div>
        <div class="product-code-banner">
          <?php echo htmlspecialchars($product_info['product_code']); ?>
        </div>
      </div>

      <div class="card-body-content">
        <!-- 1. Product Code -->
        <div class="spec-row">
          <span class="spec-label"><i class="bi bi-upc-scan me-1 text-gold"></i> Product Code</span>
          <span class="spec-val font-monospace fw-bold text-dark"><?php echo htmlspecialchars($product_info['product_code']); ?></span>
        </div>

        <!-- 2. Product Name -->
        <div class="spec-row">
          <span class="spec-label"><i class="bi bi-journal-text me-1 text-gold"></i> Product Name</span>
          <span class="spec-val highlight-val"><?php echo htmlspecialchars($product_info['product_name']); ?></span>
        </div>

        <!-- 3. Variety -->
        <div class="spec-row">
          <span class="spec-label"><i class="bi bi-pencil-ruler me-1 text-gold"></i> Variety</span>
          <span class="spec-val"><span class="variety-pill"><?php echo htmlspecialchars($product_info['variety']); ?></span></span>
        </div>

        <!-- 4. Page Count -->
        <div class="spec-row">
          <span class="spec-label"><i class="bi bi-files me-1 text-gold"></i> Page Count</span>
          <span class="spec-val"><?php echo htmlspecialchars($product_info['page_count']); ?></span>
        </div>

        <?php if (!empty($product_info['bundle_id'])): ?>
          <div class="spec-row">
            <span class="spec-label"><i class="bi bi-upc me-1 text-gold"></i> Bundle ID</span>
            <span class="spec-val font-monospace fw-bold text-dark"><?php echo htmlspecialchars($product_info['bundle_id']); ?></span>
          </div>
        <?php endif; ?>

        <?php if (!empty($product_info['bundle_qty'])): ?>
          <div class="spec-row">
            <span class="spec-label"><i class="bi bi-box-seam me-1 text-gold"></i> Bundle Pack Qty</span>
            <span class="spec-val highlight-val text-success">1 BDLS &times; <?php echo (int)$product_info['bundle_qty']; ?> NOS</span>
          </div>
        <?php endif; ?>

        <?php if (!empty($product_info['brand_name'])): ?>
          <div class="spec-row">
            <span class="spec-label"><i class="bi bi-award me-1 text-gold"></i> Brand</span>
            <span class="spec-val"><?php echo htmlspecialchars($product_info['brand_name']); ?></span>
          </div>
        <?php endif; ?>

        <?php if (!empty($product_info['size'])): ?>
          <div class="spec-row">
            <span class="spec-label"><i class="bi bi-aspect-ratio me-1 text-gold"></i> Size</span>
            <span class="spec-val"><?php echo htmlspecialchars($product_info['size']); ?></span>
          </div>
        <?php endif; ?>

        <?php if (!empty($product_info['binding'])): ?>
          <div class="spec-row">
            <span class="spec-label"><i class="bi bi-book me-1 text-gold"></i> Binding</span>
            <span class="spec-val"><?php echo htmlspecialchars($product_info['binding']); ?></span>
          </div>
        <?php endif; ?>

        <div class="card-action-buttons mt-4 pt-2 no-print">
          <button type="button" class="btn btn-print-details btn-sm rounded-pill px-4 py-2 fw-semibold d-inline-flex align-items-center justify-content-center gap-2" onclick="window.print()">
            <i class="bi bi-printer"></i>
            <span>Print Product Details</span>
          </button>
          <a href="products.php" class="btn btn-outline-dark btn-sm rounded-pill px-4 py-2 fw-semibold d-inline-flex align-items-center justify-content-center gap-2">
            <i class="bi bi-grid"></i>
            <span>Browse Raj Priya Catalog</span>
          </a>
        </div>
      </div>
    <?php else: ?>
      <div class="card-banner bg-danger">
        <div class="banner-top-row">
          <a href="<?php echo htmlspecialchars($backFallbackUrl); ?>" class="btn-card-back no-print" onclick="handleGoBack(event)" title="Return to Label Printer">
            <i class="bi bi-arrow-left"></i>
            <span>Back</span>
          </a>
          <span class="badge bg-white text-danger px-2.5 py-1 fw-bold" style="font-size: 0.72rem; letter-spacing: 0.5px;">NOT FOUND</span>
        </div>
        <h5 class="fw-bold mb-0 text-white"><i class="bi bi-exclamation-triangle me-1"></i> Product Not Found</h5>
      </div>
      <div class="card-body-content text-center py-5">
        <p class="text-secondary mb-4">No product matching code <strong><?php echo htmlspecialchars($lookup_code ?: 'N/A'); ?></strong> was found.</p>
        <a href="<?php echo htmlspecialchars($backFallbackUrl); ?>" class="btn btn-dark btn-sm rounded-pill px-4 py-2 no-print">
          <i class="bi bi-arrow-left me-1"></i> Return to Label Printer
        </a>
      </div>
    <?php endif; ?>
  </div>

  <div class="footer-brand">
    &copy; <?php echo date('Y'); ?> RAJPRIYA NOTEBOOKS &bull; Quality Notebooks for Learning
  </div>

  <script>
    function handleGoBack(e) {
      if (e) e.preventDefault();
      var fallbackUrl = <?php echo json_encode($backFallbackUrl); ?>;
      var ref = document.referrer;
      
      // If client navigated from print_label.php in this tab history, use history.back()
      if (window.history.length > 1 && ref && ref.indexOf('print_label.php') !== -1) {
        window.history.back();
      } else {
        // Safe fallback preserving product_id, variant_id, code, bundle_qty, bundle_id
        window.location.href = fallbackUrl;
      }
    }
  </script>
</body>
</html>
