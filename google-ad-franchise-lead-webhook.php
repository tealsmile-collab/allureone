<?php
declare(strict_types=1);

/**
 * Google Ads Lead Form webhook endpoint.
 * For now, it writes incoming payloads to a local log file.
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok' => false,
        'error' => 'Method not allowed. Use POST.',
    ]);
    exit;
}

date_default_timezone_set('UTC');
$expectedWebhookKey = 'aellure123franchise';

$rawBody = (string) file_get_contents('php://input');
$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));

$parsed = [];
if ($rawBody !== '') {
    $json = json_decode($rawBody, true);
    if (is_array($json)) {
        $parsed = $json;
    } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
        parse_str($rawBody, $formData);
        if (is_array($formData)) {
            $parsed = $formData;
        }
    }
}

if ($parsed === [] && $_POST !== []) {
    $parsed = $_POST;
}

$headers = [];
if (function_exists('getallheaders')) {
    $h = getallheaders();
    if (is_array($h)) {
        $headers = $h;
    }
}

$incomingKey = trim((string) (
    $_GET['key']
    ?? $_POST['key']
    ?? ($parsed['key'] ?? '')
    ?? ($_SERVER['HTTP_X_WEBHOOK_KEY'] ?? '')
    ?? ($_SERVER['HTTP_X_GOOGLE_WEBHOOK_KEY'] ?? '')
));
if ($incomingKey === '' && is_array($headers)) {
    $incomingKey = trim((string) (
        $headers['X-Webhook-Key']
        ?? $headers['x-webhook-key']
        ?? $headers['X-Google-Webhook-Key']
        ?? $headers['x-google-webhook-key']
        ?? ''
    ));
}

if (!hash_equals($expectedWebhookKey, $incomingKey)) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Unauthorized webhook key.',
    ]);
    exit;
}

$entry = [
    'received_at_utc' => gmdate('Y-m-d H:i:s'),
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    'remote_addr' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    'content_type' => $contentType,
    'query' => $_GET,
    'headers' => $headers,
    'raw_body' => $rawBody,
    'parsed_payload' => $parsed,
];

$logPath = __DIR__ . '/google_ad_franchise_lead_webhook.log';
$logLine = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($logLine === false) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Could not encode webhook payload.',
    ]);
    exit;
}

$writeOk = @file_put_contents($logPath, $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
if ($writeOk === false) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Could not write webhook log file.',
    ]);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => 'Webhook received and logged.',
]);
