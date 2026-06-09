<?php
declare(strict_types=1);

/**
 * POST JSON announcement to a specific user (by user_id or mobile).
 *
 * Header: X-Announcement-Api-Key: <secret from config.php pwa.announcement_api_key>
 * Or: Authorization: Bearer <secret>
 *
 * Body: { "message": "...", "user_id": 123 } or { "message": "...", "mobile": "9876543210" }
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed. Use POST.']);
    exit;
}

if (pwa_announcement_api_key() === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'Announcement API is not configured. Set pwa.announcement_api_key in config.php.']);
    exit;
}

if (!pwa_validate_announcement_api_key(pwa_extract_announcement_api_key_from_request())) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid or missing API key.']);
    exit;
}

$raw = file_get_contents('php://input');
$json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($json)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body.']);
    exit;
}

$message = trim((string) ($json['message'] ?? ''));
$userId = isset($json['user_id']) ? (int) $json['user_id'] : null;
$mobile = trim((string) ($json['mobile'] ?? ''));

if ($message === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'message is required.']);
    exit;
}

$resolved = pwa_resolve_announcement_user($userId !== null && $userId > 0 ? $userId : null, $mobile !== '' ? $mobile : null);
if (!($resolved['ok'] ?? false)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => (string) ($resolved['error'] ?? 'User not found.')]);
    exit;
}

$targetUserIds = (array) ($resolved['user_ids'] ?? []);
$result = pwa_send_announcement($message, 0, 'API', $targetUserIds, 'api');

if (!($result['ok'] ?? false)) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => (string) ($result['error'] ?? 'Could not send announcement.'),
    ]);
    exit;
}

$sent = (int) ($result['sent'] ?? 0);
$failed = (int) ($result['failed'] ?? 0);
$errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];

echo json_encode([
    'ok' => true,
    'announcement_id' => (int) ($result['announcement_id'] ?? 0),
    'user_id' => (int) ($targetUserIds[0] ?? 0),
    'sent' => $sent,
    'failed' => $failed,
    'error' => $errors !== [] ? (string) $errors[0] : '',
]);
