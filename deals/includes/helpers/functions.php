<?php
/**
 * Common helper functions
 */

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(float|int|string|null $amount): string
{
    $symbol = config('currency_symbol', '₹');
    return $symbol . number_format((float) $amount, 2);
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/i', '-', $text) ?? '';
    return trim($text, '-') ?: 'item';
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

function discount_percent(float $original, float $offer): float
{
    if ($original <= 0 || $offer >= $original) {
        return 0.0;
    }
    return round((($original - $offer) / $original) * 100, 2);
}

function save_amount(float $original, float $offer): float
{
    return max(0, round($original - $offer, 2));
}

function gst_amount(float $taxable, ?float $percent = null): float
{
    $percent ??= (float) config('gst_percent', 18);
    return round(($taxable * $percent) / 100, 2);
}

/** GST component already included in a tax-inclusive amount. */
function gst_from_inclusive(float $inclusive, ?float $percent = null): float
{
    $percent ??= (float) config('gst_percent', 18);
    if ($percent <= 0 || $inclusive <= 0) {
        return 0.0;
    }
    return round(($inclusive * $percent) / (100 + $percent), 2);
}

function cart_token(): string
{
    $key = (string) config('app.cart_session_key', 'allure_cart_token');
    if (empty($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(16));
    }
    return (string) $_SESSION[$key];
}

function format_phone_in(string $phone): string
{
    // Digits only (drops +, spaces, dashes, etc.)
    $digits = preg_replace('/\D+/', '', $phone) ?? '';

    // International prefix 00…
    if (str_starts_with($digits, '00')) {
        $digits = substr($digits, 2);
    }

    // Local Indian format 0XXXXXXXXXX → XXXXXXXXXX
    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }

    // 10-digit mobile → default India country code 91
    if (strlen($digits) === 10) {
        $digits = '91' . $digits;
    }

    return $digits;
}

/**
 * Build WhatsApp / E.164-style digits from country code + local mobile.
 * Local mobile is expected as 10 digits (no + / country code).
 */
function format_phone_with_country(string $mobile, string $countryCode = '91'): string
{
    $local = preg_replace('/\D+/', '', $mobile) ?? '';
    $cc = preg_replace('/\D+/', '', $countryCode) ?? '';
    if ($cc === '') {
        $cc = '91';
    }

    // If a full international number was pasted into mobile, normalize as-is
    if (strlen($local) > 10) {
        return format_phone_in($local);
    }

    if (str_starts_with($local, '0')) {
        $local = ltrim($local, '0');
    }

    if ($local === '') {
        return '';
    }

    // Avoid doubling country code
    if (str_starts_with($local, $cc)) {
        return $local;
    }

    return $cc . $local;
}

function json_input(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if (!$raw) {
        $cached = $_POST ?: [];
        return $cached;
    }
    $data = json_decode($raw, true);
    $cached = is_array($data) ? $data : ($_POST ?: []);
    return $cached;
}

function upload_path(string $subdir = ''): string
{
    $base = ROOT_PATH . '/uploads';
    $path = $subdir ? $base . '/' . trim($subdir, '/') : $base;
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    return $path;
}

function asset_url(string $path): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return base_url(ltrim($path, '/'));
}

function logo_url(): string
{
    $logo = ltrim((string) config('logo'), '/');
    if ($logo === '' || !is_file(ROOT_PATH . '/' . $logo)) {
        $logo = 'assets/img/logo1.png';
    }
    return asset_url($logo);
}

/** Opaque URL-safe token for admin invoice links (hides plain order id). */
function invoice_ref_encode(int $orderId): string
{
    if ($orderId <= 0) {
        return '';
    }
    return rtrim(strtr(base64_encode((string) $orderId), '+/', '-_'), '=');
}

/** Decode invoice token; also accepts legacy plain numeric ids. */
function invoice_ref_decode(string $token): int
{
    $token = trim(rawurldecode($token));
    if ($token === '') {
        return 0;
    }
    if (ctype_digit($token)) {
        return (int) $token;
    }
    $b64 = strtr($token, '-_', '+/');
    $pad = strlen($b64) % 4;
    if ($pad > 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $raw = base64_decode($b64, true);
    if (!is_string($raw) || !ctype_digit($raw)) {
        return 0;
    }
    return (int) $raw;
}

/**
 * Gallabox settings from deployable includes/config/gallabox.php (overrides config.php).
 */
function gallabox_config(?string $key = null, mixed $default = null): mixed
{
    static $gb = null;
    if ($gb === null) {
        $file = ROOT_PATH . '/includes/config/gallabox.php';
        $gb = is_file($file) ? (require $file) : [];
        if (!is_array($gb)) {
            $gb = [];
        }
        // Fallback to config.php keys if file missing values
        foreach (['api_url', 'api_key', 'api_secret', 'channel_id', 'template', 'buyer_template', 'default_phone', 'default_name'] as $k) {
            if (!isset($gb[$k]) || $gb[$k] === '' || $gb[$k] === null) {
                $gb[$k] = config('gallabox.' . $k);
            }
        }
    }
    if ($key === null) {
        return $gb;
    }
    return $gb[$key] ?? $default;
}

function is_ajax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function view(string $file, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $path = ROOT_PATH . '/' . ltrim($file, '/');
    if (!is_file($path)) {
        http_response_code(500);
        echo 'View not found: ' . e($file);
        exit;
    }
    require $path;
}

function activity_log(string $action, string $entity = '', ?int $entityId = null, array $meta = []): void
{
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            'INSERT INTO alluredeal_activity_logs
            (user_id, action, entity, entity_id, meta_json, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $userId = Auth::id();
        $stmt->execute([
            $userId,
            $action,
            $entity,
            $entityId,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        // non-blocking
    }
}
