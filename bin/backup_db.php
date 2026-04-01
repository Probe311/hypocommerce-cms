<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

loadBackendEnv();

$host = (string) ($_ENV['DB_HOST'] ?? '');
$port = (string) ($_ENV['DB_PORT'] ?? '3306');
$db = (string) ($_ENV['DB_NAME'] ?? '');
$user = (string) ($_ENV['DB_USER'] ?? '');
$pass = (string) ($_ENV['DB_PASSWORD'] ?? '');

if ($host === '' || $db === '' || $user === '') {
    fwrite(STDERR, "Missing DB env config for backup.\n");
    exit(1);
}

$root = dirname(__DIR__);
$dir = $root . '/var/backups';
if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
}
$target = $dir . '/db-backup-' . date('Ymd-His') . '.sql';

$cmd = sprintf(
    'mysqldump --host=%s --port=%s --user=%s --password=%s %s > %s',
    escapeshellarg($host),
    escapeshellarg($port),
    escapeshellarg($user),
    escapeshellarg($pass),
    escapeshellarg($db),
    escapeshellarg($target)
);

passthru($cmd, $code);
if ($code !== 0) {
    fwrite(STDERR, "Backup failed.\n");
    exit(1);
}

fwrite(STDOUT, "Backup created: {$target}\n");
