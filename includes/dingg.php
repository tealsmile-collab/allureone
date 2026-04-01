<?php
declare(strict_types=1);

const DINGG_KEY_POS_TOKEN = 'posToken';

/** @internal Last-resort session key if openssl_encrypt fails (same trust boundary as encrypted blob). */
const DINGG_SESSION_TOKEN_PLAIN = 'dingg_pos_plain';

/**
 * Creates allureone_keys if missing (install skipped or older DB).
 */
function allureone_keys_ensure_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS allureone_keys (
          id INT NOT NULL AUTO_INCREMENT,
          key_name VARCHAR(64) NOT NULL,
          key_value LONGTEXT NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_allureone_keys_name (key_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $done = true;
}

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

function allureone_key_get(PDO $pdo, string $name): ?string
{
    allureone_keys_ensure_table($pdo);
    $stmt = $pdo->prepare('SELECT key_value FROM allureone_keys WHERE key_name = :k LIMIT 1');
    $stmt->execute(['k' => $name]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    $v = $row['key_value'] ?? null;
    if ($v === null || $v === '') {
        return null;
    }
    return (string) $v;
}

function allureone_key_set(PDO $pdo, string $name, string $value): void
{
    allureone_keys_ensure_table($pdo);
    $stmt = $pdo->prepare(
        'INSERT INTO allureone_keys (key_name, key_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)'
    );
    $stmt->execute(['k' => $name, 'v' => $value]);
}

/**
 * Reads stored Dingg POS token; tries canonical name first, then common manual-insert variants.
 */
function allureone_key_get_pos_token_from_db(PDO $pdo): ?string
{
    foreach ([DINGG_KEY_POS_TOKEN, 'pos_token'] as $name) {
        $v = allureone_key_get($pdo, $name);
        if ($v !== null && trim($v) !== '') {
            return trim($v);
        }
    }

    return null;
}

/**
 * Optional testing override: when dingg.posToken in config is non-empty, use it instead of DB value.
 * Callers must still invoke allureone_key_get_pos_token_from_db() when a DB read is required.
 */
function dingg_config_pos_token_override(): string
{
    $cfg = require __DIR__ . '/../config.php';
    $t = trim((string) (($cfg['dingg']['posToken'] ?? '')));

    return $t === '' ? '' : dingg_normalize_pos_token($t);
}

/**
 * Effective POS token: config posToken overrides DB when set.
 */
function dingg_effective_pos_token_from_db(PDO $pdo): string
{
    $dbPos = allureone_key_get_pos_token_from_db($pdo) ?? '';
    $override = dingg_config_pos_token_override();

    return $override !== '' ? $override : $dbPos;
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
    } else {
        $opts[CURLOPT_HTTPGET] = true;
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

    return dingg_http_execute($method, $url, $headers, $jsonBody);
}

/**
 * @return list<string>
 */
function dingg_mobile_variants(string $mobile): array
{
    $mobile = trim($mobile);
    $out = [];
    if ($mobile !== '') {
        $out[] = $mobile;
    }
    if (preg_match('/^91(\d{10})$/', $mobile, $m)) {
        $out[] = $m[1];
    }
    if (preg_match('/^(\d{10})$/', $mobile, $m)) {
        $out[] = '91' . $m[1];
    }

    return array_values(array_unique(array_filter($out)));
}

function dingg_fetch_token(): ?string
{
    $config = require __DIR__ . '/../config.php';
    $c = $config['dingg'] ?? [];
    $url = (string) ($c['login_url'] ?? '');
    if ($url === '') {
        dingg_token_diag_set('Dingg login_url is empty in config.');
        return null;
    }

    $basePayload = [
        'isWeb' => (bool) ($c['isWeb'] ?? true),
        'password' => (string) ($c['password'] ?? ''),
        'fcm_token' => (string) ($c['fcm_token'] ?? ''),
    ];

    $mobiles = dingg_mobile_variants((string) ($c['mobile'] ?? ''));
    if ($mobiles === []) {
        dingg_token_diag_set('Dingg mobile is empty in config.');
        return null;
    }

    $lastHttp = 0;
    $httpFailures = [];
    foreach ($mobiles as $mob) {
        $payload = $basePayload;
        $payload['mobile'] = $mob;
        $resp = dingg_http_request_login('POST', $url, json_encode($payload, JSON_UNESCAPED_SLASHES));
        $lastHttp = (int) $resp['http'];
        if ($lastHttp < 200 || $lastHttp >= 300) {
            error_log('Dingg login HTTP status: ' . $lastHttp . ' body: ' . substr((string) $resp['body'], 0, 500));
            $httpFailures[] = $lastHttp;
            continue;
        }

        $decoded = json_decode((string) $resp['body'], true);
        if (!is_array($decoded)) {
            error_log('Dingg login: response is not JSON');
            dingg_token_diag_append('Login returned non-JSON.');
            continue;
        }
        $token = dingg_extract_token_from_login_response($decoded);
        if ($token !== null && $token !== '') {
            return $token;
        }
        error_log('Dingg login: no token in JSON. Top keys: ' . implode(', ', array_keys($decoded)));
        dingg_token_diag_append('Login OK but no token in JSON (see PHP error log for keys).');
    }

    if ($httpFailures !== []) {
        $uniq = array_values(array_unique($httpFailures));
        dingg_token_diag_append('Dingg login HTTP codes tried: ' . implode(', ', $uniq) . '. If 0: connection/SSL/firewall (see cURL lines above).');
    }

    return null;
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
 * GET get_all_business — returns success if JSON status is "success".
 * unauthorized: HTTP 401/403 or response indicates auth failure.
 */
function dingg_validate_pos_token_business(string $token): array
{
    $config = require __DIR__ . '/../config.php';
    $url = (string) (($config['dingg']['get_all_business_url'] ?? 'https://api.dingg.app/api/v1/vendor/get_all_business?by_group=false'));

    $resp = dingg_http_request_authenticated('GET', $url, $token, null);
    $code = $resp['http'];
    $body = (string) $resp['body'];

    if ($code === 401 || $code === 403) {
        return ['success' => false, 'unauthorized' => true];
    }

    $decoded = json_decode($body, true);
    if (is_array($decoded)) {
        if (($decoded['status'] ?? '') === 'success') {
            return ['success' => true, 'unauthorized' => false];
        }
        if (($decoded['success'] ?? false) === true) {
            return ['success' => true, 'unauthorized' => false];
        }
        $msg = strtolower((string) ($decoded['message'] ?? ''));
        if ($msg === 'success') {
            return ['success' => true, 'unauthorized' => false];
        }
    }

    $lower = strtolower($body);
    $unauth = str_contains($lower, 'unauthor')
        || str_contains($lower, 'unauthorised')
        || str_contains($lower, 'unauthorized');

    return ['success' => false, 'unauthorized' => $unauth];
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
function dingg_fetch_sales_target(string $startDateYmd, string $endDateYmd, string $locationIdsCsv): array
{
    $locationIdsCsv = trim($locationIdsCsv);
    if ($locationIdsCsv === '') {
        return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'empty_location_ids'];
    }

    $token = dingg_resolve_pos_token_for_api();
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
 * Validates stored token against Dingg; on failure runs vendor login and persists a new token when possible.
 */
function dingg_apply_stored_token_or_refresh(PDO $pdo, string $pos): void
{
    $pos = trim($pos);
    if ($pos === '') {
        return;
    }

    $check = dingg_validate_pos_token_business($pos);
    if ($check['success']) {
        dingg_encrypt_session_token($pos);
        return;
    }

    $new = dingg_fetch_token();
    if ($new !== null && $new !== '') {
        allureone_key_set($pdo, DINGG_KEY_POS_TOKEN, $new);
        dingg_encrypt_session_token($new);
    } else {
        dingg_encrypt_session_token($pos);
    }
}

/**
 * After app login: load posToken from DB, validate or refresh Dingg token, store encrypted in session.
 *
 * @param bool $skipFetchWhenDbEmpty If true, do not call Dingg login when DB has no posToken (avoids duplicate fetch with dingg_resolve_pos_token_for_api).
 */
function dingg_ensure_pos_token_after_login(bool $skipFetchWhenDbEmpty = false): void
{
    try {
        $pdo = db();
        $pos = dingg_effective_pos_token_from_db($pdo);

        if ($pos === '') {
            if ($skipFetchWhenDbEmpty) {
                return;
            }
            $new = dingg_fetch_token();
            if ($new !== null && $new !== '') {
                allureone_key_set($pdo, DINGG_KEY_POS_TOKEN, $new);
                dingg_encrypt_session_token($new);
            }
            return;
        }

        dingg_apply_stored_token_or_refresh($pdo, $pos);
    } catch (Throwable $e) {
        error_log('Dingg pos token ensure: ' . $e->getMessage());
    }
}

/**
 * Resolves a usable Bearer token: session (decrypted), DB re-encrypt, or fresh Dingg login.
 */
function dingg_resolve_pos_token_for_api(): ?string
{
    dingg_token_diag_clear();
    dingg_ensure_pos_token_after_login(true);
    $t = dingg_get_pos_token_from_session();
    if ($t !== null && $t !== '') {
        return $t;
    }

    try {
        $pdo = db();
        $pos = dingg_effective_pos_token_from_db($pdo);
        if ($pos !== '') {
            // ensure() already validated/refreshed when this row existed; retry encrypt only (e.g. openssl hiccup)
            dingg_encrypt_session_token($pos);
            $t = dingg_get_pos_token_from_session();
            if ($t !== null && $t !== '') {
                return $t;
            }
            dingg_token_diag_append('DB token present but session store failed (openssl?).');
        } else {
            dingg_token_diag_append('No pos token row in allureone_keys (need key_name posToken or pos_token, non-empty key_value).');
        }
    } catch (Throwable $e) {
        error_log('Dingg resolve token from DB: ' . $e->getMessage());
        dingg_token_diag_append('Database: ' . $e->getMessage());
    }

    $fresh = dingg_fetch_token();
    if ($fresh !== null && $fresh !== '') {
        try {
            $pdo = db();
            allureone_key_set($pdo, DINGG_KEY_POS_TOKEN, $fresh);
        } catch (Throwable $e) {
            error_log('Dingg resolve token save: ' . $e->getMessage());
            dingg_token_diag_append('Could not save token to DB.');
        }
        dingg_encrypt_session_token($fresh);
        $t = dingg_get_pos_token_from_session();

        if ($t !== null && $t !== '') {
            return $t;
        }
        dingg_token_diag_append('Fresh token from Dingg but session readback failed.');
    }

    if (dingg_token_diag_get() === '') {
        dingg_token_diag_set(
            'Could not obtain Dingg token. Check dingg.mobile and dingg.password in config, PHP openssl extension, and outbound HTTPS to api.dingg.app. If logs show SSL errors, try dingg.ssl_verify => false (development only).'
        );
    }

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
 * GET vendor bills — term = invoice number; start/end = current date (Y-m-d).
 *
 * @return array{ok:bool, error?:string, http:int, body:string, error_detail?:string}
 */
function dingg_fetch_vendor_bills(string $term): array
{
    $term = sanitize_invoice_search_term($term);
    if ($term === '') {
        return ['ok' => false, 'error' => 'empty_term', 'http' => 0, 'body' => ''];
    }

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
 *
 * @return array{ok:bool, error?:string, http:int, body:string}
 */
function dingg_request_bill_cancellation(int $billId): array
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

    $token = dingg_resolve_pos_token_for_api();
    if ($token === null || $token === '') {
        return ['ok' => false, 'error' => 'no_token', 'http' => 0, 'body' => ''];
    }

    $url = str_contains($template, '{id}')
        ? str_replace('{id}', (string) $billId, $template)
        : $template;

    $payload = json_encode(['id' => $billId], JSON_UNESCAPED_SLASHES);
    $resp = dingg_http_request_authenticated('POST', $url, $token, $payload);

    return [
        'ok' => true,
        'http' => $resp['http'],
        'body' => (string) $resp['body'],
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
