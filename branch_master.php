<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_superadmin();

$pdo = db();
$message = '';
$messageType = '';

$flash = [
    'branch_created' => ['Branch created.', 'ok'],
    'branch_updated' => ['Branch updated.', 'ok'],
];
$mk = isset($_GET['msg']) ? (string) $_GET['msg'] : '';
if (isset($flash[$mk])) {
    [$message, $messageType] = $flash[$mk];
}

/**
 * Digits only, max 15. Empty allowed.
 */
function branch_normalize_mobile(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) > 15) {
        $digits = substr($digits, 0, 15);
    }

    return $digits;
}

/**
 * @return array{ok:bool,value:?float}
 */
function branch_parse_monthly_target(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['ok' => true, 'value' => null];
    }
    if (preg_match('/^\d{1,16}(\.\d{1,2})?$/', $raw) !== 1) {
        return ['ok' => false, 'value' => null];
    }

    return ['ok' => true, 'value' => (float) $raw];
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $es = $pdo->prepare(
        'SELECT id, business_name, locality, vendor_id, mobile_number, isActive, isDingg, enableSaleRecord, MonthlyTarget
         FROM allureone_branch WHERE id = :id LIMIT 1'
    );
    $es->execute(['id' => $editId]);
    $editRow = $es->fetch();
    if ($editRow === false) {
        $editId = 0;
        $message = 'Branch not found.';
        $messageType = 'error';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $message = 'Invalid session. Please refresh and try again.';
        $messageType = 'error';
    } else {
        $action = isset($_POST['_action']) ? (string) $_POST['_action'] : 'create';
        $id = isset($_POST['branch_id']) ? (int) $_POST['branch_id'] : 0;
        $name = isset($_POST['business_name']) ? trim((string) $_POST['business_name']) : '';
        $locality = isset($_POST['locality']) ? trim((string) $_POST['locality']) : '';
        $vendorId = isset($_POST['vendor_id']) ? (int) $_POST['vendor_id'] : 0;
        $mobileRaw = isset($_POST['mobile_number']) ? trim((string) $_POST['mobile_number']) : '';
        $mobileNumber = branch_normalize_mobile($mobileRaw);
        $lenName = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);

        if ($id < 1) {
            $message = 'Branch ID is required and must be greater than 0.';
            $messageType = 'error';
        } elseif ($name === '') {
            $message = 'Business name is required.';
            $messageType = 'error';
        } elseif ($lenName > 255) {
            $message = 'Business name is too long.';
            $messageType = 'error';
        } elseif ($vendorId < 1) {
            $message = 'Vendor ID is required and must be greater than 0.';
            $messageType = 'error';
        } elseif ($mobileRaw !== '' && !preg_match('/^\d{1,15}$/', $mobileRaw)) {
            $message = 'Mobile number must be digits only (max 15 characters).';
            $messageType = 'error';
        } elseif ($action === 'update') {
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            $isDingg = isset($_POST['is_dingg']) ? 1 : 0;
            $enableSaleRecord = isset($_POST['enable_sale_record']) ? 1 : 0;
            $monthlyTarget = null;
            if ($isDingg !== 1) {
                $parsedTarget = branch_parse_monthly_target((string) ($_POST['monthly_target'] ?? ''));
                if (!$parsedTarget['ok']) {
                    $message = 'Monthly target must be a number (max 2 decimal places).';
                    $messageType = 'error';
                } else {
                    $monthlyTarget = $parsedTarget['value'];
                }
            }
            if ($message !== '') {
                // keep form error
            } elseif ($id < 1) {
                $message = 'Invalid branch.';
                $messageType = 'error';
            } else {
                $chk = $pdo->prepare('SELECT COUNT(*) FROM allureone_branch WHERE id = :id');
                $chk->execute(['id' => $id]);
                if ((int) $chk->fetchColumn() === 0) {
                    $message = 'Branch not found.';
                    $messageType = 'error';
                } else {
                    if ($isDingg === 1) {
                        $upd = $pdo->prepare(
                            'UPDATE allureone_branch
                             SET business_name = :n, locality = :l, vendor_id = :v, mobile_number = :m,
                                 isActive = :a, isDingg = :d, enableSaleRecord = :s
                             WHERE id = :id'
                        );
                        $upd->execute([
                            'n' => $name,
                            'l' => $locality,
                            'v' => $vendorId,
                            'm' => $mobileNumber !== '' ? $mobileNumber : null,
                            'a' => $isActive,
                            'd' => $isDingg,
                            's' => $enableSaleRecord,
                            'id' => $id,
                        ]);
                    } else {
                        $upd = $pdo->prepare(
                            'UPDATE allureone_branch
                             SET business_name = :n, locality = :l, vendor_id = :v, mobile_number = :m,
                                 isActive = :a, isDingg = :d, enableSaleRecord = :s, MonthlyTarget = :t
                             WHERE id = :id'
                        );
                        $upd->execute([
                            'n' => $name,
                            'l' => $locality,
                            'v' => $vendorId,
                            'm' => $mobileNumber !== '' ? $mobileNumber : null,
                            'a' => $isActive,
                            'd' => $isDingg,
                            's' => $enableSaleRecord,
                            't' => $monthlyTarget,
                            'id' => $id,
                        ]);
                    }
                    header('Location: branch_master.php?msg=branch_updated');
                    exit;
                }
            }
        } else {
            $isDingg = isset($_POST['is_dingg']) ? 1 : 0;
            $enableSaleRecord = isset($_POST['enable_sale_record']) ? 1 : 0;
            $monthlyTarget = null;
            if ($isDingg !== 1) {
                $parsedTarget = branch_parse_monthly_target((string) ($_POST['monthly_target'] ?? ''));
                if (!$parsedTarget['ok']) {
                    $message = 'Monthly target must be a number (max 2 decimal places).';
                    $messageType = 'error';
                } else {
                    $monthlyTarget = $parsedTarget['value'];
                }
            }
            if ($message === '') {
                try {
                    $ins = $pdo->prepare(
                        'INSERT INTO allureone_branch
                            (id, business_name, locality, vendor_id, mobile_number, isActive, isDingg, enableSaleRecord, MonthlyTarget)
                         VALUES (:id, :n, :l, :v, :m, 1, :d, :s, :t)'
                    );
                    $ins->execute([
                        'id' => $id,
                        'n' => $name,
                        'l' => $locality,
                        'v' => $vendorId,
                        'm' => $mobileNumber !== '' ? $mobileNumber : null,
                        'd' => $isDingg,
                        's' => $enableSaleRecord,
                        't' => $isDingg === 1 ? null : $monthlyTarget,
                    ]);
                    header('Location: branch_master.php?msg=branch_created');
                    exit;
                } catch (PDOException $e) {
                    $dup = ($e->errorInfo[1] ?? null) === 1062;
                    $message = $dup ? 'Branch ID already exists.' : 'Could not create branch.';
                    $messageType = 'error';
                }
            }
        }
    }
}

$list = $pdo->query(
    'SELECT id, business_name, locality, vendor_id, mobile_number, isActive, isDingg, enableSaleRecord, MonthlyTarget
     FROM allureone_branch ORDER BY id ASC'
)->fetchAll();

$pageTitle = 'Branch Master';
$activeNav = 'branch';
require __DIR__ . '/includes/layout_start.php';
?>

<?php if ($message !== ''): ?>
    <div class="alert alert--<?= $messageType === 'ok' ? 'ok' : 'error' ?>" style="margin-bottom:1rem"><?= e($message) ?></div>
<?php endif; ?>

<?php if ($editId > 0 && $editRow !== null): ?>
<div class="card" style="margin-bottom:1.5rem">
    <div class="card__head">Edit branch</div>
    <div class="card__body" style="padding:1.25rem">
        <form class="form" method="post" action="branch_master.php?edit=<?= (int) $editId ?>" style="max-width:480px">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="_action" value="update">
            <input type="hidden" name="branch_id" value="<?= (int) $editRow['id'] ?>">
            <div class="form__row">
                <label for="edit_branch_name">Business name</label>
                <input id="edit_branch_name" name="business_name" type="text" required maxlength="255"
                       value="<?= e((string) ($editRow['business_name'] ?? '')) ?>">
            </div>
            <div class="form__row">
                <label for="edit_locality">Locality</label>
                <input id="edit_locality" name="locality" type="text" maxlength="255"
                       value="<?= e((string) ($editRow['locality'] ?? '')) ?>">
            </div>
            <div class="form__row">
                <label for="edit_vendor_id">Vendor ID</label>
                <input id="edit_vendor_id" name="vendor_id" type="number" min="1" required
                       value="<?= (int) ($editRow['vendor_id'] ?? 0) ?>">
            </div>
            <div class="form__row">
                <label for="edit_mobile_number">Mobile number</label>
                <input id="edit_mobile_number" name="mobile_number" type="text" inputmode="numeric"
                       pattern="[0-9]*" maxlength="15" autocomplete="tel"
                       value="<?= e((string) ($editRow['mobile_number'] ?? '')) ?>">
            </div>
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="is_active" value="1"<?= ((int) ($editRow['isActive'] ?? 0) === 1) ? ' checked' : '' ?>>
                    Active
                </label>
            </div>
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="is_dingg" id="edit_is_dingg" value="1"<?= ((int) ($editRow['isDingg'] ?? 0) === 1) ? ' checked' : '' ?>>
                    Dingg software
                </label>
            </div>
            <?php
            $editMonthlyTargetVal = '';
            $emt = $editRow['MonthlyTarget'] ?? null;
            if ($emt !== null && $emt !== '') {
                $editMonthlyTargetVal = rtrim(rtrim(number_format((float) $emt, 2, '.', ''), '0'), '.');
            }
            ?>
            <div class="form__row" id="edit_monthly_target_wrap">
                <label for="edit_monthly_target">Monthly target</label>
                <input id="edit_monthly_target" name="monthly_target" type="text" inputmode="decimal"
                       maxlength="20" placeholder="Target for a month"
                       value="<?= e($editMonthlyTargetVal) ?>">
            </div>
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="enable_sale_record" value="1"<?= ((int) ($editRow['enableSaleRecord'] ?? 0) === 1) ? ' checked' : '' ?>>
                    Enable sale record
                </label>
            </div>
            <div class="form__actions">
                <button class="btn btn--primary" type="submit">Save changes</button>
                <a class="btn btn--ghost" href="branch_master.php">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:1.5rem">
    <div class="card__head">New branch</div>
    <div class="card__body" style="padding:1.25rem">
        <form class="form" method="post" action="branch_master.php" style="max-width:480px">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="_action" value="create">
            <div class="form__row">
                <label for="branch_id">ID</label>
                <input id="branch_id" name="branch_id" type="number" min="1" required
                       value="<?= (!$editId && isset($_POST['branch_id']) && ($_POST['_action'] ?? '') === 'create') ? (int) $_POST['branch_id'] : '' ?>">
            </div>
            <div class="form__row">
                <label for="business_name">Business name</label>
                <input id="business_name" name="business_name" type="text" required maxlength="255"
                       value="<?= (!$editId && isset($_POST['business_name']) && ($_POST['_action'] ?? '') === 'create') ? e((string) $_POST['business_name']) : '' ?>">
            </div>
            <div class="form__row">
                <label for="locality">Locality</label>
                <input id="locality" name="locality" type="text" maxlength="255"
                       value="<?= (!$editId && isset($_POST['locality']) && ($_POST['_action'] ?? '') === 'create') ? e((string) $_POST['locality']) : '' ?>">
            </div>
            <div class="form__row">
                <label for="vendor_id">Vendor ID</label>
                <input id="vendor_id" name="vendor_id" type="number" min="1" required
                       value="<?= (!$editId && isset($_POST['vendor_id']) && ($_POST['_action'] ?? '') === 'create') ? (int) $_POST['vendor_id'] : 11179 ?>">
            </div>
            <div class="form__row">
                <label for="mobile_number">Mobile number</label>
                <input id="mobile_number" name="mobile_number" type="text" inputmode="numeric"
                       pattern="[0-9]*" maxlength="15" autocomplete="tel"
                       value="<?= (!$editId && isset($_POST['mobile_number']) && ($_POST['_action'] ?? '') === 'create') ? e((string) $_POST['mobile_number']) : '' ?>">
            </div>
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="is_dingg" id="is_dingg" value="1"<?= (!$editId && isset($_POST['is_dingg']) && ($_POST['_action'] ?? '') === 'create') ? ' checked' : '' ?>>
                    Dingg software
                </label>
            </div>
            <div class="form__row" id="monthly_target_wrap">
                <label for="monthly_target">Monthly target</label>
                <input id="monthly_target" name="monthly_target" type="text" inputmode="decimal"
                       maxlength="20" placeholder="Target for a month"
                       value="<?= (!$editId && isset($_POST['monthly_target']) && ($_POST['_action'] ?? '') === 'create') ? e((string) $_POST['monthly_target']) : '' ?>">
            </div>
            <div class="form__row form__row--check">
                <label class="check-label">
                    <input type="checkbox" name="enable_sale_record" value="1">
                    Enable sale record
                </label>
            </div>
            <button class="btn btn--primary" type="submit">Create branch</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card__head">Branches</div>
    <div class="card__body">
        <?php if (count($list) === 0): ?>
            <p class="empty">No branches.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Business name</th>
                            <th>Locality</th>
                            <th>Vendor ID</th>
                            <th>Mobile</th>
                            <th>Active</th>
                            <th>Dingg</th>
                            <th>Monthly target</th>
                            <th>Sale record</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($list as $b): ?>
                            <tr>
                                <td><?= (int) $b['id'] ?></td>
                                <td><?= e((string) $b['business_name']) ?></td>
                                <td><?= e((string) ($b['locality'] ?? '')) ?></td>
                                <td><?= (int) $b['vendor_id'] ?></td>
                                <td><?= e((string) ($b['mobile_number'] ?? '')) ?></td>
                                <td><?= ((int) $b['isActive'] === 1) ? 'Yes' : 'No' ?></td>
                                <td><?= ((int) ($b['isDingg'] ?? 0) === 1) ? 'Yes' : 'No' ?></td>
                                <td><?php
                                    if ((int) ($b['isDingg'] ?? 0) === 1) {
                                        echo '—';
                                    } elseif (($b['MonthlyTarget'] ?? null) === null || $b['MonthlyTarget'] === '') {
                                        echo '—';
                                    } else {
                                        echo e(rtrim(rtrim(number_format((float) $b['MonthlyTarget'], 2, '.', ''), '0'), '.'));
                                    }
                                ?></td>
                                <td><?= ((int) ($b['enableSaleRecord'] ?? 0) === 1) ? 'Yes' : 'No' ?></td>
                                <td class="table-actions"><a href="branch_master.php?edit=<?= (int) $b['id'] ?>">Edit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    function digitsOnly(el) {
        if (!el) return;
        el.addEventListener('input', function () {
            var next = String(el.value || '').replace(/\D+/g, '').slice(0, 15);
            if (el.value !== next) el.value = next;
        });
    }
    digitsOnly(document.getElementById('mobile_number'));
    digitsOnly(document.getElementById('edit_mobile_number'));

    function bindDinggTarget(cb, wrap) {
        if (!cb || !wrap) return;
        function sync() {
            wrap.style.display = cb.checked ? 'none' : '';
        }
        cb.addEventListener('change', sync);
        sync();
    }
    bindDinggTarget(document.getElementById('edit_is_dingg'), document.getElementById('edit_monthly_target_wrap'));
    bindDinggTarget(document.getElementById('is_dingg'), document.getElementById('monthly_target_wrap'));
})();
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
