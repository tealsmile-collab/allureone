<?php
/**
 * Upsert full Privacy Policy content into alluredeal_policy.
 * CLI: php database/update_privacy_policy.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$content = require ROOT_PATH . '/includes/content/privacy_policy.php';
$db = Database::getInstance();

$stmt = $db->prepare(
    'INSERT INTO alluredeal_policy (slug, title, content, display_order, is_active, is_deleted)
     VALUES (?, ?, ?, 1, 1, 0)
     ON DUPLICATE KEY UPDATE
       title = VALUES(title),
       content = VALUES(content),
       display_order = VALUES(display_order),
       is_active = 1,
       is_deleted = 0,
       updated_at = NOW()'
);
$stmt->execute(['privacy-policy', 'Privacy Policy', $content]);

echo "Privacy Policy updated successfully.\n";
