<?php
/**
 * Installer — imports database/schema.sql once.
 * Web: /install.php?key=allure-install-once
 * CLI: php install.php allure-install-once
 * DELETE this file after success.
 */
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$key = $_GET['key'] ?? ($argv[1] ?? '');
if ($key !== 'allure-install-once') {
    http_response_code(403);
    echo "Forbidden. Use ?key=allure-install-once (or CLI: php install.php allure-install-once)\n";
    exit;
}

$sqlFile = ROOT_PATH . '/database/schema.sql';
if (!is_file($sqlFile)) {
    echo "schema.sql missing\n";
    exit;
}

$host = (string) config('db.host');
$user = (string) config('db.user');
$pass = (string) config('db.password');
$name = (string) config('db.database');

$mysqli = @new mysqli($host, $user, $pass, $name);
if ($mysqli->connect_errno) {
    echo 'Connection failed: ' . $mysqli->connect_error . "\n";
    exit(1);
}
$mysqli->set_charset('utf8mb4');

$sql = (string) file_get_contents($sqlFile);
if (!$mysqli->multi_query($sql)) {
    echo 'Import failed: ' . $mysqli->error . "\n";
    exit(1);
}

$ok = 0;
$fail = 0;
do {
    if ($result = $mysqli->store_result()) {
        $result->free();
    }
    if ($mysqli->errno) {
        $fail++;
        echo 'WARN: ' . $mysqli->error . "\n";
    } else {
        $ok++;
    }
} while ($mysqli->more_results() && $mysqli->next_result());

$tables = [];
$res = $mysqli->query("SHOW TABLES LIKE 'alluredeal_%'");
if ($res) {
    while ($row = $res->fetch_row()) {
        $tables[] = $row[0];
    }
    $res->free();
}

echo "Executed result sets: {$ok}, warnings: {$fail}\n";
echo 'alluredeal_ tables found: ' . count($tables) . "\n";
foreach ($tables as $t) {
    echo " - {$t}\n";
}
echo "\nDelete install.php after verifying.\n";
$mysqli->close();
