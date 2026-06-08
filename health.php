<?php
declare(strict_types=1);

/**
 * Temporary diagnostics — open once after deploy, then delete this file.
 * Example: https://one.allurethaispa.in/health.php
 */

header('Content-Type: text/plain; charset=utf-8');

$checks = [];

$checks[] = 'PHP version: ' . PHP_VERSION . ' (need >= 8.0.0)';

$configPath = __DIR__ . '/config.php';
$checks[] = 'config.php: ' . (is_file($configPath) ? 'found' : 'MISSING — create from config.example.php');

$vendorAutoload = __DIR__ . '/vendor/autoload.php';
$checks[] = 'vendor/autoload.php: ' . (is_file($vendorAutoload) ? 'found' : 'MISSING — deploy vendor/ folder');

if (is_file($vendorAutoload)) {
    try {
        require_once $vendorAutoload;
        $checks[] = 'Composer autoload: OK';
        $checks[] = 'web-push class: ' . (class_exists(\Minishlink\WebPush\WebPush::class) ? 'OK' : 'not found');
    } catch (Throwable $e) {
        $checks[] = 'Composer autoload FAILED: ' . $e->getMessage();
    }
}

foreach (['openssl', 'mbstring', 'curl', 'json'] as $ext) {
    $checks[] = 'ext-' . $ext . ': ' . (extension_loaded($ext) ? 'loaded' : 'MISSING');
}

if (is_file($configPath)) {
    try {
        $config = require $configPath;
        $pwa = is_array($config['pwa'] ?? null) ? $config['pwa'] : [];
        $checks[] = 'config pwa section: ' . (isset($config['pwa']) ? 'present' : 'missing');
        $checks[] = 'vapid_public_key: ' . (trim((string) ($pwa['vapid_public_key'] ?? '')) !== '' ? 'set' : 'EMPTY');
        $checks[] = 'vapid_private_key: ' . (trim((string) ($pwa['vapid_private_key'] ?? '')) !== '' ? 'set' : 'EMPTY');
        require_once __DIR__ . '/includes/database.php';
        db()->query('SELECT 1');
        $checks[] = 'database: OK';
    } catch (Throwable $e) {
        $checks[] = 'database FAILED: ' . $e->getMessage();
    }
}

if (is_file(__DIR__ . '/includes/pwa.php')) {
    require_once __DIR__ . '/includes/pwa.php';
    $status = pwa_readiness_details();
    $checks[] = 'pwa ready: ' . (!empty($status['ready']) ? 'yes' : 'no');
    foreach ($status['issues'] as $issue) {
        $checks[] = '  - ' . $issue;
    }
}

$checks[] = 'bootstrap test:';
try {
    require_once __DIR__ . '/bootstrap.php';
    $checks[] = '  bootstrap.php loaded OK';
} catch (Throwable $e) {
    $checks[] = '  bootstrap FAILED: ' . $e->getMessage();
}

echo "AllureOne health check\n";
echo str_repeat('=', 40) . "\n";
echo implode("\n", $checks) . "\n";
echo str_repeat('=', 40) . "\n";
echo "Delete health.php after debugging.\n";
