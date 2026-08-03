<?php
/**
 * Security helpers — CSRF, XSS, sanitization
 */

declare(strict_types=1);

class Security
{
    public static function csrfToken(): string
    {
        $name = (string) config('app.csrf_token_name', 'allure_csrf');
        if (empty($_SESSION[$name])) {
            $_SESSION[$name] = bin2hex(random_bytes(32));
        }
        return (string) $_SESSION[$name];
    }

    public static function csrfField(): string
    {
        $token = self::csrfToken();
        return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
    }

    public static function verifyCsrf(?string $token = null): bool
    {
        $name = (string) config('app.csrf_token_name', 'allure_csrf');
        $session = $_SESSION[$name] ?? '';
        if ($token === null) {
            $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            // JSON AJAX bodies put csrf_token in php://input (not $_POST)
            if ($token === '') {
                $json = json_input();
                $token = (string) ($json['csrf_token'] ?? '');
            }
        }
        if ($session === '' || $token === '') {
            return false;
        }
        return hash_equals((string) $session, (string) $token);
    }

    public static function requireCsrf(): void
    {
        if (!self::verifyCsrf()) {
            Response::error('Invalid CSRF token', 419);
        }
    }

    public static function clean(string $value): string
    {
        return trim(strip_tags($value));
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : $default;
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}
