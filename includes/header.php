<?php
require_once __DIR__ . '/functions.php';
$logos = get_active_logos();
$cart_count = get_cart_count();
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo htmlspecialchars(get_setting('site_title', 'RAJPRIYA NOTEBOOKS')); ?></title>
  <meta name="description" content="Official website of RAJPRIYA Notebooks. Write Every Idea. Keep Every Thought. Premium educational & writing notebooks.">
  
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  <!-- Custom Warm Ivory + Deep Navy + Gold Stylesheet -->
  <link rel="stylesheet" href="assets/css/style.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/style.css'); ?>">
  <?php if ($current_page === 'index.php'): ?><link rel="stylesheet" href="assets/css/home.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/home.css'); ?>"><?php endif; ?>
  <?php if ($current_page === 'reviews.php'): ?><link rel="stylesheet" href="assets/css/reviews.css?v=<?php echo filemtime(__DIR__ . '/../assets/css/reviews.css'); ?>"><?php endif; ?>
  
  <!-- Use the active brand logo as the site favicon -->
  <link rel="icon" href="<?php echo htmlspecialchars(get_site_url() . '/' . ltrim($logos['primary'], '/'), ENT_QUOTES, 'UTF-8'); ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="assets/images/apple-touch-icon.webp">
</head>
<body>

<!-- Top Announcement Bar -->
<div class="top-bar">
  <div class="container d-flex flex-wrap flex-md-nowrap justify-content-center justify-content-md-between align-items-center">
    <div class="text-center text-md-start">
      <span class="fw-semibold">Raj Priya Notebooks</span> - Made for <span class="tagline-highlight fw-semibold">Better writing & Greater Success</span>
    </div>
    <div class="d-none d-md-flex align-items-center">
      <?php if (is_admin_logged_in()): 
        $current_admin = get_current_admin();
        $admin_display_name = !empty($current_admin['name']) ? $current_admin['name'] : (!empty($_SESSION['admin_name']) ? $_SESSION['admin_name'] : 'Admin');
      ?>
        <a href="admin/index.php"><i class="bi bi-speedometer2 me-1"></i> Admin Dashboard (<?php echo htmlspecialchars($admin_display_name); ?>)</a>
        <a href="admin/logout.php"><i class="bi bi-box-arrow-right me-1"></i> Logout</a>
      <?php elseif (is_customer_logged_in()): 
        $customer = get_current_customer();
        $customer_display_name = !empty($customer['name']) ? $customer['name'] : (!empty($_SESSION['customer_name']) ? $_SESSION['customer_name'] : 'Customer');
      ?>
        <a href="account.php"><i class="bi bi-person-circle me-1"></i> Hi, <?php echo htmlspecialchars($customer_display_name); ?></a>
        <a href="logout.php"><i class="bi bi-box-arrow-right me-1"></i> Logout</a>
      <?php else: ?>
        <a href="login.php"><i class="bi bi-person me-1"></i> My Account</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Main Navbar Header -->
<header class="main-header">
  <div class="container">
    <nav class="navbar navbar-expand-lg navbar-light p-0">
      
      <!-- Brand Logo (Primary Logo + Brand Name Lockup) -->
      <a class="navbar-brand py-0 me-2 me-md-4 d-flex align-items-center gap-2" href="index.php">
        <img src="<?php echo htmlspecialchars($logos['primary']); ?>" alt="RAJPRIYA NOTEBOOKS" class="navbar-brand-logo">
        <div class="brand-text-lockup d-flex flex-column lh-1">
          <span class="brand-title-main">RAJ PRIYA</span>
          <span class="brand-title-sub">NOTEBOOKS</span>
        </div>
      </a>
      
      <!-- Mobile Cart Quick Access + Mobile Toggler -->
      <div class="d-flex align-items-center gap-2 d-lg-none ms-auto">
        <a href="cart.php" class="header-icon-link position-relative me-1" title="Shopping Cart" aria-label="Shopping Cart">
          <i class="bi bi-bag"></i>
          <span class="cart-badge-count"><?php echo $cart_count; ?></span>
        </a>
        <button class="navbar-toggler custom-navbar-toggler p-2 border-0 shadow-none rounded-2" type="button" data-bs-toggle="collapse" data-bs-target="#rajpriyaNavbar" aria-controls="rajpriyaNavbar" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>
      </div>

      <!-- Nav Links & Mobile Drawer -->
      <div class="collapse navbar-collapse" id="rajpriyaNavbar">
        <ul class="navbar-nav mx-auto mb-3 mb-lg-0 py-2 py-lg-0">
          <li class="nav-item">
            <a class="nav-link-custom <?php echo ($current_page === 'index.php') ? 'active' : ''; ?>" href="index.php">Home</a>
          </li>
          <li class="nav-item">
            <a class="nav-link-custom <?php echo ($current_page === 'about.php') ? 'active' : ''; ?>" href="about.php">About</a>
          </li>
          <li class="nav-item">
            <a class="nav-link-custom <?php echo ($current_page === 'products.php') ? 'active' : ''; ?>" href="products.php">Products</a>
          </li>
          <li class="nav-item">
            <a class="nav-link-custom <?php echo ($current_page === 'price_list.php') ? 'active' : ''; ?>" href="price_list.php">Price List</a>
          </li>
          <li class="nav-item">
            <a class="nav-link-custom <?php echo ($current_page === 'categories.php') ? 'active' : ''; ?>" href="categories.php">Categories</a>
          </li>
          <li class="nav-item">
            <a class="nav-link-custom <?php echo ($current_page === 'reviews.php') ? 'active' : ''; ?>" href="reviews.php">Reviews</a>
          </li>
          <li class="nav-item">
            <a class="nav-link-custom <?php echo ($current_page === 'contact.php') ? 'active' : ''; ?>" href="contact.php">Contact</a>
          </li>
        </ul>
        
        <?php
        if (is_admin_logged_in()) {
            $account_href = 'admin/index.php';
            $account_title = 'Admin Dashboard';
            $account_icon = 'bi-speedometer2';
        } elseif (is_customer_logged_in()) {
            $account_href = 'account.php';
            $account_title = 'My Account';
            $account_icon = 'bi-person-circle';
        } else {
            $account_href = 'login.php';
            $account_title = 'Sign In / Register';
            $account_icon = 'bi-person';
        }
        ?>

        <!-- Desktop Search & Icons Right -->
        <div class="d-none d-lg-flex align-items-center gap-3">
          <form class="header-search-form" action="products.php" method="GET">
            <input type="text" name="search" class="header-search-input" placeholder="Search notebooks..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
            <button type="submit" class="header-search-btn" aria-label="Search"><i class="bi bi-search"></i></button>
          </form>
          
          <!-- Main Account Icon -->
          <a href="<?php echo $account_href; ?>" class="header-icon-link" title="<?php echo $account_title; ?>" aria-label="<?php echo $account_title; ?>">
            <i class="bi <?php echo $account_icon; ?>"></i>
          </a>
          
          <!-- Shopping Cart Icon -->
          <a href="cart.php" class="header-icon-link position-relative" title="Shopping Cart" aria-label="Shopping Cart">
            <i class="bi bi-bag"></i>
            <span class="cart-badge-count"><?php echo $cart_count; ?></span>
          </a>
        </div>

        <!-- Mobile Drawer Search & Account Footer -->
        <div class="d-lg-none mobile-nav-actions pt-3 mt-2 border-top">
          <form class="header-search-form w-100 mb-2.5" action="products.php" method="GET">
            <div class="position-relative w-100">
              <input type="text" name="search" class="header-search-input w-100 py-2 ps-3 pe-5" placeholder="Search notebooks, codes..." value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
              <button type="submit" class="header-search-btn position-absolute top-50 end-0 translate-middle-y pe-3" aria-label="Search"><i class="bi bi-search"></i></button>
            </div>
          </form>
          <a href="<?php echo $account_href; ?>" class="btn btn-outline-navy btn-sm w-100 fw-semibold d-flex align-items-center justify-content-center gap-2 py-2">
            <i class="bi <?php echo $account_icon; ?> text-gold fs-6"></i>
            <span><?php echo $account_title; ?></span>
          </a>
        </div>

      </div>
      
    </nav>
  </div>
</header>

<main>
<?php display_flash(); ?>
