<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();

// Counts for stats
$catCount = $db->query("SELECT COUNT(*) FROM categories")->fetchColumn();
$prodCount = $db->query("SELECT COUNT(*) FROM products")->fetchColumn();

require_once __DIR__ . '/includes/admin_header.php';
?>

<style>
/* ─── Excel Manager Page Styles ─── */
.excel-page-title {
  font-size: 1.5rem;
  font-weight: 700;
  color: #17243A;
  letter-spacing: -0.3px;
}
.excel-page-subtitle {
  color: #64748B;
  font-size: 0.875rem;
}
.excel-section-card {
  background: #ffffff;
  border-radius: 14px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 2px 8px rgba(0,0,0,0.05);
  overflow: hidden;
  transition: box-shadow 0.2s ease;
}
.excel-section-card:hover {
  box-shadow: 0 4px 16px rgba(0,0,0,0.09);
}
.excel-section-header {
  padding: 18px 22px 14px;
  display: flex;
  align-items: center;
  gap: 14px;
  border-bottom: 1px solid #f1f5f9;
}
.excel-section-icon {
  width: 46px; height: 46px; min-width: 46px;
  border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.35rem;
}
.icon-category { background-color: #f0fdf4; color: #16a34a; }
.icon-product  { background-color: #eff6ff; color: #2563eb; }
.excel-section-title {
  font-size: 1rem;
  font-weight: 700;
  color: #17243A;
  margin: 0;
}
.excel-section-meta {
  font-size: 0.8rem;
  color: #64748b;
}
.excel-section-body {
  padding: 20px 22px;
}

/* Action Buttons Row */
.excel-action-row {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 10px;
  margin-bottom: 20px;
}
@media (max-width: 768px) {
  .excel-action-row { grid-template-columns: 1fr; }
}

.excel-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 16px 10px;
  border-radius: 10px;
  border: 1.5px solid transparent;
  font-size: 0.8rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  transition: all 0.2s ease;
  text-align: center;
  line-height: 1.3;
}
.excel-btn i { font-size: 1.5rem; }
.excel-btn-template {
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #15803d;
}
.excel-btn-template:hover {
  background: #dcfce7;
  border-color: #4ade80;
  color: #14532d;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(34,197,94,0.18);
}
.excel-btn-upload {
  background: #fff7ed;
  border-color: #fed7aa;
  color: #c2410c;
}
.excel-btn-upload:hover {
  background: #ffedd5;
  border-color: #fb923c;
  color: #9a3412;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(249,115,22,0.18);
}
.excel-btn-export {
  background: #eff6ff;
  border-color: #bfdbfe;
  color: #1d4ed8;
}
.excel-btn-export:hover {
  background: #dbeafe;
  border-color: #93c5fd;
  color: #1e3a8a;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(59,130,246,0.18);
}

/* Upload Zone */
.upload-zone {
  background: #f8fafc;
  border: 2px dashed #cbd5e1;
  border-radius: 10px;
  padding: 24px 20px;
  text-align: center;
  cursor: pointer;
  transition: all 0.2s ease;
}
.upload-zone:hover {
  border-color: #C89B3C;
  background: #fffbf0;
}
.upload-zone i { font-size: 2rem; color: #C89B3C; }
.upload-zone label { cursor: pointer; }

/* Step indicator */
.upload-steps {
  display: flex;
  align-items: center;
  gap: 0;
  margin-bottom: 16px;
  font-size: 0.73rem;
  font-weight: 600;
  color: #94a3b8;
}
.upload-steps .step {
  flex: 1;
  text-align: center;
  padding: 7px 4px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-right: none;
  position: relative;
}
.upload-steps .step:first-child { border-radius: 6px 0 0 6px; }
.upload-steps .step:last-child  { border-radius: 0 6px 6px 0; border-right: 1px solid #e2e8f0; }
.upload-steps .step.active { background: #17243A; color: #ffffff; border-color: #17243A; }

/* Info box */
.excel-info-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 15px;
  font-size: 0.8rem;
  color: #475569;
}
.excel-info-box h6 { font-size: 0.82rem; font-weight: 700; color: #17243A; margin-bottom: 6px; }
.excel-info-box ul { margin: 0; padding-left: 18px; }
.excel-info-box li { margin-bottom: 3px; }

/* Stats strip */
.excel-stat-strip {
  display: flex;
  gap: 10px;
  margin-bottom: 20px;
}
.excel-stat-pill {
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 50px;
  padding: 5px 14px;
  font-size: 0.78rem;
  font-weight: 600;
  color: #334155;
  display: flex;
  align-items: center;
  gap: 6px;
}
.excel-stat-pill i { color: #C89B3C; }
</style>

<!-- Page Header -->
<div class="d-flex justify-content-between align-items-start mb-4 admin-page-header">
  <div>
    <h3 class="excel-page-title"><i class="bi bi-file-earmark-spreadsheet text-gold me-2"></i>Excel Import / Export Manager</h3>
    <p class="excel-page-subtitle mb-0">Bulk manage categories and products using Excel / CSV files. Download templates, upload data, preview changes before applying.</p>
  </div>
</div>

<!-- Stats Strip -->
<div class="excel-stat-strip">
  <div class="excel-stat-pill"><i class="bi bi-tags-fill"></i> <?php echo $catCount; ?> Categories in Database</div>
  <div class="excel-stat-pill"><i class="bi bi-journal-text"></i> <?php echo $prodCount; ?> Products in Database</div>
  <div class="excel-stat-pill"><i class="bi bi-box-seam"></i> Bulk Variety Stock Management Available</div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     TALLY ERP 9 / TALLYPRIME INTEGRATION SECTION
════════════════════════════════════════════════════════════════════════════ -->
<div class="excel-section-card mb-4" style="border-left: 4px solid #0F2C59;">
  <div class="excel-section-header">
    <div class="excel-section-icon" style="background-color: #eff6ff; color: #0F2C59;">
      <i class="bi bi-upc-scan"></i>
    </div>
    <div class="d-flex justify-content-between align-items-center w-100 flex-wrap gap-2">
      <div>
        <div class="excel-section-title">Tally ERP 9 / TallyPrime Product Master &amp; Barcode Integration</div>
        <div class="excel-section-meta">Export compatible Stock Item Masters with bundle barcode aliases so scanning barcodes in Tally automatically identifies products.</div>
      </div>
      <a href="tally_products_export.php" class="btn text-white btn-sm fw-bold px-3" style="background-color: #0F2C59;">
        <i class="bi bi-gear-wide-connected me-1"></i> Open Tally Integration Hub
      </a>
    </div>
  </div>
  <div class="excel-section-body">
    <div class="excel-action-row" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
      <a href="tally_products_export.php?action=xml" class="excel-btn excel-btn-export" style="background: #f0fdf4; border-color: #86efac; color: #166534;">
        <i class="bi bi-file-earmark-code"></i>
        <span>Export All to<br>Tally XML (65 Items)</span>
      </a>
      <a href="tally_products_export.php?action=xml_sample" class="excel-btn excel-btn-template">
        <i class="bi bi-check2-circle"></i>
        <span>Export Test Sample<br>XML (3 Items)</span>
      </a>
      <a href="tally_products_export.php?action=csv" class="excel-btn excel-btn-export">
        <i class="bi bi-file-earmark-spreadsheet"></i>
        <span>Export All to<br>Tally Excel/CSV</span>
      </a>
      <a href="tally_products_export.php" class="excel-btn excel-btn-upload" style="background: #fefce8; border-color: #fde047; color: #854d0e;">
        <i class="bi bi-upc"></i>
        <span>Live Barcode<br>Scanner Simulator</span>
      </a>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     STOCK EXCEL BULK MANAGEMENT SECTION
════════════════════════════════════════════════════════════════════════════ -->
<div class="excel-section-card mb-4" style="border-left: 4px solid #C89B3C;">
  <div class="excel-section-header">
    <div class="excel-section-icon" style="background-color: #fefce8; color: #b45309;">
      <i class="bi bi-boxes"></i>
    </div>
    <div class="d-flex justify-content-between align-items-center w-100 flex-wrap gap-2">
      <div>
        <div class="excel-section-title">Product Variety Stock Bulk Management</div>
        <div class="excel-section-meta">Upload Excel to update stock for multiple products and varieties at once, or export current levels.</div>
      </div>
      <a href="stock.php" class="btn btn-warning btn-sm fw-bold px-3">
        <i class="bi bi-box-seam me-1"></i> Open Stock Manager
      </a>
    </div>
  </div>
  <div class="excel-section-body">
    <div class="excel-action-row">
      <a href="stock_excel_action.php?action=template" class="excel-btn excel-btn-template">
        <i class="bi bi-file-earmark-arrow-down"></i>
        <span>Download Stock<br>Excel Template</span>
      </a>
      <a href="stock.php?upload=1" class="excel-btn excel-btn-upload">
        <i class="bi bi-cloud-upload"></i>
        <span>Upload Stock<br>Excel / CSV</span>
      </a>
      <a href="stock_excel_action.php?action=export" class="excel-btn excel-btn-export">
        <i class="bi bi-file-earmark-excel"></i>
        <span>Download Current<br>Stock (Export)</span>
      </a>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     CATEGORY EXCEL SECTION
════════════════════════════════════════════════════════════════════════════ -->
<div class="excel-section-card mb-4">
  <div class="excel-section-header">
    <div class="excel-section-icon icon-category">
      <i class="bi bi-tags-fill"></i>
    </div>
    <div>
      <div class="excel-section-title">Category Excel Management</div>
      <div class="excel-section-meta">Add new categories or update existing ones in bulk via Excel/CSV</div>
    </div>
  </div>
  <div class="excel-section-body">

    <!-- Quick Action Buttons -->
    <div class="excel-action-row">
      <a href="category_excel_action.php?action=template" class="excel-btn excel-btn-template" id="cat-template-btn">
        <i class="bi bi-file-earmark-arrow-down"></i>
        <span>Download Category<br>Excel Template</span>
      </a>
      <button type="button" class="excel-btn excel-btn-upload" id="cat-upload-trigger" onclick="showCatUpload()">
        <i class="bi bi-cloud-upload"></i>
        <span>Upload Category<br>Excel / CSV</span>
      </button>
      <a href="category_excel_action.php?action=export" class="excel-btn excel-btn-export" id="cat-export-btn">
        <i class="bi bi-file-earmark-excel"></i>
        <span>Export Existing<br>Categories Excel</span>
      </a>
    </div>

    <!-- Upload Form (hidden initially) -->
    <div id="cat-upload-area" class="d-none">
      <div class="upload-steps mb-3">
        <div class="step active">1. Select File</div>
        <div class="step">2. Validate</div>
        <div class="step">3. Preview</div>
        <div class="step">4. Confirm</div>
      </div>

      <form id="catUploadForm" enctype="multipart/form-data">
        <div class="upload-zone mb-3" onclick="document.getElementById('cat_excel_file').click()">
          <i class="bi bi-cloud-arrow-up d-block mb-2"></i>
          <label class="fw-semibold text-navy d-block">Click to select Excel or CSV file</label>
          <input type="file" id="cat_excel_file" name="cat_excel_file" accept=".csv,.xlsx,.xls" class="d-none" onchange="handleCatFileSelect(this)">
          <div id="cat_selected_file" class="small fw-semibold text-success mt-2"></div>
        </div>
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-sm fw-bold px-4" style="background-color: #C89B3C; color:#fff;" id="cat-upload-btn">
            <i class="bi bi-search me-1"></i> Validate & Preview
          </button>
          <button type="button" class="btn btn-sm btn-secondary px-3" onclick="hideCatUpload()">Cancel</button>
        </div>
        <div id="cat_upload_spinner" class="d-none mt-2">
          <span class="spinner-border spinner-border-sm text-warning me-1"></span>
          <span class="small fw-semibold text-navy">Parsing and validating category data...</span>
        </div>
      </form>
    </div>

    <!-- Info box -->
    <div class="excel-info-box mt-3">
      <h6><i class="bi bi-info-circle me-1"></i> Category Template Columns</h6>
      <ul>
        <li><strong>Category ID</strong> – Leave blank for new categories. Provide existing ID to update.</li>
        <li><strong>Category Name</strong> – Required. Must be unique.</li>
        <li><strong>Description</strong> – Optional short description.</li>
        <li><strong>Status</strong> – Use <code>1</code> for Active, <code>0</code> for Disabled.</li>
      </ul>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     PRODUCT EXCEL SECTION
════════════════════════════════════════════════════════════════════════════ -->
<div class="excel-section-card mb-4">
  <div class="excel-section-header">
    <div class="excel-section-icon icon-product">
      <i class="bi bi-journal-text"></i>
    </div>
    <div>
      <div class="excel-section-title">Product Excel Management</div>
      <div class="excel-section-meta">Replace the product catalogue from an Excel / CSV file: matching products are updated, new products are added, and products missing from the file are removed.</div>
    </div>
  </div>
  <div class="excel-section-body">

    <!-- Quick Action Buttons -->
    <div class="excel-action-row">
      <a href="excel_action.php?action=template" class="excel-btn excel-btn-template" id="prod-template-btn">
        <i class="bi bi-file-earmark-arrow-down"></i>
        <span>Download Product<br>Excel Template</span>
      </a>
      <button type="button" class="excel-btn excel-btn-upload" id="prod-upload-trigger" onclick="showProdUpload()">
        <i class="bi bi-cloud-upload"></i>
        <span>Upload Product<br>Excel / CSV</span>
      </button>
      <a href="excel_action.php?action=export" class="excel-btn excel-btn-export" id="prod-export-btn">
        <i class="bi bi-file-earmark-excel"></i>
        <span>Export Existing<br>Products Excel</span>
      </a>
    </div>

    <!-- Upload Form (hidden initially) -->
    <div id="prod-upload-area" class="d-none">
      <div class="upload-steps mb-3">
        <div class="step active">1. Select File</div>
        <div class="step">2. Validate</div>
        <div class="step">3. Preview</div>
        <div class="step">4. Confirm</div>
      </div>

      <form id="prodUploadForm" enctype="multipart/form-data">
        <div class="upload-zone mb-3" onclick="document.getElementById('prod_excel_file').click()">
          <i class="bi bi-cloud-arrow-up d-block mb-2"></i>
          <label class="fw-semibold text-navy d-block">Click to select Excel or CSV file</label>
          <input type="file" id="prod_excel_file" name="excel_file" accept=".csv,.xlsx,.xls" class="d-none" onchange="handleProdFileSelect(this)">
          <div id="prod_selected_file" class="small fw-semibold text-success mt-2"></div>
        </div>
        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-sm fw-bold px-4" style="background-color: #C89B3C; color:#fff;" id="prod-upload-btn">
            <i class="bi bi-search me-1"></i> Validate & Preview
          </button>
          <button type="button" class="btn btn-sm btn-secondary px-3" onclick="hideProdUpload()">Cancel</button>
        </div>
        <div id="prod_upload_spinner" class="d-none mt-2">
          <span class="spinner-border spinner-border-sm text-warning me-1"></span>
          <span class="small fw-semibold text-navy">Parsing and validating product data...</span>
        </div>
      </form>
    </div>

    <!-- Info box -->
    <div class="excel-info-box mt-3">
      <h6><i class="bi bi-info-circle me-1"></i> Product Template Key Columns</h6>
      <ul>
        <li><strong>Product ID / SKU</strong> – Used to match existing products for update. Leave blank to insert new.</li>
        <li><strong>Full catalogue replacement</strong> – Include every product you want to keep. Products missing from the uploaded file will be removed.</li>
        <li><strong>Category Name</strong> – Must match an existing category name exactly.</li>
        <li><strong>Ruling Option</strong> – Values: <code>Both</code>, <code>Ruled</code>, <code>Unruled</code>, <code>Maths Plain</code>, <code>Maths Ruled</code>, <code>Double Line</code>, <code>Four Line</code>, <code>Square</code>, <code>One Side Ruled</code>, <code>Standard</code></li>
        <li><strong>Prices</strong> – Selling Price Ruled / Unruled, Discounted Price, MRP, Rate/Price.</li>
        <li><strong>Stock</strong> – Single piece stock quantity.</li>
      </ul>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     CATEGORY PREVIEW MODAL
════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="catPreviewModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg rounded-3">
      <div class="modal-header text-white" style="background-color: #17243A;">
        <h5 class="modal-title fw-bold fs-6">
          <i class="bi bi-eye-fill text-gold me-2"></i> Category Import Preview &amp; Verification
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
          <div class="col-6 col-md-3">
            <div class="p-3 rounded border bg-primary-subtle border-primary-subtle text-center">
              <div class="fs-4 fw-bold text-primary" id="cat_prev_update">0</div>
              <div class="small text-primary fw-semibold">Will Update</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-3 rounded border bg-success-subtle border-success-subtle text-center">
              <div class="fs-4 fw-bold text-success" id="cat_prev_new">0</div>
              <div class="small text-success fw-semibold">New Categories</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-3 rounded border bg-light border-secondary-subtle text-center">
              <div class="fs-4 fw-bold text-secondary" id="cat_prev_unchanged">0</div>
              <div class="small text-secondary fw-semibold">Unchanged</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-3 rounded border bg-danger-subtle border-danger-subtle text-center">
              <div class="fs-4 fw-bold text-danger" id="cat_prev_errors">0</div>
              <div class="small text-danger fw-semibold">Errors</div>
            </div>
          </div>
        </div>

        <div class="table-responsive border rounded" style="max-height: 380px;">
          <table class="table table-hover align-middle mb-0" style="font-size: 0.825rem;">
            <thead class="table-light sticky-top">
              <tr>
                <th style="width: 60px;">Row #</th>
                <th>Category Name</th>
                <th>Action</th>
                <th>Changes / Details</th>
              </tr>
            </thead>
            <tbody id="catPreviewTbody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer bg-light">
        <form action="category_excel_action.php" method="POST" id="catCommitForm">
          <input type="hidden" name="action" value="commit">
          <input type="hidden" name="import_token" id="cat_import_token" value="">
          <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm px-4 fw-bold" style="background:#C89B3C;color:#fff;" id="catConfirmBtn">
            <i class="bi bi-check-circle-fill me-1"></i> Confirm &amp; Apply Category Import
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════
     PRODUCT PREVIEW MODAL
════════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="prodPreviewModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg rounded-3">
      <div class="modal-header text-white" style="background-color: #17243A;">
        <h5 class="modal-title fw-bold fs-6">
          <i class="bi bi-eye-fill text-gold me-2"></i> Product Import Preview &amp; Verification
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-4">
        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
          <div class="col-6 col-md-3">
            <div class="p-3 rounded border bg-primary-subtle border-primary-subtle text-center">
              <div class="fs-4 fw-bold text-primary" id="prod_prev_update">0</div>
              <div class="small text-primary fw-semibold">Will Update</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-3 rounded border bg-success-subtle border-success-subtle text-center">
              <div class="fs-4 fw-bold text-success" id="prod_prev_new">0</div>
              <div class="small text-success fw-semibold">New Products</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-3 rounded border bg-light border-secondary-subtle text-center">
              <div class="fs-4 fw-bold text-secondary" id="prod_prev_unchanged">0</div>
              <div class="small text-secondary fw-semibold">Unchanged</div>
            </div>
          </div>
          <div class="col-6 col-md-3">
            <div class="p-3 rounded border bg-danger-subtle border-danger-subtle text-center">
              <div class="fs-4 fw-bold text-danger" id="prod_prev_errors">0</div>
              <div class="small text-danger fw-semibold">Errors</div>
            </div>
          </div>
        </div>

        <div class="alert alert-warning small mb-3" role="alert">
          <strong>This upload replaces the entire product catalogue.</strong>
          <span id="prod_replacement_summary">Products not included in the file will be removed.</span>
          Removed products also lose their variants, cart entries, reviews, and stock history. Past orders remain, but their links to removed products are cleared. Imports with validation errors cannot be applied.
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
            <tbody id="prodPreviewTbody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer bg-light">
        <form action="excel_action.php" method="POST" id="prodCommitForm">
          <input type="hidden" name="action" value="commit">
          <input type="hidden" name="redirect" value="products">
          <input type="hidden" name="import_token" id="prod_import_token" value="">
          <button type="button" class="btn btn-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm px-4 fw-bold" style="background:#C89B3C;color:#fff;" id="prodConfirmBtn">
            <i class="bi bi-check-circle-fill me-1"></i> Confirm &amp; Apply Product Import
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
/* ───── Category Upload Section ───── */
function showCatUpload() {
  document.getElementById('cat-upload-area').classList.remove('d-none');
  document.getElementById('cat-upload-trigger').classList.add('d-none');
}
function hideCatUpload() {
  document.getElementById('cat-upload-area').classList.add('d-none');
  document.getElementById('cat-upload-trigger').classList.remove('d-none');
}
function handleCatFileSelect(input) {
  if (input.files && input.files[0]) {
    document.getElementById('cat_selected_file').textContent = '✔ Selected: ' + input.files[0].name;
  }
}

document.getElementById('catUploadForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const fileInput = document.getElementById('cat_excel_file');
  if (!fileInput.files || fileInput.files.length === 0) {
    alert('Please select a file first.');
    return;
  }
  const spinner = document.getElementById('cat_upload_spinner');
  spinner.classList.remove('d-none');
  const btn = document.getElementById('cat-upload-btn');
  btn.disabled = true;

  const formData = new FormData(this);

  fetch('category_excel_action.php?action=preview', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    spinner.classList.add('d-none');
    btn.disabled = false;

    if (!data.success) {
      alert(data.message || 'Error parsing file.');
      return;
    }

    // Populate summary counters
    document.getElementById('cat_prev_update').textContent    = data.updated_count;
    document.getElementById('cat_prev_new').textContent       = data.new_count;
    document.getElementById('cat_prev_unchanged').textContent = data.unchanged_count;
    document.getElementById('cat_prev_errors').textContent    = data.error_count;
    document.getElementById('cat_import_token').value         = data.token;

    // Render preview rows
    const tbody = document.getElementById('catPreviewTbody');
    tbody.innerHTML = '';
    (data.items || []).forEach(item => {
      const tr = document.createElement('tr');

      let badge = '';
      if (item.action === 'update')    badge = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold">UPDATE</span>';
      else if (item.action === 'new')  badge = '<span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold">NEW</span>';
      else if (item.action === 'unchanged') badge = '<span class="badge bg-secondary-subtle text-secondary">UNCHANGED</span>';
      else badge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-semibold">ERROR</span>';

      let detailHtml = '';
      if (item.errors && item.errors.length > 0) {
        detailHtml = '<div class="text-danger small fw-semibold">' + item.errors.join('<br>') + '</div>';
      } else if (item.action === 'update' && item.diffs && Object.keys(item.diffs).length > 0) {
        const diffList = [];
        for (let key in item.diffs) {
          const d = item.diffs[key];
          diffList.push(`<span class="fw-semibold text-dark">${d.label}:</span> <span class="text-muted text-decoration-line-through">${d.old}</span> &rarr; <span class="text-success fw-bold">${d.new}</span>`);
        }
        detailHtml = diffList.join('<br>');
      } else if (item.action === 'new') {
        detailHtml = '<span class="text-success small fw-semibold"><i class="bi bi-plus-circle me-1"></i>New category will be created</span>';
      } else {
        detailHtml = '<span class="text-muted small">No changes detected</span>';
      }

      tr.innerHTML = `
        <td class="fw-bold text-secondary">${item.row_num}</td>
        <td class="fw-semibold text-dark">${item.cat_name || '-'}</td>
        <td>${badge}</td>
        <td>${detailHtml}</td>
      `;
      tbody.appendChild(tr);
    });

    // Show preview modal
    new bootstrap.Modal(document.getElementById('catPreviewModal')).show();
  })
  .catch(err => {
    spinner.classList.add('d-none');
    btn.disabled = false;
    console.error(err);
    alert('An error occurred while uploading/parsing the file.');
  });
});

/* ───── Product Upload Section ───── */
function showProdUpload() {
  document.getElementById('prod-upload-area').classList.remove('d-none');
  document.getElementById('prod-upload-trigger').classList.add('d-none');
}
function hideProdUpload() {
  document.getElementById('prod-upload-area').classList.add('d-none');
  document.getElementById('prod-upload-trigger').classList.remove('d-none');
}
function handleProdFileSelect(input) {
  if (input.files && input.files[0]) {
    document.getElementById('prod_selected_file').textContent = '✔ Selected: ' + input.files[0].name;
  }
}

document.getElementById('prodUploadForm').addEventListener('submit', function(e) {
  e.preventDefault();
  const fileInput = document.getElementById('prod_excel_file');
  if (!fileInput.files || fileInput.files.length === 0) {
    alert('Please select a file first.');
    return;
  }
  const spinner = document.getElementById('prod_upload_spinner');
  spinner.classList.remove('d-none');
  const btn = document.getElementById('prod-upload-btn');
  btn.disabled = true;

  const formData = new FormData(this);

  fetch('excel_action.php?action=preview', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    spinner.classList.add('d-none');
    btn.disabled = false;

    if (!data.success) {
      alert(data.message || 'Error parsing file.');
      return;
    }

    document.getElementById('prod_prev_update').textContent    = data.updated_count;
    document.getElementById('prod_prev_new').textContent       = data.new_count;
    document.getElementById('prod_prev_unchanged').textContent = data.unchanged_count;
    document.getElementById('prod_prev_errors').textContent    = data.error_count;
    document.getElementById('prod_import_token').value         = data.token;
    const replacementSummary = document.getElementById('prod_replacement_summary');
    replacementSummary.textContent = data.error_count > 0
      ? 'Fix all validation errors before applying this replacement. No products will be removed while errors remain.'
      : `${data.products_to_remove} product(s) not included in this file will be removed.`;
    document.getElementById('prodConfirmBtn').disabled = data.error_count > 0;

    const tbody = document.getElementById('prodPreviewTbody');
    tbody.innerHTML = '';
    (data.items || []).forEach(item => {
      const tr = document.createElement('tr');

      let badge = '';
      if (item.action === 'update')    badge = '<span class="badge bg-primary-subtle text-primary border border-primary-subtle fw-semibold">UPDATE</span>';
      else if (item.action === 'new')  badge = '<span class="badge bg-success-subtle text-success border border-success-subtle fw-semibold">NEW</span>';
      else if (item.action === 'unchanged') badge = '<span class="badge bg-secondary-subtle text-secondary">UNCHANGED</span>';
      else badge = '<span class="badge bg-danger-subtle text-danger border border-danger-subtle fw-semibold">ERROR</span>';

      let detailHtml = '';
      if (item.errors && item.errors.length > 0) {
        detailHtml = '<div class="text-danger small fw-semibold">' + item.errors.join('<br>') + '</div>';
      } else if (item.action === 'update' && item.diffs) {
        const diffList = [];
        for (let key in item.diffs) {
          const d = item.diffs[key];
          diffList.push(`<span class="fw-semibold text-dark">${d.label}:</span> <span class="text-muted text-decoration-line-through">${d.old}</span> &rarr; <span class="text-success fw-bold">${d.new}</span>`);
        }
        detailHtml = diffList.join('<br>');
      } else if (item.action === 'new') {
        detailHtml = '<span class="text-success small fw-semibold"><i class="bi bi-plus-circle me-1"></i>New product will be inserted into database</span>';
      } else {
        detailHtml = '<span class="text-muted small">No changes detected in Excel row</span>';
      }

      tr.innerHTML = `
        <td class="fw-bold text-secondary">${item.row_num}</td>
        <td class="fw-bold text-navy">${item.sku || ('ID: ' + (item.matched_id || '-'))}</td>
        <td class="fw-semibold text-dark">${item.product_name}</td>
        <td>${badge}</td>
        <td>${detailHtml}</td>
      `;
      tbody.appendChild(tr);
    });

    new bootstrap.Modal(document.getElementById('prodPreviewModal')).show();
  })
  .catch(err => {
    spinner.classList.add('d-none');
    btn.disabled = false;
    console.error(err);
    alert('An error occurred while uploading/parsing the file.');
  });
});
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
