<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$raw = file_get_contents('php://input');
$decoded = is_string($raw) ? json_decode($raw, true) : null;
$input = is_array($decoded) ? $decoded : $_POST;
$csrf = (string) ($input['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if (!csrf_validate($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Request was rejected.']);
    exit;
}

$catalogPath = __DIR__ . '/storage/sop/catalog.json';
$catalog = [];
if (is_file($catalogPath)) {
    $parsed = json_decode((string) file_get_contents($catalogPath), true);
    if (is_array($parsed)) {
        $catalog = $parsed;
    }
}

$byId = [];
foreach ($catalog as $row) {
    if (!is_array($row)) {
        continue;
    }
    $id = (string) ($row['id'] ?? '');
    if ($id !== '' && preg_match('/^\d+$/', $id)) {
        $byId[$id] = $row;
    }
}

$key = trim((string) ($input['k'] ?? 'list'));
if ($key === '' || $key === 'list') {
    $items = [];
    foreach ($catalog as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sections = [];
        foreach (is_array($row['sections'] ?? null) ? $row['sections'] : [] as $section) {
            if (!is_array($section)) {
                continue;
            }
            $sections[] = [
                'n' => (string) ($section['n'] ?? ''),
                'title' => (string) ($section['title'] ?? ''),
                'page' => max(1, (int) ($section['page'] ?? 1)),
            ];
        }
        $items[] = [
            'id' => (string) ($row['id'] ?? ''),
            'title' => (string) ($row['title'] ?? ''),
            'pages' => max(0, (int) ($row['pages'] ?? 0)),
            'sections' => $sections,
        ];
    }
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

if (!isset($byId[$key])) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'That section is not available.']);
    exit;
}

$pages = max(0, (int) ($byId[$key]['pages'] ?? 0));
$n = max(1, (int) ($input['n'] ?? 1));
if ($pages < 1 || $n > $pages) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'That page is not available.']);
    exit;
}

$root = realpath(__DIR__ . '/storage/sop/' . $key);
$file = $root === false ? false : realpath($root . '/p' . sprintf('%03d', $n) . '.png');
$rootPrefix = $root === false ? '' : $root . DIRECTORY_SEPARATOR;
if ($file === false || $rootPrefix === '' || strncmp($file, $rootPrefix, strlen($rootPrefix)) !== 0 || !is_file($file)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'That page is not available.']);
    exit;
}

$bytes = file_get_contents($file);
if ($bytes === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The page could not be loaded.']);
    exit;
}

echo json_encode([
    'ok' => true,
    'n' => $n,
    'm' => $pages,
    'd' => base64_encode($bytes),
]);
