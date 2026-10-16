<?php
declare(strict_types=1);

/**
 * @return array{graph_version:string,access_token:string,ad_account_id:string}
 */
function meta_ads_settings(): array
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
        'access_token' => trim((string) ($cfg['access_token'] ?? '')),
        'ad_account_id' => $accountId,
    ];
}

/**
 * @return array{ok:bool,http:int,json:?array,error:string}
 */
function meta_ads_graph_get(string $path, array $query = []): array
{
    $settings = meta_ads_settings();
    if ($settings['access_token'] === '') {
        return ['ok' => false, 'http' => 0, 'json' => null, 'error' => 'Meta Ads access token is not configured.'];
    }
    $query['access_token'] = $settings['access_token'];
    $url = 'https://graph.facebook.com/' . rawurlencode($settings['graph_version']) . '/' . ltrim($path, '/');
    $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

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

        return ['ok' => false, 'http' => $http, 'json' => $json, 'error' => $msg !== '' ? $msg : 'Meta request failed.'];
    }
    if ($http !== 0 && ($http < 200 || $http >= 300)) {
        return ['ok' => false, 'http' => $http, 'json' => $json, 'error' => 'Meta request failed (HTTP ' . $http . ').'];
    }

    return ['ok' => true, 'http' => $http, 'json' => $json, 'error' => ''];
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
        return [
            'ok' => false,
            'error' => 'Set meta_ads.access_token in config.php to a Marketing API token with ads_read.',
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
        $accounts[] = [
            'id' => $id,
            'name' => trim((string) ($row['name'] ?? $id)),
            'currency' => $currency,
            'balance_label' => meta_ads_format_money($row['balance'] ?? null, $currency),
            'funding' => trim((string) ($funding['display_string'] ?? '')),
        ];

        $nextPath = $id . '/ads';
        $query = [
            'fields' => 'id,name,effective_status,created_time,campaign{name,daily_budget,lifetime_budget},adset{name,daily_budget,lifetime_budget},' . meta_ads_insights_field($period),
            'effective_status' => '["ACTIVE"]',
            'limit' => '100',
        ];
        for ($page = 0; $page < 8 && $nextPath !== ''; $page++) {
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
                    'spend' => meta_ads_format_major($insight['spend'] ?? '0', $currency),
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
