<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
$db = Database::getInstance();

function sliderDatetimeLocal(?string $value): string
{
    if (!$value) {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

function sliderToSqlDatetime(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    return str_replace('T', ' ', $value) . (strlen($value) === 16 ? ':00' : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::verifyCsrf()) {
    $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    $desktop = trim((string) ($_POST['desktop_image'] ?? ''));
    $mobile = trim((string) ($_POST['mobile_image'] ?? ''));

    if ($id) {
        $cur = $db->prepare('SELECT desktop_image, mobile_image FROM alluredeal_slider WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $cur->execute([$id]);
        $existing = $cur->fetch() ?: [];
        if ($desktop === '') {
            $desktop = (string) ($existing['desktop_image'] ?? '');
        }
        if ($mobile === '') {
            $mobile = (string) ($existing['mobile_image'] ?? $desktop);
        }
    }

    if (!empty($_FILES['desktop_file']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['desktop_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, (array) config('app.allowed_images'), true)) {
            $name = 'slider-d-' . time() . '.' . $ext;
            move_uploaded_file($_FILES['desktop_file']['tmp_name'], upload_path('slider') . '/' . $name);
            $desktop = 'uploads/slider/' . $name;
        }
    }
    if (!empty($_FILES['mobile_file']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['mobile_file']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, (array) config('app.allowed_images'), true)) {
            $name = 'slider-m-' . time() . '.' . $ext;
            move_uploaded_file($_FILES['mobile_file']['tmp_name'], upload_path('slider') . '/' . $name);
            $mobile = 'uploads/slider/' . $name;
        }
    }

    if ($desktop === '') {
        $desktop = 'assets/img/slider-1.jpg';
    }
    if ($mobile === '') {
        $mobile = $desktop;
    }

    $params = [
        trim((string) ($_POST['heading'] ?? '')),
        trim((string) ($_POST['sub_heading'] ?? '')),
        trim((string) ($_POST['cta_text'] ?? '')),
        trim((string) ($_POST['cta_link'] ?? '')),
        $desktop,
        $mobile,
        (int) ($_POST['priority'] ?? 0),
        sliderToSqlDatetime($_POST['starts_at'] ?? null),
        sliderToSqlDatetime($_POST['ends_at'] ?? null),
        isset($_POST['is_active']) ? 1 : 0,
    ];

    if ($id) {
        $db->prepare(
            'UPDATE alluredeal_slider
             SET heading=?, sub_heading=?, cta_text=?, cta_link=?, desktop_image=?, mobile_image=?,
                 priority=?, starts_at=?, ends_at=?, is_active=?, updated_by=?, is_deleted=0
             WHERE id=?'
        )->execute([...$params, Auth::id(), $id]);
    } else {
        $db->prepare(
            'INSERT INTO alluredeal_slider
             (heading, sub_heading, cta_text, cta_link, desktop_image, mobile_image, priority, starts_at, ends_at, is_active, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([...$params, Auth::id()]);
    }

    redirect(base_url('admin/slider.php?saved=1'));
}

$edit = null;
if (!empty($_GET['edit'])) {
    $st = $db->prepare('SELECT * FROM alluredeal_slider WHERE id = ? AND is_deleted = 0 LIMIT 1');
    $st->execute([(int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

$rows = $db->query('SELECT * FROM alluredeal_slider WHERE is_deleted=0 ORDER BY priority, id DESC')->fetchAll();
admin_header('Hero Slider', 'slider');
?>
<?php if (!empty($_GET['saved'])): ?><div class="alert alert-success">Slide saved.</div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-4"><div class="panel">
    <h2><?= $edit ? 'Edit Slide' : 'Add Slide' ?></h2>
    <form method="post" enctype="multipart/form-data" class="vstack gap-2">
      <?= Security::csrfField() ?>
      <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">
      <input type="hidden" name="desktop_image" value="<?= e((string) ($edit['desktop_image'] ?? '')) ?>">
      <input type="hidden" name="mobile_image" value="<?= e((string) ($edit['mobile_image'] ?? '')) ?>">

      <input class="form-control" name="heading" placeholder="Heading" required value="<?= e((string) ($edit['heading'] ?? '')) ?>">
      <input class="form-control" name="sub_heading" placeholder="Sub heading" value="<?= e((string) ($edit['sub_heading'] ?? '')) ?>">
      <input class="form-control" name="cta_text" placeholder="CTA text" value="<?= e((string) ($edit['cta_text'] ?? 'Shop Deals')) ?>">
      <input class="form-control" name="cta_link" placeholder="Link" value="<?= e((string) ($edit['cta_link'] ?? '#deals')) ?>">

      <label>Desktop Image
        <input type="file" name="desktop_file" class="form-control" accept="image/*">
        <small class="text-muted d-block mt-1">Required resolution: <strong>1920 × 768</strong> px (JPG/PNG/WebP). Max 5 MB.</small>
        <?php if (!empty($edit['desktop_image'])): ?>
          <img src="<?= e(asset_url(ltrim((string) $edit['desktop_image'], '/'))) ?>" alt="" class="img-fluid rounded mt-2 border" style="max-height:120px">
          <small class="text-muted d-block">Leave empty to keep current image.</small>
        <?php endif; ?>
      </label>

      <label>Mobile Image
        <input type="file" name="mobile_file" class="form-control" accept="image/*">
        <small class="text-muted d-block mt-1">Required resolution: <strong>768 × 1024</strong> px (JPG/PNG/WebP). Max 5 MB.</small>
        <?php if (!empty($edit['mobile_image'])): ?>
          <img src="<?= e(asset_url(ltrim((string) $edit['mobile_image'], '/'))) ?>" alt="" class="img-fluid rounded mt-2 border" style="max-height:120px">
          <small class="text-muted d-block">Leave empty to keep current image.</small>
        <?php endif; ?>
      </label>

      <input class="form-control" type="number" name="priority" value="<?= e((string) ($edit['priority'] ?? 1)) ?>" placeholder="Priority">
      <label>Schedule start<input class="form-control" type="datetime-local" name="starts_at" value="<?= e(sliderDatetimeLocal($edit['starts_at'] ?? null)) ?>"></label>
      <label>Schedule end<input class="form-control" type="datetime-local" name="ends_at" value="<?= e(sliderDatetimeLocal($edit['ends_at'] ?? null)) ?>"></label>
      <label><input type="checkbox" name="is_active" <?= $edit ? (!empty($edit['is_active']) ? 'checked' : '') : 'checked' ?>> Enable</label>

      <div class="d-flex gap-2">
        <button class="btn btn-brand" type="submit"><?= $edit ? 'Update Slide' : 'Save Slide' ?></button>
        <?php if ($edit): ?><a class="btn btn-outline-secondary" href="<?= e(base_url('admin/slider.php')) ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div></div>
  <div class="col-lg-8"><div class="panel">
    <h2>Slides</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead><tr><th>Preview</th><th>Heading</th><th>Priority</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr class="<?= empty($r['is_active']) ? 'table-secondary' : '' ?>">
            <td style="width:96px">
              <?php if (!empty($r['desktop_image'])): ?>
                <img src="<?= e(asset_url(ltrim((string) $r['desktop_image'], '/'))) ?>" alt="" class="rounded border" style="width:80px;height:40px;object-fit:cover">
              <?php endif; ?>
            </td>
            <td><?= e($r['heading']) ?><br><small class="text-muted"><?= e($r['sub_heading']) ?></small></td>
            <td><?= (int) $r['priority'] ?></td>
            <td><?= !empty($r['is_active']) ? '<span class="badge text-bg-success">On</span>' : '<span class="badge text-bg-secondary">Off</span>' ?></td>
            <td class="text-nowrap">
              <a class="btn btn-sm btn-outline-dark" href="?edit=<?= (int) $r['id'] ?>">Edit</a>
              <button class="btn btn-sm btn-outline-danger" type="button" data-delete="<?= (int) $r['id'] ?>" data-type="slider">Delete</button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div></div>
</div>
<?php admin_footer(); ?>
