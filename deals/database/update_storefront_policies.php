<?php
/**
 * Upsert storefront policy pages and hide unused footer policies.
 * CLI: php database/update_storefront_policies.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$db = Database::getInstance();

$upsert = $db->prepare(
    'INSERT INTO alluredeal_policy (slug, title, content, display_order, is_active, is_deleted)
     VALUES (?, ?, ?, ?, 1, 0)
     ON DUPLICATE KEY UPDATE
       title = VALUES(title),
       content = VALUES(content),
       display_order = VALUES(display_order),
       is_active = 1,
       is_deleted = 0,
       updated_at = NOW()'
);

$pages = [
    [
        'slug' => 'privacy-policy',
        'title' => 'Privacy Policy',
        'file' => 'privacy_policy.php',
        'order' => 1,
    ],
    [
        'slug' => 'terms-conditions',
        'title' => 'Terms & Conditions',
        'file' => 'terms_conditions.php',
        'order' => 2,
    ],
    [
        'slug' => 'cancellation-policy',
        'title' => 'Cancellation Policy and Refund Policy',
        'file' => 'cancellation_refund_policy.php',
        'order' => 3,
    ],
    [
        'slug' => 'digital-product-policy',
        'title' => 'Digital Product Policy',
        'file' => 'digital_product_policy.php',
        'order' => 4,
    ],
    [
        'slug' => 'payment-policy',
        'title' => 'Payment Policy',
        'file' => 'payment_policy.php',
        'order' => 5,
    ],
];

foreach ($pages as $page) {
    $content = $page['content'] ?? require ROOT_PATH . '/includes/content/' . $page['file'];
    $upsert->execute([$page['slug'], $page['title'], $content, $page['order']]);
    echo "Updated: {$page['title']}\n";
}

// Hide policies that should not appear in the footer
$hide = $db->prepare(
    'UPDATE alluredeal_policy
     SET is_active = 0, is_deleted = 1, updated_at = NOW()
     WHERE slug IN (?, ?, ?)'
);
$hide->execute(['refund-policy', 'no-refund-policy', 'gift-voucher-policy']);
echo "Hidden from footer: Refund Policy, No Refund Policy, Gift Voucher Policy\n";
echo "Done.\n";
