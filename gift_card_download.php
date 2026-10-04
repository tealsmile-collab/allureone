<?php
declare(strict_types=1);

// Capture any accidental output/warnings so they cannot corrupt the PDF binary.
ob_start();

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/gift_helpers.php';
require_once __DIR__ . '/includes/gift_card_pdf.php';
require_login();
require_not_franchise_officer_role();

$itemId = isset($_GET['gift']) ? (int) $_GET['gift'] : 0;
if ($itemId <= 0) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid gift item.';
    exit;
}

try {
    $pdo = wp_db();
    $wpPrefix = wp_table_prefix();
    $sql = "SELECT
            oi.order_item_id,
            oi.order_id,
            MAX(CASE WHEN oim.meta_key = '_ywgc_gift_card_code' THEN oim.meta_value END) AS gift_card_code,
            MAX(CASE WHEN oim.meta_key = '_ywgc_design' THEN oim.meta_value END) AS design_attachment_id,
            MAX(CASE WHEN oim.meta_key = '_ywgc_recipient_name' THEN oim.meta_value END) AS recipient_name,
            MAX(CASE WHEN oim.meta_key = 'Recipient Mobile' THEN oim.meta_value END) AS recipient_mobile,
            MAX(CASE WHEN oim.meta_key = '_ywgc_sender_name' THEN oim.meta_value END) AS sender_name,
            MAX(CASE WHEN oim.meta_key = '_ywgc_message' THEN oim.meta_value END) AS message,
            MAX(CASE WHEN oim.meta_key = '_line_total' THEN oim.meta_value END) AS amount,
            p.post_date,
            MAX(CASE WHEN pm.meta_key = '_billing_phone' THEN pm.meta_value END) AS buyer_phone,
            MAX(CASE WHEN pm.meta_key = 'billing_location' THEN pm.meta_value END) AS location
        FROM wp_woocommerce_order_items oi
        JOIN wp_woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
        JOIN wp_posts p ON p.ID = oi.order_id
        LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id
        WHERE oi.order_item_type = 'line_item'
          AND oi.order_item_id = :item_id
          AND oi.order_item_id IN (
              SELECT order_item_id
              FROM wp_woocommerce_order_itemmeta
              WHERE meta_key = '_ywgc_gift_card_code'
          )
        GROUP BY oi.order_item_id, oi.order_id, p.post_date, p.post_status
        LIMIT 1";
    $sql = str_replace('wp_', $wpPrefix, $sql);
    $st = $pdo->prepare($sql);
    $st->execute(['item_id' => $itemId]);
    $gift = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!is_array($gift)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Gift card not found.';
        exit;
    }

    $designAttachmentId = (int) ($gift['design_attachment_id'] ?? 0);
    $gift['design_image_url'] = $designAttachmentId > 0
        ? gift_design_image_url_from_attachment_id($pdo, $designAttachmentId, $wpPrefix)
        : '';

    $code = extract_gift_code((string) ($gift['gift_card_code'] ?? ''));
    $filename = 'allure-gift-card' . ($code !== '' ? '-' . preg_replace('/[^A-Za-z0-9\-]/', '', $code) : '-' . $itemId) . '.pdf';
    gift_card_pdf_output($gift, $filename);
    exit;
} catch (Throwable $e) {
    error_log('AllureOne gift card PDF failed: ' . $e->getMessage());
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Could not generate gift card PDF.';
    exit;
}
