<?php
/**
 * /policy/ router — delegates to root policy.php
 */
declare(strict_types=1);

$slug = trim((string) ($_GET['slug'] ?? $_GET['policy'] ?? ''));
if ($slug === '' && !empty($_SERVER['REQUEST_URI'])) {
    $path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '';
    if (preg_match('#/policy/([a-z0-9\-]+)/?#i', $path, $m)) {
        $slug = strtolower($m[1]);
    }
}
$_GET['slug'] = $slug;
require dirname(__DIR__) . '/policy.php';
