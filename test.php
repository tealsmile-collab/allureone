<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

$testHttp = null;
$testBody = '';
$testError = '';
$testUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_get_all_business'])) {
    if (!csrf_validate($_POST['_csrf'] ?? null)) {
        $testError = 'Invalid session. Refresh the page and try again.';
    } else {
        $config = require __DIR__ . '/config.php';
        $testUrl = (string) (($config['dingg']['get_all_business_url'] ?? 'https://api.dingg.app/api/v1/vendor/get_all_business?by_group=false'));
        $token = dingg_resolve_pos_token_for_api();
        if ($token === null || $token === '') {
            $testError = 'No Dingg POS token. Sign in again or check allureone_keys / Dingg config.';
        } else {
            $resp = dingg_http_request_authenticated('GET', $testUrl, $token, null);
            $testHttp = (int) ($resp['http'] ?? 0);
            $testBody = (string) ($resp['body'] ?? '');
        }
    }
}

$pageTitle = 'API test';
$activeNav = '';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="card">
    <div class="card__head">
        <span>get_all_business</span>
    </div>
    <div class="card__body" style="padding:1.25rem">
        <p class="main__meta" style="margin-top:0">Calls Dingg <code>GET get_all_business</code> on the server (same as the app). Requires a valid POS token.</p>
        <form method="post" action="test.php" style="margin-bottom:1rem">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <button class="btn btn--primary" type="submit" name="run_get_all_business" value="1">Call get_all_business</button>
            <a class="btn btn--ghost" href="dashboard.php" style="margin-left:0.5rem">Back to dashboard</a>
        </form>

        <?php if ($testError !== ''): ?>
            <p class="alert alert--error" role="alert"><?= e($testError) ?></p>
        <?php endif; ?>

        <?php if ($testHttp !== null): ?>
            <p class="main__meta" style="margin:0 0 0.5rem"><strong>HTTP <?= $testHttp ?></strong></p>
            <?php if ($testUrl !== ''): ?>
                <p class="main__meta" style="margin:0 0 0.75rem;font-size:0.85rem;word-break:break-all"><?= e($testUrl) ?></p>
            <?php endif; ?>
            <?php
            $decoded = json_decode($testBody, true);
            $display = is_array($decoded)
                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : $testBody;
            ?>
            <pre class="invoice-api-json" style="max-height:70vh;overflow:auto"><?= e($display) ?></pre>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
