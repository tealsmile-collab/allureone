<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
$db = Database::getInstance();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::verifyCsrf()) {
    $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    $name = trim((string) $_POST['name']);
    $slug = trim((string) ($_POST['slug'] ?: slugify($name)));
    $desc = $_POST['description'] ?? '';
    $order = (int) ($_POST['display_order'] ?? 0);
    $active = isset($_POST['is_active']) ? 1 : 0;
    if ($id) {
        $db->prepare('UPDATE alluredeal_category SET name=?, slug=?, description=?, display_order=?, is_active=?, updated_by=? WHERE id=?')
            ->execute([$name, $slug, $desc, $order, $active, Auth::id(), $id]);
    } else {
        $db->prepare('INSERT INTO alluredeal_category (name, slug, description, display_order, is_active, created_by) VALUES (?,?,?,?,?,?)')
            ->execute([$name, $slug, $desc, $order, $active, Auth::id()]);
    }
    $msg = 'Category saved.';
}

$edit = null;
if (!empty($_GET['edit'])) {
    $st = $db->prepare('SELECT * FROM alluredeal_category WHERE id=? AND is_deleted=0');
    $st->execute([(int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$rows = $db->query('SELECT * FROM alluredeal_category WHERE is_deleted=0 ORDER BY display_order, name')->fetchAll();
admin_header('Categories', 'categories');
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-md-4"><div class="panel">
    <h2><?= $edit ? 'Edit' : 'Add' ?> Category</h2>
    <form method="post" class="vstack gap-2">
      <?= Security::csrfField() ?>
      <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
      <input class="form-control" name="name" placeholder="Name" required value="<?= e($edit['name'] ?? '') ?>">
      <input class="form-control" name="slug" placeholder="Slug" value="<?= e($edit['slug'] ?? '') ?>">
      <textarea class="form-control" name="description" placeholder="Description" rows="3"><?= e($edit['description'] ?? '') ?></textarea>
      <input class="form-control" type="number" name="display_order" value="<?= e((string) ($edit['display_order'] ?? 0)) ?>">
      <label><input type="checkbox" name="is_active" <?= !isset($edit) || !empty($edit['is_active']) ? 'checked' : '' ?>> Active</label>
      <button class="btn btn-brand">Save</button>
    </form>
  </div></div>
  <div class="col-md-8"><div class="panel">
    <h2>Categories</h2>
    <table class="table"><thead><tr><th>Name</th><th>Slug</th><th>Order</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['name']) ?></td><td><?= e($r['slug']) ?></td><td><?= (int) $r['display_order'] ?></td>
        <td><a href="?edit=<?= (int) $r['id'] ?>">Edit</a> · <button class="btn btn-link text-danger p-0" data-delete="<?= (int) $r['id'] ?>" data-type="category">Delete</button></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div></div>
</div>
<?php admin_footer(); ?>
