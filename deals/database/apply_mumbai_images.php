<?php
/**
 * Map Mumbai spa-services images onto 90 & 120 min products.
 * Sources:
 * https://allurethaispa.in/spa-services-in-mumbai/page/1/ ... /page/6/
 *
 * CLI: php database/apply_mumbai_images.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

/** Prefer full-size WP uploads (strip -300x300 etc.) */
function fullImageUrl(string $url): string
{
    $url = preg_replace('/-\d+x\d+(?=\.(jpg|jpeg|png|webp|gif)$)/i', '', $url) ?? $url;
    return $url;
}

/**
 * Title keywords from Mumbai catalog → image URL
 * https://allurethaispa.in/spa-services-in-mumbai/
 */
$catalog = [
    'swedish' => 'https://allurethaispa.in/wp-content/uploads/2025/01/Swedish-Massage-In-Mumbai-.png',
    'deep tissue' => 'https://allurethaispa.in/wp-content/uploads/2025/03/Deep-Tissue-Massage.png',
    'balinese' => 'https://allurethaispa.in/wp-content/uploads/2025/04/Balinese-Massage.jpeg',
    'aromatherapy' => 'https://allurethaispa.in/wp-content/uploads/2024/02/essential-oils-oil-fragrance-2918183.jpg',
    'aroma therapy' => 'https://allurethaispa.in/wp-content/uploads/2024/02/essential-oils-oil-fragrance-2918183.jpg',
    'thai dry' => 'https://allurethaispa.in/wp-content/uploads/2026/07/thai-dry-massage.jpg',
    'hot stone' => 'https://allurethaispa.in/wp-content/uploads/2024/02/stones-spa-massage-3184610.jpg',
    'potli' => 'https://allurethaispa.in/wp-content/uploads/2023/07/closeup-man-getting-back-massage-with-detox-herbal-balls-health-spa.jpg',
    'abhyanga' => 'https://allurethaispa.in/wp-content/uploads/2024/04/massaggio-ayurvedico-abhyanga.jpg',
    'snehanam' => 'https://allurethaispa.in/wp-content/uploads/2024/03/anuruddha-lokuhapuarachchi-77vZsyvV0bg-unsplash-e1712223441939.jpg',
    'couple' => 'https://allurethaispa.in/wp-content/uploads/2024/02/couplee-main.jpg',
    'tranquility' => 'https://allurethaispa.in/wp-content/uploads/2024/02/essential-oils-oil-fragrance-2918183.jpg',
    'body wrap' => 'https://allurethaispa.in/wp-content/uploads/2024/04/young-woman-having-treatment-beauty-salon.jpg',
    'full back' => 'https://allurethaispa.in/wp-content/uploads/2024/04/body-polishing-2.png',
    'foot reflexology' => 'https://allurethaispa.in/wp-content/uploads/2024/04/rune-enstad-qeuJczNo54w-unsplash-edited.jpg',
    'foot massage' => 'https://allurethaispa.in/wp-content/uploads/2024/08/Foot-Reflexology-A-Holistic-Healing-Approach.png',
    'head, neck' => 'https://allurethaispa.in/wp-content/uploads/2023/07/closeup-man-having-back-massage-during-spa-treatment-wellness-center-1-1.jpg',
    'indian head' => 'https://allurethaispa.in/wp-content/uploads/2024/03/2021-10-01.jpg',
    'herbal balm' => 'https://allurethaispa.in/wp-content/uploads/2023/08/pexels-cottonbro-studio-3997983.jpg',
    'allure signature' => 'https://allurethaispa.in/wp-content/uploads/2024/04/balinese-massage.jpg',
];

// Normalize catalog URLs
foreach ($catalog as $k => $url) {
    $catalog[$k] = fullImageUrl($url);
}

function matchImage(string $productName, array $catalog): ?string
{
    $name = strtolower($productName);
    // Longer keys first for better specificity
    $keys = array_keys($catalog);
    usort($keys, static fn ($a, $b) => strlen($b) <=> strlen($a));
    foreach ($keys as $key) {
        if (str_contains($name, $key)) {
            return $catalog[$key];
        }
    }
    return null;
}

$products = $db->query(
    'SELECT id, name, duration, image
     FROM alluredeal_product
     WHERE is_deleted = 0 AND is_active = 1
       AND duration IN (90, 120)
     ORDER BY duration, name'
)->fetchAll();

$upd = $db->prepare('UPDATE alluredeal_product SET image = ?, updated_at = NOW() WHERE id = ?');
$updated = 0;
$skipped = 0;

foreach ($products as $p) {
    $img = matchImage((string) $p['name'], $catalog);
    if (!$img) {
        echo "SKIP (no map): {$p['name']}\n";
        $skipped++;
        continue;
    }
    $upd->execute([$img, (int) $p['id']]);
    echo "OK {$p['duration']}m: {$p['name']}\n  → {$img}\n";
    $updated++;
}

echo "\nUpdated {$updated} products (90/120 min). Skipped {$skipped}.\n";
