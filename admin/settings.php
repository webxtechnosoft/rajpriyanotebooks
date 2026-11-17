<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();

// Handle Settings POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    foreach ($_POST['settings'] as $key => $val) {
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([sanitize($key), sanitize($val)]);
    }
    set_flash('success', 'Website settings updated successfully!');
    header("Location: settings.php");
    exit();
}

// Handle Password Change POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $current_pass = $_POST['current_password'];
    $new_pass     = $_POST['new_password'];
    $admin        = get_current_admin();

    if ($admin && $current_pass === $admin['password']) {
        $stmt = $db->prepare("UPDATE admins SET password = ? WHERE id = ?");
        $stmt->execute([$new_pass, $admin['id']]);
        set_flash('success', 'Admin password changed successfully!');
    } else {
        set_flash('error', 'Current password is incorrect.');
    }
    header("Location: settings.php");
    exit();
}

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 admin-page-header">
  <div>
    <h3 class="fw-bold text-navy mb-0">Website Settings</h3>
    <p class="text-secondary small mb-0">Update brand details, store contact information, shipping thresholds, and admin security.</p>
  </div>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="bg-white p-4 rounded-3 border">
      <h5 class="fw-bold text-navy mb-4"><i class="bi bi-sliders text-gold me-2"></i> Store Configuration</h5>
      <form action="settings.php" method="POST">
        <input type="hidden" name="action" value="save_settings">

        <div class="mb-3">
          <label class="form-label small fw-semibold text-navy">Website Base URL / App URL (for QR Code Scanning & Stickers)</label>
          <input type="text" name="settings[app_url]" class="form-control font-monospace fs-7" value="<?php echo htmlspecialchars(get_setting('app_url', get_app_base_url())); ?>" placeholder="https://yourdomain.com/rajpriya">
          <div class="form-text small text-muted">
            Used to generate scannable QR codes for parcel stickers, order tracking & invoices. In production, set your live domain (e.g. <code>https://rajpriyanotebooks.com</code>). For local WiFi phone testing, set your computer's local IP (e.g. <code>http://192.168.1.15/rajpriya</code>).
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Browser Title / SEO Name</label>
          <input type="text" name="settings[site_title]" class="form-control" value="<?php echo htmlspecialchars(get_setting('site_title')); ?>" required>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Official Brand Name</label>
          <input type="text" name="settings[brand_name]" class="form-control" value="<?php echo htmlspecialchars(get_setting('brand_name')); ?>" required>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Customer Support Phone</label>
          <input type="text" name="settings[phone]" class="form-control" value="<?php echo htmlspecialchars(get_setting('phone')); ?>" required>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Admin WhatsApp Number (for Order Invoices & Alerts)</label>
          <input type="text" name="settings[admin_whatsapp]" class="form-control" value="<?php echo htmlspecialchars(get_setting('admin_whatsapp', defined('ADMIN_WHATSAPP_NUMBER') ? ADMIN_WHATSAPP_NUMBER : '919842746472')); ?>" placeholder="e.g. 919842746472">
          <div class="form-text small text-muted">Country code required without +, spaces, brackets, or hyphens (e.g. 919842746472 for India).</div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Official Support Email</label>
          <input type="email" name="settings[email]" class="form-control" value="<?php echo htmlspecialchars(get_setting('email')); ?>" required>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Headquarters Physical Address</label>
          <textarea name="settings[address]" rows="2" class="form-control" required><?php echo htmlspecialchars(get_setting('address')); ?></textarea>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">Company GSTIN</label>
          <input type="text" name="settings[company_gstin]" class="form-control" value="<?php echo htmlspecialchars(get_setting('company_gstin', '33AHNPM8502B1ZM')); ?>" placeholder="33AHNPM8502B1ZM">
        </div>

        <div class="mb-4">
          <label class="form-label small fw-semibold">Free Shipping Threshold Amount (₹)</label>
          <input type="number" step="0.01" name="settings[free_shipping_threshold]" class="form-control" value="<?php echo htmlspecialchars(get_setting('free_shipping_threshold', 499)); ?>" required>
        </div>

        <button type="submit" class="btn btn-gold fw-bold px-4">Save Store Settings</button>
      </form>
    </div>
  </div>

  <!-- Password Change & WhatsApp API Boxes -->
  <div class="col-lg-5">
    <!-- WhatsApp Cloud API Configuration Card -->
    <div class="bg-white p-4 rounded-3 border mb-4">
      <div class="d-flex align-items-center justify-content-between mb-3 border-bottom pb-2">
        <h5 class="fw-bold text-navy mb-0">
          <i class="bi bi-whatsapp text-success me-2"></i> WhatsApp Cloud API
        </h5>
        <?php if (is_whatsapp_configured()): ?>
          <span class="badge bg-success fw-bold px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i> Active</span>
        <?php else: ?>
          <span class="badge bg-warning text-dark fw-bold px-2 py-1"><i class="bi bi-exclamation-triangle-fill me-1"></i> Not Configured</span>
        <?php endif; ?>
      </div>
      <p class="small text-muted mb-3">Configure Meta WhatsApp Cloud API credentials for automatic invoice PDF dispatch and live delivery tracking.</p>

      <form action="settings.php" method="POST">
        <input type="hidden" name="action" value="save_settings">

        <div class="mb-3">
          <label class="form-label small fw-semibold text-navy">WhatsApp Phone Number ID</label>
          <input type="text" name="settings[whatsapp_phone_number_id]" class="form-control font-monospace fs-7" value="<?php echo htmlspecialchars(get_setting('whatsapp_phone_number_id', defined('WHATSAPP_PHONE_NUMBER_ID') ? WHATSAPP_PHONE_NUMBER_ID : '')); ?>" placeholder="e.g. 109283746501928" required>
          <div class="form-text small text-muted">Found in Meta App Dashboard &gt; WhatsApp &gt; API Setup.</div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold text-navy">Permanent Access Token</label>
          <div class="input-group">
            <input type="password" id="wa_token_input" name="settings[whatsapp_access_token]" class="form-control font-monospace fs-7" value="<?php echo htmlspecialchars(get_setting('whatsapp_access_token', defined('WHATSAPP_ACCESS_TOKEN') ? WHATSAPP_ACCESS_TOKEN : '')); ?>" placeholder="EAA..." required>
            <button type="button" class="btn btn-outline-secondary btn-sm" onclick="const f = document.getElementById('wa_token_input'); f.type = f.type === 'password' ? 'text' : 'password'; this.innerHTML = f.type === 'password' ? '<i class=\'bi bi-eye\'></i>' : '<i class=\'bi bi-eye-slash\'></i>';">
              <i class="bi bi-eye"></i>
            </button>
          </div>
          <div class="form-text small text-muted">Meta System User permanent token with <code>whatsapp_business_messaging</code> permission.</div>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-sm-6">
            <label class="form-label small fw-semibold text-navy">Graph API Version</label>
            <input type="text" name="settings[whatsapp_api_version]" class="form-control font-monospace fs-7" value="<?php echo htmlspecialchars(get_setting('whatsapp_api_version', defined('WHATSAPP_GRAPH_API_VERSION') ? WHATSAPP_GRAPH_API_VERSION : 'v20.0')); ?>" placeholder="v20.0" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label small fw-semibold text-navy">Auto-Send on Approval</label>
            <?php $auto_send_val = get_setting('whatsapp_auto_send', '1'); ?>
            <select name="settings[whatsapp_auto_send]" class="form-select form-select-sm fw-semibold">
              <option value="1" <?php echo ($auto_send_val == '1' || $auto_send_val === 'true') ? 'selected' : ''; ?>>Yes (Auto-send)</option>
              <option value="0" <?php echo ($auto_send_val == '0' || $auto_send_val === 'false') ? 'selected' : ''; ?>>No (Manual Only)</option>
            </select>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold text-navy">Document Template Name (Optional Fallback)</label>
          <input type="text" name="settings[whatsapp_template_name]" class="form-control font-monospace fs-7" value="<?php echo htmlspecialchars(get_setting('whatsapp_template_name', defined('WHATSAPP_TEMPLATE_NAME') ? WHATSAPP_TEMPLATE_NAME : 'invoice_delivery')); ?>" placeholder="invoice_delivery">
          <div class="form-text small text-muted">Approved Meta template name for conversations outside 24h window.</div>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold text-navy">Webhook Verify Token</label>
          <input type="text" name="settings[whatsapp_webhook_verify_token]" class="form-control font-monospace fs-7" value="<?php echo htmlspecialchars(get_setting('whatsapp_webhook_verify_token', defined('WHATSAPP_WEBHOOK_VERIFY_TOKEN') ? WHATSAPP_WEBHOOK_VERIFY_TOKEN : 'rajpriya_wa_secure_webhook_token_2026')); ?>" required>
        </div>

        <div class="p-2.5 bg-light rounded border mb-3">
          <label class="form-label small fw-bold text-navy mb-1"><i class="bi bi-link-45deg me-1"></i> Webhook Callback URL</label>
          <input type="text" readonly class="form-control form-control-sm font-monospace text-secondary" value="<?php echo htmlspecialchars(get_app_base_url() . '/webhook_whatsapp.php'); ?>">
          <div class="small text-muted mt-1" style="font-size: 0.72rem;">
            Subscribe to <code>messages</code> field in Meta Developer Portal to receive real-time <code>sent</code>, <code>delivered</code>, and <code>read</code> status updates.
          </div>
        </div>

        <button type="submit" class="btn btn-success w-100 fw-bold">
          <i class="bi bi-save me-1"></i> Save WhatsApp API Settings
        </button>
      </form>
    </div>

    <!-- Password Change Box -->
    <div class="bg-white p-4 rounded-3 border">
      <h5 class="fw-bold text-navy mb-3"><i class="bi bi-shield-lock text-gold me-2"></i> Change Admin Password</h5>
      <form action="settings.php" method="POST">
        <input type="hidden" name="action" value="change_password">

        <div class="mb-3">
          <label class="form-label small fw-semibold">Current Password</label>
          <input type="password" name="current_password" class="form-control" required>
        </div>

        <div class="mb-3">
          <label class="form-label small fw-semibold">New Password</label>
          <input type="password" name="new_password" class="form-control" required minlength="6">
        </div>

        <button type="submit" class="btn btn-navy w-100 fw-bold">Update Password</button>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
