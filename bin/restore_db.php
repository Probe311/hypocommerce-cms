<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (($argv[1] ?? null) === null) {
    fwrite(STDERR, "Usage: php bin/restore_db.php <backup.sql>\n");
    exit(1);
}

$source = (string) $argv[1];
if (!is_file($source)) {
    fwrite(STDERR, "Backup file not found: {$source}\n");
    exit(1);
}

loadBackendEnv();

$host = (string) ($_ENV['DB_HOST'] ?? '');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$db = (string) ($_ENV['DB_NAME'] ?? '');
$user = (string) ($_ENV['DB_USER'] ?? '');
$pass = (string) ($_ENV['DB_PASSWORD'] ?? '');

if ($host === '' || $db === '' || $user === '') {
    fwrite(STDERR, "Missing DB env config for restore.\n");
    exit(1);
}

$cmd = sprintf(
    'mysql --host=%s --port=%s --user=%s --password=%s %s < %s',
    escapeshellarg($host),
    escapeshellarg($port),
    escapeshellarg($user),
    escapeshellarg($pass),
    escapeshellarg($db),
    escapeshellarg($source)
);

passthru($cmd, $code);
if ($code !== 0) {
    fwrite(STDERR, "Restore failed.\n");
    exit(1);
}

fwrite(STDOUT, "Restore completed from {$source}\n");
