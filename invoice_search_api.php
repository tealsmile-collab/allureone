<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/invoice_search_render.php';

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

$csrf = isset($data['_csrf']) ? (string) $data['_csrf'] : '';
if (!csrf_validate($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid session. Please refresh and try again.'], JSON_UNESCAPED_UNICODE);

    exit;
}

$term = isset($data['term']) ? sanitize_invoice_search_term((string) $data['term']) : '';
if ($term === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Please enter an invoice number.'], JSON_UNESCAPED_UNICODE);

    exit;
}

$headerToken = '';
if (function_exists('getallheaders')) {
    $h = getallheaders();
    if (is_array($h)) {
        foreach ($h as $k => $v) {
            if (strcasecmp((string) $k, 'X-AllureOne-Dingg-Token') === 0) {
                $headerToken = trim((string) $v);
                break;
            }
        }
    }
}
if ($headerToken === '' && isset($_SERVER['HTTP_X_ALLUREONE_DINGG_TOKEN'])) {
    $headerToken = trim((string) $_SERVER['HTTP_X_ALLUREONE_DINGG_TOKEN']);
}

if ($headerToken === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'No Dingg token. Sign in again or allow localStorage for this site.'], JSON_UNESCAPED_UNICODE);

    exit;
}

$invoiceApiResult = dingg_fetch_vendor_bills_with_token($term, $headerToken);
$invHttp = (int) ($invoiceApiResult['http'] ?? 0);
$invBody = (string) ($invoiceApiResult['body'] ?? '');
if (($invoiceApiResult['ok'] ?? false) && dingg_response_looks_unauthorized($invHttp, $invBody)) {
    echo json_encode([
        'ok' => false,
        'auth_expired' => true,
        'error' => dingg_auth_expired_user_message(),
    ], JSON_UNESCAPED_UNICODE);

    exit;
}
if (!($invoiceApiResult['ok'] ?? false)) {
    $detail = (string) ($invoiceApiResult['error_detail'] ?? '');
    $err = (string) ($invoiceApiResult['error'] ?? 'Could not search invoices.');
    echo json_encode([
        'ok' => false,
        'error' => $err,
        'error_detail' => $detail,
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$parsed = allureone_parse_invoice_bills_api_result($invoiceApiResult);
$invBranchLabel = '';
if ($parsed['invBill'] !== null && is_array($parsed['invBill'])) {
    $invLocationMap = dingg_fetch_vendor_business_location_map_with_token($headerToken);
    $invBranchLabel = dingg_invoice_bill_branch_display($parsed['invBill'], $invLocationMap);
}

$html = allureone_invoice_search_result_markup(
    $parsed,
    $invoiceApiResult,
    $term,
    csrf_token(),
    $invBranchLabel
);

echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);
