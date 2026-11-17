<?php
require_once __DIR__ . '/includes/functions.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$db = getDB();
$search = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$download = isset($_GET['download']) && $_GET['download'] == '1';

$sql = "
    SELECT p.id, p.name, p.brand_name, p.size, p.pages, p.price, p.sale_price, p.mrp, p.varieties_managed,
           p.selling_price_ruled, p.discounted_price_ruled,
           p.selling_price_unruled, p.discounted_price_unruled,
           p.stock, p.bundle_stock, p.std_qty, p.ruling_option, p.gst_rate, p.active,
           c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.active = 1
";
$params = [];

if ($search !== '') {
    $sql .= " AND (
        LOWER(p.name) LIKE ? OR LOWER(p.brand_name) LIKE ? OR LOWER(c.name) LIKE ?
        OR LOWER(p.size) LIKE ? OR CAST(p.pages AS CHAR) LIKE ?
        OR EXISTS (
            SELECT 1 FROM product_variants pv_search
            WHERE pv_search.product_id = p.id
              AND (LOWER(pv_search.variety_display) LIKE ? OR LOWER(pv_search.variety) LIKE ?)
        )
    )";
    $searchParam = '%' . strtolower($search) . '%';
    $params = [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam, $searchParam];
}

$sql .= " ORDER BY p.name ASC, p.pages ASC, p.size ASC, p.ruling_option ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
$variantStmt = $db->query("
    SELECT pv.id, pv.product_id, pv.variety, pv.variety_display, pv.mrp, pv.price,
           pv.sale_price, pv.gst_rate, pv.selling_price, pv.discounted_price,
           pv.stock, pv.bundle_stock
    FROM product_variants pv
    INNER JOIN products p ON p.id = pv.product_id
    WHERE p.active = 1
      AND COALESCE(pv.variety_display, '') NOT LIKE '%GST%'
      AND COALESCE(pv.variety, '') NOT LIKE '%GST%'
    ORDER BY pv.product_id ASC, pv.id ASC
");
$variantsByProduct = [];
foreach ($variantStmt->fetchAll(PDO::FETCH_ASSOC) as $variant) {
    $variantsByProduct[(int)$variant['product_id']][] = $variant;
}

$positivePrice = static function ($value) {
    return is_numeric($value) && (float)$value > 0 ? (float)$value : null;
};
$makePriceRow = static function ($product, $variety, $variant = null, $priceType = '') use ($positivePrice) {
    $mrp = $positivePrice($variant['mrp'] ?? null) ?? $positivePrice($product['mrp'] ?? null);
    $salePrice = $positivePrice($variant['sale_price'] ?? null)
        ?? $positivePrice($variant['price'] ?? null)
        ?? $positivePrice($product['sale_price'] ?? null)
        ?? $positivePrice($product['price'] ?? null);
    if ($salePrice === null && $mrp !== null) {
        $salePrice = round($mrp / 2, 2);
    }

    $variantGstRate = isset($variant['gst_rate']) && is_numeric($variant['gst_rate'])
        ? (float)$variant['gst_rate']
        : null;
    $productGstRate = isset($product['gst_rate']) && is_numeric($product['gst_rate'])
        ? (float)$product['gst_rate']
        : null;
    $gstRate = $variantGstRate ?? $productGstRate ?? 18.0;
    $sellingPrice = $positivePrice($variant['selling_price'] ?? null);
    $discountedPrice = null;
    if ($priceType !== '') {
        $sellingPrice = $sellingPrice ?? $positivePrice($product['selling_price_' . $priceType] ?? null);
    }
    if ($variant && $positivePrice($variant['discounted_price'] ?? null) !== null) {
        $discountedPrice = round((float)$variant['discounted_price'] * (1 + $gstRate / 100), 2);
    } elseif ($priceType !== '') {
        $discountedPrice = $positivePrice($product['discounted_price_' . $priceType] ?? null);
    }
    if ($sellingPrice === null && $salePrice !== null) {
        $sellingPrice = round($salePrice * (1 + $gstRate / 100), 2);
    }

    return [
        'product' => $product,
        'variety_display' => $variety,
        'mrp' => $mrp,
        'sale_price' => $salePrice,
        'selling_price' => $sellingPrice,
        'discounted_price' => $discountedPrice,
        'stock' => $variant ? (int)$variant['stock'] : (int)($product['stock'] ?? 0),
        'bundle_stock' => $variant ? (int)$variant['bundle_stock'] : (int)($product['bundle_stock'] ?? 0)
    ];
};

$rows = [];
foreach ($products as $product) {
    $variants = $variantsByProduct[(int)$product['id']] ?? [];
    if (!empty($variants)) {
        foreach ($variants as $variant) {
            $variety = trim((string)($variant['variety_display'] ?: $variant['variety']));
            $priceType = is_notebook_variety_ruled($variety, $variant['variety']) ? 'ruled' : 'unruled';
            $rows[] = $makePriceRow($product, $variety, $variant, $priceType);
        }
        continue;
    }

    $hasRuledPrice = $positivePrice($product['selling_price_ruled'] ?? null) !== null
        || $positivePrice($product['discounted_price_ruled'] ?? null) !== null;
    $hasUnruledPrice = $positivePrice($product['selling_price_unruled'] ?? null) !== null
        || $positivePrice($product['discounted_price_unruled'] ?? null) !== null;
    if ($hasRuledPrice || $hasUnruledPrice) {
        if ($hasRuledPrice) {
            $rows[] = $makePriceRow($product, 'Ruled', null, 'ruled');
        }
        if ($hasUnruledPrice) {
            $rows[] = $makePriceRow($product, 'Unruled', null, 'unruled');
        }
        continue;
    }

    $rulingOption = trim((string)($product['ruling_option'] ?? ''));
    $variety = !empty($product['varieties_managed'])
        ? 'No varieties configured'
        : (($rulingOption !== '' && strcasecmp($rulingOption, 'Both') !== 0) ? $rulingOption : 'Ruled & Unruled');
    $rows[] = $makePriceRow($product, $variety);
}

if ($download) {
    require_once __DIR__ . '/vendor/autoload.php';

    // 1. Fetch verified company settings and brand identity from database/constants
    $companyName    = 'RAJPRIYA NOTEBOOK';
    $companyTagline = get_setting('tagline', 'Quality Notebooks - Made for Better Learning');
    $companyAddress = get_setting('address', '123, Station Road, Salem - 636001, Tamil Nadu, India');
    $companyPhone   = get_setting('phone', '+91 98765 43210');
    $companyEmail   = get_setting('email', defined('INFO_EMAIL') ? INFO_EMAIL : 'info@rajpriyanotebooks.com');
    $companyWebsite = get_setting('site_url', 'www.rajpriyanotebooks.com');
    if (empty($companyWebsite) || strpos($companyWebsite, 'localhost') !== false) {
        $companyWebsite = 'www.rajpriyanotebooks.com';
    } else {
        $companyWebsite = preg_replace('#^https?://#i', '', rtrim($companyWebsite, '/'));
    }
    $companyGstin   = function_exists('get_company_gstin') ? get_company_gstin() : (defined('COMPANY_GSTIN') ? COMPANY_GSTIN : '33AHNPM8502B1ZM');

    // 2. Resolve official company logo asset (embed as base64 Data URI)
    $logoDataUri = '';
    $logoPngPath = __DIR__ . '/uploads/logos/logo_primary.png';
    if (!file_exists($logoPngPath)) {
        $logos = function_exists('get_active_logos') ? get_active_logos() : [];
        if (!empty($logos['primary'])) {
            $candidatePng = __DIR__ . '/' . ltrim(preg_replace('/\.webp$/i', '.png', $logos['primary']), '/\\');
            if (file_exists($candidatePng)) {
                $logoPngPath = $candidatePng;
            } elseif (file_exists(__DIR__ . '/' . ltrim($logos['primary'], '/\\'))) {
                $logoPngPath = __DIR__ . '/' . ltrim($logos['primary'], '/\\');
            }
        }
    }
    if (file_exists($logoPngPath)) {
        $ext = strtolower(pathinfo($logoPngPath, PATHINFO_EXTENSION));
        $mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');
        $logoDataUri = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPngPath));
    }

    // 3. Build table rows directly from UI $rows (guarantees 100% data, count and filter parity)
    $currentDate = format_date(time());
    $currentTime = date('h:i A');
    $currentEdition = date('F Y');
    $filterSubtitle = ($search !== '') ? ' &bull; Filtered by: &ldquo;' . htmlspecialchars($search) . '&rdquo; (' . count($rows) . ' price entries found)' : ' &bull; All Active Price Entries (' . count($rows) . ')';

    $tableRowsHtml = '';
    foreach ($rows as $index => $entry) {
        $product = $entry['product'];
        $stock = (int)$entry['stock'];
        $bundleStock = (int)$entry['bundle_stock'];
        if ($bundleStock > 0) {
            $availabilityText = 'In Stock (' . number_format($bundleStock) . ' Bdl / ' . number_format($stock) . ' Pcs)';
            $isAvailable = true;
        } elseif ($stock > 0) {
            $availabilityText = 'In Stock (' . number_format($stock) . ' Pcs)';
            $isAvailable = true;
        } else {
            $availabilityText = 'Out of Stock';
            $isAvailable = false;
        }

        $sizeText = trim((string)($product['size'] ?? ''));
        $varietyDisplay = (string)$entry['variety_display'];
        $mrpHtml = $entry['mrp'] !== null
            ? format_price($entry['mrp'])
            : '<span style="color: #94A3B8;">—</span>';
        $salePriceHtml = $entry['sale_price'] !== null
            ? format_price($entry['sale_price'])
            : '<span style="color: #94A3B8;">—</span>';
        $sellingPriceHtml = $entry['selling_price'] !== null
            ? format_price($entry['selling_price'])
            : '<span style="color: #94A3B8;">—</span>';
        $discountedPriceHtml = $entry['discounted_price'] !== null
            ? format_price($entry['discounted_price'])
            : '<span style="color: #94A3B8;">—</span>';

        $rowBg = ($index % 2 === 0) ? 'background-color: #F8FAFC;' : 'background-color: #FFFFFF;';
        $sNo = $index + 1;
        $categoryHtml = !empty($product['category_name']) ? '<div style="font-size: 6.8pt; color: #64748B; margin-top: 1px;">' . htmlspecialchars($product['category_name']) . '</div>' : '';

        $badgeStyle = $isAvailable 
            ? 'background-color: #ECFDF5; color: #065F46; border: 0.5px solid #A7F3D0;' 
            : 'background-color: #FEF2F2; color: #991B1B; border: 0.5px solid #FECACA;';

        $tableRowsHtml .= '<tr style="' . $rowBg . '">
            <td style="text-align: center; color: #64748B; font-weight: 600;">' . $sNo . '</td>
            <td style="vertical-align: middle;">
                <div style="font-weight: bold; color: #17243A; font-size: 7.8pt;">' . htmlspecialchars($product['name'] ?? '') . '</div>
                ' . $categoryHtml . '
            </td>
            <td style="color: #475569; vertical-align: middle;">' . (!empty($product['brand_name']) ? htmlspecialchars($product['brand_name']) : '<span style="color: #94A3B8;">—</span>') . '</td>
            <td style="vertical-align: middle;">
                <div style="font-weight: 600; color: #1E293B;">' . htmlspecialchars($sizeText !== '' ? $sizeText : 'Standard') . '</div>
                <div style="font-size: 6.8pt; color: #64748B; margin-top: 1.5px; line-height: 1.25;">
                    <span style="font-weight: 600; color: #475569;">Variety:</span> ' . htmlspecialchars($varietyDisplay) . '
                </div>
            </td>
            <td style="text-align: center; color: #334155; font-weight: 600; vertical-align: middle;">' . (!empty($product['pages']) ? htmlspecialchars((string)$product['pages']) : '<span style="color: #94A3B8;">—</span>') . '</td>
            <td style="text-align: right; vertical-align: middle;">' . $mrpHtml . '</td>
            <td style="text-align: right; vertical-align: middle;">' . $salePriceHtml . '</td>
            <td style="text-align: right; vertical-align: middle;">' . $sellingPriceHtml . '</td>
            <td style="text-align: right; vertical-align: middle;">' . $discountedPriceHtml . '</td>
            <td style="text-align: center; vertical-align: middle;">
                <span style="display: inline-block; padding: 3px 6px; border-radius: 9999px; font-size: 6.6pt; font-weight: 600; ' . $badgeStyle . '">
                    ' . htmlspecialchars($availabilityText) . '
                </span>
            </td>
        </tr>';
    }

    $logoHtml = '';
    if (!empty($logoDataUri)) {
        $logoHtml = '<img src="' . $logoDataUri . '" style="height: 46px; width: auto; vertical-align: middle;" alt="Rajpriya Logo">';
    }

    $pdfHtml = '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Rajpriya Wholesale Price List</title>
<style>
  @page {
    margin-top: 96px;
    margin-bottom: 42px;
    margin-left: 28px;
    margin-right: 28px;
  }
  body {
    font-family: "DejaVu Sans", sans-serif;
    font-size: 7.5pt;
    color: #17243A;
    line-height: 1.25;
  }
  .pdf-header {
    position: fixed;
    top: -82px;
    left: 0px;
    right: 0px;
    height: 72px;
    border-bottom: 2px solid #C89B3C;
  }
  .title-banner {
    background-color: #FAF6ED;
    border: 1px solid #E9D9A8;
    padding: 6px 10px;
    margin-bottom: 8px;
  }
  table.data-table {
    width: 100%;
    border-collapse: collapse;
  }
  table.data-table thead {
    display: table-header-group;
  }
  table.data-table tr {
    page-break-inside: avoid;
  }
  table.data-table th {
    background-color: #17243A;
    color: #FFFFFF;
    font-size: 7.3pt;
    font-weight: bold;
    padding: 5px 4px;
    text-align: left;
    border: 0.5px solid #17243A;
  }
  table.data-table td {
    padding: 4.5px 4px;
    border: 0.5px solid #E2E8F0;
    font-size: 7.3pt;
  }
</style>
</head>
<body>
  <div class="pdf-header">
    <table style="width: 100%; border-collapse: collapse;">
      <tr>
        ' . (!empty($logoHtml) ? '<td style="width: 52px; border: none; padding: 0; vertical-align: middle;">' . $logoHtml . '</td>' : '') . '
        <td style="border: none; padding-left: 10px; vertical-align: middle;">
          <div style="font-size: 14pt; font-weight: bold; color: #17243A; letter-spacing: 0.5px;">' . htmlspecialchars($companyName) . '</div>
          <div style="font-size: 7.2pt; color: #854D0E; font-weight: 600;">' . htmlspecialchars($companyTagline) . '</div>
          <div style="font-size: 6.8pt; color: #475569; margin-top: 2px;">
            ' . htmlspecialchars($companyAddress) . '
          </div>
        </td>
        <td style="width: 250px; border: none; padding: 0; text-align: right; vertical-align: middle; font-size: 7pt; color: #334155; line-height: 1.35;">
          <div><strong style="color: #17243A;">GSTIN:</strong> ' . htmlspecialchars($companyGstin) . '</div>
          <div><strong style="color: #17243A;">Phone:</strong> ' . htmlspecialchars($companyPhone) . ' &bull; <strong style="color: #17243A;">Email:</strong> ' . htmlspecialchars($companyEmail) . '</div>
          <div><strong style="color: #17243A;">Web:</strong> ' . htmlspecialchars($companyWebsite) . '</div>
        </td>
      </tr>
    </table>
  </div>

  <div class="title-banner">
    <table style="width: 100%; border-collapse: collapse;">
      <tr>
        <td style="border: none; padding: 0;">
          <span style="font-size: 11.5pt; font-weight: bold; color: #17243A; letter-spacing: 0.3px;">WHOLESALE PRICE LIST</span>
          <span style="font-size: 7.5pt; color: #64748B; margin-left: 8px;">Notebook Collection &amp; Wholesale Pricing' . $filterSubtitle . '</span>
        </td>
        <td style="border: none; padding: 0; text-align: right; font-size: 7.2pt; color: #475569;">
          <strong>Date:</strong> ' . $currentDate . ', ' . $currentTime . ' &bull; <strong>Catalogue:</strong> ' . $currentEdition . ' &bull; <strong>Currency:</strong> INR (₹)
        </td>
      </tr>
    </table>
  </div>

  <table class="data-table">
    <thead>
      <tr>
        <th style="width: 28px; text-align: center;">S.No.</th>
        <th style="width: 125px;">Book / Notebook Name</th>
        <th style="width: 55px;">Brand</th>
        <th style="width: 160px;">Available Size / Variety</th>
        <th style="width: 40px; text-align: center;">Page Count</th>
        <th style="width: 55px; text-align: right;">MRP</th>
        <th style="width: 58px; text-align: right;">Sale Price</th>
        <th style="width: 66px; text-align: right;">Sell Price</th>
        <th style="width: 66px; text-align: right;">Discount Price</th>
        <th style="width: 90px; text-align: center;">Availability</th>
      </tr>
    </thead>
    <tbody>
      ' . $tableRowsHtml . '
    </tbody>
  </table>
</body>
</html>';

    // 4. Generate PDF via Dompdf
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($pdfHtml);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    // 5. Render dynamic footer line, appreciation text, contact info & page numbers across all pages
    $canvas = $dompdf->getCanvas();
    $fontMetrics = $dompdf->getFontMetrics();
    $fontNormal = $fontMetrics->getFont('DejaVu Sans', 'normal');

    $canvas->line(28, 560, 814, 560, [0.85, 0.88, 0.92], 0.6);
    $footerNote = 'Thank you for your business | Rajpriya Notebook • Phone: ' . $companyPhone . ' • Email: ' . $companyEmail . ' • GSTIN: ' . $companyGstin;
    $canvas->page_text(28, 568, $footerNote, $fontNormal, 6.7, [0.4, 0.45, 0.5]);
    $canvas->page_text(745, 568, 'Page {PAGE_NUM} of {PAGE_COUNT}', $fontNormal, 7.2, [0.35, 0.4, 0.45]);

    $pdfContent = $dompdf->output();

    // 6. Stream PDF response directly to browser for download
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/pdf');
    $disposition = (!empty($_GET['inline']) || !empty($_GET['view'])) ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="rajpriya-wholesale-price-list-' . date('Y-m-d') . '.pdf"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Length: ' . strlen($pdfContent));
    echo $pdfContent;
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
  .price-list-page {
    padding-top: 2rem;
    padding-bottom: 4rem;
  }
  .price-list-hero {
    position: relative;
    overflow: hidden;
    padding: clamp(1.5rem, 4vw, 2.75rem);
    border: 1px solid rgba(200, 155, 60, 0.3);
    border-radius: 1.5rem;
    background: linear-gradient(120deg, #17243A 0%, #243552 68%, #344463 100%);
    box-shadow: 0 16px 36px rgba(23, 36, 58, 0.14);
    color: #fff;
  }
  .price-list-hero::after {
    position: absolute;
    top: -110px;
    right: -50px;
    width: 290px;
    height: 290px;
    border: 1px solid rgba(232, 199, 106, 0.22);
    border-radius: 50%;
    box-shadow: 0 0 0 28px rgba(232, 199, 106, 0.05), 0 0 0 58px rgba(232, 199, 106, 0.035);
    content: "";
    pointer-events: none;
  }
  .price-list-hero-content {
    position: relative;
    z-index: 1;
  }
  .price-list-eyebrow {
    color: #E8C76A;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.16em;
  }
  .price-list-title {
    margin: 0.4rem 0 0.5rem;
    color: #fff;
    font-family: var(--font-heading, Georgia, serif);
    font-size: clamp(1.8rem, 4vw, 2.65rem);
  }
  .price-list-subtitle {
    max-width: 560px;
    margin: 0;
    color: rgba(255, 255, 255, 0.76);
    font-size: 0.92rem;
  }
  .price-list-count {
    min-width: 132px;
    padding: 1rem 1.25rem;
    border: 1px solid rgba(232, 199, 106, 0.3);
    border-radius: 1rem;
    background: rgba(255, 255, 255, 0.08);
    text-align: center;
    backdrop-filter: blur(6px);
  }
  .price-list-count strong {
    display: block;
    color: #E8C76A;
    font-size: 1.65rem;
    line-height: 1.1;
  }
  .price-list-count span {
    display: block;
    margin-top: 0.3rem;
    color: rgba(255, 255, 255, 0.78);
    font-size: 0.72rem;
  }
  .price-list-toolbar {
    margin-top: 1.25rem;
    padding: 1rem;
    border: 1px solid #EAE2D3;
    border-radius: 1rem;
    background: #fff;
    box-shadow: 0 6px 20px rgba(23, 36, 58, 0.045);
  }
  .price-list-search {
    min-height: 48px;
    border: 1px solid #E7E1D6;
    border-radius: 0.75rem;
    background: #FCFBF8;
  }
  .price-list-search:focus-within {
    border-color: #C89B3C;
    box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.12);
  }
  .price-list-search input {
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
  }
  .price-list-table-card {
    overflow: hidden;
    margin-top: 1rem;
    border: 1px solid #EAE2D3;
    border-radius: 1rem;
    background: #fff;
    box-shadow: 0 10px 28px rgba(23, 36, 58, 0.06);
  }
  .price-list-table {
    min-width: 1080px;
  }
  .price-list-table thead th {
    padding-top: 1rem !important;
    padding-bottom: 1rem !important;
    border-bottom: 2px solid #C89B3C;
    background: #17243A;
    color: #fff !important;
    font-size: 0.76rem;
    letter-spacing: 0.025em;
    vertical-align: middle;
  }
  .price-list-table tbody td {
    padding-top: 0.95rem !important;
    padding-bottom: 0.95rem !important;
    border-color: #F0ECE4;
    font-size: 0.86rem;
    vertical-align: middle;
  }
  .price-list-table tbody tr:hover {
    background: #FCF8EF;
  }
  .price-list-book-name {
    color: #17243A;
    font-size: 0.92rem;
  }
  .price-list-price {
    color: #17243A;
    font-variant-numeric: tabular-nums;
    font-weight: 700;
  }
  .price-list-empty {
    padding: 4rem 1rem;
    border: 1px dashed #DCCDA9;
    border-radius: 1rem;
    background: #fff;
  }
  @media (max-width: 575.98px) {
    .price-list-page {
      padding-top: 1.25rem;
      padding-bottom: 2.5rem;
    }
    .price-list-hero {
      border-radius: 1.1rem;
    }
    .price-list-count {
      min-width: 104px;
      padding: 0.75rem;
    }
    .price-list-toolbar {
      padding: 0.8rem;
    }
  }
</style>

<div class="container price-list-page">
  <section class="price-list-hero">
    <div class="price-list-hero-content d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
      <div>
        <div class="price-list-eyebrow"><i class="bi bi-stars me-1"></i> RAJPRIYA WHOLESALE CATALOGUE</div>
        <h1 class="price-list-title">Notebook Price List</h1>
        <p class="price-list-subtitle">Explore our notebook collection, compare prices, and download the latest catalogue.</p>
      </div>
      <div class="price-list-count">
        <strong><?php echo number_format(count($rows)); ?></strong>
        <span><?php echo $search !== '' ? 'Matching price entries' : 'Active price entries'; ?></span>
      </div>
    </div>
  </section>

  <section class="price-list-toolbar" aria-label="Price list tools">
    <div class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center gap-3">
      <form method="GET" action="price_list.php" class="price-list-search d-flex align-items-center gap-2 px-3 flex-grow-1">
        <i class="bi bi-search text-gold" aria-hidden="true"></i>
        <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" class="flex-grow-1 py-2" placeholder="Search notebooks..." aria-label="Search notebooks">
        <?php if ($search !== ''): ?>
          <a href="price_list.php" class="text-secondary text-decoration-none px-1" aria-label="Clear search" title="Clear search"><i class="bi bi-x-circle"></i></a>
        <?php endif; ?>
        <button type="submit" class="btn btn-navy rounded-pill px-4">Search</button>
      </form>
      <a href="price_list.php?download=1<?php echo $search !== '' ? '&q=' . urlencode($search) : ''; ?>" class="btn btn-gold rounded-pill px-4 py-2 fw-bold text-center text-nowrap">
        <i class="bi bi-file-earmark-pdf me-1"></i> Download Price List
      </a>
    </div>
  </section>

  <?php if (empty($rows)): ?>
    <div class="price-list-empty text-center mt-3">
      <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width: 64px; height: 64px; background: #FCF8EF;">
        <i class="bi bi-journal-x fs-2 text-gold"></i>
      </div>
      <h2 class="h5 fw-bold text-navy mb-2"><?php echo $search !== '' ? 'No matching notebooks' : 'No active notebooks found'; ?></h2>
      <p class="text-secondary mb-0"><?php echo $search !== '' ? 'Try another notebook name, brand, size or page count.' : 'There are currently no active notebook records available.'; ?></p>
    </div>
  <?php else: ?>
    <div class="price-list-table-card">
      <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 px-md-4 py-3 border-bottom">
        <div class="fw-bold text-navy"><i class="bi bi-journal-text text-gold me-2"></i>Notebook collection</div>
        <div class="small text-secondary">Prices shown in <span class="fw-semibold text-navy">Indian Rupees (₹)</span></div>
      </div>
      <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 price-list-table">
        <thead>
          <tr>
            <th class="px-3">S.No.</th>
            <th class="px-3">Book / Notebook Name</th>
            <th class="px-3">Brand</th>
            <th class="px-3">Available Size / Variety</th>
            <th class="px-3 text-center">Page Count</th>
            <th class="px-3 text-nowrap text-end">MRP</th>
            <th class="px-3 text-nowrap text-end">Sale Price</th>
            <th class="px-3 text-nowrap text-end">Sell Price</th>
            <th class="px-3 text-nowrap text-end">Discount Price</th>
            <th class="px-3">Availability</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $index => $entry): ?>
            <?php
              $product = $entry['product'];
              $stock = (int)$entry['stock'];
              $bundleStock = (int)$entry['bundle_stock'];
              if ($bundleStock > 0) {
                  $availability = 'In Stock (' . number_format($bundleStock) . ' Bdl / ' . number_format($stock) . ' Pcs)';
              } elseif ($stock > 0) {
                  $availability = 'In Stock (' . number_format($stock) . ' Pcs)';
              } else {
                  $availability = 'Out of Stock';
              }
              $sizeText = trim((string)($product['size'] ?? ''));
              $varietyDisplay = (string)$entry['variety_display'];
            ?>
            <tr>
              <td class="px-3 text-secondary fw-semibold"><?php echo $index + 1; ?></td>
              <td class="px-3 py-3">
                <div class="price-list-book-name fw-bold"><?php echo htmlspecialchars($product['name'] ?? ''); ?></div>
                <?php if (!empty($product['category_name'])): ?>
                  <div class="small text-muted" style="font-size: 0.75rem;"><?php echo htmlspecialchars($product['category_name']); ?></div>
                <?php endif; ?>
              </td>
              <td class="px-3 py-3 text-secondary"><?php echo !empty($product['brand_name']) ? htmlspecialchars($product['brand_name']) : '<span class="text-muted">—</span>'; ?></td>
              <td class="px-3 py-3">
                <div class="fw-semibold text-dark"><?php echo htmlspecialchars($sizeText !== '' ? $sizeText : 'Standard'); ?></div>
                <div class="small text-muted mt-1" style="font-size: 0.75rem;">
                  <span class="fw-semibold text-secondary">Variety:</span> <?php echo htmlspecialchars($varietyDisplay); ?>
                </div>
              </td>
              <td class="px-3 py-3 text-secondary text-center"><?php echo !empty($product['pages']) ? htmlspecialchars((string)$product['pages']) : '<span class="text-muted">—</span>'; ?></td>
              <td class="px-3 text-nowrap text-end price-list-price">
                <?php echo $entry['mrp'] !== null ? format_price($entry['mrp']) : '<span class="text-muted">—</span>'; ?>
              </td>
              <td class="px-3 py-3 text-nowrap text-end">
                <?php echo $entry['sale_price'] !== null ? format_price($entry['sale_price']) : '<span class="text-muted">—</span>'; ?>
              </td>
              <td class="px-3 py-3 text-nowrap text-end price-list-price">
                <?php echo $entry['selling_price'] !== null ? format_price($entry['selling_price']) : '<span class="text-muted">—</span>'; ?>
              </td>
              <td class="px-3 py-3 text-nowrap text-end">
                <?php echo $entry['discounted_price'] !== null ? format_price($entry['discounted_price']) : '<span class="text-muted">—</span>'; ?>
              </td>

              <td class="px-3 py-3">
                <span class="badge rounded-pill px-3 py-2 <?php echo ($bundleStock > 0 || $stock > 0) ? 'bg-success-subtle text-success-emphasis' : 'bg-danger-subtle text-danger-emphasis'; ?>">
                  <i class="bi <?php echo ($bundleStock > 0 || $stock > 0) ? 'bi-check-circle-fill' : 'bi-dash-circle-fill'; ?> me-1"></i><?php echo htmlspecialchars($availability); ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
