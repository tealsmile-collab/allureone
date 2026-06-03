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
