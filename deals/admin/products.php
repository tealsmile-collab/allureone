<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$db = Database::getInstance();
$catalog = new CatalogModel();
$categories = $catalog->categories();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::verifyCsrf()) {
    $data = [
        'id' => $_POST['id'] ?? null,
        'category_id' => (int) $_POST['category_id'],
        'name' => trim((string) $_POST['name']),
        'slug' => trim((string) ($_POST['slug'] ?? '')),
        'short_description' => $_POST['short_description'] ?? '',
        'long_description' => $_POST['long_description'] ?? '',
        'benefits' => $_POST['benefits'] ?? '',
        'duration' => (int) ($_POST['duration'] ?? 0),
        'original_price' => (float) $_POST['original_price'],
        'offer_price' => (float) $_POST['offer_price'],
        'discount_percent' => (float) ($_POST['discount_percent'] ?? 0),
        'auto_strike_price' => isset($_POST['auto_strike_price']) ? 1 : 0,
        'is_today_deal' => isset($_POST['is_today_deal']) ? 1 : 0,
        'is_featured' => isset($_POST['is_featured']) ? 1 : 0,
        'is_bestseller' => isset($_POST['is_bestseller']) ? 1 : 0,
        'display_order' => (int) ($_POST['display_order'] ?? 0),
        'seo_title' => $_POST['seo_title'] ?? '',
        'seo_description' => $_POST['seo_description'] ?? '',
        'seo_keywords' => $_POST['seo_keywords'] ?? '',
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
        'image' => $_POST['image'] ?? null,
    ];

    if (!empty($_FILES['image_file']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, (array) config('app.allowed_images'), true)) {
            $name = 'product-' . time() . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
            $dest = upload_path('products') . '/' . $name;
            if (move_uploaded_file($_FILES['image_file']['tmp_name'], $dest)) {
                $data['image'] = 'uploads/products/' . $name;
            }
        }
    }

    $id = !empty($data['id']) ? (int) $data['id'] : null;
    (new ProductModel())->save($data, $id);
    $msg = 'Product saved successfully.';
}

$edit = null;
if (!empty($_GET['edit'])) {
    $edit = (new ProductModel())->find((int) $_GET['edit']);
}

$products = $db->query(
    'SELECT p.id, p.name, p.offer_price, p.original_price, p.is_active, p.is_today_deal, c.name AS category_name
     FROM alluredeal_product p
     LEFT JOIN alluredeal_category c ON c.id = p.category_id
     WHERE p.is_deleted = 0
     ORDER BY p.id DESC'
)->fetchAll();

admin_header('Products', 'products');
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-5">
    <div class="panel">
      <h2><?= $edit ? 'Edit Product' : 'Add Product' ?></h2>
      <form method="post" enctype="multipart/form-data" class="row g-2">
        <?= Security::csrfField() ?>
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div class="col-12"><label class="form-label">Name</label><input name="name" class="form-control" required value="<?= e($edit['name'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">SEO URL (slug)</label><input name="slug" class="form-control" value="<?= e($edit['slug'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">Category</label>
          <select name="category_id" class="form-select" required>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (($edit['category_id'] ?? '') == $c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-12"><label class="form-label">Short Description</label><textarea name="short_description" class="form-control" rows="2"><?= e($edit['short_description'] ?? '') ?></textarea></div>
        <div class="col-12"><label class="form-label">Long Description</label><textarea name="long_description" class="form-control" rows="3"><?= e($edit['long_description'] ?? '') ?></textarea></div>
        <div class="col-12"><label class="form-label">Benefits (semicolon separated)</label><input name="benefits" class="form-control" value="<?= e($edit['benefits'] ?? '') ?>"></div>
        <div class="col-4"><label class="form-label">Duration</label><input type="number" name="duration" class="form-control" value="<?= e((string) ($edit['duration'] ?? 60)) ?>"></div>
        <div class="col-4"><label class="form-label">Original *</label><input type="number" step="0.01" name="original_price" class="form-control" required value="<?= e((string) ($edit['original_price'] ?? '')) ?>"><div class="form-text">Incl. GST</div></div>
        <div class="col-4"><label class="form-label">Offer *</label><input type="number" step="0.01" name="offer_price" class="form-control" required value="<?= e((string) ($edit['offer_price'] ?? '')) ?>"><div class="form-text">Incl. GST</div></div>
        <div class="col-12">
          <label class="form-label">Image</label>
          <input type="file" name="image_file" class="form-control" accept="image/*">
          <small class="text-muted d-block mt-1">Required resolution: <strong>1024 × 768</strong> px (JPG/PNG/WebP). Max 5 MB.</small>
          <?php if (!empty($edit['image_path'])): ?><input type="hidden" name="image" value="<?= e($edit['image_path']) ?>"><small class="text-muted">Current image kept if no new upload</small><?php endif; ?>
        </div>
        <div class="col-12 d-flex flex-wrap gap-3">
          <label><input type="checkbox" name="auto_strike_price" <?= !isset($edit) || !empty($edit['auto_strike_price']) ? 'checked' : '' ?>> Auto Strike</label>
          <label><input type="checkbox" name="is_today_deal" <?= !empty($edit['is_today_deal']) ? 'checked' : '' ?>> Today's Deal</label>
          <label><input type="checkbox" name="is_featured" <?= !empty($edit['is_featured']) ? 'checked' : '' ?>> Featured</label>
          <label><input type="checkbox" name="is_bestseller" <?= !empty($edit['is_bestseller']) ? 'checked' : '' ?>> Bestseller</label>
          <label><input type="checkbox" name="is_active" <?= !isset($edit) || !empty($edit['is_active'] ?? 1) || ($edit['is_active'] ?? 1) ? 'checked' : '' ?>> Active</label>
        </div>
        <div class="col-6"><label class="form-label">Display Order</label><input type="number" name="display_order" class="form-control" value="<?= e((string) ($edit['display_order'] ?? 0)) ?>"></div>
        <div class="col-12"><label class="form-label">SEO Title</label><input name="seo_title" class="form-control" value="<?= e($edit['seo_title'] ?? '') ?>"></div>
        <div class="col-12"><label class="form-label">SEO Description</label><textarea name="seo_description" class="form-control" rows="2"><?= e($edit['seo_description'] ?? '') ?></textarea></div>
        <div class="col-12"><label class="form-label">SEO Keywords</label><input name="seo_keywords" class="form-control" value="<?= e($edit['seo_keywords'] ?? '') ?>"></div>
        <div class="col-12"><button class="btn btn-brand">Save Product</button>
          <?php if ($edit): ?><a class="btn btn-outline-secondary" href="products.php">Cancel</a><?php endif; ?>
        </div>
      </form>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="panel">
      <h2>All Products</h2>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead><tr><th>ID</th><th>Name</th><th>Category</th><th>Price</th><th>Flags</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($products as $p): ?>
            <tr>
              <td><?= (int) $p['id'] ?></td>
              <td><?= e($p['name']) ?></td>
              <td><?= e($p['category_name']) ?></td>
              <td><?= e(money((float) $p['offer_price'])) ?></td>
              <td><?= $p['is_today_deal'] ? 'Deal ' : '' ?><?= $p['is_active'] ? 'Active' : 'Off' ?></td>
              <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-dark" href="?edit=<?= (int) $p['id'] ?>">Edit</a>
                <button class="btn btn-sm btn-outline-danger" data-delete="<?= (int) $p['id'] ?>" data-type="product">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php admin_footer(); ?>
