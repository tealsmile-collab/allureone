<?php
declare(strict_types=1);

/**
 * @return array{graph_version:string,app_id:string,app_secret:string,access_token:string,ad_account_id:string}
 */
function meta_ads_config(): array
{
    $config = require __DIR__ . '/../config.php';
    $cfg = is_array($config['meta_ads'] ?? null) ? $config['meta_ads'] : [];
    $version = trim((string) ($cfg['graph_version'] ?? 'v21.0'));
    if ($version === '') {
        $version = 'v21.0';
    }
    $accountId = trim((string) ($cfg['ad_account_id'] ?? ''));
    if ($accountId !== '' && stripos($accountId, 'act_') !== 0) {
        $accountId = 'act_' . $accountId;
    }

    return [
        'graph_version' => $version,
        'app_id' => trim((string) ($cfg['app_id'] ?? '')),
        'app_secret' => trim((string) ($cfg['app_secret'] ?? '')),
        'access_token' => trim((string) ($cfg['access_token'] ?? '')),
        'ad_account_id' => $accountId,
    ];
}

/**
 * @return array{token:string,expires_at:int}
 */
function meta_ads_session_token(): array
{
    $raw = $_SESSION['meta_ads_access_token'] ?? null;
    if (!is_array($raw)) {
        return ['token' => '', 'expires_at' => 0];
    }
    $token = trim((string) ($raw['token'] ?? ''));
    if ($token === '') {
        return ['token' => '', 'expires_at' => 0];
    }

    return [
        'token' => $token,
        'expires_at' => (int) ($raw['expires_at'] ?? 0),
    ];
}

function meta_ads_save_session_token(string $token, int $expiresAt): void
{
    $token = trim($token);
    if ($token === '' || session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['meta_ads_access_token'] = [
        'token' => $token,
        'expires_at' => $expiresAt,
    ];
}

function meta_ads_clear_session_token(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    unset($_SESSION['meta_ads_access_token']);
}

/**
 * @return array{http:int,json:?array,error:string}
 */
function meta_ads_http_get(string $url): array
{
    $body = '';
    $http = 0;
    $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        if ($raw === false || $http === 0) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $raw = curl_exec($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
        }
        $body = $raw !== false ? (string) $raw : '';
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        $body = $raw !== false ? (string) $raw : '';
    }
    $json = json_decode($body, true);

    return [
        'http' => $http,
        'json' => is_array($json) ? $json : null,
        'error' => $err,
    ];
}

/**
 * @return array{ok:bool,is_valid:bool,expires_at:int,error:string}
 */
function meta_ads_debug_token(string $token, array $cfg): array
{
    $appId = (string) ($cfg['app_id'] ?? '');
    $appSecret = (string) ($cfg['app_secret'] ?? '');
    if ($appId === '' || $appSecret === '') {
        return ['ok' => false, 'is_valid' => false, 'expires_at' => 0, 'error' => 'Meta app id and app secret are not configured.'];
    }
    $version = (string) ($cfg['graph_version'] ?? 'v21.0');
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . '/debug_token?' . http_build_query([
        'input_token' => $token,
        'access_token' => $appId . '|' . $appSecret,
    ], '', '&', PHP_QUERY_RFC3986);
    $res = meta_ads_http_get($url);
    $json = $res['json'];
    $data = is_array($json) && is_array($json['data'] ?? null) ? $json['data'] : null;
    if (!is_array($data)) {
        $msg = is_array($json) && is_array($json['error'] ?? null)
            ? trim((string) ($json['error']['message'] ?? ''))
            : '';

        return [
            'ok' => false,
            'is_valid' => false,
            'expires_at' => 0,
            'error' => $msg !== '' ? $msg : 'Could not check the Meta access token.',
        ];
    }

    return [
        'ok' => true,
        'is_valid' => ($data['is_valid'] ?? false) === true,
        'expires_at' => (int) ($data['expires_at'] ?? 0),
        'error' => trim((string) ($data['error']['message'] ?? '')),
    ];
}

/**
 * Ask Meta's oauth/access_token API for a new long-lived token and store it in the session.
 *
 * @return array{ok:bool,token:string,expires_at:int,error:string}
 */
function meta_ads_exchange_token(string $token, array $cfg): array
{
    $appId = (string) ($cfg['app_id'] ?? '');
    $appSecret = (string) ($cfg['app_secret'] ?? '');
    $version = (string) ($cfg['graph_version'] ?? 'v21.0');
    $url = 'https://graph.facebook.com/' . rawurlencode($version) . '/oauth/access_token?' . http_build_query([
        'grant_type' => 'fb_exchange_token',
        'client_id' => $appId,
        'client_secret' => $appSecret,
        'fb_exchange_token' => $token,
    ], '', '&', PHP_QUERY_RFC3986);
    $res = meta_ads_http_get($url);
    $json = $res['json'];
    $newToken = is_array($json) ? trim((string) ($json['access_token'] ?? '')) : '';
    if ($newToken === '') {
        $msg = is_array($json) && is_array($json['error'] ?? null)
            ? trim((string) ($json['error']['message'] ?? ''))
            : '';

        return [
            'ok' => false,
            'token' => '',
            'expires_at' => 0,
            'error' => $msg !== '' ? $msg : 'Could not generate a new Meta access token.',
        ];
    }
    $expiresIn = (int) ($json['expires_in'] ?? 0);
    $expiresAt = $expiresIn > 0 ? time() + $expiresIn : 0;
    meta_ads_save_session_token($newToken, $expiresAt);

    return ['ok' => true, 'token' => $newToken, 'expires_at' => $expiresAt, 'error' => ''];
}

function meta_ads_token_needs_refresh(array $debug): bool
{
    if (!($debug['is_valid'] ?? false)) {
        return true;
    }
    $expiresAt = (int) ($debug['expires_at'] ?? 0);
    if ($expiresAt === 0) {
        return false;
    }

    return $expiresAt <= time() + (7 * 24 * 60 * 60);
}

/**
 * Reuse the session token when Meta says it is valid. Otherwise call the token API and save the new one.
 *
 * @return array{token:string,error:string}
 */
function meta_ads_ensure_access_token(bool $forceRefresh = false): array
{
    static $resolved = null;
    if ($resolved !== null && !$forceRefresh) {
        return $resolved;
    }

    $cfg = meta_ads_config();
    $session = meta_ads_session_token();
    if (!$forceRefresh && $session['token'] !== '' && $cfg['app_id'] !== '' && $cfg['app_secret'] !== '') {
        $debug = meta_ads_debug_token($session['token'], $cfg);
        if ($debug['ok'] && !meta_ads_token_needs_refresh($debug)) {
            $resolved = ['token' => $session['token'], 'error' => ''];

            return $resolved;
        }
    }

    $seeds = [];
    if ($cfg['access_token'] !== '') {
        $seeds[] = $cfg['access_token'];
    }
    if ($session['token'] !== '' && !in_array($session['token'], $seeds, true)) {
        $seeds[] = $session['token'];
    }
    if ($seeds === []) {
        meta_ads_clear_session_token();
        $resolved = ['token' => '', 'error' => 'Meta Ads access token is not configured.'];

        return $resolved;
    }
    if ($cfg['app_id'] === '' || $cfg['app_secret'] === '') {
        meta_ads_save_session_token($seeds[0], 0);
        $resolved = ['token' => $seeds[0], 'error' => ''];

        return $resolved;
    }

    $lastError = '';
    foreach ($seeds as $token) {
        $debug = meta_ads_debug_token($token, $cfg);
        if (!$debug['ok']) {
            $lastError = $debug['error'];
            continue;
        }
        if (!$forceRefresh && !meta_ads_token_needs_refresh($debug)) {
            meta_ads_save_session_token($token, (int) $debug['expires_at']);
            $resolved = ['token' => $token, 'error' => ''];

            return $resolved;
        }
        $exchanged = meta_ads_exchange_token($token, $cfg);
        if ($exchanged['ok']) {
            $resolved = ['token' => $exchanged['token'], 'error' => ''];

            return $resolved;
        }
        if ($debug['is_valid'] && !$forceRefresh) {
            meta_ads_save_session_token($token, (int) $debug['expires_at']);
            $resolved = ['token' => $token, 'error' => ''];

            return $resolved;
        }
        $lastError = $exchanged['error'] !== '' ? $exchanged['error'] : $debug['error'];
    }

    meta_ads_clear_session_token();
    $resolved = [
        'token' => '',
        'error' => $lastError !== '' ? $lastError : 'Could not generate a Meta access token.',
    ];

    return $resolved;
}

function meta_ads_runtime_token(?string $token = null): ?string
{
    static $value = null;
    if ($token !== null) {
        $value = $token;
    }

    return $value;
}

/**
 * @return array{graph_version:string,access_token:string,ad_account_id:string,token_error:string}
 */
function meta_ads_settings(): array
{
    $cfg = meta_ads_config();
    $runtime = meta_ads_runtime_token();
    if (is_string($runtime) && $runtime !== '') {
        return [
            'graph_version' => $cfg['graph_version'],
            'access_token' => $runtime,
            'ad_account_id' => $cfg['ad_account_id'],
            'token_error' => '',
        ];
    }
    $token = meta_ads_ensure_access_token(false);
    if ($token['token'] !== '') {
        meta_ads_runtime_token($token['token']);
    }

    return [
        'graph_version' => $cfg['graph_version'],
        'access_token' => $token['token'],
        'ad_account_id' => $cfg['ad_account_id'],
        'token_error' => $token['error'],
    ];
}

/**
 * @return array{ok:bool,http:int,json:?array,error:string}
 */
function meta_ads_graph_get(string $path, array $query = []): array
{
    $settings = meta_ads_settings();
    if ($settings['access_token'] === '') {
        $tokenError = trim((string) ($settings['token_error'] ?? ''));

        return ['ok' => false, 'http' => 0, 'json' => null, 'error' => $tokenError !== '' ? $tokenError : 'Meta Ads access token is not configured.'];
    }

    $send = static function (string $token) use ($path, $query, $settings): array {
        $query['access_token'] = $token;
        $url = 'https://graph.facebook.com/' . rawurlencode($settings['graph_version']) . '/' . ltrim($path, '/');
        $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $res = meta_ads_http_get($url);
        $http = (int) ($res['http'] ?? 0);
        $json = $res['json'];
        $err = (string) ($res['error'] ?? '');
        if (!is_array($json)) {
            return [
                'ok' => false,
                'http' => $http,
                'json' => null,
                'error' => $err !== '' ? $err : ('Meta request failed (HTTP ' . $http . ').'),
            ];
        }
        if (isset($json['error']) && is_array($json['error'])) {
            $msg = trim((string) ($json['error']['message'] ?? 'Meta request failed.'));
            $code = (int) ($json['error']['code'] ?? 0);

            return [
                'ok' => false,
                'http' => $http,
                'json' => $json,
                'error' => $msg !== '' ? $msg : 'Meta request failed.',
                'token_expired' => $code === 190,
            ];
        }
        if ($http !== 0 && ($http < 200 || $http >= 300)) {
            return ['ok' => false, 'http' => $http, 'json' => $json, 'error' => 'Meta request failed (HTTP ' . $http . ').'];
        }

        return ['ok' => true, 'http' => $http, 'json' => $json, 'error' => ''];
    };

    $first = $send($settings['access_token']);
    if (($first['ok'] ?? false) || empty($first['token_expired'])) {
        unset($first['token_expired']);

        return $first;
    }

    $cfg = meta_ads_config();
    $exchanged = meta_ads_exchange_token($settings['access_token'], $cfg);
    if (!$exchanged['ok'] || $exchanged['token'] === '' || $exchanged['token'] === $settings['access_token']) {
        unset($first['token_expired']);

        return $first;
    }
    meta_ads_runtime_token($exchanged['token']);

    $retry = $send($exchanged['token']);
    unset($retry['token_expired']);

    return $retry;
}

function meta_ads_format_money($minor, string $currency): string
{
    if ($minor === null || $minor === '') {
        return '—';
    }
    $zeroDecimal = ['JPY', 'KRW', 'VND', 'CLP'];
    $offset = in_array(strtoupper($currency), $zeroDecimal, true) ? 1 : 100;
    $amount = ((float) $minor) / $offset;
    $symbol = strtoupper($currency) === 'INR' ? '₹' : (strtoupper($currency) . ' ');

    return $symbol . number_format($amount, $offset === 1 ? 0 : 2, '.', ',');
}

function meta_ads_format_major($amount, string $currency): string
{
    if ($amount === null || $amount === '') {
        return '—';
    }
    $symbol = strtoupper($currency) === 'INR' ? '₹' : (strtoupper($currency) . ' ');

    return $symbol . number_format((float) $amount, 2, '.', ',');
}

/**
 * Prepaid wallets report available funds in funding_source_details.display_string.
 * The balance field is the amount owed, which is 0 when the account is prepaid.
 */
function meta_ads_prepaid_balance_label(string $display, string $currency): ?string
{
    $display = trim($display);
    if ($display === '' || !preg_match('/available balance\s*\((?:[^\d)]*)([0-9][0-9,]*(?:\.[0-9]+)?)[^)]*\)/iu', $display, $match)) {
        return null;
    }
    $amount = (float) str_replace(',', '', $match[1]);

    return meta_ads_format_major($amount, $currency);
}

/**
 * @return array<string, string>
 */
function meta_ads_period_options(): array
{
    return [
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'today_yesterday' => 'Today and yesterday',
        'last_7' => 'Last 7 days',
        'last_14' => 'Last 14 days',
        'last_28' => 'Last 28 days',
        'last_30' => 'Last 30 days',
        'this_week' => 'This week',
        'last_week' => 'Last week',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'maximum' => 'Maximum',
    ];
}

function meta_ads_normalize_period(string $period): string
{
    $period = trim($period);
    $options = meta_ads_period_options();

    return isset($options[$period]) ? $period : 'last_14';
}

/**
 * Kolkata ranges matching Ads Manager. Last N days end yesterday.
 *
 * @return array{since:DateTime,until:DateTime}
 */
function meta_ads_period_bounds(string $period): array
{
    $period = meta_ads_normalize_period($period);
    $tz = new DateTimeZone('Asia/Kolkata');
    $today = new DateTime('today', $tz);
    $since = clone $today;
    $until = clone $today;

    if ($period === 'yesterday') {
        $since->modify('-1 day');
        $until->modify('-1 day');
    } elseif ($period === 'today_yesterday') {
        $since->modify('-1 day');
    } elseif (in_array($period, ['last_7', 'last_14', 'last_28', 'last_30'], true)) {
        $days = ['last_7' => 7, 'last_14' => 14, 'last_28' => 28, 'last_30' => 30][$period];
        $until->modify('-1 day');
        $since = (clone $until)->modify('-' . ($days - 1) . ' days');
    } elseif ($period === 'this_week') {
        $since->modify('-' . ((int) $today->format('N') - 1) . ' days');
    } elseif ($period === 'last_week') {
        $until = (clone $today)->modify('-' . (int) $today->format('N') . ' days');
        $since = (clone $until)->modify('-6 days');
    } elseif ($period === 'this_month') {
        $since = new DateTime($today->format('Y-m-01'), $tz);
    } elseif ($period === 'last_month') {
        $since = new DateTime($today->format('Y-m-01'), $tz);
        $since->modify('-1 month');
        $until = new DateTime($since->format('Y-m-t'), $tz);
    } elseif ($period === 'maximum') {
        // Insights are available for 37 months. Start one day inside that limit and include today.
        $since = (clone $today)->modify('-37 months +1 day');
    }

    return ['since' => $since, 'until' => $until];
}

function meta_ads_period_button_label(string $period): string
{
    $period = meta_ads_normalize_period($period);
    $options = meta_ads_period_options();
    $bounds = meta_ads_period_bounds($period);

    return ($options[$period] ?? 'Last 14 days')
        . ': ' . $bounds['since']->format('j M Y')
        . ' - ' . $bounds['until']->format('j M Y');
}

/**
 * Insights field expansion for the selected range.
 */
function meta_ads_insights_field(string $period): string
{
    $bounds = meta_ads_period_bounds($period);
    $range = '{"since":"' . $bounds['since']->format('Y-m-d') . '","until":"' . $bounds['until']->format('Y-m-d') . '"}';

    return 'insights.time_range(' . $range . '){impressions,reach,spend,actions}';
}

/**
 * @param array<string, mixed> $source
 */
function meta_ads_budget_label(array $source, string $currency): string
{
    $daily = $source['daily_budget'] ?? null;
    $lifetime = $source['lifetime_budget'] ?? null;
    if ($daily !== null && $daily !== '' && (float) $daily > 0) {
        return meta_ads_format_money($daily, $currency) . ' / day';
    }
    if ($lifetime !== null && $lifetime !== '' && (float) $lifetime > 0) {
        return meta_ads_format_money($lifetime, $currency);
    }

    return '';
}

/**
 * @param array<string, mixed> $insight
 */
function meta_ads_lead_count(array $insight): int
{
    $actions = $insight['actions'] ?? [];
    if (!is_array($actions)) {
        return 0;
    }
    $byType = [];
    foreach ($actions as $action) {
        if (!is_array($action)) {
            continue;
        }
        $type = (string) ($action['action_type'] ?? '');
        if ($type === '') {
            continue;
        }
        $byType[$type] = (float) ($action['value'] ?? 0);
    }
    foreach (['lead', 'onsite_conversion.lead_grouped', 'leadgen.other', 'offsite_conversion.fb_pixel_lead'] as $type) {
        if (isset($byType[$type])) {
            return (int) round($byType[$type]);
        }
    }

    return 0;
}

function meta_ads_format_time(?string $raw): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '—';
    }
    try {
        $dt = new DateTime($raw);
        $dt->setTimezone(new DateTimeZone('Asia/Kolkata'));

        return $dt->format('d-M-Y H:i');
    } catch (Throwable $e) {
        return $raw;
    }
}

/**
 * @return array{ok:bool,error:string,accounts:list<array<string,mixed>>,ads:list<array<string,mixed>>}
 */
function meta_ads_load(string $period = 'last_14'): array
{
    $period = meta_ads_normalize_period($period);
    $settings = meta_ads_settings();
    if ($settings['access_token'] === '') {
        $tokenError = trim((string) ($settings['token_error'] ?? ''));

        return [
            'ok' => false,
            'error' => $tokenError !== '' ? $tokenError : 'Set meta_ads.access_token in config.php to a Marketing API token with ads_read.',
            'accounts' => [],
            'ads' => [],
        ];
    }

    $accountFields = 'id,name,account_status,currency,balance,amount_spent,spend_cap,funding_source_details';
    if ($settings['ad_account_id'] !== '') {
        $accountRes = meta_ads_graph_get($settings['ad_account_id'], ['fields' => $accountFields]);
        if (!$accountRes['ok'] || !is_array($accountRes['json'])) {
            return ['ok' => false, 'error' => $accountRes['error'], 'accounts' => [], 'ads' => []];
        }
        $accountRows = [$accountRes['json']];
    } else {
        $accountRes = meta_ads_graph_get('me/adaccounts', [
            'fields' => $accountFields,
            'limit' => '50',
        ]);
        if (!$accountRes['ok'] || !is_array($accountRes['json'])) {
            return ['ok' => false, 'error' => $accountRes['error'], 'accounts' => [], 'ads' => []];
        }
        $accountRows = is_array($accountRes['json']['data'] ?? null) ? $accountRes['json']['data'] : [];
    }

    $accounts = [];
    $ads = [];
    $errors = [];
    foreach ($accountRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $currency = strtoupper(trim((string) ($row['currency'] ?? 'INR')));
        $funding = is_array($row['funding_source_details'] ?? null) ? $row['funding_source_details'] : [];
        $fundingDisplay = trim((string) ($funding['display_string'] ?? ''));
        $prepaidBalance = meta_ads_prepaid_balance_label($fundingDisplay, $currency);
        $accounts[] = [
            'id' => $id,
            'name' => trim((string) ($row['name'] ?? $id)),
            'currency' => $currency,
            'balance_label' => $prepaidBalance ?? meta_ads_format_money($row['balance'] ?? null, $currency),
            'funding' => $prepaidBalance !== null ? '' : $fundingDisplay,
        ];

        $includeOff = $period === 'maximum';
        $nextPath = $id . '/ads';
        $query = [
            'fields' => 'id,name,effective_status,created_time,campaign{name,daily_budget,lifetime_budget},adset{name,daily_budget,lifetime_budget},' . meta_ads_insights_field($period),
            'effective_status' => $includeOff
                ? '["ACTIVE","PAUSED","ADSET_PAUSED","CAMPAIGN_PAUSED","ARCHIVED"]'
                : '["ACTIVE"]',
            'limit' => '100',
        ];
        $pageLimit = $includeOff ? 20 : 8;
        for ($page = 0; $page < $pageLimit && $nextPath !== ''; $page++) {
            $adsRes = meta_ads_graph_get($nextPath, $query);
            if (!$adsRes['ok'] || !is_array($adsRes['json'])) {
                $errors[] = ($row['name'] ?? $id) . ': ' . $adsRes['error'];
                break;
            }
            $data = is_array($adsRes['json']['data'] ?? null) ? $adsRes['json']['data'] : [];
            foreach ($data as $ad) {
                if (!is_array($ad)) {
                    continue;
                }
                $campaign = is_array($ad['campaign'] ?? null) ? $ad['campaign'] : [];
                $adset = is_array($ad['adset'] ?? null) ? $ad['adset'] : [];
                $budget = meta_ads_budget_label($adset, $currency);
                if ($budget === '') {
                    $budget = meta_ads_budget_label($campaign, $currency);
                }
                $insightWrap = is_array($ad['insights'] ?? null) ? $ad['insights'] : [];
                $insightRows = is_array($insightWrap['data'] ?? null) ? $insightWrap['data'] : [];
                $insight = is_array($insightRows[0] ?? null) ? $insightRows[0] : [];
                $spendAmount = (float) ($insight['spend'] ?? 0);
                $ads[] = [
                    'account' => trim((string) ($row['name'] ?? $id)),
                    'id' => trim((string) ($ad['id'] ?? '')),
                    'name' => trim((string) ($ad['name'] ?? '')),
                    'campaign' => trim((string) ($campaign['name'] ?? '')),
                    'adset' => trim((string) ($adset['name'] ?? '')),
                    'status' => trim((string) ($ad['effective_status'] ?? '')),
                    'created' => meta_ads_format_time(isset($ad['created_time']) ? (string) $ad['created_time'] : ''),
                    'leads' => meta_ads_lead_count($insight),
                    'budget' => $budget !== '' ? $budget : '—',
                    'spend_amount' => $spendAmount,
                    'currency' => $currency,
                    'spend' => meta_ads_format_major($spendAmount, $currency),
                    'impressions' => number_format((float) ($insight['impressions'] ?? 0), 0, '.', ','),
                    'reach' => number_format((float) ($insight['reach'] ?? 0), 0, '.', ','),
                ];
            }
            $next = trim((string) ($adsRes['json']['paging']['next'] ?? ''));
            if ($next === '') {
                $nextPath = '';
                break;
            }
            $parts = parse_url($next);
            $nextPath = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : '';
            $version = $settings['graph_version'] . '/';
            if (strpos($nextPath, $version) === 0) {
                $nextPath = substr($nextPath, strlen($version));
            }
            $nextQuery = [];
            if (isset($parts['query'])) {
                parse_str((string) $parts['query'], $nextQuery);
            }
            unset($nextQuery['access_token']);
            $query = $nextQuery;
        }
    }

    if ($accounts === [] && $errors !== []) {
        return ['ok' => false, 'error' => implode(' ', $errors), 'accounts' => [], 'ads' => []];
    }

    return [
        'ok' => $accounts !== [] || $errors === [],
        'error' => $errors === [] ? '' : implode(' ', $errors),
        'accounts' => $accounts,
        'ads' => $ads,
    ];
}
