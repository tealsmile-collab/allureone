<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$db = Database::getInstance();
$error = '';

function location_slug_taken(PDO $db, string $table, string $slug, ?int $exceptId): bool
{
    $sql = "SELECT id FROM {$table} WHERE slug = ?";
    $params = [$slug];
    if ($exceptId) {
        $sql .= ' AND id <> ?';
        $params[] = $exceptId;
    }
    $st = $db->prepare($sql . ' LIMIT 1');
    $st->execute($params);

    return (bool) $st->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::verifyCsrf()) {
    $form = (string) ($_POST['form'] ?? '');
    if ($form === 'city') {
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $slug = $slug !== '' ? slugify($slug) : slugify($name);
        $state = trim((string) ($_POST['state'] ?? ''));
        $order = (int) ($_POST['display_order'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') {
            $error = 'City name is required.';
        } elseif (location_slug_taken($db, 'alluredeal_city', $slug, $id)) {
            $error = 'That city slug is already used.';
        } else {
            if ($id) {
                $db->prepare('UPDATE alluredeal_city SET name=?, slug=?, state=?, display_order=?, is_active=?, updated_by=? WHERE id=? AND is_deleted=0')
                    ->execute([$name, $slug, $state !== '' ? $state : null, $order, $active, Auth::id(), $id]);
            } else {
                $db->prepare('INSERT INTO alluredeal_city (name, slug, state, display_order, is_active, created_by) VALUES (?,?,?,?,?,?)')
                    ->execute([$name, $slug, $state !== '' ? $state : 'Maharashtra', $order, $active, Auth::id()]);
                $id = (int) $db->lastInsertId();
            }
            activity_log('save', 'city', $id);
            header('Location: locations.php?saved=city');
            exit;
        }
    } elseif ($form === 'branch') {
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $cityId = (int) ($_POST['city_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slug = trim((string) ($_POST['slug'] ?? ''));
        $slug = $slug !== '' ? slugify($slug) : slugify($name);
        $address = trim((string) ($_POST['address'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $whatsapp = trim((string) ($_POST['whatsapp'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $order = (int) ($_POST['display_order'] ?? 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        $cityOk = false;
        if ($cityId > 0) {
            $st = $db->prepare('SELECT id FROM alluredeal_city WHERE id=? AND is_deleted=0');
            $st->execute([$cityId]);
            $cityOk = (bool) $st->fetchColumn();
        }
        if ($name === '' || !$cityOk) {
            $error = 'Branch name and city are required.';
        } elseif (strlen($phone) > 20 || strlen($whatsapp) > 20) {
            $error = 'Phone and WhatsApp can be at most 20 characters.';
        } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'Enter a valid branch email.';
        } elseif (location_slug_taken($db, 'alluredeal_branch', $slug, $id)) {
            $error = 'That branch slug is already used.';
        } else {
            $params = [$cityId, $name, $slug, $address !== '' ? $address : null, $phone !== '' ? $phone : null, $whatsapp !== '' ? $whatsapp : null, $email !== '' ? $email : null, $order, $active, Auth::id()];
            if ($id) {
                $db->prepare('UPDATE alluredeal_branch SET city_id=?, name=?, slug=?, address=?, phone=?, whatsapp=?, email=?, display_order=?, is_active=?, updated_by=? WHERE id=? AND is_deleted=0')
                    ->execute([...$params, $id]);
            } else {
                $db->prepare('INSERT INTO alluredeal_branch (city_id, name, slug, address, phone, whatsapp, email, display_order, is_active, created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute($params);
                $id = (int) $db->lastInsertId();
            }
            activity_log('save', 'branch', $id);
            header('Location: locations.php?saved=branch');
            exit;
        }
    }
}

$editCity = null;
$editBranch = null;
if ($error !== '' && ($_POST['form'] ?? '') === 'city') {
    $editCity = [
        'id' => (int) ($_POST['id'] ?? 0),
        'name' => (string) ($_POST['name'] ?? ''),
        'slug' => (string) ($_POST['slug'] ?? ''),
        'state' => (string) ($_POST['state'] ?? ''),
        'display_order' => (int) ($_POST['display_order'] ?? 0),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
} elseif (!empty($_GET['edit_city'])) {
    $st = $db->prepare('SELECT * FROM alluredeal_city WHERE id=? AND is_deleted=0');
    $st->execute([(int) $_GET['edit_city']]);
    $editCity = $st->fetch() ?: null;
}
if ($error !== '' && ($_POST['form'] ?? '') === 'branch') {
    $editBranch = [
        'id' => (int) ($_POST['id'] ?? 0),
        'city_id' => (int) ($_POST['city_id'] ?? 0),
        'name' => (string) ($_POST['name'] ?? ''),
        'slug' => (string) ($_POST['slug'] ?? ''),
        'address' => (string) ($_POST['address'] ?? ''),
        'phone' => (string) ($_POST['phone'] ?? ''),
        'whatsapp' => (string) ($_POST['whatsapp'] ?? ''),
        'email' => (string) ($_POST['email'] ?? ''),
        'display_order' => (int) ($_POST['display_order'] ?? 0),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
} elseif (!empty($_GET['edit_branch'])) {
    $st = $db->prepare('SELECT * FROM alluredeal_branch WHERE id=? AND is_deleted=0');
    $st->execute([(int) $_GET['edit_branch']]);
    $editBranch = $st->fetch() ?: null;
}

$cities = $db->query('SELECT * FROM alluredeal_city WHERE is_deleted=0 ORDER BY display_order, name')->fetchAll();
$branches = $db->query(
    'SELECT b.*, c.name AS city_name
     FROM alluredeal_branch b
     JOIN alluredeal_city c ON c.id = b.city_id
     WHERE b.is_deleted=0
     ORDER BY c.display_order, b.display_order, b.name'
)->fetchAll();
$saved = (string) ($_GET['saved'] ?? '');

admin_header('Cities & Branches', 'locations');
?>
<?php if ($saved === 'city'): ?><div class="alert alert-success">City saved.</div><?php endif; ?>
<?php if ($saved === 'branch'): ?><div class="alert alert-success">Branch saved.</div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-4"><div class="panel">
    <h2><?= !empty($editCity['id']) ? 'Edit' : 'Add' ?> City</h2>
    <form method="post" class="vstack gap-2">
      <?= Security::csrfField() ?>
      <input type="hidden" name="form" value="city">
      <input type="hidden" name="id" value="<?= e((string) ($editCity['id'] ?? '')) ?>">
      <input class="form-control" name="name" placeholder="City name" required value="<?= e($editCity['name'] ?? '') ?>">
      <input class="form-control" name="slug" placeholder="Slug" value="<?= e($editCity['slug'] ?? '') ?>">
      <input class="form-control" name="state" placeholder="State" value="<?= e($editCity['state'] ?? 'Maharashtra') ?>">
      <input class="form-control" type="number" name="display_order" placeholder="Order" value="<?= e((string) ($editCity['display_order'] ?? 0)) ?>">
      <label><input type="checkbox" name="is_active" <?= !isset($editCity) || !empty($editCity['is_active']) ? 'checked' : '' ?>> Active</label>
      <button class="btn btn-brand">Save city</button>
    </form>
  </div></div>
  <div class="col-lg-8"><div class="panel">
    <h2>Cities</h2>
    <table class="table"><thead><tr><th>Name</th><th>State</th><th>Order</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($cities as $r): ?>
      <tr>
        <td><?= e($r['name']) ?></td>
        <td><?= e((string) ($r['state'] ?? '')) ?></td>
        <td><?= (int) $r['display_order'] ?></td>
        <td><?= !empty($r['is_active']) ? 'Active' : 'Inactive' ?></td>
        <td>
          <a href="?edit_city=<?= (int) $r['id'] ?>">Edit</a>
          · <button type="button" class="btn btn-link text-danger p-0" data-delete="<?= (int) $r['id'] ?>" data-type="city">Delete</button>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($cities === []): ?><tr><td colspan="5">No cities yet.</td></tr><?php endif; ?>
    </tbody></table>
  </div></div>
</div>

<div class="row g-3">
  <div class="col-lg-4"><div class="panel">
    <h2><?= !empty($editBranch['id']) ? 'Edit' : 'Add' ?> Branch</h2>
    <form method="post" class="vstack gap-2">
      <?= Security::csrfField() ?>
      <input type="hidden" name="form" value="branch">
      <input type="hidden" name="id" value="<?= e((string) ($editBranch['id'] ?? '')) ?>">
      <select class="form-select" name="city_id" required>
        <option value="">Select city</option>
        <?php foreach ($cities as $c): ?>
          <option value="<?= (int) $c['id'] ?>" <?= (int) ($editBranch['city_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?><?= empty($c['is_active']) ? ' (inactive)' : '' ?></option>
        <?php endforeach; ?>
      </select>
      <input class="form-control" name="name" placeholder="Branch name" required value="<?= e($editBranch['name'] ?? '') ?>">
      <input class="form-control" name="slug" placeholder="Slug" value="<?= e($editBranch['slug'] ?? '') ?>">
      <textarea class="form-control" name="address" placeholder="Address" rows="2"><?= e($editBranch['address'] ?? '') ?></textarea>
      <input class="form-control" name="phone" placeholder="Phone" maxlength="20" value="<?= e($editBranch['phone'] ?? '') ?>">
      <input class="form-control" name="whatsapp" placeholder="WhatsApp" maxlength="20" value="<?= e($editBranch['whatsapp'] ?? '') ?>">
      <input class="form-control" type="email" name="email" placeholder="Email" value="<?= e($editBranch['email'] ?? '') ?>">
      <input class="form-control" type="number" name="display_order" placeholder="Order" value="<?= e((string) ($editBranch['display_order'] ?? 0)) ?>">
      <label><input type="checkbox" name="is_active" <?= !isset($editBranch) || !empty($editBranch['is_active']) ? 'checked' : '' ?>> Active</label>
      <button class="btn btn-brand">Save branch</button>
    </form>
  </div></div>
  <div class="col-lg-8"><div class="panel">
    <h2>Branches</h2>
    <table class="table"><thead><tr><th>Branch</th><th>City</th><th>Phone</th><th>Order</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($branches as $r): ?>
      <tr>
        <td><?= e($r['name']) ?></td>
        <td><?= e((string) ($r['city_name'] ?? '')) ?></td>
        <td><?= e((string) ($r['phone'] ?? '')) ?></td>
        <td><?= (int) $r['display_order'] ?></td>
        <td><?= !empty($r['is_active']) ? 'Active' : 'Inactive' ?></td>
        <td>
          <a href="?edit_branch=<?= (int) $r['id'] ?>">Edit</a>
          · <button type="button" class="btn btn-link text-danger p-0" data-delete="<?= (int) $r['id'] ?>" data-type="branch">Delete</button>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($branches === []): ?><tr><td colspan="6">No branches yet.</td></tr><?php endif; ?>
    </tbody></table>
  </div></div>
</div>
<?php admin_footer(); ?>
