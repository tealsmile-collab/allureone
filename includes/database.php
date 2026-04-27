<?php
declare(strict_types=1);

/**
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/../config.php';
    $c = $config['db'];
    $pdo = allureone_connect_pdo_with_charset_fallback($c);

    return $pdo;
}

/**
 * @return PDO
 */
function wp_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = require __DIR__ . '/../config.php';
    $c = $config['wordpress_db'];
    $pdo = allureone_connect_pdo_with_charset_fallback($c);

    return $pdo;
}

/**
 * @param array<string, mixed> $c
 */
function allureone_connect_pdo_with_charset_fallback(array $c): PDO
{
    $charset = trim((string) ($c['charset'] ?? 'utf8mb4'));
    if ($charset === '') {
        $charset = 'utf8mb4';
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        (string) ($c['host'] ?? ''),
        (string) ($c['database'] ?? ''),
        $charset
    );

    try {
        return new PDO($dsn, (string) ($c['user'] ?? ''), (string) ($c['password'] ?? ''), $options);
    } catch (PDOException $e) {
        $msg = strtolower($e->getMessage());
        $isUnknownCharset = ((int) $e->getCode() === 2019) || str_contains($msg, 'unknown character set');
        if (!$isUnknownCharset || strtolower($charset) === 'utf8') {
            throw $e;
        }

        $fallbackDsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            (string) ($c['host'] ?? ''),
            (string) ($c['database'] ?? ''),
            'utf8'
        );
        error_log('AllureOne DB charset fallback: retrying PDO with utf8 after unknown charset for configured charset=' . $charset);

        return new PDO($fallbackDsn, (string) ($c['user'] ?? ''), (string) ($c['password'] ?? ''), $options);
    }
}

function wp_table_prefix(): string
{
    $config = require __DIR__ . '/../config.php';
    $prefix = trim((string) (($config['wordpress_db']['prefix'] ?? 'wp_')));

    return $prefix !== '' ? $prefix : 'wp_';
}
