<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_meta_config_access();
require_not_accounts_role();
require_not_franchise_officer_role();

$user = current_user();
$pdo = db();
$userId = (int) ($user['id'] ?? 0);
$message = '';
$messageType = '';

$flash = [
    'saved' => ['Meta form config saved.', 'ok'],
];
$mk = isset($_GET['msg']) ? (string) $_GET['msg'] : '';
if (isset($flash[$mk])) {
    [$message, $messageType] = $flash[$mk];
}

/**
 * Ensure config table + campaign_name column exist.
 */
function meta_form_config_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $exists = (int) $pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'allureone_meta_form_config'"
    )->fetchColumn();
    if ($exists === 0) {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS allureone_meta_form_config (
              id INT NOT NULL AUTO_INCREMENT,
              BranchId INT NOT NULL,
              meta_form_id VARCHAR(64) NOT NULL,
              campaign_name VARCHAR(255) NULL,
              CreatedBy INT NULL,
              CreatedDate DATETIME NOT NULL,
              UpdatedBy INT NULL,
              UpdatedDate DATETIME NULL,
              IsActive TINYINT(1) NOT NULL DEFAULT 1,
              PRIMARY KEY (id),
              UNIQUE KEY uq_meta_form_id_active (meta_form_id),
              KEY idx_meta_form_branch (BranchId),
              CONSTRAINT fk_meta_form_branch FOREIGN KEY (BranchId) REFERENCES allureone_branch (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } else {
        $cols = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'allureone_meta_form_config'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $colSet = is_array($cols) ? array_flip(array_map('strval', $cols)) : [];
        if (!isset($colSet['campaign_name'])) {
            $pdo->exec('ALTER TABLE allureone_meta_form_config ADD COLUMN campaign_name VARCHAR(255) NULL AFTER meta_form_id');
        }
    }
    $done = true;
}

/**
 * @return list<string>
 */
function meta_form_config_parse_ids(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
    $out = [];
    $seen = [];
    foreach ($parts as $p) {
        $id = trim((string) $p);
        if ($id === '') {
            continue;
        }
        if (!preg_match('/^\d{5,64}$/', $id)) {
            continue;
        }
        if (isset($seen[$id])) {
            continue;
        }
        $seen[$id] = true;
        $out[] = $id;
    }

    return $out;
}

meta_form_config_ensure_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $message = 'Invalid session. Please refresh and try again.';
        $messageType = 'error';
    } else {
        $branchId = isset($_POST['branch_id']) ? (int) $_POST['branch_id'] : 0;
        $rawIds = isset($_POST['meta_form_ids']) ? (string) $_POST['meta_form_ids'] : '';
        $formIds = meta_form_config_parse_ids($rawIds);
        $campaignName = isset($_POST['campaign_name']) ? trim((string) $_POST['campaign_name']) : '';
        if (function_exists('mb_strlen') ? mb_strlen($campaignName) > 255 : strlen($campaignName) > 255) {
            $message = 'Campaign name must be at most 255 characters.';
            $messageType = 'error';
        }

        $chk = $pdo->prepare('SELECT id FROM allureone_branch WHERE id = :id AND isActive = 1 LIMIT 1');
        $chk->execute(['id' => $branchId]);
        if ($messageType !== 'error' && $chk->fetch() === false) {
            $message = 'Branch not found or inactive.';
            $messageType = 'error';
        } elseif ($messageType !== 'error') {
            try {
                $pdo->beginTransaction();
                $now = date('Y-m-d H:i:s');

                if ($formIds !== []) {
                    $placeholders = implode(',', array_fill(0, count($formIds), '?'));
                    $dupSql = "SELECT meta_form_id, BranchId
                               FROM allureone_meta_form_config
                               WHERE IsActive = 1
                                 AND meta_form_id IN ({$placeholders})
                                 AND BranchId <> ?";
                    $dupSt = $pdo->prepare($dupSql);
                    $params = $formIds;
                    $params[] = $branchId;
                    $dupSt->execute($params);
                    $dups = $dupSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                    if ($dups !== []) {
                        $pdo->rollBack();
                        $bits = [];
                        foreach ($dups as $d) {
                            $bits[] = (string) ($d['meta_form_id'] ?? '') . ' → branch ' . (string) ($d['BranchId'] ?? '');
                        }
                        $message = 'Form ID already mapped to another branch: ' . implode('; ', $bits);
                        $messageType = 'error';
                    }
                }

                if ($messageType !== 'error') {
                    if ($formIds === []) {
                        $del = $pdo->prepare(
                            'UPDATE allureone_meta_form_config
                             SET IsActive = 0, UpdatedBy = :u, UpdatedDate = :ud
                             WHERE BranchId = :b AND IsActive = 1'
                        );
                        $del->execute(['u' => $userId, 'ud' => $now, 'b' => $branchId]);
                    } else {
                        $placeholders = implode(',', array_fill(0, count($formIds), '?'));
                        $delSql = "UPDATE allureone_meta_form_config
                                    SET IsActive = 0, UpdatedBy = ?, UpdatedDate = ?
                                    WHERE BranchId = ? AND IsActive = 1
                                      AND meta_form_id NOT IN ({$placeholders})";
                        $delParams = [$userId, $now, $branchId];
                        foreach ($formIds as $fid) {
                            $delParams[] = $fid;
                        }
                        $del = $pdo->prepare($delSql);
                        $del->execute($delParams);
                    }

                    $find = $pdo->prepare(
                        'SELECT id, BranchId, IsActive FROM allureone_meta_form_config
                         WHERE meta_form_id = :f
                         LIMIT 1'
                    );
                    $ins = $pdo->prepare(
                        'INSERT INTO allureone_meta_form_config
                         (BranchId, meta_form_id, campaign_name, CreatedBy, CreatedDate, UpdatedBy, UpdatedDate, IsActive)
                         VALUES (:b, :f, :c, :cb, :cd, NULL, NULL, 1)'
                    );
                    $reactivate = $pdo->prepare(
                        'UPDATE allureone_meta_form_config
                         SET BranchId = :b, campaign_name = :c, IsActive = 1, UpdatedBy = :u, UpdatedDate = :ud
                         WHERE id = :id'
                    );
                    $campaignDb = $campaignName !== '' ? $campaignName : null;

                    foreach ($formIds as $fid) {
                        $find->execute(['f' => $fid]);
                        $existing = $find->fetch(PDO::FETCH_ASSOC);
                        if (is_array($existing)) {
                            $reactivate->execute([
                                'b' => $branchId,
                                'c' => $campaignDb,
                                'u' => $userId,
                                'ud' => $now,
                                'id' => (int) $existing['id'],
                            ]);
                        } else {
                            $ins->execute([
                                'b' => $branchId,
                                'f' => $fid,
                                'c' => $campaignDb,
                                'cb' => $userId,
                                'cd' => $now,
                            ]);
                        }
                    }

                    // Keep campaign name consistent for all active rows of this branch
                    if ($formIds !== []) {
                        $syncCamp = $pdo->prepare(
                            'UPDATE allureone_meta_form_config
                             SET campaign_name = :c, UpdatedBy = :u, UpdatedDate = :ud
                             WHERE BranchId = :b AND IsActive = 1'
                        );
                        $syncCamp->execute([
                            'c' => $campaignDb,
                            'u' => $userId,
                            'ud' => $now,
                            'b' => $branchId,
                        ]);
                    }

                    $pdo->commit();
                    header('Location: meta-leads-config.php?msg=saved');
                    exit;
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Meta form config save failed: ' . $e->getMessage());
                $cfg = require __DIR__ . '/config.php';
                $message = 'Could not save Meta form config.';
                if (!empty($cfg['app']['debug'])) {
                    $message .= ' ' . $e->getMessage();
                }
                $messageType = 'error';
            }
        }
    }
}

$branches = $pdo->query(
    'SELECT id, business_name, locality
     FROM allureone_branch
     WHERE isActive = 1
     ORDER BY locality ASC, business_name ASC'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

/** @var array<int, list<string>> $formIdsByBranch */
$formIdsByBranch = [];
/** @var array<int, string> $campaignByBranch */
$campaignByBranch = [];
try {
    $cfgRows = $pdo->query(
        'SELECT BranchId, meta_form_id, campaign_name
         FROM allureone_meta_form_config
         WHERE IsActive = 1
         ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($cfgRows as $row) {
        $bid = (int) ($row['BranchId'] ?? 0);
        $fid = trim((string) ($row['meta_form_id'] ?? ''));
        $camp = trim((string) ($row['campaign_name'] ?? ''));
        if ($bid <= 0 || $fid === '') {
            continue;
        }
        if (!isset($formIdsByBranch[$bid])) {
            $formIdsByBranch[$bid] = [];
        }
        $formIdsByBranch[$bid][] = $fid;
        if ($camp !== '' && !isset($campaignByBranch[$bid])) {
            $campaignByBranch[$bid] = $camp;
        }
    }
} catch (PDOException $e) {
    error_log('Meta form config load failed: ' . $e->getMessage());
}

$pageTitle = 'Meta Config';
$activeNav = 'meta_config';
require __DIR__ . '/includes/layout_start.php';
?>

<?php if ($message !== ''): ?>
    <div class="alert alert--<?= $messageType === 'ok' ? 'ok' : 'error' ?>" style="margin-bottom:1rem"><?= e($message) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card__head">Meta Ad lead form IDs by branch</div>
    <div class="card__body" style="padding:1rem">
        <p class="main__meta" style="margin:0 0 1rem">
            Add campaign name and one or more Meta form IDs per branch. One form ID can belong to only one branch; a branch can have many form IDs.
        </p>
        <?php if ($branches === []): ?>
            <p class="empty">No active branches.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data meta-config-table">
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Campaign name</th>
                            <th>Meta form IDs</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($branches as $b): ?>
                            <?php
                            $bid = (int) ($b['id'] ?? 0);
                            $loc = trim((string) ($b['locality'] ?? ''));
                            $bn = trim((string) ($b['business_name'] ?? ''));
                            $label = $loc !== '' ? $loc : ($bn !== '' ? $bn : ('Branch #' . $bid));
                            if ($loc !== '' && $bn !== '' && strcasecmp($loc, $bn) !== 0) {
                                $label = $loc . ' · ' . $bn;
                            }
                            $ids = $formIdsByBranch[$bid] ?? [];
                            $campaign = $campaignByBranch[$bid] ?? '';
                            ?>
                            <tr data-branch-id="<?= $bid ?>">
                                <td><?= e($label) ?></td>
                                <td>
                                    <input class="meta-campaign-input" type="text" name="campaign_name" maxlength="255"
                                           placeholder="Campaign name"
                                           value="<?= e($campaign) ?>"
                                           form="meta-form-<?= $bid ?>">
                                </td>
                                <td>
                                    <form class="meta-form-ids-form" method="post" action="meta-leads-config.php" data-branch-id="<?= $bid ?>" id="meta-form-<?= $bid ?>">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="branch_id" value="<?= $bid ?>">
                                        <input type="hidden" name="meta_form_ids" class="meta-form-ids-hidden" value="<?= e(implode(',', $ids)) ?>">
                                        <div class="meta-form-row">
                                            <div class="meta-form-ids-main">
                                                <div class="meta-form-ids-chips" aria-live="polite">
                                                    <?php foreach ($ids as $fid): ?>
                                                        <span class="meta-form-chip" data-id="<?= e($fid) ?>">
                                                            <span class="meta-form-chip__text"><?= e($fid) ?></span>
                                                            <button type="button" class="meta-form-chip__remove" aria-label="Remove <?= e($fid) ?>">×</button>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                                <div class="meta-form-ids-add">
                                                    <input type="text" class="meta-form-ids-input" inputmode="numeric" maxlength="64"
                                                           placeholder="Add form ID and press Enter"
                                                           autocomplete="off">
                                                    <button type="button" class="btn btn--ghost btn--sm meta-form-ids-add-btn">Add</button>
                                                    <button class="btn btn--primary btn--sm" type="submit">Save</button>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.meta-config-table th,
.meta-config-table td {
    vertical-align: top;
    padding: 0.55rem 0.5rem;
}
.meta-campaign-input {
    width: 100%;
    min-width: 10rem;
    max-width: 18rem;
    padding: 0.35rem 0.5rem;
    border: 1px solid #c9d2de;
    border-radius: 6px;
    font-size: 0.9rem;
}
.meta-form-row {
    display: flex;
    gap: 0.75rem;
    align-items: flex-start;
    justify-content: space-between;
}
.meta-form-ids-main {
    flex: 1;
    min-width: 0;
}
.meta-form-ids-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
    margin-bottom: 0.45rem;
    min-height: 1.5rem;
}
.meta-form-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    background: #eef2f7;
    border: 1px solid #d0d7e2;
    border-radius: 999px;
    padding: 0.15rem 0.35rem 0.15rem 0.55rem;
    font-size: 0.85rem;
    line-height: 1.2;
}
.meta-form-chip__remove {
    border: 0;
    background: transparent;
    color: #64748b;
    cursor: pointer;
    font-size: 1rem;
    line-height: 1;
    padding: 0 0.2rem;
    border-radius: 999px;
}
.meta-form-chip__remove:hover {
    color: #b91c1c;
    background: #fee2e2;
}
.meta-form-ids-add {
    display: flex;
    gap: 0.4rem;
    align-items: center;
    flex-wrap: wrap;
}
.meta-form-ids-input {
    flex: 1;
    min-width: 10rem;
    max-width: 18rem;
    padding: 0.35rem 0.5rem;
    border: 1px solid #c9d2de;
    border-radius: 6px;
    font-size: 0.9rem;
}
.btn--sm {
    padding: 0.35rem 0.7rem;
    font-size: 0.85rem;
}
</style>

<script>
(function () {
    function syncHidden(form) {
        var hidden = form.querySelector('.meta-form-ids-hidden');
        var chips = form.querySelectorAll('.meta-form-chip');
        var ids = [];
        chips.forEach(function (chip) {
            var id = String(chip.getAttribute('data-id') || '').trim();
            if (id) ids.push(id);
        });
        if (hidden) hidden.value = ids.join(',');
    }

    function addChip(form, raw) {
        var id = String(raw || '').trim();
        if (!/^\d{5,64}$/.test(id)) {
            return false;
        }
        var chipsWrap = form.querySelector('.meta-form-ids-chips');
        if (!chipsWrap) return false;
        if (form.querySelector('.meta-form-chip[data-id="' + id.replace(/"/g, '') + '"]')) {
            return true;
        }
        var chip = document.createElement('span');
        chip.className = 'meta-form-chip';
        chip.setAttribute('data-id', id);
        chip.innerHTML = '<span class="meta-form-chip__text"></span><button type="button" class="meta-form-chip__remove" aria-label="Remove">×</button>';
        chip.querySelector('.meta-form-chip__text').textContent = id;
        chipsWrap.appendChild(chip);
        syncHidden(form);
        return true;
    }

    document.querySelectorAll('.meta-form-ids-form').forEach(function (form) {
        form.addEventListener('click', function (ev) {
            var btn = ev.target.closest('.meta-form-chip__remove');
            if (!btn || !form.contains(btn)) return;
            var chip = btn.closest('.meta-form-chip');
            if (chip) chip.remove();
            syncHidden(form);
        });

        var input = form.querySelector('.meta-form-ids-input');
        var addBtn = form.querySelector('.meta-form-ids-add-btn');

        function tryAdd() {
            if (!input) return;
            var ok = addChip(form, input.value);
            if (ok) input.value = '';
            else if (String(input.value || '').trim() !== '') {
                input.focus();
            }
        }

        if (addBtn) addBtn.addEventListener('click', tryAdd);
        if (input) {
            input.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' || ev.key === ',') {
                    ev.preventDefault();
                    tryAdd();
                }
            });
            input.addEventListener('input', function () {
                var v = String(input.value || '').replace(/[^\d,\s;]/g, '');
                if (v !== input.value) input.value = v;
            });
        }

        form.addEventListener('submit', function () {
            if (input && String(input.value || '').trim() !== '') {
                addChip(form, input.value);
                input.value = '';
            }
            syncHidden(form);
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
