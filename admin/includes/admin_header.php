<?php
require_once __DIR__ . '/../../includes/functions.php';
require_admin_login();

$admin = get_current_admin();
$logos = get_active_logos();
$current_admin_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RAJPRIYA Admin Panel</title>
  <link rel="icon" href="<?php echo htmlspecialchars(get_site_url() . '/' . ltrim($logos['primary'], '/'), ENT_QUOTES, 'UTF-8'); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <style>
    :root {
      --navy: #17243A;
      --gold: #C89B3C;
      --light-gold: #E8C76A;
      --ivory: #FCF8EF;
    }
    .bg-gold {
      background-color: var(--gold) !important;
      color: #ffffff !important;
    }
    .bg-gold-subtle {
      background-color: var(--ivory) !important;
      color: var(--navy) !important;
    }
    .text-gold {
      color: var(--gold) !important;
    }
    .text-navy {
      color: var(--navy) !important;
    }
    .admin-wrapper .btn:not(.btn-stock-action):not(.btn-action-edit):not(.btn-action-delete):not(.btn-action-print):not(.search-submit-btn) {
      padding: 0.5rem 0.9rem !important;
      line-height: 1.25;
    }
    html, body {
      height: 100%;
      margin: 0;
      padding: 0;
      overflow: hidden;
      background-color: #F4F6F9;
      font-family: 'Segoe UI', system-ui, sans-serif;
    }
    .admin-wrapper {
      display: flex;
      height: 100vh;
      width: 100vw;
      overflow: hidden;
    }
    .sidebar {
      width: 260px;
      min-width: 260px;
      max-width: 260px;
      height: 100vh;
      background-color: var(--navy);
      color: #fff;
      overflow-y: auto;
      flex-shrink: 0;
    }
    .sidebar-brand { padding: 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
    .sidebar-brand img { max-height: 48px; object-fit: contain; }
    .sidebar-menu { list-style: none; padding: 15px 0; margin: 0; }
    .sidebar-menu a { color: #A0ACC0; padding: 12px 24px; display: flex; align-items: center; gap: 12px; font-weight: 500; font-size: 0.9rem; text-decoration: none; }
    .sidebar-menu a:hover, .sidebar-menu a.active { color: var(--gold); background: rgba(200, 155, 60, 0.1); border-left: 4px solid var(--gold); }
    .admin-main {
      flex: 1;
      min-width: 0;
      height: 100vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      padding: 20px;
      box-sizing: border-box;
    }
    .admin-page-header-slot { flex: 0 0 auto; }
    .admin-page-header-slot:empty { display: none; }
    .admin-content-scroll {
      flex: 1 1 auto;
      min-height: 0;
      overflow-y: auto;
    }
    .admin-navbar { background: #fff; padding: 12px 25px; border-bottom: 1px solid #E2E8F0; margin-bottom: 16px; border-radius: 8px; }
    .admin-main .mb-4 { margin-bottom: 1rem !important; }
    .admin-main .mt-4 { margin-top: 1rem !important; }
    .admin-main .my-4 { margin-top: 1rem !important; margin-bottom: 1rem !important; }
    .admin-main .gap-4 { gap: 1rem !important; }
    .admin-main .g-4 { --bs-gutter-x: 1rem !important; --bs-gutter-y: 1rem !important; }
    .admin-main .p-4 { padding: 1.25rem !important; }
    .admin-main .px-4 { padding-left: 1.25rem !important; padding-right: 1.25rem !important; }
    .admin-main .py-4 { padding-top: 1.25rem !important; padding-bottom: 1.25rem !important; }
    .admin-main .p-5 { padding: 1.5rem !important; }
    .admin-main .py-5 { padding-top: 1.5rem !important; padding-bottom: 1.5rem !important; }
    .stat-card { background: #fff; border-radius: 10px; padding: 20px; border: 1px solid #E2E8F0; }
    .stat-icon { width: 48px; height: 48px; border-radius: 10px; background: var(--ivory); color: var(--gold); display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }

    @media (max-width: 991.98px) {
      html, body {
        height: auto;
        overflow: auto;
      }
      .admin-wrapper {
        height: auto;
        width: 100%;
        flex-direction: column;
        overflow: visible;
      }
      .sidebar {
        width: 100%;
        min-width: 100%;
        max-width: 100%;
        height: auto;
        min-height: 0;
        overflow-y: visible;
      }
      .sidebar-brand {
        padding: 14px 20px;
      }
      .sidebar-menu {
        display: none;
        padding: 10px 0;
        border-top: 1px solid rgba(255,255,255,0.08);
      }
      .sidebar-menu.show {
        display: block;
      }
      .admin-main {
        height: calc(100vh - 82px);
        min-height: 300px;
        padding: 12px;
      }
      .admin-navbar {
        padding: 10px 16px;
        margin-bottom: 12px;
      }
    }

    @media screen {
      .sidebar {
        background: linear-gradient(180deg, #17243A 0%, #111B2D 100%);
        border-right: 1px solid rgba(255, 255, 255, 0.08);
      }
      .sidebar-brand {
        min-height: 82px;
        padding: 16px 18px;
      }
      .sidebar-menu {
        padding: 10px 8px 18px;
      }
      .sidebar-menu li + li {
        margin-top: 3px;
      }
      .sidebar-menu a {
        margin: 0 8px;
        padding: 10px 14px;
        gap: 10px;
        border-left: 3px solid transparent;
        border-radius: 9px;
        transition: color 0.18s ease, background-color 0.18s ease, border-color 0.18s ease;
      }
      .sidebar-menu a:hover,
      .sidebar-menu a.active {
        color: #E8C76A;
        background: rgba(200, 155, 60, 0.12);
        border-left-color: #C89B3C;
      }
      .admin-navbar {
        border: 1px solid #E2E8F0;
        box-shadow: 0 3px 12px rgba(23, 36, 58, 0.05);
      }
      .admin-page-header {
        padding: 14px 18px !important;
        margin-bottom: 16px !important;
        border: 1px solid #E2E8F0;
        border-left: 4px solid #C89B3C;
        border-radius: 12px;
        background: linear-gradient(110deg, #FFFFFF 0%, #FBFCFE 100%);
        box-shadow: 0 3px 12px rgba(23, 36, 58, 0.045);
      }
      .admin-page-header h3 {
        color: #17243A;
        font-size: 1.2rem;
        font-weight: 700;
        letter-spacing: -0.25px;
        line-height: 1.3;
      }
      .admin-page-header p,
      .admin-page-header small {
        color: #64748B;
      }
      .admin-page-header .breadcrumb {
        margin-bottom: 0.4rem;
      }
      .admin-main .btn {
        border-radius: 8px;
        font-weight: 600;
        transition: background-color 0.18s ease, border-color 0.18s ease, color 0.18s ease, box-shadow 0.18s ease;
      }
      .admin-main .card,
      .admin-main div.bg-white.p-3.rounded-3,
      .admin-main div.bg-white.p-4.rounded-3 {
        border-color: #E2E8F0;
        border-radius: 14px !important;
        box-shadow: 0 4px 14px rgba(23, 36, 58, 0.05);
      }
      .admin-main .table thead th {
        background-color: #F8FAFC;
        color: #475569;
        font-size: 0.74rem;
        font-weight: 700;
        letter-spacing: 0.35px;
        vertical-align: middle;
      }
      .admin-main .table tbody td {
        border-color: #EDF2F7;
        vertical-align: middle;
      }
      .admin-main .form-control,
      .admin-main .form-select {
        border-color: #CBD5E1;
        border-radius: 8px;
      }
      .admin-main .form-control:focus,
      .admin-main .form-select:focus {
        border-color: #C89B3C;
        box-shadow: 0 0 0 3px rgba(200, 155, 60, 0.16);
      }
      .admin-main .form-check-input:checked {
        background-color: #17243A;
        border-color: #17243A;
      }
      .admin-main .alert {
        border-radius: 12px;
      }
      .admin-main .modal-content {
        overflow: hidden;
        border: 1px solid #E2E8F0;
        border-radius: 14px;
        box-shadow: 0 16px 40px rgba(23, 36, 58, 0.18);
      }
      .admin-main .modal-header,
      .admin-main .modal-footer {
        border-color: #E2E8F0;
      }
      .admin-main .pagination .page-link {
        color: #17243A;
        border-color: #E2E8F0;
      }
      .admin-main .pagination .page-item.active .page-link {
        color: #ffffff;
        background-color: #17243A;
        border-color: #17243A;
      }
      .admin-main :focus-visible {
        outline: 2px solid #C89B3C;
        outline-offset: 2px;
      }
    }

    @media screen and (max-width: 767.98px) {
      .admin-page-header {
        flex-direction: column;
        align-items: stretch !important;
      }
      .admin-page-header > .d-flex:last-child {
        width: 100%;
      }
      .admin-page-header .btn {
        flex: 1 1 auto;
      }
    }

    /* Strict Media Print Rule for Admin Invoices & Labels */
    @media print {
      .sidebar,
      .admin-navbar,
      .no-print,
      .btn,
      .alert,
      form.no-print-form,
      .admin-actions-wrap,
      .modal,
      .modal-backdrop {
        display: none !important;
      }

      html, body, .admin-wrapper, .admin-main {
        height: auto !important;
        min-height: 0 !important;
        width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        overflow: visible !important;
        background: #ffffff !important;
      }

      .admin-main {
        padding: 0 !important;
      }
      .admin-content-scroll {
        height: auto !important;
        overflow: visible !important;
      }

      .row {
        margin: 0 !important;
      }

      .col-lg-8, .col-lg-4, .col-md-6 {
        width: 100% !important;
        flex: 0 0 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
      }

      .bg-white, .p-4, .border, .rounded-3,
      .admin-main .p-4, .admin-main .px-4, .admin-main .py-4,
      .admin-main .p-5, .admin-main .py-5 {
        box-shadow: none !important;
        border: none !important;
        padding: 0 !important;
      }
    }
  </style>
  <script>
    (function () {
      const logoutSignalKey = 'rajpriya_admin_logout';
      const logoutSignalAtLoad = localStorage.getItem(logoutSignalKey);

      function redirectIfAdminLoggedOut() {
        if (localStorage.getItem(logoutSignalKey) !== logoutSignalAtLoad) {
          window.location.replace('../login.php');
        }
      }

      window.addEventListener('storage', function (event) {
        if (event.key === logoutSignalKey) {
          redirectIfAdminLoggedOut();
        }
      });
      window.addEventListener('pageshow', redirectIfAdminLoggedOut);
      document.addEventListener('DOMContentLoaded', function () {
        const pageHeader = document.querySelector('.admin-main .admin-page-header');
        const headerSlot = document.querySelector('.admin-page-header-slot');
        if (pageHeader && headerSlot) {
          headerSlot.appendChild(pageHeader);
        }
      });
    })();

    function signalAdminLogout() {
      localStorage.setItem('rajpriya_admin_logout', String(Date.now()) + '-' + Math.random());
    }

    function toggleAdminMobileMenu() {
      const menu = document.querySelector('.sidebar-menu');
      const btnIcon = document.getElementById('adminMenuToggleIcon');
      if (menu) {
        menu.classList.toggle('show');
        if (btnIcon) {
          btnIcon.classList.toggle('bi-list');
          btnIcon.classList.toggle('bi-x-lg');
        }
      }
    }
  </script>
</head>
<body>

<div class="admin-wrapper">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-brand d-flex align-items-center justify-content-between justify-content-lg-center">
      <div class="d-flex align-items-center gap-2">
        <img src="../<?php echo htmlspecialchars($logos['primary']); ?>" alt="RAJPRIYA Admin">
        <div class="small text-gold mt-1 fw-bold">ADMIN PANEL</div>
      </div>
      <button class="btn btn-outline-warning btn-sm d-lg-none py-1 px-2.5 fw-semibold" type="button" onclick="toggleAdminMobileMenu()" id="adminMenuToggleBtn">
        <i class="bi bi-list fs-5 align-middle" id="adminMenuToggleIcon"></i> <span class="align-middle">Menu</span>
      </button>
    </div>
    <ul class="sidebar-menu">
      <li><a href="index.php" class="<?php echo ($current_admin_page==='index.php')?'active':''; ?>"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
      <li><a href="products.php" class="<?php echo (in_array($current_admin_page, ['products.php', 'product-add.php', 'product-edit.php']))?'active':''; ?>"><i class="bi bi-journal-text"></i> Products</a></li>
      <li><a href="categories.php" class="<?php echo ($current_admin_page==='categories.php')?'active':''; ?>"><i class="bi bi-tags"></i> Categories</a></li>
      <li><a href="orders.php" class="<?php echo (in_array($current_admin_page, ['orders.php', 'order-details.php', 'print_parcel_sticker.php']))?'active':''; ?>"><i class="bi bi-box-seam text-gold"></i> Orders & Packing</a></li>
      <li><a href="quotations.php" class="<?php echo (in_array($current_admin_page, ['quotations.php', 'quotation.php']))?'active':''; ?>"><i class="bi bi-file-earmark-text text-gold"></i> Quotations</a></li>
      <li><a href="customers.php" class="<?php echo ($current_admin_page==='customers.php')?'active':''; ?>"><i class="bi bi-people"></i> Customers</a></li>
      <li><a href="stock.php" class="<?php echo ($current_admin_page==='stock.php')?'active':''; ?>"><i class="bi bi-box-seam"></i> Stock Manager</a></li>
      <li><a href="excel-manager.php" class="<?php echo ($current_admin_page==='excel-manager.php')?'active':''; ?>"><i class="bi bi-file-earmark-spreadsheet text-gold"></i> Excel Manager</a></li>
      <li><a href="tally_products_export.php" class="<?php echo ($current_admin_page==='tally_products_export.php')?'active':''; ?>"><i class="bi bi-upc-scan text-gold"></i> Tally Barcodes</a></li>
      <li><a href="print_label.php" class="<?php echo ($current_admin_page==='print_label.php')?'active':''; ?>"><i class="bi bi-printer text-gold"></i> Print Bundle Labels</a></li>
      <li><a href="brand-assets.php" class="<?php echo ($current_admin_page==='brand-assets.php')?'active':''; ?>"><i class="bi bi-images"></i> Brand Logos</a></li>
      <li><a href="reviews.php" class="<?php echo ($current_admin_page==='reviews.php')?'active':''; ?>"><i class="bi bi-star"></i> Reviews</a></li>
      <li><a href="settings.php" class="<?php echo ($current_admin_page==='settings.php')?'active':''; ?>"><i class="bi bi-gear"></i> Settings</a></li>
      <li><a href="../index.php" target="_self"><i class="bi bi-box-arrow-up-right"></i> View Website</a></li>
      <li><a href="logout.php" class="text-danger" onclick="signalAdminLogout()"><i class="bi bi-power"></i> Logout</a></li>
    </ul>
  </aside>

  <!-- Main Content -->
  <main class="admin-main">
    <!-- Top Navbar -->
    <div class="admin-navbar d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2">
        <button class="btn btn-outline-secondary btn-sm d-lg-none py-1 px-2 border-secondary-subtle" type="button" onclick="toggleAdminMobileMenu()" title="Toggle Menu">
          <i class="bi bi-list fs-6 text-gold align-middle"></i>
        </button>
        <div class="fw-bold text-navy fs-6">RAJPRIYA Store Admin</div>
      </div>
      <div class="d-flex align-items-center gap-2.5">
        <span class="small text-muted d-none d-sm-inline"><i class="bi bi-person-circle me-1 text-gold"></i> <?php echo htmlspecialchars($admin['name']); ?></span>
        <a href="logout.php" class="btn btn-outline-danger btn-sm py-1 px-2.5" onclick="signalAdminLogout()">Logout</a>
      </div>
    </div>

    <div class="admin-page-header-slot"></div>
    <div class="admin-content-scroll">
    <?php display_flash(); ?>
