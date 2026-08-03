<?php
/**
 * Allure Thai Spa Deals - Global Configuration
 * Hostinger / PHP 8.3+ ready
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

date_default_timezone_set('Asia/Kolkata');

define('ROOT_PATH', __DIR__);
define('APP_ENV', 'production'); // development | production

$config = [
    // Site
    'site_name'       => 'Allure Thai Spa Deals',
    'company_name'    => 'Allure Thai Spa & Wellness',
    'site_url'        => 'https://deals.allurethaispa.in', // update on Hostinger
    'logo'            => '/assets/img/allure-thai.png',
    'favicon'         => '/assets/img/favicon.ico',
    'currency'        => 'INR',
    'currency_symbol' => '₹',
    'gst_percent'     => 18.0,
    'support_email'   => 'feedback.allurespa@gmail.com',
    'support_phone'   => '7620049769',
    'admin_roles'     => ['admin', 'superadmin'],

    // Database (Hostinger)
    'db' => [
        'host'     => '82.25.121.179',
        'user'     => 'u716393246_allureproadmin',
        'password' => 'YOUR_DB_PASSWORD',
        'database' => 'u716393246_AllurePro',
        'charset'  => 'utf8mb4',
    ],

    // Razorpay (India) — Key ID = user, Key Secret = pass (HTTP Basic Auth)
    'razorpay' => [
        'key_id'      => 'rzp_live_XXXXXXXX',
        'key_secret'  => 'YOUR_RAZORPAY_SECRET',
        'currency'    => 'INR',
        'company_name'=> 'AELLURE ENTERTAINMENT AND WELLNESS',
    ],

    // Gallabox WhatsApp
    'gallabox' => [
        'api_url'    => 'https://server.gallabox.com/devapi/messages/whatsapp',
        'api_key'    => 'YOUR_GALLABOX_API_KEY',
        'api_secret' => 'YOUR_GALLABOX_API_SECRET',
        'channel_id' => 'YOUR_GALLABOX_CHANNEL_ID',
        'template'   => 'meta_lead',
        'buyer_template' => 'allure_deal_confirmation',
        'default_phone' => '918369676845',
        'default_name'  => 'Shailesh',
    ],

    // Branch WhatsApp phones (override per branch name)
    'branch_phones' => [
        'Andheri East' => '918010545836',
        'Bandra'       => '918975155984',
        'Kharghar'     => '918424925346',
        'Bhandup'      => '918080515738',
        'Palghar'      => '917875588844',
        'Franchise'    => '918369676845',
    ],

    // Offers / marketing
    'offers' => [
        'free_shipping_min' => 0,
        'show_countdown'    => true,
        'deal_badge_text'   => 'Limited Time Deal',
    ],

    // App settings
    'app' => [
        'items_per_page'   => 12,
        'csrf_token_name'  => 'allure_csrf',
        'cart_session_key' => 'allure_cart_token',
        'upload_max_mb'    => 5,
        'allowed_images'   => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
    ],
];

// Autoload core
require_once ROOT_PATH . '/includes/helpers/functions.php';
require_once ROOT_PATH . '/config/Database.php';
require_once ROOT_PATH . '/includes/helpers/Auth.php';
require_once ROOT_PATH . '/includes/helpers/Security.php';
require_once ROOT_PATH . '/includes/helpers/Response.php';
require_once ROOT_PATH . '/includes/helpers/OrderCartLog.php';

/**
 * Get config value by dot notation: config('db.host')
 */
function config(string $key, mixed $default = null): mixed
{
    global $config;
    $keys = explode('.', $key);
    $value = $config;
    foreach ($keys as $k) {
        if (!is_array($value) || !array_key_exists($k, $value)) {
            return $default;
        }
        $value = $value[$k];
    }
    return $value;
}

/**
 * Resolve app root directory from SCRIPT_NAME (strips admin/ajax/policy/slug folders).
 */
function app_root_dir(): string
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $dir = str_replace('\\', '/', dirname($script));
    // /policy/privacy-policy, /admin, /ajax, etc. → app root
    if (preg_match('#^(.+)/(?:admin|ajax|api|cart|checkout|product|policy)(?:/.*)?$#', $dir, $m)) {
        $dir = $m[1];
    } elseif (preg_match('#^/(?:admin|ajax|api|cart|checkout|product|policy)(?:/.*)?$#', $dir)) {
        $dir = '';
    }
    if ($dir === '/' || $dir === '.' || $dir === '\\') {
        $dir = '';
    }
    return $dir;
}

/**
 * Base URL helper — prefers current request host so AJAX works on any domain/subdir
 */
function base_url(string $path = ''): string
{
    $configured = rtrim((string) config('site_url'), '/');
    $base = $configured;

    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $scheme = strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' ? 'https' : 'http';
        }
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'] . app_root_dir();
    }

    $path = ltrim($path, '/');
    return $path === '' ? $base : $base . '/' . $path;
}

/** Root-relative app path (best for AJAX/assets) */
function app_path(string $path = ''): string
{
    $dir = app_root_dir();
    $path = ltrim($path, '/');
    return $path === '' ? ($dir === '' ? '/' : $dir) : $dir . '/' . $path;
}
