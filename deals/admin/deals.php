<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
$db = Database::getInstance();

// Allow blank ends_at (no expiry)
try {
    $db->exec('ALTER TABLE alluredeal_todaydeal MODIFY ends_at DATETIME NULL DEFAULT NULL');
} catch (Throwable $e) {
    // already nullable
}

// Deal locations: all centres or selected cities/branches
foreach ([
    'ALTER TABLE alluredeal_todaydeal ADD COLUMN all_locations TINYINT(1) NOT NULL DEFAULT 1 AFTER display_order',
    'ALTER TABLE alluredeal_todaydeal ADD COLUMN city_ids JSON NULL AFTER all_locations',
    'ALTER TABLE alluredeal_todaydeal ADD COLUMN branch_ids JSON NULL AFTER city_ids',
] as $sql) {
    try {
        $db->exec($sql);
    } catch (Throwable $e) {
        // already exists
    }
}

$products = $db->query(
    'SELECT id, name, offer_price, original_price, discount_percent, duration
     FROM alluredeal_product WHERE is_deleted=0 AND is_active=1 ORDER BY name'
)->fetchAll();

$cities = $db->query(
    'SELECT id, name FROM alluredeal_city WHERE is_deleted=0 AND is_active=1 ORDER BY display_order, name'
)->fetchAll();
$cityMap = [];
foreach ($cities as $c) {
    $cityMap[(int) $c['id']] = $c['name'];
}

$branches = $db->query(
    'SELECT b.id, b.city_id, b.name, c.name AS city_name
     FROM alluredeal_branch b
     JOIN alluredeal_city c ON c.id = b.city_id
     WHERE b.is_deleted=0 AND b.is_active=1 AND c.is_deleted=0 AND c.is_active=1
     ORDER BY c.display_order, b.display_order, b.name'
)->fetchAll();
$branchMap = [];
foreach ($branches as $b) {
    $branchMap[(int) $b['id']] = ($b['city_name'] ?? '') . ' · ' . $b['name'];
}

function toDatetimeLocal(?string $value): string
{
    if (!$value) {
        return '';
    }
    $ts = strtotime($value);
    return $ts ? date('Y-m-d\TH:i', $ts) : '';
}

function decodeDealIds(mixed $raw): array
{
    if (is_array($raw)) {
        return array_values(array_map('intval', $raw));
    }
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? array_values(array_map('intval', $decoded)) : [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::verifyCsrf()) {
    $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    $endsAt = trim((string) ($_POST['ends_at'] ?? ''));
    $endsAt = $endsAt !== '' ? str_replace('T', ' ', $endsAt) . (strlen($endsAt) === 16 ? ':00' : '') : null;
    $startsAt = trim((string) ($_POST['starts_at'] ?? ''));
    $startsAt = $startsAt !== '' ? str_replace('T', ' ', $startsAt) . (strlen($startsAt) === 16 ? ':00' : '') : date('Y-m-d H:i:s');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $productId = (int) $_POST['product_id'];
    $dealPrice = (float) $_POST['deal_price'];
    $discount = (float) $_POST['discount_percent'];

    $allLocations = (($_POST['location_scope'] ?? 'all') !== 'selected') ? 1 : 0;
    $selectedCities = [];
    $selectedBranches = [];
    if (!$allLocations) {
        $selectedBranches = array_values(array_unique(array_map('intval', (array) ($_POST['branch_ids'] ?? []))));
        $selectedBranches = array_values(array_filter($selectedBranches, static fn ($v) => $v > 0));
        $selectedCities = array_values(array_unique(array_map('intval', (array) ($_POST['city_ids'] ?? []))));
        $selectedCities = array_values(array_filter($selectedCities, static fn ($v) => $v > 0));

        // Derive cities from selected branches
        if ($selectedBranches) {
            $placeholders = implode(',', array_fill(0, count($selectedBranches), '?'));
            $st = $db->prepare("SELECT DISTINCT city_id FROM alluredeal_branch WHERE id IN ({$placeholders})");
            $st->execute($selectedBranches);
            $fromBranches = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            $selectedCities = array_values(array_unique(array_merge($selectedCities, $fromBranches)));
        }

        if (!$selectedCities && !$selectedBranches) {
            $allLocations = 1;
        }
    }
    $cityIdsJson = $allLocations || !$selectedCities ? null : json_encode($selectedCities);
    $branchIdsJson = $allLocations || !$selectedBranches ? null : json_encode($selectedBranches);

    $params = [
        $productId,
        $_POST['badge_text'] ?: "Today's Deal",
        $discount,
        $dealPrice,
        $startsAt,
        $endsAt,
        isset($_POST['show_countdown']) ? 1 : 0,
        (int) ($_POST['display_order'] ?? 0),
        $allLocations,
        $cityIdsJson,
        $branchIdsJson,
        $isActive,
        Auth::id(),
    ];

    $oldProductId = null;
    if ($id) {
        $old = $db->prepare('SELECT product_id FROM alluredeal_todaydeal WHERE id = ? LIMIT 1');
        $old->execute([$id]);
        $oldProductId = (int) ($old->fetchColumn() ?: 0);

        $db->prepare(
            'UPDATE alluredeal_todaydeal
             SET product_id=?, badge_text=?, discount_percent=?, deal_price=?, starts_at=?, ends_at=?,
                 show_countdown=?, display_order=?, all_locations=?, city_ids=?, branch_ids=?, is_active=?, updated_by=?, is_deleted=0
             WHERE id=?'
        )->execute([...$params, $id]);
    } else {
        $db->prepare(
            'INSERT INTO alluredeal_todaydeal
             (product_id, badge_text, discount_percent, deal_price, starts_at, ends_at, show_countdown, display_order, all_locations, city_ids, branch_ids, is_active, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute($params);
    }

    $original = $discount > 0 && $discount < 100
        ? round($dealPrice / (1 - $discount / 100), 2)
        : $dealPrice;

    if ($isActive) {
        $db->prepare(
            'UPDATE alluredeal_product
             SET is_today_deal=1, offer_price=?, original_price=?, discount_percent=?
             WHERE id=?'
        )->execute([$dealPrice, $original, $discount, $productId]);
    } else {
        $db->prepare('UPDATE alluredeal_product SET is_today_deal=0 WHERE id=?')->execute([$productId]);
    }

    if ($oldProductId && $oldProductId !== $productId) {
        $db->prepare('UPDATE alluredeal_product SET is_today_deal=0 WHERE id=?')->execute([$oldProductId]);
    }

    $flash = $id ? 'updated' : 'created';
    redirect(base_url('admin/deals.php?saved=' . $flash));
}

$edit = null;
$editCityIds = [];
$editBranchIds = [];
if (!empty($_GET['edit'])) {
    $st = $db->prepare(
        'SELECT d.*, p.name AS product_name
         FROM alluredeal_todaydeal d
         LEFT JOIN alluredeal_product p ON p.id = d.product_id
         WHERE d.id = ? AND d.is_deleted = 0 LIMIT 1'
    );
    $st->execute([(int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
    if ($edit) {
        $editCityIds = decodeDealIds($edit['city_ids'] ?? null);
        $editBranchIds = decodeDealIds($edit['branch_ids'] ?? null);
    }
}

$rows = $db->query(
    'SELECT d.*, p.name AS product_name
     FROM alluredeal_todaydeal d
     JOIN alluredeal_product p ON p.id = d.product_id
     WHERE d.is_deleted = 0
     ORDER BY p.name ASC, d.is_active DESC, d.id DESC'
)->fetchAll();

$allLocationsChecked = !$edit || !empty($edit['all_locations']) || ($editCityIds === [] && $editBranchIds === []);
$selectedLocationsChecked = $edit && empty($edit['all_locations']) && ($editCityIds !== [] || $editBranchIds !== []);

admin_header("Today's Deals", 'deals');
?>
<div class="row g-3">
  <div class="col-lg-4"><div class="panel">
    <h2><?= $edit ? 'Edit Deal' : 'Add Deal' ?></h2>
    <form method="post" class="vstack gap-2" id="dealForm">
      <?= Security::csrfField() ?>
      <input type="hidden" name="id" value="<?= e((string) ($edit['id'] ?? '')) ?>">

      <div>
        <label class="form-label" for="dealProduct">Product</label>
        <select name="product_id" id="dealProduct" class="form-select" required>
          <?php if (!$edit): ?>
            <option value="" selected disabled data-original="0">Select product…</option>
          <?php endif; ?>
          <?php foreach ($products as $p):
              $mrp = (float) $p['original_price'] > 0 ? (float) $p['original_price'] : (float) $p['offer_price'];
              if ((float) $p['offer_price'] > $mrp) {
                  $mrp = (float) $p['offer_price'];
              }
          ?>
            <option
              value="<?= (int) $p['id'] ?>"
              data-original="<?= e((string) $mrp) ?>"
              <?= ($edit && (int) ($edit['product_id'] ?? 0) === (int) $p['id']) ? 'selected' : '' ?>
            >
              <?= e($p['name']) ?> (<?= e(money((float) $p['offer_price'])) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="form-label" for="dealBadge">Badge text</label>
        <input class="form-control" id="dealBadge" name="badge_text" value="<?= e((string) ($edit['badge_text'] ?? '')) ?>" placeholder="Today's Deal">
      </div>

      <div>
        <label class="form-label">Original price</label>
        <input type="text" class="form-control" id="dealOriginalDisplay" readonly tabindex="-1" value="" placeholder="—">
        <input type="hidden" id="dealOriginal" value="0">
        <small class="text-muted">Read-only · incl. GST · used to calculate deal price from discount %</small>
      </div>

      <div>
        <label class="form-label" for="dealDiscount">Discount percentage (%)</label>
        <input class="form-control" id="dealDiscount" type="number" step="0.01" min="0" max="100" name="discount_percent" required value="<?= e((string) ($edit['discount_percent'] ?? '')) ?>" placeholder="e.g. 22">
      </div>

      <div>
        <label class="form-label" for="dealPrice">Deal price (₹) *</label>
        <input class="form-control" id="dealPrice" type="number" step="0.01" min="0" name="deal_price" required value="<?= e((string) ($edit['deal_price'] ?? '')) ?>" placeholder="0.00 *">
        <small class="text-muted">Incl. GST · changing discount updates deal price, and vice versa</small>
      </div>

      <div>
        <span class="form-label d-block">Available locations</span>
        <label class="d-block mb-1">
          <input type="radio" name="location_scope" value="all" id="locAll" <?= $allLocationsChecked ? 'checked' : '' ?>>
          All locations
        </label>
        <label class="d-block mb-2">
          <input type="radio" name="location_scope" value="selected" id="locSelected" <?= $selectedLocationsChecked ? 'checked' : '' ?>>
          Selected locations / branches
        </label>
        <div id="dealCitySelectWrap" class="<?= $selectedLocationsChecked ? '' : 'd-none' ?>">
          <label class="form-label" for="dealCities">Cities (optional filter)</label>
          <select name="city_ids[]" id="dealCities" class="form-select" multiple size="5">
            <?php foreach ($cities as $c): ?>
              <option value="<?= (int) $c['id'] ?>" <?= in_array((int) $c['id'], $editCityIds, true) ? 'selected' : '' ?>>
                <?= e($c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted d-block mb-2">Hold Ctrl/Cmd to multi-select. Used to filter branches below.</small>

          <label class="form-label" for="dealBranches">Branches</label>
          <select name="branch_ids[]" id="dealBranches" class="form-select" multiple size="8">
            <?php foreach ($branches as $b): ?>
              <option
                value="<?= (int) $b['id'] ?>"
                data-city-id="<?= (int) $b['city_id'] ?>"
                <?= in_array((int) $b['id'], $editBranchIds, true) ? 'selected' : '' ?>
              >
                <?= e(($b['city_name'] ?? '') . ' · ' . $b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted">Select specific branch IDs. If none selected, all branches in chosen cities apply.</small>
        </div>
      </div>

      <div>
        <label class="form-label" for="dealStarts">Starts</label>
        <input class="form-control" id="dealStarts" type="datetime-local" name="starts_at" value="<?= e(toDatetimeLocal($edit ? (string) ($edit['starts_at'] ?? date('Y-m-d H:i:s')) : null)) ?>">
      </div>

      <div>
        <label class="form-label" for="dealEnds">Ends</label>
        <input class="form-control" id="dealEnds" type="datetime-local" name="ends_at" value="<?= e(toDatetimeLocal($edit['ends_at'] ?? null)) ?>">
        <small class="text-muted d-block mt-1">Leave blank for <strong>no expiry</strong>.</small>
      </div>

      <label class="d-block"><input type="checkbox" name="show_countdown" <?= !empty($edit['show_countdown']) ? 'checked' : '' ?>> Show countdown (only if end date is set)</label>
      <label class="d-block"><input type="checkbox" name="is_active" <?= $edit ? (!empty($edit['is_active']) ? 'checked' : '') : 'checked' ?>> Active</label>

      <div>
        <label class="form-label" for="dealOrder">Display order</label>
        <input class="form-control" id="dealOrder" type="number" name="display_order" value="<?= e((string) ($edit['display_order'] ?? '')) ?>" placeholder="0">
      </div>

      <div class="d-flex gap-2">
        <button class="btn btn-brand" type="submit"><?= $edit ? 'Update Deal' : 'Save Deal' ?></button>
        <?php if ($edit): ?><a class="btn btn-outline-secondary" href="<?= e(base_url('admin/deals.php')) ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div></div>
  <div class="col-lg-8"><div class="panel">
    <h2>Deals</h2>
    <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr><th>Product</th><th>Price</th><th>Locations</th><th>Window</th><th>Badge</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $cids = decodeDealIds($r['city_ids'] ?? null);
            $bids = decodeDealIds($r['branch_ids'] ?? null);
            if (!empty($r['all_locations']) || (!$cids && !$bids)) {
                $locLabel = 'All locations';
            } elseif ($bids) {
                $locLabel = implode(', ', array_map(static fn ($id) => $branchMap[$id] ?? ('Branch #' . $id), $bids));
            } else {
                $locLabel = 'Cities: ' . implode(', ', array_map(static fn ($id) => $cityMap[$id] ?? ('#' . $id), $cids));
            }
        ?>
          <tr class="<?= empty($r['is_active']) ? 'table-secondary' : '' ?>">
            <td><?= e($r['product_name']) ?></td>
            <td><?= e(money((float) $r['deal_price'])) ?> (<?= e((string) $r['discount_percent']) ?>%)</td>
            <td><small><?= e($locLabel) ?></small></td>
            <td><small>
              <?= e((string) $r['starts_at']) ?><br>
              <?= $r['ends_at'] ? e((string) $r['ends_at']) : '<em class="text-muted">No expiry</em>' ?>
            </small></td>
            <td><?= e($r['badge_text']) ?></td>
            <td><?= !empty($r['is_active']) ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>' ?></td>
            <td class="text-nowrap">
              <a class="btn btn-sm btn-outline-dark" href="?edit=<?= (int) $r['id'] ?>">Edit</a>
              <button
                class="btn btn-sm btn-outline-danger"
                type="button"
                data-delete-deal="<?= (int) $r['id'] ?>"
                data-product-id="<?= (int) $r['product_id'] ?>"
                <?= empty($r['is_active']) ? 'disabled' : '' ?>
              >Delete</button>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-muted">No deals found.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div></div>
</div>
<script>
(function () {
  const all = document.getElementById('locAll');
  const selected = document.getElementById('locSelected');
  const wrap = document.getElementById('dealCitySelectWrap');
  const cities = document.getElementById('dealCities');
  const branches = document.getElementById('dealBranches');

  function syncScope() {
    if (!wrap) return;
    wrap.classList.toggle('d-none', !(selected && selected.checked));
  }

  function filterBranchesByCities() {
    if (!branches || !cities) return;
    const selectedCities = Array.from(cities.selectedOptions).map((o) => o.value);
    Array.from(branches.options).forEach((opt) => {
      const cityId = String(opt.getAttribute('data-city-id') || '');
      const show = !selectedCities.length || selectedCities.includes(cityId);
      opt.hidden = !show;
      if (!show) opt.selected = false;
    });
  }

  if (all) all.addEventListener('change', syncScope);
  if (selected) selected.addEventListener('change', syncScope);
  if (cities) cities.addEventListener('change', filterBranchesByCities);
  syncScope();
  filterBranchesByCities();
})();
</script>
<?php admin_footer(); ?>
