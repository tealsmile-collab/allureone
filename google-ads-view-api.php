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
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$apiKey = 'e616b0354f9af02d249bfe8942463141';
$secretKey = 'dd2b761a626303a25249c9d57d6b2fb0';
$selectedDateInput = trim((string) ($_GET['date'] ?? date('Y-m-d')));
$startDate = date('Ymd');
$endDate = $startDate;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDateInput) === 1) {
    $startDate = str_replace('-', '', $selectedDateInput);
    $endDate = $startDate;
}

/** @var list<string> Fixed display order */
$visitEvents = [
    'google-Ad-Visit-Marol',
    'google-Ad-Visit-AndheriWest',
    'google-Ad-Visit-BorivaliWest',
    'google-Ad-Visit-Powai',
    'google-Ad-Visit-Mulund',
    'google-Ad-Visit-Seawoods',
    'google-Ad-Visit-ThaneLodha',
    'google-Ad-Visit-Malad-NewPage',
    'google-Ad-Visit-Franchise',
];

if (!function_exists('curl_init')) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cURL extension is not enabled on this server.']);
    exit;
}
if ($apiKey === 'YOUR_API_KEY' || $secretKey === 'YOUR_SECRET_KEY') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Please set Amplitude API key and secret key in google-ads-view-api.php.']);
    exit;
}

$credentials = base64_encode($apiKey . ':' . $secretKey);

/**
 * google-Ad-Visit-{Location} → google-Ad-Call-{Location}
 */
function google_ads_call_event_for_visit(string $visitEvent): ?string
{
    $prefix = 'google-Ad-Visit-';
    if (strncmp($visitEvent, $prefix, strlen($prefix)) === 0) {
        $location = substr($visitEvent, strlen($prefix));
        if ($location !== '') {
            return 'google-Ad-Call-' . $location;
        }
    }

    return null;
}

function google_ads_amplitude_url(string $event, string $startDate, string $endDate): string
{
    $params = http_build_query([
        'e' => json_encode(['event_type' => $event]),
        'start' => $startDate,
        'end' => $endDate,
        'm' => 'totals',
        'i' => '1',
    ], '', '&', PHP_QUERY_RFC3986);

    return 'https://amplitude.com/api/2/events/segmentation?' . $params;
}

function google_ads_is_ssl_certificate_error(string $curlErr): bool
{
    return $curlErr !== '' && (
        stripos($curlErr, 'unable to get local issuer certificate') !== false
        || stripos($curlErr, 'certificate') !== false
    );
}

function google_ads_amplitude_request_failed(int $httpCode, string $response, string $curlErr): bool
{
    if ($curlErr !== '') {
        return true;
    }

    return $httpCode === 0 || trim($response) === '';
}

/**
 * @return array{count:int, error:string}
 */
function google_ads_parse_amplitude_response(string $response, int $httpCode): array
{
    if ($httpCode >= 400) {
        return ['count' => 0, 'error' => 'HTTP ' . $httpCode];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['count' => 0, 'error' => 'Invalid Amplitude response'];
    }

    if (isset($data['error']) && is_array($data['error'])) {
        $message = trim((string) ($data['error']['message'] ?? 'Amplitude API error'));

        return ['count' => 0, 'error' => $message !== '' ? $message : 'Amplitude API error'];
    }

    $series = $data['data']['series'][0] ?? null;
    if (!is_array($series) || $series === []) {
        $collapsed = $data['data']['seriesCollapsed'][0][0]['value'] ?? null;
        if ($collapsed !== null) {
            return ['count' => (int) $collapsed, 'error' => ''];
        }

        return ['count' => 0, 'error' => ''];
    }

    if (count($series) === 1) {
        return ['count' => (int) ($series[0] ?? 0), 'error' => ''];
    }

    return ['count' => (int) array_sum(array_map('intval', $series)), 'error' => ''];
}

/**
 * Fetch multiple Amplitude event counts in parallel.
 *
 * @param list<string> $events
 * @return array<string, int>
 */
function google_ads_fetch_amplitude_event_counts(array $events, string $startDate, string $endDate, string $credentials): array
{
    $events = array_values(array_unique(array_filter($events, static fn ($e) => $e !== '')));
    $counts = [];
    foreach ($events as $event) {
        $counts[$event] = 0;
    }
    if ($events === []) {
        return $counts;
    }

    $pending = $events;
    // Shared hosting often fails SSL verify with curl_multi; skip verify up front.
    $verifySsl = false;
    $retryPass = 0;

    while ($pending !== []) {
        $mh = curl_multi_init();
        if ($mh === false) {
            break;
        }

        /** @var list<array{ch:CurlHandle|resource, event:string}> $handleMeta */
        $handleMeta = [];

        foreach ($pending as $event) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, google_ads_amplitude_url($event, $startDate, $endDate));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 25);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . $credentials,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handleMeta[] = ['ch' => $ch, 'event' => $event];
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($status === CURLM_CALL_MULTI_PERFORM) {
                continue;
            }
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0);

        $retryEvents = [];
        foreach ($handleMeta as $meta) {
            $ch = $meta['ch'];
            $event = $meta['event'];
            $response = (string) curl_multi_getcontent($ch);
            $curlErr = (string) curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_multi_remove_handle($mh, $ch);
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }

            $failed = google_ads_amplitude_request_failed($httpCode, $response, $curlErr);
            $shouldRetry = $failed && $retryPass < 1 && (
                $verifySsl
                || google_ads_is_ssl_certificate_error($curlErr)
                || $httpCode === 0
                || trim($response) === ''
            );
            if ($shouldRetry) {
                $retryEvents[] = $event;
                continue;
            }

            if (!$failed) {
                $counts[$event] = google_ads_parse_amplitude_response($response, $httpCode)['count'];
            }
        }

        curl_multi_close($mh);

        if ($retryEvents !== []) {
            $pending = $retryEvents;
            $verifySsl = false;
            $retryPass++;
            continue;
        }

        break;
    }

    return $counts;
}

$amplitudeEvents = $visitEvents;
foreach ($visitEvents as $visitEvent) {
    $callEvent = google_ads_call_event_for_visit($visitEvent);
    if ($callEvent !== null) {
        $amplitudeEvents[] = $callEvent;
    }
}

$eventCounts = google_ads_fetch_amplitude_event_counts($amplitudeEvents, $startDate, $endDate, $credentials);

$results = [];
foreach ($visitEvents as $event) {
    $callEvent = google_ads_call_event_for_visit($event);
    $results[] = [
        'event' => $event,
        'count' => $eventCounts[$event] ?? 0,
        'call_event' => $callEvent,
        'call_count' => $callEvent !== null ? ($eventCounts[$callEvent] ?? 0) : null,
    ];
}

$totalVisits = 0;
$totalCalls = 0;
$countedCallEvents = [];
foreach ($results as $row) {
    $totalVisits += (int) ($row['count'] ?? 0);
    $callEventKey = (string) ($row['call_event'] ?? '');
    if ($callEventKey !== '' && !isset($countedCallEvents[$callEventKey])) {
        $totalCalls += (int) ($row['call_count'] ?? 0);
        $countedCallEvents[$callEventKey] = true;
    }
}

echo json_encode([
    'ok' => true,
    'date' => $selectedDateInput,
    'results' => $results,
    'total' => $totalVisits,
    'total_calls' => $totalCalls,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
