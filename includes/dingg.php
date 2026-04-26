<?php
declare(strict_types=1);

/** @internal Last-resort session key if openssl_encrypt fails (same trust boundary as encrypted blob). */
const DINGG_SESSION_TOKEN_PLAIN = 'dingg_pos_plain';

function dingg_token_diag_clear(): void
{
    $GLOBALS['__allureone_dingg_token_diag'] = '';
}

function dingg_token_diag_set(string $msg): void
{
    $GLOBALS['__allureone_dingg_token_diag'] = $msg;
}

function dingg_token_diag_append(string $msg): void
{
    $g = (string) ($GLOBALS['__allureone_dingg_token_diag'] ?? '');
    $GLOBALS['__allureone_dingg_token_diag'] = $g === '' ? $msg : ($g . ' ' . $msg);
}

function dingg_token_diag_get(): string
{
    return (string) ($GLOBALS['__allureone_dingg_token_diag'] ?? '');
}

function dingg_auth_expired_user_message(): string
{
    return 'Your Dingg session has expired. Please log out and log in again, then try.';
}

/**
 * True when Dingg rejected the bearer token (HTTP 401/403 or equivalent JSON/body).
 */
function dingg_response_looks_unauthorized(int $http, string $body): bool
{
    if ($http === 401 || $http === 403) {
        return true;
    }

    $j = json_decode($body, true);
    if (is_array($j)) {
        $code = (int) ($j['code'] ?? 0);
        if ($code === 401 || $code === 403) {
            return true;
        }
        $msg = strtolower((string) ($j['message'] ?? ''));
        if ($msg !== '' && preg_match('/unauthor|session\s*expir|token\s*expir|invalid\s*token|login\s*required|access\s*denied/i', $msg) === 1) {
            return true;
        }
        if (($j['success'] ?? null) === false && $msg !== '' && str_contains($msg, 'author')) {
            return true;
        }
    }

    if ($http >= 400 && $http < 500 && $http !== 404) {
        $lower = strtolower($body);
        if (str_contains($lower, 'unauthor') || str_contains($lower, 'token expired') || str_contains($lower, 'invalid token')) {
            return true;
        }
    }

    return false;
}

/**
 * Sets a one-shot session notice so the next layout can show an auth-expired message (logged-in users only).
 */
function dingg_note_unauthorized_if_needed(int $http, string $body): void
{
    if (!dingg_response_looks_unauthorized($http, $body)) {
        return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    if (empty($_SESSION['user_id'])) {
        return;
    }
    $_SESSION['dingg_auth_expired_notice'] = dingg_auth_expired_user_message();
}

function dingg_secret_key_bytes(): string
{
    $config = require __DIR__ . '/../config.php';
    $secret = (string) (($config['dingg']['encryption_key'] ?? 'allureone-dingg-default'));

    return hash('sha256', $secret, true);
}

function dingg_encrypt_session_token(string $plain): void
{
    if ($plain === '') {
        dingg_clear_session_encrypted_token();
        return;
    }
    unset($_SESSION[DINGG_SESSION_TOKEN_PLAIN]);

    if (!function_exists('openssl_encrypt')) {
        error_log('Dingg: PHP openssl extension is not enabled; pos token stored in session without AES (enable ext-openssl).');
        $_SESSION[DINGG_SESSION_TOKEN_PLAIN] = $plain;
        unset($_SESSION['dingg_pos_enc'], $_SESSION['dingg_token'], $_SESSION['dingg_authorization'], $_SESSION['dingg_token_fetched_at']);
        return;
    }

    $key = dingg_secret_key_bytes();
    $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        error_log('Dingg: openssl_encrypt failed; using plain session fallback for pos token.');
        $_SESSION[DINGG_SESSION_TOKEN_PLAIN] = $plain;
        unset($_SESSION['dingg_token'], $_SESSION['dingg_authorization'], $_SESSION['dingg_token_fetched_at']);
        return;
    }
    $_SESSION['dingg_pos_enc'] = base64_encode($iv . $cipher);
    unset($_SESSION['dingg_token'], $_SESSION['dingg_authorization'], $_SESSION['dingg_token_fetched_at']);
}

function dingg_clear_session_encrypted_token(): void
{
    unset(
        $_SESSION['dingg_pos_enc'],
        $_SESSION[DINGG_SESSION_TOKEN_PLAIN],
        $_SESSION['dingg_token'],
        $_SESSION['dingg_authorization'],
        $_SESSION['dingg_token_fetched_at']
    );
}

/**
 * Decrypt pos token from session (for subsequent Dingg API calls).
 */
function dingg_get_pos_token_from_session(): ?string
{
    if (isset($_SESSION[DINGG_SESSION_TOKEN_PLAIN]) && is_string($_SESSION[DINGG_SESSION_TOKEN_PLAIN])) {
        $p = trim($_SESSION[DINGG_SESSION_TOKEN_PLAIN]);
        if ($p !== '') {
            return $p;
        }
    }
    if (empty($_SESSION['dingg_pos_enc']) || !is_string($_SESSION['dingg_pos_enc'])) {
        return null;
    }
    if (!function_exists('openssl_decrypt')) {
        error_log('Dingg: PHP openssl extension is not enabled; cannot read encrypted session token (enable ext-openssl).');
        unset($_SESSION['dingg_pos_enc']);

        return null;
    }
    $raw = base64_decode($_SESSION['dingg_pos_enc'], true);
    if ($raw === false || strlen($raw) < 17) {
        unset($_SESSION['dingg_pos_enc']);
        return null;
    }
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $key = dingg_secret_key_bytes();
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    if ($plain === false || $plain === '') {
        unset($_SESSION['dingg_pos_enc']);
        return null;
    }
    return $plain;
}

/**
 * Normalize token before use in headers (strip accidental "Bearer " prefix).
 */
function dingg_normalize_pos_token(string $token): string
{
    $token = trim($token);
    if ($token === '') {
        return '';
    }

    return preg_replace('/^bearer\s+/i', '', $token) ?? $token;
}

/**
 * Value part only (no "Authorization: " prefix), for helpers that need the raw scheme + token.
 */
function dingg_authorization_value_for_token(string $token): string
{
    $token = dingg_normalize_pos_token($token);
    if ($token === '') {
        return '';
    }

    $cfg = require __DIR__ . '/../config.php';
    $template = (string) (($cfg['dingg']['authorization_value'] ?? 'Bearer %s'));
    if (strpos($template, '%s') === false) {
        $template = 'Bearer %s';
    }

    return sprintf($template, trim($token));
}

/**
 * Headers for authenticated Dingg calls: Authorization (configurable) and optional posToken.
 *
 * @return list<string>
 */
function dingg_auth_http_headers(string $token): array
{
    $token = dingg_normalize_pos_token($token);
    if ($token === '') {
        return [];
    }
    $cfg = require __DIR__ . '/../config.php';
    $dingg = $cfg['dingg'] ?? [];
    $headers = ['Authorization: ' . dingg_authorization_value_for_token($token)];
    if (!empty($dingg['send_pos_token_header'])) {
        $headers[] = 'posToken: ' . $token;
    }

    return $headers;
}

/**
 * Authorization header value for Dingg APIs (legacy helper).
 */
function dingg_authorization_header_value(): ?string
{
    $t = dingg_get_pos_token_from_session();
    if ($t === null || $t === '') {
        return null;
    }

    return dingg_authorization_value_for_token($t);
}

/**
 * @return array{http:int, body:string, curl_err:string, curl_errno:int}
 */
function dingg_curl_exec_once(string $url, string $method, array $headers, ?string $jsonBody, bool $sslVerify): array
{
    $method = strtoupper($method);
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_USERAGENT => 'AllureOne/1.0 (PHP; +https://api.dingg.app)',
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
        $opts[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
    }
    if (defined('CURL_IPRESOLVE_V4')) {
        $opts[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
    }
    if ($sslVerify) {
        $opts[CURLOPT_SSL_VERIFYPEER] = true;
        $opts[CURLOPT_SSL_VERIFYHOST] = 2;
    } else {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
    }
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $jsonBody ?? '';
    } elseif ($method === 'GET') {
        $opts[CURLOPT_HTTPGET] = true;
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = $jsonBody;
        }
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $curlErr = curl_error($ch);
    $curlErrno = (int) curl_errno($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return [
        'http' => $http,
        'body' => $body !== false ? (string) $body : '',
        'curl_err' => $curlErr,
        'curl_errno' => $curlErrno,
        'ok_transfer' => $body !== false,
    ];
}

/**
 * HTTPS via PHP streams when cURL returns HTTP 0 or fails (some hosts block or misconfigure cURL).
 *
 * @param list<string> $headers
 * @return array{http:int, body:string, ok:bool}
 */
function dingg_http_streams_request(string $method, string $url, array $headers, ?string $jsonBody, bool $sslVerify): array
{
    $method = strtoupper($method);
    $hdrLines = array_merge(['User-Agent: AllureOne/1.0 (PHP-streams)'], $headers);
    $headerStr = implode("\r\n", $hdrLines);
    $httpOpts = [
        'method' => $method,
        'header' => $headerStr . "\r\n",
        'timeout' => 45,
        'ignore_errors' => true,
    ];
    if ($method === 'POST') {
        $httpOpts['content'] = $jsonBody ?? '';
    }
    $ctx = stream_context_create([
        'http' => $httpOpts,
        'ssl' => [
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    $http = dingg_streams_parse_http_status();

    return [
        'http' => $http,
        'body' => $body !== false ? (string) $body : '',
        'ok' => $body !== false,
    ];
}

function dingg_streams_parse_http_status(): int
{
    if (function_exists('http_get_last_response_headers')) {
        $hdrs = http_get_last_response_headers();
        if (is_array($hdrs) && isset($hdrs[0]) && preg_match('/HTTP\/\S+\s+(\d{3})/', (string) $hdrs[0], $m)) {
            return (int) $m[1];
        }
    }

    return 0;
}

/**
 * @param list<string> $headers
 * @return array{http:int, body:string, ok:bool}
 */
function dingg_http_streams_try(string $method, string $url, array $headers, ?string $jsonBody): array
{
    if (!filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        dingg_token_diag_append('allow_url_fopen is Off; cannot use stream fallback.');

        return ['http' => 0, 'body' => '', 'ok' => false];
    }

    $cfg = require __DIR__ . '/../config.php';
    $verify = (bool) (($cfg['dingg']['ssl_verify'] ?? true));
    $autoInsecure = (bool) (($cfg['dingg']['ssl_insecure_retry'] ?? true));

    $r = dingg_http_streams_request($method, $url, $headers, $jsonBody, $verify);
    if ($r['http'] > 0 || ($r['ok'] && $r['body'] !== '')) {
        return $r;
    }
    if ($verify && $autoInsecure) {
        error_log('Dingg: streams retry with ssl verify off');
        $r2 = dingg_http_streams_request($method, $url, $headers, $jsonBody, false);
        if ($r2['http'] > 0 || ($r2['ok'] && $r2['body'] !== '')) {
            dingg_token_diag_append('Stream HTTPS used with verify_peer off (ssl_insecure_retry).');

            return $r2;
        }

        return $r2;
    }

    return $r;
}

/**
 * Low-level HTTP for Dingg (cURL + stream fallback). Callers supply full header list.
 *
 * @param list<string> $headers
 * @return array{http:int, body:string, curl_errno?:int, curl_err?:string, transport?:string}
 */
function dingg_http_execute(string $method, string $url, array $headers, ?string $jsonBody): array
{
    $method = strtoupper($method);

    if (function_exists('curl_init')) {
        $cfg = require __DIR__ . '/../config.php';
        $dinggSsl = (bool) (($cfg['dingg']['ssl_verify'] ?? true));
        $autoInsecure = (bool) (($cfg['dingg']['ssl_insecure_retry'] ?? true));

        $r = dingg_curl_exec_once($url, $method, $headers, $jsonBody, $dinggSsl);
        $sslRetried = false;

        if (!$r['ok_transfer'] && $dinggSsl && $autoInsecure) {
            error_log(
                'Dingg: retrying with SSL verification disabled (dingg.ssl_insecure_retry). errno='
                . $r['curl_errno'] . ' ' . $r['curl_err']
            );
            $sslRetried = true;
            $r = dingg_curl_exec_once($url, $method, $headers, $jsonBody, false);
        }

        $curlOk = $r['ok_transfer'] && $r['http'] > 0;
        if ($curlOk) {
            return ['http' => $r['http'], 'body' => $r['body'], 'transport' => 'curl'];
        }

        $streamResult = dingg_http_streams_try($method, $url, $headers, $jsonBody);
        if ($streamResult['http'] > 0 || ($streamResult['ok'] && $streamResult['body'] !== '')) {
            if ($r['http'] === 0 || !$r['ok_transfer']) {
                dingg_token_diag_append('Used PHP stream HTTPS fallback (cURL unusable or HTTP 0).');
            }

            return [
                'http' => $streamResult['http'],
                'body' => $streamResult['body'],
                'transport' => 'stream',
                'curl_errno' => $r['curl_errno'],
                'curl_err' => $r['curl_err'],
            ];
        }

        if (!$r['ok_transfer']) {
            $detail = $r['curl_err'] !== ''
                ? ('cURL #' . $r['curl_errno'] . ': ' . $r['curl_err'])
                : ('cURL failed (errno ' . $r['curl_errno'] . ', HTTP ' . $r['http'] . ')' . ($sslRetried ? ' after SSL retry' : ''));
            error_log('Dingg curl error: ' . $detail);
            dingg_token_diag_append($detail);
        } elseif ($r['http'] === 0) {
            $detail = 'cURL HTTP 0; stream fallback also failed. errno=' . $r['curl_errno'] . ' err=' . $r['curl_err'];
            error_log('Dingg: ' . $detail);
            dingg_token_diag_append($detail);
        }

        return [
            'http' => $r['http'] ?: 0,
            'body' => $r['ok_transfer'] ? $r['body'] : '',
            'curl_errno' => $r['curl_errno'],
            'curl_err' => $r['curl_err'],
            'transport' => 'curl_failed',
        ];
    }

    error_log('Dingg: cURL extension not available; using file_get_contents for HTTPS (less reliable).');

    $headerStr = implode("\r\n", $headers);
    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => $headerStr !== '' ? $headerStr . "\r\n" : '',
            'content' => $jsonBody ?? '',
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    $http = 0;
    if (function_exists('http_get_last_response_headers')) {
        $hdrs = http_get_last_response_headers();
        if (is_array($hdrs) && isset($hdrs[0]) && preg_match('/HTTP\/\S+\s+(\d{3})/', (string) $hdrs[0], $m)) {
            $http = (int) $m[1];
        }
    } elseif (PHP_VERSION_ID < 80400 && isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $http = (int) $m[1];
    }
    if ($http === 0 && ($body === false || $body === '')) {
        $last = error_get_last();
        $hint = is_array($last) && isset($last['message']) ? (string) $last['message'] : 'no PHP error message';
        error_log('Dingg: file_get_contents HTTPS failed or HTTP status unknown (HTTP 0). allow_url_fopen=' . ini_get('allow_url_fopen') . ' last_error=' . $hint);
    }

    return [
        'http' => $http,
        'body' => $body !== false ? (string) $body : '',
        'transport' => 'file_get_contents',
        'curl_errno' => -1,
        'curl_err' => 'cURL extension not loaded',
    ];
}

/**
 * Dingg vendor login only — must not send Authorization.
 *
 * @return array{http:int, body:string}
 */
function dingg_http_request_login(string $method, string $url, ?string $jsonBody): array
{
    $headers = [];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }

    return dingg_http_execute($method, $url, $headers, $jsonBody);
}

/**
 * Exact header lines sent on authenticated Dingg requests (for logging / tests).
 *
 * @return list<string>
 */
function dingg_authenticated_request_headers(string $bearerToken, ?string $jsonBody): array
{
    $token = trim($bearerToken);
    if ($token === '') {
        return [];
    }

    $headers = [];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    foreach (dingg_auth_http_headers($token) as $authLine) {
        $headers[] = $authLine;
    }

    return $headers;
}

/**
 * All Dingg APIs except login require an Authorization header (and optional posToken per config).
 *
 * @return array{http:int, body:string}
 */
function dingg_http_request_authenticated(string $method, string $url, string $bearerToken, ?string $jsonBody): array
{
    $headers = dingg_authenticated_request_headers($bearerToken, $jsonBody);
    if ($headers === []) {
        error_log('Dingg: authenticated request blocked (empty token).');

        return ['http' => 0, 'body' => ''];
    }

    $resp = dingg_http_execute($method, $url, $headers, $jsonBody);
    dingg_note_unauthorized_if_needed((int) ($resp['http'] ?? 0), (string) ($resp['body'] ?? ''));

    return $resp;
}

/**
 * True when login name is numeric-only (use Dingg "mobile"); otherwise use "email".
 */
function dingg_login_username_is_all_digits(string $trimmed): bool
{
    return $trimmed !== '' && preg_match('/^\d+$/', $trimmed) === 1;
}

/**
 * Normalize mobile for vendor/login: 10-digit India numbers get leading 91.
 */
function dingg_normalize_mobile_for_login_api(string $digitsOnly): string
{
    if (strlen($digitsOnly) === 10) {
        return '91' . $digitsOnly;
    }

    return $digitsOnly;
}

/**
 * POST vendor/login with user-supplied identifier + password.
 * All-digit username → JSON "mobile"; otherwise → JSON "email".
 *
 * @return array{ok:bool, error:?string, http:int, token:?string, employee_name:string}
 */
function dingg_vendor_login_credentials(string $emailRaw, string $password): array
{
    $config = require __DIR__ . '/../config.php';
    $c = $config['dingg'] ?? [];
    $url = (string) ($c['login_url'] ?? 'https://api.dingg.app/api/v1/vendor/login');
    if ($url === '') {
        return ['ok' => false, 'error' => 'Dingg login URL is not configured.', 'http' => 0, 'token' => null, 'employee_name' => ''];
    }

    $login = trim($emailRaw);
    if ($login === '') {
        return ['ok' => false, 'error' => 'Enter a valid user name.', 'http' => 0, 'token' => null, 'employee_name' => ''];
    }

    $payload = [
        'isWeb' => (bool) ($c['isWeb'] ?? true),
        'password' => $password,
        'fcm_token' => (string) ($c['fcm_token'] ?? ''),
    ];
    if (dingg_login_username_is_all_digits($login)) {
        $payload['mobile'] = dingg_normalize_mobile_for_login_api($login);
    } else {
        $payload['email'] = $login;
    }

    $resp = dingg_http_request_login('POST', $url, json_encode($payload, JSON_UNESCAPED_SLASHES));
    $http = (int) ($resp['http'] ?? 0);
    $body = (string) ($resp['body'] ?? '');

    if ($http < 200 || $http >= 300) {
        $decoded = json_decode($body, true);
        $em = is_array($decoded) ? trim((string) ($decoded['message'] ?? '')) : '';

        return ['ok' => false, 'error' => $em !== '' ? $em : ('Login failed (HTTP ' . $http . ').'), 'http' => $http, 'token' => null, 'employee_name' => ''];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'error' => 'Invalid response from server.', 'http' => $http, 'token' => null, 'employee_name' => ''];
    }

    if (($decoded['success'] ?? null) === false) {
        $em = trim((string) ($decoded['message'] ?? ''));

        return ['ok' => false, 'error' => $em !== '' ? $em : 'Login failed.', 'http' => $http, 'token' => null, 'employee_name' => ''];
    }

    $msg = trim((string) ($decoded['message'] ?? ''));
    $token = dingg_extract_token_from_login_response($decoded);
    if ($token === null || $token === '') {
        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'Login succeeded but no token was returned.', 'http' => $http, 'token' => null, 'employee_name' => ''];
    }
    $token = dingg_normalize_pos_token($token);

    $employeeName = '';
    $data = $decoded['data'] ?? null;
    if (is_array($data)) {
        $emp = $data['employee'] ?? null;
        if (is_array($emp)) {
            $employeeName = trim((string) ($emp['name'] ?? ''));
        }
    }

    return [
        'ok' => true,
        'error' => null,
        'http' => $http,
        'token' => $token,
        'employee_name' => $employeeName,
    ];
}

/**
 * @param array<string, mixed> $decoded
 */
function dingg_extract_token_from_login_response(array $decoded, int $depth = 0): ?string
{
    if ($depth > 10) {
        return null;
    }

    foreach (['token', 'access_token', 'posToken', 'auth_token', 'pos_token'] as $k) {
        if (!empty($decoded[$k]) && is_string($decoded[$k])) {
            $t = trim($decoded[$k]);
            if ($t !== '' && strlen($t) > 4) {
                return $t;
            }
        }
    }

    foreach ($decoded as $v) {
        if (is_array($v)) {
            $found = dingg_extract_token_from_login_response($v, $depth + 1);
            if ($found !== null && $found !== '') {
                return $found;
            }
        }
    }

    return null;
}

/**
 * Parse get_all_business JSON into map: vendor location id (branch id) => business_name, locality.
 *
 * @return array<int, array{business_name: string, locality: string}>
 */
function dingg_parse_get_all_business_location_map(string $body): array
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [];
    }
    $data = $decoded['data'] ?? null;
    if (!is_array($data)) {
        return [];
    }
    $map = [];
    foreach ($data as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $map[$id] = [
            'business_name' => trim((string) ($row['business_name'] ?? '')),
            'locality' => trim((string) ($row['locality'] ?? '')),
        ];
    }

    return $map;
}

/**
 * GET get_all_business and return vendor location id => name/locality. Cached in session ~5 minutes.
 *
 * @return array<int, array{business_name: string, locality: string}>
 */
function dingg_fetch_vendor_business_location_map(): array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $cached = $_SESSION['dingg_vendor_location_map'] ?? null;
        $ts = (int) ($_SESSION['dingg_vendor_location_map_ts'] ?? 0);
        if (is_array($cached) && time() - $ts < 300) {
            return $cached;
        }
    }

    $token = dingg_resolve_pos_token_for_api();
    if ($token === null || $token === '') {
        return [];
    }

    $config = require __DIR__ . '/../config.php';
    $url = (string) (($config['dingg']['get_all_business_url'] ?? 'https://api.dingg.app/api/v1/vendor/get_all_business?by_group=false'));
    $resp = dingg_http_request_authenticated('GET', $url, $token, null);
    $code = (int) ($resp['http'] ?? 0);
    if ($code < 200 || $code >= 300) {
        return [];
    }

    $map = dingg_parse_get_all_business_location_map((string) ($resp['body'] ?? ''));
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['dingg_vendor_location_map'] = $map;
        $_SESSION['dingg_vendor_location_map_ts'] = time();
    }

    return $map;
}

/**
 * Same as dingg_fetch_vendor_business_location_map() but uses an explicit bearer token (no session resolve).
 *
 * @return array<int, array{business_name: string, locality: string}>
 */
function dingg_fetch_vendor_business_location_map_with_token(string $bearerToken): array
{
    $token = dingg_normalize_pos_token($bearerToken);
    if ($token === '') {
        return [];
    }

    $config = require __DIR__ . '/../config.php';
    $url = (string) (($config['dingg']['get_all_business_url'] ?? 'https://api.dingg.app/api/v1/vendor/get_all_business?by_group=false'));
    $resp = dingg_http_request_authenticated('GET', $url, $token, null);
    $code = (int) ($resp['http'] ?? 0);
    if ($code < 200 || $code >= 300) {
        return [];
    }

    return dingg_parse_get_all_business_location_map((string) ($resp['body'] ?? ''));
}

/**
 * Labels for bill_payments[].vendor_location_id using get_all_business map (id matches BranchID).
 */
function dingg_invoice_bill_branch_display(array $bill, array $locationMap): string
{
    $bps = $bill['bill_payments'] ?? null;
    if (!is_array($bps) || $bps === []) {
        return '';
    }

    $parts = [];
    $seen = [];
    foreach ($bps as $bp) {
        if (!is_array($bp)) {
            continue;
        }
        $vid = (int) ($bp['vendor_location_id'] ?? 0);
        if ($vid <= 0 || isset($seen[$vid])) {
            continue;
        }
        $seen[$vid] = true;

        if (isset($locationMap[$vid])) {
            $name = trim((string) ($locationMap[$vid]['business_name'] ?? ''));
            $loc = trim((string) ($locationMap[$vid]['locality'] ?? ''));
            if ($name !== '') {
                $parts[] = $loc !== '' ? ($name . ' (' . $loc . ')') : $name;
            } else {
                $parts[] = 'Branch ID ' . $vid;
            }
        } else {
            $parts[] = 'Branch ID ' . $vid;
        }
    }

    return $parts === [] ? '' : implode(', ', $parts);
}

/**
 * GET vendor/target/all — monthly sales target vs achieved per location.
 *
 * @return array{ok:bool, http:int, body:string, error?:string}
 */
function dingg_sales_target_token_from_session_data_admin(): ?string
{
    try {
        $st = db()->prepare(
            'SELECT session_key
             FROM allureone_session_data
             WHERE mobile_number = :mobile_number
             ORDER BY updated_date DESC
             LIMIT 1'
        );
        $st->execute(['mobile_number' => 'admin']);
        $token = trim((string) ($st->fetchColumn() ?: ''));
        if ($token !== '') {
            return $token;
        }
    } catch (PDOException $e) {
        error_log('Dingg sales target token lookup failed: ' . $e->getMessage());
    }

    return null;
}

function dingg_fetch_sales_target(string $startDateYmd, string $endDateYmd, string $locationIdsCsv): array
{
    $locationIdsCsv = trim($locationIdsCsv);
    if ($locationIdsCsv === '') {
        return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'empty_location_ids'];
    }

    $token = dingg_sales_target_token_from_session_data_admin();
    if ($token === null || $token === '') {
        return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'no_token'];
    }

    $config = require __DIR__ . '/../config.php';
    $base = trim((string) (($config['dingg']['sales_target_url'] ?? 'https://api.dingg.app/api/v1/vendor/target/all')));
    if ($base === '') {
        return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'no_url'];
    }

    $params = [
        'location_ids' => $locationIdsCsv,
        'employee_ids' => '-1',
        'time_type' => 'monthly',
        'start_date' => $startDateYmd,
        'end_date' => $endDateYmd,
    ];
    $url = $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $resp = dingg_http_request_authenticated('GET', $url, $token, null);

    return [
        'ok' => true,
        'http' => (int) ($resp['http'] ?? 0),
        'body' => (string) ($resp['body'] ?? ''),
    ];
}

/**
 * Extract location_id => total_sales, total_sales_achieved from target API JSON (flexible nesting).
 *
 * @return array<int, array{total_sales: float, total_sales_achieved: float}>
 */
function dingg_parse_sales_target_by_location(string $body): array
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [];
    }

    $rows = [];
    dingg_sales_target_walk_collect($decoded, $rows);

    $map = [];
    foreach ($rows as $r) {
        $lid = (int) ($r['location_id'] ?? 0);
        if ($lid <= 0) {
            continue;
        }
        $map[$lid] = [
            'total_sales' => $r['total_sales'],
            'total_sales_achieved' => $r['total_sales_achieved'],
        ];
    }

    return $map;
}

/**
 * @param array<int, array{location_id: int, total_sales: float, total_sales_achieved: float}> $rows
 */
function dingg_sales_target_walk_collect(array $node, array &$rows, int $depth = 0): void
{
    if ($depth > 24) {
        return;
    }

    $lid = (int) ($node['location_id'] ?? $node['vendor_location_id'] ?? 0);
    if ($lid > 0 && (array_key_exists('total_sales', $node) || array_key_exists('total_sales_achieved', $node))) {
        $ts = $node['total_sales'] ?? null;
        $ta = $node['total_sales_achieved'] ?? null;
        $rows[] = [
            'location_id' => $lid,
            'total_sales' => is_numeric($ts) ? (float) $ts : 0.0,
            'total_sales_achieved' => is_numeric($ta) ? (float) $ta : 0.0,
        ];
    }

    foreach ($node as $v) {
        if (is_array($v)) {
            dingg_sales_target_walk_collect($v, $rows, $depth + 1);
        }
    }
}

/**
 * Bearer token for server-side Dingg calls: from PHP session (set at login and synced from localStorage via dingg_token_sync.php).
 */
function dingg_resolve_pos_token_for_api(): ?string
{
    dingg_token_diag_clear();
    $t = dingg_get_pos_token_from_session();
    if ($t !== null && $t !== '') {
        return $t;
    }

    dingg_token_diag_set(
        'No Dingg token in session. Sign in again, or ensure localStorage (allureone_dingg_bearer) is synced to the server.'
    );

    return null;
}

/**
 * Remove characters not allowed in invoice search (prevents injection into query string / URL).
 */
function sanitize_invoice_search_term(string $raw): string
{
    return trim(preg_replace("/['\"%&()#:<>?\[\]]/u", '', $raw) ?? '');
}

/**
 * GET vendor bills with an explicit bearer token (e.g. from browser localStorage via proxy).
 *
 * @return array{ok:bool, error?:string, http:int, body:string, error_detail?:string}
 */
function dingg_fetch_vendor_bills_with_token(string $term, string $bearerToken): array
{
    $term = sanitize_invoice_search_term($term);
    if ($term === '') {
        return ['ok' => false, 'error' => 'empty_term', 'http' => 0, 'body' => ''];
    }

    $token = dingg_normalize_pos_token($bearerToken);
    if ($token === '') {
        return [
            'ok' => false,
            'error' => 'no_token',
            'http' => 0,
            'body' => '',
            'error_detail' => 'Missing or empty Dingg bearer token.',
        ];
    }

    $today = date('Y-m-d');
    $params = [
        'web' => 'true',
        'page' => '1',
        'limit' => '1000',
        'start' => $today,
        'end' => $today,
        'term' => $term,
        'is_product_only' => '',
    ];
    $url = 'https://api.dingg.app/api/v1/vendor/bills?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    $resp = dingg_http_request_authenticated('GET', $url, $token, null);

    $cfg = require __DIR__ . '/../config.php';
    if (($cfg['dingg']['log_invoice_search'] ?? true)) {
        $http = (int) ($resp['http'] ?? 0);
        $body = (string) ($resp['body'] ?? '');
        error_log('Dingg invoice search (vendor/bills) term=' . $term . ' HTTP=' . $http . ' url=' . $url);
        error_log('Dingg invoice search (vendor/bills) response body: ' . $body);
    }

    return [
        'ok' => true,
        'http' => $resp['http'],
        'body' => (string) $resp['body'],
    ];
}

/**
 * GET vendor bills — term = invoice number; start/end = current date (Y-m-d). Uses session/DB/config token.
 *
 * @return array{ok:bool, error?:string, http:int, body:string, error_detail?:string}
 */
function dingg_fetch_vendor_bills(string $term): array
{
    $token = dingg_resolve_pos_token_for_api();
    if ($token === null || $token === '') {
        return [
            'ok' => false,
            'error' => 'no_token',
            'http' => 0,
            'body' => '',
            'error_detail' => dingg_token_diag_get(),
        ];
    }

    return dingg_fetch_vendor_bills_with_token($term, $token);
}

function dingg_bills_response_is_success(array $json): bool
{
    if (($json['success'] ?? false) === true) {
        return true;
    }
    $m = strtolower((string) ($json['message'] ?? ''));

    return $m === 'success';
}

/**
 * @return array<int, array<string, mixed>>|null
 */
function dingg_bills_api_data_rows(array $json): ?array
{
    $data = $json['data'] ?? null;
    if (!is_array($data)) {
        return null;
    }
    $out = [];
    foreach ($data as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out === [] ? null : $out;
}

function dingg_bills_api_error_message(array $json): string
{
    if (dingg_bills_response_is_success($json)) {
        return '';
    }

    return (string) ($json['message'] ?? 'Request failed.');
}

function dingg_format_invoice_date(?string $ymd): string
{
    if ($ymd === null || $ymd === '') {
        return '—';
    }
    $t = strtotime($ymd);

    return $t === false ? '—' : date('d-M-y', $t);
}

/**
 * @param array<string, mixed> $bill
 */
function dingg_format_invoice_client_name(array $bill): string
{
    $user = $bill['user'] ?? null;
    if (!is_array($user)) {
        return '—';
    }
    $fn = trim((string) ($user['fname'] ?? ''));
    $ln = trim((string) ($user['lname'] ?? ''));
    $name = trim($fn . ' ' . $ln);

    return $name !== '' ? $name : '—';
}

/**
 * @param array<string, mixed> $bill
 */
function dingg_format_invoice_status_label(array $bill): string
{
    $parts = [];
    $ps = (string) ($bill['payment_status'] ?? '');
    if ($ps !== '') {
        $parts[] = ucfirst(str_replace('_', ' ', $ps));
    }
    $st = $bill['status'] ?? null;
    if ($st === true) {
        $parts[] = 'Active';
    } elseif ($st === false) {
        $parts[] = 'Inactive';
    }
    $cr = $bill['cancel_reason'] ?? null;
    if ($cr !== null && $cr !== '') {
        $parts[] = 'Note: ' . (string) $cr;
    }

    return $parts !== [] ? implode(' · ', $parts) : '—';
}

/**
 * POST cancellation for a bill. Set config dingg.cancel_bill_url (optional {id} placeholder).
 * Pass $bearerTokenOverride when the token is only in browser localStorage (same as invoice search).
 *
 * @return array{ok:bool, error?:string, http:int, body:string}
 */
function dingg_request_bill_cancellation(int $billId, ?string $bearerTokenOverride = null, ?string $cancelReason = null): array
{
    if ($billId <= 0) {
        return ['ok' => false, 'error' => 'invalid_id', 'http' => 0, 'body' => ''];
    }

    $config = require __DIR__ . '/../config.php';
    $dingg = $config['dingg'] ?? [];
    $template = trim((string) ($dingg['cancel_bill_url'] ?? ''));
    if ($template === '') {
        return ['ok' => false, 'error' => 'not_configured', 'http' => 0, 'body' => ''];
    }

    $token = null;
    if ($bearerTokenOverride !== null && trim($bearerTokenOverride) !== '') {
        $token = dingg_normalize_pos_token($bearerTokenOverride);
    }
    if ($token === null || $token === '') {
        $token = dingg_resolve_pos_token_for_api();
    }
    if ($token === null || $token === '') {
        return ['ok' => false, 'error' => 'no_token', 'http' => 0, 'body' => ''];
    }

    $url = str_contains($template, '{id}')
        ? str_replace('{id}', (string) $billId, $template)
        : $template;

    $payloadData = ['id' => $billId];
    $cancelReason = trim((string) ($cancelReason ?? ''));
    if ($cancelReason !== '') {
        $payloadData['reason'] = function_exists('mb_substr')
            ? mb_substr($cancelReason, 0, 100)
            : substr($cancelReason, 0, 100);
    }
    $payload = json_encode($payloadData, JSON_UNESCAPED_SLASHES);
    $resp = dingg_http_request_authenticated('POST', $url, $token, $payload);

    return [
        'ok' => true,
        'http' => $resp['http'],
        'body' => (string) $resp['body'],
    ];
}

/**
 * DELETE vendor bill for approval flow.
 *
 * @return array{ok:bool, error?:string, http:int, body:string}
 */
function dingg_delete_vendor_bill(int $billId, string $reason, ?string $bearerTokenOverride = null): array
{
    if ($billId <= 0) {
        return ['ok' => false, 'error' => 'invalid_id', 'http' => 0, 'body' => ''];
    }

    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'error' => 'empty_reason', 'http' => 0, 'body' => ''];
    }

    $token = null;
    if ($bearerTokenOverride !== null && trim($bearerTokenOverride) !== '') {
        $token = dingg_normalize_pos_token($bearerTokenOverride);
    }
    if ($token === null || $token === '') {
        $token = dingg_resolve_pos_token_for_api();
    }
    if ($token === null || $token === '') {
        return ['ok' => false, 'error' => 'no_token', 'http' => 0, 'body' => ''];
    }

    $config = require __DIR__ . '/../config.php';
    $baseUrl = trim((string) (($config['dingg']['delete_bill_url'] ?? 'https://api.dingg.app/api/v1/vendor/bill')));
    if ($baseUrl === '') {
        return ['ok' => false, 'error' => 'not_configured', 'http' => 0, 'body' => ''];
    }

    $url = $baseUrl . '?' . http_build_query(
        ['bill_id' => $billId, 'reason' => $reason],
        '',
        '&',
        PHP_QUERY_RFC3986
    );
    $resp = dingg_http_request_authenticated('DELETE', $url, $token, null);

    return [
        'ok' => true,
        'http' => (int) ($resp['http'] ?? 0),
        'body' => (string) ($resp['body'] ?? ''),
    ];
}

/** @deprecated Use dingg_encrypt_session_token + dingg_get_pos_token_from_session */
function dingg_store_token(?string $token): void
{
    if ($token === null || $token === '') {
        dingg_clear_session_encrypted_token();
        return;
    }
    dingg_encrypt_session_token($token);
}
