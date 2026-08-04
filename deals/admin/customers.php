<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';

$db = Database::getInstance();
$catalog = new CatalogModel();
$cities = $catalog->cities();
$msg = '';
$err = '';

$genders = [
    'female' => 'Female',
    'male' => 'Male',
    'other' => 'Other',
    'prefer_not' => 'Prefer not to say',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::verifyCsrf($_POST['csrf_token'] ?? null)) {
    $action = (string) ($_POST['action'] ?? 'save');
    $id = !empty($_POST['id']) ? (int) $_POST['id'] : 0;

    if ($action === 'delete' && $id > 0) {
        // Hard delete customer (orders keep denormalized name/mobile)
        $db->prepare('UPDATE alluredeal_cart SET customer_id = NULL WHERE customer_id = ?')->execute([$id]);
        $db->prepare('UPDATE alluredeal_coupon_usage SET customer_id = NULL WHERE customer_id = ?')->execute([$id]);
        try {
            $db->prepare('UPDATE alluredeal_wishlist SET customer_id = NULL WHERE customer_id = ?')->execute([$id]);
        } catch (Throwable $e) {
            // wishlist table may not exist on older DBs
        }
        $db->prepare('UPDATE alluredeal_orders SET customer_id = NULL WHERE customer_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM alluredeal_customer WHERE id = ?')->execute([$id]);
        activity_log('hard_delete', 'customer', $id);
        redirect(base_url('admin/customers.php?deleted=1'));
    }

    if ($action === 'save') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $mobileLocal = preg_replace('/\D+/', '', (string) ($_POST['mobile'] ?? '')) ?? '';
        $countryCode = preg_replace('/\D+/', '', (string) ($_POST['country_code'] ?? '91')) ?: '91';
        $email = trim((string) ($_POST['email'] ?? ''));
        $gender = (string) ($_POST['gender'] ?? '');
        $cityId = !empty($_POST['city_id']) ? (int) $_POST['city_id'] : null;
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $active = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || mb_strlen($name) > 80) {
            $err = 'Name is required (max 80 characters).';
        } elseif ($mobileLocal === '' || !preg_match('/^\d{10}$/', $mobileLocal)) {
            $err = 'Mobile must be exactly 10 digits.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'Enter a valid email address.';
        } elseif ($gender !== '' && !isset($genders[$gender])) {
            $err = 'Invalid gender.';
        } else {
            $mobile = format_phone_with_country($mobileLocal, $countryCode);
            $dup = $db->prepare(
                'SELECT id FROM alluredeal_customer WHERE mobile = ? AND id <> ? LIMIT 1'
            );
            $dup->execute([$mobile, $id]);
            if ($dup->fetchColumn()) {
                $err = 'Another customer already uses this mobile number.';
            } else {
                if ($id > 0) {
                    $db->prepare(
                        'UPDATE alluredeal_customer
                         SET name=?, mobile=?, email=?, gender=?, city_id=?, notes=?, is_active=?, is_deleted=0, updated_by=?, updated_at=NOW()
                         WHERE id=?'
                    )->execute([
                        mb_substr($name, 0, 80),
                        $mobile,
                        $email !== '' ? mb_substr($email, 0, 100) : null,
                        $gender !== '' ? $gender : null,
                        $cityId,
                        mb_substr($notes, 0, 150),
                        $active,
                        Auth::id(),
                        $id,
                    ]);
                    activity_log('update', 'customer', $id);
                    redirect(base_url('admin/customers.php?saved=1&edit=' . $id));
                } else {
                    $db->prepare(
                        'INSERT INTO alluredeal_customer
                         (name, mobile, email, gender, city_id, notes, is_active, created_by, created_at)
                         VALUES (?,?,?,?,?,?,?,?,NOW())'
                    )->execute([
                        mb_substr($name, 0, 80),
                        $mobile,
                        $email !== '' ? mb_substr($email, 0, 100) : null,
                        $gender !== '' ? $gender : null,
                        $cityId,
                        mb_substr($notes, 0, 150),
                        $active,
                        Auth::id(),
                    ]);
                    $newId = (int) $db->lastInsertId();
                    activity_log('create', 'customer', $newId);
                    redirect(base_url('admin/customers.php?saved=1'));
                }
            }
        }
    }
}

if (!empty($_GET['saved'])) {
    $msg = 'Customer saved.';
}
if (!empty($_GET['deleted'])) {
    $msg = 'Customer permanently deleted.';
}

$edit = null;
$editMobileLocal = '';
$editCountryCode = '91';
$editId = !empty($_GET['edit']) ? (int) $_GET['edit'] : (!empty($_POST['id']) ? (int) $_POST['id'] : 0);
if ($editId > 0) {
    $st = $db->prepare('SELECT * FROM alluredeal_customer WHERE id=? LIMIT 1');
    $st->execute([$editId]);
    $edit = $st->fetch() ?: null;
    if ($edit) {
        $full = preg_replace('/\D+/', '', (string) ($edit['mobile'] ?? '')) ?? '';
        if (strlen($full) > 10 && str_starts_with($full, '91')) {
            $editCountryCode = '91';
            $editMobileLocal = substr($full, -10);
        } elseif (strlen($full) === 10) {
            $editMobileLocal = $full;
            $editCountryCode = '91';
        } else {
            $editMobileLocal = strlen($full) >= 10 ? substr($full, -10) : $full;
            $editCountryCode = strlen($full) > 10 ? substr($full, 0, -10) : '91';
        }
    }
}

$q = trim((string) ($_GET['q'] ?? ''));
if ($q !== '') {
    $stmt = $db->prepare(
        'SELECT * FROM alluredeal_customer
         WHERE (name LIKE ? OR mobile LIKE ? OR email LIKE ?)
         ORDER BY id DESC LIMIT 200'
    );
    $like = '%' . $q . '%';
    $stmt->execute([$like, $like, $like]);
    $rows = $stmt->fetchAll();
} else {
    $rows = $db->query('SELECT * FROM alluredeal_customer ORDER BY id DESC LIMIT 200')->fetchAll();
}

admin_header('Customers', 'customers');
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="panel">
      <h2><?= $edit ? 'Edit Customer' : 'Add Customer' ?></h2>
      <form method="post" class="vstack gap-2">
        <?= Security::csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
        <div>
          <label class="form-label">Name *</label>
          <input class="form-control" name="name" required maxlength="80" value="<?= e($edit['name'] ?? ($_POST['name'] ?? '')) ?>">
        </div>
        <div>
          <label class="form-label">Mobile *</label>
          <div class="input-group">
            <select name="country_code" class="form-select" style="max-width:7rem">
              <?php
              $cc = (string) ($_POST['country_code'] ?? $editCountryCode);
              foreach (['91','971','966','974','968','965','973','977','880','94','65','60','44','1','61'] as $code):
              ?>
                <option value="<?= $code ?>" <?= $cc === $code ? 'selected' : '' ?>>+<?= $code ?></option>
              <?php endforeach; ?>
            </select>
            <input class="form-control" name="mobile" required maxlength="10" minlength="10" pattern="[0-9]{10}" inputmode="numeric" placeholder="10 digits" value="<?= e((string) ($_POST['mobile'] ?? $editMobileLocal)) ?>">
          </div>
        </div>
        <div>
          <label class="form-label">Email</label>
          <input type="email" class="form-control" name="email" maxlength="100" value="<?= e((string) ($edit['email'] ?? ($_POST['email'] ?? ''))) ?>">
        </div>
        <div>
          <label class="form-label">Gender</label>
          <select name="gender" class="form-select">
            <option value="">Select</option>
            <?php $gSel = (string) ($edit['gender'] ?? ($_POST['gender'] ?? '')); foreach ($genders as $gk => $gl): ?>
              <option value="<?= e($gk) ?>" <?= $gSel === $gk ? 'selected' : '' ?>><?= e($gl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">City</label>
          <select name="city_id" class="form-select">
            <option value="">Select</option>
            <?php $citySel = (string) ($edit['city_id'] ?? ($_POST['city_id'] ?? '')); foreach ($cities as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= (string) $c['id'] === $citySel ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Notes</label>
          <textarea class="form-control" name="notes" rows="2" maxlength="150"><?= e((string) ($edit['notes'] ?? ($_POST['notes'] ?? ''))) ?></textarea>
        </div>
        <label><input type="checkbox" name="is_active" <?= $edit ? (!empty($edit['is_active']) ? 'checked' : '') : 'checked' ?>> Active</label>
        <div class="d-flex gap-2">
          <button class="btn btn-brand" type="submit">Save</button>
          <?php if ($edit): ?>
            <a class="btn btn-outline-secondary" href="<?= e(base_url('admin/customers.php')) ?>">Cancel</a>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="panel mb-3">
      <form method="get" class="row g-2">
        <div class="col-md-8"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Search name / mobile / email" maxlength="50"></div>
        <div class="col-md-4"><button class="btn btn-brand w-100">Search</button></div>
      </form>
    </div>
    <div class="panel">
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Name</th><th>Mobile</th><th>Email</th><th>Orders</th><th>Spent</th><th>Joined</th><th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e($r['name']) ?><?= empty($r['is_active']) ? ' <span class="badge text-bg-secondary">Inactive</span>' : '' ?></td>
              <td><?= e($r['mobile']) ?></td>
              <td><?= e((string) $r['email']) ?></td>
              <td><?= (int) $r['total_orders'] ?></td>
              <td><?= e(money((float) $r['total_spent'])) ?></td>
              <td><small><?= e($r['created_at']) ?></small></td>
              <td class="text-nowrap">
                <a class="btn btn-sm btn-outline-dark" href="?edit=<?= (int) $r['id'] ?><?= $q !== '' ? '&q=' . e(rawurlencode($q)) : '' ?>">Edit</a>
                <button type="button" class="btn btn-sm btn-outline-danger" data-hard-delete-customer="<?= (int) $r['id'] ?>" data-name="<?= e($r['name']) ?>">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr><td colspan="7" class="text-muted">No customers found.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<form id="customerHardDeleteForm" method="post" class="d-none">
  <?= Security::csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="customerHardDeleteId" value="">
</form>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-hard-delete-customer]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const id = btn.getAttribute('data-hard-delete-customer');
      const name = btn.getAttribute('data-name') || 'this customer';
      const go = function () {
        document.getElementById('customerHardDeleteId').value = id;
        document.getElementById('customerHardDeleteForm').submit();
      };
      if (window.Swal) {
        Swal.fire({
          title: 'Permanently delete?',
          html: 'This will <b>hard delete</b> <em>' + name + '</em>. Orders remain, but the customer record is removed.',
          icon: 'warning',
          showCancelButton: true,
          confirmButtonText: 'Yes, delete',
          confirmButtonColor: '#b02a37',
        }).then(function (r) { if (r.isConfirmed) go(); });
      } else if (confirm('Permanently delete ' + name + '?')) {
        go();
      }
    });
  });
});
</script>
<?php admin_footer(); ?>
