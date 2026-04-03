<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * Découpe un fichier .sql en instructions (séparateur ; en fin de ligne).
 *
 * @return list<string>
 */
function migrationSqlStatements(string $sql): array
{
    $sql = trim($sql);
    if ($sql === '') {
        return [];
    }
    $chunks = preg_split('/;\s*\R/u', $sql);
    if (!is_array($chunks)) {
        return [];
    }
    $out = [];
    foreach ($chunks as $chunk) {
        $chunk = trim((string) $chunk);
        if ($chunk === '' || str_starts_with($chunk, '--')) {
            continue;
        }
        $out[] = $chunk;
    }
    return $out;
}

function isIgnorableMigrationError(Throwable $e): bool
{
    $code = (string) $e->getCode();
    $message = strtolower($e->getMessage());
    if ($code === '42S11') {
        return true;
    }
    if ($code === '42S21') {
        return true;
    }
    if (str_contains($message, 'duplicate column name')) {
        return true;
    }
    if (str_contains($message, 'duplicate key name')) {
        return true;
    }
    if (str_contains($message, 'duplicate foreign key')) {
        return true;
    }
    if ($code === '42000' && str_contains($message, 'already exists')) {
        return true;
    }
    if (str_contains($message, 'already exists') && str_contains($message, 'foreign key constraint')) {
        return true;
    }
    if (str_contains($message, 'duplicate foreign key constraint name')) {
        return true;
    }
    if (str_contains($message, 'errno: 121') || str_contains($message, '(errno: 121')) {
        return true;
    }
    return false;
}

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
            $statements = migrationSqlStatements($sql);
            foreach ($statements as $stmt) {
                try {
                    $pdo->exec($stmt . ';');
                } catch (Throwable $e) {
                    if (!isIgnorableMigrationError($e)) {
                        throw $e;
                    }
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

    if ($baselineOnly) {
        fwrite(STDOUT, "Baseline complete. New migrations registered: {$appliedCount}, already registered: {$skippedCount}\n");
    } else {
        fwrite(STDOUT, "Migrations applied: {$appliedCount}, already applied: {$skippedCount}\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Versioned migration failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    try {
        $unlockStmt = $pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $unlockStmt->execute(['name' => $lockName]);
    } catch (Throwable) {
    }
}
