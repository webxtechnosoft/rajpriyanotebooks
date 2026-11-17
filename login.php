<?php
require_once __DIR__ . '/includes/functions.php';

$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? $_SESSION['redirect_url'] ?? 'account.php';

// If admin is already logged in, redirect to admin dashboard
if (is_admin_logged_in()) {
    header("Location: admin/index.php");
    exit();
}

// If customer is already logged in, redirect to intended page
if (is_customer_logged_in()) {
    unset($_SESSION['redirect_url']);
    header("Location: " . $redirect);
    exit();
}

$logos = get_active_logos();
$customer_error = '';
$admin_error = '';
$active_tab = (isset($_GET['type']) && $_GET['type'] === 'admin') ? 'admin' : 'customer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_type = isset($_POST['login_type']) ? $_POST['login_type'] : 'customer';

    if ($login_type === 'customer') {
        $active_tab = 'customer';
        $login_identifier = sanitize(trim($_POST['email'] ?? ''));
        $password = $_POST['password'] ?? '';

        if (!empty($login_identifier) && !empty($password)) {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE (email = ? OR phone = ? OR customer_code = ?) AND status = 'active' LIMIT 1");
            $stmt->execute([$login_identifier, $login_identifier, $login_identifier]);
            $user = $stmt->fetch();

            if ($user && $password === $user['password']) {
                $_SESSION['customer_id'] = $user['id'];
                $_SESSION['customer_name'] = $user['name'];
                
                // Sync cart on customer login
                sync_cart_on_login($user['id'], get_cart_session_id());

                set_flash('success', 'Welcome back, ' . htmlspecialchars($user['name']) . '!');
                $target_url = $_POST['redirect'] ?? $_GET['redirect'] ?? $_SESSION['redirect_url'] ?? 'account.php';
                unset($_SESSION['redirect_url']);
                header("Location: " . $target_url);
                exit();
            } else {
                $customer_error = "Invalid mobile number/email or password.";
            }
        } else {
            $customer_error = "Please enter your mobile number or email and password.";
        }
    } elseif ($login_type === 'admin') {
        $active_tab = 'admin';
        $username = sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!empty($username) && !empty($password)) {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM admins WHERE username = ? OR email = ?");
            $stmt->execute([$username, $username]);
            $admin = $stmt->fetch();

            if ($admin && $password === $admin['password']) {
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['name'];
                set_flash('success', 'Logged in as Admin successfully.');
                header("Location: admin/index.php");
                exit();
            } else {
                $admin_error = "Invalid admin username or password.";
            }
        } else {
            $admin_error = "Please fill in all admin credentials.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login — RAJPRIYA NOTEBOOKS</title>
  
  <!-- Google Fonts (Outfit & Inter) -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
  
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
  
  <link rel="icon" href="<?php echo htmlspecialchars(get_site_url() . '/' . ltrim($logos['primary'], '/'), ENT_QUOTES, 'UTF-8'); ?>">

  <style>
    :root {
      --rp-navy: #17243A;
      --rp-navy-hover: #0F1828;
      --rp-gold: #C89B3C;
      --rp-gold-hover: #B2872E;
      --rp-cream: #FCF8EF;
      --rp-border: #E6DCB8;
      --rp-text-dark: #1E293B;
      --rp-text-muted: #64748B;
    }

    * {
      box-sizing: border-box;
    }

    html {
      height: 100%;
      -webkit-text-size-adjust: 100%;
    }

    body.login-page-body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      background-color: var(--rp-cream);
      background-image: 
        radial-gradient(circle at 10% 10%, rgba(200, 155, 60, 0.08) 0%, transparent 40%),
        radial-gradient(circle at 90% 90%, rgba(23, 36, 58, 0.07) 0%, transparent 40%),
        linear-gradient(180deg, rgba(230, 220, 184, 0.25) 1px, transparent 1px);
      background-size: 100% 100%, 100% 100%, 100% 32px;
      min-height: 100%;
      min-height: 100dvh;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      overflow-x: hidden;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
    }

    .login-page-container {
      width: 100%;
      min-height: 100vh;
      min-height: 100dvh;
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      padding: 24px 16px;
      box-sizing: border-box;
      position: relative;
    }

    /* Top Navigation Bar: Top-left pinned on desktop, in-flow on mobile */
    .login-top-bar {
      position: absolute;
      top: 28px;
      left: 36px;
      z-index: 20;
    }

    @media (max-width: 767.98px) {
      .login-page-container {
        justify-content: flex-start;
        padding-top: 12px;
      }

      .login-top-bar {
        position: static;
        width: 100%;
        max-width: 440px;
        margin: 0 auto;
        padding: 8px 4px 14px;
        display: flex;
        align-items: center;
        justify-content: flex-start;
      }
    }

    .btn-back-website {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background-color: #FFFFFF;
      color: var(--rp-navy);
      border: 1.5px solid var(--rp-border);
      padding: 10px 20px;
      border-radius: 50px;
      font-size: 0.875rem;
      font-weight: 600;
      text-decoration: none;
      box-shadow: 0 4px 12px rgba(23, 36, 58, 0.06);
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .btn-back-website i {
      color: var(--rp-gold);
      font-size: 1rem;
      transition: transform 0.25s ease;
    }

    .btn-back-website:hover {
      background-color: var(--rp-navy);
      color: #FFFFFF;
      border-color: var(--rp-navy);
      box-shadow: 0 6px 18px rgba(23, 36, 58, 0.18);
      transform: translateY(-2px);
    }

    .btn-back-website:hover i {
      color: var(--rp-gold);
      transform: translateX(-3px);
    }

    /* Main Container & Centered Card */
    .login-card-wrapper {
      width: 100%;
      max-width: 440px;
      margin: auto;
      z-index: 10;
    }

    .login-card {
      background: #FFFFFF;
      border-radius: 20px;
      border: 1px solid var(--rp-border);
      padding: 40px 36px;
      box-shadow: 0 20px 50px rgba(23, 36, 58, 0.09);
      transition: all 0.3s ease;
    }

    /* Brand Logo Wrap */
    .login-logo-container {
      text-align: center;
      margin-bottom: 28px;
    }

    .login-logo-img {
      max-height: 62px;
      width: auto;
      max-width: 100%;
      object-fit: contain;
      margin: 0 auto;
      display: inline-block;
      transition: transform 0.25s ease;
    }

    .login-logo-img:hover {
      transform: scale(1.02);
    }

    /* Segmented Control / Tab Switcher */
    .login-tab-switcher {
      display: flex;
      background: #F1F5F9;
      padding: 4px;
      border-radius: 12px;
      margin-bottom: 28px;
      border: 1px solid #E2E8F0;
    }

    .login-tab-btn {
      flex: 1;
      padding: 10px 14px;
      border: none;
      background: transparent;
      border-radius: 9px;
      font-size: 0.875rem;
      font-weight: 600;
      color: var(--rp-text-muted);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      transition: all 0.2s ease;
    }

    .login-tab-btn.active {
      background: #FFFFFF;
      color: var(--rp-navy);
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    .login-tab-btn i {
      font-size: 0.95rem;
    }

    .login-tab-btn.active.customer-tab i {
      color: var(--rp-gold);
    }

    .login-tab-btn.active.admin-tab i {
      color: var(--rp-navy);
    }

    /* Titles & Subtitles */
    .login-title {
      font-family: 'Outfit', sans-serif;
      font-size: 1.5rem;
      font-weight: 700;
      color: var(--rp-navy);
      margin-bottom: 6px;
      text-align: center;
    }

    .login-subtitle {
      font-size: 0.875rem;
      color: var(--rp-text-muted);
      text-align: center;
      margin-bottom: 24px;
    }

    /* Form Fields & Controls */
    .form-group-wrap {
      margin-bottom: 20px;
    }

    .form-label-custom {
      display: block;
      font-size: 0.825rem;
      font-weight: 600;
      color: var(--rp-navy);
      margin-bottom: 8px;
    }

    .form-control-custom {
      width: 100%;
      height: 48px;
      padding: 12px 16px;
      font-size: 0.925rem;
      font-weight: 500;
      color: var(--rp-text-dark);
      background-color: #FAFAFA;
      border: 1.5px solid #E2E8F0;
      border-radius: 10px;
      transition: all 0.2s ease;
    }

    .form-control-custom:focus {
      background-color: #FFFFFF;
      border-color: var(--rp-gold);
      box-shadow: 0 0 0 4px rgba(200, 155, 60, 0.15);
      outline: none;
    }

    /* Form Submit Buttons */
    .btn-submit-custom {
      width: 100%;
      height: 48px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      font-size: 0.95rem;
      font-weight: 600;
      border-radius: 10px;
      border: none;
      cursor: pointer;
      transition: all 0.25s ease;
      box-shadow: 0 4px 12px rgba(23, 36, 58, 0.12);
    }

    .btn-submit-customer {
      background-color: var(--rp-gold);
      color: #FFFFFF;
    }

    .btn-submit-customer:hover {
      background-color: var(--rp-gold-hover);
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(200, 155, 60, 0.25);
    }

    .btn-submit-admin {
      background-color: var(--rp-navy);
      color: #FFFFFF;
    }

    .btn-submit-admin:hover {
      background-color: var(--rp-navy-hover);
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(23, 36, 58, 0.25);
    }

    /* Footer Links & Notes */
    .card-footer-note {
      text-align: center;
      margin-top: 24px;
      padding-top: 20px;
      border-top: 1px solid #F1F5F9;
      font-size: 0.85rem;
      color: var(--rp-text-muted);
    }

    .card-footer-note a {
      color: var(--rp-gold);
      font-weight: 600;
      text-decoration: none;
    }

    .card-footer-note a:hover {
      text-decoration: underline;
    }

    /* Smooth Transitions for Forms */
    .form-panel {
      transition: opacity 0.2s ease, transform 0.2s ease;
    }

    /* Responsive Adjustments */
    @media (max-width: 576px) {
      .login-top-bar {
        padding: 16px 4px 12px;
      }

      .btn-back-website {
        padding: 8px 16px;
        font-size: 0.8rem;
      }

      .login-card {
        padding: 28px 20px;
        border-radius: 16px;
      }

      .login-title {
        font-size: 1.35rem;
      }

      .login-logo-img {
        max-height: 52px;
      }

      .form-control-custom {
        font-size: 16px !important;
      }
    }
  </style>
</head>
<body class="login-page-body">

<div class="login-page-container">
  <!-- Top-Left Back to Website Navigation -->
  <div class="login-top-bar">
    <a href="index.php" class="btn-back-website">
      <i class="bi bi-arrow-left"></i> Back to Website
    </a>
  </div>

  <!-- Main Centered Login Card -->
  <div class="login-card-wrapper">
  <div class="login-card">
    
    <!-- Centered Brand Logo -->
    <div class="login-logo-container">
      <a href="index.php" title="Raj Priya Notebooks Home">
        <img src="<?php echo htmlspecialchars($logos['primary']); ?>" alt="RAJPRIYA NOTEBOOKS" class="login-logo-img">
      </a>
    </div>

    <!-- Segmented Tab Switcher (Customer vs Admin) -->
    <div class="login-tab-switcher">
      <button type="button" id="tabCustomerBtn" class="login-tab-btn customer-tab <?php echo ($active_tab !== 'admin') ? 'active' : ''; ?>" onclick="showCustomerLogin()">
        <i class="bi bi-person-circle"></i> Customer Login
      </button>
      <button type="button" id="tabAdminBtn" class="login-tab-btn admin-tab <?php echo ($active_tab === 'admin') ? 'active' : ''; ?>" onclick="showAdminLogin()">
        <i class="bi bi-shield-lock"></i> Admin Portal
      </button>
    </div>

    <!-- CUSTOMER LOGIN FORM PANEL -->
    <div id="customerLoginForm" class="form-panel" style="<?php echo ($active_tab === 'admin') ? 'display: none;' : ''; ?>">
      <div class="login-title">Customer Sign In</div>
      <div class="login-subtitle">Access your Rajpriya account & track your orders</div>

      <?php if ($customer_error): ?>
        <div class="alert alert-danger py-2.5 px-3 small rounded-3 mb-4 d-flex align-items-center gap-2">
          <i class="bi bi-exclamation-circle-fill text-danger fs-6"></i>
          <div><?php echo htmlspecialchars($customer_error); ?></div>
        </div>
      <?php endif; ?>

      <form action="login.php" method="POST">
        <input type="hidden" name="login_type" value="customer">
        <?php if (!empty($redirect)): ?>
          <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
        <?php endif; ?>

        <div class="form-group-wrap">
          <label class="form-label-custom">Mobile Number or Email</label>
          <input type="text" name="email" class="form-control-custom" required placeholder="e.g. 9876543210 or priya@example.com" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
        </div>

        <div class="form-group-wrap mb-4">
          <label class="form-label-custom">Password</label>
          <input type="password" name="password" class="form-control-custom" required placeholder="••••••••">
        </div>

        <button type="submit" class="btn-submit-custom btn-submit-customer">
          <i class="bi bi-box-arrow-in-right"></i> Sign In as Customer
        </button>
      </form>

      <div class="card-footer-note">
        Don't have a customer account? <a href="register.php">Register Now</a>
      </div>
    </div>

    <!-- ADMIN LOGIN FORM PANEL -->
    <div id="adminLoginForm" class="form-panel" style="<?php echo ($active_tab === 'admin') ? '' : 'display: none;'; ?>">
      <div class="login-title">Admin Management</div>
      <div class="login-subtitle">Store administration & notebook control panel</div>

      <?php if ($admin_error): ?>
        <div class="alert alert-danger py-2.5 px-3 small rounded-3 mb-4 d-flex align-items-center gap-2">
          <i class="bi bi-exclamation-triangle-fill text-danger fs-6"></i>
          <div><?php echo htmlspecialchars($admin_error); ?></div>
        </div>
      <?php endif; ?>

      <form action="login.php" method="POST">
        <input type="hidden" name="login_type" value="admin">

        <div class="form-group-wrap">
          <label class="form-label-custom">Username or Email</label>
          <input type="text" name="username" class="form-control-custom" required placeholder="admin" value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : 'admin'; ?>">
        </div>

        <div class="form-group-wrap mb-4">
          <label class="form-label-custom">Admin Password</label>
          <input type="password" name="password" class="form-control-custom" required placeholder="••••••••">
        </div>

        <button type="submit" class="btn-submit-custom btn-submit-admin">
          <i class="bi bi-shield-check"></i> Sign In to Admin Portal
        </button>
      </form>

      <div class="card-footer-note">
        Customer portal? <a href="javascript:void(0)" onclick="showCustomerLogin()">Switch to Customer Login</a>
      </div>
    </div>

  </div>
</div>
</div>

<script>
function showAdminLogin() {
    const customerForm = document.getElementById('customerLoginForm');
    const adminForm = document.getElementById('adminLoginForm');
    const tabCustomerBtn = document.getElementById('tabCustomerBtn');
    const tabAdminBtn = document.getElementById('tabAdminBtn');
    
    tabCustomerBtn.classList.remove('active');
    tabAdminBtn.classList.add('active');

    customerForm.style.opacity = '0';
    setTimeout(() => {
        customerForm.style.display = 'none';
        adminForm.style.display = 'block';
        adminForm.style.opacity = '0';
        setTimeout(() => {
            adminForm.style.opacity = '1';
        }, 30);
    }, 120);
}

function showCustomerLogin() {
    const customerForm = document.getElementById('customerLoginForm');
    const adminForm = document.getElementById('adminLoginForm');
    const tabCustomerBtn = document.getElementById('tabCustomerBtn');
    const tabAdminBtn = document.getElementById('tabAdminBtn');
    
    tabAdminBtn.classList.remove('active');
    tabCustomerBtn.classList.add('active');

    adminForm.style.opacity = '0';
    setTimeout(() => {
        adminForm.style.display = 'none';
        customerForm.style.display = 'block';
        customerForm.style.opacity = '0';
        setTimeout(() => {
            customerForm.style.opacity = '1';
        }, 30);
    }, 120);
}
</script>

</body>
</html>
