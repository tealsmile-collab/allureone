<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app_path.php';
require_once __DIR__ . '/includes/pwa.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo json_encode(pwa_manifest_data(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
