<?php
/**
 * PDO Database Singleton
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host = config('db.host');
            $db   = config('db.database');
            $user = config('db.user');
            $pass = config('db.password');
            $charset = config('db.charset', 'utf8mb4');

            $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            self::$instance = new PDO($dsn, $user, $pass, $options);
            self::$instance->exec("SET NAMES {$charset}");
        }

        return self::$instance;
    }

    private function __construct() {}
    private function __clone() {}
}
