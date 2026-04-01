<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    $pdo = pdoFromArgv($argv);
    $row = $pdo->query('SELECT DATABASE() AS db_name, VERSION() AS mysql_version, NOW() AS now_ts')->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('No health row returned.');
    }
    fwrite(STDOUT, "DB connection OK\n");
    fwrite(STDOUT, 'database=' . (string) ($row['db_name'] ?? 'unknown') . "\n");
    fwrite(STDOUT, 'mysql_version=' . (string) ($row['mysql_version'] ?? 'unknown') . "\n");
    fwrite(STDOUT, 'timestamp=' . (string) ($row['now_ts'] ?? 'unknown') . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}
