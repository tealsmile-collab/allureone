<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/pwa.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$raw = file_get_contents('php://input');
$json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($json)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON.']);
    exit;
}

$ackToken = trim((string) ($json['ack_token'] ?? ''));
$event = trim((string) ($json['event'] ?? ''));
$result = pwa_ack_delivery($ackToken, $event);
echo json_encode($result);
