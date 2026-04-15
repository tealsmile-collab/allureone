<?php
declare(strict_types=1);

/**
 * Copies Dingg bearer from browser localStorage into PHP session (dingg_encrypt_session_token)
 * so server-side Dingg API calls can use dingg_resolve_pos_token_for_api().
 */
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
    echo json_encode(['ok' => false, 'error' => 'Invalid session.'], JSON_UNESCAPED_UNICODE);

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
    echo json_encode(['ok' => true, 'skipped' => true], JSON_UNESCAPED_UNICODE);

    exit;
}

dingg_encrypt_session_token($headerToken);
echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
