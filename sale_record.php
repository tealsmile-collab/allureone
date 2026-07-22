<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_sale_record_access();

$user = current_user();
$pdo = db();
$userId = (int) ($user['id'] ?? 0);
$branchId = isset($user['branch_id']) ? (int) $user['branch_id'] : 0;
$today = date('Y-m-d');

if (isset($_GET['lookup_date'])) {
    header('Content-Type: application/json; charset=utf-8');
    $lookupDate = trim((string) $_GET['lookup_date']);
    $dt = DateTime::createFromFormat('Y-m-d', $lookupDate);
    $valid = $dt && $dt->format('Y-m-d') === $lookupDate;
    if (!$valid || $lookupDate > $today || $branchId < 1) {
        echo json_encode(['ok' => true, 'found' => false]);
        exit;
    }
    try {
        $ls = $pdo->prepare(
            'SELECT id, TotalSale FROM allureone_salerecord
             WHERE BranchId = :b AND SaleDate = :d AND IsActive = 1
             LIMIT 1'
        );
        $ls->execute(['b' => $branchId, 'd' => $lookupDate]);
        $lrow = $ls->fetch();
        if (is_array($lrow)) {
            echo json_encode([
                'ok' => true,
                'found' => true,
                'id' => (int) $lrow['id'],
                'total_sale' => number_format((float) ($lrow['TotalSale'] ?? 0), 2, '.', ''),
            ]);
            exit;
        }
    } catch (PDOException $e) {
        error_log('AllureOne sale record lookup failed: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'found' => false]);
        exit;
    }
    echo json_encode(['ok' => true, 'found' => false]);
    exit;
}

$message = '';
$messageType = '';
$flash = [
    'saved' => ['Sale record saved.', 'ok'],
    'updated' => ['Sale record updated.', 'ok'],
];
$mk = isset($_GET['msg']) ? (string) $_GET['msg'] : '';
if (isset($flash[$mk])) {
    [$message, $messageType] = $flash[$mk];
}

$formDate = $today;
$formAmount = '';
$editId = 0;
$showList = isset($_GET['view']) && (string) $_GET['view'] === '1';
$savedRecordId = isset($_GET['rid']) ? (int) $_GET['rid'] : 0;
$showSavedOnly = !$showList && $savedRecordId > 0 && isset($flash[$mk]);

$branchLabel = '—';
if ($branchId > 0) {
    $bst = $pdo->prepare(
        'SELECT business_name, locality FROM allureone_branch WHERE id = :id LIMIT 1'
    );
    $bst->execute(['id' => $branchId]);
    $brow = $bst->fetch();
    if (is_array($brow)) {
        $bn = trim((string) ($brow['business_name'] ?? ''));
        $loc = trim((string) ($brow['locality'] ?? ''));
        $branchLabel = $bn !== '' ? $bn : ('Branch #' . $branchId);
        if ($loc !== '') {
            $branchLabel .= ' · ' . $loc;
        }
    }
}

function sale_record_validate_amount(string $raw): ?string
{
    $v = trim($raw);
    if ($v === '') {
        return 'Total Sale Amount is required.';
    }
    $len = function_exists('mb_strlen') ? mb_strlen($v) : strlen($v);
    if ($len > 20) {
        return 'Total Sale Amount must be at most 20 characters.';
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $v)) {
        return 'Enter a valid amount with up to 2 decimal places.';
    }
    if ((float) $v < 0) {
        return 'Amount cannot be negative.';
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $message = 'Invalid session. Please refresh and try again.';
        $messageType = 'error';
    } elseif ($branchId < 1) {
        $message = 'No branch assigned to your user. Contact admin.';
        $messageType = 'error';
    } else {
        $action = isset($_POST['_action']) ? (string) $_POST['_action'] : 'save';
        $saleDate = isset($_POST['sale_date']) ? trim((string) $_POST['sale_date']) : '';
        $totalRaw = isset($_POST['total_sale']) ? trim((string) $_POST['total_sale']) : '';
        $editId = isset($_POST['record_id']) ? (int) $_POST['record_id'] : 0;
        $formDate = $saleDate !== '' ? $saleDate : $today;
        $formAmount = $totalRaw;

        $dt = DateTime::createFromFormat('Y-m-d', $saleDate);
        $validDate = $dt && $dt->format('Y-m-d') === $saleDate;
        $amountErr = sale_record_validate_amount($totalRaw);

        if (!$validDate) {
            $message = 'Please select a valid date.';
            $messageType = 'error';
        } elseif ($saleDate > $today) {
            $message = 'Future dates are not allowed.';
            $messageType = 'error';
        } elseif ($amountErr !== null) {
            $message = $amountErr;
            $messageType = 'error';
        } else {
            $totalSale = number_format((float) $totalRaw, 2, '.', '');
            $now = date('Y-m-d H:i:s');
            try {
                // Always upsert by branch + date so Save updates when that date already exists.
                $exist = $pdo->prepare(
                    'SELECT id FROM allureone_salerecord
                     WHERE BranchId = :b AND SaleDate = :d AND IsActive = 1
                     LIMIT 1'
                );
                $exist->execute(['b' => $branchId, 'd' => $saleDate]);
                $existing = $exist->fetch();

                if (is_array($existing)) {
                    $rid = (int) $existing['id'];
                    $upd = $pdo->prepare(
                        'UPDATE allureone_salerecord
                         SET TotalSale = :t, UpdatedBy = :u, UpdatedDate = :ud
                         WHERE id = :id AND BranchId = :b'
                    );
                    $upd->execute([
                        't' => $totalSale,
                        'u' => $userId,
                        'ud' => $now,
                        'id' => $rid,
                        'b' => $branchId,
                    ]);
                    header('Location: sale_record.php?msg=updated&rid=' . $rid);
                    exit;
                }

                if ($action === 'update' && $editId > 0) {
                    $chk = $pdo->prepare(
                        'SELECT id FROM allureone_salerecord
                         WHERE id = :id AND BranchId = :b AND IsActive = 1
                         LIMIT 1'
                    );
                    $chk->execute(['id' => $editId, 'b' => $branchId]);
                    if ($chk->fetch() === false) {
                        $message = 'Record not found.';
                        $messageType = 'error';
                    } else {
                        $upd = $pdo->prepare(
                            'UPDATE allureone_salerecord
                             SET SaleDate = :d, TotalSale = :t, UpdatedBy = :u, UpdatedDate = :ud
                             WHERE id = :id AND BranchId = :b'
                        );
                        $upd->execute([
                            'd' => $saleDate,
                            't' => $totalSale,
                            'u' => $userId,
                            'ud' => $now,
                            'id' => $editId,
                            'b' => $branchId,
                        ]);
                        header('Location: sale_record.php?msg=updated&rid=' . $editId);
                        exit;
                    }
                } else {
                    $ins = $pdo->prepare(
                        'INSERT INTO allureone_salerecord
                         (BranchId, SaleDate, TotalSale, CreatedBy, CreatedDate, UpdatedBy, UpdatedDate, IsActive)
                         VALUES (:b, :d, :t, :cb, :cd, NULL, NULL, 1)'
                    );
                    $ins->execute([
                        'b' => $branchId,
                        'd' => $saleDate,
                        't' => $totalSale,
                        'cb' => $userId,
                        'cd' => $now,
                    ]);
                    $rid = (int) $pdo->lastInsertId();
                    header('Location: sale_record.php?msg=saved&rid=' . $rid);
                    exit;
                }
            } catch (PDOException $e) {
                error_log('AllureOne sale record save failed: ' . $e->getMessage());
                $cfg = require __DIR__ . '/config.php';
                $message = 'Could not save sale record.';
                if (!empty($cfg['app']['debug'])) {
                    $message .= ' ' . $e->getMessage();
                }
                $messageType = 'error';
            }
        }
    }
}

if (isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    $showList = false;
    $showSavedOnly = false;
    if ($editId > 0 && $branchId > 0) {
        $es = $pdo->prepare(
            'SELECT id, SaleDate, TotalSale FROM allureone_salerecord
             WHERE id = :id AND BranchId = :b AND IsActive = 1
             LIMIT 1'
        );
        $es->execute(['id' => $editId, 'b' => $branchId]);
        $erow = $es->fetch();
        if (is_array($erow)) {
            $formDate = (string) ($erow['SaleDate'] ?? $today);
            $formAmount = number_format((float) ($erow['TotalSale'] ?? 0), 2, '.', '');
        } else {
            $editId = 0;
            $message = 'Record not found.';
            $messageType = 'error';
        }
    }
} elseif (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    && !$showList
    && !$showSavedOnly
    && $editId === 0
    && $branchId > 0
) {
    $ps = $pdo->prepare(
        'SELECT id, TotalSale FROM allureone_salerecord
         WHERE BranchId = :b AND SaleDate = :d AND IsActive = 1
         LIMIT 1'
    );
    $ps->execute(['b' => $branchId, 'd' => $formDate]);
    $prow = $ps->fetch();
    if (is_array($prow)) {
        $editId = (int) $prow['id'];
        $formAmount = number_format((float) ($prow['TotalSale'] ?? 0), 2, '.', '');
    }
}

$showForm = !$showList && !$showSavedOnly;
$records = [];
$savedRow = null;

if ($showSavedOnly && $branchId > 0) {
    $ss = $pdo->prepare(
        'SELECT id, SaleDate, TotalSale
         FROM allureone_salerecord
         WHERE id = :id AND BranchId = :b AND IsActive = 1
         LIMIT 1'
    );
    $ss->execute(['id' => $savedRecordId, 'b' => $branchId]);
    $savedRow = $ss->fetch();
    if (!is_array($savedRow)) {
        $showSavedOnly = false;
        $showForm = true;
    }
}

if ($showList && $branchId > 0) {
    $rst = $pdo->prepare(
        'SELECT id, SaleDate, TotalSale, CreatedDate, UpdatedDate
         FROM allureone_salerecord
         WHERE BranchId = :b AND IsActive = 1
         ORDER BY SaleDate DESC
         LIMIT 100'
    );
    $rst->execute(['b' => $branchId]);
    $records = $rst->fetchAll() ?: [];
}

$pageTitle = 'Sale Record';
$activeNav = 'sale_record';
require __DIR__ . '/includes/layout_start.php';
?>

<?php if ($message !== ''): ?>
    <div class="alert alert--<?= $messageType === 'ok' ? 'ok' : 'error' ?>" style="margin-bottom:1rem"><?= e($message) ?></div>
<?php endif; ?>

<div class="sale-record-layout">
    <?php if ($showForm): ?>
    <div class="card sale-record-form-card">
        <div class="card__head">
            <span><?= $editId > 0 ? 'Edit sale record' : 'Record sale' ?></span>
            <a class="btn btn--secondary btn--sm" href="sale_record.php?view=1">View sales</a>
        </div>
        <div class="card__body sale-record-body">
            <p class="main__meta" style="margin:0 0 0.85rem">Branch: <strong><?= e($branchLabel) ?></strong></p>
            <?php if ($branchId < 1): ?>
                <p class="empty">Your user has no branch assigned. Ask Superadmin to set a branch in User Master.</p>
            <?php else: ?>
                <form class="form" method="post" action="sale_record.php" style="max-width:420px" autocomplete="off" id="sale-record-form">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="_action" id="sale_action" value="<?= $editId > 0 ? 'update' : 'save' ?>">
                    <input type="hidden" name="record_id" id="record_id" value="<?= (int) $editId ?>">
                    <div class="form__row">
                        <label for="sale_date">Date</label>
                        <input id="sale_date" name="sale_date" type="date" required
                               value="<?= e($formDate) ?>"
                               max="<?= e($today) ?>">
                    </div>
                    <div class="form__row">
                        <label for="total_sale">Total Sale Amount</label>
                        <input id="total_sale" name="total_sale" type="text" inputmode="decimal"
                               required maxlength="20" placeholder="0.00"
                               value="<?= e($formAmount) ?>">
                        <span class="hint" id="sale_date_hint"><?= $editId > 0 ? 'Existing sale found for this date. Save will update it.' : 'Numbers only, up to 2 decimal places (max 20 characters).' ?></span>
                    </div>
                    <div class="form__actions">
                        <button class="btn btn--primary" type="submit" id="sale_submit_btn"><?= $editId > 0 ? 'Update' : 'Save' ?></button>
                        <?php if ($editId > 0 && isset($_GET['edit'])): ?>
                            <a class="btn btn--ghost" href="sale_record.php">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($showSavedOnly && is_array($savedRow)): ?>
    <div class="card sale-record-form-card">
        <div class="card__head">
            <span>Record sale</span>
            <a class="btn btn--secondary btn--sm" href="sale_record.php?view=1">View sales</a>
        </div>
        <div class="card__body sale-record-body">
            <p class="main__meta" style="margin:0 0 0.85rem">Branch: <strong><?= e($branchLabel) ?></strong></p>
            <div class="table-wrap sale-record-table-wrap">
                <table class="data sale-record-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Total Sale Amount (Rs.)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= e((string) ($savedRow['SaleDate'] ?? '')) ?></td>
                            <td><?= e(number_format((float) ($savedRow['TotalSale'] ?? 0), 2, '.', ',')) ?></td>
                            <td class="table-actions">
                                <a href="sale_record.php?edit=<?= (int) $savedRow['id'] ?>">Edit</a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="form__actions" style="margin-top:0.85rem">
                <a class="btn btn--primary" href="sale_record.php">Record sale</a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($showList): ?>
    <div class="card sale-record-list-card">
        <div class="card__head">
            <span>Date-wise Total Sale</span>
            <a class="btn btn--secondary btn--sm" href="sale_record.php">Record sale</a>
        </div>
        <div class="card__body sale-record-body">
            <p class="main__meta" style="margin:0 0 0.85rem">Branch: <strong><?= e($branchLabel) ?></strong></p>
            <?php if ($branchId < 1): ?>
                <p class="empty">No branch assigned.</p>
            <?php elseif (count($records) === 0): ?>
                <p class="empty">No sale records yet.</p>
            <?php else: ?>
                <div class="table-wrap sale-record-table-wrap">
                    <table class="data sale-record-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Total Sale Amount (Rs.)</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $r): ?>
                                <tr>
                                    <td><?= e((string) ($r['SaleDate'] ?? '')) ?></td>
                                    <td><?= e(number_format((float) ($r['TotalSale'] ?? 0), 2, '.', ',')) ?></td>
                                    <td class="table-actions">
                                        <a href="sale_record.php?edit=<?= (int) $r['id'] ?>">Edit</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
.sale-record-layout {
    display: grid;
    gap: 1rem;
    max-width: 720px;
}
.sale-record-form-card .card__head,
.sale-record-list-card .card__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
}
.sale-record-body {
    padding: 0.85rem 0.75rem 1rem;
}
.sale-record-table-wrap {
    overflow-x: auto;
}
.sale-record-table th,
.sale-record-table td {
    padding: 0.35rem 0.45rem;
    font-size: 0.88rem;
    white-space: nowrap;
}
.btn--sm {
    padding: 0.35rem 0.7rem;
    font-size: 0.85rem;
}
</style>

<script>
(function () {
    var amount = document.getElementById('total_sale');
    var dateEl = document.getElementById('sale_date');
    var actionEl = document.getElementById('sale_action');
    var recordIdEl = document.getElementById('record_id');
    var submitBtn = document.getElementById('sale_submit_btn');
    var hintEl = document.getElementById('sale_date_hint');
    var lookupSeq = 0;

    if (amount) {
        amount.addEventListener('input', function () {
            var v = String(amount.value || '');
            v = v.replace(/[^\d.]/g, '');
            var parts = v.split('.');
            if (parts.length > 2) {
                v = parts[0] + '.' + parts.slice(1).join('');
                parts = v.split('.');
            }
            if (parts.length === 2) {
                v = parts[0] + '.' + parts[1].slice(0, 2);
            }
            if (v.length > 20) {
                v = v.slice(0, 20);
            }
            amount.value = v;
        });
    }

    function setExistingMode(found, id, totalSale) {
        if (!actionEl || !recordIdEl || !submitBtn) return;
        if (found) {
            actionEl.value = 'update';
            recordIdEl.value = String(id || 0);
            submitBtn.textContent = 'Update';
            if (amount) amount.value = totalSale || '';
            if (hintEl) hintEl.textContent = 'Existing sale found for this date. Save will update it.';
        } else {
            actionEl.value = 'save';
            recordIdEl.value = '0';
            submitBtn.textContent = 'Save';
            if (amount) amount.value = '';
            if (hintEl) hintEl.textContent = 'Numbers only, up to 2 decimal places (max 20 characters).';
        }
    }

    function lookupByDate() {
        if (!dateEl) return;
        var d = String(dateEl.value || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) {
            setExistingMode(false, 0, '');
            return;
        }
        var seq = ++lookupSeq;
        fetch('sale_record.php?lookup_date=' + encodeURIComponent(d), {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (seq !== lookupSeq) return;
                if (data && data.ok && data.found) {
                    setExistingMode(true, data.id, data.total_sale);
                } else {
                    setExistingMode(false, 0, '');
                }
            })
            .catch(function () {
                if (seq !== lookupSeq) return;
            });
    }

    if (dateEl) {
        dateEl.addEventListener('change', lookupByDate);
        dateEl.addEventListener('input', lookupByDate);
    }
})();
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
