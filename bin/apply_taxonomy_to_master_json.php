<?php

declare(strict_types=1);

/**
 * Applique la nomenclature (ProductNormalizationService) sur le master curated.
 *
 * Usage:
 *   php backend/bin/apply_taxonomy_to_master_json.php [--master=path] [--dry-run] [--report=path] [--divers-sample=N]
 *   php backend/bin/apply_taxonomy_to_master_json.php --list-leaf=basing-decors-elements-decor-divers [--list-out=path.json]
 *
 * JSON par ligne :
 *   taxonomy_locked true : conserve taxonomy_leaf_slug / taxonomy_path (recalcule le path si slug présent et path absent).
 *   taxonomy_leaf_override : slug feuille forcé ; path recalculé via le service.
 */

require __DIR__ . '/bootstrap.php';

use App\Application\Catalog\ProductNormalizationService;

$repoRoot = dirname(__DIR__, 2);
$defaultMaster = $repoRoot . '/seo-suppliers/donnees/produits/master-eeat.curated.json';
$masterPath = $defaultMaster;
$dryRun = in_array('--dry-run', $argv, true);
$reportPath = $repoRoot . '/backend/var/reports/taxonomy_master_pass.json';
$diversSample = 20;
$listLeaf = null;
$listOut = null;

foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--master=')) {
        $masterPath = substr((string) $arg, strlen('--master='));
    }
    if (str_starts_with((string) $arg, '--report=')) {
        $reportPath = substr((string) $arg, strlen('--report='));
    }
    if (str_starts_with((string) $arg, '--divers-sample=')) {
        $diversSample = max(0, (int) substr((string) $arg, strlen('--divers-sample=')));
    }
    if (str_starts_with((string) $arg, '--list-leaf=')) {
        $listLeaf = trim(substr((string) $arg, strlen('--list-leaf=')));
    }
    if (str_starts_with((string) $arg, '--list-out=')) {
        $listOut = trim(substr((string) $arg, strlen('--list-out=')));
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

$service = new ProductNormalizationService();

/**
 * @param array<string,mixed> $row
 */
function rowTaxonomyLocked(array $row): bool
{
    if (isset($row['taxonomy_locked']) && $row['taxonomy_locked'] === true) {
        return true;
    }
    if (isset($row['taxonomy_locked']) && $row['taxonomy_locked'] === 1) {
        return true;
    }
    if (isset($row['taxonomy_locked']) && $row['taxonomy_locked'] === 'true') {
        return true;
    }
    $src = trim((string) ($row['taxonomy_source'] ?? ''));

    return strcasecmp($src, 'manual') === 0;
}

/**
 * @param array<string,mixed> $row
 * @return array{leaf:string,path:list<array{slug:string,name:string}>}
 */
function resolveTaxonomyForRow(array $row, ProductNormalizationService $service): array
{
    $override = trim((string) ($row['taxonomy_leaf_override'] ?? ''));
    if ($override !== '') {
        return [
            'leaf' => $override,
            'path' => $service->taxonomyPathForLeafSlug($override),
        ];
    }

    if (rowTaxonomyLocked($row)) {
        $leaf = trim((string) ($row['taxonomy_leaf_slug'] ?? ''));
        $path = $row['taxonomy_path'] ?? null;
        if ($leaf !== '' && (is_array($path) && $path !== [])) {
            /** @var list<array{slug:string,name:string}> $path */
            return ['leaf' => $leaf, 'path' => $path];
        }
        if ($leaf !== '') {
            return [
                'leaf' => $leaf,
                'path' => $service->taxonomyPathForLeafSlug($leaf),
            ];
        }
    }

    $norm = $service->normalizeFromMasterRow($row);

    return [
        'leaf' => (string) ($norm['taxonomy_leaf_slug'] ?? ''),
        'path' => $norm['categoryPath'] ?? [],
    ];
}

if ($listLeaf !== null && $listLeaf !== '') {
    $listed = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $resolved = resolveTaxonomyForRow($row, $service);
        if ($resolved['leaf'] !== $listLeaf) {
            continue;
        }
        $long = trim((string) ($row['long_description'] ?? ''));
        $desc = $long !== '' ? $long : trim((string) ($row['description'] ?? ''));
        $snippet = $desc !== '' ? mb_substr($desc, 0, 220) : '';
        $listed[] = [
            'product_name' => $row['product_name'] ?? '',
            'sku' => $row['sku'] ?? $row['reference'] ?? '',
            'source_url' => $row['source_url'] ?? '',
            'taxonomy_leaf_slug' => $resolved['leaf'],
            'text_snippet' => $snippet,
        ];
    }
    $outFile = $listOut !== null && $listOut !== ''
        ? $listOut
        : $repoRoot . '/backend/var/reports/taxonomy_list_' . preg_replace('/[^a-z0-9_-]+/i', '_', $listLeaf) . '.json';
    $dir = dirname($outFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    $payload = [
        'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        'master' => $masterPath,
        'list_leaf' => $listLeaf,
        'count' => count($listed),
        'items' => $listed,
    ];
    file_put_contents($outFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    fwrite(STDOUT, "Liste {$listLeaf}: " . count($listed) . " produits → {$outFile}\n");
    exit(0);
}

$counts = [];
$diversExamples = [];
$outItems = [];

foreach ($items as $row) {
    if (!is_array($row)) {
        $outItems[] = $row;
        continue;
    }

    $resolved = resolveTaxonomyForRow($row, $service);
    $leaf = $resolved['leaf'];
    $path = $resolved['path'];

    $counts[$leaf] = ($counts[$leaf] ?? 0) + 1;

    if ($leaf === 'basing-decors-elements-decor-divers' && count($diversExamples) < $diversSample) {
        $diversExamples[] = [
            'product_name' => $row['product_name'] ?? '',
            'sku' => $row['sku'] ?? $row['reference'] ?? '',
            'source_url' => $row['source_url'] ?? '',
        ];
    }

    if (!$dryRun) {
        $row['taxonomy_leaf_slug'] = $leaf;
        $row['taxonomy_path'] = $path;
        $ov = trim((string) ($row['taxonomy_leaf_override'] ?? ''));
        if (!rowTaxonomyLocked($row) && $ov === '') {
            $row['taxonomy_pass'] = (int) ($row['taxonomy_pass'] ?? 0) + 1;
            $row['taxonomy_source'] = 'auto_v1';
        } elseif ($ov !== '') {
            $row['taxonomy_source'] = 'leaf_override';
        }
    }

    $outItems[] = $row;
}

arsort($counts, SORT_NUMERIC);
$topLeaves = array_slice($counts, 0, 15, true);

$underPeintures = 0;
$underBasing = 0;
foreach ($counts as $slug => $n) {
    if (str_starts_with((string) $slug, 'peintures')) {
        $underPeintures += $n;
    }
    if (str_starts_with((string) $slug, 'basing-decors')) {
        $underBasing += $n;
    }
}

$report = [
    'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'master' => $masterPath,
    'dry_run' => $dryRun,
    'rows' => count($items),
    'counts_by_leaf' => $counts,
    'top_15_leaves' => $topLeaves,
    'under_peintures_slugs' => $underPeintures,
    'under_basing_decors_slugs' => $underBasing,
    'divers_sample' => $diversExamples,
];

$reportDir = dirname($reportPath);
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0777, true);
}
file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
fwrite(STDOUT, "Rapport: {$reportPath}\n");

if (!$dryRun) {
    $encoded = json_encode($outItems, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        fwrite(STDERR, "Encodage JSON impossible.\n");
        exit(1);
    }
    if (file_put_contents($masterPath, $encoded . "\n") === false) {
        fwrite(STDERR, "Écriture master impossible.\n");
        exit(1);
    }
    fwrite(STDOUT, "Master mis à jour: {$masterPath}\n");
} else {
    fwrite(STDOUT, "Dry-run: master non modifié.\n");
}
