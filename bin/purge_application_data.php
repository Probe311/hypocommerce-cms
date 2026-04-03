<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * **DANGER** : supprime les données applicatives (catalogue, commandes, clients, CMS, blog, FAQ, médias, EEAT scores…).
 * Conserve la structure des tables et **schema_migrations**.
 *
 * Garde-fous :
 *   - Variable d'environnement ALLOW_DESTRUCTIVE_PURGE=1 obligatoire (sauf --dry-run).
 *   - Par défaut préserve comptes admin, réglages, plugins, SEO global, webhooks, idempotency.
 *
 * Usage:
 *   ALLOW_DESTRUCTIVE_PURGE=1 php backend/bin/purge_application_data.php [--dry-run] [host db user password]
 *   ALLOW_DESTRUCTIVE_PURGE=1 php backend/bin/purge_application_data.php --include-admin-config ...
 *   ALLOW_DESTRUCTIVE_PURGE=1 php backend/bin/purge_application_data.php --include-ops ...
 *
 * Windows PowerShell:
 *   $env:ALLOW_DESTRUCTIVE_PURGE=1; php backend/bin/purge_application_data.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv, true);
$includeAdminConfig = in_array('--include-admin-config', $argv, true);
$includeOps = in_array('--include-ops', $argv, true);

loadBackendEnv();
$allow = trim((string) ($_ENV['ALLOW_DESTRUCTIVE_PURGE'] ?? getenv('ALLOW_DESTRUCTIVE_PURGE') ?: ''));
if (!$dryRun && $allow !== '1') {
    fwrite(
        STDERR,
        "REFUS: définissez ALLOW_DESTRUCTIVE_PURGE=1 pour exécuter une purge réelle (ou utilisez --dry-run).\n" .
        "Sauvegardez la base avant toute opération destructive.\n"
    );
    exit(1);
}

fwrite(STDOUT, "** PURGE IRREVERSIBLE ** — schema_migrations est préservé. Sauvegarde recommandée.\n\n");

/** @var list<string> $alwaysPreserve */
$alwaysPreserve = ['schema_migrations'];

/** @var list<string> $preserveIfKeepAdmin */
$preserveIfKeepAdmin = [
    'admin_users',
    'settings',
    'admin_plugins',
    'plugin_categories',
    'admin_tax_rules',
    'admin_shipping_carriers',
    'admin_payment_methods',
    'webhook_events',
    'idempotency_keys',
    'seo_site_settings',
    'seo_social_settings',
    'seo_schema_settings',
    'seo_indexation_rules',
    'seo_eeat_defaults',
    'audit_logs',
];

if ($includeOps) {
    $preserveIfKeepAdmin = array_values(array_filter(
        $preserveIfKeepAdmin,
        static fn (string $t): bool => !in_array($t, ['webhook_events', 'idempotency_keys'], true)
    ));
}

if ($includeAdminConfig) {
    $preserveIfKeepAdmin = array_values(array_filter(
        $preserveIfKeepAdmin,
        static fn (string $t): bool => !in_array($t, [
            'admin_users',
            'settings',
            'admin_plugins',
            'plugin_categories',
            'admin_tax_rules',
            'admin_shipping_carriers',
            'admin_payment_methods',
            'audit_logs',
        ], true)
    ));
}

$exclude = array_merge($alwaysPreserve, $preserveIfKeepAdmin);
$exclude = array_values(array_unique($exclude));

$pdoArgv = [$argv[0] ?? 'purge_application_data.php'];
foreach (array_slice($argv, 1) as $arg) {
    if (!is_string($arg) || $arg === '') {
        continue;
    }
    if ($arg === '--dry-run' || $arg === '--include-admin-config' || $arg === '--include-ops') {
        continue;
    }
    if (str_starts_with($arg, '--')) {
        continue;
    }
    $pdoArgv[] = $arg;
}

$pdo = pdoFromArgv($pdoArgv);
$dbName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT TABLE_NAME AS t
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = :type
     ORDER BY TABLE_NAME'
);
$stmt->execute(['db' => $dbName, 'type' => 'BASE TABLE']);
$allTables = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!is_array($row)) {
        continue;
    }
    $t = (string) ($row['t'] ?? '');
    if ($t !== '') {
        $allTables[] = $t;
    }
}

$toTruncate = array_values(array_filter($allTables, static function (string $t) use ($exclude): bool {
    return !in_array($t, $exclude, true);
}));

fwrite(STDOUT, "Base: {$dbName}\n");
fwrite(STDOUT, 'Tables à vider: ' . count($toTruncate) . " (exclues: " . count($exclude) . ")\n");
fwrite(STDOUT, 'Exclues: ' . implode(', ', $exclude) . "\n\n");

if ($dryRun) {
    foreach ($toTruncate as $table) {
        try {
            $c = (int) $pdo->query('SELECT COUNT(*) FROM `' . str_replace('`', '``', $table) . '`')->fetchColumn();
            fwrite(STDOUT, sprintf("%s\t%d\n", $table, $c));
        } catch (Throwable $e) {
            fwrite(STDOUT, sprintf("%s\t(error: %s)\n", $table, $e->getMessage()));
        }
    }
    fwrite(STDOUT, "\nDry-run terminé. Aucune écriture.\n");
    exit(0);
}

$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($toTruncate as $table) {
    $safe = str_replace('`', '``', $table);
    try {
        $pdo->exec('TRUNCATE TABLE `' . $safe . '`');
        fwrite(STDOUT, "TRUNCATE {$table}\n");
    } catch (Throwable $e) {
        try {
            $pdo->exec('DELETE FROM `' . $safe . '`');
            fwrite(STDOUT, "DELETE FROM {$table} (fallback)\n");
        } catch (Throwable $e2) {
            fwrite(STDERR, "Échec {$table}: " . $e2->getMessage() . "\n");
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            exit(1);
        }
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');

fwrite(STDOUT, "\nPurge terminée.\n");
