<?php

declare(strict_types=1);

/**
 * Rapport Passe 2 : écarts données factuelles (EAN, SKU/référence, prix, devise) + résidu feuille Divers.
 *
 * Usage:
 *   php backend/bin/report_pass2_gaps.php
 *   php backend/bin/report_pass2_gaps.php --master=chemin/master.json
 *   php backend/bin/report_pass2_gaps.php --out=backend/var/reports/pass2_gaps.json
 */

$repoRoot = dirname(__DIR__, 2);
$masterPath = $repoRoot . '/seo-suppliers/donnees/produits/master-eeat.curated.json';
$outPath = null;

foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--master=')) {
        $masterPath = substr((string) $arg, strlen('--master='));
    }
    if (str_starts_with((string) $arg, '--out=')) {
        $outPath = substr((string) $arg, strlen('--out='));
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

const DIVERS_LEAF = 'basing-decors-elements-decor-divers';
const SAMPLE_CAP = 35;

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function rowSample(array $row): array
{
    return [
        'source_url' => (string) ($row['source_url'] ?? ''),
        'brand' => (string) ($row['brand'] ?? ''),
        'product_name' => (string) ($row['product_name'] ?? ''),
        'sku' => (string) ($row['sku'] ?? ''),
        'reference' => (string) ($row['reference'] ?? ''),
        'ean' => (string) ($row['ean'] ?? ''),
        'sale_price' => (string) ($row['sale_price'] ?? ''),
        'currency' => (string) ($row['currency'] ?? ''),
        'taxonomy_leaf_slug' => (string) ($row['taxonomy_leaf_slug'] ?? ''),
    ];
}

/**
 * @param list<array<string,mixed>> $into
 * @param array<string,mixed> $sample
 */
function pushSample(array &$into, array $sample): void
{
    if (count($into) >= SAMPLE_CAP) {
        return;
    }
    $into[] = $sample;
}

$totals = [
    'rows' => 0,
    'missing_ean' => 0,
    'missing_sku_and_reference' => 0,
    'missing_sale_price' => 0,
    'empty_currency' => 0,
    'non_eur_currency' => 0,
    'divers_leaf' => 0,
];

$samples = [
    'missing_ean' => [],
    'missing_sku_and_reference' => [],
    'missing_sale_price' => [],
    'empty_currency' => [],
    'non_eur_currency' => [],
    'divers_leaf' => [],
];

foreach ($items as $row) {
    if (!is_array($row)) {
        continue;
    }
    ++$totals['rows'];

    $ean = trim((string) ($row['ean'] ?? ''));
    $sku = trim((string) ($row['sku'] ?? ''));
    $ref = trim((string) ($row['reference'] ?? ''));
    $price = trim((string) ($row['sale_price'] ?? ''));
    $currency = trim((string) ($row['currency'] ?? ''));
    $leaf = (string) ($row['taxonomy_leaf_slug'] ?? '');

    if ($ean === '') {
        ++$totals['missing_ean'];
        pushSample($samples['missing_ean'], rowSample($row));
    }
    if ($sku === '' && $ref === '') {
        ++$totals['missing_sku_and_reference'];
        pushSample($samples['missing_sku_and_reference'], rowSample($row));
    }
    if ($price === '') {
        ++$totals['missing_sale_price'];
        pushSample($samples['missing_sale_price'], rowSample($row));
    }
    if ($currency === '') {
        ++$totals['empty_currency'];
        pushSample($samples['empty_currency'], rowSample($row));
    } elseif (strtoupper($currency) !== 'EUR') {
        ++$totals['non_eur_currency'];
        pushSample($samples['non_eur_currency'], rowSample($row));
    }
    if ($leaf === DIVERS_LEAF) {
        ++$totals['divers_leaf'];
        pushSample($samples['divers_leaf'], rowSample($row));
    }
}

$payload = [
    'generated_at' => gmdate('c'),
    'master' => $masterPath,
    'totals' => $totals,
    'samples' => $samples,
    'note' => 'Passe 2 : compléter le master puis backfill_products_from_master.php ; taxo résidu via apply_taxonomy / overrides.',
];

if ($outPath !== null && $outPath !== '') {
    $dir = dirname($outPath);
    if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            fwrite(STDERR, "Impossible de créer le dossier: {$dir}\n");
            exit(1);
        }
    }
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($outPath, $json . "\n") === false) {
        fwrite(STDERR, "Écriture rapport impossible: {$outPath}\n");
        exit(1);
    }
    fwrite(STDOUT, "Rapport JSON écrit: {$outPath}\n");
}

fwrite(STDOUT, "Passe 2 — écarts catalogue (master: {$masterPath})\n");
fwrite(STDOUT, "  lignes: {$totals['rows']}\n");
fwrite(STDOUT, "  sans EAN: {$totals['missing_ean']}\n");
fwrite(STDOUT, "  sans SKU ni référence: {$totals['missing_sku_and_reference']}\n");
fwrite(STDOUT, "  sans sale_price: {$totals['missing_sale_price']}\n");
fwrite(STDOUT, "  devise vide: {$totals['empty_currency']}\n");
fwrite(STDOUT, "  devise ≠ EUR: {$totals['non_eur_currency']}\n");
fwrite(STDOUT, '  feuille Divers (' . DIVERS_LEAF . "): {$totals['divers_leaf']}\n");
