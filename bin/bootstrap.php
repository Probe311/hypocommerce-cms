<?php

declare(strict_types=1);

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

function loadBackendEnv(): void
{
    $root = dirname(__DIR__);
    $envPath = $root . '/.env';

    if (is_file($envPath)) {
        if (class_exists(\Dotenv\Dotenv::class)) {
            $dotenv = \Dotenv\Dotenv::createImmutable($root);
            $dotenv->safeLoad();
            return;
        }

        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (!is_string($line)) {
                continue;
            }
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
        }
    }
}

function dbConfigFromArgv(array $argv): array
{
    loadBackendEnv();

    return [
        'host' => $argv[1] ?? ($_ENV['DB_HOST'] ?? null),
        'db' => $argv[2] ?? ($_ENV['DB_NAME'] ?? null),
        'user' => $argv[3] ?? ($_ENV['DB_USER'] ?? null),
        'password' => $argv[4] ?? ($_ENV['DB_PASSWORD'] ?? null),
    ];
}

function ensureDbConfig(array $config): void
{
    $host = (string) ($config['host'] ?? '');
    $db = (string) ($config['db'] ?? '');
    $user = (string) ($config['user'] ?? '');
    if ($host === '' || $db === '' || $user === '') {
        throw new \RuntimeException('Missing DB configuration. Provide argv args or backend/.env');
    }
}

function pdoFromArgv(array $argv): \PDO
{
    $config = dbConfigFromArgv($argv);
    ensureDbConfig($config);

    $_ENV['DB_HOST'] = (string) $config['host'];
    $_ENV['DB_NAME'] = (string) $config['db'];
    $_ENV['DB_USER'] = (string) $config['user'];
    $_ENV['DB_PASSWORD'] = (string) ($config['password'] ?? '');

    return \App\Infrastructure\Database\ConnectionFactory::createNewConnection();
}
