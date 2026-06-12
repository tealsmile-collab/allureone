<?php
declare(strict_types=1);

/**
 * Gift card sale notification cron.
 * Sends push announcements only when there is at least one new gift card sale today.
 * Run twice daily on Hostinger, e.g.:
 *   php /home/.../public_html/wp-giftcard-cron.php
 * or:
 *   curl -sS "https://one.example.com/wp-giftcard-cron.php?key=YOUR_SECRET"
 */
$isCli = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
}

$config = require __DIR__ . '/config.php';
$appCfg = is_array($config['app'] ?? null) ? $config['app'] : [];
$expectedKey = trim((string) ($appCfg['giftcard_cron_key'] ?? ''));
if (!$isCli) {
    $providedKey = trim((string) ($_GET['key'] ?? $_POST['key'] ?? ''));
    if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$tz = trim((string) ($appCfg['giftcard_cron_timezone'] ?? 'Asia/Kolkata'));
if ($tz === '') {
    $tz = 'Asia/Kolkata';
}
try {
    date_default_timezone_set($tz);
} catch (Throwable $e) {
    date_default_timezone_set('Asia/Kolkata');
}

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/gift_helpers.php';

$saleDate = date('Y-m-d');
$rawSales = gift_fetch_wp_sales_for_date($saleDate);
$sales = [];
foreach ($rawSales as $sale) {
    if ((int) ($sale['order_item_id'] ?? 0) <= 0) {
        continue;
    }
    $sales[] = $sale;
}

$summary = [
    'ok' => true,
    'date' => $saleDate,
    'timezone' => date_default_timezone_get(),
    'sales_found' => count($sales),
    'notifications_sent' => 0,
    'skipped_already_notified' => 0,
    'skipped_no_recipients' => 0,
    'failed' => 0,
    'details' => [],
];

if ($sales === []) {
    $summary['message'] = 'No gift card sales for today. No notification sent.';
    echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$newSales = [];
foreach ($sales as $sale) {
    $orderItemId = (int) ($sale['order_item_id'] ?? 0);
    if ($orderItemId > 0 && !gift_sale_already_notified($orderItemId)) {
        $newSales[] = $sale;
    }
}

if ($newSales === []) {
    $summary['message'] = 'Gift card sales found but already notified. No notification sent.';
    $summary['skipped_already_notified'] = count($sales);
    echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$giftCodesUrl = allureone_url('gift_codes.php');

foreach ($newSales as $sale) {
    $orderItemId = (int) ($sale['order_item_id'] ?? 0);
    $orderId = (int) ($sale['order_id'] ?? 0);
    $amount = (float) ($sale['amount'] ?? 0);
    $location = trim((string) ($sale['location'] ?? ''));

    if ($orderItemId <= 0) {
        continue;
    }

    if (gift_sale_already_notified($orderItemId)) {
        $summary['skipped_already_notified']++;
        $summary['details'][] = [
            'order_item_id' => $orderItemId,
            'status' => 'skipped',
            'reason' => 'already_notified',
        ];
        continue;
    }

    $targetUserIds = gift_notification_user_ids_for_sale($location);
    if ($targetUserIds === []) {
        $summary['skipped_no_recipients']++;
        $summary['details'][] = [
            'order_item_id' => $orderItemId,
            'status' => 'skipped',
            'reason' => 'no_recipients',
            'location' => $location,
        ];
        continue;
    }

    $message = gift_format_sale_notification_message($amount, $location);
    $result = pwa_send_announcement(
        $message,
        0,
        'Gift Card Cron',
        $targetUserIds,
        'cron',
        $giftCodesUrl,
        'Gift Card Sale'
    );

    if (!($result['ok'] ?? false)) {
        $summary['failed']++;
        $summary['details'][] = [
            'order_item_id' => $orderItemId,
            'status' => 'failed',
            'error' => (string) ($result['error'] ?? 'Could not send announcement.'),
            'location' => $location,
            'amount' => $amount,
        ];
        continue;
    }

    $announcementId = (int) ($result['announcement_id'] ?? 0);
    gift_mark_sale_notified($orderItemId, $orderId, $announcementId);
    $summary['notifications_sent']++;
    $summary['details'][] = [
        'order_item_id' => $orderItemId,
        'status' => 'sent',
        'announcement_id' => $announcementId,
        'location' => $location,
        'amount' => $amount,
        'recipients' => count($targetUserIds),
        'push_sent' => (int) ($result['sent'] ?? 0),
        'push_failed' => (int) ($result['failed'] ?? 0),
    ];
}

echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
