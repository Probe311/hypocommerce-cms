<?php

declare(strict_types=1);

/**
 * Nettoyage seo-suppliers: archive les fichiers redondants en conservant des "masters".
 *
 * Usage:
 *   php backend/bin/seo_suppliers_spring_clean.php [--apply]
 */

$apply = in_array('--apply', $argv, true);

$repoRoot = dirname(__DIR__, 2);
$ssRoot = $repoRoot . '/seo-suppliers';
$invPath = $ssRoot . '/logs/seo_suppliers_master_inventory.json';

if (!is_file($invPath)) {
    fwrite(STDERR, "Inventaire introuvable. Lance d'abord: php backend/bin/seo_suppliers_inventory_masters.php\n");
    exit(1);
}

$invRaw = file_get_contents($invPath);
if ($invRaw === false) {
    fwrite(STDERR, "Lecture inventaire impossible.\n");
    exit(1);
}

/** @var mixed $inv */
$inv = json_decode($invRaw, true);
if (!is_array($inv) || !isset($inv['groups']) || !is_array($inv['groups'])) {
    fwrite(STDERR, "Inventaire invalide.\n");
    exit(1);
}

$ts = (new DateTimeImmutable())->format('Y-m-d_His');
$archiveRoot = $ssRoot . '/archive/spring-clean_' . $ts;

/**
 * @param list<string> $segments
 */
function archivePath(string $archiveRoot, array $segments): string
{
    $path = rtrim($archiveRoot, '/');
    foreach ($segments as $seg) {
        $path .= '/' . $seg;
    }
    return $path;
}

/**
 * @return array{moved:list<array<string,string>>,skipped:list<array<string,string>>,errors:list<array<string,string>>}
 */
function ensureDir(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }
    return mkdir($dir, 0777, true) || is_dir($dir);
}

/**
 * @param array<string,mixed> $inv
 * @return list<string>
 */
function masterPathsFromInventory(array $inv): array
{
    $masters = [];
    $groups = $inv['groups'] ?? [];
    if (!is_array($groups)) {
        return [];
    }
    foreach ($groups as $g) {
        if (!is_array($g)) {
            continue;
        }
        $m = $g['master'] ?? null;
        if (is_array($m) && isset($m['path']) && is_string($m['path'])) {
            $masters[] = $m['path'];
        }
    }
    return array_values(array_unique($masters));
}

$masters = masterPathsFromInventory($inv);

// Masters explicites additionnels (hors groupes)
$alwaysKeep = [
    $repoRoot . '/seo-suppliers/donnees/contenu/strategie-seo/eeat-strategy.md',
    $repoRoot . '/seo-suppliers/donnees/contenu/strategie-seo/briefs/brief-template-produit.md',
    $repoRoot . '/seo-suppliers/donnees/contenu/strategie-seo/canonical-content-map.md',
    $repoRoot . '/seo-suppliers/donnees/contenu/categories-marques-longform/coverage_matrix.json',
    $repoRoot . '/seo-suppliers/logs/seo_suppliers_master_inventory.json',
];

$keep = array_values(array_unique(array_merge(
    array_map(static fn($p) => str_replace('\\', '/', $p), $masters),
    array_map(static fn($p) => str_replace('\\', '/', $p), $alwaysKeep)
)));

$toMove = [];

// 1) Produits: archive le master non-curated + dossier lots EEAT
$toMove[] = ['from' => $ssRoot . '/donnees/produits/master-eeat.json', 'to' => archivePath($archiveRoot, ['donnees', 'produits', 'master-eeat.json'])];
$toMove[] = ['from' => $ssRoot . '/donnees/produits/lots-eeat', 'to' => archivePath($archiveRoot, ['donnees', 'produits', 'lots-eeat'])];

// 2) Longform catégorie×marque : garder categories-marques-longform.json, archiver le reste (json+md)
$longformJsonDir = $ssRoot . '/donnees/contenu/categories-marques-longform/json';
if (is_dir($longformJsonDir)) {
    foreach (glob($longformJsonDir . '/*.json') ?: [] as $file) {
        $base = basename($file);
        if ($base === 'categories-marques-longform.json') {
            continue;
        }
        $toMove[] = [
            'from' => $file,
            'to' => archivePath($archiveRoot, ['donnees', 'contenu', 'categories-marques-longform', 'json', $base]),
        ];
    }
}
$longformMdDir = $ssRoot . '/donnees/contenu/categories-marques-longform/markdown';
if (is_dir($longformMdDir)) {
    foreach (glob($longformMdDir . '/*') ?: [] as $file) {
        $base = basename($file);
        $toMove[] = [
            'from' => $file,
            'to' => archivePath($archiveRoot, ['donnees', 'contenu', 'categories-marques-longform', 'markdown', $base]),
        ];
    }
}

// 3) Brut: archiver les exports fournisseurs unitaires (garder catalogue-fournisseurs.json)
$rawDir = $ssRoot . '/donnees/brut';
if (is_dir($rawDir)) {
    foreach (glob($rawDir . '/*_raw.json') ?: [] as $file) {
        $base = basename($file);
        $toMove[] = [
            'from' => $file,
            'to' => archivePath($archiveRoot, ['donnees', 'brut', $base]),
        ];
    }
}

$report = [
    'apply' => $apply,
    'archive_root' => str_replace('\\', '/', $archiveRoot),
    'kept_masters' => $keep,
    'moved' => [],
    'skipped' => [],
    'errors' => [],
];

foreach ($toMove as $item) {
    $from = str_replace('\\', '/', (string) $item['from']);
    $to = str_replace('\\', '/', (string) $item['to']);

    if (!file_exists($from)) {
        $report['skipped'][] = ['from' => $from, 'reason' => 'missing'];
        continue;
    }

    // sécurité: ne jamais archiver un fichier maître
    foreach ($keep as $k) {
        $k = str_replace('\\', '/', $k);
        if ($from === $k) {
            $report['skipped'][] = ['from' => $from, 'reason' => 'protected_master'];
            continue 2;
        }
    }

    if ($apply) {
        $toDir = dirname($to);
        if (!ensureDir($toDir)) {
            $report['errors'][] = ['from' => $from, 'to' => $to, 'reason' => 'mkdir_failed'];
            continue;
        }
        if (!rename($from, $to)) {
            $report['errors'][] = ['from' => $from, 'to' => $to, 'reason' => 'rename_failed'];
            continue;
        }
    }

    $report['moved'][] = ['from' => $from, 'to' => $to];
}

$reportPath = $ssRoot . '/logs/seo_suppliers_spring_clean_report.json';
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || file_put_contents($reportPath, $json . "\n") === false) {
    fwrite(STDERR, "Ecriture rapport impossible: {$reportPath}\n");
    exit(1);
}

fwrite(STDOUT, ($apply ? 'APPLY' : 'DRY-RUN') . " termine.\n");
fwrite(STDOUT, "Rapport: {$reportPath}\n");
fwrite(STDOUT, 'moved=' . count($report['moved']) . ' skipped=' . count($report['skipped']) . ' errors=' . count($report['errors']) . "\n");
