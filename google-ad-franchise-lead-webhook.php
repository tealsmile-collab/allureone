<?php
declare(strict_types=1);

/**
 * Google Ads Lead Form webhook endpoint.
 * Logs webhook payload and stores lead in DB.
 */
require_once __DIR__ . '/includes/database.php';

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
    } elseif (strpos($contentType, 'application/x-www-form-urlencoded') !== false) {
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

$incomingKey = trim((string) ($parsed['google_key'] ?? ''));
if ($incomingKey === '' && isset($_POST['google_key'])) {
    $incomingKey = trim((string) $_POST['google_key']);
}
if ($incomingKey === '' && isset($_GET['google_key'])) {
    $incomingKey = trim((string) $_GET['google_key']);
}

if (!hash_equals($expectedWebhookKey, $incomingKey)) {
    error_log('GoogleAdWebhook request unauthorized: method=' . (string) ($_SERVER['REQUEST_METHOD'] ?? '') . ' ip=' . (string) ($_SERVER['REMOTE_ADDR'] ?? '') . ' content_type=' . $contentType . ' lead_id=' . trim((string) ($parsed['lead_id'] ?? '')));
    http_response_code(401);
    $response = [
        'ok' => false,
        'error' => 'Unauthorized webhook key.',
    ];
    error_log('GoogleAdWebhook response 401: ' . json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo json_encode($response);
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

error_log('GoogleAdWebhook request accepted: ' . json_encode([
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
    'content_type' => $contentType,
    'query' => $_GET,
    'payload_keys' => array_keys(is_array($parsed) ? $parsed : []),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

$logPath = __DIR__ . '/google_ad_franchise_lead_webhook.log';
$logLine = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($logLine === false) {
    http_response_code(500);
    $response = [
        'ok' => false,
        'error' => 'Could not encode webhook payload.',
    ];
    error_log('GoogleAdWebhook response 500: ' . json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo json_encode($response);
    exit;
}

$writeOk = @file_put_contents($logPath, $logLine . PHP_EOL, FILE_APPEND | LOCK_EX);
if ($writeOk === false) {
    http_response_code(500);
    $response = [
        'ok' => false,
        'error' => 'Could not write webhook log file.',
    ];
    error_log('GoogleAdWebhook response 500: ' . json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo json_encode($response);
    exit;
}

/**
 * @param array<string, mixed> $payload
 */
function webhook_extract_lead_value(array $payload, string $targetColumnId): string
{
    $rows = $payload['user_column_data'] ?? null;
    if (!is_array($rows)) {
        return '';
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $columnId = trim((string) ($row['column_id'] ?? ''));
        if ($columnId !== $targetColumnId) {
            continue;
        }

        return trim((string) ($row['string_value'] ?? ''));
    }

    return '';
}

$fullName = webhook_extract_lead_value($parsed, 'FULL_NAME');
$phoneNumber = webhook_extract_lead_value($parsed, 'PHONE_NUMBER');
$city = webhook_extract_lead_value($parsed, 'CITY');
$investmentBudget = webhook_extract_lead_value($parsed, 'what_is_your_investment_budget_for_the_project?');
$preferredTimeline = webhook_extract_lead_value($parsed, 'what_is_your_preferred_timeline_to_start_operations?');
$experienceInWellness = webhook_extract_lead_value($parsed, 'do_you_have_experience_in_the_wellness_or_beauty_industry?');
$propertyForWellness = webhook_extract_lead_value($parsed, 'do_you_already_have_a_property_for_the_wellness_centre?');
$formId = trim((string) ($parsed['form_id'] ?? ''));
$campaignId = trim((string) ($parsed['campaign_id'] ?? ''));

try {
    $pdo = db();
    $sql = 'INSERT INTO allureone_franchise_leads (
                FULL_NAME,
                PHONE_NUMBER,
                CITY,
                investment_budget,
                preferred_timeline,
                experience_in_the_wellness,
                property_for_the_wellness,
                DateTime,
                form_id,
                campaign_id
            ) VALUES (
                :full_name,
                :phone_number,
                :city,
                :investment_budget,
                :preferred_timeline,
                :experience_in_the_wellness,
                :property_for_the_wellness,
                NOW(),
                :form_id,
                :campaign_id
            )';
    $st = $pdo->prepare($sql);
    $st->execute([
        'full_name' => $fullName,
        'phone_number' => $phoneNumber,
        'city' => $city,
        'investment_budget' => $investmentBudget,
        'preferred_timeline' => $preferredTimeline,
        'experience_in_the_wellness' => $experienceInWellness,
        'property_for_the_wellness' => $propertyForWellness,
        'form_id' => $formId,
        'campaign_id' => $campaignId,
    ]);
    error_log('GoogleAdWebhook DB insert success: lead_id=' . trim((string) ($parsed['lead_id'] ?? '')) . ' form_id=' . $formId . ' campaign_id=' . $campaignId);
} catch (Throwable $e) {
    http_response_code(500);
    $response = [
        'ok' => false,
        'error' => 'Could not save lead into database.',
    ];
    error_log('GoogleAdWebhook DB insert failed: ' . $e->getMessage());
    error_log('GoogleAdWebhook response 500: ' . json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    echo json_encode($response);
    exit;
}

$response = [
    'ok' => true,
    'message' => 'Webhook received, logged, and saved.',
];
error_log('GoogleAdWebhook response 200: ' . json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
echo json_encode($response);
