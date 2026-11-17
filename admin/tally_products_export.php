<?php
/**
 * Tally ERP 9 / TallyPrime Product Master & Barcode Integration Exporter
 * 
 * Supports:
 * - Full XML Master Import (Tally.ERP 9 & TallyPrime all releases via Import > Masters)
 * - Sample XML Master Import (3 sample products for initial test mapping)
 * - Full CSV / Excel Master Import (TallyPrime 4.0+ & Excel tools)
 * - Sample CSV / Excel Master Import
 * - Interactive Admin UI with Live Barcode Scanner Simulator & Mapping Table
 */
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();
$action = isset($_GET['action']) ? trim($_GET['action']) : '';

// ─────────────────────────────────────────────────────────────────────────────
// Helper: Fetch all variants mapped with parent product data
// ─────────────────────────────────────────────────────────────────────────────
function get_tally_mapped_products($db, $limit_sample = false) {
    $sql = "
        SELECT pv.*, 
               p.id as parent_product_id,
               p.name as prod_name, 
               p.sku as prod_sku, 
               p.pages as prod_pages, 
               p.price as prod_price, 
               p.mrp as prod_mrp, 
               p.gst_rate, 
               c.name as category_name
        FROM product_variants pv
        JOIN products p ON pv.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
    ";
    
    if ($limit_sample) {
        $sql .= " WHERE p.id = 205 LIMIT 3"; // Sample: POWER 192P Ruled, Unruled, Double Line
    } else {
        $sql .= " ORDER BY p.id ASC, pv.id ASC";
    }

    $stmt = $db->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $mapped = [];
    foreach ($rows as $v) {
        $pPages = !empty($v['page_count']) ? $v['page_count'] : (!empty($v['prod_pages']) ? $v['prod_pages'] : 192);
        $cleanPages = preg_replace('/[^0-9]/', '', (string)$pPages) ?: '192';
        $vDisplay = !empty($v['variety_display']) ? $v['variety_display'] : (!empty($v['variety']) ? $v['variety'] : 'Ruled');
        if (strcasecmp($vDisplay, 'Micro Point') === 0) {
            $vDisplay = 'Maths Plain';
        }
        $vCode = strtoupper(trim((string)$v['variety']));
        
        $shorthand = get_variant_shorthand_code($v['product_code'], $v['prod_name'], $cleanPages, $vCode);
        $productTitle = format_bundle_label_product_title($v['prod_name'], $cleanPages, $vDisplay, $vCode);
        
        $bundleQty = $v['bundle_qty'] > 0 ? (int)$v['bundle_qty'] : 320;
        $rate = $v['selling_price'] > 0 ? (float)$v['selling_price'] : ($v['prod_price'] > 0 ? (float)$v['prod_price'] : 19.63);
        $mrp = $v['prod_mrp'] > 0 ? (float)$v['prod_mrp'] : ($rate > 0 ? $rate : 20.00);
        $gstRate = !empty($v['gst_rate']) ? (float)$v['gst_rate'] : 18.0;

        // Deterministic unique barcodes & aliases
        $primaryBarcode = format_bundle_barcode_value($shorthand, $bundleQty, 1);
        $std400Barcode  = format_bundle_barcode_value($shorthand, 400, 1);
        $cleanAlpha     = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $shorthand));
        
        // Comprehensive list of aliases to ensure Tally matches no matter what is scanned
        $aliasCandidates = [
            $productTitle,
            $shorthand,
            $primaryBarcode,
            $std400Barcode,
            $cleanAlpha,
            $cleanAlpha . $bundleQty,
            $v['product_code'],
            $v['prod_sku'] . '-' . $vCode
        ];

        $uniqueAliases = [];
        foreach ($aliasCandidates as $cand) {
            $cand = trim((string)$cand);
            if (!empty($cand) && !in_array($cand, $uniqueAliases, true)) {
                $uniqueAliases[] = $cand;
            }
        }

        $mapped[] = [
            'variant_id'      => (int)$v['id'],
            'product_id'      => (int)$v['parent_product_id'],
            'item_name'       => $productTitle,
            'shorthand'       => $shorthand,
            'primary_barcode' => $primaryBarcode,
            'std400_barcode'  => $std400Barcode,
            'clean_alpha'     => $cleanAlpha,
            'db_code'         => $v['product_code'],
            'aliases'         => $uniqueAliases,
            'alias_string'    => implode(', ', array_slice($uniqueAliases, 1)),
            'category'        => !empty($v['category_name']) ? $v['category_name'] : 'Student Exercise Book - Laminated',
            'pages'           => $cleanPages,
            'variety'         => $vDisplay,
            'variety_code'    => $vCode,
            'bundle_qty'      => $bundleQty,
            'stock_qty'       => (int)($v['stock'] ?? 0),
            'rate'            => $rate,
            'mrp'             => $mrp,
            'gst_rate'        => $gstRate,
            'hsn_code'        => '4820',
            'base_unit'       => 'NOS',
            'alt_unit'        => 'BDL'
        ];
    }

    return $mapped;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1) Export XML (Compatible with ALL Tally versions: Tally.ERP 9 & TallyPrime)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'xml' || $action === 'xml_sample') {
    $isSample = ($action === 'xml_sample');
    $items = get_tally_mapped_products($db, $isSample);

    $filename = $isSample 
        ? 'rajpriya_tally_sample_stock_items_' . date('Ymd') . '.xml'
        : 'rajpriya_tally_all_stock_items_' . date('Ymd') . '.xml';

    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $envelope = $dom->createElement('ENVELOPE');
    $dom->appendChild($envelope);

    $header = $dom->createElement('HEADER');
    $tallyRequest = $dom->createElement('TALLYREQUEST', 'Import Data');
    $header->appendChild($tallyRequest);
    $envelope->appendChild($header);

    $body = $dom->createElement('BODY');
    $importData = $dom->createElement('IMPORTDATA');
    $body->appendChild($importData);
    $envelope->appendChild($body);

    $reqDesc = $dom->createElement('REQUESTDESC');
    $reqDesc->appendChild($dom->createElement('REPORTNAME', 'All Masters'));
    $staticVars = $dom->createElement('STATICVARIABLES');
    $staticVars->appendChild($dom->createElement('SVCURRENTCOMPANY', 'Rajpriya Notebooks'));
    $reqDesc->appendChild($staticVars);
    $importData->appendChild($reqDesc);

    $reqData = $dom->createElement('REQUESTDATA');
    $importData->appendChild($reqData);

    // Units of Measure (NOS & BDL)
    foreach (['NOS' => '0', 'BDL' => '0'] as $uName => $dec) {
        $tallyMsg = $dom->createElement('TALLYMESSAGE');
        $tallyMsg->setAttribute('xmlns:UDF', 'TallyUDF');
        $unit = $dom->createElement('UNIT');
        $unit->setAttribute('NAME', $uName);
        $unit->setAttribute('ACTION', 'Create');
        $unit->appendChild($dom->createElement('NAME', $uName));
        $unit->appendChild($dom->createElement('ISSIMPLEUNIT', 'Yes'));
        $unit->appendChild($dom->createElement('DECIMALPLACES', $dec));
        $tallyMsg->appendChild($unit);
        $reqData->appendChild($tallyMsg);
    }

    // Stock Groups (Categories)
    $categoriesSeen = [];
    foreach ($items as $it) {
        $cat = $it['category'];
        if (!isset($categoriesSeen[$cat])) {
            $categoriesSeen[$cat] = true;
            $tallyMsg = $dom->createElement('TALLYMESSAGE');
            $tallyMsg->setAttribute('xmlns:UDF', 'TallyUDF');
            $stkGrp = $dom->createElement('STOCKGROUP');
            $stkGrp->setAttribute('NAME', $cat);
            $stkGrp->setAttribute('ACTION', 'Create');
            $stkGrp->appendChild($dom->createElement('NAME', $cat));
            $tallyMsg->appendChild($stkGrp);
            $reqData->appendChild($tallyMsg);
        }
    }

    // Stock Items
    foreach ($items as $it) {
        $tallyMsg = $dom->createElement('TALLYMESSAGE');
        $tallyMsg->setAttribute('xmlns:UDF', 'TallyUDF');
        
        $stkItem = $dom->createElement('STOCKITEM');
        $stkItem->setAttribute('NAME', $it['item_name']);
        $stkItem->setAttribute('ACTION', 'Create');

        // NAME.LIST contains Item Name + All Barcode Aliases
        $nameList = $dom->createElement('NAME.LIST');
        foreach ($it['aliases'] as $alias) {
            $nameList->appendChild($dom->createElement('NAME', $alias));
        }
        $stkItem->appendChild($nameList);

        $stkItem->appendChild($dom->createElement('PARENT', $it['category']));
        $stkItem->appendChild($dom->createElement('BASEUNITS', 'NOS'));
        $stkItem->appendChild($dom->createElement('ADDITIONALUNITS', 'BDL'));
        $stkItem->appendChild($dom->createElement('PARTNO', $it['shorthand']));
        $stkItem->appendChild($dom->createElement('DESCRIPTION', "{$it['item_name']}, Pack: {$it['bundle_qty']} NOS/BDL, Barcode: {$it['primary_barcode']}"));
        $stkItem->appendChild($dom->createElement('ISBATCHWISEON', 'No'));
        $stkItem->appendChild($dom->createElement('ISPERISHABLEON', 'No'));
        
        // GST & Statutory Fields
        $stkItem->appendChild($dom->createElement('GSTAPPLICABLE', 'Applicable'));
        $stkItem->appendChild($dom->createElement('GSTTYPEOFSUPPLY', 'Goods'));
        $stkItem->appendChild($dom->createElement('HSNCODE', $it['hsn_code']));
        $stkItem->appendChild($dom->createElement('RATEOFVAT', (string)$it['gst_rate']));

        // Standard Selling Rate
        $stdRateList = $dom->createElement('STANDARDPRICELIST.LIST');
        $stdRateList->appendChild($dom->createElement('RATE', number_format($it['rate'], 2, '.', '') . '/NOS'));
        $stkItem->appendChild($stdRateList);

        $tallyMsg->appendChild($stkItem);
        $reqData->appendChild($tallyMsg);
    }

    echo $dom->saveXML();
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 2) Export CSV / Excel Format (TallyPrime 4.0+ & Spreadsheet Import)
// ─────────────────────────────────────────────────────────────────────────────
if ($action === 'csv' || $action === 'csv_sample') {
    $isSample = ($action === 'csv_sample');
    $items = get_tally_mapped_products($db, $isSample);

    $filename = $isSample 
        ? 'rajpriya_tally_sample_stock_items_' . date('Ymd') . '.csv'
        : 'rajpriya_tally_all_stock_items_' . date('Ymd') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    // Write UTF-8 BOM for Excel compatibility
    fputs($output, "\xEF\xBB\xBF");

    // Standard Tally Stock Item Master Headers
    fputcsv($output, [
        'Stock Item Name',
        'Primary Barcode (Bundle Label)',
        'Product Code (Shorthand)',
        'Alternative Barcode (400 Pack)',
        'Alphanumeric Code',
        'Database SKU',
        'All Aliases (Comma Separated for Tally)',
        'Stock Group (Category)',
        'Base Unit',
        'Alternate Unit',
        'Pack Size (NOS per BDL)',
        'Standard Rate (Per Piece)',
        'MRP',
        'GST Rate (%)',
        'HSN Code',
        'Type of Supply',
        'Current Stock (Pieces)'
    ]);

    foreach ($items as $it) {
        fputcsv($output, [
            $it['item_name'],
            $it['primary_barcode'],
            $it['shorthand'],
            $it['std400_barcode'],
            $it['clean_alpha'],
            $it['db_code'],
            $it['alias_string'],
            $it['category'],
            $it['base_unit'],
            $it['alt_unit'],
            $it['bundle_qty'],
            number_format($it['rate'], 2, '.', ''),
            number_format($it['mrp'], 2, '.', ''),
            $it['gst_rate'],
            $it['hsn_code'],
            'Goods',
            $it['stock_qty']
        ]);
    }

    fclose($output);
    exit();
}

// ─────────────────────────────────────────────────────────────────────────────
// 3) Interactive Admin UI with Live Barcode Scanner Simulator
// ─────────────────────────────────────────────────────────────────────────────
$allItems = get_tally_mapped_products($db, false);
$sampleItems = get_tally_mapped_products($db, true);

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="container-fluid py-4">
  
  <!-- Page Title & Navigation -->
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 admin-page-header">
    <div>
      <div class="d-flex align-items-center gap-2">
        <a href="excel-manager.php" class="btn btn-outline-secondary btn-sm py-1 px-2.5">
          <i class="bi bi-arrow-left me-1"></i> Excel Manager
        </a>
        <h3 class="fw-bold mb-0 text-navy" style="letter-spacing: -0.3px;">
          <i class="bi bi-upc-scan text-gold me-2"></i>Tally Barcode & Product Master Integration
        </h3>
      </div>
      <p class="text-muted small mb-0 mt-1">
        Seamlessly map Rajpriya physical bundle barcodes with Tally.ERP 9 and TallyPrime stock items.
      </p>
    </div>

    <!-- Quick Export Dropdown -->
    <div class="d-flex gap-2">
      <div class="btn-group shadow-sm">
        <a href="tally_products_export.php?action=xml" class="btn text-white fw-bold btn-sm" style="background-color: #0F2C59;">
          <i class="bi bi-file-earmark-code me-1.5 text-warning"></i> Export All to Tally XML (65 Items)
        </a>
        <button type="button" class="btn text-white btn-sm dropdown-toggle dropdown-toggle-split" style="background-color: #0b2244;" data-bs-toggle="dropdown" aria-expanded="false">
          <span class="visually-hidden">Toggle Dropdown</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow border-0 py-2">
          <li><h6 class="dropdown-header text-uppercase fw-bold text-muted small">Tally XML Format (All Tally Versions)</h6></li>
          <li>
            <a class="dropdown-item py-2" href="tally_products_export.php?action=xml_sample">
              <i class="bi bi-check-circle text-success me-2"></i> Export Sample XML (3 Test Items)
            </a>
          </li>
          <li>
            <a class="dropdown-item py-2" href="tally_products_export.php?action=xml">
              <i class="bi bi-boxes text-primary me-2"></i> Export Complete XML (All 65 Variants)
            </a>
          </li>
          <li><hr class="dropdown-divider"></li>
          <li><h6 class="dropdown-header text-uppercase fw-bold text-muted small">Excel / CSV Format (TallyPrime 4.0+)</h6></li>
          <li>
            <a class="dropdown-item py-2" href="tally_products_export.php?action=csv_sample">
              <i class="bi bi-file-earmark-spreadsheet text-success me-2"></i> Export Sample CSV (3 Test Items)
            </a>
          </li>
          <li>
            <a class="dropdown-item py-2" href="tally_products_export.php?action=csv">
              <i class="bi bi-file-earmark-excel text-primary me-2"></i> Export Complete CSV (All 65 Variants)
            </a>
          </li>
        </ul>
      </div>
    </div>
  </div>

  <!-- Key Architecture & Workflow Cards -->
  <div class="row g-3 mb-4">
    
    <!-- Card 1: How Barcode Mapping Works -->
    <div class="col-lg-4 col-md-6">
      <div class="card h-100 border-0 shadow-sm rounded-3">
        <div class="card-body p-3.5">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge bg-primary-subtle text-primary p-2 rounded-circle">
              <i class="bi bi-upc fs-5"></i>
            </span>
            <h6 class="fw-bold mb-0 text-navy">1. Preserved Barcode Architecture</h6>
          </div>
          <p class="text-secondary small mb-2">
            The existing Code 128 barcode format on bundle labels is <strong>100% preserved</strong> without changing existing designs:
          </p>
          <div class="bg-light p-2 rounded border font-monospace text-center small text-navy fw-bold mb-2">
            PWR192SR32001 &bull; PWR192SR40001
          </div>
          <ul class="text-muted small ps-3 mb-0">
            <li><strong>PWR-192-SR:</strong> Product Shorthand</li>
            <li><strong>320 / 400:</strong> Pack quantity in pieces</li>
            <li><strong>01:</strong> Consistent product identifier sequence</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Card 2: Why Each Variety is a Separate Stock Item -->
    <div class="col-lg-4 col-md-6">
      <div class="card h-100 border-0 shadow-sm rounded-3">
        <div class="card-body p-3.5">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge bg-success-subtle text-success p-2 rounded-circle">
              <i class="bi bi-diagram-3 fs-5"></i>
            </span>
            <h6 class="fw-bold mb-0 text-navy">2. Why Varieties Need Separate Stock Items</h6>
          </div>
          <p class="text-secondary small mb-2">
            In notebooks manufacturing, <strong>Ruled</strong> and <strong>Unruled</strong> have distinct physical ruling, separate warehouse stock, and distinct customer demand:
          </p>
          <ul class="text-muted small ps-3 mb-0">
            <li><strong>POWER 192P RULED (SR)</strong> &rarr; Barcode: <code>PWR192SR32001</code></li>
            <li><strong>POWER 192P UNRULED (UR)</strong> &rarr; Barcode: <code>PWR192UR32001</code></li>
            <li>Separate Stock Items allow Tally to accurately track stock, valuation, and sales for each ruling option independently!</li>
          </ul>
        </div>
      </div>
    </div>

    <!-- Card 3: Tally Settings Configuration -->
    <div class="col-lg-4 col-md-12">
      <div class="card h-100 border-0 shadow-sm rounded-3">
        <div class="card-body p-3.5">
          <div class="d-flex align-items-center gap-2 mb-2">
            <span class="badge bg-warning-subtle text-warning p-2 rounded-circle">
              <i class="bi bi-gear-fill fs-5"></i>
            </span>
            <h6 class="fw-bold mb-0 text-navy">3. Required Tally Configuration</h6>
          </div>
          <p class="text-secondary small mb-2">
            Ensure the following settings are enabled in Tally:
          </p>
          <ul class="text-muted small ps-3 mb-2">
            <li><strong>F12: Configuration &gt; Inventory:</strong> Set <em>"Provide Aliases along with Names"</em> to <strong>Yes</strong>.</li>
            <li><strong>F12: Configuration &gt; Inventory:</strong> Set <em>"Provide Part Numbers for Stock Items"</em> to <strong>Yes</strong>.</li>
            <li><strong>Barcode Scanner:</strong> Set scanner suffix to <strong>Enter (CR)</strong> so scanning auto-selects the item in Tally vouchers.</li>
          </ul>
        </div>
      </div>
    </div>

  </div>

  <!-- Live Barcode Scanner Simulator Tool -->
  <div class="card border-0 shadow-sm rounded-3 mb-4" style="background: linear-gradient(135deg, #0F2C59 0%, #17243A 100%); color: #FFFFFF;">
    <div class="card-body p-4">
      <div class="row align-items-center g-3">
        <div class="col-lg-6">
          <h5 class="fw-bold text-white mb-1">
            <i class="bi bi-lightning-charge-fill text-warning me-1.5"></i>Live Barcode-to-Tally Scanner Test
          </h5>
          <p class="text-white-50 small mb-0">
            Scan any physical bundle label using your barcode scanner (or type any code) to test how Tally identifies the item instantly.
          </p>
        </div>
        <div class="col-lg-6">
          <div class="input-group input-group-lg">
            <span class="input-group-text bg-white border-0 text-navy">
              <i class="bi bi-upc-scan fs-4"></i>
            </span>
            <input type="text" id="barcodeSimulatorInput" class="form-control border-0 font-monospace fw-bold text-navy" 
                   placeholder="Scan or type barcode (e.g. PWR192SR32001 or PWR-192-SR)" 
                   autofocus autocomplete="off">
            <button class="btn btn-warning fw-bold px-3" type="button" onclick="testScannerLookup()">
              <i class="bi bi-search me-1"></i> Scan
            </button>
          </div>
        </div>
      </div>

      <!-- Real-time Scanner Match Alert Box -->
      <div id="scannerResultBox" class="mt-3 p-3 rounded-3 bg-white text-dark shadow-sm" style="display: none;">
        <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
          <div>
            <span class="badge bg-success mb-1">
              <i class="bi bi-check-circle-fill me-1"></i> TALLY MATCH FOUND
            </span>
            <h5 class="fw-bold text-navy mb-1" id="resItemName">POWER 192P RULED (SR)</h5>
            <div class="small text-muted mb-2">
              Stock Group: <strong class="text-dark" id="resCategory">Student Exercise Book - Laminated</strong> &bull; 
              Rate: <strong class="text-dark" id="resRate">Rs. 19.63 / NOS</strong> &bull; 
              Pack Size: <strong class="text-dark" id="resPack">320 NOS</strong>
            </div>
            <div class="small">
              <strong>Recognized By Scanned Value:</strong> <span class="badge bg-light text-dark border font-monospace" id="resMatchedVal"></span>
            </div>
          </div>
          <div class="text-end">
            <a href="#" id="resLabelLink" target="_blank" class="btn btn-outline-dark btn-sm fw-bold">
              <i class="bi bi-printer me-1"></i> View Bundle Label
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Step-by-Step Tally Import Instructions Accordion -->
  <div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white py-3 border-0">
      <h6 class="fw-bold mb-0 text-navy">
        <i class="bi bi-journal-check text-primary me-2"></i>How to Import Rajpriya Product Masters into Tally
      </h6>
    </div>
    <div class="card-body p-4 pt-1">
      <div class="row g-4">
        
        <div class="col-md-6">
          <div class="p-3 rounded border bg-light h-100">
            <h6 class="fw-bold text-navy mb-2">
              <span class="badge bg-navy text-white me-1.5" style="background-color: #0F2C59;">Method A</span>
              Tally XML Import (Recommended &bull; Zero Add-ons Required)
            </h6>
            <ol class="small text-muted ps-3 mb-0" style="line-height: 1.6;">
              <li>Click <strong>Export to Tally XML</strong> at the top right to download <code>rajpriya_tally_all_stock_items.xml</code>.</li>
              <li>Open <strong>Tally.ERP 9</strong> or <strong>TallyPrime</strong> and select your Company.</li>
              <li><strong>In TallyPrime:</strong> Press <code>Alt + O</code> (Import) &gt; Select <strong>Masters</strong>.</li>
              <li><strong>In Tally.ERP 9:</strong> Gateway of Tally &gt; <strong>Import of Data</strong> &gt; <strong>Masters</strong>.</li>
              <li>Select file path <code>rajpriya_tally_all_stock_items.xml</code> and choose <em>"Modify with new data"</em>.</li>
              <li>Tally will automatically create all Stock Groups, Units (`NOS`, `BDL`), and all 65 Stock Items with complete Barcode Aliases in seconds!</li>
            </ol>
          </div>
        </div>

        <div class="col-md-6">
          <div class="p-3 rounded border bg-light h-100">
            <h6 class="fw-bold text-navy mb-2">
              <span class="badge bg-secondary text-white me-1.5">Method B</span>
              TallyPrime 4.0+ Excel Import / Custom Excel Connectors
            </h6>
            <ol class="small text-muted ps-3 mb-0" style="line-height: 1.6;">
              <li>Click <strong>Export Complete CSV</strong> to download <code>rajpriya_tally_all_stock_items.csv</code>.</li>
              <li>In TallyPrime 4.0 or higher: Go to <strong>Import &gt; Masters &gt; File Format: Excel</strong>.</li>
              <li>Map <code>Stock Item Name</code>, <code>Stock Group</code>, <code>Base Unit</code>, and <code>All Aliases</code>.</li>
              <li>Execute import. All stock items and barcode aliases will be loaded into Tally.</li>
            </ol>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- Complete Product Master & Barcode Mapping Table -->
  <div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div>
        <h6 class="fw-bold mb-0 text-navy">
          <i class="bi bi-table text-secondary me-2"></i>Product Master & Barcode Alias Mapping Table (<?php echo count($allItems); ?> Items)
        </h6>
        <span class="text-muted small">Every item is configured with unique aliases so scanning any physical label identifies the stock item in Tally.</span>
      </div>

      <!-- Table Search Filter -->
      <div class="d-flex align-items-center gap-2">
        <input type="text" id="tableFilterInput" class="form-control form-control-sm" placeholder="Search item, code, barcode..." style="width: 240px;">
      </div>
    </div>

    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small" id="tallyMappingTable">
        <thead class="table-light">
          <tr>
            <th class="ps-3" style="width: 40px;">#</th>
            <th>Tally Stock Item Name</th>
            <th>Primary Bundle Barcode</th>
            <th>Product Code</th>
            <th>Category (Stock Group)</th>
            <th class="text-center">Pack NOS</th>
            <th class="text-end">Standard Rate</th>
            <th class="text-center">Tally Aliases</th>
            <th class="text-center pe-3">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allItems as $idx => $it): ?>
            <tr class="tally-item-row" 
                data-item-name="<?php echo htmlspecialchars($it['item_name']); ?>"
                data-primary-barcode="<?php echo htmlspecialchars($it['primary_barcode']); ?>"
                data-shorthand="<?php echo htmlspecialchars($it['shorthand']); ?>"
                data-category="<?php echo htmlspecialchars($it['category']); ?>"
                data-rate="<?php echo number_format($it['rate'], 2); ?>"
                data-pack="<?php echo $it['bundle_qty']; ?>"
                data-variant-id="<?php echo $it['variant_id']; ?>"
                data-aliases="<?php echo htmlspecialchars(implode('|', $it['aliases'])); ?>">
              
              <td class="ps-3 text-muted"><?php echo $idx + 1; ?></td>
              
              <td>
                <span class="fw-bold text-navy d-block"><?php echo htmlspecialchars($it['item_name']); ?></span>
                <span class="text-muted font-monospace" style="font-size: 0.72rem;"><?php echo htmlspecialchars($it['db_code']); ?></span>
              </td>
              
              <td>
                <span class="badge bg-light text-dark border font-monospace px-2 py-1">
                  <?php echo htmlspecialchars($it['primary_barcode']); ?>
                </span>
              </td>
              
              <td>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle font-monospace">
                  <?php echo htmlspecialchars($it['shorthand']); ?>
                </span>
              </td>
              
              <td class="text-secondary"><?php echo htmlspecialchars($it['category']); ?></td>
              
              <td class="text-center fw-bold"><?php echo $it['bundle_qty']; ?></td>
              
              <td class="text-end fw-bold text-navy">Rs. <?php echo number_format($it['rate'], 2); ?></td>
              
              <td class="text-center">
                <span class="badge bg-secondary-subtle text-dark border" title="<?php echo htmlspecialchars($it['alias_string']); ?>">
                  <?php echo count($it['aliases']); ?> Aliases
                </span>
              </td>
              
              <td class="text-center pe-3">
                <a href="print_label.php?variant_id=<?php echo $it['variant_id']; ?>" target="_blank" class="btn btn-outline-dark btn-sm py-0.5 px-1.5" title="Print Bundle Label">
                  <i class="bi bi-printer"></i>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- Interactive Scanner & Search Script -->
<script>
const barcodeInput = document.getElementById('barcodeSimulatorInput');
const resultBox    = document.getElementById('scannerResultBox');
const rows         = document.querySelectorAll('.tally-item-row');

// Real-time lookup on input or scanner Enter key
barcodeInput.addEventListener('keydown', function(e) {
  if (e.key === 'Enter') {
    e.preventDefault();
    testScannerLookup();
  }
});

barcodeInput.addEventListener('input', function() {
  testScannerLookup();
});

function testScannerLookup() {
  const query = barcodeInput.value.trim().toUpperCase().replace(/[^A-Z0-9-]/g, '');
  if (!query) {
    resultBox.style.display = 'none';
    return;
  }

  let matchedRow = null;
  let matchedValue = '';

  rows.forEach(row => {
    if (matchedRow) return;
    const aliases = (row.getAttribute('data-aliases') || '').toUpperCase().split('|');
    const primaryBarcode = (row.getAttribute('data-primary-barcode') || '').toUpperCase();
    const shorthand = (row.getAttribute('data-shorthand') || '').toUpperCase();

    // Check exact or partial match
    for (let a of aliases) {
      const cleanA = a.replace(/[^A-Z0-9-]/g, '');
      if (cleanA === query || cleanA.replace(/-/g, '') === query.replace(/-/g, '')) {
        matchedRow = row;
        matchedValue = a;
        break;
      }
    }
  });

  if (matchedRow) {
    resultBox.style.display = 'block';
    document.getElementById('resItemName').textContent = matchedRow.getAttribute('data-item-name');
    document.getElementById('resCategory').textContent = matchedRow.getAttribute('data-category');
    document.getElementById('resRate').textContent = 'Rs. ' + matchedRow.getAttribute('data-rate') + ' / NOS';
    document.getElementById('resPack').textContent = matchedRow.getAttribute('data-pack') + ' NOS';
    document.getElementById('resMatchedVal').textContent = query + ' (Matches Alias: ' + matchedValue + ')';
    document.getElementById('resLabelLink').href = 'print_label.php?variant_id=' + matchedRow.getAttribute('data-variant-id');
    
    // Highlight table row
    rows.forEach(r => r.classList.remove('table-warning'));
    matchedRow.classList.add('table-warning');
    matchedRow.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  } else {
    resultBox.style.display = 'none';
  }
}

// Table Filter
document.getElementById('tableFilterInput').addEventListener('input', function() {
  const term = this.value.toLowerCase().trim();
  rows.forEach(row => {
    const text = row.textContent.toLowerCase();
    row.style.display = text.includes(term) ? '' : 'none';
  });
});
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
