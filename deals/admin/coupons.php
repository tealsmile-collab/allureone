<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
$db = Database::getInstance();

function couponDatetimeLocal(?string $value): string
{
    if (!$value) {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

function couponToSqlDatetime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    return str_replace('T', ' ', $value) . (strlen($value) === 16 ? ':00' : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::verifyCsrf()) {
    $formType = (string) ($_POST['form_type'] ?? 'marketing');
    if ($formType === 'onetime') {
        $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
        $params = [
            strtoupper(trim((string) $_POST['code'])),
            $_POST['discount_type'],
            (float) $_POST['discount_value'],
            $_POST['max_discount'] !== '' ? (float) $_POST['max_discount'] : null,
            (float) ($_POST['min_order_amount'] ?? 0),
            couponToSqlDatetime($_POST['expires_at'] ?? null),
            isset($_POST['is_active']) ? 1 : 0,
            Auth::id(),
        ];
        if ($id) {
            $db->prepare(
                'UPDATE alluredeal_onetime_coupon
                 SET code=?, discount_type=?, discount_value=?, max_discount=?, min_order_amount=?, expires_at=?, is_active=?, updated_by=?, is_deleted=0
                 WHERE id=?'
            )->execute([...$params, $id]);
        } else {
            $db->prepare(
                'INSERT INTO alluredeal_onetime_coupon
                 (code, discount_type, discount_value, max_discount, min_order_amount, expires_at, is_active, created_by)
                 VALUES (?,?,?,?,?,?,?,?)'
            )->execute($params);
        }
        redirect(base_url('admin/coupons.php?saved=onetime'));
    }

    $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    $params = [
        strtoupper(trim((string) $_POST['code'])),
        $_POST['title'] ?? '',
        $_POST['discount_type'],
        (float) $_POST['discount_value'],
        (float) ($_POST['min_order_amount'] ?? 0),
        $_POST['max_discount'] !== '' ? (float) $_POST['max_discount'] : null,
        $_POST['usage_limit'] !== '' ? (int) $_POST['usage_limit'] : null,
        couponToSqlDatetime($_POST['starts_at'] ?? null),
        couponToSqlDatetime($_POST['expires_at'] ?? null),
        isset($_POST['is_active']) ? 1 : 0,
        Auth::id(),
    ];
    if ($id) {
        $db->prepare(
            'UPDATE alluredeal_coupon
             SET code=?, title=?, discount_type=?, discount_value=?, min_order_amount=?, max_discount=?, usage_limit=?, starts_at=?, expires_at=?, is_active=?, updated_by=?, is_deleted=0
             WHERE id=?'
        )->execute([...$params, $id]);
    } else {
        $db->prepare(
            'INSERT INTO alluredeal_coupon
             (code, title, discount_type, discount_value, min_order_amount, max_discount, usage_limit, starts_at, expires_at, is_active, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute($params);
    }
    redirect(base_url('admin/coupons.php?saved=marketing'));
}

$edit = null;
$editOt = null;
if (!empty($_GET['edit'])) {
    $st = $db->prepare('SELECT * FROM alluredeal_coupon WHERE id=? AND is_deleted=0 LIMIT 1');
    $st->execute([(int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
if (!empty($_GET['edit_ot'])) {
    $st = $db->prepare('SELECT * FROM alluredeal_onetime_coupon WHERE id=? AND is_deleted=0 LIMIT 1');
    $st->execute([(int) $_GET['edit_ot']]);
    $editOt = $st->fetch() ?: null;
}

$coupons = $db->query('SELECT * FROM alluredeal_coupon WHERE is_deleted=0 ORDER BY id DESC')->fetchAll();
$onetime = $db->query('SELECT * FROM alluredeal_onetime_coupon WHERE is_deleted=0 ORDER BY id DESC LIMIT 100')->fetchAll();
admin_header('Coupons', 'coupons');
?>
<?php if (!empty($_GET['saved'])): ?><div class="alert alert-success">Coupon saved.</div><?php endif; ?>
<div class="row g-3">
  <div class="col-md-6"><div class="panel">
    <h2><?= $edit ? 'Edit' : 'Add' ?> Marketing Coupon</h2>
    <form method="post" class="row g-2">
      <?= Security::csrfField() ?>
      <input type="hidden" name="form_type" value="marketing">
      <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
      <div class="col-6"><input class="form-control" name="code" placeholder="CODE *" required maxlength="20" value="<?= e((string) ($edit['code'] ?? '')) ?>"></div>
      <div class="col-6"><input class="form-control" name="title" placeholder="Title" maxlength="30" value="<?= e((string) ($edit['title'] ?? '')) ?>"></div>
      <div class="col-6">
        <select class="form-select" name="discount_type" required>
          <option value="percent" <?= (($edit['discount_type'] ?? '') === 'percent') ? 'selected' : '' ?>>Discount type: Percent (%) *</option>
          <option value="flat" <?= (($edit['discount_type'] ?? '') === 'flat') ? 'selected' : '' ?>>Discount type: Flat (₹) *</option>
        </select>
      </div>
      <div class="col-6"><input class="form-control" type="number" step="0.01" name="discount_value" placeholder="Value *" required value="<?= e((string) ($edit['discount_value'] ?? '')) ?>"></div>
      <div class="col-6"><input class="form-control" type="number" step="0.01" name="min_order_amount" value="<?= e((string) ($edit['min_order_amount'] ?? '0')) ?>"></div>
      <div class="col-6"><input class="form-control" type="number" step="0.01" name="max_discount" placeholder="Max discount" value="<?= e((string) ($edit['max_discount'] ?? '')) ?>"></div>
      <div class="col-6"><input class="form-control" type="number" name="usage_limit" placeholder="Usage limit" value="<?= e((string) ($edit['usage_limit'] ?? '')) ?>"></div>
      <div class="col-6"><input class="form-control" type="datetime-local" name="starts_at" value="<?= e(couponDatetimeLocal($edit['starts_at'] ?? null)) ?>"></div>
      <div class="col-6"><input class="form-control" type="datetime-local" name="expires_at" value="<?= e(couponDatetimeLocal($edit['expires_at'] ?? null)) ?>"></div>
      <div class="col-12"><label><input type="checkbox" name="is_active" <?= $edit ? (!empty($edit['is_active']) ? 'checked' : '') : 'checked' ?>> Enable</label></div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-brand" type="submit"><?= $edit ? 'Update Coupon' : 'Save Coupon' ?></button>
        <?php if ($edit): ?><a class="btn btn-outline-secondary" href="<?= e(base_url('admin/coupons.php')) ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div></div>
  <div class="col-md-6"><div class="panel">
    <h2><?= $editOt ? 'Edit' : 'Add' ?> One-Time Coupon</h2>
    <p class="text-muted small mb-2">Valid for a single use only. After one successful order, it cannot be used again.</p>
    <form method="post" class="row g-2">
      <?= Security::csrfField() ?>
      <input type="hidden" name="form_type" value="onetime">
      <input type="hidden" name="id" value="<?= e((string) ($editOt['id'] ?? '')) ?>">
      <div class="col-6">
        <input class="form-control" name="code" placeholder="Coupon code * (customer enters at checkout)" required maxlength="20" value="<?= e((string) ($editOt['code'] ?? '')) ?>">
      </div>
      <div class="col-6">
        <select class="form-select" name="discount_type" title="Discount type *" required>
          <option value="percent" <?= (($editOt['discount_type'] ?? '') === 'percent') ? 'selected' : '' ?>>Discount type: Percent (%) *</option>
          <option value="flat" <?= (($editOt['discount_type'] ?? '') === 'flat') ? 'selected' : '' ?>>Discount type: Flat (₹) *</option>
        </select>
      </div>
      <div class="col-6">
        <input class="form-control" type="number" step="0.01" name="discount_value" placeholder="Discount value * (% or ₹ amount)" required value="<?= e((string) ($editOt['discount_value'] ?? '')) ?>">
      </div>
      <div class="col-6">
        <input class="form-control" type="number" step="0.01" name="max_discount" placeholder="Max discount ₹ (optional, for % coupons)" value="<?= e((string) ($editOt['max_discount'] ?? '')) ?>">
      </div>
      <div class="col-6">
        <input class="form-control" type="number" step="0.01" name="min_order_amount" placeholder="Minimum order ₹ (required cart total)" value="<?= e((string) ($editOt['min_order_amount'] ?? '')) ?>">
      </div>
      <div class="col-6">
        <input class="form-control" type="datetime-local" name="expires_at" placeholder="Expires at (optional)" title="Expires at (optional)" value="<?= e(couponDatetimeLocal($editOt['expires_at'] ?? null)) ?>">
      </div>
      <div class="col-12">
        <label><input type="checkbox" name="is_active" <?= $editOt ? (!empty($editOt['is_active']) ? 'checked' : '') : 'checked' ?>> Enable coupon (uncheck to disable without deleting)</label>
      </div>
      <div class="col-12 d-flex gap-2">
        <button class="btn btn-brand" type="submit"><?= $editOt ? 'Update One-Time' : 'Create One-Time' ?></button>
        <?php if ($editOt): ?><a class="btn btn-outline-secondary" href="<?= e(base_url('admin/coupons.php')) ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div></div>
  <div class="col-12"><div class="panel">
    <h2>Coupons</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Used</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($coupons as $c): ?>
          <tr class="<?= empty($c['is_active']) ? 'table-secondary' : '' ?>">
            <td><?= e($c['code']) ?><br><small class="text-muted"><?= e((string) ($c['title'] ?? '')) ?></small></td>
            <td><?= e($c['discount_type']) ?></td>
            <td><?= e((string) $c['discount_value']) ?></td>
            <td><?= (int) $c['used_count'] ?>/<?= e((string) ($c['usage_limit'] ?? '∞')) ?></td>
            <td><?= !empty($c['is_active']) ? 'On' : 'Off' ?></td>
            <td class="text-nowrap">
              <a class="btn btn-sm btn-outline-dark" href="?edit=<?= (int) $c['id'] ?>">Edit</a>
              <button class="btn btn-sm btn-outline-danger" type="button" data-delete="<?= (int) $c['id'] ?>" data-type="coupon">Delete</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$coupons): ?>
          <tr><td colspan="6" class="text-muted">No marketing coupons.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
    <h2 class="mt-4">One-Time Coupons</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Code</th><th>Value</th><th>Used</th><th>Expires</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($onetime as $c): ?>
          <tr class="<?= empty($c['is_active']) || !empty($c['is_used']) ? 'table-secondary' : '' ?>">
            <td><?= e($c['code']) ?></td>
            <td><?= e($c['discount_type']) ?> <?= e((string) $c['discount_value']) ?></td>
            <td><?= !empty($c['is_used']) ? 'Yes' : 'No' ?></td>
            <td><?= $c['expires_at'] ? e((string) $c['expires_at']) : '—' ?></td>
            <td><?= !empty($c['is_active']) ? 'On' : 'Off' ?></td>
            <td class="text-nowrap">
              <a class="btn btn-sm btn-outline-dark" href="?edit_ot=<?= (int) $c['id'] ?>">Edit</a>
              <button class="btn btn-sm btn-outline-danger" type="button" data-delete="<?= (int) $c['id'] ?>" data-type="onetime_coupon">Delete</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$onetime): ?>
          <tr><td colspan="6" class="text-muted">No one-time coupons.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div></div>
</div>
<?php admin_footer(); ?>
