<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$schemaFile = dirname(__DIR__) . '/config/schema.sql';

if (!is_file($schemaFile)) {
    fwrite(STDERR, "Schema file not found: {$schemaFile}\n");
    exit(1);
}

$sql = file_get_contents($schemaFile);

if ($sql === false) {
    fwrite(STDERR, "Unable to read schema file\n");
    exit(1);
}

try {
    loadBackendEnv();
    $args = $argv;
    $createDb = in_array('--create-db', $args, true);
    $databaseName = (string) ($_ENV['DB_NAME'] ?? '');
    if ($createDb && $databaseName !== '') {
        $host = (string) ($_ENV['DB_HOST'] ?? '127.0.0.1');
        $port = (string) ($_ENV['DB_PORT'] ?? '3306');
        $user = (string) ($_ENV['DB_USER'] ?? '');
        $password = (string) ($_ENV['DB_PASSWORD'] ?? '');
        $adminDsn = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $host, $port);
        $adminPdo = new PDO($adminDsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $escapedDb = str_replace('`', '``', $databaseName);
        $adminPdo->exec("CREATE DATABASE IF NOT EXISTS `{$escapedDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        fwrite(STDOUT, "Database ensured: {$databaseName}\n");
    }

    $pdo = \App\Infrastructure\Database\ConnectionFactory::getConnection();
    $pdo->exec($sql);
    fwrite(STDOUT, "Database schema applied successfully.\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Error applying schema: " . $e->getMessage() . "\n");
    exit(1);
}

