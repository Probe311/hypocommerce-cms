<?php

declare(strict_types=1);

/**
 * Met à jour les colonnes enrichissement (source fournisseur, image HD, EAN, devise)
 * depuis le JSON master, par SKU.
 *
 * Usage:
 *   php backend/bin/backfill_products_from_master.php [--master=path] [--dry-run]
 */

require __DIR__ . '/bootstrap.php';

$repoRoot = dirname(__DIR__, 2);
$defaultMaster = $repoRoot . '/seo-suppliers/donnees/produits/master-eeat.curated.json';
$masterPath = $defaultMaster;
$dryRun = in_array('--dry-run', $argv, true);

foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--master=')) {
        $masterPath = substr((string) $arg, strlen('--master='));
    }
}

if (!is_file($masterPath)) {
    fwrite(STDERR, "Master introuvable: {$masterPath}\n");
    exit(1);
}

$raw = file_get_contents($masterPath);
if ($raw === false) {
    fwrite(STDERR, "Lecture master impossible.\n");
    exit(1);
}

/** @var mixed $items */
$items = json_decode($raw, true);
if (!is_array($items)) {
    fwrite(STDERR, "JSON master invalide.\n");
    exit(1);
}

$pdo = \App\Infrastructure\Database\ConnectionFactory::getConnection();

$stmt = $pdo->prepare(
    'UPDATE products SET
        source_url = :source_url,
        supplier_image_url = :supplier_image_url,
        supplier_reference = :supplier_reference,
        currency = :currency,
        gtin = :gtin,
        updated_at = NOW()
     WHERE sku = :sku'
);

$stats = ['rows_master' => count($items), 'updated' => 0, 'skipped_no_sku' => 0, 'not_found' => 0];

$findSku = $pdo->prepare('SELECT id FROM products WHERE sku = :sku LIMIT 1');

foreach ($items as $row) {
    if (!is_array($row)) {
        continue;
    }
    $sku = trim((string) ($row['sku'] ?? ''));
    if ($sku === '') {
        $sku = trim((string) ($row['reference'] ?? ''));
    }
    if ($sku === '') {
        $stats['skipped_no_sku']++;
        continue;
    }

    $findSku->execute(['sku' => $sku]);
    if ($findSku->fetchColumn() === false) {
        $stats['not_found']++;
        continue;
    }

    $sourceUrl = trim((string) ($row['source_url'] ?? ''));
    $imgHd = trim((string) ($row['image_url_hd'] ?? ''));
    $ref = trim((string) ($row['reference'] ?? ''));
    $skuRef = trim((string) ($row['sku'] ?? ''));
    $supplierRef = $ref !== '' ? $ref : $skuRef;

    $ean = trim((string) ($row['ean'] ?? ''));
    $gtin = $ean !== '' ? substr($ean, 0, 32) : null;

    $cur = strtoupper(trim((string) ($row['currency'] ?? '')));
    if ($cur === '' || strlen($cur) !== 3) {
        $cur = 'EUR';
    }

    $params = [
        'sku' => $sku,
        'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
        'supplier_image_url' => $imgHd !== '' ? $imgHd : null,
        'supplier_reference' => $supplierRef !== '' ? substr($supplierRef, 0, 128) : null,
        'currency' => $cur,
        'gtin' => $gtin,
    ];

    if (!$dryRun) {
        $stmt->execute($params);
        $stats['updated']++;
    } else {
        $stats['updated']++;
    }
}

fwrite(STDOUT, json_encode($stats + ['dry_run' => $dryRun], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
