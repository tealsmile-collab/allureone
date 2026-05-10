<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (current_user() === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw !== false && $raw !== '' ? $raw : '[]', true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!csrf_validate(isset($data['_csrf']) ? (string) $data['_csrf'] : '')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid session. Please refresh and try again.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$paymentId = trim((string) ($data['payment_id'] ?? ''));
if ($paymentId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing payment id.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (strlen($paymentId) > 50) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Payment id must be at most 50 characters.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rzpUser = 'rzp_live_rWoS2QO65A1I2g';
$rzpPass = 'SjGyHZWxwn3xarvT4Hk2TpKS';

/**
 * @return array{body:string,http:int,curlErr:string}
 */
function razorpay_api_get_json(string $apiUrl, string $rzpUser, string $rzpPass): array
{
    if (!function_exists('curl_init')) {
        return ['body' => '', 'http' => 0, 'curlErr' => 'cURL not available'];
    }

    $request = static function (bool $verifySsl) use ($apiUrl, $rzpUser, $rzpPass): array {
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $rzpUser . ':' . $rzpPass,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
        ]);
        $respBody = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (PHP_VERSION_ID < 80500) {
            curl_close($ch);
        }

        return [
            'body' => $respBody !== false ? (string) $respBody : '',
            'http' => $code,
            'err' => $err,
        ];
    };

    $r = $request(true);
    $body = (string) ($r['body'] ?? '');
    $http = (int) ($r['http'] ?? 0);
    $curlErr = (string) ($r['err'] ?? '');

    if ($curlErr !== '' && stripos($curlErr, 'SSL certificate') !== false) {
        error_log('Razorpay status API retrying with SSL verify disabled due to cert chain issue.');
        $r2 = $request(false);
        $body = (string) ($r2['body'] ?? '');
        $http = (int) ($r2['http'] ?? 0);
        $curlErr = (string) ($r2['err'] ?? '');
    }

    return ['body' => $body, 'http' => $http, 'curlErr' => $curlErr];
}

function razorpay_error_description_from_body(array $json, int $http): string
{
    $msg = trim((string) ($json['error']['description'] ?? $json['error_description'] ?? ''));
    if ($msg === '') {
        $msg = 'Razorpay returned HTTP ' . $http;
    }

    return $msg;
}

function razorpay_is_id_not_found_error(string $description): bool
{
    $d = strtolower($description);

    return str_contains($d, 'does not exist')
        || str_contains($d, 'id provided')
        || str_contains($d, 'no such payment')
        || str_contains($d, 'invalid payment');
}

function razorpay_format_amount_paise(float $paise, string $currency): string
{
    $cur = strtoupper($currency !== '' ? $currency : 'INR');

    return $cur . ' ' . number_format($paise / 100, 2, '.', '');
}

$paymentUrl = 'https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId);
$r = razorpay_api_get_json($paymentUrl, $rzpUser, $rzpPass);

if ($r['curlErr'] !== '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Razorpay call failed: ' . $r['curlErr']], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = $r['body'];
$http = $r['http'];
$json = json_decode($body, true);

if (!is_array($json)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Invalid response from Razorpay.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tryOrderFallback = false;
if ($http < 200 || $http >= 300) {
    $errMsg = razorpay_error_description_from_body($json, $http);
    if (razorpay_is_id_not_found_error($errMsg)) {
        $tryOrderFallback = true;
    } else {
        echo json_encode(['ok' => false, 'error' => $errMsg], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!$tryOrderFallback) {
    $currency = strtoupper((string) ($json['currency'] ?? 'INR'));
    $amountPaise = is_numeric($json['amount'] ?? null) ? (float) $json['amount'] : 0.0;
    $amountFormatted = razorpay_format_amount_paise($amountPaise, $currency);

    $result = [
        'record_type' => 'payment',
        'payment_id' => (string) ($json['id'] ?? ''),
        'order_id' => (string) ($json['order_id'] ?? ''),
        'payment_status' => (string) ($json['status'] ?? ''),
        'amount' => $amountFormatted,
        'contact' => (string) ($json['contact'] ?? ''),
        'email' => (string) ($json['email'] ?? ''),
        'payment_method' => (string) ($json['method'] ?? ''),
        'error_description' => (string) ($json['error_description'] ?? ''),
        'error_reason' => (string) ($json['error_reason'] ?? ''),
        'error_step' => (string) ($json['error_step'] ?? ''),
        'has_error' => ($json['error_code'] ?? null) !== null,
    ];

    echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

// Fallback: treat id as Razorpay Order id (GET /v1/orders/{id})
$orderUrl = 'https://api.razorpay.com/v1/orders/' . rawurlencode($paymentId);
$r2 = razorpay_api_get_json($orderUrl, $rzpUser, $rzpPass);

if ($r2['curlErr'] !== '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Razorpay order call failed: ' . $r2['curlErr']], JSON_UNESCAPED_UNICODE);
    exit;
}

$oBody = $r2['body'];
$oHttp = $r2['http'];
$oJson = json_decode($oBody, true);

if (!is_array($oJson)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Invalid response from Razorpay (order).'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($oHttp < 200 || $oHttp >= 300) {
    $msg = razorpay_error_description_from_body($oJson, $oHttp);
    echo json_encode(['ok' => false, 'error' => $msg !== '' ? $msg : ('Razorpay returned HTTP ' . $oHttp)], JSON_UNESCAPED_UNICODE);
    exit;
}

$currency = strtoupper((string) ($oJson['currency'] ?? 'INR'));
$amt = is_numeric($oJson['amount'] ?? null) ? (float) $oJson['amount'] : 0.0;
$amtPaid = is_numeric($oJson['amount_paid'] ?? null) ? (float) $oJson['amount_paid'] : 0.0;
$amtDue = is_numeric($oJson['amount_due'] ?? null) ? (float) $oJson['amount_due'] : 0.0;
$attempts = is_numeric($oJson['attempts'] ?? null) ? (int) $oJson['attempts'] : 0;

$result = [
    'record_type' => 'order',
    'payment_id' => '',
    'order_id' => (string) ($oJson['id'] ?? ''),
    'payment_status' => (string) ($oJson['status'] ?? ''),
    'amount' => razorpay_format_amount_paise($amt, $currency),
    'amount_paid' => razorpay_format_amount_paise($amtPaid, $currency),
    'amount_due' => razorpay_format_amount_paise($amtDue, $currency),
    'currency' => $currency,
    'receipt' => (string) ($oJson['receipt'] ?? ''),
    'attempts' => $attempts,
    'contact' => '',
    'email' => '',
    'payment_method' => '',
    'error_description' => '',
    'error_reason' => '',
    'error_step' => '',
    'has_error' => false,
];

echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
