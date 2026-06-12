<?php
declare(strict_types=1);

function format_purchase_date(?string $dt): string
{
    if ($dt === null || $dt === '') {
        return '—';
    }
    $t = strtotime($dt);
    if ($t === false) {
        return '—';
    }
    return date('d-M-y', $t);
}

function extract_gift_code(?string $raw): string
{
    if ($raw === null || $raw === '') {
        return '';
    }
    if (preg_match('/\"([A-Z0-9\\-]{8,})\"/', $raw, $m) === 1) {
        return $m[1];
    }
    return $raw;
}

function format_amount($amount): string
{
    if ($amount === null || $amount === '') {
        return 'Rs 0.00';
    }
    return 'Rs ' . number_format((float) $amount, 2, '.', '');
}

function extract_email_value(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }
    if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
        return $raw;
    }
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $raw, $m) === 1) {
        return $m[0];
    }
    return '';
}

/**
 * When true, gift card lists only include orders whose WooCommerce billing_location matches the user's branch locality.
 * When false (default), all gift card line items are listed (branch locality must still be loaded for other uses).
 */
function gift_cards_filter_by_branch_locality_enabled(): bool
{
    $config = require __DIR__ . '/../config.php';

    return (bool) (($config['app']['filter_gift_cards_by_branch_locality'] ?? false));
}

/**
 * Gift code price for Dingg voucher sync: multiple of 1000 as-is;
 * else next thousand when 5% below; else original amount.
 */
function gift_compute_code_price(int $amountRupees): int
{
    if ($amountRupees <= 0) {
        return 0;
    }
    if ($amountRupees % 1000 === 0) {
        return $amountRupees;
    }
    $nextThousand = (int) (ceil($amountRupees / 1000) * 1000);
    $diff = $nextThousand - $amountRupees;
    if ($diff * 100 === $nextThousand * 5) {
        return $nextThousand;
    }

    return $amountRupees;
}

function gift_branch_id_from_locality(string $locality): int
{
    $loc = trim($locality);
    if ($loc === '') {
        return 0;
    }
    try {
        $st = db()->prepare(
            'SELECT id
             FROM allureone_branch
             WHERE isActive = 1
               AND LOWER(TRIM(locality)) = LOWER(TRIM(:locality))
             ORDER BY id ASC
             LIMIT 1'
        );
        $st->execute(['locality' => $loc]);

        return (int) ($st->fetchColumn() ?: 0);
    } catch (PDOException $e) {
        error_log('AllureOne gift branch locality lookup failed: ' . $e->getMessage());
    }

    return 0;
}

function gift_branch_session_key(int $branchId): string
{
    if ($branchId <= 0) {
        return '';
    }
    try {
        $st = db()->prepare(
            'SELECT session_key
             FROM allureone_session_data
             WHERE branch_id = :branch_id
             ORDER BY updated_date DESC
             LIMIT 1'
        );
        $st->execute(['branch_id' => $branchId]);

        return trim((string) ($st->fetchColumn() ?: ''));
    } catch (PDOException $e) {
        error_log('AllureOne gift branch session key lookup failed: ' . $e->getMessage());
    }

    return '';
}

/**
 * @param list<array<string,mixed>> $voucherItems
 */
function gift_voucher_type_names_csv(array $voucherItems): string
{
    $names = [];
    foreach ($voucherItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $name = trim((string) ($item['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return implode(', ', $names);
}

/**
 * @param list<array<string,mixed>> $voucherItems
 * @return array{id:int, name:string}|null
 */
function gift_find_voucher_by_code_price(int $giftCodePrice, array $voucherItems): ?array
{
    if ($giftCodePrice <= 0) {
        return null;
    }
    foreach ($voucherItems as $item) {
        if (!is_array($item)) {
            continue;
        }
        $price = (int) round((float) ($item['actual_price'] ?? 0));
        if ($price !== $giftCodePrice) {
            continue;
        }
        $id = (int) ($item['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        return [
            'id' => $id,
            'name' => trim((string) ($item['name'] ?? '')),
        ];
    }

    return null;
}

function gift_voucher_type_api_url(): string
{
    return 'https://api.dingg.app/api/v1/vendor/voucher_type';
}

/**
 * @return array{ok:bool, items:list<array<string,mixed>>, error:string}
 */
function gift_fetch_voucher_type_items(string $sessionKey): array
{
    if ($sessionKey === '') {
        return ['ok' => false, 'items' => [], 'error' => 'Missing session key.'];
    }
    $resp = dingg_http_request_authenticated('GET', gift_voucher_type_api_url(), $sessionKey, null);
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');
    if ($http < 200 || $http >= 300 || $body === '' || dingg_response_looks_unauthorized($http, $body)) {
        return ['ok' => false, 'items' => [], 'error' => 'Could not fetch voucher types from Dingg.'];
    }
    $json = json_decode($body, true);
    $items = is_array($json) && isset($json['data']) && is_array($json['data']) ? $json['data'] : [];

    return ['ok' => true, 'items' => $items, 'error' => ''];
}

/**
 * @return array<string, mixed>
 */
function gift_create_voucher_type_payload(int $giftCodePrice): array
{
    $price = (int) $giftCodePrice;

    return [
        'type' => 'G',
        'name' => 'GiftCard - ' . $price,
        'duration' => 180,
        'actual_price' => $price,
        'offer_price' => $price,
        'description' => 'online sale ' . $price,
        'apply_tax' => false,
        'is_tax_inclusive' => true,
        'tax_on_redeem' => false,
        'redeem_on_total' => true,
        'expire_by_date' => false,
        'visibility' => false,
        'is_online' => false,
        'emp_percent_on_redeem' => 100,
        'max_number_codes' => -1,
        'validity_start_on' => 'activate',
        'apply_on' => ['service'],
    ];
}

/**
 * @return array{ok:bool, error:string}
 */
function gift_create_voucher_type(string $sessionKey, int $giftCodePrice): array
{
    if ($sessionKey === '' || $giftCodePrice <= 0) {
        return ['ok' => false, 'error' => 'Invalid session or gift code price.'];
    }
    $payload = json_encode(gift_create_voucher_type_payload($giftCodePrice), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return ['ok' => false, 'error' => 'Could not build voucher create payload.'];
    }
    $resp = dingg_http_request_authenticated('POST', gift_voucher_type_api_url(), $sessionKey, $payload);
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');
    if ($http < 200 || $http >= 300 || dingg_response_looks_unauthorized($http, $body)) {
        return ['ok' => false, 'error' => 'Could not create gift voucher in Dingg.'];
    }

    return ['ok' => true, 'error' => ''];
}

/**
 * Lookup voucher by giftcodeprice; create via API if missing, then re-fetch.
 *
 * @return array{
 *   found:bool,
 *   created:bool,
 *   giftcodeprice:int,
 *   voucher_id?:int,
 *   voucher_name?:string,
 *   voucher_type_names?:string,
 *   message?:string,
 *   error?:string
 * }
 */
function gift_sync_voucher_for_code_price(string $sessionKey, int $giftCodePrice): array
{
    $result = [
        'found' => false,
        'created' => false,
        'giftcodeprice' => $giftCodePrice,
    ];
    $fetch = gift_fetch_voucher_type_items($sessionKey);
    if (!$fetch['ok']) {
        $result['error'] = $fetch['error'];

        return $result;
    }
    $items = $fetch['items'];
    $result['voucher_type_names'] = gift_voucher_type_names_csv($items);
    $match = gift_find_voucher_by_code_price($giftCodePrice, $items);
    if ($match !== null) {
        $result['found'] = true;
        $result['voucher_id'] = (int) ($match['id'] ?? 0);
        $result['voucher_name'] = (string) ($match['name'] ?? '');

        return $result;
    }

    $create = gift_create_voucher_type($sessionKey, $giftCodePrice);
    if (!$create['ok']) {
        $result['message'] = 'Gift code voucher not found';
        $result['error'] = $create['error'];

        return $result;
    }
    $result['created'] = true;

    $refetch = gift_fetch_voucher_type_items($sessionKey);
    if (!$refetch['ok']) {
        $result['message'] = 'Voucher created but could not verify.';
        $result['error'] = $refetch['error'];

        return $result;
    }
    $items = $refetch['items'];
    $result['voucher_type_names'] = gift_voucher_type_names_csv($items);
    $match = gift_find_voucher_by_code_price($giftCodePrice, $items);
    if ($match !== null) {
        $result['found'] = true;
        $result['voucher_id'] = (int) ($match['id'] ?? 0);
        $result['voucher_name'] = (string) ($match['name'] ?? '');

        return $result;
    }

    $result['message'] = 'Gift code voucher not found after creation.';

    return $result;
}

/**
 * @return array{ok:bool, exists:bool, error:string}
 */
function gift_gift_card_exists_in_dingg(string $sessionKey, string $giftCode): array
{
    $code = trim($giftCode);
    if ($sessionKey === '' || $code === '') {
        return ['ok' => false, 'exists' => false, 'error' => 'Invalid session or gift code.'];
    }
    $url = 'https://api.dingg.app/api/v1/vendor/members?' . http_build_query(
        [
            'type' => 'gift-card',
            'limit' => 10,
            'page' => 1,
            'search' => $code,
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
    $resp = dingg_http_request_authenticated('GET', $url, $sessionKey, null);
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');
    if ($http < 200 || $http >= 300 || $body === '' || dingg_response_looks_unauthorized($http, $body)) {
        return ['ok' => false, 'exists' => false, 'error' => 'Could not check gift code in Dingg.'];
    }
    $json = json_decode($body, true);
    $list = is_array($json) && isset($json['list']) && is_array($json['list']) ? $json['list'] : [];
    $codeNorm = strtoupper($code);
    foreach ($list as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowCode = trim((string) ($row['code'] ?? ''));
        if ($rowCode !== '' && strtoupper($rowCode) === $codeNorm) {
            return ['ok' => true, 'exists' => true, 'error' => ''];
        }
    }

    return ['ok' => true, 'exists' => false, 'error' => ''];
}

function gift_import_gift_codes_api_url(): string
{
    return 'https://api.dingg.app/api/v1/vendor/gift-card/import-gift-codes';
}

/**
 * @return array{ok:bool, error:string}
 */
function gift_import_gift_code_to_dingg(
    string $sessionKey,
    string $giftCode,
    int $giftCodePrice,
    int $voucherTypeId,
    string $voucherTypeName
): array {
    $code = trim($giftCode);
    if ($sessionKey === '' || $code === '' || $giftCodePrice <= 0 || $voucherTypeId <= 0) {
        return ['ok' => false, 'error' => 'Invalid gift code import parameters.'];
    }
    $title = trim($voucherTypeName);
    if ($title === '') {
        $title = 'GiftCard - ' . $giftCodePrice;
    }
    $payload = json_encode([
        'import_giftcodes' => [
            [
                'title' => $title,
                'code' => $code,
                'gift_value' => $giftCodePrice,
                'type_id' => $voucherTypeId,
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return ['ok' => false, 'error' => 'Could not build gift code import payload.'];
    }
    $resp = dingg_http_request_authenticated('POST', gift_import_gift_codes_api_url(), $sessionKey, $payload);
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');
    if ($http < 200 || $http >= 300 || dingg_response_looks_unauthorized($http, $body)) {
        return ['ok' => false, 'error' => 'Could not import gift code into Dingg.'];
    }

    return ['ok' => true, 'error' => ''];
}

/**
 * @return array{gift_code:string, amount:float}|null
 */
function gift_fetch_order_item_for_dingg_sync(int $orderItemId): ?array
{
    if ($orderItemId <= 0) {
        return null;
    }
    try {
        $pdo = wp_db();
        $wpPrefix = wp_table_prefix();
        $sql = "SELECT
                    MAX(CASE WHEN oim.meta_key = '_ywgc_gift_card_code' THEN oim.meta_value END) AS gift_card_code,
                    MAX(CASE WHEN oim.meta_key = '_line_total' THEN oim.meta_value END) AS amount
                FROM wp_woocommerce_order_itemmeta oim
                WHERE oim.order_item_id = :item_id";
        $sql = str_replace('wp_', $wpPrefix, $sql);
        $st = $pdo->prepare($sql);
        $st->execute(['item_id' => $orderItemId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $giftCode = extract_gift_code((string) ($row['gift_card_code'] ?? ''));
        if ($giftCode === '') {
            return null;
        }

        return [
            'gift_code' => $giftCode,
            'amount' => (float) ($row['amount'] ?? 0),
        ];
    } catch (PDOException $e) {
        error_log('AllureOne gift order item dingg sync lookup failed: ' . $e->getMessage());

        return null;
    }
}

/**
 * Dingg voucher + gift code sync before mark redeemed.
 *
 * @return array{ok:bool, messages:list<string>, error:string}
 */
function gift_run_dingg_sync_for_redeem(string $redeemedLocation, string $giftCode, int $giftCodePrice): array
{
    $messages = [];
    $loc = trim($redeemedLocation);
    $code = trim($giftCode);
    if ($loc === '') {
        return ['ok' => false, 'messages' => [], 'error' => 'Select a redeemed location.'];
    }
    if ($code === '') {
        return ['ok' => false, 'messages' => [], 'error' => 'Gift code is missing.'];
    }
    if ($giftCodePrice <= 0) {
        return ['ok' => false, 'messages' => [], 'error' => 'Invalid gift code price.'];
    }

    $syncBranchId = gift_branch_id_from_locality($loc);
    if ($syncBranchId <= 0) {
        return ['ok' => false, 'messages' => [], 'error' => 'Could not resolve branch for redeemed location.'];
    }
    $sessionKey = gift_branch_session_key($syncBranchId);
    if ($sessionKey === '') {
        return ['ok' => false, 'messages' => [], 'error' => 'Dingg session key is not configured for the selected redeemed location branch.'];
    }

    $syncResult = gift_sync_voucher_for_code_price($sessionKey, $giftCodePrice);
    if (isset($syncResult['error']) && ($syncResult['error'] ?? '') !== '' && !($syncResult['found'] ?? false)) {
        return ['ok' => false, 'messages' => [], 'error' => (string) $syncResult['error']];
    }
    if (!($syncResult['found'] ?? false)) {
        return [
            'ok' => false,
            'messages' => [],
            'error' => (string) ($syncResult['message'] ?? 'Gift code voucher not found'),
        ];
    }

    $voucherId = (int) ($syncResult['voucher_id'] ?? 0);
    $voucherName = (string) ($syncResult['voucher_name'] ?? '');
    if ($voucherId > 0) {
        $voucherLabel = trim($voucherName) !== '' ? $voucherName : ('Voucher #' . $voucherId);
        $prefix = ($syncResult['created'] ?? false) ? 'Created voucher: ' : 'Voucher: ';
        $messages[] = $prefix . $voucherLabel . ' (' . $voucherId . ').';
    }

    $existsCheck = gift_gift_card_exists_in_dingg($sessionKey, $code);
    if (!$existsCheck['ok']) {
        return ['ok' => false, 'messages' => $messages, 'error' => (string) ($existsCheck['error'] ?? 'Could not check gift code in Dingg.')];
    }
    if ($existsCheck['exists'] ?? false) {
        $messages[] = 'gift code already exists in Dingg';

        return ['ok' => true, 'messages' => $messages, 'error' => ''];
    }

    $importResult = gift_import_gift_code_to_dingg($sessionKey, $code, $giftCodePrice, $voucherId, $voucherName);
    if (!$importResult['ok']) {
        return ['ok' => false, 'messages' => $messages, 'error' => (string) ($importResult['error'] ?? 'Could not import gift code into Dingg.')];
    }
    $messages[] = 'created gift code in Dingg';

    return ['ok' => true, 'messages' => $messages, 'error' => ''];
}

function gift_ensure_sale_notification_table(): void
{
    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS allureone_giftcard_sale_notifications (
                order_item_id BIGINT NOT NULL,
                order_id BIGINT NOT NULL,
                announcement_id INT NULL,
                notified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (order_item_id),
                KEY idx_gift_sale_notified_at (notified_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (PDOException $e) {
        error_log('AllureOne gift sale notification table ensure failed: ' . $e->getMessage());
    }
}

function gift_sale_already_notified(int $orderItemId): bool
{
    if ($orderItemId <= 0) {
        return true;
    }
    gift_ensure_sale_notification_table();
    try {
        $st = db()->prepare(
            'SELECT 1 FROM allureone_giftcard_sale_notifications WHERE order_item_id = :id LIMIT 1'
        );
        $st->execute(['id' => $orderItemId]);

        return (bool) $st->fetchColumn();
    } catch (PDOException $e) {
        error_log('AllureOne gift sale notified check failed: ' . $e->getMessage());

        return false;
    }
}

function gift_mark_sale_notified(int $orderItemId, int $orderId, int $announcementId): void
{
    if ($orderItemId <= 0) {
        return;
    }
    gift_ensure_sale_notification_table();
    try {
        $st = db()->prepare(
            'INSERT INTO allureone_giftcard_sale_notifications (order_item_id, order_id, announcement_id)
             VALUES (:order_item_id, :order_id, :announcement_id)
             ON DUPLICATE KEY UPDATE
                order_id = VALUES(order_id),
                announcement_id = VALUES(announcement_id),
                notified_at = CURRENT_TIMESTAMP'
        );
        $st->execute([
            'order_item_id' => $orderItemId,
            'order_id' => $orderId,
            'announcement_id' => $announcementId > 0 ? $announcementId : null,
        ]);
    } catch (PDOException $e) {
        error_log('AllureOne gift sale notified insert failed: ' . $e->getMessage());
    }
}

/**
 * WooCommerce gift card line items for a calendar date (Y-m-d), same source as gift_codes.php.
 *
 * @return list<array{order_item_id:int, order_id:int, amount:float, location:string, post_date:string}>
 */
function gift_fetch_wp_sales_for_date(string $dateYmd): array
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd) !== 1) {
        return [];
    }

    try {
        $pdo = wp_db();
        $wpPrefix = wp_table_prefix();
        $sql = "SELECT
                oi.order_item_id,
                oi.order_id,
                MAX(CASE WHEN oim.meta_key = '_line_total' THEN oim.meta_value END) AS amount,
                p.post_date,
                MAX(CASE WHEN pm.meta_key = 'billing_location' THEN pm.meta_value END) AS location
            FROM wp_woocommerce_order_items oi
            JOIN wp_woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
            JOIN wp_posts p ON p.ID = oi.order_id
            LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id
            WHERE oi.order_item_type = 'line_item'
              AND oi.order_item_id IN (
                  SELECT order_item_id
                  FROM wp_woocommerce_order_itemmeta
                  WHERE meta_key = '_ywgc_gift_card_code'
              )
              AND DATE(p.post_date) = :sale_date
            GROUP BY oi.order_item_id, oi.order_id, p.post_date
            ORDER BY p.post_date ASC, oi.order_item_id ASC";
        $sql = str_replace('wp_', $wpPrefix, $sql);
        $st = $pdo->prepare($sql);
        $st->execute(['sale_date' => $dateYmd]);
        $rows = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $orderItemId = (int) ($row['order_item_id'] ?? 0);
            if ($orderItemId <= 0) {
                continue;
            }
            $rows[] = [
                'order_item_id' => $orderItemId,
                'order_id' => (int) ($row['order_id'] ?? 0),
                'amount' => (float) ($row['amount'] ?? 0),
                'location' => trim((string) ($row['location'] ?? '')),
                'post_date' => (string) ($row['post_date'] ?? ''),
            ];
        }

        return $rows;
    } catch (PDOException $e) {
        error_log('AllureOne gift_fetch_wp_sales_for_date failed: ' . $e->getMessage());

        return [];
    }
}

function gift_format_sale_notification_message(float $amount, string $location): string
{
    $locationLabel = trim($location);
    if ($locationLabel === '') {
        $locationLabel = 'Unknown';
    }

    return 'New E-Gift Card sale - ' . format_amount($amount) . ' (' . $locationLabel . ')';
}

/**
 * Branch users for locality plus superadmin, admin, and accounts roles.
 *
 * @return list<int>
 */
function gift_notification_user_ids_for_sale(string $location): array
{
    require_once __DIR__ . '/auth.php';
    $locality = trim($location);
    $ids = [];
    try {
        $pdo = db();
        $sql = 'SELECT DISTINCT u.id
                FROM allureone_users u
                LEFT JOIN allureone_branch b ON b.id = u.BranchId
                WHERE u.isactive = 1
                  AND (
                    u.RoleId IN (:role_superadmin, :role_admin, :role_accounts)';
        $params = [
            'role_superadmin' => ROLE_SUPERADMIN,
            'role_admin' => ROLE_ADMIN,
            'role_accounts' => ROLE_ACCOUNTS,
        ];
        if ($locality !== '') {
            $sql .= '
                    OR (
                        b.isActive = 1
                        AND b.locality IS NOT NULL
                        AND TRIM(b.locality) <> \'\'
                        AND LOWER(TRIM(b.locality)) = LOWER(TRIM(:locality))
                    )';
            $params['locality'] = $locality;
        }
        $sql .= '
                  )
                ORDER BY u.id ASC';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $uid = (int) ($row['id'] ?? 0);
            if ($uid > 0) {
                $ids[] = $uid;
            }
        }
    } catch (PDOException $e) {
        error_log('AllureOne gift_notification_user_ids_for_sale failed: ' . $e->getMessage());
    }

    return array_values(array_unique($ids));
}
