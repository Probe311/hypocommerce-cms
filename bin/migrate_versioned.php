<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/** @var PDO $pdo */
$pdo = pdoFromArgv($argv);
$migrationsDir = dirname(__DIR__) . '/migrations';
$baselineOnly = in_array('--baseline-current', $argv, true);
$lockName = 'backend_schema_migrations_lock';

if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Migrations directory not found: {$migrationsDir}\n");
    exit(1);
}

$files = glob($migrationsDir . '/*.sql');
if (!is_array($files)) {
    fwrite(STDERR, "Unable to list migrations in {$migrationsDir}\n");
    exit(1);
}
sort($files, SORT_STRING);

try {
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(:name, 15)');
    $lockStmt->execute(['name' => $lockName]);
    $lockValue = $lockStmt->fetchColumn();
    if ((string) $lockValue !== '1') {
        throw new RuntimeException('Could not acquire migration lock within 15 seconds.');
    }

    $pdo->beginTransaction();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(64) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $appliedRows = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
    $applied = [];
    foreach ($appliedRows as $version) {
        if (is_string($version)) {
            $applied[$version] = true;
        }
    }

    $insertMigration = $pdo->prepare(
        'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)'
    );
    $appliedCount = 0;
    $skippedCount = 0;

    foreach ($files as $file) {
        $basename = basename($file);
        $version = preg_replace('/\.sql$/', '', $basename) ?: $basename;
        if (isset($applied[$version])) {
            $skippedCount++;
            continue;
        }

        if (!$baselineOnly) {
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException("Unable to read migration file: {$file}");
            }
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                $code = (string) $e->getCode();
                // Ignore duplicate index/table errors to keep migrations idempotent in mixed environments.
                if (!in_array($code, ['42S11', '42000'], true)) {
                    throw $e;
                }
            }
        }

        $insertMigration->execute([
            'version' => $version,
            'applied_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        $applied[$version] = true;
        $appliedCount++;
    }

    $pdo->commit();

    if ($baselineOnly) {
        fwrite(STDOUT, "Baseline complete. New migrations registered: {$appliedCount}, already registered: {$skippedCount}\n");
    } else {
        fwrite(STDOUT, "Migrations applied: {$appliedCount}, already applied: {$skippedCount}\n");
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Versioned migration failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    try {
        $unlockStmt = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $unlockStmt->execute(['name' => $lockName]);
    } catch (Throwable) {
    }
}
