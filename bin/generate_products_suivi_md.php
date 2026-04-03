<?php

declare(strict_types=1);

/**
 * Génère docs/PRODUITS-SUIVI.md depuis master-eeat.curated.json (+ stats brut catalogue-fournisseurs, option SQL).
 *
 * Usage:
 *   php backend/bin/generate_products_suivi_md.php
 *   php backend/bin/generate_products_suivi_md.php /chemin/sortie.md
 *   php backend/bin/generate_products_suivi_md.php --with-sql
 *   php backend/bin/generate_products_suivi_md.php docs/out.md --with-sql [host db user password]
 */

$repoRoot = dirname(__DIR__, 2);
$curatedPath = $repoRoot . '/seo-suppliers/donnees/produits/master-eeat.curated.json';
$rawPath = $repoRoot . '/seo-suppliers/donnees/brut/catalogue-fournisseurs.json';

$withSql = in_array('--with-sql', $argv, true);
$positional = array_values(array_filter(
    array_slice($argv, 1),
    static fn (string $a): bool => $a !== '--with-sql'
));
$outPath = $repoRoot . '/docs/PRODUITS-SUIVI.md';
$dbArgvTail = [];
if (isset($positional[0]) && $positional[0] !== '' && !str_starts_with($positional[0], '--')) {
    $outPath = $positional[0];
    $dbArgvTail = array_slice($positional, 1);
}

if (!is_readable($curatedPath)) {
    fwrite(STDERR, "Fichier introuvable: {$curatedPath}\n");
    exit(1);
}

$curated = json_decode((string) file_get_contents($curatedPath), true);
if (!is_array($curated)) {
    fwrite(STDERR, "JSON curated invalide.\n");
    exit(1);
}

function normProductUrl(string $u): string
{
    return rtrim(trim($u), '/');
}

/**
 * Arborescence officielle (migration 028 + biomes étendus). Slugs = feuilles de classement.
 *
 * @var list<array{name:string,slug:string,children?:list<array{name:string,slug:string,children?:mixed}>}>
 */
function productNomenclatureTree(): array
{
    return [
        [
            'name' => 'Peintures',
            'slug' => 'peintures',
            'children' => [
                ['name' => 'Pack & Set', 'slug' => 'peintures-pack-set'],
                ['name' => 'Acryliques', 'slug' => 'peintures-acryliques', 'children' => [
                    ['name' => 'Base', 'slug' => 'peintures-acryliques-base'],
                    ['name' => 'Layer', 'slug' => 'peintures-acryliques-layer'],
                    ['name' => 'Airbrush', 'slug' => 'peintures-acryliques-airbrush'],
                ]],
                ['name' => 'Lavis & encres', 'slug' => 'peintures-lavis-encres'],
                ['name' => 'Métalliques', 'slug' => 'peintures-metalliques'],
                ['name' => 'Effets spéciaux', 'slug' => 'peintures-effets-speciaux', 'children' => [
                    ['name' => 'Sang', 'slug' => 'peintures-effets-speciaux-sang'],
                    ['name' => 'Rouille', 'slug' => 'peintures-effets-speciaux-rouille'],
                    ['name' => 'Fluorescent', 'slug' => 'peintures-effets-speciaux-fluorescent'],
                    ['name' => 'Caméléon', 'slug' => 'peintures-effets-speciaux-cameleon'],
                ]],
                ['name' => 'Pigments', 'slug' => 'peintures-pigments'],
            ],
        ],
        [
            'name' => 'Basing & décors',
            'slug' => 'basing-decors',
            'children' => [
                ['name' => 'Végétation', 'slug' => 'basing-decors-vegetation', 'children' => [
                    ['name' => 'Herbes', 'slug' => 'basing-decors-vegetation-tufts-herbes'],
                    ['name' => 'Buissons', 'slug' => 'basing-decors-vegetation-buissons'],
                    ['name' => 'Fleurs', 'slug' => 'basing-decors-vegetation-fleurs'],
                ]],
                ['name' => 'Textures & sols', 'slug' => 'basing-decors-textures-sols', 'children' => [
                    ['name' => 'Sable / gravier', 'slug' => 'basing-decors-textures-sols-sable-gravier'],
                    ['name' => 'Flocage', 'slug' => 'basing-decors-textures-sols-flocage'],
                    ['name' => 'Texture paint', 'slug' => 'basing-decors-textures-sols-texture-paint'],
                    ['name' => 'Neige / boue / eau', 'slug' => 'basing-decors-textures-sols-neige-boue-eau'],
                ]],
                ['name' => 'Éléments de décor', 'slug' => 'basing-decors-elements-decor', 'children' => [
                    ['name' => 'Rochers', 'slug' => 'basing-decors-elements-decor-rochers'],
                    ['name' => 'Ruines', 'slug' => 'basing-decors-elements-decor-ruines'],
                    ['name' => 'Débris', 'slug' => 'basing-decors-elements-decor-debris'],
                    ['name' => 'Arbres', 'slug' => 'basing-decors-elements-decor-arbres'],
                    ['name' => 'Divers', 'slug' => 'basing-decors-elements-decor-divers'],
                ]],
                ['name' => 'Socles', 'slug' => 'basing-decors-socles', 'children' => [
                    ['name' => 'Socles nus', 'slug' => 'basing-decors-socles-nus'],
                    ['name' => 'Socles texturés', 'slug' => 'basing-decors-socles-textures'],
                    ['name' => 'Socles premium', 'slug' => 'basing-decors-socles-premium'],
                ]],
                ['name' => 'Kits & pack', 'slug' => 'basing-decors-sets-bundles', 'children' => [
                    ['name' => 'Pack débutant', 'slug' => 'basing-decors-sets-bundles-kits-demarrage'],
                    ['name' => 'Pack peinture', 'slug' => 'basing-decors-sets-bundles-kits-peinture-debutant'],
                    ['name' => 'Pack basing', 'slug' => 'basing-decors-sets-bundles-kits-basing'],
                ]],
                ['name' => 'Biomes', 'slug' => 'basing-decors-biomes', 'children' => [
                    ['name' => 'Désert', 'slug' => 'basing-decors-biomes-desert'],
                    ['name' => 'Jungle', 'slug' => 'basing-decors-biomes-jungle'],
                    ['name' => 'Urbain', 'slug' => 'basing-decors-biomes-urbain'],
                    ['name' => 'Neige', 'slug' => 'basing-decors-biomes-neige'],
                    ['name' => 'Forêt', 'slug' => 'basing-decors-biomes-foret'],
                    ['name' => 'Marécage', 'slug' => 'basing-decors-biomes-marecage'],
                    ['name' => 'Montagne', 'slug' => 'basing-decors-biomes-montagne'],
                    ['name' => 'Plaines', 'slug' => 'basing-decors-biomes-plaines'],
                    ['name' => 'Volcanique', 'slug' => 'basing-decors-biomes-volcanique'],
                    ['name' => 'Toundra', 'slug' => 'basing-decors-biomes-toundra'],
                    ['name' => 'Côtier', 'slug' => 'basing-decors-biomes-cotier'],
                    ['name' => 'Marin', 'slug' => 'basing-decors-biomes-marin'],
                    ['name' => 'Savane', 'slug' => 'basing-decors-biomes-savane'],
                    ['name' => 'Steppe', 'slug' => 'basing-decors-biomes-steppe'],
                    ['name' => 'Arctique', 'slug' => 'basing-decors-biomes-arctique'],
                    ['name' => 'Ruines (biome)', 'slug' => 'basing-decors-biomes-ruines'],
                ]],
            ],
        ],
    ];
}

/**
 * @param list<array{name:string,slug:string,children?:mixed}> $nodes
 * @return list<string>
 */
function mdNomenclatureReferenceBullets(array $nodes, int $level = 0): array
{
    $lines = [];
    $pad = str_repeat('  ', $level);
    foreach ($nodes as $n) {
        $lines[] = $pad . '- **' . $n['name'] . '** — `' . $n['slug'] . '`';
        if (!empty($n['children']) && is_array($n['children'])) {
            $lines = array_merge($lines, mdNomenclatureReferenceBullets($n['children'], $level + 1));
        }
    }

    return $lines;
}

/**
 * @param list<array{name:string,slug:string,children?:mixed}> $nodes
 * @param list<string> $nameTrail
 * @return list<array{path:string,slug:string}>
 */
function nomenclatureCollectLeaves(array $nodes, array $nameTrail = []): array
{
    $out = [];
    foreach ($nodes as $n) {
        $trail = array_merge($nameTrail, [$n['name']]);
        if (!empty($n['children']) && is_array($n['children'])) {
            $out = array_merge($out, nomenclatureCollectLeaves($n['children'], $trail));
        } else {
            $out[] = ['path' => implode(' → ', $trail), 'slug' => $n['slug']];
        }
    }

    return $out;
}

/**
 * Slugs intermédiaires (non-feuilles) pour agrégats.
 *
 * @param list<array{name:string,slug:string,children?:mixed}> $nodes
 * @return list<string>
 */
function nomenclatureCollectIntermediateSlugs(array $nodes): array
{
    $slugs = [];
    foreach ($nodes as $n) {
        if (!empty($n['children']) && is_array($n['children'])) {
            $slugs[] = $n['slug'];
            $slugs = array_merge($slugs, nomenclatureCollectIntermediateSlugs($n['children']));
        }
    }

    return $slugs;
}

/**
 * Chaîne de slugs de la racine à la feuille (pour agrégats si `taxonomy_path` absent).
 *
 * @param list<array{name:string,slug:string,children?:mixed}> $nodes
 * @param list<string> $trail
 * @return list<string>|null
 */
function nomenclatureSlugTrailToLeaf(string $targetLeaf, array $nodes, array $trail = []): ?array
{
    foreach ($nodes as $n) {
        $next = array_merge($trail, [$n['slug']]);
        $hasChildren = !empty($n['children']) && is_array($n['children']);
        if ($hasChildren) {
            $found = nomenclatureSlugTrailToLeaf($targetLeaf, $n['children'], $next);
            if ($found !== null) {
                return $found;
            }
        }
        if ($n['slug'] === $targetLeaf) {
            return $next;
        }
    }

    return null;
}

/** @var array<string, array{slug:string,status:string,img_count:int}>|null $sqlByUrl */
$sqlByUrl = null;
if ($withSql) {
    require __DIR__ . '/bootstrap.php';
    $pdoArgv = array_merge([$argv[0] ?? 'generate_products_suivi_md.php'], $dbArgvTail);
    try {
        $pdo = pdoFromArgv($pdoArgv);
        $stmt = $pdo->query(
            'SELECT p.source_url AS u, p.slug AS s, p.status AS st,
                (SELECT COUNT(*) FROM product_images pi WHERE pi.product_id = p.id) AS ic
             FROM products p
             WHERE p.source_url IS NOT NULL AND TRIM(p.source_url) != \'\''
        );
        $sqlByUrl = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!is_array($row)) {
                continue;
            }
            $u = normProductUrl((string) ($row['u'] ?? ''));
            if ($u === '') {
                continue;
            }
            $sqlByUrl[$u] = [
                'slug' => (string) ($row['s'] ?? ''),
                'status' => (string) ($row['st'] ?? ''),
                'img_count' => (int) ($row['ic'] ?? 0),
            ];
        }
        fwrite(STDOUT, 'SQL: ' . count($sqlByUrl) . " produits indexés par source_url.\n");
    } catch (Throwable $e) {
        fwrite(STDERR, 'SQL ignoré: ' . $e->getMessage() . "\n");
        $sqlByUrl = null;
    }
}

$rawCount = null;
$rawPending = 0;
if (is_readable($rawPath)) {
    $raw = json_decode((string) file_get_contents($rawPath), true);
    if (is_array($raw)) {
        $rawCount = count($raw);
        $curatedUrls = [];
        foreach ($curated as $row) {
            if (is_array($row)) {
                $u = trim((string) ($row['source_url'] ?? ''));
                if ($u !== '') {
                    $curatedUrls[$u] = true;
                }
            }
        }
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $u = trim((string) ($row['source_url'] ?? ''));
            if ($u !== '' && !isset($curatedUrls[$u])) {
                $rawPending++;
            }
        }
    }
}

/**
 * @param array<string,mixed> $p
 */
function longBody(array $p): string
{
    $a = trim((string) ($p['long_description'] ?? ''));
    if ($a !== '') {
        return $a;
    }

    return trim((string) ($p['description'] ?? ''));
}

/**
 * @param array<string,mixed> $p
 */
function suiviEmoji(array $p): string
{
    $q = trim((string) ($p['data_quality'] ?? ''));
    $hasShort = trim((string) ($p['short_description'] ?? '')) !== '';
    $desc = longBody($p);
    $hasLong = $desc !== '' && strlen($desc) >= 120;
    $eeat = trim((string) ($p['eeat_enriched_at'] ?? '')) !== '';
    $copy = trim((string) ($p['copy_rewritten_at'] ?? '')) !== '';

    if ($q === 'complete' && $hasShort && $hasLong && $eeat && $copy) {
        return '✅';
    }
    if (!$eeat) {
        return '📝';
    }
    if (!$copy) {
        return '✏️';
    }
    if ($q === 'partial' || !$hasShort || !$hasLong) {
        return '⚠️';
    }

    return '➖';
}

/**
 * @param array<string,mixed> $p
 * @param array<string, array{slug:string,status:string,img_count:int}>|null $sqlByUrl
 */
function suiviSqlSuffix(array $p, ?array $sqlByUrl): string
{
    if ($sqlByUrl === null) {
        return '';
    }
    $url = normProductUrl((string) ($p['source_url'] ?? ''));
    if ($url === '') {
        return ' | SQL: _pas d’URL_';
    }
    $row = $sqlByUrl[$url] ?? null;
    if ($row === null) {
        return ' | SQL: ❌ absent';
    }
    $img = $row['img_count'] > 0 ? '🖼️' . $row['img_count'] : '🖼️0';
    $slug = $row['slug'] !== '' ? $row['slug'] : '—';
    $st = $row['status'] !== '' ? $row['status'] : '—';

    return ' | SQL: `' . $slug . '` · ' . $st . ' · ' . $img;
}

/**
 * @param array<string,mixed> $p
 */
function suiviFieldHints(array $p): string
{
    $hints = [];
    $ean = trim((string) ($p['ean'] ?? ''));
    $sku = trim((string) ($p['sku'] ?? ''));
    $ref = trim((string) ($p['reference'] ?? ''));
    if ($ean === '' && $sku === '' && $ref === '') {
        $hints[] = '🆔sans id';
    } elseif ($ean === '') {
        $hints[] = 'EAN✗';
    }
    $longLen = strlen(longBody($p));
    if ($longLen > 0 && $longLen < 200) {
        $hints[] = 'desc<' . $longLen;
    }
    if (trim((string) ($p['short_description'] ?? '')) === '') {
        $hints[] = 'court✗';
    }

    return $hints !== [] ? ' [' . implode(', ', $hints) . ']' : '';
}

/**
 * @param array<string,mixed> $p
 */
function suiviTaxonomySuffix(array $p): string
{
    $leaf = trim((string) ($p['taxonomy_leaf_slug'] ?? ''));
    $path = $p['taxonomy_path'] ?? null;
    $names = [];
    if (is_array($path)) {
        foreach ($path as $seg) {
            if (is_array($seg) && isset($seg['name'])) {
                $names[] = (string) $seg['name'];
            }
        }
    }
    if ($names !== [] && $leaf !== '') {
        return ' | taxo: ' . implode(' / ', $names) . ' (`' . $leaf . '`)';
    }
    if ($leaf !== '') {
        return ' | taxo: `' . $leaf . '`';
    }

    return '';
}

/**
 * @param array<string,mixed> $p
 * @param array<string, array{slug:string,status:string,img_count:int}>|null $sqlByUrl
 */
function suiviLine(array $p, ?array $sqlByUrl): string
{
    $emoji = suiviEmoji($p);
    $name = str_replace(['|', "\n", "\r"], ['/', ' ', ' '], (string) ($p['product_name'] ?? ''));
    $brand = (string) ($p['brand'] ?? '');
    $ref = (string) ($p['reference'] ?? $p['sku'] ?? '—');
    $price = (string) ($p['sale_price'] ?? '—');
    $dq = (string) ($p['data_quality'] ?? '—');
    $url = (string) ($p['source_url'] ?? '');
    $tags = $p['tags'] ?? [];
    $tagsStr = is_array($tags) ? implode(', ', array_map('strval', $tags)) : '';
    $nChar = (string) strlen(longBody($p));

    return sprintf(
        '- %s **%s** — %s | ref `%s` | %s EUR | qualité `%s` | long=%s | [fiche](%s)%s%s%s%s',
        $emoji,
        $name,
        $brand,
        $ref,
        $price,
        $dq,
        $nChar,
        $url,
        $tagsStr !== '' ? ' | tags: ' . $tagsStr : '',
        suiviFieldHints($p),
        suiviTaxonomySuffix($p),
        suiviSqlSuffix($p, $sqlByUrl)
    );
}

$byBrand = [];
foreach ($curated as $row) {
    if (!is_array($row)) {
        continue;
    }
    $b = trim((string) ($row['brand'] ?? 'Sans marque')) ?: 'Sans marque';
    if (!isset($byBrand[$b])) {
        $byBrand[$b] = [];
    }
    $byBrand[$b][] = $row;
}
ksort($byBrand, SORT_STRING);

$counts = ['✅' => 0, '⚠️' => 0, '📝' => 0, '✏️' => 0, '➖' => 0];
$missingEan = 0;
$missingShort = 0;
$lenBands = ['0' => 0, '1-199' => 0, '200-999' => 0, '1000+' => 0];
$sqlMatched = 0;
$sqlMissingInDb = 0;
$sqlNoImage = 0;
$taxoLeafCounts = [];
$taxoRollup = [];
$taxoPeintures = 0;
$taxoBasing = 0;
foreach ($curated as $row) {
    if (!is_array($row)) {
        continue;
    }
    $e = suiviEmoji($row);
    $counts[$e] = ($counts[$e] ?? 0) + 1;
    if (trim((string) ($row['ean'] ?? '')) === '') {
        $missingEan++;
    }
    if (trim((string) ($row['short_description'] ?? '')) === '') {
        $missingShort++;
    }
    $L = strlen(longBody($row));
    if ($L === 0) {
        $lenBands['0']++;
    } elseif ($L < 200) {
        $lenBands['1-199']++;
    } elseif ($L < 1000) {
        $lenBands['200-999']++;
    } else {
        $lenBands['1000+']++;
    }
    if ($sqlByUrl !== null) {
        $url = normProductUrl((string) ($row['source_url'] ?? ''));
        $hit = $url !== '' && isset($sqlByUrl[$url]);
        if ($hit) {
            $sqlMatched++;
            $key = $sqlByUrl[$url];
            if (($key['img_count'] ?? 0) === 0) {
                $sqlNoImage++;
            }
        } else {
            $sqlMissingInDb++;
        }
    }
    $tLeaf = trim((string) ($row['taxonomy_leaf_slug'] ?? ''));
    if ($tLeaf !== '') {
        $taxoLeafCounts[$tLeaf] = ($taxoLeafCounts[$tLeaf] ?? 0) + 1;
        if (str_starts_with($tLeaf, 'peintures')) {
            $taxoPeintures++;
        }
        if (str_starts_with($tLeaf, 'basing-decors')) {
            $taxoBasing++;
        }
    }

    $trailSlugs = null;
    $path = $row['taxonomy_path'] ?? null;
    if (is_array($path) && $path !== []) {
        $trailSlugs = [];
        foreach ($path as $seg) {
            if (is_array($seg) && isset($seg['slug'])) {
                $s = trim((string) $seg['slug']);
                if ($s !== '') {
                    $trailSlugs[] = $s;
                }
            }
        }
        if ($trailSlugs === []) {
            $trailSlugs = null;
        }
    }
    if ($trailSlugs === null && $tLeaf !== '') {
        $trailSlugs = nomenclatureSlugTrailToLeaf($tLeaf, productNomenclatureTree());
    }
    if (is_array($trailSlugs)) {
        foreach ($trailSlugs as $s) {
            $taxoRollup[$s] = ($taxoRollup[$s] ?? 0) + 1;
        }
    }
}

$generated = (new DateTimeImmutable())->format('Y-m-d H:i:s T');

$buf = [];
$buf[] = '# Suivi produits (catalogue JSON → SQL)';
$buf[] = '';
$buf[] = '> Généré le **' . $generated . '** — ne pas éditer le corps auto-généré à la main ; relancer le script après mise à jour du JSON.';
$buf[] = '';
$buf[] = '## Résumé';
$buf[] = '';
$buf[] = '| Indicateur | Valeur |';
$buf[] = '|------------|--------|';
$buf[] = '| Entrées curated | ' . count($curated) . ' |';
if ($rawCount !== null) {
    $buf[] = '| Entrées raw | ' . $rawCount . ' |';
    $buf[] = '| Hors curated (à enrichir) | ' . $rawPending . ' |';
}
$buf[] = '| ✅ Complet (qualité + textes + EEAT + copy) | ' . ($counts['✅'] ?? 0) . ' |';
$buf[] = '| ⚠️ Partiel / texte court | ' . ($counts['⚠️'] ?? 0) . ' |';
$buf[] = '| 📝 Sans EEAT | ' . ($counts['📝'] ?? 0) . ' |';
$buf[] = '| ✏️ Sans copy rewrite | ' . ($counts['✏️'] ?? 0) . ' |';
$buf[] = '| ➖ Autre | ' . ($counts['➖'] ?? 0) . ' |';
$buf[] = '| Sans EAN (champ vide) | ' . $missingEan . ' |';
$buf[] = '| Sans short_description | ' . $missingShort . ' |';
$buf[] = '| Longueur description/long (car.) 0 | ' . $lenBands['0'] . ' |';
$buf[] = '| Longueur 1–199 | ' . $lenBands['1-199'] . ' |';
$buf[] = '| Longueur 200–999 | ' . $lenBands['200-999'] . ' |';
$buf[] = '| Longueur 1000+ | ' . $lenBands['1000+'] . ' |';
if ($sqlByUrl !== null) {
    $buf[] = '| SQL — fiches trouvées (source_url) | ' . $sqlMatched . ' |';
    $buf[] = '| SQL — absentes en base | ' . $sqlMissingInDb . ' |';
    $buf[] = '| SQL — sans image produit | ' . $sqlNoImage . ' |';
}
if ($taxoLeafCounts !== []) {
    $buf[] = '| Taxonomie — sous `peintures-*` | ' . $taxoPeintures . ' |';
    $buf[] = '| Taxonomie — sous `basing-decors-*` | ' . $taxoBasing . ' |';
    $buf[] = '| Taxonomie — feuille Divers (`basing-decors-elements-decor-divers`) | ' . (int) ($taxoLeafCounts['basing-decors-elements-decor-divers'] ?? 0) . ' |';
}
$buf[] = '';

$nomTree = productNomenclatureTree();
$allNomLeaves = nomenclatureCollectLeaves($nomTree);
usort(
    $allNomLeaves,
    static function (array $a, array $b): int {
        $order = static function (string $slug): int {
            return str_starts_with($slug, 'peintures') ? 0 : 1;
        };
        $oa = $order($a['slug']);
        $ob = $order($b['slug']);
        if ($oa !== $ob) {
            return $oa <=> $ob;
        }

        return strcmp($a['path'], $b['path']);
    }
);
$canonicalLeafSlugs = [];
foreach ($allNomLeaves as $L) {
    $canonicalLeafSlugs[$L['slug']] = true;
}
$unknownTaxoLeaves = [];
foreach ($taxoLeafCounts as $slug => $n) {
    if (!isset($canonicalLeafSlugs[$slug])) {
        $unknownTaxoLeaves[$slug] = $n;
    }
}

$buf[] = '## Nomenclature complète de référence';
$buf[] = '';
$buf[] = '> Arborescence officielle (sous-catégories et feuilles), alignée sur `backend/migrations/028_product_category_taxonomy.sql` et `029_taxonomy_labels_marin_biome.sql` (libellés / biome Marin). Comptages : curated (`taxonomy_path` / `taxonomy_leaf_slug`, script `apply_taxonomy_to_master_json.php`).';
$buf[] = '';
$buf[] = '### Arborescence (libellés et slugs)';
$buf[] = '';
$buf = array_merge($buf, mdNomenclatureReferenceBullets($nomTree));
$buf[] = '';
$buf[] = '### Répartition catalogue : chaque feuille (chemin complet)';
$buf[] = '';
$buf[] = '| Chemin (racine → … → feuille) | Slug feuille | Produits |';
$buf[] = '|-------------------------------|--------------|----------|';
foreach ($allNomLeaves as $L) {
    $n = (int) ($taxoLeafCounts[$L['slug']] ?? 0);
    $pathEsc = str_replace('|', '/', $L['path']);
    $buf[] = '| ' . $pathEsc . ' | `' . $L['slug'] . '` | ' . $n . ' |';
}
$buf[] = '';
$buf[] = '### Répartition catalogue : racines et sous-branches (agrégats)';
$buf[] = '';
$buf[] = 'Nombre de produits classés dans la branche (feuille incluse), par slug de nœud.';
$buf[] = '';
$interSlugs = array_unique(array_merge(['peintures', 'basing-decors'], nomenclatureCollectIntermediateSlugs($nomTree)));
sort($interSlugs, SORT_STRING);
$buf[] = '| Slug (nœud) | Produits dans la branche |';
$buf[] = '|---------------|---------------------------|';
foreach ($interSlugs as $slug) {
    $n = (int) ($taxoRollup[$slug] ?? 0);
    $buf[] = '| `' . $slug . '` | ' . $n . ' |';
}
$buf[] = '';
if ($unknownTaxoLeaves !== []) {
    ksort($unknownTaxoLeaves, SORT_STRING);
    $buf[] = '### Feuilles présentes dans le JSON mais absentes du référentiel';
    $buf[] = '';
    $buf[] = '| Slug | Produits |';
    $buf[] = '|------|----------|';
    foreach ($unknownTaxoLeaves as $slug => $n) {
        $buf[] = '| `' . $slug . '` | ' . $n . ' |';
    }
    $buf[] = '';
}

$buf[] = '### Légende émojis (état éditorial)';
$buf[] = '';
$buf[] = '- ✅ Données `complete`, descriptions courtes/longues (≥120 car.) présentes, `eeat_enriched_at` et `copy_rewritten_at` renseignés.';
$buf[] = '- 📝 Pas de date EEAT.';
$buf[] = '- ✏️ EEAT OK mais pas de `copy_rewritten_at`.';
$buf[] = '- ⚠️ Qualité `partial` ou textes insuffisants.';
$buf[] = '- ➖ Cas restants.';
$buf[] = '';
$buf[] = '### Suffixes sur chaque ligne';
$buf[] = '';
$buf[] = '- `[EAN✗]` / `[🆔sans id]` / `[court✗]` / `[desc<N]` : champs JSON à compléter.';
$buf[] = '- `long=N` : taille du texte long (`long_description` ou `description`).';
$buf[] = '- Avec `--with-sql` : `SQL: slug · status · 🖼️n` ou `❌ absent` si pas de ligne `products.source_url`.';
$buf[] = '- `taxo: …` : chemin complet sur chaque ligne ; vue globale : section **Nomenclature complète de référence** ci-dessus.';
$buf[] = '';
$buf[] = '### Outils';
$buf[] = '';
$buf[] = '- Audit raw vs curated : `node seo-suppliers/scripts/audit_product_json.mjs` (option `--strict`).';
$buf[] = '- Taxonomie sur le master : `php backend/bin/apply_taxonomy_to_master_json.php` (`--dry-run`, `--list-leaf=…` pour export ciblé).';
$buf[] = '- Passe 2 (écarts catalogue) : `php backend/bin/report_pass2_gaps.php` (`--out=` pour JSON).';
$buf[] = '- Backfill factuel depuis `source_url` : `node seo-suppliers/scripts/backfill_master_factual_from_source.mjs` (`--dry-run`, `--host=`, `--limit=`).';
$buf[] = '- Purge SQL (garde-fous) : section **Workflow**.';
$buf[] = '- Import SQL : `php backend/bin/backfill_products_from_master.php`.';
$buf[] = '- Suivi + base : `php backend/bin/generate_products_suivi_md.php --with-sql` (utilise `.env` ou `host db user pass` après le chemin du `.md`).';
$buf[] = '';
$buf[] = '## Détail par marque';
$buf[] = '';

foreach ($byBrand as $brand => $items) {
    $buf[] = '### ' . $brand . ' (' . count($items) . ')';
    $buf[] = '';
    foreach ($items as $item) {
        if (is_array($item)) {
            $buf[] = suiviLine($item, $sqlByUrl);
        }
    }
    $buf[] = '';
}

$buf[] = '---';
$buf[] = '';
$buf[] = '## Workflow enrichissement';
$buf[] = '';
$buf[] = '1. **Vérifier les JSON** : `node seo-suppliers/scripts/audit_product_json.mjs` ; en CI vous pouvez ajouter `--strict`.';
$buf[] = '2. **Enrichir (Phase 3 EEAT/copy)** : sélection candidats (`node seo-suppliers/scripts/select_eeat_candidates.mjs`) puis exécution (`node seo-suppliers/scripts/run_phase3_eeat_on_candidates.mjs`) sur le master curated.';
$buf[] = '3. **Taxonomie (Passe 1)** : `php backend/bin/apply_taxonomy_to_master_json.php` (puis régénérer ce suivi).';
$buf[] = '4. **Données factuelles (Passe 2)** : `node seo-suppliers/scripts/backfill_master_factual_from_source.mjs` puis `php backend/bin/report_pass2_gaps.php` → reliquat manuel / overrides taxo Divers → `backfill_products_from_master.php` si besoin.';
$buf[] = '5. **Régénérer ce fichier** : `php backend/bin/generate_products_suivi_md.php` ; avec données boutique : ajouter `--with-sql`.';
$buf[] = '6. **Purge base (optionnel, irréversible)** : sauvegarde MySQL puis `ALLOW_DESTRUCTIVE_PURGE=1 php backend/bin/purge_application_data.php --dry-run` puis sans `--dry-run`.';
$buf[] = '7. **Réinjecter** : `schema_migrations` conservé → `php backend/bin/backfill_products_from_master.php` + scripts médias / uniformisation catégories si besoin.';
$buf[] = '';
$buf[] = '## Passes d\'enrichissement (plan)';
$buf[] = '';
$buf[] = '### Passe 1 — Taxonomie';
$buf[] = '';
$buf[] = '- Règles : `ProductNormalizationService` + `apply_taxonomy_to_master_json.php`.';
$buf[] = '- Inventaire Divers : `--list-leaf=basing-decors-elements-decor-divers`.';
$buf[] = '- Stabiliser une ligne : `taxonomy_locked` / `taxonomy_source: manual` ou `taxonomy_leaf_override`.';
$buf[] = '';
$buf[] = '### Passe 2 — Données catalogue factuelles + résidu Divers';
$buf[] = '';
$buf[] = '1. Lancer `php backend/bin/report_pass2_gaps.php` (option `--out=backend/var/reports/pass2_gaps.json`) pour lister sans EAN, sans SKU/référence, devises suspectes, et produits en feuille Divers.';
$buf[] = '2. Enrichir le master JSON : `node seo-suppliers/scripts/backfill_master_factual_from_source.mjs` (re-fetch `source_url`) puis compléter manuellement le reliquat ; champs : `ean`, `reference`, `sku`, `sale_price`, `currency`.';
$buf[] = '3. Pour colles / décals / accessoires encore en Divers : correction manuelle avec override ou verrou ; une future migration « consommables » pourra les sortir de Divers proprement.';
$buf[] = '4. Mettre à jour la boutique : `php backend/bin/backfill_products_from_master.php` ; contrôler avec ce suivi en `--with-sql`.';
$buf[] = '';
$buf[] = '### Passe 3 — EEAT / qualité données';
$buf[] = '';
$buf[] = '- Sélection : `node seo-suppliers/scripts/select_eeat_candidates.mjs --out=seo-suppliers/logs/eeat_candidates.json` (selon `data_quality`, présence `eeat_enriched_at` et `copy_rewritten_at`).';
$buf[] = '- Exécution : `node seo-suppliers/scripts/run_phase3_eeat_on_candidates.mjs --candidates=seo-suppliers/logs/eeat_candidates.json` (remplit `short_description`, `long_description`, `description`, puis marque `copy_rewritten_at` et `data_quality=complete`).';
$buf[] = '';
$buf[] = '### Passe 4 — Copy rewrite (reliquat)';
$buf[] = '';
$buf[] = '- Optionnel : compléter manuellement les rares reliquats/edge-cases si un produit a été volontairement exclu (filtre brand/leaf) ou si tu as besoin de réécrire un style particulier.';
$buf[] = '';

$dir = dirname($outPath);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

if (file_put_contents($outPath, implode("\n", $buf)) === false) {
    fwrite(STDERR, "Écriture impossible: {$outPath}\n");
    exit(1);
}

fwrite(STDOUT, "Écrit: {$outPath} (" . count($curated) . " produits)\n");
