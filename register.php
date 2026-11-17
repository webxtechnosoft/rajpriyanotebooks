<?php
require_once __DIR__ . '/includes/functions.php';

$redirect = $_GET['redirect'] ?? $_POST['redirect'] ?? $_SESSION['redirect_url'] ?? 'account.php';

// If admin is already logged in, redirect to admin dashboard
if (is_admin_logged_in()) {
    header("Location: admin/index.php");
    exit();
}

if (is_customer_logged_in()) {
    unset($_SESSION['redirect_url']);
    header("Location: " . $redirect);
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name'] ?? '');
    $email_raw = trim($_POST['email'] ?? '');
    $email = !empty($email_raw) ? sanitize($email_raw) : null;
    $phone = sanitize($_POST['phone'] ?? '');
    $district = sanitize($_POST['district'] ?? '');
    $city = sanitize($_POST['city'] ?? '');
    $customer_type = trim($_POST['customer_type'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($name) || empty($phone) || empty($password)) {
        $error = "Please fill in all required fields (Full Name, Mobile Number, and Password).";
    } elseif (empty($customer_type) || !is_valid_customer_type($customer_type)) {
        $error = "Please select a valid Customer Type (Wholesaler, Distributor, Retailer, or Other).";
    } else {
        $db = getDB();

        // Check duplicate email ONLY if an email is provided
        $email_exists = false;
        if ($email !== null) {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) {
                $email_exists = true;
            }
        }

        // Check duplicate phone number
        $stmt_phone = $db->prepare("SELECT id FROM users WHERE phone = ?");
        $stmt_phone->execute([$phone]);
        $phone_exists = (bool)$stmt_phone->fetch();

        if ($email_exists) {
            $error = "An account with this email address already exists.";
        } elseif ($phone_exists) {
            $error = "An account with this mobile number already exists. Please sign in.";
        } else {
            $distToUse = !empty($district) ? $district : $city;
            $customer_code = generate_next_customer_code($distToUse, $name, $customer_type);
            $qr_code = generate_qr_code_image_url(get_customer_tracking_url($customer_code), 200);

            $stmt = $db->prepare("INSERT INTO users (customer_code, qr_code, name, email, phone, city, district, customer_type, password, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$customer_code, $qr_code, $name, $email, $phone, $city, $district ?: $city, $customer_type, $password]);
            $user_id = $db->lastInsertId();

            $_SESSION['customer_id'] = $user_id;
            $_SESSION['customer_name'] = $name;

            // Sync cart on customer registration
            sync_cart_on_login($user_id, get_cart_session_id());

            set_flash('success', 'Registration successful! Welcome to Rajpriya Notebooks. Your Customer ID is ' . $customer_code);
            $target_url = $_POST['redirect'] ?? $_GET['redirect'] ?? $_SESSION['redirect_url'] ?? 'account.php';
            unset($_SESSION['redirect_url']);
            header("Location: " . $target_url);
            exit();
        }
    }
}

$logos = get_active_logos();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
  <title>Create Account — RAJPRIYA NOTEBOOKS</title>
  <link rel="icon" href="<?php echo htmlspecialchars(get_site_url() . '/' . ltrim($logos['primary'], '/'), ENT_QUOTES, 'UTF-8'); ?>">
  
  <!-- Google Fonts: Inter & Outfit -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
  
  <!-- Bootstrap 5 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">

  <style>
    :root {
      --rp-navy: #17243A;
      --rp-navy-hover: #0E1726;
      --rp-gold: #C89B3C;
      --rp-gold-hover: #B2872E;
      --rp-cream: #FCF8EF;
      --rp-border: #E6DCB8;
      --rp-text-dark: #1E293B;
      --rp-text-muted: #64748B;
    }

    *, *::before, *::after {
      box-sizing: border-box;
    }

    html {
      height: 100%;
      -webkit-text-size-adjust: 100%;
    }

    body.register-page-body {
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

    /* Overall Container: Full viewport height flex column */
    .register-page-container {
      width: 100%;
      min-height: 100vh;
      min-height: 100dvh;
      display: flex;
      flex-direction: column;
      justify-content: flex-start;
      align-items: center;
      padding: 0 16px 36px;
      box-sizing: border-box;
    }

    /* Top Navigation Bar: In-flow header, never overlaps card */
    .register-top-bar {
      width: 100%;
      max-width: 1100px;
      margin: 0 auto;
      padding: 20px 8px 12px;
      display: flex;
      align-items: center;
      justify-content: flex-start;
      flex-shrink: 0;
    }

    .btn-back-website {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background-color: #FFFFFF;
      color: var(--rp-navy);
      border: 1.5px solid var(--rp-border);
      padding: 9px 18px;
      border-radius: 50px;
      font-size: 0.875rem;
      font-weight: 600;
      text-decoration: none;
      box-shadow: 0 4px 12px rgba(23, 36, 58, 0.06);
      transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
      min-height: 40px;
      touch-action: manipulation;
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

    /* Main Container & Centered Card: auto margin centers vertically without overflow clipping */
    .register-card-wrapper {
      width: 100%;
      max-width: 480px;
      margin: auto;
      padding: 8px 0;
      z-index: 10;
    }

    .register-card {
      background: #FFFFFF;
      border-radius: 20px;
      border: 1px solid var(--rp-border);
      padding: 36px 32px;
      box-shadow: 0 20px 50px rgba(23, 36, 58, 0.09);
      width: 100%;
      box-sizing: border-box;
      transition: all 0.3s ease;
    }

    /* Brand Logo Wrap */
    .register-logo-container {
      text-align: center;
      margin-bottom: 20px;
    }

    .register-logo-img {
      max-height: 56px;
      width: auto;
      max-width: 100%;
      object-fit: contain;
      margin: 0 auto;
      display: inline-block;
      transition: transform 0.25s ease;
    }

    .register-logo-img:hover {
      transform: scale(1.02);
    }

    /* Titles & Subtitles */
    .register-title {
      font-family: 'Outfit', sans-serif;
      font-size: 1.55rem;
      font-weight: 700;
      color: var(--rp-navy);
      margin-bottom: 6px;
      text-align: center;
      letter-spacing: -0.01em;
    }

    .register-subtitle {
      font-size: 0.875rem;
      color: var(--rp-text-muted);
      text-align: center;
      margin-bottom: 22px;
      line-height: 1.45;
    }

    /* Form Fields & Controls */
    .form-group-wrap {
      margin-bottom: 16px;
    }

    .form-label-custom {
      display: block;
      font-size: 0.825rem;
      font-weight: 600;
      color: var(--rp-navy);
      margin-bottom: 6px;
    }

    .input-icon-group {
      position: relative;
      display: flex;
      align-items: center;
      width: 100%;
    }

    .input-icon-group i.input-icon-left {
      position: absolute;
      left: 15px;
      color: #94A3B8;
      font-size: 1rem;
      pointer-events: none;
      transition: color 0.2s ease;
      z-index: 2;
    }

    .input-icon-group .form-control-custom {
      padding-left: 44px;
      padding-right: 16px;
    }

    .input-icon-group.has-toggle .form-control-custom {
      padding-right: 46px;
    }

    .input-icon-group:focus-within i.input-icon-left {
      color: var(--rp-gold);
    }

    .form-control-custom {
      width: 100%;
      height: 48px;
      padding: 11px 16px;
      font-size: 0.95rem;
      font-weight: 500;
      color: var(--rp-text-dark);
      background-color: #FAFAFA;
      border: 1.5px solid #E2E8F0;
      border-radius: 10px;
      transition: all 0.2s ease;
      box-sizing: border-box;
    }

    .form-control-custom:focus {
      background-color: #FFFFFF;
      border-color: var(--rp-gold);
      box-shadow: 0 0 0 4px rgba(200, 155, 60, 0.15);
      outline: none;
    }

    .form-select-custom {
      appearance: none;
      -webkit-appearance: none;
      -moz-appearance: none;
      cursor: pointer;
      padding-right: 42px !important;
    }

    .input-icon-right {
      position: absolute;
      right: 15px;
      color: #94A3B8;
      font-size: 0.85rem;
      pointer-events: none;
      z-index: 2;
    }

    .password-toggle-btn {
      position: absolute;
      right: 2px;
      top: 0;
      bottom: 0;
      width: 44px;
      height: 100%;
      background: none;
      border: none;
      color: #94A3B8;
      cursor: pointer;
      font-size: 1.05rem;
      padding: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 3;
      touch-action: manipulation;
    }

    .password-toggle-btn:hover,
    .password-toggle-btn:focus {
      color: var(--rp-navy);
      outline: none;
    }

    /* Form Submit Buttons */
    .btn-submit-custom {
      width: 100%;
      min-height: 48px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      font-size: 0.95rem;
      font-weight: 600;
      border-radius: 10px;
      border: none;
      cursor: pointer;
      background-color: var(--rp-gold);
      color: #FFFFFF;
      transition: all 0.25s ease;
      box-shadow: 0 4px 12px rgba(200, 155, 60, 0.25);
      margin-top: 6px;
      touch-action: manipulation;
    }

    .btn-submit-custom:hover {
      background-color: var(--rp-gold-hover);
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(200, 155, 60, 0.35);
      color: #FFFFFF;
    }

    .btn-submit-custom:active {
      transform: translateY(0);
    }

    /* Footer Links & Notes */
    .card-footer-note {
      text-align: center;
      margin-top: 20px;
      padding-top: 16px;
      border-top: 1px solid #F1F5F9;
      font-size: 0.85rem;
      color: var(--rp-text-muted);
    }

    .card-footer-note a {
      color: var(--rp-gold);
      font-weight: 600;
      text-decoration: none;
      padding: 2px 4px;
    }

    .card-footer-note a:hover {
      text-decoration: underline;
    }

    /* Responsive Adjustments for Mobile (< 576px) */
    @media (max-width: 576px) {
      .register-page-container {
        padding: 0 12px 28px;
      }

      .register-top-bar {
        padding: 14px 4px 8px;
      }

      .btn-back-website {
        padding: 8px 14px;
        font-size: 0.8rem;
        gap: 6px;
      }

      .register-card-wrapper {
        padding: 4px 0;
      }

      .register-card {
        padding: 24px 18px;
        border-radius: 16px;
        box-shadow: 0 8px 24px rgba(23, 36, 58, 0.07);
      }

      .register-logo-container {
        margin-bottom: 16px;
      }

      .register-logo-img {
        max-height: 48px;
      }

      .register-title {
        font-size: 1.35rem;
        margin-bottom: 4px;
      }

      .register-subtitle {
        font-size: 0.825rem;
        margin-bottom: 18px;
      }

      .form-group-wrap {
        margin-bottom: 14px;
      }

      .form-label-custom {
        font-size: 0.8rem;
        margin-bottom: 5px;
      }

      /* Critical for Mobile/iOS: 16px prevents automatic browser zooming on input focus */
      .form-control-custom {
        font-size: 16px !important;
        height: 46px;
        padding-top: 10px;
        padding-bottom: 10px;
      }

      .input-icon-group .form-control-custom {
        padding-left: 42px;
      }

      .input-icon-group i.input-icon-left {
        left: 13px;
        font-size: 0.95rem;
      }

      .btn-submit-custom {
        height: 46px;
        font-size: 0.925rem;
        margin-top: 4px;
      }

      .card-footer-note {
        margin-top: 16px;
        padding-top: 14px;
        font-size: 0.825rem;
      }
    }

    /* Extra Small Screen Adjustments (< 360px: iPhone SE 1st gen, Galaxy Fold) */
    @media (max-width: 360px) {
      .register-page-container {
        padding: 0 8px 20px;
      }

      .register-card {
        padding: 20px 14px;
        border-radius: 14px;
      }

      .register-title {
        font-size: 1.25rem;
      }

      .register-subtitle {
        font-size: 0.8rem;
      }

      .input-icon-group .form-control-custom {
        padding-left: 38px;
      }

      .input-icon-group i.input-icon-left {
        left: 11px;
        font-size: 0.9rem;
      }

      .btn-back-website {
        padding: 7px 12px;
        font-size: 0.775rem;
      }
    }
  </style>
</head>
<body class="register-page-body">

<div class="register-page-container">
  <!-- Top Navigation Bar: Back to Website -->
  <header class="register-top-bar">
    <a href="index.php" class="btn-back-website">
      <i class="bi bi-arrow-left"></i> Back to Website
    </a>
  </header>

  <!-- Main Centered Register Card -->
  <main class="register-card-wrapper">
    <div class="register-card">
      
      <!-- Centered Brand Logo -->
      <div class="register-logo-container">
        <a href="index.php" title="Raj Priya Notebooks Home">
          <img src="<?php echo htmlspecialchars($logos['primary']); ?>" alt="RAJPRIYA NOTEBOOKS" class="register-logo-img">
        </a>
      </div>

      <!-- Title & Subtitle -->
      <h1 class="register-title">Create Account</h1>
      <p class="register-subtitle">Register for a verified Rajpriya customer account</p>

      <?php if ($error): ?>
        <div class="alert alert-danger py-2.5 px-3 small rounded-3 mb-4 d-flex align-items-center gap-2">
          <i class="bi bi-exclamation-circle-fill text-danger fs-6 flex-shrink-0"></i>
          <div><?php echo htmlspecialchars($error); ?></div>
        </div>
      <?php endif; ?>

      <form action="register.php" method="POST" id="registerForm">
        <?php if (!empty($redirect)): ?>
          <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect); ?>">
        <?php endif; ?>

        <!-- Full Name -->
        <div class="form-group-wrap">
          <label class="form-label-custom" for="regName">Full Name <span class="text-danger">*</span></label>
          <div class="input-icon-group">
            <i class="bi bi-person input-icon-left"></i>
            <input type="text" 
                   name="name" 
                   id="regName" 
                   class="form-control-custom" 
                   required 
                   autocomplete="name"
                   placeholder="e.g. Kumar S" 
                   value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
          </div>
        </div>

        <!-- Mobile Phone -->
        <div class="form-group-wrap">
          <label class="form-label-custom" for="regPhone">Mobile Phone Number <span class="text-danger">*</span></label>
          <div class="input-icon-group">
            <i class="bi bi-telephone input-icon-left"></i>
            <input type="tel" 
                   name="phone" 
                   id="regPhone" 
                   class="form-control-custom" 
                   required 
                   autocomplete="tel"
                   pattern="[0-9]{10}" 
                   maxlength="10" 
                   inputmode="numeric"
                   title="Please enter a 10-digit mobile number" 
                   placeholder="e.g. 9842746472" 
                   value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
          </div>
        </div>

        <!-- Customer Type (Required) -->
        <div class="form-group-wrap">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label-custom mb-0" for="regCustomerType">Customer Type <span class="text-danger">*</span></label>
            <span class="text-muted small" style="font-size: 0.725rem;">Wholesaler / Distributor / Retailer</span>
          </div>
          <div class="input-icon-group">
            <i class="bi bi-briefcase input-icon-left"></i>
            <select name="customer_type" id="regCustomerType" class="form-control-custom form-select-custom" required>
              <option value="" disabled <?php echo empty($_POST['customer_type']) ? 'selected' : ''; ?>>Select Customer Type</option>
              <option value="Wholesaler" <?php echo (isset($_POST['customer_type']) && $_POST['customer_type'] === 'Wholesaler') ? 'selected' : ''; ?>>Wholesaler</option>
              <option value="Distributor" <?php echo (isset($_POST['customer_type']) && $_POST['customer_type'] === 'Distributor') ? 'selected' : ''; ?>>Distributor</option>
              <option value="Retailer" <?php echo (isset($_POST['customer_type']) && $_POST['customer_type'] === 'Retailer') ? 'selected' : ''; ?>>Retailer</option>
              <option value="Other" <?php echo (isset($_POST['customer_type']) && $_POST['customer_type'] === 'Other') ? 'selected' : ''; ?>>Other</option>
            </select>
            <i class="bi bi-chevron-down input-icon-right"></i>
          </div>
        </div>

        <!-- District (Used for Customer ID Prefix) -->
        <div class="form-group-wrap">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label-custom mb-0" for="regDistrict">District</label>
            <span class="text-muted small" style="font-size: 0.725rem;">Used for Customer ID</span>
          </div>
          <div class="input-icon-group">
            <i class="bi bi-buildings input-icon-left"></i>
            <input type="text" 
                   name="district" 
                   id="regDistrict" 
                   class="form-control-custom" 
                   autocomplete="address-level1"
                   placeholder="e.g. Chennai, Salem, Madurai, Tirunelveli" 
                   value="<?php echo isset($_POST['district']) ? htmlspecialchars($_POST['district']) : ''; ?>">
          </div>
          <div class="text-muted mt-1" style="font-size: 0.725rem;">Optional. If left blank, City/Town will be used.</div>
        </div>

        <!-- City / Town -->
        <div class="form-group-wrap">
          <label class="form-label-custom" for="regCity">City / Town <span class="text-danger">*</span></label>
          <div class="input-icon-group">
            <i class="bi bi-geo-alt input-icon-left"></i>
            <input type="text" 
                   name="city" 
                   id="regCity" 
                   class="form-control-custom" 
                   required
                   autocomplete="address-level2"
                   placeholder="e.g. Ambur, Chennai, Tirunelveli, Coimbatore" 
                   value="<?php echo isset($_POST['city']) ? htmlspecialchars($_POST['city']) : ''; ?>">
          </div>
        </div>

        <!-- Email Address (Optional) -->
        <div class="form-group-wrap">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <label class="form-label-custom mb-0" for="regEmail">Email Address</label>
            <span class="text-muted small" style="font-size: 0.725rem;">Optional</span>
          </div>
          <div class="input-icon-group">
            <i class="bi bi-envelope input-icon-left"></i>
            <input type="email" 
                   name="email" 
                   id="regEmail" 
                   class="form-control-custom" 
                   autocomplete="email"
                   placeholder="e.g. customer@example.com" 
                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
          </div>
          <div class="text-muted mt-1" style="font-size: 0.725rem;">You can register and order notebooks without an email.</div>
        </div>

        <!-- Password -->
        <div class="form-group-wrap">
          <label class="form-label-custom" for="regPassword">Password <span class="text-danger">*</span></label>
          <div class="input-icon-group has-toggle">
            <i class="bi bi-lock input-icon-left"></i>
            <input type="password" 
                   name="password" 
                   id="regPassword" 
                   class="form-control-custom" 
                   required 
                   autocomplete="new-password"
                   minlength="6" 
                   placeholder="Min 6 characters">
            <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('regPassword', this)" title="Show/Hide Password" aria-label="Toggle password visibility">
              <i class="bi bi-eye"></i>
            </button>
          </div>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn-submit-custom">
          <i class="bi bi-person-plus-fill"></i> Create Customer Account
        </button>

        <!-- Footer Note -->
        <div class="card-footer-note">
          Already have an account? <a href="login.php<?php echo !empty($redirect) ? '?redirect=' . urlencode($redirect) : ''; ?>">Sign In</a>
        </div>
      </form>

    </div>
  </main>
</div>

<script>
function togglePasswordVisibility(inputId, btn) {
  const input = document.getElementById(inputId);
  if (!input) return;
  const icon = btn.querySelector('i');
  if (input.type === 'password') {
    input.type = 'text';
    if (icon) {
      icon.classList.remove('bi-eye');
      icon.classList.add('bi-eye-slash');
    }
  } else {
    input.type = 'password';
    if (icon) {
      icon.classList.remove('bi-eye-slash');
      icon.classList.add('bi-eye');
    }
  }
}
</script>

</body>
</html>
