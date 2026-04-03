<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Database\ConnectionFactory;

/**
 * Construit un JSON "master slice" à partir des produits publiés sans image locale.
 *
 * Usage:
 *   php backend/bin/build_missing_image_slice.php <out_json_path> [offset] [limit]
 */

$outPath = $argv[1] ?? '';
$offset = isset($argv[2]) ? max(0, (int) $argv[2]) : 0;
$limit = isset($argv[3]) ? max(1, (int) $argv[3]) : 25;

if ($outPath === '') {
    fwrite(STDERR, "Usage: php backend/bin/build_missing_image_slice.php <out_json_path> [offset] [limit]\n");
    exit(1);
}

$masterPath = dirname(__DIR__, 2) . '/seo-suppliers/donnees/produits/master-eeat.curated.json';
if (!is_file($masterPath)) {
    fwrite(STDERR, "Master introuvable: {$masterPath}\n");
    exit(1);
}

$raw = file_get_contents($masterPath);
if ($raw === false) {
    fwrite(STDERR, "Lecture master impossible.\n");
    exit(1);
}

$master = json_decode($raw, true);
if (!is_array($master)) {
    fwrite(STDERR, "Master JSON invalide.\n");
    exit(1);
}

$bySku = [];
$byRef = [];
$byNameNorm = [];
foreach ($master as $row) {
    if (!is_array($row)) {
        continue;
    }
    $sku = trim((string) ($row['sku'] ?? ''));
    $ref = trim((string) ($row['reference'] ?? ''));
    $name = trim((string) ($row['product_name'] ?? ''));
    if ($sku === '') {
        // on peut quand même indexer par référence/nom.
    } else {
        $bySku[$sku] = $row;
    }
    if ($ref !== '') {
        $byRef[$ref] = $row;
    }
    if ($name !== '') {
        $nameNorm = mb_strtolower($name, 'UTF-8');
        $nameNorm = preg_replace('/\s+/', ' ', $nameNorm) ?? $nameNorm;
        $nameNorm = trim($nameNorm);
        if ($nameNorm !== '') {
            $byNameNorm[$nameNorm] = $row;
        }
    }
}

$pdo = ConnectionFactory::getConnection();
$stmt = $pdo->query(
    "SELECT p.sku, p.name
     FROM products p
     LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.url LIKE '/uploads/products/%'
     WHERE p.status='published' AND pi.product_id IS NULL
     ORDER BY p.sku ASC"
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$missingRows = [];
if (is_array($rows)) {
    foreach ($rows as $r) {
        $sku = trim((string) ($r['sku'] ?? ''));
        $name = trim((string) ($r['name'] ?? ''));
        $missingRows[] = ['sku' => $sku, 'name' => $name];
    }
}

$sliceRows = array_slice($missingRows, $offset, $limit);
$out = [];
foreach ($sliceRows as $row) {
    $sku = $row['sku'];
    $name = $row['name'];
    if ($sku !== '' && isset($bySku[$sku]) && is_array($bySku[$sku])) {
        $out[] = $bySku[$sku];
        continue;
    }
    if ($sku !== '' && isset($byRef[$sku]) && is_array($byRef[$sku])) {
        $out[] = $byRef[$sku];
        continue;
    }
    if ($name !== '') {
        $nameNorm = mb_strtolower($name, 'UTF-8');
        $nameNorm = preg_replace('/\s+/', ' ', $nameNorm) ?? $nameNorm;
        $nameNorm = trim($nameNorm);
        if ($nameNorm !== '' && isset($byNameNorm[$nameNorm]) && is_array($byNameNorm[$nameNorm])) {
            $out[] = $byNameNorm[$nameNorm];
            continue;
        }
    }
}

if (!is_dir(dirname($outPath))) {
    mkdir(dirname($outPath), 0775, true);
}

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fwrite(STDERR, "Encodage JSON impossible.\n");
    exit(1);
}
file_put_contents($outPath, $json . PHP_EOL);

fwrite(STDOUT, "missing_total=" . count($missingRows) . " selected=" . count($out) . " offset={$offset} limit={$limit}\n");

