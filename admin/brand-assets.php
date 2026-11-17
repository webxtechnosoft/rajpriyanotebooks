<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();

// Handle Logo Upload / Replacement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_logo') {
    $logo_id = (int)$_POST['logo_id'];
    $logo_name = sanitize($_POST['logo_name']);

    if (isset($_FILES['logo_file']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['png', 'webp', 'jpg', 'jpeg', 'svg'];
        $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            // Get logo type
            $stmt = $db->prepare("SELECT logo_type FROM logos WHERE id = ?");
            $stmt->execute([$logo_id]);
            $ltype = $stmt->fetchColumn();

            $new_filename = 'logo_' . $ltype . '_' . time() . '.' . $ext;
            $upload_dest = __DIR__ . '/../uploads/logos/' . $new_filename;

            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $upload_dest)) {
                $file_path = 'uploads/logos/' . $new_filename;
                $stmt_up = $db->prepare("UPDATE logos SET logo_name = ?, file_path = ?, updated_at = NOW() WHERE id = ?");
                $stmt_up->execute([$logo_name, $file_path, $logo_id]);
                set_flash('success', "Logo '$logo_name' updated successfully!");
            } else {
                set_flash('error', 'Failed to upload logo file.');
            }
        } else {
            set_flash('error', 'Invalid file type. Please upload PNG, WebP, JPG, or SVG.');
        }
    } else {
        $stmt_up = $db->prepare("UPDATE logos SET logo_name = ?, updated_at = NOW() WHERE id = ?");
        $stmt_up->execute([$logo_name, $logo_id]);
        set_flash('success', 'Logo title updated.');
    }
    header("Location: brand-assets.php");
    exit();
}

$stmt = $db->query("SELECT * FROM logos ORDER BY id ASC");
$logos_list = $stmt->fetchAll();

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 admin-page-header">
  <div>
    <h3 class="fw-bold text-navy mb-0">Brand Logo Asset Management</h3>
    <p class="text-secondary small mb-0">Manage official RAJPRIYA logos extracted from the official PDF (Primary, Secondary, Product Logos).</p>
  </div>
</div>

<div class="alert alert-info border-info mb-4">
  <i class="bi bi-shield-check me-2 fs-5"></i>
  <strong>Brand Rule:</strong> The logos below are the 3 official variations extracted from <code>RAJPRIYA LOGO.pdf</code>. Original proportions and appearance are preserved across the site.
</div>

<div class="row g-4">
  <?php foreach ($logos_list as $l): ?>
    <div class="col-lg-4">
      <div class="bg-white p-4 rounded-3 border h-100 d-flex flex-column justify-content-between">
        <div>
          <div class="d-flex justify-content-between align-items-center mb-3">
            <span class="badge bg-gold text-uppercase fw-bold p-2"><?php echo htmlspecialchars($l['logo_type']); ?> LOGO</span>
            <span class="badge bg-success">ACTIVE</span>
          </div>

          <h5 class="fw-bold text-navy mb-2"><?php echo htmlspecialchars($l['logo_name']); ?></h5>
          
          <!-- Transparent Checkerboard Preview Box -->
          <div class="p-4 rounded-3 border text-center my-3" style="background-color: #f0f0f0; background-image: repeating-linear-gradient(45deg, #e0e0e0 25%, transparent 25%, transparent 75%, #e0e0e0 75%, #e0e0e0), repeating-linear-gradient(45deg, #e0e0e0 25%, #f0f0f0 25%, #f0f0f0 75%, #e0e0e0 75%, #e0e0e0); background-position: 0 0, 10px 10px; background-size: 20px 20px; min-height: 160px; display: flex; align-items: center; justify-content: center;">
            <img src="../<?php echo htmlspecialchars($l['file_path']); ?>?v=<?php echo time(); ?>" alt="<?php echo htmlspecialchars($l['logo_name']); ?>" style="max-height: 130px; max-width: 100%; object-fit: contain;">
          </div>

          <div class="small text-muted mb-3">
            <strong>Used in:</strong>
            <?php if ($l['logo_type']==='primary'): ?>
              Header Navbar, Main Footer, Customer Account, Order Success, Checkout.
            <?php elseif ($l['logo_type']==='secondary'): ?>
              About Us Page, SVK Brand Story, Marketing Banners.
            <?php else: ?>
              Notebook Product Mockups, Product Details, AI Design Logo Overlays.
            <?php endif; ?>
          </div>
        </div>

        <!-- Replace Form -->
        <form action="brand-assets.php" method="POST" enctype="multipart/form-data" class="border-top pt-3">
          <input type="hidden" name="action" value="update_logo">
          <input type="hidden" name="logo_id" value="<?php echo $l['id']; ?>">
          
          <div class="mb-2">
            <label class="form-label small fw-semibold">Logo Name</label>
            <input type="text" name="logo_name" class="form-control form-control-sm" value="<?php echo htmlspecialchars($l['logo_name']); ?>" required>
          </div>
          
          <div class="mb-3">
            <label class="form-label small fw-semibold">Replace Logo Asset (PNG/WebP)</label>
            <input type="file" name="logo_file" class="form-control form-control-sm" accept="image/*">
          </div>

          <button type="submit" class="btn btn-navy btn-sm w-100 fw-bold">Update Logo</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
