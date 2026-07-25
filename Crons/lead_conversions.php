<?php
declare(strict_types=1);

/**
 * Hostinger cron: mark Meta leads converted from yesterday's Dingg bills.
 *
 * CLI:
 *   php Crons/lead_conversions.php
 *
 * HTTP:
 *   https://your-domain/Crons/lead_conversions.php
 *
 * For each active branch with isDingg = 1:
 *   GET vendor/bills for yesterday (branch session key)
 *   For each invoice user.mobile, find allureone_meta_leads by lead_phone_number
 *   If lead exists and status is not converted → set converted + amount = paid
 */

$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

set_time_limit(0);
ini_set('memory_limit', '256M');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/dingg.php';

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
    fwrite(STDERR, "Cannot create log directory: {$logDir}\n");
    exit(1);
}

$logFile = $logDir . '/lead_conversions_' . date('Y-m-d') . '.log';
$runStarted = date('Y-m-d H:i:s');
$yesterday = date('Y-m-d', strtotime('-1 day'));

/**
 * @param mixed $value
 */
function lc_log_line(string $logFile, string $line): void
{
    file_put_contents($logFile, $line . "\n", FILE_APPEND);
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        echo $line . PHP_EOL;
    }
}

/**
 * Normalize phone to last 10 digits (strip leading 91 when present).
 */
function lc_mobile_tail(string $rawPhone): string
{
    $digits = preg_replace('/\D+/', '', $rawPhone) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    return $digits;
}

function lc_normalize_status_key(string $key): string
{
    $key = strtolower(trim($key));
    $key = str_replace([' ', '-'], '_', $key);

    return $key;
}

function lc_branch_session_key(int $branchId): string
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
        error_log('lead_conversions session key lookup failed: ' . $e->getMessage());
    }

    return '';
}

/**
 * @return array{ok:bool,error?:string,http?:int,json?:mixed,body?:string}
 */
function lc_dingg_get(string $url, string $token): array
{
    $resp = dingg_http_request_authenticated('GET', $url, $token, null);
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');
    $json = json_decode($body, true);
    if ($http < 200 || $http >= 300 || dingg_response_looks_unauthorized($http, $body)) {
        $msg = is_array($json) ? trim((string) ($json['message'] ?? '')) : '';

        return [
            'ok' => false,
            'error' => $msg !== '' ? $msg : ('Request failed (HTTP ' . $http . ').'),
            'http' => $http,
            'json' => $json,
            'body' => $body,
        ];
    }

    return ['ok' => true, 'http' => $http, 'json' => $json, 'body' => $body];
}

function lc_converted_status_id(PDO $pdo): int
{
    try {
        $st = $pdo->query(
            "SELECT id
             FROM allureone_leads_status
             WHERE LOWER(TRIM(status_key)) = 'converted'
             LIMIT 1"
        );
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    } catch (Throwable $e) {
        error_log('lead_conversions converted status lookup failed: ' . $e->getMessage());
    }

    return 0;
}

function lc_format_amount(float $amount): string
{
    if (fmod($amount, 1.0) === 0.0) {
        return (string) (int) round($amount);
    }

    return number_format($amount, 2, '.', '');
}

function lc_mark_lead_converted(PDO $pdo, int $leadId, int $convertedStatusId, float $amount): bool
{
    if ($leadId <= 0 || $convertedStatusId <= 0) {
        return false;
    }
    try {
        $upd = $pdo->prepare(
            'UPDATE allureone_meta_leads
             SET status = :status, amount = :amount
             WHERE id = :id'
        );
        $upd->execute([
            'status' => $convertedStatusId,
            'amount' => lc_format_amount($amount),
            'id' => $leadId,
        ]);

        return true;
    } catch (Throwable $e) {
        error_log('lead_conversions mark converted failed: ' . $e->getMessage());
    }

    return false;
}

/**
 * @return list<array<string, mixed>>
 */
function lc_bills_rows(array $json): array
{
    $data = $json['data'] ?? null;
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * Extract mobile + paid from a vendor/bills invoice row.
 *
 * @return array{mobile:string,paid:float,paid_display:string,bill_id:int}|null
 */
function lc_invoice_mobile_and_paid(array $bill): ?array
{
    $user = $bill['user'] ?? null;
    if (!is_array($user)) {
        $user = [];
    }
    $mobile = lc_mobile_tail((string) ($user['mobile'] ?? $bill['mobile'] ?? ''));
    if ($mobile === '' || strlen($mobile) < 8) {
        return null;
    }

    $paidRaw = $bill['paid'] ?? null;
    $paidNum = is_numeric($paidRaw) ? (float) $paidRaw : 0.0;

    return [
        'mobile' => $mobile,
        'paid' => $paidNum,
        'paid_display' => lc_format_amount($paidNum),
        'bill_id' => (int) ($bill['id'] ?? 0),
    ];
}

/**
 * Build mobile-tail => lead rows map for fast lookup.
 *
 * @return array<string, list<array{id:int,branch_id:int,status_key:string,lead_name:string}>>
 */
function lc_load_leads_by_mobile(PDO $pdo): array
{
    $map = [];
    try {
        $sql = 'SELECT m.id, m.branch_id, m.lead_name, m.lead_phone_number, m.status,
                       s.status_key
                FROM allureone_meta_leads m
                LEFT JOIN allureone_leads_status s ON s.id = m.status';
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $tail = lc_mobile_tail((string) ($row['lead_phone_number'] ?? ''));
            if ($tail === '') {
                continue;
            }
            if (!isset($map[$tail])) {
                $map[$tail] = [];
            }
            $map[$tail][] = [
                'id' => (int) ($row['id'] ?? 0),
                'branch_id' => (int) ($row['branch_id'] ?? 0),
                'status_key' => lc_normalize_status_key((string) ($row['status_key'] ?? '')),
                'lead_name' => trim((string) ($row['lead_name'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
        error_log('lead_conversions load leads failed: ' . $e->getMessage());
    }

    return $map;
}

lc_log_line($logFile, '===== lead_conversions run start: ' . $runStarted . ' =====');
lc_log_line($logFile, 'bills date (yesterday): ' . $yesterday);

try {
    $pdo = db();
} catch (Throwable $e) {
    lc_log_line($logFile, 'DB connection failed: ' . $e->getMessage());
    exit(1);
}

$convertedStatusId = lc_converted_status_id($pdo);
if ($convertedStatusId <= 0) {
    lc_log_line($logFile, 'ERROR: converted status id not found in allureone_leads_status; aborting.');
    exit(1);
}

try {
    $branches = $pdo->query(
        'SELECT id, business_name, locality
         FROM allureone_branch
         WHERE isActive = 1 AND isDingg = 1
         ORDER BY id ASC'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    lc_log_line($logFile, 'Failed to load isDingg branches: ' . $e->getMessage());
    exit(1);
}

if ($branches === []) {
    lc_log_line($logFile, 'No active isDingg branches found.');
    exit(0);
}

$leadsByMobile = lc_load_leads_by_mobile($pdo);
$branchCount = 0;
$invoiceCount = 0;
$convertedCount = 0;
$alreadyConverted = 0;
$noLeadMatch = 0;
$didBranchApi = false;

foreach ($branches as $branch) {
    if (!is_array($branch)) {
        continue;
    }
    $branchId = (int) ($branch['id'] ?? 0);
    $loc = trim((string) ($branch['locality'] ?? ''));
    $bn = trim((string) ($branch['business_name'] ?? ''));
    $branchLabel = $loc !== '' ? $loc : ($bn !== '' ? $bn : ('branch#' . $branchId));
    if ($loc !== '' && $bn !== '' && strcasecmp($loc, $bn) !== 0) {
        $branchLabel = $loc . ' · ' . $bn;
    }

    if ($didBranchApi) {
        sleep(3);
    }

    $token = lc_branch_session_key($branchId);
    if ($token === '') {
        continue;
    }

    $billsUrl = 'https://api.dingg.app/api/v1/vendor/bills?' . http_build_query(
        [
            'web' => 'true',
            'page' => '1',
            'limit' => '1000',
            'start' => $yesterday,
            'end' => $yesterday,
            'term' => '',
            'is_product_only' => '',
        ],
        '',
        '&',
        PHP_QUERY_RFC3986
    );

    $billsResp = lc_dingg_get($billsUrl, $token);
    $didBranchApi = true;
    $branchCount++;

    if (!$billsResp['ok']) {
        lc_log_line($logFile, 'branch_id ' . $branchId . ' | ' . $branchLabel . ' | bills API failed: ' . ($billsResp['error'] ?? 'unknown error'));
        continue;
    }

    $bills = lc_bills_rows(is_array($billsResp['json'] ?? null) ? $billsResp['json'] : []);

    foreach ($bills as $bill) {
        if (!is_array($bill)) {
            continue;
        }
        $invoiceCount++;
        $info = lc_invoice_mobile_and_paid($bill);
        if ($info === null) {
            continue;
        }

        $mobile = $info['mobile'];
        $paid = (float) $info['paid'];
        $paidDisplay = (string) $info['paid_display'];
        $billId = (int) $info['bill_id'];

        $matches = $leadsByMobile[$mobile] ?? [];
        if ($matches === []) {
            $noLeadMatch++;
            continue;
        }

        // Prefer leads for this branch; otherwise all phone matches
        $preferred = [];
        $others = [];
        foreach ($matches as $m) {
            if ((int) ($m['branch_id'] ?? 0) === $branchId) {
                $preferred[] = $m;
            } else {
                $others[] = $m;
            }
        }
        $toProcess = $preferred !== [] ? $preferred : $others;

        foreach ($toProcess as $lead) {
            $leadId = (int) ($lead['id'] ?? 0);
            $statusKey = (string) ($lead['status_key'] ?? '');
            $leadName = (string) ($lead['lead_name'] ?? '');

            if ($statusKey === 'converted') {
                $alreadyConverted++;
                continue;
            }

            if (lc_mark_lead_converted($pdo, $leadId, $convertedStatusId, $paid)) {
                $convertedCount++;
                // Keep in-memory map in sync for later invoices same run
                foreach ($leadsByMobile[$mobile] as $i => $cached) {
                    if ((int) ($cached['id'] ?? 0) === $leadId) {
                        $leadsByMobile[$mobile][$i]['status_key'] = 'converted';
                    }
                }
                lc_log_line(
                    $logFile,
                    'branch_id ' . $branchId . ' | ' . $branchLabel
                    . ' | invoice ' . $billId
                    . ' | mobile ' . $mobile
                    . ' | lead_id ' . $leadId
                    . ($leadName !== '' ? (' | ' . $leadName) : '')
                    . ' | status updated to converted, amount = ' . $paidDisplay
                );
            }
        }
    }
}

$runEnded = date('Y-m-d H:i:s');
lc_log_line(
    $logFile,
    '===== lead_conversions run end: ' . $runEnded
    . ' | branches=' . $branchCount
    . ' | invoices=' . $invoiceCount
    . ' | converted=' . $convertedCount
    . ' | already_converted=' . $alreadyConverted
    . ' | no_lead_match=' . $noLeadMatch
    . ' ====='
);

if (!$isCli) {
    echo "OK branches={$branchCount} invoices={$invoiceCount} converted={$convertedCount}\n";
    echo "Log: {$logFile}\n";
}

exit(0);
