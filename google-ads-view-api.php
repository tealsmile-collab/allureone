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

require_once __DIR__ . '/includes/google_ads_amplitude.php';

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
    'google-Ad-Visit-BorivaliWest-NewPage',
    'google-Ad-Visit-Powai',
    'google-Ad-Visit-Powai-NewPage',
    'google-Ad-Visit-Mulund',
    'google-Ad-Visit-Seawoods',
    'google-Ad-Visit-ThaneLodha',
    'google-Ad-Visit-ThaneLodha-NewPage',
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
