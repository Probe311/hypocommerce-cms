<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/apply_remote_schema.php <host> <db> <user> <password> [--reset]\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];
$reset = in_array('--reset', $argv, true);

loadBackendEnv();
$appEnv = strtolower((string) ($_ENV['APP_ENV'] ?? 'dev'));
if ($reset && in_array($appEnv, ['prod', 'production'], true)) {
    fwrite(STDERR, "Refus: --reset interdit en environnement production.\n");
    exit(1);
}

$schemaPath = dirname(__DIR__) . '/config/schema.sql';
$sql = file_get_contents($schemaPath);
if ($sql === false) {
    fwrite(STDERR, "Unable to read schema file: {$schemaPath}\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ($reset) {
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $pdo->exec(sprintf('DROP TABLE IF EXISTS `%s`', str_replace('`', '``', (string) $table)));
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    $pdo->exec($sql);
    fwrite(STDOUT, "Schema applied successfully.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Schema apply failed: ' . $e->getMessage() . "\n");
    exit(1);
}
