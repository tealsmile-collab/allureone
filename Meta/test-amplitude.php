<?php
$apiKey = 'e616b0354f9af02d249bfe8942463141';
$secretKey = 'dd2b761a626303a25249c9d57d6b2fb0';
$credentials = base64_encode($apiKey . ':' . $secretKey);
$startDate = date('Ymd');
$events = [
    'google-Ad-Visit-Marol',
    'google-Ad-Call-Marol',
];
foreach ($events as $event) {
    $t0 = microtime(true);
    $params = http_build_query([
        'e' => json_encode(['event_type' => $event]),
        'start' => $startDate,
        'end' => $startDate,
        'm' => 'totals',
        'i' => '1',
    ], '', '&', PHP_QUERY_RFC3986);
    $url = 'https://amplitude.com/api/2/events/segmentation?' . $params;
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Authorization: Basic ' . $credentials],
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $ms = round((microtime(true) - $t0) * 1000);
    echo "$event HTTP=$http {$ms}ms err=" . ($err ?: 'none') . "\n";
    if ($resp) {
        $data = json_decode((string) $resp, true);
        $count = $data['data']['series'][0][0] ?? '?';
        echo "  count=$count\n";
    }
}
