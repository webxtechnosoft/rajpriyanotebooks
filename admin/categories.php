<?php
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$db = getDB();

// Delete Action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $db->prepare("DELETE FROM categories WHERE id = ?");
    $stmt->execute([$id]);
    set_flash('success', 'Category deleted successfully.');
    header("Location: categories.php");
    exit();
}

// Add/Edit Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $name = sanitize($_POST['name']);
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
    $description = sanitize($_POST['description']);
    $status = isset($_POST['status']) ? 1 : 0;

    $image_path = isset($_POST['current_image']) ? $_POST['current_image'] : 'assets/images/categories/cat_single_line.webp';

    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $new_filename = 'cat_' . time() . '.' . $ext;
            $upload_dest = __DIR__ . '/../assets/images/categories/' . $new_filename;
            if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_dest)) {
                $image_path = 'assets/images/categories/' . $new_filename;
            }
        }
    }

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE categories SET name=?, slug=?, description=?, image=?, status=? WHERE id=?");
        $stmt->execute([$name, $slug, $description, $image_path, $status, $id]);
        set_flash('success', 'Category updated successfully.');
    } else {
        $stmt = $db->prepare("INSERT INTO categories (name, slug, description, image, status) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $slug, $description, $image_path, $status]);
        set_flash('success', 'New category created successfully.');
    }
    header("Location: categories.php");
    exit();
}

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$sql = "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) as product_count FROM categories c WHERE 1=1";
$params = [];
if ($search !== '') {
    $sql .= " AND (c.name LIKE ? OR c.slug LIKE ? OR c.description LIKE ?)";
    $term = "%{$search}%";
    $params = [$term, $term, $term];
}
$sql .= " ORDER BY c.id ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/includes/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2 admin-page-header">
  <div>
    <h3 class="fw-bold text-navy mb-0">Category Management</h3>
    <p class="text-secondary small mb-0">Add, edit, enable/disable notebook categories.</p>
  </div>
  <button class="btn btn-gold btn-sm fw-bold" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="resetCatForm()">
    <i class="bi bi-plus-lg me-1"></i> Add Category
  </button>
</div>

<!-- Search Bar for Categories -->
<div class="bg-white p-3 rounded-3 border mb-4 shadow-xs">
  <form action="categories.php" method="GET" class="row g-2 align-items-center" id="catSearchForm">
    <div class="col-md-9 col-sm-8">
      <div class="input-group">
        <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
        <input type="text" name="search" id="catSearchInput" class="form-control border-start-0 ps-0" 
               placeholder="Search categories by name (e.g. Long Exercise, Drawing, Student, Ledgers)..." 
               value="<?php echo htmlspecialchars($search); ?>">
        <?php if ($search !== ''): ?>
          <button type="button" class="btn btn-outline-secondary border-start-0" id="catClearBtn" title="Clear search">
            <i class="bi bi-x-lg"></i>
          </button>
        <?php endif; ?>
      </div>
    </div>
    <div class="col-md-3 col-sm-4 d-flex gap-2">
      <button type="submit" class="btn btn-gold btn-sm w-100 fw-bold py-2">
        <i class="bi bi-search me-1"></i> Search
      </button>
      <?php if ($search !== ''): ?>
        <a href="categories.php" class="btn btn-outline-secondary btn-sm d-flex align-items-center justify-content-center" title="Reset Search" style="min-width: 38px;">
          <i class="bi bi-arrow-counterclockwise"></i>
        </a>
      <?php endif; ?>
    </div>
  </form>
</div>

<div class="bg-white p-4 rounded-3 border shadow-xs">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h6 class="fw-bold text-navy mb-0">
      <i class="bi bi-tags text-gold me-1.5"></i> All Categories
      <span class="badge bg-light text-navy border ms-2"><?php echo count($categories); ?> Found</span>
      <?php if ($search !== ''): ?>
        <span class="badge bg-gold-subtle text-navy border border-warning-subtle ms-1">Keyword: "<?php echo htmlspecialchars($search); ?>"</span>
      <?php endif; ?>
    </h6>
  </div>

  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead>
        <tr class="table-light">
          <th>Image</th>
          <th>Category Name</th>
          <th>Slug</th>
          <th>Description</th>
          <th>Products</th>
          <th>Status</th>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($categories)): ?>
          <tr>
            <td colspan="7" class="text-center py-5 text-muted">
              <div class="py-4">
                <i class="bi bi-tags fs-1 text-muted d-block mb-2"></i>
                <h6 class="fw-bold text-navy mb-1">No categories found matching your search</h6>
                <p class="small text-secondary mb-3">No notebook categories matched "<strong><?php echo htmlspecialchars($search); ?></strong>".</p>
                <a href="categories.php" class="btn btn-gold btn-sm px-3 fw-bold">Clear Search & Show All Categories</a>
              </div>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($categories as $cat): ?>
            <tr>
              <td><img src="../<?php echo htmlspecialchars($cat['image']); ?>" alt="" style="width: 44px; height: 44px; object-fit: contain;"></td>
              <td class="fw-bold text-navy"><?php echo htmlspecialchars($cat['name']); ?></td>
              <td><code><?php echo htmlspecialchars($cat['slug']); ?></code></td>
              <td class="small text-muted" style="max-width: 250px;"><?php echo htmlspecialchars($cat['description']); ?></td>
              <td><span class="badge bg-light text-dark border"><?php echo $cat['product_count']; ?> Notebooks</span></td>
              <td>
                <?php if ($cat['status']): ?>
                  <span class="badge bg-success">Active</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Disabled</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <button class="btn btn-sm btn-outline-primary me-1" onclick='editCat(<?php echo json_encode($cat); ?>)'><i class="bi bi-pencil"></i> Edit</button>
                <a href="categories.php?action=delete&id=<?php echo $cat['id']; ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this category?')"><i class="bi bi-trash"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form action="categories.php" method="POST" enctype="multipart/form-data">
        <div class="modal-header">
          <h5 class="modal-title fw-bold text-navy" id="catModalTitle">Add Category</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="id" id="cat_id" value="0">
          <input type="hidden" name="current_image" id="cat_current_image" value="">

          <div class="mb-3">
            <label class="form-label small fw-semibold">Category Name *</label>
            <input type="text" name="name" id="cat_name" class="form-control" required placeholder="Single Line">
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Description</label>
            <textarea name="description" id="cat_description" rows="2" class="form-control"></textarea>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-semibold">Category Image</label>
            <input type="file" name="image" class="form-control">
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="status" id="cat_status" value="1" checked>
            <label class="form-check-label small" for="cat_status">Category Active</label>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-gold">Save Category</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function resetCatForm() {
  document.getElementById('catModalTitle').innerText = 'Add Category';
  document.getElementById('cat_id').value = '0';
  document.getElementById('cat_name').value = '';
  document.getElementById('cat_description').value = '';
  document.getElementById('cat_status').checked = true;
}

function editCat(c) {
  document.getElementById('catModalTitle').innerText = 'Edit Category #' + c.id;
  document.getElementById('cat_id').value = c.id;
  document.getElementById('cat_name').value = c.name;
  document.getElementById('cat_description').value = c.description;
  document.getElementById('cat_current_image').value = c.image;
  document.getElementById('cat_status').checked = (c.status == 1);

  var modal = new bootstrap.Modal(document.getElementById('categoryModal'));
  modal.show();
}

document.addEventListener('DOMContentLoaded', function() {
  const clearBtn = document.getElementById('catClearBtn');
  const input = document.getElementById('catSearchInput');
  if (clearBtn && input) {
    clearBtn.addEventListener('click', function() {
      input.value = '';
      window.location.href = 'categories.php';
    });
  }
});
</script>

<?php require_once __DIR__ . '/includes/admin_footer.php'; ?>
