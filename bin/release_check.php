<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$phpBinary = PHP_BINARY;
$commands = [
    ['-l', 'bin/migrate.php'],
    ['-l', 'bin/migrate_versioned.php'],
    ['-l', 'bin/import_seo_products.php'],
    ['-l', 'bin/import_seo_pages.php'],
    ['-l', 'bin/backfill_cms_content.php'],
    ['-l', 'bin/preflight_prod.php'],
    ['-l', 'bin/smoke_test.php'],
    ['-l', 'bin/integration_api_test.php'],
    ['-l', 'bin/check_slug_canonical.php'],
    ['-l', 'bin/check_db_indexes.php'],
    ['-l', 'bin/reconcile_refunds.php'],
];

$failures = 0;

foreach ($commands as $args) {
    $display = 'php ' . implode(' ', $args);
    $fullCommand = '"' . $phpBinary . '" ' . implode(' ', array_map('escapeshellarg', $args));
    fwrite(STDOUT, ">> {$display}\n");
    passthru($fullCommand, $exitCode);
    if ($exitCode !== 0) {
        $failures++;
    }
}

fwrite(STDOUT, ">> php bin/preflight_prod.php\n");
passthru('"' . $phpBinary . '" bin/preflight_prod.php', $preflightCode);
if ($preflightCode !== 0) {
    $failures++;
}

fwrite(STDOUT, ">> php bin/smoke_test.php --without-db\n");
passthru('"' . $phpBinary . '" bin/smoke_test.php --without-db', $smokeCode);
if ($smokeCode !== 0) {
    $failures++;
}

fwrite(STDOUT, ">> php bin/integration_api_test.php\n");
passthru('"' . $phpBinary . '" bin/integration_api_test.php', $integrationCode);
if ($integrationCode !== 0) {
    $failures++;
}

fwrite(STDOUT, ">> php bin/check_slug_canonical.php\n");
passthru('"' . $phpBinary . '" bin/check_slug_canonical.php', $slugCode);
if ($slugCode !== 0) {
    $failures++;
}

fwrite(STDOUT, ">> php bin/check_db_indexes.php\n");
passthru('"' . $phpBinary . '" bin/check_db_indexes.php', $indexCode);
if ($indexCode !== 0) {
    $failures++;
}

fwrite(STDOUT, ">> php bin/reconcile_refunds.php\n");
passthru('"' . $phpBinary . '" bin/reconcile_refunds.php', $reconcileCode);
if ($reconcileCode !== 0) {
    $failures++;
}

if ($failures > 0) {
    fwrite(STDERR, "Release check failed ({$failures} step(s)).\n");
    exit(1);
}

fwrite(STDOUT, "Release check passed.\n");
