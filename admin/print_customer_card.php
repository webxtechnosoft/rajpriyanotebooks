<?php
/**
 * Official Customer ID Card & QR Code Label Printable Template
 * 
 * Generates an official, high-resolution, print-ready Customer Identity Card
 * featuring:
 * - Rajpriya Notebooks Brand Header & Salem Contact Pill (9443346472)
 * - Customer Name, District, City, Phone, and Registration Date
 * - Customer ID in bold badge format (e.g. CHE-KRI-001)
 * - Scannable Code 128 Customer ID Barcode
 * - High-resolution Customer Verification QR Code encoding the tracking URL
 * - Clean CSS print styles for cardstock / sticker label printing
 */

require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();

$cust_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$cust_code = isset($_GET['code']) ? trim($_GET['code']) : '';

$customer = null;
if ($cust_id > 0) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$cust_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
} elseif (!empty($cust_code)) {
    $stmt = $db->prepare("SELECT * FROM users WHERE customer_code = ? LIMIT 1");
    $stmt->execute([$cust_code]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$customer) {
    echo "<div style='padding:40px; text-align:center; font-family:sans-serif;'><h3>Customer Not Found</h3><p>Could not locate the requested customer profile.</p><a href='customers.php'>&larr; Back to Customers</a></div>";
    exit();
}

$cCode = !empty($customer['customer_code']) ? $customer['customer_code'] : ('CUST' . str_pad($customer['id'], 5, '0', STR_PAD_LEFT));
$trackingUrl = get_customer_tracking_url($cCode);
$qrImg = get_customer_qr_image_url($cCode, 260);
$barcodeSvg = generate_code128_svg($cCode, 36);
$logos = get_active_logos();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <link rel="icon" href="<?php echo htmlspecialchars(get_site_url() . '/' . ltrim($logos['primary'], '/'), ENT_QUOTES, 'UTF-8'); ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer ID Card — <?php echo htmlspecialchars($cCode); ?> — RAJPRIYA NOTEBOOKS</title>
  
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Outfit:wght@600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

  <style>
    :root {
      --rp-navy: #17243A;
      --rp-navy-dark: #0F172A;
      --rp-gold: #C89B3C;
      --rp-cream: #FCF8EF;
      --rp-red: #D32F2F;
      --rp-border: #DDE2EC;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
      background: #F1F5F9;
      color: #1E293B;
      padding: 24px;
      display: flex;
      flex-direction: column;
      align-items: center;
      min-height: 100vh;
    }

    /* Toolbar for Admin Controls */
    .toolbar {
      width: 100%;
      max-width: 680px;
      background: #FFFFFF;
      padding: 12px 20px;
      border-radius: 12px;
      border: 1px solid #E2E8F0;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
      margin-bottom: 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .toolbar-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      border-radius: 8px;
      font-size: 0.85rem;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
      border: 1px solid transparent;
      transition: all 0.15s ease;
    }

    .btn-print {
      background: var(--rp-navy);
      color: #FFFFFF;
    }
    .btn-print:hover {
      background: #0E1726;
      color: var(--rp-gold);
    }

    .btn-back {
      background: #F8FAFC;
      border-color: #CBD5E1;
      color: #334155;
    }
    .btn-back:hover {
      background: #E2E8F0;
    }

    .btn-download {
      background: #FEF3C7;
      border-color: #FDE68A;
      color: #92400E;
    }
    .btn-download:hover {
      background: #FDE68A;
    }

    /* Print Card Canvas */
    .id-card-wrapper {
      width: 100%;
      max-width: 680px;
      background: #FFFFFF;
      border-radius: 16px;
      border: 2px solid #CBD5E1;
      box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.1);
      overflow: hidden;
      position: relative;
    }

    /* Card Header */
    .card-header {
      background: linear-gradient(135deg, #17243A 0%, #223758 100%);
      padding: 16px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      color: #FFFFFF;
      border-bottom: 3px solid var(--rp-gold);
    }

    .brand-title {
      font-family: 'Outfit', sans-serif;
      font-size: 1.35rem;
      font-weight: 900;
      letter-spacing: 1.5px;
      color: #FFFFFF;
      line-height: 1.1;
      text-transform: uppercase;
    }

    .brand-title span {
      color: var(--rp-gold);
    }

    .brand-subtitle {
      font-size: 0.72rem;
      color: #E2E8F0;
      font-weight: 600;
      letter-spacing: 0.8px;
      text-transform: uppercase;
      margin-top: 2px;
    }

    .contact-pill {
      background: var(--rp-red);
      color: #FFFFFF;
      padding: 6px 14px;
      border-radius: 50px;
      font-weight: 800;
      font-size: 0.8rem;
      letter-spacing: 0.5px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.2);
    }

    /* Card Main Body */
    .card-body {
      padding: 24px;
      display: grid;
      grid-template-columns: 1fr 200px;
      gap: 24px;
      align-items: center;
      background: #FFFFFF;
    }

    .cust-info-col {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .cust-badge-row {
      display: flex;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
    }

    .badge-label {
      font-size: 0.72rem;
      font-weight: 700;
      color: #64748B;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .cust-id-pill {
      background: var(--rp-navy);
      color: var(--rp-gold);
      font-family: 'Outfit', sans-serif;
      font-size: 1.15rem;
      font-weight: 800;
      letter-spacing: 1px;
      padding: 4px 14px;
      border-radius: 6px;
      display: inline-block;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .cust-name {
      font-family: 'Outfit', sans-serif;
      font-size: 1.5rem;
      font-weight: 800;
      color: var(--rp-navy);
      line-height: 1.2;
    }

    .cust-details-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
    }

    .cust-details-table td {
      padding: 4px 0;
      vertical-align: top;
    }

    .cust-details-table .lbl {
      color: #64748B;
      font-weight: 600;
      width: 100px;
    }

    .cust-details-table .val {
      color: #0F172A;
      font-weight: 700;
    }

    /* Barcode Section */
    .barcode-wrap {
      margin-top: 8px;
      padding-top: 8px;
      border-top: 1px dashed #CBD5E1;
    }

    .barcode-svg-container {
      height: 38px;
      display: flex;
      align-items: center;
    }

    .barcode-svg-container svg {
      height: 38px;
      max-width: 100%;
    }

    .barcode-text {
      font-family: monospace;
      font-size: 0.75rem;
      color: #475569;
      letter-spacing: 2px;
      font-weight: 700;
      margin-top: 2px;
    }

    /* QR Code Column */
    .qr-col {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      background: #F8FAFC;
      border: 2px dashed #CBD5E1;
      border-radius: 12px;
      padding: 14px;
    }

    .qr-image {
      width: 155px;
      height: 155px;
      border-radius: 8px;
      background: #FFFFFF;
      padding: 6px;
      border: 1px solid #E2E8F0;
      box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
      margin-bottom: 8px;
    }

    .qr-caption {
      font-size: 0.72rem;
      font-weight: 800;
      color: var(--rp-navy);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .qr-subcaption {
      font-size: 0.65rem;
      color: #64748B;
      margin-top: 2px;
    }

    /* Card Footer Bar */
    .card-footer {
      background: #F8FAFC;
      border-top: 1px solid #E2E8F0;
      padding: 10px 24px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-size: 0.72rem;
      color: #64748B;
    }

    .footer-highlight {
      font-weight: 700;
      color: var(--rp-navy);
    }

    /* Print Styles */
    @media print {
      body {
        background: #FFFFFF !important;
        padding: 0 !important;
      }

      .no-print {
        display: none !important;
      }

      .id-card-wrapper {
        border: 2px solid #000000 !important;
        box-shadow: none !important;
        border-radius: 8px !important;
        max-width: 100% !important;
        page-break-inside: avoid;
      }

      .card-header {
        background: #17243A !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      .contact-pill {
        background: #D32F2F !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }

      .cust-id-pill {
        background: #17243A !important;
        color: #C89B3C !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
      }
    }
  </style>
</head>
<body>

  <!-- Controls Toolbar (Hidden during print) -->
  <div class="toolbar no-print">
    <div style="display: flex; align-items: center; gap: 8px;">
      <a href="customers.php?view=<?php echo urlencode($cCode); ?>" class="toolbar-btn btn-back">
        <i class="bi bi-arrow-left"></i> Customer Profile
      </a>
      <span style="font-weight: 700; color: #64748B; font-size: 0.85rem;">
        ID: <span style="color: #17243A;"><?php echo htmlspecialchars($cCode); ?></span>
      </span>
    </div>
    <div style="display: flex; align-items: center; gap: 8px;">
      <a href="<?php echo htmlspecialchars($qrImg); ?>" download="QR-<?php echo htmlspecialchars($cCode); ?>.png" target="_blank" class="toolbar-btn btn-download">
        <i class="bi bi-download"></i> Download QR
      </a>
      <button onclick="window.print()" class="toolbar-btn btn-print">
        <i class="bi bi-printer"></i> Print ID Card / Label
      </button>
    </div>
  </div>

  <!-- Physical Customer ID Card Canvas -->
  <div class="id-card-wrapper">
    <!-- Header -->
    <div class="card-header">
      <div>
        <div class="brand-title">RAJPRIYA <span>NOTEBOOKS</span></div>
        <div class="brand-subtitle">Manufacturers of Quality Note Books</div>
      </div>
      <div class="contact-pill">
        <i class="bi bi-telephone-fill"></i> SALEM &bull; 9443346472
      </div>
    </div>

    <!-- Body -->
    <div class="card-body">
      <!-- Customer Information Column -->
      <div class="cust-info-col">
        <div class="cust-badge-row">
          <span class="badge-label">Customer ID:</span>
          <span class="cust-id-pill"><?php echo htmlspecialchars($cCode); ?></span>
          <span style="background: #FCF8EF; color: #17243A; border: 1.5px solid #C89B3C; font-weight: 700; font-size: 0.8rem; padding: 3px 10px; border-radius: 6px; text-transform: uppercase; letter-spacing: 0.5px;">
            <?php echo htmlspecialchars($customer['customer_type'] ?? 'Retailer'); ?>
          </span>
        </div>

        <div class="cust-name"><?php echo htmlspecialchars($customer['name']); ?></div>

        <table class="cust-details-table">
          <tr>
            <td class="lbl">Customer Type:</td>
            <td class="val" style="color: #17243A; font-weight: 700;"><?php echo htmlspecialchars($customer['customer_type'] ?? 'Retailer'); ?></td>
          </tr>
          <tr>
            <td class="lbl">Mobile Phone:</td>
            <td class="val"><?php echo htmlspecialchars($customer['phone']); ?></td>
          </tr>
          <tr>
            <td class="lbl">District / City:</td>
            <td class="val">
              <?php 
              $distCity = array_filter([$customer['district'], $customer['city']]);
              echo htmlspecialchars(!empty($distCity) ? implode(', ', array_unique($distCity)) : 'Tamil Nadu');
              if (!empty($customer['pincode'])) {
                echo ' - ' . htmlspecialchars($customer['pincode']);
              }
              ?>
            </td>
          </tr>
          <?php if (!empty($customer['gst_number'])): ?>
            <tr>
              <td class="lbl">GSTIN:</td>
              <td class="val font-monospace" style="color: #92400E;"><?php echo htmlspecialchars($customer['gst_number']); ?></td>
            </tr>
          <?php endif; ?>
          <tr>
            <td class="lbl">Registered:</td>
            <td class="val" style="color: #475569;"><?php echo format_date($customer['created_at']); ?></td>
          </tr>
        </table>

        <!-- Code 128 Barcode -->
        <div class="barcode-wrap">
          <div class="barcode-svg-container">
            <?php echo $barcodeSvg; ?>
          </div>
          <div class="barcode-text"><?php echo htmlspecialchars($cCode); ?></div>
        </div>
      </div>

      <!-- Customer QR Code Column -->
      <div class="qr-col">
        <img src="<?php echo htmlspecialchars($qrImg); ?>" alt="Customer QR Code" class="qr-image">
        <div class="qr-caption">Verified Customer QR</div>
        <div class="qr-subcaption">Scan to track orders & verify profile</div>
      </div>
    </div>

    <!-- Footer -->
    <div class="card-footer">
      <div>Official Rajpriya Notebooks Customer Identity Document</div>
      <div class="footer-highlight">Verification: <?php echo htmlspecialchars($cCode); ?></div>
    </div>
  </div>

</body>
</html>
