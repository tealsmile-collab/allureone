<?php
declare(strict_types=1);

/**
 * Amplitude Chart API helpers for Google Ads view.
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
        'e' => json_encode(['event_type' => $event], JSON_UNESCAPED_UNICODE),
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

    $allSeries = $data['data']['series'] ?? null;
    if (is_array($allSeries) && $allSeries !== [] && is_numeric($allSeries[0]) && !is_array($allSeries[0])) {
        return ['count' => (int) array_sum(array_map('intval', $allSeries)), 'error' => ''];
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
 * @return array{count:int, failed:bool, error:string, http:int}
 */
function google_ads_fetch_amplitude_event_count(string $event, string $startDate, string $endDate, string $credentials, bool $verifySsl = false): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, google_ads_amplitude_url($event, $startDate, $endDate));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $credentials,
    ]);
    $response = (string) curl_exec($ch);
    $curlErr = (string) curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80500) {
        curl_close($ch);
    }

    $failed = google_ads_amplitude_request_failed($httpCode, $response, $curlErr);
    $parsed = google_ads_parse_amplitude_response($response, $httpCode);

    return [
        'count' => $parsed['count'],
        'failed' => $failed,
        'error' => $parsed['error'],
        'http' => $httpCode,
    ];
}

/**
 * Fetch multiple Amplitude event counts in parallel (batched), with sequential retry on failure.
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

    $batchSize = 6;
    $verifySsl = false;

    foreach (array_chunk($events, $batchSize) as $batch) {
        $mh = curl_multi_init();
        if ($mh === false) {
            break;
        }

        /** @var list<array{ch:CurlHandle|resource, event:string}> $handleMeta */
        $handleMeta = [];

        foreach ($batch as $event) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, google_ads_amplitude_url($event, $startDate, $endDate));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
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

        foreach ($handleMeta as $meta) {
            $ch = $meta['ch'];
            $event = $meta['event'];
            $response = (string) curl_multi_getcontent($ch);
            $curlErr = (string) curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);

            $failed = google_ads_amplitude_request_failed($httpCode, $response, $curlErr);
            if ($failed) {
                $retry = google_ads_fetch_amplitude_event_count($event, $startDate, $endDate, $credentials, $verifySsl);
                if (!$retry['failed']) {
                    $counts[$event] = $retry['count'];
                }
                continue;
            }

            $counts[$event] = google_ads_parse_amplitude_response($response, $httpCode)['count'];
        }

        curl_multi_close($mh);
    }

    return $counts;
}
