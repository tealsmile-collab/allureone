<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_google_ads_view_access();
require_not_accounts_role();
require_not_franchise_officer_role();

header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

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
    'google-Ad-Visit-Powai',
    'google-Ad-Visit-MulundRunwal',
    'google-Ad-Visit-Seawoods',
    'google-Ad-Visit-ThaneLodha',
    'google-Ad-Visit-VartakNagar',
    'google-Ad-Visit-Malad',
    'google-Ad-Visit-Franchise',
];

/** Menu card Amplitude events (display name + visit/call/whatsapp) */
$menuCardRows = [
    [
        'event' => 'Byke Thane MenuPage',
        'visit_event' => 'Byke-Thane-MenuCard-Visit',
        'call_event' => 'Byke-Thane-MenuCard-Call',
        'whatsapp_event' => 'Byke-Thane-MenuCard-Whatsapp',
    ],
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
    $whatsappEvent = google_ads_whatsapp_event_for_visit($visitEvent);
    if ($whatsappEvent !== null) {
        $amplitudeEvents[] = $whatsappEvent;
    }
}
foreach ($menuCardRows as $menuRow) {
    foreach (['visit_event', 'call_event', 'whatsapp_event'] as $ek) {
        $ev = trim((string) ($menuRow[$ek] ?? ''));
        if ($ev !== '') {
            $amplitudeEvents[] = $ev;
        }
    }
}

$eventCounts = google_ads_fetch_amplitude_event_counts($amplitudeEvents, $startDate, $endDate, $credentials);

$results = [];
foreach ($visitEvents as $event) {
    $callEvent = google_ads_call_event_for_visit($event);
    $whatsappEvent = google_ads_whatsapp_event_for_visit($event);
    $results[] = [
        'event' => $event,
        'count' => $eventCounts[$event] ?? 0,
        'call_event' => $callEvent,
        'call_count' => $callEvent !== null ? ($eventCounts[$callEvent] ?? 0) : null,
        'whatsapp_event' => $whatsappEvent,
        'whatsapp_count' => $whatsappEvent !== null ? ($eventCounts[$whatsappEvent] ?? 0) : null,
    ];
}
foreach ($menuCardRows as $menuRow) {
    $visitEv = (string) ($menuRow['visit_event'] ?? '');
    $callEv = (string) ($menuRow['call_event'] ?? '');
    $waEv = (string) ($menuRow['whatsapp_event'] ?? '');
    $results[] = [
        'event' => (string) ($menuRow['event'] ?? ''),
        'count' => $visitEv !== '' ? ($eventCounts[$visitEv] ?? 0) : 0,
        'call_event' => $callEv !== '' ? $callEv : null,
        'call_count' => $callEv !== '' ? ($eventCounts[$callEv] ?? 0) : null,
        'whatsapp_event' => $waEv !== '' ? $waEv : null,
        'whatsapp_count' => $waEv !== '' ? ($eventCounts[$waEv] ?? 0) : null,
    ];
}

$totalVisits = 0;
$totalCalls = 0;
$totalWhatsapp = 0;
$countedCallEvents = [];
$countedWaEvents = [];
foreach ($results as $row) {
    $totalVisits += (int) ($row['count'] ?? 0);
    $callEventKey = (string) ($row['call_event'] ?? '');
    if ($callEventKey !== '' && !isset($countedCallEvents[$callEventKey])) {
        $totalCalls += (int) ($row['call_count'] ?? 0);
        $countedCallEvents[$callEventKey] = true;
    }
    $waEventKey = (string) ($row['whatsapp_event'] ?? '');
    if ($waEventKey !== '' && !isset($countedWaEvents[$waEventKey])) {
        $totalWhatsapp += (int) ($row['whatsapp_count'] ?? 0);
        $countedWaEvents[$waEventKey] = true;
    }
}

echo json_encode([
    'ok' => true,
    'date' => $selectedDateInput,
    'results' => $results,
    'total' => $totalVisits,
    'total_calls' => $totalCalls,
    'total_whatsapp' => $totalWhatsapp,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
