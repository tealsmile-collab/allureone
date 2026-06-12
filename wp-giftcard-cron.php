<?php
declare(strict_types=1);

/**
 * Gift card sale notification cron.
 * Sends one push announcement per new gift card sale in the reporting window
 * (default: previous day 9:00 PM IST through current run time).
 * Run once daily on Hostinger at 9 PM IST, e.g.:
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
$runHour = (int) ($appCfg['giftcard_cron_run_hour'] ?? 21);
try {
    date_default_timezone_set($tz !== '' ? $tz : 'Asia/Kolkata');
} catch (Throwable $e) {
    date_default_timezone_set('Asia/Kolkata');
}

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/gift_helpers.php';

$window = gift_cron_reporting_window($tz !== '' ? $tz : 'Asia/Kolkata', $runHour);
$rawSales = gift_fetch_wp_sales_between($window['start'], $window['end']);
$sales = [];
foreach ($rawSales as $sale) {
    if ((int) ($sale['order_item_id'] ?? 0) <= 0) {
        continue;
    }
    $sales[] = $sale;
}

$summary = [
    'ok' => true,
    'window_start' => $window['start'],
    'window_end' => $window['end'],
    'timezone' => $window['timezone'],
    'run_hour' => $window['run_hour'],
    'sales_found' => count($sales),
    'notifications_sent' => 0,
    'skipped_already_notified' => 0,
    'skipped_no_recipients' => 0,
    'failed' => 0,
    'details' => [],
];

if ($sales === []) {
    $summary['message'] = 'No gift card sales in reporting window. No notification sent.';
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

foreach ($newSales as $sale) {
    $orderItemId = (int) ($sale['order_item_id'] ?? 0);
    $orderId = (int) ($sale['order_id'] ?? 0);
    $amount = (float) ($sale['amount'] ?? 0);
    $location = trim((string) ($sale['location'] ?? ''));
    $giftCode = trim((string) ($sale['gift_code'] ?? ''));

    if ($orderItemId <= 0) {
        continue;
    }

    if (gift_sale_already_notified($orderItemId)) {
        $summary['skipped_already_notified']++;
        $summary['details'][] = [
            'order_item_id' => $orderItemId,
            'gift_code' => $giftCode,
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
            'gift_code' => $giftCode,
            'status' => 'skipped',
            'reason' => 'no_recipients',
            'location' => $location,
        ];
        continue;
    }

    $message = gift_format_sale_notification_message($amount, $location);
    $giftDetailUrl = allureone_url('gift_codes.php?gift=' . $orderItemId);
    $result = pwa_send_announcement(
        $message,
        0,
        'Gift Card Cron',
        $targetUserIds,
        'cron',
        $giftDetailUrl,
        'Gift Card Sale'
    );

    if (!($result['ok'] ?? false)) {
        $summary['failed']++;
        $summary['details'][] = [
            'order_item_id' => $orderItemId,
            'gift_code' => $giftCode,
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
        'gift_code' => $giftCode,
        'status' => 'sent',
        'announcement_id' => $announcementId,
        'location' => $location,
        'amount' => $amount,
        'recipients' => count($targetUserIds),
        'push_sent' => (int) ($result['sent'] ?? 0),
        'push_failed' => (int) ($result['failed'] ?? 0),
        'url' => $giftDetailUrl,
    ];
}

echo json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
