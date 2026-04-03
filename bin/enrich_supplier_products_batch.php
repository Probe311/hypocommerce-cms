<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php backend/bin/enrich_supplier_products_batch.php <input_json> [offset] [limit] [output_json] [report_json] [--dry-run]\n");
    exit(1);
}

$inputPath = $argv[1];
$offset = isset($argv[2]) ? max(0, (int) $argv[2]) : 0;
$limit = isset($argv[3]) ? max(1, (int) $argv[3]) : 25;
$outputPath = $argv[4] ?? dirname(__DIR__, 2) . '/seo-suppliers/donnees/produits/master-eeat.curated.json';
$reportPath = $argv[5] ?? dirname(__DIR__, 2) . '/seo-suppliers/logs/enrichment_batch_report.json';
$dryRun = in_array('--dry-run', $argv, true);

if (!is_file($inputPath)) {
    fwrite(STDERR, "Fichier introuvable: {$inputPath}\n");
    exit(1);
}

$raw = file_get_contents($inputPath);
if ($raw === false) {
    fwrite(STDERR, "Lecture impossible du fichier d'entree.\n");
    exit(1);
}

/** @var mixed $decoded */
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "JSON invalide.\n");
    exit(1);
}

/**
 * @param string $text
 */
function normalizeWhitespace(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\n{2,}/', "\n\n", $text) ?? $text;
    return trim($text);
}

/**
 * @param string $text
 * @return list<string>
 */
function words(string $text): array
{
    $clean = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if ($clean === '') {
        return [];
    }
    $parts = preg_split('/\s+/u', $clean);
    return is_array($parts) ? array_values(array_filter($parts, static fn($w) => $w !== '')) : [];
}

function countWords(string $text): int
{
    return count(words($text));
}

/**
 * Corrige les oublis d'accents / orthographe courants dans les paragraphes générés.
 * Post-traitement pour éviter de réécrire tous les gabarits à la main.
 */
function fixFrenchAccents(string $text): string
{
    if (trim($text) === '') return $text;

    $rules = [
        // Vocabulaire fréquent
        '/\breference(s)?\b/i' => 'références',
        '/\breference\b/i' => 'référence',
        '/\bresultat(s)?\b/i' => 'résultat$1',
        '/\bmethode\b/i' => 'méthode',
        '/\bintegre\b/i' => 'intègre',
        '/\bpensee\b/i' => 'pensée',
        '/\bpassionnes\b/i' => 'passionnés',
        '/\bmodelisme\b/i' => 'modélisme',
        '/\bmateriaux\b/i' => 'matériaux',
        '/\bmateriau\b/i' => 'matériau',
        '/\bmatieres\b/i' => 'matières',
        '/\bmatiere\b/i' => 'matière',
        '/\bdecor\b/i' => 'décor',
        '/\bscenes\b/i' => 'scènes',
        '/\bscene\b/i' => 'scène',
        '/\bdifference\b/i' => 'différence',
        '/\betapes\b/i' => 'étapes',
        '/\betape\b/i' => 'étape',

        // Orthographe / accords
        '/\breguliere\b/i' => 'régulière',
        '/\breguliers\b/i' => 'réguliers',
        '/\bregulier\b/i' => 'régulier',
        '/\badapte\s+a\b/i' => 'adapté à',
        '/\badaptees\s+a\b/i' => 'adaptées à',
        '/\badaptee\s+a\b/i' => 'adaptée à',
        '/\bdelais\b/i' => 'délais',
        '/\bregularite\b/i' => 'régularité',
        '/\bpercue\b/i' => 'perçue',
        '/\bpreparation\b/i' => 'préparation',
        '/\bsechage\b/i' => 'séchage',
        '/\bseche\b/i' => 'sèche',
        '/\bduree\b/i' => 'durée',
        '/\blisible\b/i' => 'lisible',

        // Prépositions fréquentes (réduction du bruit)
        '/\blisible\s+a\b/i' => 'lisible à',
        '/\bdistance\s+a\b/i' => 'distance à',
        '/\bcombiner\s+a\b/i' => 'combiner à',
        '/\ba\s+sec\b/i' => 'à sec',
        '/\ba\s+distance\b/i' => 'à distance',

        // Accentuation des mots sans diacritiques
        '/\blumiere\b/i' => 'lumière',
        '/\beclairage\b/i' => 'éclairage',
        '/\bcoherence\b/i' => 'cohérence',
        '/\bcoherente\b/i' => 'cohérente',
        '/\bqualite\b/i' => 'qualité',
        '/\bfiabilite\b/i' => 'fiabilité',
        '/\blisibilite\b/i' => 'lisibilité',
        '/\bvérification\b/i' => 'vérification',

        // Cas particuliers courants
        '/\brapprochee\b/i' => 'rapprochée',
        '/\bseches\b/i' => 'sèches',
        '/\bhumidite\b/i' => 'humidité',
        '/\bproprietes\b/i' => 'propriétés',
        '/\bmaitrise\b/i' => 'maîtrise',
        '/\binterpretations\b/i' => 'interprétations',
    ];

    foreach ($rules as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text) ?? $text;
    }

    return $text;
}

function trimToWords(string $text, int $maxWords): string
{
    $tokens = words($text);
    if (count($tokens) <= $maxWords) {
        return trim($text);
    }
    return trim(implode(' ', array_slice($tokens, 0, $maxWords))) . '.';
}

function absoluteUrl(string $baseUrl, string $candidate): string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $candidate) === 1) {
        return $candidate;
    }
    if (str_starts_with($candidate, '//')) {
        return 'https:' . $candidate;
    }

    $base = parse_url($baseUrl);
    if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
        return $candidate;
    }
    $scheme = $base['scheme'];
    $host = $base['host'];
    if (str_starts_with($candidate, '/')) {
        return "{$scheme}://{$host}{$candidate}";
    }

    $basePath = $base['path'] ?? '/';
    $baseDir = rtrim(str_replace('\\', '/', dirname($basePath)), '/');
    if ($baseDir === '') {
        $baseDir = '';
    }
    return "{$scheme}://{$host}{$baseDir}/{$candidate}";
}

/**
 * @param bool $relaxedTls
 * @return array{status:int,body:string,error:string}
 */
function fetchHtmlWithCurl(string $url, bool $relaxedTls): array
{
    if (!function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'error' => 'cURL indisponible'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['status' => 0, 'body' => '', 'error' => 'Init cURL impossible'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ProductEnricher/1.0)',
        CURLOPT_SSL_VERIFYPEER => !$relaxedTls,
        CURLOPT_SSL_VERIFYHOST => $relaxedTls ? 0 : 2,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($body)) {
        return ['status' => $status, 'body' => '', 'error' => $error !== '' ? $error : 'Reponse vide'];
    }
    return ['status' => $status, 'body' => $body, 'error' => $error];
}

/**
 * @return array{status:int,body:string,error:string}
 */
function fetchHtml(string $url): array
{
    $strict = fetchHtmlWithCurl($url, false);
    if ($strict['body'] !== '' && $strict['status'] >= 200 && $strict['status'] < 400) {
        return $strict;
    }
    $err = mb_strtolower($strict['error'], 'UTF-8');
    if (
        str_contains($err, 'ssl') ||
        str_contains($err, 'certificate') ||
        str_contains($err, 'issuer')
    ) {
        return fetchHtmlWithCurl($url, true);
    }
    return $strict;
}

function firstMatch(string $pattern, string $subject, int $group = 1): string
{
    $m = [];
    if (preg_match($pattern, $subject, $m) === 1 && isset($m[$group])) {
        return trim((string) $m[$group]);
    }
    return '';
}

/**
 * @return list<string>
 */
function extractJsonLdImages(string $html, string $baseUrl): array
{
    $results = [];
    if (preg_match_all('/<script[^>]*type="application\/ld\+json"[^>]*>(.*?)<\/script>/is', $html, $matches) !== 1 && count($matches[1] ?? []) === 0) {
        return [];
    }
    $scripts = $matches[1] ?? [];
    if (!is_array($scripts)) {
        return [];
    }
    foreach ($scripts as $jsonChunk) {
        if (!is_string($jsonChunk)) {
            continue;
        }
        /** @var mixed $parsed */
        $parsed = json_decode(trim($jsonChunk), true);
        if ($parsed === null) {
            continue;
        }
        $nodes = [];
        if (is_array($parsed) && isset($parsed['@graph']) && is_array($parsed['@graph'])) {
            $nodes = $parsed['@graph'];
        } else {
            $nodes[] = $parsed;
        }
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $img = $node['image'] ?? null;
            if (is_string($img) && $img !== '') {
                $results[] = absoluteUrl($baseUrl, $img);
                continue;
            }
            if (is_array($img)) {
                if (isset($img['url']) && is_string($img['url'])) {
                    $results[] = absoluteUrl($baseUrl, $img['url']);
                    continue;
                }
                foreach ($img as $item) {
                    if (is_string($item)) {
                        $results[] = absoluteUrl($baseUrl, $item);
                    }
                }
            }
        }
    }
    return array_values(array_unique(array_filter($results, static fn($v) => $v !== '')));
}

/**
 * @return list<string>
 */
function extractImageCandidates(string $html, string $sourceUrl): array
{
    $candidates = [];
    $og = firstMatch('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html);
    if ($og !== '') {
        $candidates[] = absoluteUrl($sourceUrl, $og);
    }
    $tw = firstMatch('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html);
    if ($tw !== '') {
        $candidates[] = absoluteUrl($sourceUrl, $tw);
    }
    foreach (extractJsonLdImages($html, $sourceUrl) as $img) {
        $candidates[] = $img;
    }
    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $imgMatches) === 1 || count($imgMatches[1] ?? []) > 0) {
        foreach (($imgMatches[1] ?? []) as $img) {
            if (!is_string($img)) {
                continue;
            }
            $u = absoluteUrl($sourceUrl, $img);
            if ($u !== '') {
                $candidates[] = $u;
            }
            if (count($candidates) > 30) {
                break;
            }
        }
    }
    $clean = [];
    foreach ($candidates as $c) {
        $lc = mb_strtolower($c, 'UTF-8');
        if (
            str_contains($lc, 'logo') ||
            str_contains($lc, 'icon') ||
            str_contains($lc, 'sprite') ||
            str_contains($lc, 'avatar')
        ) {
            continue;
        }
        $clean[] = $c;
    }
    return array_values(array_unique($clean));
}

function pickBestImage(string $html, string $sourceUrl): string
{
    $candidates = extractImageCandidates($html, $sourceUrl);
    if ($candidates === []) {
        return '';
    }
    usort($candidates, static function (string $a, string $b): int {
        $score = static function (string $url): int {
            $u = mb_strtolower($url, 'UTF-8');
            $s = 0;
            if (str_contains($u, 'og:image')) {
                $s += 50;
            }
            if (str_contains($u, 'large') || str_contains($u, 'zoom') || str_contains($u, 'hd') || str_contains($u, '1200')) {
                $s += 25;
            }
            if (preg_match('/\.(jpe?g|png|webp)(\?|$)/i', $u) === 1) {
                $s += 10;
            }
            if (str_contains($u, 'thumb') || str_contains($u, 'small')) {
                $s -= 20;
            }
            return $s;
        };
        return $score($b) <=> $score($a);
    });
    return $candidates[0];
}

/**
 * @param array<string,mixed> $row
 * @return list<string>
 */
function generateTags(array $row): array
{
    $name = mb_strtolower((string) ($row['product_name'] ?? ''), 'UTF-8');
    $cat1 = mb_strtolower((string) ($row['category_l1'] ?? ''), 'UTF-8');
    $cat2 = mb_strtolower((string) ($row['category_l2'] ?? ''), 'UTF-8');
    $desc = mb_strtolower((string) ($row['description'] ?? ''), 'UTF-8');
    $pool = trim($name . ' ' . $cat1 . ' ' . $cat2 . ' ' . $desc);

    $rules = [
        'herbe statique' => 'herbe-statique',
        'gazon' => 'gazon',
        'soclage' => 'soclage',
        'diorama' => 'diorama',
        'resine' => 'resine',
        'hiver' => 'hiver',
        'arctique' => 'arctique',
        'miniature' => 'miniatures',
        'wargame' => 'wargame',
        'texture' => 'texture',
        'peinture' => 'peinture',
        'terrain' => 'terrain',
        'decor' => 'decors',
        'animaux' => 'animaux',
        'bundle' => 'kit-complet',
        'kit' => 'kit-complet',
    ];

    $tags = [];
    foreach ($rules as $needle => $tag) {
        if (str_contains($pool, $needle)) {
            $tags[] = $tag;
        }
        if (count(array_unique($tags)) >= 4) {
            break;
        }
    }

    if ($tags === []) {
        $fallback = trim((string) ($row['category_l2'] ?? 'produit'));
        $fallback = mb_strtolower($fallback, 'UTF-8');
        $fallback = preg_replace('/[^a-z0-9]+/i', '-', $fallback) ?? 'produit';
        $tags[] = trim($fallback, '-') !== '' ? trim($fallback, '-') : 'produit';
    }

    $deduped = array_values(array_unique(array_map(static fn($t) => trim((string) $t), $tags)));
    return array_slice($deduped, 0, 4);
}

/**
 * @param array<string,mixed> $row
 */
function buildShortDescription(array $row): string
{
    $name = trim((string) ($row['product_name'] ?? 'Produit'));
    $brand = trim((string) ($row['brand'] ?? ''));
    $base = normalizeWhitespace((string) ($row['description'] ?? ''));
    $base = preg_replace('/^description/i', '', $base) ?? $base;
    $base = trim($base);
    if ($base !== '') {
        $base = trimToWords($base, 65);
    } else {
        $cat = trim((string) ($row['category_l2'] ?? $row['category_l1'] ?? ''));
        $base = "Produit de {$brand} concu pour {$cat}, ideal pour le modelisme, les dioramas et les projets de miniatures avec une finition propre et realiste.";
    }

    $prefix = $brand !== '' ? "{$name} par {$brand} : " : "{$name} : ";
    $short = $prefix . $base;
    return trimToWords($short, 100);
}

/**
 * @param array<string,mixed> $row
 * @param list<string> $tags
 */
function buildLongDescription(array $row, array $tags): string
{
    $name = trim((string) ($row['product_name'] ?? 'Ce produit'));
    $brand = trim((string) ($row['brand'] ?? 'la marque'));
    $category = trim((string) ($row['category_l2'] ?? $row['category_l1'] ?? ''));
    $price = trim((string) ($row['sale_price'] ?? ''));
    $currency = trim((string) ($row['currency'] ?? 'EUR'));
    $sourceText = normalizeWhitespace((string) ($row['description'] ?? ''));
    $sourceText = preg_replace('/^description/i', '', $sourceText) ?? $sourceText;
    $sourceText = trimToWords($sourceText, 180);
    $tagText = implode(', ', $tags);

    $paragraphs = [];
    $paragraphs[] = "{$name} est une reference {$category} de {$brand}, pensee pour les passionnes de modelisme, de diorama et de jeux de figurines qui recherchent un rendu convaincant sans complexifier leur workflow. Ce produit s'integre naturellement dans un projet de soclage, de decor ou d'ambiance narrative et permet d'obtenir un resultat visuel plus lisible a distance de jeu comme en photo rapprochee. Son positionnement est clair : offrir une solution fiable, facile a combiner avec d'autres materiaux, et suffisamment qualitative pour une utilisation reguliere, que vous soyez debutant motive ou hobbyiste experimente.";
    $paragraphs[] = "Dans un atelier, la constance des materiaux fait toute la difference. Avec {$name}, vous beneficiez d'une base de travail stable qui aide a gagner du temps sur les etapes de preparation et de finition. Le produit est pertinent pour enrichir les textures, renforcer le contraste de scene et ajouter une touche de realisme sans alourdir la composition. Il se marie bien avec les techniques courantes : colle blanche, pates texturantes, pigments, brossage a sec, lavis et effets de weathering. Cette polyvalence en fait un choix solide quand on veut standardiser sa methode de travail et reproduire des resultats coherents sur plusieurs figurines ou elements de decor.";
    $paragraphs[] = "Pour l'utilisation, commencez par definir la zone de pose et preparer une surface propre, seche et legerement texturée si necessaire. Appliquez ensuite le materiau progressivement afin de controler la densite, puis ajustez l'equilibre visuel en fonction de l'echelle de vos pieces. Une fois sec, vous pouvez renforcer la profondeur avec des nuances complementaires et proteger l'ensemble a l'aide d'un vernis adapte au rendu recherche. Ce protocole simple limite les erreurs, facilite les retouches et permet de construire des ambiances credibles, qu'il s'agisse d'un socle unitaire, d'une escouade complete ou d'un diorama narratif.";
    $paragraphs[] = "Ce produit est egalement interessant par sa compatibilite avec d'autres familles de references de l'univers hobby : vegetation, textures de sol, neiges artificielles, elements en resine et details de finition. En combinant ces ressources, vous pouvez creer des scenes plus riches sans multiplier les contraintes techniques. Les tags associes a cette fiche ({$tagText}) decrivent justement ses usages principaux et facilitent la navigation dans un catalogue orienté projets. Si votre objectif est de monter en qualite visuelle tout en gardant un process reproductible, {$name} offre un bon compromis entre simplicite d'emploi, impact esthetique et regularite de resultat.";
    $paragraphs[] = "Informations utiles : {$sourceText} Prix indicatif constate {$price} {$currency}. Comme pour tout materiau de modelisme, faites un essai sur une petite zone avant application definitive, stockez le produit a l'abri de l'humidite et refermez soigneusement apres usage pour conserver ses proprietes. Cette approche prudente permet de maintenir une qualite constante dans le temps et d'eviter les variations de rendu entre deux sessions. En synthese, {$name} est une option pertinente pour enrichir vos creations avec un niveau de detail maitrise, une mise en oeuvre accessible et une vraie valeur pratique au quotidien.";

    $long = implode("\n\n", $paragraphs);
    $wordCount = countWords($long);
    if ($wordCount < 450) {
        $long .= "\n\n" . "Pour aller plus loin, vous pouvez documenter vos recettes de pose, vos melanges et vos temps de sechage afin de reproduire facilement vos meilleurs resultats. Cette routine transforme une simple reference materielle en vrai standard de production personnelle. Plus votre methode est stable, plus vos projets gagnent en coherence visuelle, en vitesse d'execution et en qualite percue sur la table de jeu comme en presentation.";
    }
    if (countWords($long) > 550) {
        $long = trimToWords($long, 550);
    }
    return $long;
}

$total = count($decoded);
$slice = array_slice($decoded, $offset, $limit, true);

$stats = [
    'batch_id' => sprintf('seo-batch-%s-%d-%d', (new DateTimeImmutable())->format('YmdHis'), $offset, $limit),
    'input_total' => $total,
    'input_file' => $inputPath,
    'offset' => $offset,
    'limit' => $limit,
    'dry_run' => $dryRun,
    'processed' => 0,
    'enriched' => 0,
    'failed_image' => 0,
    'failed_content' => 0,
    'skipped_already_enriched' => 0,
    'errors' => [],
];

foreach ($slice as $index => $row) {
    $stats['processed']++;
    if (!is_array($row)) {
        $stats['failed_content']++;
        $stats['errors'][] = ['index' => $index, 'reason' => 'invalid_row'];
        continue;
    }

    $existingImage = trim((string) ($row['image_url_hd'] ?? ''));
    $existingShort = trim((string) ($row['short_description'] ?? ''));
    $existingLong = trim((string) ($row['description'] ?? ''));
    if ($existingImage !== '' && $existingShort !== '' && countWords($existingLong) >= 450) {
        $stats['skipped_already_enriched']++;
        continue;
    }

    $sourceUrl = trim((string) ($row['source_url'] ?? ''));
    $html = '';
    $imageUrl = $existingImage;
    if ($imageUrl === '' && $sourceUrl !== '') {
        $resp = fetchHtml($sourceUrl);
        if ($resp['status'] >= 200 && $resp['status'] < 400 && $resp['body'] !== '') {
            $html = $resp['body'];
            $imageUrl = pickBestImage($html, $sourceUrl);
        } else {
            $stats['errors'][] = [
                'index' => $index,
                'product_name' => (string) ($row['product_name'] ?? ''),
                'source_url' => $sourceUrl,
                'reason' => 'source_unreachable',
                'http_status' => $resp['status'],
                'error' => $resp['error'],
            ];
        }
    }

    $tags = generateTags($row);
    $short = fixFrenchAccents(buildShortDescription($row));
    $long = fixFrenchAccents(buildLongDescription($row, $tags));

    if ($imageUrl === '') {
        $stats['failed_image']++;
        $stats['errors'][] = [
            'index' => $index,
            'product_name' => (string) ($row['product_name'] ?? ''),
            'source_url' => $sourceUrl,
            'reason' => 'image_not_found',
        ];
    }
    if (countWords($short) > 100 || countWords($long) < 450) {
        $stats['failed_content']++;
        $stats['errors'][] = [
            'index' => $index,
            'product_name' => (string) ($row['product_name'] ?? ''),
            'reason' => 'content_quality_guard',
            'short_words' => countWords($short),
            'long_words' => countWords($long),
        ];
        continue;
    }

    $decoded[$index]['image_url_hd'] = $imageUrl;
    $decoded[$index]['tags'] = array_slice($tags, 0, 4);
    $decoded[$index]['short_description'] = $short;
    $decoded[$index]['description'] = $long;
    $stats['enriched']++;
}

$outDir = dirname($outputPath);
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Impossible de creer le dossier output: {$outDir}\n");
    exit(1);
}

$reportDir = dirname($reportPath);
if (!is_dir($reportDir) && !mkdir($reportDir, 0777, true) && !is_dir($reportDir)) {
    fwrite(STDERR, "Impossible de creer le dossier report: {$reportDir}\n");
    exit(1);
}

if (!$dryRun) {
    $jsonOut = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($jsonOut) || file_put_contents($outputPath, $jsonOut . "\n") === false) {
        fwrite(STDERR, "Ecriture output impossible: {$outputPath}\n");
        exit(1);
    }
}

$stats['completed_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
$reportOut = json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($reportOut) || file_put_contents($reportPath, $reportOut . "\n") === false) {
    fwrite(STDERR, "Ecriture report impossible: {$reportPath}\n");
    exit(1);
}

fwrite(STDOUT, "Batch termine.\n");
fwrite(STDOUT, "processed={$stats['processed']} enriched={$stats['enriched']} failed_image={$stats['failed_image']} failed_content={$stats['failed_content']} skipped={$stats['skipped_already_enriched']}\n");
