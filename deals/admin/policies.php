<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
$db = Database::getInstance();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::verifyCsrf()) {
    $db->prepare('UPDATE alluredeal_policy SET title=?, content=?, display_order=?, is_active=?, updated_by=? WHERE id=?')
        ->execute([
            $_POST['title'],
            $_POST['content'],
            (int) $_POST['display_order'],
            isset($_POST['is_active']) ? 1 : 0,
            Auth::id(),
            (int) $_POST['id'],
        ]);
    $msg = 'Policy updated.';
}

$edit = null;
if (!empty($_GET['edit'])) {
    $st = $db->prepare('SELECT * FROM alluredeal_policy WHERE id=?');
    $st->execute([(int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$rows = $db->query('SELECT * FROM alluredeal_policy WHERE is_deleted=0 ORDER BY display_order')->fetchAll();
admin_header('Policies', 'policies');
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-md-5"><div class="panel">
    <h2><?= $edit ? 'Edit Policy' : 'Select a policy to edit' ?></h2>
    <?php if ($edit): ?>
    <form method="post" class="vstack gap-2">
      <?= Security::csrfField() ?>
      <input type="hidden" name="id" value="<?= (int) $edit['id'] ?>">
      <input class="form-control" name="title" value="<?= e($edit['title']) ?>" required>
      <textarea class="form-control" name="content" rows="12" required><?= e($edit['content']) ?></textarea>
      <input class="form-control" type="number" name="display_order" value="<?= (int) $edit['display_order'] ?>">
      <label><input type="checkbox" name="is_active" <?= $edit['is_active'] ? 'checked' : '' ?>> Active</label>
      <button class="btn btn-brand">Save Policy</button>
    </form>
    <?php else: ?><p class="text-muted">Choose a policy from the list.</p><?php endif; ?>
  </div></div>
  <div class="col-md-7"><div class="panel">
    <h2>Policy Pages</h2>
    <table class="table"><thead><tr><th>Title</th><th>Slug</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= e($r['title']) ?></td>
        <td><?= e($r['slug']) ?></td>
        <td><a href="?edit=<?= (int) $r['id'] ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table>
  </div></div>
</div>
<?php admin_footer(); ?>
