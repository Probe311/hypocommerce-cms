<?php

declare(strict_types=1);

/**
 * Inventorie seo-suppliers et propose un fichier "maitre" par groupe (heuristique par mots-cles).
 *
 * Usage:
 *   php backend/bin/seo_suppliers_inventory_masters.php [seo_suppliers_root]
 */

$root = $argv[1] ?? dirname(__DIR__, 2) . '/seo-suppliers';
$root = rtrim(str_replace('\\', '/', $root), '/');

if (!is_dir($root)) {
    fwrite(STDERR, "Dossier introuvable: {$root}\n");
    exit(1);
}

/**
 * @return list<string>
 */
function listFilesRecursive(string $dir): array
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    $files = [];
    /** @var SplFileInfo $fi */
    foreach ($it as $fi) {
        if (!$fi->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $fi->getPathname());
        if (str_contains($path, '/node_modules/')) {
            continue;
        }
        $files[] = $path;
    }
    return $files;
}

/**
 * @param array<string, mixed> $group
 */
function pickMaster(array $group): ?array
{
    if ($group['candidates'] === []) {
        return null;
    }
    usort($group['candidates'], static function (array $a, array $b): int {
        if ($a['size'] !== $b['size']) {
            return $b['size'] <=> $a['size'];
        }
        return strcmp($a['path'], $b['path']);
    });
    return $group['candidates'][0];
}

$files = listFilesRecursive($root);

// Groupes demandés (mots-clés sur le basename)
$groups = [
    'products_master' => [
        'keywords' => ['products_', 'product_', 'catalogue', 'master-eeat', 'produits-normalises'],
        'candidates' => [],
    ],
    'marques_longform' => [
        'keywords' => ['marque-', 'categories-marques-longform'],
        'candidates' => [],
    ],
    'contenus_pages' => [
        'keywords' => ['contenus-', 'pages-', 'plan-contenus', 'pages-editoriales'],
        'candidates' => [],
    ],
    'inventaire' => [
        'keywords' => ['inventaire'],
        'candidates' => [],
    ],
    'raw_all_products' => [
        'keywords' => ['all_products_raw', 'catalogue-fournisseurs'],
        'candidates' => [],
    ],
];

foreach ($files as $path) {
    $base = basename($path);
    $lower = mb_strtolower($base, 'UTF-8');
    $size = @filesize($path);
    if (!is_int($size)) {
        continue;
    }

    foreach ($groups as $gid => &$g) {
        foreach ($g['keywords'] as $kw) {
            if (str_contains($lower, mb_strtolower($kw, 'UTF-8'))) {
                $g['candidates'][] = ['path' => $path, 'size' => $size, 'basename' => $base];
                break;
            }
        }
    }
    unset($g);
}

$report = [
    'root' => $root,
    'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'groups' => [],
];

foreach ($groups as $gid => $g) {
    $master = pickMaster($g);
    $report['groups'][$gid] = [
        'keywords' => $g['keywords'],
        'candidate_count' => count($g['candidates']),
        'master' => $master,
        'top_candidates' => array_slice($g['candidates'], 0, 10),
    ];
}

$outPath = $root . '/logs/seo_suppliers_master_inventory.json';
$outDir = dirname($outPath);
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Impossible de creer le dossier logs: {$outDir}\n");
    exit(1);
}

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json) || file_put_contents($outPath, $json . "\n") === false) {
    fwrite(STDERR, "Ecriture rapport impossible: {$outPath}\n");
    exit(1);
}

fwrite(STDOUT, "OK inventaire ecrit: {$outPath}\n");
