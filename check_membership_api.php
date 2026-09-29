<?php
declare(strict_types=1);

/**
 * CheckMembership API — package / membership balance for a client mobile.
 *
 * GET check_membership_api.php?clientMobileNumber=98XXXXXXXX[&branch_id=3000]
 * Header: X-Leads-Api-Key: <secret>  (or Authorization: Bearer <secret>)
 * Config: app.leads_api_key in config.php (same key as leads_api.php)
 *
 * Flow:
 *  1) Find customer via Dingg vendor/customer_list (branch session).
 *  2) If branch_id omitted, try each active Dingg branch until found.
 *  3) Fetch packages via vendor/customer/other?type=packages.
 *  4) Return active membership balance text(s).
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * @return array<string, mixed>
 */
function cm_api_config(): array
{
    global $config;
    if (!is_array($config ?? null)) {
        $config = require __DIR__ . '/config.php';
    }

    return is_array($config['app'] ?? null) ? $config['app'] : [];
}

function cm_api_expected_key(): string
{
    return trim((string) (cm_api_config()['leads_api_key'] ?? ''));
}

function cm_api_extract_key(): string
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if (is_array($h)) {
            $headers = $h;
        }
    }
    $normalized = [];
    foreach ($headers as $k => $v) {
        $normalized[strtolower((string) $k)] = (string) $v;
    }

    $fromHeader = trim((string) ($normalized['x-leads-api-key'] ?? ''));
    if ($fromHeader !== '') {
        return $fromHeader;
    }

    $auth = trim((string) ($normalized['authorization'] ?? ''));
    if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
        return trim((string) $m[1]);
    }

    return '';
}

function cm_normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) === 10) {
        return '91' . $digits;
    }
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        return $digits;
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        return '91' . substr($digits, 1);
    }

    return $digits;
}

function cm_branch_session_key(int $branchId): string
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
    } catch (Throwable $e) {
        error_log('AllureOne CheckMembership session key lookup failed: ' . $e->getMessage());
    }

    return '';
}

/**
 * Active Dingg branches with a usable session key.
 *
 * @return list<array{id:int,business_name:string,locality:string,session_key:string}>
 */
function cm_dingg_branches(?int $onlyBranchId = null): array
{
    $out = [];
    try {
        if ($onlyBranchId !== null && $onlyBranchId > 0) {
            $st = db()->prepare(
                'SELECT id, business_name, locality
                 FROM allureone_branch
                 WHERE id = :id AND isActive = 1 AND isDingg = 1
                 LIMIT 1'
            );
            $st->execute(['id' => $onlyBranchId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $st = db()->query(
                'SELECT id, business_name, locality
                 FROM allureone_branch
                 WHERE isActive = 1 AND isDingg = 1
                 ORDER BY id ASC'
            );
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $bid = (int) ($row['id'] ?? 0);
            if ($bid <= 0) {
                continue;
            }
            $sessionKey = cm_branch_session_key($bid);
            if ($sessionKey === '') {
                continue;
            }
            $out[] = [
                'id' => $bid,
                'business_name' => trim((string) ($row['business_name'] ?? '')),
                'locality' => trim((string) ($row['locality'] ?? '')),
                'session_key' => $sessionKey,
            ];
        }
    } catch (Throwable $e) {
        error_log('AllureOne CheckMembership branch list failed: ' . $e->getMessage());
    }

    return $out;
}

/**
 * @return array{ok:bool,user_id?:int,history_id?:int,client_name?:string,mobile?:string,error?:string}
 */
function cm_find_customer(string $sessionKey, string $mobile91): array
{
    $url = 'https://api.dingg.app/api/v1/vendor/customer_list?' . http_build_query(
        [
            'page' => '1',
            'limit' => '10',
            'mobile' => $mobile91,
            'amount_start' => '0',
            'is_multi_location' => 'false',
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
    $resp = dingg_http_request_authenticated('GET', $url, $sessionKey, null);
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');
    if ($http < 200 || $http >= 300 || $body === '' || dingg_response_looks_unauthorized($http, $body)) {
        return ['ok' => false, 'error' => 'customer_list request failed.'];
    }
    $json = json_decode($body, true);
    $rows = is_array($json) && isset($json['data']) && is_array($json['data']) ? $json['data'] : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $user = isset($row['user']) && is_array($row['user']) ? $row['user'] : [];
        $userId = (int) ($row['user_id'] ?? ($user['id'] ?? 0));
        $historyId = (int) ($row['id'] ?? 0);
        $clientName = trim((string) ($row['fname'] ?? ($user['fname'] ?? '')));
        $mobile = trim((string) ($row['mobile'] ?? ($user['mobile'] ?? '')));
        if ($userId <= 0 || $historyId <= 0) {
            continue;
        }

        return [
            'ok' => true,
            'user_id' => $userId,
            'history_id' => $historyId,
            'client_name' => $clientName !== '' ? $clientName : 'Client',
            'mobile' => $mobile,
        ];
    }

    return ['ok' => false, 'error' => 'Customer not found.'];
}

/**
 * @return array{ok:bool,packages?:list<array<string,mixed>>,error?:string}
 */
function cm_fetch_packages(string $sessionKey, int $userId, int $historyId): array
{
    $url = 'https://api.dingg.app/api/v1/vendor/customer/other?' . http_build_query(
        [
            'id' => (string) $userId,
            'historyId' => (string) $historyId,
            'type' => 'packages',
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
    $resp = dingg_http_request_authenticated('GET', $url, $sessionKey, null);
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');
    if ($http < 200 || $http >= 300 || $body === '' || dingg_response_looks_unauthorized($http, $body)) {
        return ['ok' => false, 'error' => 'packages request failed.'];
    }
    $json = json_decode($body, true);
    $data = is_array($json) && isset($json['data']) && is_array($json['data']) ? $json['data'] : [];
    $packages = isset($data['packages']) && is_array($data['packages']) ? $data['packages'] : [];

    return [
        'ok' => true,
        'packages' => array_values(array_filter($packages, 'is_array')),
    ];
}

function cm_format_hours(float $hours): string
{
    if ($hours < 0) {
        $hours = 0.0;
    }
    if (abs($hours - round($hours)) < 0.05) {
        return ((int) round($hours)) . 'h';
    }

    return rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.') . 'h';
}

/**
 * Build membership display lines from Dingg packages.
 *
 * @param list<array<string,mixed>> $packages
 * @return list<string>
 */
function cm_membership_texts(array $packages, string $clientName): array
{
    $lines = [];
    foreach ($packages as $pkg) {
        $currentValue = (float) ($pkg['currentValue'] ?? 0);
        if ($currentValue <= 0) {
            // Expired membership — skip from active balance text.
            continue;
        }
        $userPackages = isset($pkg['user_packages']) && is_array($pkg['user_packages']) ? $pkg['user_packages'] : [];
        $originalMins = 0;
        $consumedMins = 0;
        foreach ($userPackages as $up) {
            if (!is_array($up)) {
                continue;
            }
            $originalMins += (int) ($up['original'] ?? 0);
            $consumedMins += (int) ($up['consumed'] ?? 0);
        }
        if ($originalMins <= 0) {
            continue;
        }
        $balanceMins = max(0, $originalMins - $consumedMins);
        $usedHours = $consumedMins / 60;
        $leftHours = $balanceMins / 60;
        $loc = isset($pkg['vendor_location']) && is_array($pkg['vendor_location']) ? $pkg['vendor_location'] : [];
        $locality = trim((string) ($loc['locality'] ?? ''));
        if ($locality === '') {
            $locality = trim((string) ($loc['business_name'] ?? 'branch'));
        }
        $lines[] = $clientName
            . ' - Membership in '
            . $locality
            . ' - '
            . cm_format_hours($usedHours)
            . ' used, '
            . cm_format_hours($leftHours)
            . ' ('
            . $balanceMins
            . ' mins) left';
    }

    return $lines;
}

function cm_json_out(int $http, array $payload): void
{
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    cm_json_out(405, ['ok' => false, 'error' => 'Method not allowed. Use GET.']);
}

$expectedKey = cm_api_expected_key();
if ($expectedKey === '') {
    cm_json_out(503, [
        'ok' => false,
        'error' => 'CheckMembership API is not configured. Set app.leads_api_key in config.php.',
    ]);
}

$providedKey = cm_api_extract_key();
if ($providedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    cm_json_out(401, ['ok' => false, 'error' => 'Invalid or missing API key. Send header X-Leads-Api-Key.']);
}

$mobileRaw = trim((string) ($_GET['clientMobileNumber'] ?? $_GET['client_mobile_number'] ?? $_GET['mobile'] ?? ''));
$branchRaw = trim((string) ($_GET['branch_id'] ?? $_GET['branchID'] ?? $_GET['branchId'] ?? ''));
$branchIdFilter = $branchRaw !== '' ? (int) $branchRaw : 0;

$mobile = cm_normalize_phone($mobileRaw);
if ($mobile === '' || (strlen($mobile) !== 12 && strlen($mobile) !== 10)) {
    cm_json_out(400, ['ok' => false, 'error' => 'clientMobileNumber is required (10-digit or 91XXXXXXXXXX).']);
}
if (strlen($mobile) === 10) {
    $mobile = '91' . $mobile;
}

$branches = cm_dingg_branches($branchIdFilter > 0 ? $branchIdFilter : null);
if ($branches === []) {
    cm_json_out(400, [
        'ok' => false,
        'error' => $branchIdFilter > 0
            ? 'Invalid, inactive, non-Dingg branch, or missing Dingg session for branch_id.'
            : 'No active Dingg branches with session keys found.',
    ]);
}

$found = null;
$foundBranch = null;
foreach ($branches as $branch) {
    $customer = cm_find_customer((string) $branch['session_key'], $mobile);
    if (!($customer['ok'] ?? false)) {
        continue;
    }
    $found = $customer;
    $foundBranch = $branch;
    break;
}

if ($found === null || $foundBranch === null) {
    cm_json_out(404, [
        'ok' => false,
        'error' => 'Customer not found for this mobile number.',
        'clientMobileNumber' => $mobile,
    ]);
}

$userId = (int) ($found['user_id'] ?? 0);
$historyId = (int) ($found['history_id'] ?? 0);
$clientName = (string) ($found['client_name'] ?? 'Client');
$pkgRes = cm_fetch_packages((string) $foundBranch['session_key'], $userId, $historyId);
if (!($pkgRes['ok'] ?? false)) {
    cm_json_out(502, [
        'ok' => false,
        'error' => (string) ($pkgRes['error'] ?? 'Could not load membership packages.'),
        'user_id' => $userId,
        'history_id' => $historyId,
        'client_name' => $clientName,
        'branch_id' => (int) $foundBranch['id'],
    ]);
}

$packages = is_array($pkgRes['packages'] ?? null) ? $pkgRes['packages'] : [];
$memberships = cm_membership_texts($packages, $clientName);
$expiredCount = 0;
foreach ($packages as $pkg) {
    if (!is_array($pkg)) {
        continue;
    }
    if ((float) ($pkg['currentValue'] ?? 0) <= 0) {
        $expiredCount++;
    }
}

cm_json_out(200, [
    'ok' => true,
    'client_name' => $clientName,
    'clientMobileNumber' => $mobile,
    'user_id' => $userId,
    'history_id' => $historyId,
    'branch_id' => (int) $foundBranch['id'],
    'branch_name' => (string) ($foundBranch['business_name'] ?? ''),
    'locality' => (string) ($foundBranch['locality'] ?? ''),
    'memberships' => $memberships,
    'membership_text' => $memberships !== [] ? implode("\n", $memberships) : '',
    'has_active_membership' => $memberships !== [],
    'expired_package_count' => $expiredCount,
    'message' => $memberships !== []
        ? 'Active membership found.'
        : ($expiredCount > 0 ? 'Customer found; membership expired or no balance.' : 'Customer found; no packages.'),
]);
