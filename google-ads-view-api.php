<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
$roleId = (int) ($user['role_id'] ?? 0);
if ($roleId !== ROLE_SUPERADMIN && $roleId !== ROLE_ADMIN && $roleId !== 3) {
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

$visitEvents = [
    'google-Ad-Visit-Powai',
    'google-Ad-Visit-Malad',
    'google-Ad-Visit-BorivaliWest',
    'google-Ad-Visit-Marol',
    'google-Ad-Visit-AndheriWest',
    'google-Ad-Visit-Franchise',
    'google-Ad-Visit-Mulund',
    'google-Ad-Visit-ThaneLodha',
    'google-Ad-Visit-Seawoods',
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
$results = [];

foreach ($visitEvents as $event) {
    $params = http_build_query([
        'e' => json_encode(['event_type' => $event]),
        'start' => $startDate,
        'end' => $endDate,
        'm' => 'totals',
        'i' => '1',
    ], '', '&', PHP_QUERY_RFC3986);

    $url = 'https://amplitude.com/api/2/events/segmentation?' . $params;
    $doCall = static function (bool $verifySsl) use ($url, $credentials): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $credentials,
        ]);

        $respBody = curl_exec($ch);
        $err = curl_error($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        return [
            'body' => ($respBody === false ? '' : (string) $respBody),
            'error' => (string) $err,
            'http' => $http,
        ];
    };

    $call = $doCall(true);
    $response = (string) ($call['body'] ?? '');
    $curlErr = (string) ($call['error'] ?? '');
    $httpCode = (int) ($call['http'] ?? 0);
    if (
        $curlErr !== '' &&
        (stripos($curlErr, 'unable to get local issuer certificate') !== false || stripos($curlErr, 'certificate') !== false)
    ) {
        $retry = $doCall(false);
        $response = (string) ($retry['body'] ?? '');
        $curlErr = (string) ($retry['error'] ?? '');
        $httpCode = (int) ($retry['http'] ?? 0);
    }

    if ($curlErr !== '') {
        $results[] = ['event' => $event, 'count' => 0];
        continue;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || $httpCode >= 400) {
        $results[] = ['event' => $event, 'count' => 0];
        continue;
    }

    $count = (int) (($data['data']['series'][0][0] ?? 0));
    $results[] = ['event' => $event, 'count' => $count];
}

usort($results, static function (array $a, array $b): int {
    return (int) ($b['count'] ?? 0) <=> (int) ($a['count'] ?? 0);
});

$total = 0;
foreach ($results as $row) {
    $total += (int) ($row['count'] ?? 0);
}

echo json_encode([
    'ok' => true,
    'date' => $selectedDateInput,
    'results' => $results,
    'total' => $total,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
