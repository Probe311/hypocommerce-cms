<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Database\ConnectionFactory;

/**
 * Synchronise (images + caractéristiques techniques + arborescence catégories) depuis le JSON master.
 *
 * Usage:
 *   php backend/bin/sync_supplier_assets_batch.php --master=path/to/products.json --offset=0 --limit=25 [--dry-run] [--force-specs] [--images-only] [--max-images=4]
 *
 * Notes:
 * - Télécharge `image_url_hd` si aucune image locale n'existe encore pour le produit.
 * - Extrait les spécifications depuis les blocs JSON-LD (`additionalProperty(s)`) et via une heuristique HTML.
 * - Rattache le produit à la catégorie feuille (category_l2) sous category_l1.
 * - `--force-specs` : ré-extrait et remplace `product_technical_specs` même si des specs existent déjà.
 */

$repoRoot = dirname(__DIR__, 2);
$defaultMaster = $repoRoot . '/seo-suppliers/donnees/produits/master-eeat.curated.json';

$masterPath = $defaultMaster;
$offset = 0;
$limit = 25;
$dryRun = false;
$forceSpecs = false;
$imagesOnly = false;
$maxImagesCli = null;

foreach ($argv as $arg) {
    if ($arg === $argv[0]) {
        continue;
    }
    if (str_starts_with($arg, '--master=')) {
        $masterPath = substr($arg, strlen('--master='));
    } elseif (str_starts_with($arg, '--offset=')) {
        $offset = max(0, (int) substr($arg, strlen('--offset=')));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, strlen('--limit=')));
    } elseif (str_starts_with($arg, '--max-images=')) {
        $maxImagesCli = max(1, min(4, (int) substr($arg, strlen('--max-images='))));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--force-specs') {
        $forceSpecs = true;
    } elseif ($arg === '--images-only') {
        $imagesOnly = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php backend/bin/sync_supplier_assets_batch.php --master=... --offset=0 --limit=25 [--dry-run] [--force-specs] [--images-only] [--max-images=4]\n");
        exit(0);
    }
}

$maxImages = $maxImagesCli ?? ($imagesOnly ? 4 : 1);

if (!is_file($masterPath)) {
    fwrite(STDERR, "Master JSON introuvable: {$masterPath}\n");
    exit(1);
}

$raw = file_get_contents($masterPath);
if ($raw === false) {
    fwrite(STDERR, "Impossible de lire le fichier JSON: {$masterPath}\n");
    exit(1);
}

/** @var mixed $decoded */
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "JSON invalide: attendu un tableau.\n");
    exit(1);
}

if ($limit > count($decoded)) {
    $limit = max(1, count($decoded) - $offset);
}

$slice = array_slice($decoded, $offset, $limit, true);

function slugifyTech(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
    if ($translit !== false) {
        $value = $translit;
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value === '' ? 'general' : $value;
}

function normalizeWhitespace(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\n{2,}/', "\n\n", $text) ?? $text;
    return trim($text);
}

function stringFromMixed(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    if (is_string($value) || is_numeric($value) || is_bool($value)) {
        return normalizeWhitespace((string) $value);
    }
    if (is_array($value)) {
        if (isset($value['@value'])) {
            return normalizeWhitespace((string) $value['@value']);
        }
        $parts = [];
        foreach ($value as $v) {
            $s = stringFromMixed($v);
            if ($s !== '') {
                $parts[] = $s;
            }
        }
        return normalizeWhitespace(implode(', ', $parts));
    }
    return '';
}

/**
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
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ProductAssetSync/1.0)',
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
    if (str_contains($err, 'ssl') || str_contains($err, 'certificate') || str_contains($err, 'issuer')) {
        return fetchHtmlWithCurl($url, true);
    }
    return $strict;
}

/**
 * @return array{ok:bool,data?:string,error?:string,mime?:string}
 */
function fetchBinaryInternal(string $url, bool $relaxedTls): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'cURL indisponible'];
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'Init cURL impossible'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ProductAssetSync/1.0)',
        CURLOPT_SSL_VERIFYPEER => !$relaxedTls,
        CURLOPT_SSL_VERIFYHOST => $relaxedTls ? 0 : 2,
    ]);

    $data = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($data) || $data === '') {
        return [
            'ok' => false,
            'error' => $error !== '' ? $error : "Reponse vide (http={$status})",
            'mime' => $contentType,
        ];
    }

    // Content-Type peut contenir charset=...
    $mime = '';
    if ($contentType !== '') {
        $mime = strtolower(trim(explode(';', $contentType)[0]));
    }

    return ['ok' => true, 'data' => $data, 'mime' => $mime !== '' ? $mime : null];
}

/**
 * @return array{ok:bool,data?:string,error?:string,mime?:string}
 */
function fetchBinary(string $url): array
{
    $strict = fetchBinaryInternal($url, false);
    if ($strict['ok'] === true) {
        return $strict;
    }

    $err = mb_strtolower((string) ($strict['error'] ?? ''), 'UTF-8');
    if (
        str_contains($err, 'ssl') ||
        str_contains($err, 'certificate') ||
        str_contains($err, 'issuer')
    ) {
        return fetchBinaryInternal($url, true);
    }

    return $strict;
}

function detectImageExtension(string $binary, ?string $mime, string $url): ?array
{
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    $mimeNormalized = $mime !== null ? strtolower(trim(explode(';', $mime)[0])) : null;
    if ($mimeNormalized !== null && isset($allowed[$mimeNormalized])) {
        return ['ext' => $allowed[$mimeNormalized], 'mime' => $mimeNormalized];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = strtolower((string) $finfo->buffer($binary));
    if (isset($allowed[$detected])) {
        return ['ext' => $allowed[$detected], 'mime' => $detected];
    }

    $path = parse_url($url, PHP_URL_PATH);
    $ext = $path ? strtolower(pathinfo($path, PATHINFO_EXTENSION)) : '';
    if ($ext !== '' && in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        return ['ext' => $ext === 'jpeg' ? 'jpg' : $ext, 'mime' => null];
    }

    return null;
}

/**
 * Résout une URL candidate (absolue/relative/protocol relative) vers une URL absolue.
 */
function resolveUrlFromBase(string $baseUrl, string $candidate): string
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

    $scheme = (string) $base['scheme'];
    $host = (string) $base['host'];

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
 * @return list<string>
 */
function extractJsonLdImagesForCandidates(string $html, string $baseUrl): array
{
    $results = [];
    if (
        preg_match_all(
            '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is',
            $html,
            $matches
        ) !== 1
    ) {
        return [];
    }

    $scripts = $matches[1] ?? [];
    if (!is_array($scripts) || $scripts === []) {
        return [];
    }

    foreach ($scripts as $jsonChunk) {
        if (!is_string($jsonChunk)) {
            continue;
        }
        /** @var mixed $parsed */
        $parsed = json_decode(trim($jsonChunk), true);
        if (!is_array($parsed)) {
            continue;
        }

        $nodes = [];
        if (isset($parsed['@graph']) && is_array($parsed['@graph'])) {
            $nodes = $parsed['@graph'];
        } else {
            $nodes = [$parsed];
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }
            $img = $node['image'] ?? null;
            if (is_string($img) && $img !== '') {
                $results[] = resolveUrlFromBase($baseUrl, $img);
                continue;
            }
            if (is_array($img)) {
                if (isset($img['url']) && is_string($img['url'])) {
                    $results[] = resolveUrlFromBase($baseUrl, $img['url']);
                    continue;
                }
                foreach ($img as $item) {
                    if (is_string($item)) {
                        $results[] = resolveUrlFromBase($baseUrl, $item);
                    }
                }
            }
        }
    }

    $clean = [];
    foreach ($results as $r) {
        $lc = mb_strtolower((string) $r, 'UTF-8');
        if (
            str_contains($lc, 'logo') ||
            str_contains($lc, 'icon') ||
            str_contains($lc, 'sprite') ||
            str_contains($lc, 'avatar')
        ) {
            continue;
        }
        $clean[] = $r;
    }

    return array_values(array_unique(array_filter($clean, static fn($v) => is_string($v) && trim($v) !== '')));
}

/**
 * @return list<string>
 */
function extractImageCandidatesFromHtml(string $html, string $sourceUrl): array
{
    $candidates = [];

    $og = preg_match('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) === 1 ? ($m[1] ?? '') : '';
    if (is_string($og) && $og !== '') {
        $candidates[] = resolveUrlFromBase($sourceUrl, $og);
    }

    $tw = preg_match('/<meta[^>]+name=["\']twitter:image["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) === 1 ? ($m[1] ?? '') : '';
    if (is_string($tw) && $tw !== '') {
        $candidates[] = resolveUrlFromBase($sourceUrl, $tw);
    }

    foreach (extractJsonLdImagesForCandidates($html, $sourceUrl) as $img) {
        $candidates[] = $img;
    }

    // <img src="...">
    if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $imgMatches) === 1) {
        $imgs = $imgMatches[1] ?? [];
        if (is_array($imgs)) {
            foreach ($imgs as $img) {
                if (!is_string($img)) {
                    continue;
                }
                $u = resolveUrlFromBase($sourceUrl, $img);
                if ($u !== '') {
                    $candidates[] = $u;
                }
                if (count($candidates) > 40) {
                    break;
                }
            }
        }
    }

    $dedup = array_values(array_unique(array_filter($candidates, static fn($v) => is_string($v) && trim($v) !== '')));
    return $dedup;
}

/**
 * Choisit le meilleur candidat parmi une liste d'URLs image.
 */
function pickBestImageFromCandidates(array $candidates): string
{
    if ($candidates === []) {
        return '';
    }

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

    usort($candidates, static function (string $a, string $b) use ($score): int {
        return $score($b) <=> $score($a);
    });

    return (string) ($candidates[0] ?? '');
}

/**
 * Classe les URLs candidats (meilleures en premier), sans doublons d’URL normalisée.
 *
 * @param list<string> $candidates
 * @return list<string>
 */
function rankImageCandidates(array $candidates): array
{
    $candidates = array_values(array_unique(array_filter($candidates, static fn($v) => is_string($v) && trim($v) !== '')));
    if ($candidates === []) {
        return [];
    }
    $score = static function (string $url): int {
        $u = mb_strtolower($url, 'UTF-8');
        $s = 0;
        if (str_contains($u, 'large') || str_contains($u, 'zoom') || str_contains($u, 'hd') || str_contains($u, '1200')) {
            $s += 25;
        }
        if (preg_match('/\.(jpe?g|png|webp)(\?|$)/i', $u) === 1) {
            $s += 10;
        }
        if (str_contains($u, 'thumb') || str_contains($u, 'small')) {
            $s -= 20;
        }
        if (str_contains($u, 'logo') || str_contains($u, 'icon') || str_contains($u, 'sprite')) {
            $s -= 50;
        }
        return $s;
    };
    usort($candidates, static function (string $a, string $b) use ($score): int {
        return $score($b) <=> $score($a);
    });
    $seen = [];
    $out = [];
    foreach ($candidates as $c) {
        $norm = preg_replace('/\?.*$/', '', $c);
        if (isset($seen[$norm])) {
            continue;
        }
        $seen[$norm] = true;
        $out[] = $c;
    }
    return $out;
}

/**
 * @param list<string> $rankedFromHtml
 * @return list<string>
 */
function mergeImageUrlsForProduct(string $imageUrlHd, array $rankedFromHtml): array
{
    $urls = [];
    if ($imageUrlHd !== '') {
        $urls[] = $imageUrlHd;
    }
    foreach ($rankedFromHtml as $u) {
        $urls[] = $u;
    }
    $seen = [];
    $out = [];
    foreach ($urls as $u) {
        $u = trim($u);
        if ($u === '') {
            continue;
        }
        $norm = preg_replace('/\?.*$/', '', $u);
        if (isset($seen[$norm])) {
            continue;
        }
        $seen[$norm] = true;
        $out[] = $u;
    }
    return $out;
}

/**
 * @return list<array{label:string,value:string}>
 */
function extractSpecsFromJsonLd(string $html, string $sourceUrl): array
{
    $result = [];

    if (preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $html, $matches) !== 1) {
        return [];
    }

    $scripts = $matches[1] ?? [];
    if (!is_array($scripts) || $scripts === []) {
        return [];
    }

    /**
     * Collecte récursivement les paires label/value dans un arbre JSON-LD.
     *
     * @return list<array{label:string,value:string}>
     */
    $collectFromNode = function (mixed $node, int $depth) use (&$collectFromNode): array {
        if ($depth > 25) {
            return [];
        }
        $out = [];
        if (is_array($node)) {
            // Détection "PropertyValue" (schema.org) ou structures proches.
            $label = '';
            $value = '';

            // Cas: { name, value }
            if (isset($node['name']) && array_key_exists('value', $node)) {
                $label = stringFromMixed($node['name']);
                $value = stringFromMixed($node['value']);
            }

            // Cas: { propertyID, value }
            if (($label === '' || $value === '') && isset($node['propertyID']) && array_key_exists('value', $node)) {
                $label = stringFromMixed($node['propertyID']);
                $value = stringFromMixed($node['value']);
            }

            // Cas: { label, value }
            if (($label === '' || $value === '') && isset($node['label']) && array_key_exists('value', $node)) {
                $label = stringFromMixed($node['label']);
                $value = stringFromMixed($node['value']);
            }

            if ($label !== '' && $value !== '') {
                $out[] = ['label' => $label, 'value' => $value];
            }

            // Traversée récursive.
            foreach ($node as $v) {
                $out = array_merge($out, $collectFromNode($v, $depth + 1));
            }
        }
        return $out;
    };

    foreach ($scripts as $jsonChunk) {
        if (!is_string($jsonChunk)) {
            continue;
        }
        /** @var mixed $parsed */
        $parsed = json_decode(trim($jsonChunk), true);
        if (!is_array($parsed)) {
            continue;
        }

        $nodes = [];
        if (isset($parsed['@graph']) && is_array($parsed['@graph'])) {
            $nodes = $parsed['@graph'];
        } else {
            $nodes = [$parsed];
        }

        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            // Filtrage "soft": on accepte si le type mentionne Product.
            $types = $node['@type'] ?? null;
            $isProduct = false;
            if (is_string($types) && strtolower($types) === 'product') {
                $isProduct = true;
            } elseif (is_array($types)) {
                foreach ($types as $t) {
                    if (is_string($t) && strtolower($t) === 'product') {
                        $isProduct = true;
                        break;
                    }
                }
            }
            if (!$isProduct) {
                // Certains JSON-LD mettent les specs sans être strictement `@type=Product`.
                // On n'écrase pas ici: on récupère quand même les `additionalProperty`.
            }

            $candidates = [];
            if (isset($node['additionalProperty'])) {
                $candidates[] = $node['additionalProperty'];
            }
            if (isset($node['additionalProperties'])) {
                $candidates[] = $node['additionalProperties'];
            }
            foreach ($candidates as $candidate) {
                if (is_array($candidate) && $candidate !== []) {
                    // Soit une liste de props, soit une prop unique.
                    if (array_is_list($candidate)) {
                        foreach ($candidate as $prop) {
                            if (!is_array($prop)) {
                                continue;
                            }
                            $label = stringFromMixed($prop['name'] ?? $prop['label'] ?? $prop['propertyID'] ?? '');
                            $value = stringFromMixed($prop['value'] ?? $prop['propertyValue'] ?? $prop['description'] ?? '');
                            if ($label !== '' && $value !== '') {
                                $result[] = ['label' => $label, 'value' => $value];
                            }
                        }
                    } else {
                        $label = stringFromMixed($candidate['name'] ?? $candidate['label'] ?? $candidate['propertyID'] ?? '');
                        $value = stringFromMixed($candidate['value'] ?? $candidate['propertyValue'] ?? $candidate['description'] ?? '');
                        if ($label !== '' && $value !== '') {
                            $result[] = ['label' => $label, 'value' => $value];
                        }
                    }
                } elseif (is_array($candidate)) {
                    // prop vide ou autre format: ignore
                }
            }

            // Fallback robuste : propriétés JSON-LD de type PropertyValue / équivalents.
            $out2 = $collectFromNode($node, 0);
            foreach ($out2 as $pair) {
                if (isset($pair['label'], $pair['value']) && $pair['label'] !== '' && $pair['value'] !== '') {
                    $result[] = $pair;
                }
            }
        }
    }

    return $result;
}

/**
 * @return list<array{label:string,value:string}>
 */
function extractSpecsFromDom(string $html): array
{
    $result = [];
    $doc = new DOMDocument();
    // Suppression warnings HTML non valides.
    @$doc->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

    $xpath = new DOMXPath($doc);

    $maxLabelLength = (int) ($_ENV['MAX_SPEC_LABEL_LENGTH'] ?? 120);
    if ($maxLabelLength <= 0) {
        $maxLabelLength = 120;
    }

    $addPair = static function (string $label, string $value) use (&$result, $maxLabelLength): void {
        $l = normalizeWhitespace($label);
        $v = normalizeWhitespace($value);
        if ($l === '' || $v === '') {
            return;
        }
        // Certaines fiches fournisseurs ont des libellés techniques longs.
        if (mb_strlen($l) > $maxLabelLength) {
            return;
        }
        // Filtre léger "bruit marketing".
        $lc = mb_strtolower($l, 'UTF-8');
        if (str_contains($lc, 'ajouter') || str_contains($lc, 'panier') || str_contains($lc, 'quantit')) {
            return;
        }
        $result[] = ['label' => $l, 'value' => $v];
    };

    // dl/dt/dd
    foreach ($xpath->query('//dl') as $dlNode) {
        if (!($dlNode instanceof DOMElement)) {
            continue;
        }
        $children = [];
        foreach ($dlNode->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }
        $currentDt = '';
        foreach ($children as $child) {
            if ($child->tagName === 'dt') {
                $currentDt = $child->textContent ?? '';
            } elseif ($child->tagName === 'dd' && $currentDt !== '') {
                $addPair($currentDt, $child->textContent ?? '');
                $currentDt = '';
            }
        }
    }

    // tables: on prend les lignes "2 colonnes"
    foreach ($xpath->query('//table') as $tableNode) {
        if (!($tableNode instanceof DOMElement)) {
            continue;
        }
        foreach ($xpath->query('.//tr', $tableNode) as $trNode) {
            if (!($trNode instanceof DOMElement)) {
                continue;
            }
            $cells = [];
            foreach ($xpath->query('./th|./td', $trNode) as $cell) {
                if ($cell instanceof DOMElement) {
                    $cells[] = $cell->textContent ?? '';
                }
            }
            if (count($cells) >= 2) {
                $addPair($cells[0], $cells[1]);
            }
        }
    }

    // list items: heuristique "Label : Valeur"
    foreach ($xpath->query('//li') as $liNode) {
        if (!($liNode instanceof DOMElement)) {
            continue;
        }
        $txt = normalizeWhitespace($liNode->textContent ?? '');
        if ($txt === '') {
            continue;
        }
        $pos = mb_strpos($txt, ':');
        if ($pos === false) {
            continue;
        }
        $label = normalizeWhitespace(mb_substr($txt, 0, (int) $pos));
        $value = normalizeWhitespace(mb_substr($txt, (int) $pos + 1));
        if ($label !== '' && $value !== '') {
            $addPair($label, $value);
        }
    }

    return $result;
}

/**
 * @param list<array{label:string,value:string}> $pairs
 * @return list<array{label:string,value:string}>
 */
function dedupeSpecs(array $pairs): array
{
    $seen = [];
    $out = [];
    foreach ($pairs as $pair) {
        $label = normalizeWhitespace((string) ($pair['label'] ?? ''));
        $value = normalizeWhitespace((string) ($pair['value'] ?? ''));
        if ($label === '' || $value === '') {
            continue;
        }
        $key = mb_strtolower($label, 'UTF-8') . '|' . mb_strtolower($value, 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = ['label' => $label, 'value' => $value];
    }
    return $out;
}

/**
 * @return array{0:int,1:string}
 */
function upsertCategory(PDO $pdo, string $slug, string $name, ?int $parentId): array
{
    $find = $pdo->prepare('SELECT id FROM product_categories WHERE slug = :slug LIMIT 1');
    $upsert = $pdo->prepare(
        'INSERT INTO product_categories (parent_id, name, slug)
         VALUES (:parent_id, :name, :slug)
         ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), name = VALUES(name)'
    );
    $translationUpsert = $pdo->prepare(
        'INSERT INTO product_category_translations (category_id, locale, name, description)
         VALUES (:category_id, :locale, :name, :description)
         ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)'
    );

    $translation = "Produits de la categorie " . $name . '.';

    $upsert->execute([
        'parent_id' => $parentId,
        'name' => $name,
        'slug' => $slug,
    ]);
    $find->execute(['slug' => $slug]);
    $id = $find->fetchColumn();
    $categoryId = (int) ($id ?? 0);
    if ($categoryId > 0) {
        $translationUpsert->execute([
            'category_id' => $categoryId,
            'locale' => 'fr',
            'name' => $name,
            'description' => $translation,
        ]);
    }
    return [$categoryId, $slug];
}

/**
 * @return list<array{label:string,value:string}>
 */
function extractTechnicalSpecs(string $sourceUrl, string $html): array
{
    // Priorité JSON-LD (souvent structuré en additionalProperty(s)).
    $fromJson = extractSpecsFromJsonLd($html, $sourceUrl);
    $fromDom = extractSpecsFromDom($html);
    return dedupeSpecs(array_merge($fromJson, $fromDom));
}

$pdo = ConnectionFactory::getConnection();
$now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

$stats = [
    'batch_offset' => $offset,
    'batch_limit' => $limit,
    'dry_run' => $dryRun,
    'input_rows' => count($decoded),
    'processed' => 0,
    'matched_products' => 0,
    'image_downloaded' => 0,
    'external_images_deleted' => 0,
    'specs_extracted' => 0,
    'specs_empty' => 0,
    'specs_empty_kept' => 0,
    'categories_upserted' => 0,
    'skipped' => 0,
    'errors' => [],
    'max_images' => $maxImages,
];

// Product match.
$findProductBySku = $pdo->prepare('SELECT id, name, sku, slug FROM products WHERE sku = :sku LIMIT 1');
$findProductBySlug = $pdo->prepare('SELECT id, name, sku, slug FROM products WHERE slug = :slug LIMIT 1');

// Image writes.
$countLocalImage = $pdo->prepare(
    "SELECT COUNT(*) FROM product_images
     WHERE product_id = :pid AND url NOT LIKE 'http://%' AND url NOT LIKE 'https://%'"
);
$countExternalImage = $pdo->prepare(
    "SELECT COUNT(*) FROM product_images
     WHERE product_id = :pid AND (url LIKE 'http://%' OR url LIKE 'https://%')"
);
$shiftLocalPositions = $pdo->prepare('UPDATE product_images SET position = position + 1 WHERE product_id = :pid');
$deleteExternalImages = $pdo->prepare(
    "DELETE FROM product_images
     WHERE product_id = :pid AND (url LIKE 'http://%' OR url LIKE 'https://%')"
);
$insertImage = $pdo->prepare('INSERT INTO product_images (product_id, url, alt, position) VALUES (:pid, :url, :alt, :pos)');

// Specs writes.
$countSpecs = $pdo->prepare('SELECT COUNT(*) FROM product_technical_specs WHERE product_id = :pid');
$deleteSpecs = $pdo->prepare('DELETE FROM product_technical_specs WHERE product_id = :pid');
$insertSpec = $pdo->prepare(
    'INSERT INTO product_technical_specs (product_id, label, value, position)
     VALUES (:pid, :label, :value, :position)'
);

// Categories / pivot writes.
$deletePivots = $pdo->prepare('DELETE FROM product_category_pivot WHERE product_id = :product_id');
$attachPivot = $pdo->prepare('INSERT IGNORE INTO product_category_pivot (product_id, category_id) VALUES (:product_id, :category_id)');

$uploadDir = dirname(__DIR__, 1) . '/var/uploads/products';
if (!is_dir($uploadDir) && !$dryRun) {
    if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        fwrite(STDERR, "Impossible de créer le dossier d'upload: {$uploadDir}\n");
        exit(1);
    }
}

foreach ($slice as $idx => $row) {
    $stats['processed']++;
    if (!is_array($row)) {
        $stats['errors'][] = ['index' => $idx, 'reason' => 'invalid_row'];
        continue;
    }

    $sku = trim((string) ($row['sku'] ?? ''));
    $reference = trim((string) ($row['reference'] ?? ''));
    $productName = trim((string) ($row['product_name'] ?? ''));
    $sourceUrl = trim((string) ($row['source_url'] ?? ''));
    $imageUrl = trim((string) ($row['image_url_hd'] ?? ''));

    if ($sku === '' && $reference === '' && $productName === '') {
        $stats['skipped']++;
        continue;
    }

    $product = null;
    if ($sku !== '') {
        $findProductBySku->execute(['sku' => $sku]);
        $product = $findProductBySku->fetch(PDO::FETCH_ASSOC);
    }
    if (!is_array($product) && $reference !== '') {
        $findProductBySku->execute(['sku' => $reference]);
        $product = $findProductBySku->fetch(PDO::FETCH_ASSOC);
    }
    if (!is_array($product) && $productName !== '') {
        $slug = slugifyTech($productName);
        $findProductBySlug->execute(['slug' => $slug]);
        $product = $findProductBySlug->fetch(PDO::FETCH_ASSOC);
    }

    if (!is_array($product) || !isset($product['id'])) {
        $stats['skipped']++;
        continue;
    }

    $stats['matched_products']++;
    $productId = (string) $product['id'];
    $dbName = isset($product['name']) ? (string) $product['name'] : $productName;
    $alt = $dbName !== '' ? $dbName : $productName;

    $countLocalImage->execute(['pid' => $productId]);
    $localCount = (int) ($countLocalImage->fetchColumn() ?: 0);

    $countExternalImage->execute(['pid' => $productId]);
    $externalCount = (int) ($countExternalImage->fetchColumn() ?: 0);

    // Jusqu’à `max_images` locales (plancher 1–4 en mode --images-only).
    $effectiveMax = $imagesOnly ? min(4, $maxImages) : 1;
    $slotsNeeded = max(0, $effectiveMax - $localCount);
    $needImage = $slotsNeeded > 0 && ($imageUrl !== '' || $sourceUrl !== '');

    $countSpecs->execute(['pid' => $productId]);
    $specCount = (int) ($countSpecs->fetchColumn() ?: 0);
    $needSpecs = !$imagesOnly && ($forceSpecs || $specCount === 0);

    // Categories.
    $cat1 = trim((string) ($row['category_l1'] ?? ''));
    $cat2 = trim((string) ($row['category_l2'] ?? ''));
    if ($cat1 === '') {
        $cat1 = 'general';
    }
    $rootSlug = slugifyTech($cat1);
    $rootName = $cat1;
    $leafSlug = $rootSlug;
    $leafName = $rootName;
    $leafParentId = null;

    $childSlug = null;
    if ($cat2 !== '') {
        $childSlug = slugifyTech($cat2);
        $leafSlug = $childSlug;
        $leafName = $cat2;
    }

    // Dry-run: on ne touche ni DB ni fichiers.
    if ($dryRun) {
        $would = [
            'product_id' => $productId,
            'need_image' => $needImage,
            'need_specs' => $needSpecs,
            'category_root' => $rootSlug,
            'category_leaf' => $leafSlug,
        ];
        $stats['skipped']++;
        $stats['errors'][] = ['index' => $idx, 'dry_run_would' => $would];
        continue;
    }

    $pdo->beginTransaction();
    try {
        $htmlForRow = null;
        $htmlFetched = false;
        // 1) Categories upsert (root + child) + pivot leaf.
        $root = upsertCategory($pdo, $rootSlug, $rootName, null);
        $rootId = $root[0];

        $leafId = $rootId;
        if ($childSlug !== null) {
            $child = upsertCategory($pdo, $childSlug, $leafName, $rootId);
            $leafId = $child[0];
        }

        $deletePivots->execute(['product_id' => $productId]);
        $attachPivot->execute(['product_id' => $productId, 'category_id' => $leafId]);
        $stats['categories_upserted']++;

        // 2) Images: télécharger jusqu’à combler slotsNeeded (1 à 4).
        if ($needImage) {
            $needFetchHtml = $sourceUrl !== ''
                && preg_match('#^https?://#i', $sourceUrl) === 1
                && ($slotsNeeded > 1 || $imageUrl === '');

            if ($needFetchHtml && ($htmlForRow === null || !$htmlFetched)) {
                $respImg = fetchHtml($sourceUrl);
                if (
                    $respImg['status'] >= 200 &&
                    $respImg['status'] < 400 &&
                    $respImg['body'] !== ''
                ) {
                    $htmlForRow = $respImg['body'];
                    $htmlFetched = true;
                }
            }

            $rankedHtml = [];
            if ($htmlForRow !== null && $htmlForRow !== '') {
                $rankedHtml = rankImageCandidates(extractImageCandidatesFromHtml($htmlForRow, $sourceUrl));
            }

            $urlList = mergeImageUrlsForProduct($imageUrl, $rankedHtml);
            if ($urlList === [] && $imageUrl === '' && $sourceUrl === '') {
                $urlList = [];
            } elseif ($urlList === [] && $imageUrl === '' && $rankedHtml !== []) {
                $urlList = $rankedHtml;
            }

            $toDownload = array_slice($urlList, 0, $slotsNeeded);

            if ($toDownload === []) {
                $stats['errors'][] = [
                    'index' => $idx,
                    'product_id' => $productId,
                    'sku' => $sku !== '' ? $sku : $reference,
                    'reason' => 'image_not_found_from_source_url',
                ];
            } else {
                $deleteExternalImages->execute(['pid' => $productId]);
                $pos = $localCount;
                foreach ($toDownload as $oneUrl) {
                    $oneUrl = trim($oneUrl);
                    if ($oneUrl === '') {
                        continue;
                    }
                    $binary = fetchBinary($oneUrl);
                    if (!$binary['ok'] || !isset($binary['data']) || !is_string($binary['data'])) {
                        $stats['errors'][] = [
                            'index' => $idx,
                            'product_id' => $productId,
                            'sku' => $sku !== '' ? $sku : $reference,
                            'reason' => 'image_download_failed:' . ($binary['error'] ?? ''),
                        ];
                        continue;
                    }

                    $detected = detectImageExtension($binary['data'], $binary['mime'] ?? null, $oneUrl);
                    if ($detected === null) {
                        $stats['errors'][] = [
                            'index' => $idx,
                            'product_id' => $productId,
                            'sku' => $sku !== '' ? $sku : $reference,
                            'reason' => 'image_unsupported_mime',
                        ];
                        continue;
                    }

                    $ext = (string) $detected['ext'];
                    $hash = substr(sha1($oneUrl . '|' . $productId . '|' . $pos . '|' . $detected['mime'] . '|' . strlen($binary['data'])), 0, 12);
                    $filename = $productId . '_' . $hash . '.' . $ext;
                    $targetPath = $uploadDir . '/' . $filename;

                    if (!is_file($targetPath)) {
                        $written = file_put_contents($targetPath, $binary['data']);
                        if ($written === false) {
                            throw new RuntimeException('Ecriture image locale impossible');
                        }
                    }

                    $publicUrl = '/uploads/products/' . $filename;
                    $insertImage->execute([
                        'pid' => $productId,
                        'url' => $publicUrl,
                        'alt' => $alt,
                        'pos' => $pos,
                    ]);
                    $pos++;
                    $stats['image_downloaded']++;
                }
            }
        } elseif ($localCount > 0 && $externalCount > 0) {
            // Nettoyage: on conserve les images locales déjà présentes et on supprime les URLs externes.
            $deleteExternalImages->execute(['pid' => $productId]);
            $stats['external_images_deleted']++;
        }

        // 3) Specs: extraire si aucune spec existante.
        if ($needSpecs) {
            if ($sourceUrl === '') {
                $stats['errors'][] = [
                    'index' => $idx,
                    'product_id' => $productId,
                    'sku' => $sku !== '' ? $sku : $reference,
                    'reason' => 'source_url_missing_for_specs',
                ];
                $needSpecs = false;
            } else {
                if (!$htmlFetched) {
                    $resp = fetchHtml($sourceUrl);
                    if ($resp['status'] < 200 || $resp['status'] >= 400 || $resp['body'] === '') {
                        $stats['errors'][] = [
                            'index' => $idx,
                            'product_id' => $productId,
                            'sku' => $sku !== '' ? $sku : $reference,
                            'reason' => 'page_source_inaccessible_specs',
                        ];
                        $needSpecs = false;
                    } else {
                        $htmlForRow = $resp['body'];
                        $htmlFetched = true;
                    }
                }
            }

            if ($needSpecs && $htmlForRow !== null) {
                $pairs = extractTechnicalSpecs($sourceUrl, $htmlForRow);
            if ($pairs === []) {
                // En mode force, ne pas supprimer des specs existantes juste parce que l'extraction échoue.
                $stats['specs_empty']++;
                if ($specCount > 0) {
                    $stats['specs_empty_kept']++;
                }
            } else {
                $deleteSpecs->execute(['pid' => $productId]);
                $position = 0;
                foreach ($pairs as $pair) {
                    $label = normalizeWhitespace((string) ($pair['label'] ?? ''));
                    $value = normalizeWhitespace((string) ($pair['value'] ?? ''));
                    if ($label === '' || $value === '') {
                        continue;
                    }
                    $insertSpec->execute([
                        'pid' => $productId,
                        'label' => $label,
                        'value' => $value,
                        'position' => $position,
                    ]);
                    $position++;
                }

                $stats['specs_extracted']++;
            }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $stats['errors'][] = [
            'index' => $idx,
            'product_id' => $productId,
            'sku' => $sku !== '' ? $sku : $reference,
            'reason' => $e->getMessage(),
        ];
    }
}

// Invalidation cache simple: purge var/cache/app (façon sûre au démarrage après batch).
$cacheDir = dirname(__DIR__, 1) . '/var/cache/app';
if (!$dryRun && is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*.json') ?: [] as $file) {
        @unlink($file);
    }
}

fwrite(STDOUT, "Sync batch terminé.\n");
foreach ($stats as $k => $v) {
    if (is_array($v)) {
        fwrite(STDOUT, "{$k}: " . json_encode($v) . "\n");
        continue;
    }
    fwrite(STDOUT, "{$k}: {$v}\n");
}

