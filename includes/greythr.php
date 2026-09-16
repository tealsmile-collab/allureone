<?php
declare(strict_types=1);

/**
 * greytHR API helpers — token cached server-side (APCu preferred).
 */

const GREYTHR_TOKEN_APCU_KEY = 'allureone_greythr_access_token_v1';
const GREYTHR_EMP_MAP_APCU_KEY = 'allureone_greythr_employee_map_v2';

/**
 * @return array<string, mixed>
 */
function greythr_config(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }
    $all = require __DIR__ . '/../config.php';
    $cfg = is_array($all['greythr'] ?? null) ? $all['greythr'] : [];

    return $cfg;
}

function greythr_domain(): string
{
    return trim((string) (greythr_config()['domain'] ?? 'allurethai.greythr.com'));
}

function greythr_base_url(): string
{
    $base = rtrim(trim((string) (greythr_config()['base_url'] ?? 'https://api.greythr.com')), '/');

    return $base !== '' ? $base : 'https://api.greythr.com';
}

function greythr_ssl_verify(): bool
{
    return !empty(greythr_config()['ssl_verify']);
}

function greythr_cache_store(string $key, mixed $value, int $ttlSeconds): bool
{
    $ttlSeconds = max(60, $ttlSeconds);
    if (function_exists('apcu_store')) {
        return (bool) apcu_store($key, $value, $ttlSeconds);
    }
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'allureone_greythr';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $payload = ['expires_at' => time() + $ttlSeconds, 'value' => $value];
    $path = $dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';

    return @file_put_contents($path, json_encode($payload), LOCK_EX) !== false;
}

function greythr_cache_fetch(string $key): mixed
{
    if (function_exists('apcu_fetch')) {
        $ok = false;
        $val = apcu_fetch($key, $ok);
        if ($ok) {
            return $val;
        }

        return null;
    }
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'allureone_greythr' . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }
    $expiresAt = (int) ($decoded['expires_at'] ?? 0);
    if ($expiresAt > 0 && time() >= $expiresAt) {
        @unlink($path);

        return null;
    }

    return $decoded['value'] ?? null;
}

function greythr_cache_delete(string $key): void
{
    if (function_exists('apcu_delete')) {
        apcu_delete($key);
    }
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'allureone_greythr' . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    if (is_file($path)) {
        @unlink($path);
    }
}

/**
 * @return array{ok:bool,token?:string,error?:string}
 */
function greythr_fetch_new_access_token(): array
{
    $cfg = greythr_config();
    $domain = greythr_domain();
    $tokenPath = trim((string) ($cfg['token_path'] ?? '/uas/v1/oauth2/client-token'));
    if ($tokenPath === '' || $tokenPath[0] !== '/') {
        $tokenPath = '/uas/v1/oauth2/client-token';
    }
    $url = 'https://' . $domain . $tokenPath;

    $user = trim((string) ($cfg['username'] ?? ''));
    $pass = trim((string) ($cfg['password'] ?? ''));
    if ($user === '' || $pass === '') {
        return ['ok' => false, 'error' => 'greytHR credentials are not configured (greythr.username / greythr.password).'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'Could not init greytHR token request.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_USERPWD => $user . ':' . $pass,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => greythr_ssl_verify(),
        CURLOPT_SSL_VERIFYHOST => greythr_ssl_verify() ? 2 : 0,
    ]);
    $body = (string) curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = (string) curl_error($ch);
    if (PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }

    if ($err !== '') {
        error_log('AllureOne greytHR token curl error: ' . $err);

        return ['ok' => false, 'error' => 'greytHR token request failed.'];
    }
    $json = json_decode($body, true);
    if ($http < 200 || $http >= 300 || !is_array($json)) {
        error_log('AllureOne greytHR token HTTP ' . $http . ' body=' . substr($body, 0, 500));

        return ['ok' => false, 'error' => 'Could not obtain greytHR access token.'];
    }
    $token = trim((string) ($json['access_token'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'error' => 'greytHR access_token missing in response.'];
    }
    $expiresIn = (int) ($json['expires_in'] ?? 3600);
    // Refresh a bit before expiry.
    $ttl = max(300, $expiresIn - 300);
    greythr_cache_store(GREYTHR_TOKEN_APCU_KEY, $token, $ttl);

    return ['ok' => true, 'token' => $token];
}

/**
 * @return array{ok:bool,token?:string,error?:string}
 */
function greythr_get_access_token(bool $forceRefresh = false): array
{
    if (!$forceRefresh) {
        $cached = greythr_cache_fetch(GREYTHR_TOKEN_APCU_KEY);
        if (is_string($cached) && $cached !== '') {
            return ['ok' => true, 'token' => $cached];
        }
    } else {
        greythr_cache_delete(GREYTHR_TOKEN_APCU_KEY);
    }

    return greythr_fetch_new_access_token();
}

/**
 * @param array<string, string> $extraHeaders
 * @return array{ok:bool,http:int,json:?array,body:string,error?:string}
 */
function greythr_api_request(string $method, string $pathOrUrl, array $query = [], ?array $jsonBody = null, array $extraHeaders = []): array
{
    $tokenRes = greythr_get_access_token(false);
    if (!($tokenRes['ok'] ?? false)) {
        return [
            'ok' => false,
            'http' => 0,
            'json' => null,
            'body' => '',
            'error' => (string) ($tokenRes['error'] ?? 'Missing greytHR token.'),
        ];
    }
    $token = (string) ($tokenRes['token'] ?? '');

    $url = $pathOrUrl;
    if (!preg_match('#^https?://#i', $url)) {
        $url = greythr_base_url() . (str_starts_with($pathOrUrl, '/') ? $pathOrUrl : ('/' . $pathOrUrl));
    }
    if ($query !== []) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    $headers = array_merge([
        'Accept: application/json',
        'ACCESS-TOKEN: ' . $token,
        'x-greythr-domain: ' . greythr_domain(),
    ], $extraHeaders);

    $attempt = static function (string $accessToken) use ($method, $url, $jsonBody, $headers): array {
        $hdrs = $headers;
        foreach ($hdrs as $i => $h) {
            if (stripos($h, 'ACCESS-TOKEN:') === 0) {
                $hdrs[$i] = 'ACCESS-TOKEN: ' . $accessToken;
            }
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'http' => 0, 'json' => null, 'body' => '', 'error' => 'curl_init failed'];
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => greythr_ssl_verify(),
            CURLOPT_SSL_VERIFYHOST => greythr_ssl_verify() ? 2 : 0,
        ];
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hdrs[] = 'Content-Type: application/json';
            $opts[CURLOPT_HTTPHEADER] = $hdrs;
        }
        curl_setopt_array($ch, $opts);
        $body = (string) curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = (string) curl_error($ch);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }
        if ($err !== '') {
            return ['ok' => false, 'http' => $http, 'json' => null, 'body' => $body, 'error' => $err];
        }
        $json = json_decode($body, true);

        return [
            'ok' => $http >= 200 && $http < 300,
            'http' => $http,
            'json' => is_array($json) ? $json : null,
            'body' => $body,
        ];
    };

    $res = $attempt($token);
    if (($res['http'] ?? 0) === 401 || ($res['http'] ?? 0) === 403) {
        $refresh = greythr_get_access_token(true);
        if ($refresh['ok'] ?? false) {
            $res = $attempt((string) $refresh['token']);
        }
    }

    return $res;
}

/**
 * @return array{ok:bool,employees:list<array<string,mixed>>,pages:?array,error?:string}
 */
function greythr_list_employees(int $page = 1, int $size = 20): array
{
    // UI pages are 1-based; greytHR employee API uses 0-based page index.
    $uiPage = max(1, $page);
    $apiPage = $uiPage - 1;
    $size = max(1, min(100, $size));
    $res = greythr_api_request('GET', '/employee/v2/employees', [
        'page' => $apiPage,
        'size' => $size,
    ]);
    if (!($res['ok'] ?? false)) {
        return [
            'ok' => false,
            'employees' => [],
            'pages' => null,
            'error' => (string) ($res['error'] ?? ('greytHR employees request failed (HTTP ' . (int) ($res['http'] ?? 0) . ').')),
        ];
    }
    $json = is_array($res['json'] ?? null) ? $res['json'] : [];
    $data = $json['data'] ?? [];
    if (!is_array($data)) {
        $data = [];
    }

    return [
        'ok' => true,
        'employees' => array_values(array_filter($data, 'is_array')),
        'pages' => is_array($json['pages'] ?? null) ? $json['pages'] : null,
    ];
}

/**
 * @return array{ok:bool,employee:?array<string,mixed>,error?:string}
 */
function greythr_get_employee(int $employeeId): array
{
    if ($employeeId <= 0) {
        return ['ok' => false, 'employee' => null, 'error' => 'Invalid employee id.'];
    }
    $res = greythr_api_request('GET', '/employee/v2/employees/' . $employeeId);
    if (!($res['ok'] ?? false)) {
        return [
            'ok' => false,
            'employee' => null,
            'error' => (string) ($res['error'] ?? ('greytHR employee request failed (HTTP ' . (int) ($res['http'] ?? 0) . ').')),
        ];
    }
    $json = is_array($res['json'] ?? null) ? $res['json'] : null;
    if (!is_array($json)) {
        return ['ok' => false, 'employee' => null, 'error' => 'Invalid employee response.'];
    }

    return ['ok' => true, 'employee' => $json];
}

/**
 * Employee muster for a date range.
 *
 * @return array{ok:bool,records:list<array<string,mixed>>,error?:string}
 */
function greythr_employee_muster(int $employeeId, string $startYmd, string $endYmd): array
{
    if ($employeeId <= 0) {
        return ['ok' => false, 'records' => [], 'error' => 'Invalid employee id.'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startYmd) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endYmd) !== 1) {
        return ['ok' => false, 'records' => [], 'error' => 'Invalid start/end date.'];
    }
    $res = greythr_api_request('GET', '/attendance/v2/employee/' . $employeeId . '/muster', [
        'start' => $startYmd,
        'end' => $endYmd,
    ]);
    if (!($res['ok'] ?? false)) {
        return [
            'ok' => false,
            'records' => [],
            'error' => (string) ($res['error'] ?? ('greytHR muster request failed (HTTP ' . (int) ($res['http'] ?? 0) . ').')),
        ];
    }
    $json = is_array($res['json'] ?? null) ? $res['json'] : [];
    $records = $json['records'] ?? ($json['data'] ?? []);
    if (!is_array($records)) {
        $records = [];
    }

    return [
        'ok' => true,
        'records' => array_values(array_filter($records, 'is_array')),
    ];
}

/**
 * Format greytHR date/time string to HH:MM (or date Y-m-d).
 */
function greythr_format_clock(?string $raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '';
    }
    // "2026-09-02T14:55:47.524" or "14:55"
    if (preg_match('/T(\d{2}:\d{2})/', $raw, $m) === 1) {
        return $m[1];
    }
    if (preg_match('/^(\d{1,2}:\d{2})/', $raw, $m) === 1) {
        return strlen($m[1]) === 4 ? ('0' . $m[1]) : $m[1];
    }

    return $raw;
}

/**
 * Normalize muster records into date / in / out rows.
 * In/Out: shift.startTime / shift.endTime (per product requirement),
 * with firstInTime / lastOutTime preferred when present (actual punches).
 *
 * @param list<array<string,mixed>> $records
 * @return list<array{date:string,in_time:string,out_time:string}>
 */
function greythr_muster_table_rows(array $records): array
{
    $rows = [];
    foreach ($records as $rec) {
        $summary = is_array($rec['summary'] ?? null) ? $rec['summary'] : $rec;
        $date = trim((string) ($summary['attendanceDate'] ?? ''));
        if ($date === '' && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) ($rec['attendanceDate'] ?? ''), $dm) === 1) {
            $date = $dm[0];
        }
        if ($date === '') {
            continue;
        }
        $shift = is_array($summary['shift'] ?? null) ? $summary['shift'] : [];
        $firstIn = trim((string) ($summary['firstInTime'] ?? ''));
        $lastOut = trim((string) ($summary['lastOutTime'] ?? ''));
        $shiftIn = trim((string) ($shift['startTime'] ?? ''));
        $shiftOut = trim((string) ($shift['endTime'] ?? ''));

        // Prefer actual punches; otherwise use shift start/end as In/Out.
        $inRaw = $firstIn !== '' ? $firstIn : $shiftIn;
        $outRaw = $lastOut !== '' ? $lastOut : $shiftOut;
        // If no punch and we only have schedule, show — for absent-looking days.
        $session = strtoupper(trim((string) ($summary['session1Label'] ?? ($summary['session1hLabel'] ?? ''))));
        $absent = ($firstIn === '' && $lastOut === '') && ($session === 'A' || trim((string) ($summary['absentReason'] ?? '')) !== '');
        if ($absent) {
            $inDisp = '—';
            $outDisp = '—';
        } else {
            $inDisp = greythr_format_clock($inRaw);
            $outDisp = greythr_format_clock($outRaw);
            if ($inDisp === '') {
                $inDisp = '—';
            }
            if ($outDisp === '') {
                $outDisp = '—';
            }
        }

        $rows[] = [
            'date' => $date,
            'in_time' => $inDisp,
            'out_time' => $outDisp,
        ];
    }

    usort($rows, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

    return $rows;
}

/**
 * @return array{ok:bool,rows:list<array<string,mixed>>,pages:?array,error?:string}
 */
function greythr_attendance_insights(string $startYmd, string $endYmd, int $page = 1, int $size = 20): array
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startYmd) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $endYmd) !== 1) {
        return ['ok' => false, 'rows' => [], 'pages' => null, 'error' => 'Invalid start/end date.'];
    }
    // UI pages are 1-based; greytHR attendance insights uses 0-based page index.
    $uiPage = max(1, $page);
    $apiPage = $uiPage - 1;
    $size = max(1, min(100, $size));
    $res = greythr_api_request('GET', '/attendance/v2/employee/insights', [
        'start' => $startYmd,
        'end' => $endYmd,
        'page' => $apiPage,
        'size' => $size,
    ]);
    if (!($res['ok'] ?? false)) {
        return [
            'ok' => false,
            'rows' => [],
            'pages' => null,
            'error' => (string) ($res['error'] ?? ('greytHR attendance request failed (HTTP ' . (int) ($res['http'] ?? 0) . ').')),
        ];
    }
    $json = is_array($res['json'] ?? null) ? $res['json'] : [];
    $data = $json['data'] ?? [];
    if (!is_array($data)) {
        $data = [];
    }

    return [
        'ok' => true,
        'rows' => array_values(array_filter($data, 'is_array')),
        'pages' => is_array($json['pages'] ?? null) ? $json['pages'] : null,
    ];
}

/**
 * Display name from an employee record.
 *
 * @param array<string,mixed> $emp
 */
function greythr_employee_display_name(array $emp): string
{
    $id = (int) ($emp['employeeId'] ?? 0);
    $name = trim((string) ($emp['name'] ?? ''));
    if ($name === '') {
        $parts = array_filter([
            trim((string) ($emp['firstName'] ?? '')),
            trim((string) ($emp['middleName'] ?? '')),
            trim((string) ($emp['lastName'] ?? '')),
        ], static fn (string $p): bool => $p !== '');
        $name = $parts !== [] ? implode(' ', $parts) : '';
    }
    if ($name === '') {
        $empNo = trim((string) ($emp['employeeNo'] ?? ''));
        $name = $empNo !== '' ? $empNo : ($id > 0 ? ('Employee #' . $id) : 'Unknown');
    }

    return $name;
}

/**
 * Fetch every employee page and collate into employeeId => employee row.
 *
 * @return array{ok:bool,employees:array<int,array<string,mixed>>,map:array<int,string>,error?:string}
 */
function greythr_fetch_all_employees(bool $forceRefresh = false): array
{
    if (!$forceRefresh) {
        $cached = greythr_cache_fetch(GREYTHR_EMP_MAP_APCU_KEY);
        if (is_array($cached) && isset($cached['employees'], $cached['map']) && is_array($cached['employees']) && is_array($cached['map']) && $cached['map'] !== []) {
            $employees = [];
            foreach ($cached['employees'] as $k => $row) {
                if (!is_array($row)) {
                    continue;
                }
                $employees[(int) $k] = $row;
            }
            $map = [];
            foreach ($cached['map'] as $k => $v) {
                $id = (int) $k;
                $name = trim((string) $v);
                if ($id > 0 && $name !== '') {
                    $map[$id] = $name;
                }
            }
            if ($map !== []) {
                return ['ok' => true, 'employees' => $employees, 'map' => $map];
            }
        }
    } else {
        greythr_cache_delete(GREYTHR_EMP_MAP_APCU_KEY);
    }

    $employees = [];
    $map = [];
    $size = 50;
    $page = 1; // 1-based UI page (converted to 0-based inside list helper)
    $totalPages = 1;
    $lastError = null;

    // First page — learn totalPages / totalElements, then walk every page.
    $first = greythr_list_employees(1, $size);
    if (!($first['ok'] ?? false)) {
        return [
            'ok' => false,
            'employees' => [],
            'map' => [],
            'error' => (string) ($first['error'] ?? 'Could not load employees.'),
        ];
    }
    $pagesMeta = is_array($first['pages'] ?? null) ? $first['pages'] : null;
    $totalPages = max(1, (int) ($pagesMeta['totalPages'] ?? 1));
    $totalElements = (int) ($pagesMeta['totalElements'] ?? 0);

    for ($page = 1; $page <= $totalPages; $page++) {
        $res = ($page === 1) ? $first : greythr_list_employees($page, $size);
        if (!($res['ok'] ?? false)) {
            $lastError = (string) ($res['error'] ?? ('Failed loading employees page ' . $page . '.'));
            break;
        }
        foreach ($res['employees'] as $emp) {
            $id = (int) ($emp['employeeId'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $employees[$id] = $emp;
            $map[$id] = greythr_employee_display_name($emp);
        }
        // Refresh totalPages if later pages report a different value.
        if (is_array($res['pages'] ?? null)) {
            $tp = (int) ($res['pages']['totalPages'] ?? $totalPages);
            if ($tp > $totalPages) {
                $totalPages = $tp;
            }
        }
    }

    if ($map === []) {
        return [
            'ok' => false,
            'employees' => [],
            'map' => [],
            'error' => $lastError ?? 'No employees returned from greytHR.',
        ];
    }

    // Soft warning if we got fewer than totalElements (still usable).
    if ($totalElements > 0 && count($employees) < $totalElements) {
        error_log('AllureOne greytHR employees collated ' . count($employees) . ' of ' . $totalElements);
    }

    greythr_cache_store(GREYTHR_EMP_MAP_APCU_KEY, [
        'employees' => $employees,
        'map' => $map,
    ], 900);

    return ['ok' => true, 'employees' => $employees, 'map' => $map];
}

/**
 * @return array{ok:bool,map:array<int,string>,error?:string}
 */
function greythr_employee_name_map(bool $forceRefresh = false): array
{
    $all = greythr_fetch_all_employees($forceRefresh);

    return [
        'ok' => (bool) ($all['ok'] ?? false),
        'map' => is_array($all['map'] ?? null) ? $all['map'] : [],
        'error' => isset($all['error']) ? (string) $all['error'] : null,
    ];
}

function greythr_insight_average(array $insights, string $type): string
{
    $averages = $insights['averages'] ?? null;
    if (!is_array($averages)) {
        return '';
    }
    foreach ($averages as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (trim((string) ($row['type'] ?? '')) !== $type) {
            continue;
        }
        $avg = $row['average'] ?? null;
        if ($avg === null) {
            return '';
        }

        return trim((string) $avg);
    }

    return '';
}

function greythr_is_present_intime(string $inTime): bool
{
    $t = trim($inTime);
    if ($t === '' || $t === '00:00' || $t === '0:00' || $t === '00:00:00') {
        return false;
    }

    return true;
}
