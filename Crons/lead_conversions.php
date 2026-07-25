<?php
declare(strict_types=1);

/**
 * Hostinger cron: lead conversion check via Dingg client + bill APIs.
 *
 * CLI example:
 *   php Croms/lead_conversions.php
 *
 * HTTP cron (Hostinger):
 *   https://your-domain/Croms/lead_conversions.php
 *
 * Processes allureone_meta_leads with status: new, contacted, follow_up, booked.
 * Skips: lost, no_show, converted.
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
 * Strip non-digits, then remove leading country code 91 when present.
 */
function lc_search_mobile(string $rawPhone): string
{
    $digits = preg_replace('/\D+/', '', $rawPhone) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) > 10 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    }
    // Keep last 10 for typical India numbers if still longer
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

/**
 * Find Dingg client id from user_list response for the search mobile.
 *
 * @return array{id:int,name:string}|null
 */
function lc_find_client_from_search(array $json, string $searchMobile): ?array
{
    $needle = $searchMobile;
    $needleTail = strlen($needle) > 10 ? substr($needle, -10) : $needle;

    $candidates = [];
    $data = $json['data'] ?? null;

    // Shape A: data is list of clients
    if (is_array($data) && array_is_list($data)) {
        $candidates = $data;
    } elseif (is_array($data)) {
        // Shape B: data.user or nested users list
        if (isset($data['user']) && is_array($data['user'])) {
            $candidates[] = $data['user'];
        }
        if (isset($data['users']) && is_array($data['users'])) {
            foreach ($data['users'] as $u) {
                if (is_array($u)) {
                    $candidates[] = $u;
                }
            }
        }
        // Single object with id
        if (isset($data['id'])) {
            $candidates[] = $data;
        }
    }

    if (isset($json['user']) && is_array($json['user'])) {
        $candidates[] = $json['user'];
    }

    $fallback = null;
    foreach ($candidates as $row) {
        if (!is_array($row)) {
            continue;
        }
        // Prefer nested user.id when present
        $userNode = isset($row['user']) && is_array($row['user']) ? $row['user'] : $row;
        $id = (int) ($userNode['id'] ?? $row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $name = trim((string) ($userNode['name'] ?? $row['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) (($userNode['fname'] ?? $row['fname'] ?? '') . ' ' . ($userNode['lname'] ?? $row['lname'] ?? '')));
        }
        $mobile = preg_replace('/\D+/', '', (string) ($userNode['mobile'] ?? $row['mobile'] ?? '')) ?? '';
        $mobileTail = strlen($mobile) > 10 ? substr($mobile, -10) : $mobile;

        $match = [
            'id' => $id,
            'name' => $name !== '' ? $name : ('Client #' . $id),
        ];

        if ($mobileTail !== '' && $needleTail !== '' && $mobileTail === $needleTail) {
            return $match;
        }
        if ($fallback === null) {
            $fallback = $match;
        }
    }

    // If API returned exactly one client for this search, accept it
    if (count($candidates) === 1 && $fallback !== null) {
        return $fallback;
    }

    return null;
}

/**
 * @return list<array{selected_date:string,paid:string}>
 */
function lc_extract_valid_invoices(array $json): array
{
    $data = $json['data'] ?? [];
    if (!is_array($data)) {
        return [];
    }

    $out = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }
        $selectedDate = trim((string) ($row['selected_date'] ?? ''));
        $paidRaw = $row['paid'] ?? null;
        $paymentStatus = strtolower(trim((string) ($row['payment_status'] ?? '')));
        $cancelled = !empty($row['is_cancelled']) || !empty($row['cancelled']);

        if ($cancelled) {
            continue;
        }

        $paidNum = is_numeric($paidRaw) ? (float) $paidRaw : null;
        $isValid = $selectedDate !== ''
            && (
                ($paidNum !== null && $paidNum > 0)
                || in_array($paymentStatus, ['paid', 'success', 'completed', 'partial'], true)
                || ($paidRaw !== null && $paidRaw !== '' && $paidNum === null)
            );

        if (!$isValid) {
            continue;
        }

        if ($paidNum !== null) {
            $paidDisplay = number_format($paidNum, 2, '.', '');
        } else {
            $paidDisplay = trim((string) $paidRaw);
        }

        $out[] = [
            'selected_date' => $selectedDate,
            'paid' => $paidDisplay,
        ];
    }

    return $out;
}

$includeStatusKeys = ['new', 'contacted', 'follow_up', 'followup', 'booked'];
$skipStatusKeys = ['lost', 'no_show', 'noshow', 'converted'];

lc_log_line($logFile, '===== lead_conversions run start: ' . $runStarted . ' =====');

try {
    $pdo = db();
} catch (Throwable $e) {
    lc_log_line($logFile, 'DB connection failed: ' . $e->getMessage());
    exit(1);
}

try {
    $sql = 'SELECT m.id, m.branch_id, m.branch_name, m.lead_name, m.lead_phone_number, m.status,
                   s.status_key, s.status_label
            FROM allureone_meta_leads m
            LEFT JOIN allureone_leads_status s ON s.id = m.status
            ORDER BY m.id ASC';
    $leads = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    lc_log_line($logFile, 'Failed to load leads: ' . $e->getMessage());
    exit(1);
}

$processed = 0;
$skipped = 0;

foreach ($leads as $lead) {
    if (!is_array($lead)) {
        continue;
    }

    $statusKey = lc_normalize_status_key((string) ($lead['status_key'] ?? ''));
    if ($statusKey === '') {
        // Fallback: if status master missing, treat numeric status 1 as new-like and process
        $statusId = (int) ($lead['status'] ?? 0);
        if ($statusId <= 0) {
            $skipped++;
            continue;
        }
        // Without status_key we cannot safely skip lost/converted — skip unknown
        $skipped++;
        continue;
    }

    if (in_array($statusKey, $skipStatusKeys, true)) {
        $skipped++;
        continue;
    }
    if (!in_array($statusKey, $includeStatusKeys, true)) {
        $skipped++;
        continue;
    }

    $leadId = (int) ($lead['id'] ?? 0);
    $branchId = (int) ($lead['branch_id'] ?? 0);
    $branchName = trim((string) ($lead['branch_name'] ?? ''));
    if ($branchName === '') {
        $branchName = 'branch#' . $branchId;
    }
    $leadName = trim((string) ($lead['lead_name'] ?? ''));
    $rawPhone = (string) ($lead['lead_phone_number'] ?? '');
    $searchMobile = lc_search_mobile($rawPhone);

    lc_log_line($logFile, 'lead_id ' . $leadId . ($leadName !== '' ? (' | ' . $leadName) : '') . ' | status ' . $statusKey);
    lc_log_line($logFile, 'searching number ' . ($searchMobile !== '' ? $searchMobile : '(empty)'));

    if ($searchMobile === '' || strlen($searchMobile) < 3) {
        lc_log_line($logFile, 'skipped: invalid mobile number');
        lc_log_line($logFile, '');
        lc_log_line($logFile, '');
        $processed++;
        continue;
    }

    if ($branchId <= 0) {
        lc_log_line($logFile, 'skipped: missing branch_id on lead');
        lc_log_line($logFile, '');
        lc_log_line($logFile, '');
        $processed++;
        continue;
    }

    $token = lc_branch_session_key($branchId);
    if ($token === '') {
        lc_log_line($logFile, 'skipped: no Dingg session for branch_id ' . $branchId . ', ' . $branchName);
        lc_log_line($logFile, '');
        lc_log_line($logFile, '');
        $processed++;
        continue;
    }

    $searchUrl = 'https://api.dingg.app/api/v1/vendor/user_list?'
        . http_build_query(['search' => $searchMobile, 'is_web' => 'true'], '', '&', PHP_QUERY_RFC3986);
    $searchResp = lc_dingg_get($searchUrl, $token);
    if (!$searchResp['ok']) {
        lc_log_line($logFile, 'Dingg client search failed: ' . ($searchResp['error'] ?? 'unknown error'));
        lc_log_line($logFile, '');
        lc_log_line($logFile, '');
        $processed++;
        continue;
    }

    $client = lc_find_client_from_search(
        is_array($searchResp['json'] ?? null) ? $searchResp['json'] : [],
        $searchMobile
    );

    if ($client === null) {
        lc_log_line($logFile, 'client not found in branch_id ' . $branchId . ', ' . $branchName);
        lc_log_line($logFile, '');
        lc_log_line($logFile, '');
        $processed++;
        continue;
    }

    $clientId = (int) $client['id'];
    lc_log_line($logFile, 'found client in branch_id ' . $branchId . ', ' . $branchName);

    $billUrl = 'https://api.dingg.app/api/v1/vendor/customer/bill?'
        . http_build_query(['id' => (string) $clientId], '', '&', PHP_QUERY_RFC3986);
    $billResp = lc_dingg_get($billUrl, $token);
    if (!$billResp['ok']) {
        lc_log_line($logFile, 'Dingg bill API failed: ' . ($billResp['error'] ?? 'unknown error'));
        lc_log_line($logFile, '');
        lc_log_line($logFile, '');
        $processed++;
        continue;
    }

    $invoices = lc_extract_valid_invoices(
        is_array($billResp['json'] ?? null) ? $billResp['json'] : []
    );

    if ($invoices === []) {
        lc_log_line($logFile, 'no valid invoice found');
    } else {
        foreach ($invoices as $inv) {
            lc_log_line(
                $logFile,
                'found invoice ' . $inv['selected_date'] . ', amount  - Rs. ' . $inv['paid']
            );
        }
    }

    // Two blank lines before next lead
    lc_log_line($logFile, '');
    lc_log_line($logFile, '');
    $processed++;

    // Small pause to avoid hammering Dingg
    usleep(150000);
}

$runEnded = date('Y-m-d H:i:s');
lc_log_line(
    $logFile,
    '===== lead_conversions run end: ' . $runEnded
    . ' | processed=' . $processed
    . ' | skipped_status=' . $skipped
    . ' ====='
);

if (!$isCli) {
    echo "OK processed={$processed} skipped_status={$skipped}\n";
    echo "Log: {$logFile}\n";
}

exit(0);
