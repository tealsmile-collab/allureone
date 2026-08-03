<?php
/**
 * Today's Deals = all products with duration >= 60 min @ 22% off, no expiry.
 * CLI: php database/setup_60min_deals.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

try {
    $db->exec('ALTER TABLE alluredeal_todaydeal MODIFY ends_at DATETIME NULL DEFAULT NULL');
    echo "ends_at is nullable (no expiry supported).\n";
} catch (Throwable $e) {
    echo "ALTER note: " . $e->getMessage() . "\n";
}

$discountPercent = 22.0;
$factor = 1 - ($discountPercent / 100); // 0.78

// Clear previous deal flags & rows
$db->exec('UPDATE alluredeal_product SET is_today_deal = 0 WHERE is_deleted = 0');
$db->exec('UPDATE alluredeal_todaydeal SET is_deleted = 1, is_active = 0 WHERE is_deleted = 0');

$products = $db->query(
    'SELECT id, name, offer_price, original_price, discount_percent
     FROM alluredeal_product
     WHERE duration >= 60 AND is_active = 1 AND is_deleted = 0
     ORDER BY duration ASC, display_order ASC, name ASC'
)->fetchAll();

if (!$products) {
    echo "No products with duration >= 60 found.\n";
    exit(0);
}

$upd = $db->prepare(
    'UPDATE alluredeal_product
     SET original_price = ?, offer_price = ?, discount_percent = ?,
         is_today_deal = 1, auto_strike_price = 1, updated_at = NOW()
     WHERE id = ?'
);
$ins = $db->prepare(
    'INSERT INTO alluredeal_todaydeal
     (product_id, badge_text, discount_percent, deal_price, starts_at, ends_at,
      show_countdown, display_order, is_active, created_at)
     VALUES (?, ?, ?, ?, NOW(), NULL, 0, ?, 1, NOW())'
);

$n = 0;
foreach ($products as $i => $p) {
    $offer = (float) $p['offer_price'];
    $originalExisting = (float) $p['original_price'];
    $currentDisc = round((float) ($p['discount_percent'] ?? 0), 2);

    // Our deal scripts set discount to 20 or 22 with original = menu MRP.
    // Import may inflate original while keeping menu rate in offer_price.
    if (in_array($currentDisc, [20.0, 22.0], true) && $originalExisting > $offer) {
        $listPrice = $originalExisting; // restore menu from previous deal run
    } else {
        $listPrice = $offer > 0 ? $offer : $originalExisting; // menu rate
    }

    $dealPrice = round($listPrice * $factor, 2);
    $original = $listPrice;

    $upd->execute([$original, $dealPrice, $discountPercent, (int) $p['id']]);
    $ins->execute([(int) $p['id'], "Today's Deal", $discountPercent, $dealPrice, $i + 1]);

    echo sprintf(
        "DEAL: %s | was ₹%s → now ₹%s (%.0f%% off) | no expiry\n",
        $p['name'],
        number_format($original, 2),
        number_format($dealPrice, 2),
        $discountPercent
    );
    $n++;
}

echo "\nToday's Deals configured: {$n} products (duration >= 60 Min, {$discountPercent}% off, no expiry).\n";
