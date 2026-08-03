<?php
declare(strict_types=1);
require_once __DIR__ . '/_init.php';
$db = Database::getInstance();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && Security::verifyCsrf()) {
    foreach ($_POST['settings'] ?? [] as $key => $value) {
        $db->prepare(
            'INSERT INTO alluredeal_settings (setting_key, setting_value, updated_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)'
        )->execute([$key, $value, Auth::id()]);
    }
    $msg = 'Settings saved. Note: Razorpay/Gallabox secrets are managed in config.php for security.';
}

$settings = [];
foreach ($db->query('SELECT setting_key, setting_value FROM alluredeal_settings')->fetchAll() as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

admin_header('Settings', 'settings');
?>
<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<div class="panel" style="max-width:720px">
  <h2>Global Application Settings</h2>
  <form method="post" class="vstack gap-3">
    <?= Security::csrfField() ?>
    <div><label class="form-label">Site Tagline</label>
      <input class="form-control" name="settings[site_tagline]" value="<?= e($settings['site_tagline'] ?? '') ?>"></div>
    <div><label class="form-label">Hero Autoplay (ms)</label>
      <input class="form-control" name="settings[hero_autoplay_ms]" value="<?= e($settings['hero_autoplay_ms'] ?? '5000') ?>"></div>
    <div><label class="form-label">Order Prefix</label>
      <input class="form-control" name="settings[order_prefix]" value="<?= e($settings['order_prefix'] ?? 'AD') ?>"></div>
    <div><label class="form-label">Invoice Prefix</label>
      <input class="form-control" name="settings[invoice_prefix]" value="<?= e($settings['invoice_prefix'] ?? 'ATS') ?>"></div>
    <hr>
    <p class="text-muted mb-1">Read-only from <code>config.php</code>:</p>
    <ul>
      <li>Company: <?= e((string) config('company_name')) ?></li>
      <li>GST: <?= e((string) config('gst_percent')) ?>% (included in product &amp; deal prices)</li>
      <li>Currency: <?= e((string) config('currency')) ?></li>
      <li>Support: <?= e((string) config('support_email')) ?> / <?= e((string) config('support_phone')) ?></li>
      <li>Razorpay Key: <?= e((string) config('razorpay.key_id')) ?></li>
      <li>Gallabox Channel: <?= e((string) config('gallabox.channel_id')) ?></li>
    </ul>
    <button class="btn btn-brand">Save Settings</button>
  </form>
</div>
<?php admin_footer(); ?>
