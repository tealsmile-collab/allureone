<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/pwa.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($json)) {
    $json = $_POST;
}

if (!csrf_validate($json['_csrf'] ?? null)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid session.']);
    exit;
}

$user = current_user();
$userId = (int) ($user['id'] ?? 0);
$action = strtolower(trim((string) ($json['action'] ?? 'subscribe')));

if ($action === 'unsubscribe') {
    $endpoint = trim((string) ($json['endpoint'] ?? ''));
    $result = pwa_deactivate_push_subscription($userId, $endpoint);
    echo json_encode($result);
    exit;
}

$subscription = is_array($json['subscription'] ?? null) ? $json['subscription'] : [];
$userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$result = pwa_save_push_subscription($userId, $subscription, $userAgent);
echo json_encode($result);
