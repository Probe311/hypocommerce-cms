<?php

declare(strict_types=1);

/**
 * Transforme une tranche du JSON master (seo-suppliers/donnees/produits)
 * vers le format attendu par `backend/bin/import_catalog_bulk.php`.
 *
 * Usage:
 *   php backend/bin/export_master_slice_for_catalog_bulk.php <offset> <limit> <out_json_path> [--master=path]
 */

$masterPath = $argv[5] ?? '';
if ($masterPath === '' || !str_starts_with($masterPath, '--master=')) {
    $masterPath = dirname(__DIR__, 2) . '/seo-suppliers/donnees/produits/master-eeat.curated.json';
} else {
    $masterPath = substr($masterPath, strlen('--master='));
}

$offset = isset($argv[1]) ? max(0, (int) $argv[1]) : 0;
$limit = isset($argv[2]) ? max(1, (int) $argv[2]) : 25;
$outPath = $argv[3] ?? '';

if ($outPath === '') {
    fwrite(STDERR, "Missing out_json_path.\n");
    exit(1);
}

if (!is_file($masterPath)) {
    fwrite(STDERR, "Master JSON introuvable: {$masterPath}\n");
    exit(1);
}

$raw = file_get_contents($masterPath);
if ($raw === false) {
    fwrite(STDERR, "Impossible de lire le master JSON.\n");
    exit(1);
}

$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "JSON invalide: attendu un tableau.\n");
    exit(1);
}

$slice = array_slice($decoded, $offset, $limit, true);

/**
 * @param mixed $value
 */
function toFloatOrNull(mixed $value): ?float
{
    if ($value === null) {
        return null;
    }
    if (is_string($value)) {
        $s = trim($value);
        if ($s === '') {
            return null;
        }
        $s = str_replace(',', '.', $s);
        if (!is_numeric($s)) {
            return null;
        }
        $n = (float) $s;
        return $n > 0 ? $n : null;
    }
    if (is_numeric($value)) {
        $n = (float) $value;
        return $n > 0 ? $n : null;
    }
    return null;
}

/**
 * @param array<string,mixed> $row
 */
function normalizeType(array $row): string
{
    $t = strtolower(trim((string) ($row['product_type'] ?? '')));
    return $t === 'bundle' ? 'bundle' : 'simple';
}

/**
 * Slug stable et unique basé sur le SKU (évite collisions si le nom varie).
 */
function slugifySku(string $sku): string
{
    $sku = strtolower(trim($sku));
    $sku = preg_replace('/[^a-z0-9]+/', '-', $sku) ?? '';
    $sku = trim($sku, '-');
    return $sku === '' ? 'product' : $sku;
}

$out = [];
foreach ($slice as $idx => $row) {
    if (!is_array($row)) {
        continue;
    }

    $name = trim((string) ($row['product_name'] ?? ''));
    if ($name === '') {
        continue;
    }

    $sku = trim((string) ($row['sku'] ?? ''));
    if ($sku === '') {
        $sku = trim((string) ($row['reference'] ?? ''));
    }
    if ($sku === '') {
        continue;
    }

    $purchase = toFloatOrNull($row['purchase_price'] ?? null);
    $sale = toFloatOrNull($row['sale_price'] ?? null);

    $price = $purchase ?? $sale;
    if ($price === null) {
        continue;
    }

    $description = trim((string) ($row['description'] ?? ''));
    if ($description === '') {
        $description = null;
    }

    $ean = trim((string) ($row['ean'] ?? ''));
    $sourceUrl = trim((string) ($row['source_url'] ?? ''));
    $imgHd = trim((string) ($row['image_url_hd'] ?? ''));
    $reference = trim((string) ($row['reference'] ?? ''));
    $cur = strtoupper(trim((string) ($row['currency'] ?? 'EUR')));
    if (strlen($cur) !== 3) {
        $cur = 'EUR';
    }

    $out[] = [
        'sku' => $sku,
        'name' => $name,
        'slug' => slugifySku($sku),
        'description' => $description,
        'price' => $price,
        'salePrice' => $sale,
        'status' => 'published',
        'type' => normalizeType($row),
        'seoTitle' => null,
        'seoDescription' => null,
        'gtin' => $ean !== '' ? substr($ean, 0, 32) : null,
        'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
        'supplier_image_url' => $imgHd !== '' ? $imgHd : null,
        'supplier_reference' => $reference !== '' ? substr($reference, 0, 128) : null,
        'currency' => $cur,
    ];
}

if (!is_array($out) || $out === []) {
    fwrite(STDERR, "Aucune ligne transformee pour offset={$offset} limit={$limit}.\n");
    exit(1);
}

if (!is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0775, true);
}

$jsonOut = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($jsonOut)) {
    fwrite(STDERR, "Impossible de encoder json.\n");
    exit(1);
}

file_put_contents($outPath, $jsonOut . PHP_EOL);

fwrite(STDOUT, "Transformed slice offset={$offset} limit={$limit} -> " . count($out) . " rows.\n");

