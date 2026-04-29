<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

// Keep PHP error logs in the project for easier app-level debugging.
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_errors.log');

if (session_status() === PHP_SESSION_NONE) {
    $sessionLifetime = 30 * 24 * 60 * 60; // 30 days
    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    session_name($config['app']['session_name']);
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/dingg.php';
