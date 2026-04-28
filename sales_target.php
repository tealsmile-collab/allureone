<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/gift_helpers.php';
require_login();
require_not_accounts_role();
require_not_franchise_officer_role();

$curY = (int) date('Y');
$curM = (int) date('n');

$y = isset($_GET['y']) ? (int) $_GET['y'] : $curY;
$m = isset($_GET['m']) ? (int) $_GET['m'] : $curM;
if ($y < 2000 || $y > 2100) {
    $y = $curY;
}
if ($m < 1 || $m > 12) {
    $m = $curM;
}

$startDate = sprintf('%04d-%02d-01', $y, $m);
$endDate = date('Y-m-t', strtotime($startDate . ' 00:00:00'));

$branches = [];
$loadError = '';
$tableRows = [];
$apiHttp = null;
$extraLocationRows = [];

try {
    $pdo = db();
    $stmt = $pdo->query(
        'SELECT id, business_name, locality FROM allureone_branch WHERE isActive = 1 ORDER BY business_name ASC'
    );
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('AllureOne sales_target branch list: ' . $e->getMessage());
    $loadError = 'Could not load branches.';
}

if ($loadError === '' && $branches === []) {
    $loadError = 'No active branches in Branch Master. Add branches first.';
}

if ($loadError === '') {
    $ids = [];
    foreach ($branches as $b) {
        $ids[] = (int) ($b['id'] ?? 0);
    }
    $ids = array_values(array_filter($ids, static fn (int $id): bool => $id > 0));
    $locationIdsCsv = implode(',', $ids);

    $resp = dingg_fetch_sales_target($startDate, $endDate, $locationIdsCsv);
    if (($resp['error'] ?? '') === 'no_token') {
        $loadError = 'Dingg token not available. Sign in again or check POS token / Dingg config.';
    } elseif (!($resp['ok'] ?? false)) {
        $loadError = 'Could not call sales target API.';
    } else {
        $apiHttp = (int) ($resp['http'] ?? 0);
        $body = (string) ($resp['body'] ?? '');
        if (dingg_response_looks_unauthorized($apiHttp, $body)) {
            $loadError = dingg_auth_expired_user_message();
        } elseif ($apiHttp < 200 || $apiHttp >= 300) {
            $loadError = 'Sales target API returned HTTP ' . $apiHttp . '.';
            $snippet = trim($body);
            if (strlen($snippet) > 280) {
                $snippet = substr($snippet, 0, 280) . '…';
            }
            if ($snippet !== '') {
                $loadError .= ' ' . $snippet;
            }
        } else {
            $byLoc = dingg_parse_sales_target_by_location($body);
            $branchIdsSeen = [];
            foreach ($branches as $b) {
                $bid = (int) ($b['id'] ?? 0);
                if ($bid <= 0) {
                    continue;
                }
                $branchIdsSeen[$bid] = true;
                $mets = $byLoc[$bid] ?? ['total_sales' => 0.0, 'total_sales_achieved' => 0.0];
                $bn = trim((string) ($b['business_name'] ?? ''));
                $loc = trim((string) ($b['locality'] ?? ''));
                $label = $bn !== '' ? ($loc !== '' ? $bn . ' (' . $loc . ')' : $bn) : ('Branch #' . $bid);
                $tableRows[] = [
                    'branch_name' => $label,
                    'target' => $mets['total_sales'],
                    'achieved' => $mets['total_sales_achieved'],
                ];
            }
            foreach ($byLoc as $lid => $mets) {
                if (isset($branchIdsSeen[$lid])) {
                    continue;
                }
                $extraLocationRows[] = [
                    'branch_name' => 'Location #' . $lid . ' (not in Branch Master)',
                    'target' => $mets['total_sales'],
                    'achieved' => $mets['total_sales_achieved'],
                ];
            }
        }
    }
}

$pageTitle = 'Sales target';
$activeNav = 'sales_target';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="card">
    <div class="card__head">
        <span>Sales target report</span>
    </div>
    <div class="card__body" style="padding:1.25rem">
        <form class="form form--inline-sales-period" method="get" action="sales_target.php">
            <div class="form__row form__row--month">
                <label for="sales_m">Month</label>
                <select id="sales_m" name="m">
                    <?php
                    $monthNames = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
                    foreach ($monthNames as $mi => $label) {
                        ?>
                        <option value="<?= $mi ?>"<?= $m === $mi ? ' selected' : '' ?>><?= e($label) ?></option>
                        <?php
                    }
                    ?>
                </select>
            </div>
            <div class="form__row form__row--year">
                <label for="sales_y">Year</label>
                <select id="sales_y" name="y">
                    <?php
                    for ($yy = $curY - 5; $yy <= $curY + 2; $yy++) {
                        ?>
                        <option value="<?= $yy ?>"<?= $y === $yy ? ' selected' : '' ?>><?= $yy ?></option>
                        <?php
                    }
                    ?>
                </select>
            </div>
            <div class="form__row form__row--submit">
                <button class="btn btn--primary" type="submit">Load report</button>
            </div>
        </form>
        <p class="main__meta" style="margin:0 0 1rem">Period: <strong><?= e($startDate) ?></strong> to <strong><?= e($endDate) ?></strong><?= $apiHttp !== null ? ' · HTTP ' . $apiHttp : '' ?></p>

        <?php if ($loadError !== ''): ?>
            <p class="alert alert--error" role="alert"><?= e($loadError) ?></p>
        <?php elseif ($tableRows !== [] || $extraLocationRows !== []): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Branch name</th>
                            <th>Target</th>
                            <th>Achieved</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tableRows as $row): ?>
                            <tr>
                                <td><?= e($row['branch_name']) ?></td>
                                <td><?= e(format_amount((string) $row['target'])) ?></td>
                                <td><?= e(format_amount((string) $row['achieved'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($extraLocationRows as $row): ?>
                            <tr>
                                <td><?= e($row['branch_name']) ?></td>
                                <td><?= e(format_amount((string) $row['target'])) ?></td>
                                <td><?= e(format_amount((string) $row['achieved'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="empty">No data to display.</p>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
