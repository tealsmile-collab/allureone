<?php
declare(strict_types=1);

/**
 * Generate VAPID keys for config.php pwa section.
 * Run: php pwa_vapid_generate.php
 */

require_once __DIR__ . '/bootstrap.php';

$result = pwa_generate_vapid_keys();
if (!($result['ok'] ?? false)) {
    fwrite(STDERR, (string) ($result['error'] ?? 'Could not generate VAPID keys.') . "\n");
    exit(1);
}

echo "Add to config.php on your server:\n\n";
echo pwa_format_vapid_config_snippet([
    'public_key' => (string) $result['public_key'],
    'private_key' => (string) $result['private_key'],
]);
echo "\n";
