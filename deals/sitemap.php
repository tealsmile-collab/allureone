<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once ROOT_PATH . '/includes/models/ProductModel.php';

header('Content-Type: application/xml; charset=utf-8');

$urls = [
    ['loc' => base_url(), 'priority' => '1.0'],
];

try {
    $db = Database::getInstance();
    $products = $db->query(
        'SELECT slug, updated_at FROM alluredeal_product WHERE is_active=1 AND is_deleted=0'
    )->fetchAll();
    foreach ($products as $p) {
        $urls[] = [
            'loc' => base_url('product/' . $p['slug']),
            'lastmod' => date('Y-m-d', strtotime((string) ($p['updated_at'] ?: 'now'))),
            'priority' => '0.8',
        ];
    }
    $policies = $db->query(
        'SELECT slug, updated_at FROM alluredeal_policy WHERE is_active=1 AND is_deleted=0'
    )->fetchAll();
    foreach ($policies as $p) {
        $urls[] = [
            'loc' => base_url('policy/' . $p['slug']),
            'lastmod' => date('Y-m-d', strtotime((string) ($p['updated_at'] ?: 'now'))),
            'priority' => '0.4',
        ];
    }
} catch (Throwable $e) {
    // empty sitemap fallback
}

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?= htmlspecialchars($u['loc'], ENT_XML1) ?></loc>
    <?php if (!empty($u['lastmod'])): ?><lastmod><?= e($u['lastmod']) ?></lastmod><?php endif; ?>
    <changefreq>daily</changefreq>
    <priority><?= e($u['priority']) ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
