<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/meta_ads.php';
require_meta_ads_access();

$pageTitle = 'Meta Ads';
$activeNav = 'meta_ads';
$periodOptions = meta_ads_period_options();
$selectedPeriod = meta_ads_normalize_period((string) ($_GET['period'] ?? 'last_14'));
$loaded = meta_ads_load($selectedPeriod);
$accounts = is_array($loaded['accounts'] ?? null) ? $loaded['accounts'] : [];
$ads = is_array($loaded['ads'] ?? null) ? $loaded['ads'] : [];

require __DIR__ . '/includes/layout_start.php';
?>

<style>
.meta-funds {
  display: grid;
  gap: 0.4rem;
  margin: 0;
}
.meta-fund {
  display: flex;
  align-items: baseline;
  flex-wrap: wrap;
  gap: 0.35rem 0.85rem;
  margin: 0;
}
.meta-fund__label {
  margin: 0;
  color: #425466;
  font-size: 0.85rem;
  font-weight: 600;
}
.meta-fund__value {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 800;
  color: #12263a;
  letter-spacing: -0.02em;
}
.meta-fund__meta {
  margin: 0;
  color: #425466;
  font-size: 0.82rem;
}
.meta-ads-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  flex-wrap: wrap;
}
.meta-range {
  position: relative;
  margin-left: auto;
}
.meta-range__btn {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  min-height: 36px;
  padding: 0.35rem 0.7rem;
  border: 1px solid #c5d0dc;
  border-radius: 8px;
  background: #fff;
  color: #12263a;
  font: inherit;
  font-size: 0.92rem;
  font-weight: 600;
  cursor: pointer;
}
.meta-range__btn svg { flex: 0 0 auto; color: #244a73; }
.meta-range__menu {
  position: absolute;
  right: 0;
  top: calc(100% + 6px);
  z-index: 30;
  width: 15.5rem;
  max-height: min(28rem, calc(100vh - 8rem));
  overflow: auto;
  padding: 0.45rem 0;
  border: 1px solid #d7e1ec;
  border-radius: 10px;
  background: #fff;
  box-shadow: 0 12px 28px rgba(16, 24, 40, 0.14);
}
.meta-range__menu[hidden] { display: none !important; }
.meta-range__menu label {
  display: flex;
  align-items: center;
  gap: 0.55rem;
  margin: 0;
  padding: 0.38rem 0.85rem;
  font-size: 0.95rem;
  font-weight: 500;
  color: #12263a;
  cursor: pointer;
}
.meta-range__menu label:hover { background: #f4f8fc; }
.card.meta-ads-card { overflow: visible; }
  font-weight: 700;
  vertical-align: top;
  background: #f8fbff;
}
</style>

<div class="card">
    <div class="card__head">Available funds</div>
    <div class="card__body" style="padding:0.65rem 1.25rem">
        <?php if (!($loaded['ok'] ?? false) && $accounts === []): ?>
            <p class="alert alert--error" style="margin:0"><?= e((string) ($loaded['error'] ?? 'Could not load Meta Ads.')) ?></p>
        <?php elseif ($accounts === []): ?>
            <p class="empty" style="margin:0">No ad accounts were returned for this token.</p>
        <?php else: ?>
            <?php if (trim((string) ($loaded['error'] ?? '')) !== ''): ?>
                <p class="alert alert--error"><?= e((string) $loaded['error']) ?></p>
            <?php endif; ?>
            <div class="meta-funds">
                <?php foreach ($accounts as $account): ?>
                    <div class="meta-fund">
                        <?php if (count($accounts) > 1): ?>
                            <p class="meta-fund__label"><?= e((string) ($account['name'] ?? '')) ?></p>
                        <?php endif; ?>
                        <p class="meta-fund__value"><?= e((string) ($account['balance_label'] ?? '—')) ?></p>
                        <p class="meta-fund__meta">
                            <?= e((string) ($account['name'] ?? '')) ?>
                            <?php if (trim((string) ($account['funding'] ?? '')) !== ''): ?>
                                · <?= e((string) $account['funding']) ?>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card meta-ads-card" style="margin-top:1rem">
    <div class="card__head meta-ads-head">
        <span>Active ads<?= $ads !== [] ? ' (' . count($ads) . ')' : '' ?></span>
        <div class="meta-range">
            <button type="button" class="meta-range__btn" id="metaRangeBtn" aria-expanded="false" aria-controls="metaRangeMenu">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <rect x="3" y="5" width="18" height="16" rx="2"></rect>
                    <path d="M3 10h18M8 3v4M16 3v4"></path>
                </svg>
                <span><?= e(meta_ads_period_button_label($selectedPeriod)) ?></span>
                <span aria-hidden="true">▾</span>
            </button>
            <form method="get" action="Meta.php" class="meta-range__menu" id="metaRangeMenu" hidden>
                <?php foreach ($periodOptions as $periodKey => $periodLabel): ?>
                    <label>
                        <input type="radio" name="period" value="<?= e($periodKey) ?>"<?= $selectedPeriod === $periodKey ? ' checked' : '' ?> onchange="this.form.submit()">
                        <?= e($periodLabel) ?>
                    </label>
                <?php endforeach; ?>
            </form>
        </div>
    </div>
    <div class="card__body">
        <?php if ($accounts === []): ?>
            <p class="empty">Active ads appear after Meta Ads is connected.</p>
        <?php elseif ($ads === []): ?>
            <p class="empty">No active ads.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <?php if (count($accounts) > 1): ?>
                                <th>Account</th>
                            <?php endif; ?>
                            <th>Campaign</th>
                            <th>Ad</th>
                            <th>Ad set</th>
                            <th>Leads</th>
                            <th>Budget</th>
                            <th>Amount spent</th>
                            <th>Impressions</th>
                            <th>Reach</th>
                            <th>Status</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $adGroups = [];
                        foreach ($ads as $ad) {
                            $campaignName = trim((string) ($ad['campaign'] ?? ''));
                            if ($campaignName === '') {
                                $campaignName = '—';
                            }
                            $groupKey = (count($accounts) > 1 ? ((string) ($ad['account'] ?? '') . "\n") : '') . $campaignName;
                            $adGroups[$groupKey][] = $ad;
                        }
                        foreach ($adGroups as $groupAds):
                            $groupCount = count($groupAds);
                            foreach ($groupAds as $index => $ad):
                        ?>
                            <tr>
                                <?php if (count($accounts) > 1 && $index === 0): ?>
                                    <td class="meta-campaign" rowspan="<?= $groupCount ?>"><?= e((string) ($ad['account'] ?? '')) ?></td>
                                <?php endif; ?>
                                <?php if ($index === 0): ?>
                                    <td class="meta-campaign" rowspan="<?= $groupCount ?>"><?= e(trim((string) ($ad['campaign'] ?? '')) !== '' ? (string) $ad['campaign'] : '—') ?></td>
                                <?php endif; ?>
                                <td>
                                    <?= e((string) ($ad['name'] ?? '')) ?>
                                    <?php if (trim((string) ($ad['id'] ?? '')) !== ''): ?>
                                        <div class="main__meta"><?= e((string) $ad['id']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e((string) ($ad['adset'] ?? '')) ?></td>
                                <td><?= (int) ($ad['leads'] ?? 0) ?></td>
                                <td><?= e((string) ($ad['budget'] ?? '—')) ?></td>
                                <td><?= e((string) ($ad['spend'] ?? '—')) ?></td>
                                <td><?= e((string) ($ad['impressions'] ?? '0')) ?></td>
                                <td><?= e((string) ($ad['reach'] ?? '0')) ?></td>
                                <td><?= e((string) ($ad['status'] ?? '')) ?></td>
                                <td><?= e((string) ($ad['created'] ?? '')) ?></td>
                            </tr>
                        <?php
                            endforeach;
                        endforeach;
                        ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
  var btn = document.getElementById('metaRangeBtn');
  var menu = document.getElementById('metaRangeMenu');
  if (!btn || !menu) return;
  btn.addEventListener('click', function (ev) {
    ev.stopPropagation();
    var open = menu.hidden;
    menu.hidden = !open;
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (!open) return;
    var space = window.innerHeight - btn.getBoundingClientRect().bottom - 16;
    menu.style.maxHeight = Math.max(180, space) + 'px';
  });
  document.addEventListener('click', function (ev) {
    if (menu.hidden) return;
    if (ev.target instanceof Node && (btn.contains(ev.target) || menu.contains(ev.target))) return;
    menu.hidden = true;
    btn.setAttribute('aria-expanded', 'false');
  });
})();
</script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
