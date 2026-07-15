<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();
require_not_accounts_role();
require_not_franchise_officer_role();

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

$user = current_user();
$roleId = (int) ($user['role_id'] ?? 0);
if ($roleId !== ROLE_SUPERADMIN && $roleId !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

function daily_sale_branch_session_key(int $branchId): string
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
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return trim((string) ($row['session_key'] ?? ''));
    } catch (Throwable $e) {
        error_log('AllureOne daily_sale session key: ' . $e->getMessage());

        return '';
    }
}

function daily_sale_branch_is_excluded(array $branch): bool
{
    $loc = strtolower(trim((string) ($branch['locality'] ?? '')));
    $bn = strtolower(trim((string) ($branch['business_name'] ?? '')));
    $hay = $loc . ' ' . $bn;

    return str_contains($hay, 'vadodara')
        || str_contains($hay, 'palghar')
        || str_contains($hay, 'kharghar');
}

function daily_sale_parse_amount(mixed $val): ?float
{
    if ($val === null || $val === '') {
        return null;
    }
    if (is_numeric($val)) {
        return (float) $val;
    }
    $cleaned = preg_replace('/[^0-9.\-]/', '', (string) $val);

    return is_numeric($cleaned) ? (float) $cleaned : null;
}

function daily_sale_today_ymd(): string
{
    try {
        return (new DateTime('now', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
    } catch (Throwable $e) {
        return date('Y-m-d');
    }
}

/**
 * @return list<array{branch_id:int, branch_name:string}>
 */
function daily_sale_list_branches(): array
{
    $stmt = db()->query(
        'SELECT id, business_name, locality FROM allureone_branch WHERE isActive = 1 ORDER BY business_name ASC'
    );
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $branches = array_values(array_filter(
        $branches,
        static fn (array $branch): bool => !daily_sale_branch_is_excluded($branch)
    ));
    $branches = daily_sale_sort_branches($branches);

    $out = [];
    foreach ($branches as $branch) {
        if (!is_array($branch)) {
            continue;
        }
        $bid = (int) ($branch['id'] ?? 0);
        $loc = trim((string) ($branch['locality'] ?? ''));
        $bn = trim((string) ($branch['business_name'] ?? ''));
        $label = $loc !== '' ? $loc : ($bn !== '' ? $bn : ('Branch #' . $bid));
        $out[] = [
            'branch_id' => $bid,
            'branch_name' => $label,
        ];
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $branches
 *
 * @return list<array<string, mixed>>
 */
function daily_sale_sort_branches(array $branches): array
{
    usort($branches, static function (array $a, array $b): int {
        $rankA = daily_sale_branch_order_rank($a);
        $rankB = daily_sale_branch_order_rank($b);
        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }
        $labelA = trim((string) ($a['locality'] ?? ''));
        $labelB = trim((string) ($b['locality'] ?? ''));
        if ($labelA === '') {
            $labelA = trim((string) ($a['business_name'] ?? ''));
        }
        if ($labelB === '') {
            $labelB = trim((string) ($b['business_name'] ?? ''));
        }

        return strcasecmp($labelA, $labelB);
    });

    return $branches;
}

function daily_sale_branch_order_rank(array $branch): int
{
    $loc = strtolower(trim((string) ($branch['locality'] ?? '')));
    $bn = strtolower(trim((string) ($branch['business_name'] ?? '')));
    $hay = $loc !== '' ? $loc : $bn;

    // Fixed display order requested for Daily Sale.
    $rules = [
        1 => ['borivali'],
        2 => ['powai'],
        3 => ['andheri east', 'andheri_east', 'marol'],
        4 => ['andheri west', 'andheri_west', 'lokhandwala'],
        5 => ['malad'],
        6 => ['mulund'],
        7 => ['seawoods'],
        8 => ['kolshet', 'thane kolshet'],
        9 => ['vartak', 'thane vartak'],
        10 => ['boisar'],
        11 => ['ratnagiri'],
    ];
    foreach ($rules as $rank => $needles) {
        foreach ($needles as $needle) {
            if ($hay !== '' && str_contains($hay, $needle)) {
                return $rank;
            }
        }
    }

    return 100;
}

function daily_sale_admin_fallback_token(): string
{
    try {
        $st = db()->query(
            "SELECT session_key
             FROM allureone_session_data
             WHERE LOWER(TRIM(mobile_number)) = 'admin'
             ORDER BY updated_date DESC
             LIMIT 1"
        );
        $tok = trim((string) ($st->fetchColumn() ?: ''));
        if ($tok !== '') {
            return $tok;
        }
    } catch (Throwable $e) {
        error_log('AllureOne daily_sale admin token: ' . $e->getMessage());
    }

    return '';
}

function daily_sale_is_unauthorized_error(?string $error, int $http = 0): bool
{
    if ($http === 401 || $http === 403 || $http === 422) {
        return true;
    }
    $e = strtolower(trim((string) $error));
    if ($e === '') {
        return false;
    }

    return str_contains($e, 'not authorized')
        || str_contains($e, 'no access')
        || str_contains($e, 'unauthorized');
}

/**
 * @return array{total_sale:?float, services:?float, membership:?float, error:?string, http:int}
 */
function daily_sale_fetch_for_branch(string $token, string $startDate, string $locations = 'null'): array
{
    $config = require __DIR__ . '/config.php';
    $base = trim((string) (($config['dingg']['daily_sale_url'] ?? 'https://api.dingg.app/api/v1/vendor/report/sales')));
    if ($base === '' || $token === '') {
        return ['total_sale' => null, 'services' => null, 'membership' => null, 'error' => 'missing_token_or_url', 'http' => 0];
    }

    $params = [
        'start_date' => $startDate,
        'report_type' => 'by_revenue',
        'locations' => $locations,
        'app_type' => 'web',
        'range_type' => 'day',
    ];
    $url = $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $resp = dingg_http_request_authenticated('GET', $url, $token, null);
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');

    if ($http < 200 || $http >= 300) {
        $jsonErr = json_decode($body, true);
        $msg = is_array($jsonErr) ? trim((string) ($jsonErr['message'] ?? '')) : '';

        return [
            'total_sale' => null,
            'services' => null,
            'membership' => null,
            'error' => $msg !== '' ? $msg : ('HTTP ' . $http),
            'http' => $http,
        ];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        return ['total_sale' => null, 'services' => null, 'membership' => null, 'error' => 'invalid_json', 'http' => $http];
    }
    // Some Dingg failures return HTTP 200 with success:false.
    if (array_key_exists('success', $json) && $json['success'] === false) {
        $msg = trim((string) ($json['message'] ?? 'request_failed'));

        return [
            'total_sale' => null,
            'services' => null,
            'membership' => null,
            'error' => $msg !== '' ? $msg : 'request_failed',
            'http' => $http,
        ];
    }
    if (isset($json['status']) && strtolower((string) $json['status']) !== 'success') {
        $msg = trim((string) ($json['message'] ?? 'request_failed'));

        return [
            'total_sale' => null,
            'services' => null,
            'membership' => null,
            'error' => $msg !== '' ? $msg : 'request_failed',
            'http' => $http,
        ];
    }

    $rows = $json['data'] ?? null;
    if (!is_array($rows) || $rows === []) {
        return ['total_sale' => 0.0, 'services' => 0.0, 'membership' => 0.0, 'error' => null, 'http' => $http];
    }

    $first = $rows[0];
    if (!is_array($first)) {
        return ['total_sale' => null, 'services' => null, 'membership' => null, 'error' => 'invalid_row', 'http' => $http];
    }

    $map = [];
    foreach ($first as $k => $v) {
        $map[strtolower(trim((string) $k))] = $v;
    }

    return [
        'total_sale' => daily_sale_parse_amount($map['total collection'] ?? null),
        'services' => daily_sale_parse_amount($map['services'] ?? null),
        'membership' => daily_sale_parse_amount($map['packages'] ?? null),
        'error' => null,
        'http' => $http,
    ];
}

/**
 * Branch token first; if unauthorized (e.g. Boisar), retry with admin token + locations=branch_id.
 *
 * @return array{total_sale:?float, services:?float, membership:?float, error:?string}
 */
function daily_sale_fetch_metrics(int $branchId, string $startDate): array
{
    $token = daily_sale_branch_session_key($branchId);
    $metrics = null;
    if ($token !== '') {
        $metrics = daily_sale_fetch_for_branch($token, $startDate, 'null');
        if ($metrics['error'] === null) {
            return $metrics;
        }
    }

    $needsFallback = ($token === '')
        || ($metrics !== null && daily_sale_is_unauthorized_error($metrics['error'] ?? null, (int) ($metrics['http'] ?? 0)));
    if (!$needsFallback) {
        return $metrics ?? ['total_sale' => null, 'services' => null, 'membership' => null, 'error' => 'No session token'];
    }

    $adminTok = daily_sale_admin_fallback_token();
    if ($adminTok === '' || $adminTok === $token) {
        return $metrics ?? ['total_sale' => null, 'services' => null, 'membership' => null, 'error' => 'No session token'];
    }

    $fallback = daily_sale_fetch_for_branch($adminTok, $startDate, (string) $branchId);
    if ($fallback['error'] === null) {
        return $fallback;
    }

    return $metrics ?? $fallback;
}

$action = trim((string) ($_GET['action'] ?? 'branches'));
$date = trim((string) ($_GET['date'] ?? ''));
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
    $date = daily_sale_today_ymd();
}

try {
    if ($action === 'branches') {
        echo json_encode([
            'ok' => true,
            'date' => $date,
            'branches' => daily_sale_list_branches(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'fetch') {
        $branchId = isset($_GET['branch_id']) ? (int) $_GET['branch_id'] : 0;
        if ($branchId <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid branch_id.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $label = 'Branch #' . $branchId;
        $st = db()->prepare('SELECT id, business_name, locality FROM allureone_branch WHERE id = :id LIMIT 1');
        $st->execute(['id' => $branchId]);
        $branch = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($branch)) {
            if (daily_sale_branch_is_excluded($branch)) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Branch excluded.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $loc = trim((string) ($branch['locality'] ?? ''));
            $bn = trim((string) ($branch['business_name'] ?? ''));
            $label = $loc !== '' ? $loc : ($bn !== '' ? $bn : $label);
        }

        $metrics = daily_sale_fetch_metrics($branchId, $date);
        echo json_encode([
            'ok' => true,
            'date' => $date,
            'row' => [
                'branch_id' => $branchId,
                'branch_name' => $label,
                'total_sale' => $metrics['total_sale'],
                'services' => $metrics['services'],
                'membership' => $metrics['membership'],
                'error' => $metrics['error'],
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action.'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('AllureOne daily_sale_api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load daily sale data.'], JSON_UNESCAPED_UNICODE);
}
