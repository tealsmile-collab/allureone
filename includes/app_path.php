<?php
declare(strict_types=1);

/**
 * Application URL path prefix (empty string when app is at domain root).
 * e.g. "" for https://one.example.com/ or "/allure_one" for a subfolder install.
 */
function allureone_base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $config = @include __DIR__ . '/../config.php';
    if (is_array($config) && isset($config['app']['base_path'])) {
        $configured = trim((string) $config['app']['base_path']);
        if ($configured !== '') {
            $base = '/' . trim($configured, '/');

            return $base;
        }
    }

    $docRoot = str_replace('\\', '/', (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $appRoot = str_replace('\\', '/', dirname(__DIR__));
    if ($docRoot !== '' && strncmp($appRoot, $docRoot, strlen($docRoot)) === 0) {
        $base = substr($appRoot, strlen($docRoot));
        $base = rtrim($base, '/');

        return $base;
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = dirname($script);
    $base = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');

    return $base;
}

function allureone_scope(): string
{
    $base = allureone_base_path();

    return $base === '' ? '/' : $base . '/';
}

function allureone_url(string $path = ''): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $base = allureone_base_path();
    if ($base === '') {
        return '/' . $path;
    }

    return $base . '/' . $path;
}

function allureone_base_href(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

    return ($https ? 'https' : 'http') . '://' . $host . allureone_scope();
}

function allureone_redirect(string $path): void
{
    header('Location: ' . allureone_url($path), true, 302);
    exit;
}
