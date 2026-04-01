<?php

declare(strict_types=1);

namespace App\Infrastructure\Config;

final class AppConfig
{
    public static function env(string $key, ?string $default = null): ?string
    {
        return $_ENV[$key] ?? $default;
    }

    public static function appUrl(): string
    {
        return self::env('APP_URL', 'http://localhost:8000') ?? 'http://localhost:8000';
    }

    public static function dbDsn(): string
    {
        $host = self::env('DB_HOST', '127.0.0.1');
        $port = self::env('DB_PORT', '3306');
        $db = self::env('DB_NAME', 'ecommerce');
        $socket = self::env('DB_SOCKET');

        if (is_string($socket) && $socket !== '') {
            return sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $db);
        }

        return sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
    }
}
