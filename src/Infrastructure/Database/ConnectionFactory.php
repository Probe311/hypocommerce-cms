<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use Dotenv\Dotenv;
use PDO;
use RuntimeException;

final class ConnectionFactory
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        // __DIR__ = src/Infrastructure/Database => remonter a la racine backend/
        $envPath = dirname(__DIR__, 3) . '/.env';
        if (is_file($envPath)) {
            if (class_exists(Dotenv::class)) {
                $dotenv = Dotenv::createImmutable(dirname($envPath));
                $dotenv->safeLoad();
            } else {
                foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    if (!is_string($line)) {
                        continue;
                    }
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    $_ENV[trim($k)] = trim($v);
                }
            }
        }

        $host = self::env('DB_HOST');
        $port = self::env('DB_PORT', '3306');
        $db = self::env('DB_NAME');
        $user = self::env('DB_USER');
        $password = self::env('DB_PASSWORD');
        $socket = self::env('DB_SOCKET');

        if ($host === null || $db === null || $user === null) {
            throw new RuntimeException('Database configuration is missing');
        }

        if ($socket !== null && $socket !== '') {
            $dsn = sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $db);
        } else {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => self::envInt('DB_CONNECT_TIMEOUT', 5),
        ];

        if (self::envBool('DB_SSL_VERIFY', false)) {
            $sslCa = self::env('DB_SSL_CA');
            if ($sslCa === null || $sslCa === '') {
                throw new RuntimeException('DB_SSL_VERIFY is enabled but DB_SSL_CA is missing');
            }
            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        $pdo = new PDO($dsn, $user, $password, $options);

        self::$connection = $pdo;

        return $pdo;
    }

    public static function createNewConnection(): PDO
    {
        self::$connection = null;

        return self::getConnection();
    }

    private static function env(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $default;
        if (!is_string($value)) {
            return $default;
        }

        return trim($value);
    }

    private static function envInt(string $key, int $default): int
    {
        $raw = self::env($key);
        if ($raw === null || $raw === '') {
            return $default;
        }
        if (!is_numeric($raw)) {
            return $default;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : $default;
    }

    private static function envBool(string $key, bool $default): bool
    {
        $raw = self::env($key);
        if ($raw === null || $raw === '') {
            return $default;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }
}
