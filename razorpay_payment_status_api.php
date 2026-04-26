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

$apiUrl = 'https://api.razorpay.com/v1/payments/' . rawurlencode($paymentId);
$rzpUser = 'rzp_live_rWoS2QO65A1I2g';
$rzpPass = 'SjGyHZWxwn3xarvT4Hk2TpKS';

$body = '';
$http = 0;
$curlErr = '';
if (function_exists('curl_init')) {
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
} else {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'cURL not available.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($curlErr !== '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Razorpay call failed: ' . $curlErr], JSON_UNESCAPED_UNICODE);
    exit;
}

$json = json_decode($body, true);
if (!is_array($json)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'Invalid response from Razorpay.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($http < 200 || $http >= 300) {
    $msg = trim((string) ($json['error']['description'] ?? $json['error_description'] ?? 'Razorpay returned HTTP ' . $http));
    echo json_encode(['ok' => false, 'error' => $msg !== '' ? $msg : ('Razorpay returned HTTP ' . $http)], JSON_UNESCAPED_UNICODE);
    exit;
}

$currency = strtoupper((string) ($json['currency'] ?? 'INR'));
$amountPaise = is_numeric($json['amount'] ?? null) ? (float) $json['amount'] : 0.0;
$amountFormatted = $currency . ' ' . number_format($amountPaise / 100, 2, '.', '');

$result = [
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

