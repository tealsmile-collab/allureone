<?php
/**
 * Append-only file logger for checkout / Razorpay / WhatsApp flows.
 * File: logs/orderCartLog.log
 */
declare(strict_types=1);

final class OrderCartLog
{
    public static function write(string $event, array $context = [], string $level = 'INFO'): void
    {
        try {
            $dir = ROOT_PATH . '/logs';
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            $file = $dir . '/orderCartLog.log';

            $safe = self::redact($context);
            $line = sprintf(
                "[%s] [%s] %s | %s\n",
                date('Y-m-d H:i:s'),
                strtoupper($level),
                $event,
                json_encode($safe, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        } catch (Throwable $e) {
            // Never break checkout because of logging
        }
    }

    public static function info(string $event, array $context = []): void
    {
        self::write($event, $context, 'INFO');
    }

    public static function success(string $event, array $context = []): void
    {
        self::write($event, $context, 'SUCCESS');
    }

    public static function error(string $event, array $context = []): void
    {
        self::write($event, $context, 'ERROR');
    }

    /** Hide secrets from log payloads. */
    private static function redact(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $value) {
            $k = strtolower((string) $key);
            if (str_contains($k, 'secret') || str_contains($k, 'password') || $k === 'apikey' || $k === 'api_key') {
                $out[$key] = '***';
                continue;
            }
            if ($k === 'razorpay_signature' && is_string($value) && strlen($value) > 12) {
                $out[$key] = substr($value, 0, 8) . '…';
                continue;
            }
            $out[$key] = is_array($value) ? self::redact($value) : $value;
        }
        return $out;
    }
}
