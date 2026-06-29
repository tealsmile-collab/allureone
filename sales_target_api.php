<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/gift_helpers.php';
require_login();
require_not_accounts_role();
require_not_franchise_officer_role();

header('Content-Type: application/json; charset=utf-8');
set_time_limit(180);

$curY = (int) date('Y');
$curM = (int) date('n');

$y = isset($_GET['y']) ? (int) $_GET['y'] : $curY;
$m = isset($_GET['m']) ? (int) $_GET['m'] : $curM;
if ($y < 2000 || $y > 2100) {
    $y = $curY;
}
if ($m < 1 || $m > 12) {
    $m = $curM;
}

$startDate = sprintf('%04d-%02d-01', $y, $m);
$endDate = date('Y-m-t', strtotime($startDate . ' 00:00:00'));
$daysInMonth = (int) date('t', strtotime($startDate . ' 00:00:00'));

$nowY = (int) date('Y');
$nowM = (int) date('n');
$nowD = (int) date('j');
if ($y === $nowY && $m === $nowM) {
    $daysPassed = $nowD;
    $daysRemaining = max(0, $daysInMonth - $nowD);
} elseif ($y > $nowY || ($y === $nowY && $m > $nowM)) {
    $daysPassed = 0;
    $daysRemaining = $daysInMonth;
} else {
    $daysPassed = $daysInMonth;
    $daysRemaining = 0;
}

function sales_target_branch_session_key(int $branchId): string
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
        error_log('AllureOne sales_target branch session key lookup failed: ' . $e->getMessage());
    }

    return '';
}

/**
 * @return array{total_sales: float, total_sales_achieved: float}|null
 */
function sales_target_parse_response(string $body): ?array
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return null;
    }
    $data = $decoded['data'] ?? null;
    if (!is_array($data) || $data === []) {
        return null;
    }
    $row = $data[0];
    if (!is_array($row)) {
        return null;
    }
    $ts = $row['total_sales'] ?? null;
    $ta = $row['total_sales_achieved'] ?? null;
    if (!is_numeric($ts) && !is_numeric($ta)) {
        return null;
    }

    return [
        'total_sales' => is_numeric($ts) ? (float) $ts : 0.0,
        'total_sales_achieved' => is_numeric($ta) ? (float) $ta : 0.0,
    ];
}

function sales_target_fetch_for_branch(string $token, string $startDate, string $endDate): array
{
    $config = require __DIR__ . '/config.php';
    $base = trim((string) (($config['dingg']['sales_target_url'] ?? 'https://api.dingg.app/api/v1/vendor/target/all')));
    if ($base === '') {
        return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'no_url'];
    }

    $params = [
        'employee_ids' => '-1',
        'time_type' => 'monthly',
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];
    $url = $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $resp = dingg_http_request_authenticated('GET', $url, $token, null);

    return [
        'ok' => true,
        'http' => (int) ($resp['http'] ?? 0),
        'body' => (string) ($resp['body'] ?? ''),
    ];
}

function sales_target_branch_is_vadodara(array $branch): bool
{
    $loc = strtolower(trim((string) ($branch['locality'] ?? '')));
    $bn = strtolower(trim((string) ($branch['business_name'] ?? '')));

    return str_contains($loc, 'vadodara') || str_contains($bn, 'vadodara');
}

function sales_target_branch_bottom_rank(string $label): int
{
    $l = strtolower(trim($label));
    if ($l === '' || $l === 'branch #' . preg_replace('/\D/', '', $l)) {
        return 0;
    }
    if (str_contains($l, 'boisar')) {
        return 1;
    }
    if (str_contains($l, 'kharghar')) {
        return 2;
    }
    if (str_contains($l, 'palghar')) {
        return 3;
    }

    return 0;
}

/**
 * @param list<array<string, mixed>> $branches
 *
 * @return list<array<string, mixed>>
 */
function sales_target_sort_branches(array $branches): array
{
    usort($branches, static function (array $a, array $b): int {
        $locA = trim((string) ($a['locality'] ?? ''));
        $locB = trim((string) ($b['locality'] ?? ''));
        $bnA = trim((string) ($a['business_name'] ?? ''));
        $bnB = trim((string) ($b['business_name'] ?? ''));
        $labelA = $locA !== '' ? $locA : $bnA;
        $labelB = $locB !== '' ? $locB : $bnB;

        $rankA = sales_target_branch_bottom_rank($labelA);
        $rankB = sales_target_branch_bottom_rank($labelB);
        if ($rankA !== $rankB) {
            if ($rankA === 0) {
                return -1;
            }
            if ($rankB === 0) {
                return 1;
            }

            return $rankA <=> $rankB;
        }

        return strcasecmp($labelA, $labelB);
    });

    return $branches;
}

/**
 *
 * @return array<string, mixed>
 */
function sales_target_build_row(array $branch, string $startDate, string $endDate, int $daysInMonth, int $daysPassed, int $daysRemaining): array
{
    $bid = (int) ($branch['id'] ?? 0);
    $loc = trim((string) ($branch['locality'] ?? ''));
    $bn = trim((string) ($branch['business_name'] ?? ''));
    $label = $loc !== '' ? $loc : ($bn !== '' ? $bn : ('Branch #' . $bid));

    $blank = [
        'branch_id' => $bid,
        'branch_name' => $label,
        'has_token' => false,
        'monthly_target' => null,
        'expected_avg' => null,
        'mtd' => null,
        'mtd_avg' => null,
        'remaining_sale' => null,
        'remaining_expected_avg' => null,
        'projection' => null,
    ];

    $token = sales_target_branch_session_key($bid);
    if ($token === '') {
        return $blank;
    }

    $blank['has_token'] = true;
    $resp = sales_target_fetch_for_branch($token, $startDate, $endDate);
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');

    if (!($resp['ok'] ?? false) || dingg_response_looks_unauthorized($http, $body) || $http < 200 || $http >= 300) {
        error_log('AllureOne sales_target API branch ' . $bid . ' HTTP ' . $http);
        return $blank;
    }

    $metrics = sales_target_parse_response($body);
    if ($metrics === null) {
        return $blank;
    }

    $totalSales = $metrics['total_sales'];
    $achieved = $metrics['total_sales_achieved'];
    $remaining = max(0.0, $totalSales - $achieved);

    $expectedAvg = $daysInMonth > 0 ? (int) ceil($totalSales / $daysInMonth) : null;
    $mtdAvg = $daysPassed > 0 ? (int) ceil($achieved / $daysPassed) : null;
    $remainingSale = (int) ceil($remaining);
    $remainingExpectedAvg = ($daysRemaining > 0)
        ? (int) ceil($remaining / $daysRemaining)
        : null;
    $projection = ($mtdAvg !== null && $daysInMonth > 0) ? $mtdAvg * $daysInMonth : null;

    return [
        'branch_id' => $bid,
        'branch_name' => $label,
        'has_token' => true,
        'monthly_target' => $totalSales,
        'expected_avg' => $expectedAvg,
        'mtd' => $achieved,
        'mtd_avg' => $mtdAvg,
        'remaining_sale' => $remainingSale,
        'remaining_expected_avg' => $remainingExpectedAvg,
        'projection' => $projection,
    ];
}

function sales_target_row_has_data(array $row): bool
{
    return ($row['monthly_target'] ?? null) !== null || ($row['mtd'] ?? null) !== null;
}

/**
 * Rows with API data first; blank rows (no token / failed fetch) at the end.
 *
 * @param list<array<string, mixed>> $rows
 *
 * @return list<array<string, mixed>>
 */
function sales_target_partition_rows_by_data(array $rows): array
{
    $withData = [];
    $withoutData = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (sales_target_row_has_data($row)) {
            $withData[] = $row;
        } else {
            $withoutData[] = $row;
        }
    }

    return array_merge($withData, $withoutData);
}

$branches = [];
try {
    $stmt = db()->query(
        'SELECT id, business_name, locality FROM allureone_branch WHERE isActive = 1 ORDER BY business_name ASC'
    );
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $branches = array_values(array_filter(
        $branches,
        static fn (array $branch): bool => !sales_target_branch_is_vadodara($branch)
    ));
    $branches = sales_target_sort_branches($branches);
} catch (PDOException $e) {
    error_log('AllureOne sales_target branch list: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Could not load branches.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($branches === []) {
    echo json_encode([
        'ok' => true,
        'period' => [
            'start' => $startDate,
            'end' => $endDate,
            'days_in_month' => $daysInMonth,
            'days_passed' => $daysPassed,
            'days_remaining' => $daysRemaining,
        ],
        'rows' => [],
        'message' => 'No active branches in Branch Master.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = [];
foreach ($branches as $branch) {
    if (!is_array($branch)) {
        continue;
    }
    $rows[] = sales_target_build_row($branch, $startDate, $endDate, $daysInMonth, $daysPassed, $daysRemaining);
}

$rows = sales_target_partition_rows_by_data($rows);

echo json_encode([
    'ok' => true,
    'period' => [
        'start' => $startDate,
        'end' => $endDate,
        'days_in_month' => $daysInMonth,
        'days_passed' => $daysPassed,
        'days_remaining' => $daysRemaining,
    ],
    'rows' => $rows,
], JSON_UNESCAPED_UNICODE);
