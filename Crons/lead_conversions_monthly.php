<?php
declare(strict_types=1);

/**
 * One-off / monthly backfill: mark Meta leads converted from Dingg sales-by-invoice report.
 *
 * CLI:
 *   php Crons/lead_conversions_monthly.php
 *
 * HTTP:
 *   https://your-domain/Crons/lead_conversions_monthly.php
 *
 * For each active branch with isDingg = 1, for June / July / August 2026:
 *   GET vendor/report/sales?report_type=by_invoice&start_date=&end_date=...
 *   Match invoice mobile (with/without 91) to allureone_meta_leads.lead_phone_number
 *   If lead found and not converted → set converted + amount = "total sale"
 */

$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

set_time_limit(0);
ini_set('memory_limit', '512M');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require_once $root . '/includes/database.php';
require_once $root . '/includes/dingg.php';

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir) && !mkdir($logDir, 0755, true) && !is_dir($logDir)) {
    fwrite(STDERR, "Cannot create log directory: {$logDir}\n");
    exit(1);
}

$logFile = $logDir . '/lead_conversions_monthly_' . date('Y-m-d') . '.log';
$runStarted = date('Y-m-d H:i:s');

/** Months to backfill (start/end inclusive). */
$reportMonths = [
    ['label' => 'June 2026', 'start' => '2026-06-01', 'end' => '2026-06-30'],
    ['label' => 'July 2026', 'start' => '2026-07-01', 'end' => '2026-07-31'],
    ['label' => 'August 2026', 'start' => '2026-08-01', 'end' => '2026-08-31'],
];

function lcm_log_line(string $logFile, string $line): void
{
    file_put_contents($logFile, $line . "\n", FILE_APPEND);
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        echo $line . PHP_EOL;
    }
}

/** Digits only. */
function lcm_digits(string $rawPhone): string
{
    return preg_replace('/\D+/', '', $rawPhone) ?? '';
}

/**
 * Last 10 digits (strip leading 91 when present).
 */
function lcm_mobile_tail(string $rawPhone): string
{
    $digits = lcm_digits($rawPhone);
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

function lcm_normalize_status_key(string $key): string
{
    $key = strtolower(trim($key));
    $key = str_replace([' ', '-'], '_', $key);

    return $key;
}

function lcm_branch_session_key(int $branchId): string
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
        error_log('lead_conversions_monthly session key lookup failed: ' . $e->getMessage());
    }

    return '';
}

/**
 * @return array{ok:bool,error?:string,http?:int,json?:mixed,body?:string}
 */
function lcm_dingg_get(string $url, string $token): array
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

function lcm_converted_status_id(PDO $pdo): int
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
        error_log('lead_conversions_monthly converted status lookup failed: ' . $e->getMessage());
    }

    return 0;
}

function lcm_format_amount(float $amount): string
{
    if (fmod($amount, 1.0) === 0.0) {
        return (string) (int) round($amount);
    }

    return number_format($amount, 2, '.', '');
}

function lcm_mark_lead_converted(PDO $pdo, int $leadId, int $convertedStatusId, float $amount): bool
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
            'amount' => lcm_format_amount($amount),
            'id' => $leadId,
        ]);

        return true;
    } catch (Throwable $e) {
        error_log('lead_conversions_monthly mark converted failed: ' . $e->getMessage());
    }

    return false;
}

/**
 * @return list<array<string, mixed>>
 */
function lcm_report_invoice_rows(array $json): array
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
 * Extract mobile + total sale from sales report invoice row.
 *
 * @return array{mobile_digits:string,mobile_tail:string,amount:float,amount_display:string,invoice:string}|null
 */
function lcm_invoice_mobile_and_sale(array $row): ?array
{
    $mobileRaw = (string) ($row['mobile'] ?? '');
    $digits = lcm_digits($mobileRaw);
    $tail = lcm_mobile_tail($mobileRaw);
    if ($digits === '' && $tail === '') {
        return null;
    }
    if ($tail === '' || strlen($tail) < 8) {
        // Keep odd/international numbers only if we still have usable digits
        if (strlen($digits) < 8) {
            return null;
        }
        $tail = strlen($digits) > 10 ? substr($digits, -10) : $digits;
    }

    $saleRaw = $row['total sale'] ?? $row['total_sale'] ?? null;
    $saleNum = is_numeric($saleRaw) ? (float) $saleRaw : 0.0;

    return [
        'mobile_digits' => $digits,
        'mobile_tail' => $tail,
        'amount' => $saleNum,
        'amount_display' => lcm_format_amount($saleNum),
        'invoice' => trim((string) ($row['invoice number'] ?? $row['invoice_number'] ?? '')),
    ];
}

/**
 * Index leads by phone keys: full digits AND last-10 (with/without 91).
 *
 * @return array<string, list<array{id:int,branch_id:int,status_key:string,lead_name:string,phone_digits:string}>>
 */
function lcm_load_leads_by_mobile(PDO $pdo): array
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
            $raw = (string) ($row['lead_phone_number'] ?? '');
            $digits = lcm_digits($raw);
            $tail = lcm_mobile_tail($raw);
            if ($digits === '' && $tail === '') {
                continue;
            }
            $entry = [
                'id' => (int) ($row['id'] ?? 0),
                'branch_id' => (int) ($row['branch_id'] ?? 0),
                'status_key' => lcm_normalize_status_key((string) ($row['status_key'] ?? '')),
                'lead_name' => trim((string) ($row['lead_name'] ?? '')),
                'phone_digits' => $digits,
            ];
            $keys = [];
            if ($digits !== '') {
                $keys[$digits] = true;
                if (strlen($digits) === 10) {
                    $keys['91' . $digits] = true;
                }
                if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
                    $keys[substr($digits, 2)] = true;
                }
            }
            if ($tail !== '') {
                $keys[$tail] = true;
                $keys['91' . $tail] = true;
            }
            foreach (array_keys($keys) as $key) {
                if ($key === '') {
                    continue;
                }
                if (!isset($map[$key])) {
                    $map[$key] = [];
                }
                // Avoid duplicate lead ids under same key
                $exists = false;
                foreach ($map[$key] as $existing) {
                    if ((int) ($existing['id'] ?? 0) === $entry['id']) {
                        $exists = true;
                        break;
                    }
                }
                if (!$exists) {
                    $map[$key][] = $entry;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('lead_conversions_monthly load leads failed: ' . $e->getMessage());
    }

    return $map;
}

/**
 * @param array<string, list<array{id:int,branch_id:int,status_key:string,lead_name:string,phone_digits:string}>> $leadsByMobile
 * @return list<array{id:int,branch_id:int,status_key:string,lead_name:string,phone_digits:string}>
 */
function lcm_find_leads_for_invoice_mobile(array $leadsByMobile, string $mobileDigits, string $mobileTail): array
{
    $candidates = [];
    $seen = [];
    foreach ([$mobileDigits, $mobileTail, '91' . $mobileTail] as $key) {
        if ($key === '' || !isset($leadsByMobile[$key])) {
            continue;
        }
        foreach ($leadsByMobile[$key] as $lead) {
            $id = (int) ($lead['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $candidates[] = $lead;
        }
    }

    return $candidates;
}

/**
 * Mark lead converted in DB + sync in-memory map keys for that lead.
 *
 * @param array<string, list<array{id:int,branch_id:int,status_key:string,lead_name:string,phone_digits:string}>> $leadsByMobile
 */
function lcm_sync_lead_converted_in_map(array &$leadsByMobile, int $leadId): void
{
    foreach ($leadsByMobile as $key => $list) {
        foreach ($list as $i => $cached) {
            if ((int) ($cached['id'] ?? 0) === $leadId) {
                $leadsByMobile[$key][$i]['status_key'] = 'converted';
            }
        }
    }
}

lcm_log_line($logFile, '===== lead_conversions_monthly run start: ' . $runStarted . ' =====');
lcm_log_line($logFile, 'months: June / July / August 2026');

try {
    $pdo = db();
} catch (Throwable $e) {
    lcm_log_line($logFile, 'DB connection failed: ' . $e->getMessage());
    exit(1);
}

$convertedStatusId = lcm_converted_status_id($pdo);
if ($convertedStatusId <= 0) {
    lcm_log_line($logFile, 'ERROR: converted status id not found in allureone_leads_status; aborting.');
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
    lcm_log_line($logFile, 'Failed to load isDingg branches: ' . $e->getMessage());
    exit(1);
}

if ($branches === []) {
    lcm_log_line($logFile, 'No active isDingg branches found.');
    exit(0);
}

$leadsByMobile = lcm_load_leads_by_mobile($pdo);
$branchApiCount = 0;
$invoiceCount = 0;
$convertedCount = 0;
$alreadyConverted = 0;
$noLeadMatch = 0;
$didApi = false;

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

    $token = lcm_branch_session_key($branchId);
    if ($token === '') {
        continue;
    }

    foreach ($reportMonths as $month) {
        $monthLabel = (string) ($month['label'] ?? '');
        $startDate = (string) ($month['start'] ?? '');
        $endDate = (string) ($month['end'] ?? '');
        if ($startDate === '' || $endDate === '') {
            continue;
        }

        if ($didApi) {
            sleep(3);
        }

        $reportUrl = 'https://api.dingg.app/api/v1/vendor/report/sales?' . http_build_query(
            [
                'start_date' => $startDate,
                'report_type' => 'by_invoice',
                'end_date' => $endDate,
                'locations' => 'null',
                'app_type' => 'web',
                'range_type' => 'month',
            ],
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        $reportResp = lcm_dingg_get($reportUrl, $token);
        $didApi = true;
        $branchApiCount++;

        if (!$reportResp['ok']) {
            lcm_log_line(
                $logFile,
                'branch_id ' . $branchId . ' | ' . $branchLabel . ' | ' . $monthLabel
                . ' | sales report API failed: ' . ($reportResp['error'] ?? 'unknown error')
            );
            continue;
        }

        $invoices = lcm_report_invoice_rows(is_array($reportResp['json'] ?? null) ? $reportResp['json'] : []);

        foreach ($invoices as $inv) {
            if (!is_array($inv)) {
                continue;
            }
            $invoiceCount++;
            $info = lcm_invoice_mobile_and_sale($inv);
            if ($info === null) {
                continue;
            }

            $mobileDigits = $info['mobile_digits'];
            $mobileTail = $info['mobile_tail'];
            $amount = (float) $info['amount'];
            $amountDisplay = (string) $info['amount_display'];
            $invoiceNo = (string) $info['invoice'];

            $matches = lcm_find_leads_for_invoice_mobile($leadsByMobile, $mobileDigits, $mobileTail);
            if ($matches === []) {
                $noLeadMatch++;
                continue;
            }

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

                if (lcm_mark_lead_converted($pdo, $leadId, $convertedStatusId, $amount)) {
                    $convertedCount++;
                    lcm_sync_lead_converted_in_map($leadsByMobile, $leadId);
                    lcm_log_line(
                        $logFile,
                        'branch_id ' . $branchId . ' | ' . $branchLabel
                        . ' | ' . $monthLabel
                        . ' | invoice ' . $invoiceNo
                        . ' | mobile ' . ($mobileDigits !== '' ? $mobileDigits : $mobileTail)
                        . ' | lead_id ' . $leadId
                        . ($leadName !== '' ? (' | ' . $leadName) : '')
                        . ' | status updated to converted, amount = ' . $amountDisplay
                    );
                }
            }
        }
    }
}

$runEnded = date('Y-m-d H:i:s');
lcm_log_line(
    $logFile,
    '===== lead_conversions_monthly run end: ' . $runEnded
    . ' | api_calls=' . $branchApiCount
    . ' | invoices=' . $invoiceCount
    . ' | converted=' . $convertedCount
    . ' | already_converted=' . $alreadyConverted
    . ' | no_lead_match=' . $noLeadMatch
    . ' ====='
);

if (!$isCli) {
    echo "OK api_calls={$branchApiCount} invoices={$invoiceCount} converted={$convertedCount}\n";
    echo "Log: {$logFile}\n";
}

exit(0);
