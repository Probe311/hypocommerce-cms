<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Database\ConnectionFactory;

$repoRoot = dirname(__DIR__, 2);
$defaultMaster = $repoRoot . '/seo-suppliers/donnees/produits/master-eeat.curated.json';

$masterPath = $defaultMaster;
$limit = 25;
$offset = 0;
$dryRun = false;

foreach ($argv as $arg) {
    if ($arg === $argv[0]) {
        continue;
    }
    if (str_starts_with($arg, '--master=')) {
        $masterPath = substr($arg, strlen('--master='));
    } elseif (str_starts_with($arg, '--limit=')) {
        $limit = max(1, (int) substr($arg, strlen('--limit=')));
    } elseif (str_starts_with($arg, '--offset=')) {
        $offset = max(0, (int) substr($arg, strlen('--offset=')));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php backend/bin/fill_missing_seo_batch.php [--master=...] [--offset=0] [--limit=25] [--dry-run]\n");
        exit(0);
    }
}

if (!is_file($masterPath)) {
    fwrite(STDERR, "Master JSON introuvable: {$masterPath}\n");
    exit(1);
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
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ProductSeoBatch/1.0)',
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
    if ($strict['status'] >= 200 && $strict['status'] < 400 && $strict['body'] !== '') {
        return $strict;
    }

    $err = mb_strtolower($strict['error'], 'UTF-8');
    if (str_contains($err, 'ssl') || str_contains($err, 'certificate') || str_contains($err, 'issuer')) {
        return fetchHtmlWithCurl($url, true);
    }

    return $strict;
}

function normalizeWhitespace(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

function truncateText(string $text, int $max): string
{
    $text = normalizeWhitespace($text);
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    $cut = mb_substr($text, 0, $max);
    $lastSpace = mb_strrpos($cut, ' ');
    if ($lastSpace !== false && $lastSpace > (int) ($max * 0.6)) {
        $cut = mb_substr($cut, 0, (int) $lastSpace);
    }
    return rtrim($cut, " ,;:-.") . '...';
}

function cleanupSentence(string $text): string
{
    $text = normalizeWhitespace($text);
    $text = preg_replace('/\s+([,;:.!?])/u', '$1', $text) ?? $text;
    return trim($text);
}

function buildSeoTitle(string $name, string $brand, string $cat1, string $cat2): string
{
    $name = normalizeWhitespace($name);
    $brand = normalizeWhitespace($brand);
    $cat2 = normalizeWhitespace($cat2);
    $cat1 = normalizeWhitespace($cat1);

    $parts = [$name];
    if ($brand !== '' && !str_contains(mb_strtolower($name, 'UTF-8'), mb_strtolower($brand, 'UTF-8'))) {
        $parts[] = $brand;
    }

    $cat = $cat2 !== '' ? $cat2 : $cat1;
    if ($cat !== '') {
        $parts[] = $cat;
    }
    $parts[] = 'Atelier Figurine';

    $title = cleanupSentence(implode(' | ', array_filter($parts, static fn(string $v): bool => $v !== '')));
    if (mb_strlen($title) > 65) {
        $title = truncateText($title, 62);
    }
    return $title;
}

function parseHtmlMetaDescription(string $html): string
{
    if (preg_match('/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) === 1) {
        return cleanupSentence((string) ($m[1] ?? ''));
    }
    if (preg_match('/<meta[^>]+property=["\']og:description["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) === 1) {
        return cleanupSentence((string) ($m[1] ?? ''));
    }
    return '';
}

/**
 * @param list<array{label:string,value:string}> $specs
 */
function buildSeoDescription(
    string $name,
    string $sku,
    string $brand,
    string $cat1,
    string $cat2,
    array $specs,
    string $existingDescription,
    string $supplierDescription
): string {
    $name = normalizeWhitespace($name);
    $brand = normalizeWhitespace($brand);
    $cat1 = normalizeWhitespace($cat1);
    $cat2 = normalizeWhitespace($cat2);

    $intro = $name;
    if ($brand !== '') {
        $intro .= " de {$brand}";
    }

    $contextParts = [];
    if ($cat1 !== '') {
        $contextParts[] = $cat1;
    }
    if ($cat2 !== '' && mb_strtolower($cat2, 'UTF-8') !== mb_strtolower($cat1, 'UTF-8')) {
        $contextParts[] = $cat2;
    }

    $context = $contextParts === [] ? 'pour le modélisme' : 'pour ' . implode(' / ', $contextParts);
    $first = "{$intro} {$context}.";

    $specBits = [];
    foreach (array_slice($specs, 0, 3) as $spec) {
        $label = cleanupSentence((string) ($spec['label'] ?? ''));
        $value = cleanupSentence((string) ($spec['value'] ?? ''));
        if ($label === '' || $value === '') {
            continue;
        }
        $specBits[] = "{$label}: {$value}";
    }

    $second = 'Usage conseillé: figurine, wargame et diorama.';
    if ($specBits !== []) {
        $second = 'Caractéristiques clés: ' . implode(' | ', $specBits) . '.';
    }

    $sourceSnippet = '';
    $candidate = $supplierDescription !== '' ? $supplierDescription : $existingDescription;
    if ($candidate !== '') {
        $sourceSnippet = truncateText($candidate, 110);
    }

    $third = $sourceSnippet !== ''
        ? "Info fournisseur: {$sourceSnippet}"
        : 'Fiche optimisée pour une décision d’achat claire, sans promesse technique non vérifiée.';

    $tail = $sku !== '' ? "Réf: {$sku}." : '';

    $desc = cleanupSentence($first . ' ' . $second . ' ' . $third . ' ' . $tail);
    $desc = truncateText($desc, 158);
    if (mb_strlen($desc) < 90) {
        $desc = truncateText($desc . ' Livraison rapide et support client spécialisé.', 158);
    }
    return $desc;
}

/** @var mixed $masterDecoded */
$masterDecoded = json_decode((string) file_get_contents($masterPath), true);
if (!is_array($masterDecoded)) {
    fwrite(STDERR, "Master JSON invalide.\n");
    exit(1);
}

$masterBySku = [];
$masterByRef = [];
$masterByName = [];
foreach ($masterDecoded as $row) {
    if (!is_array($row)) {
        continue;
    }
    $sku = normalizeWhitespace((string) ($row['sku'] ?? ''));
    $ref = normalizeWhitespace((string) ($row['reference'] ?? ''));
    $name = normalizeWhitespace((string) ($row['product_name'] ?? ''));
    if ($sku !== '') {
        $masterBySku[$sku] = $row;
    }
    if ($ref !== '') {
        $masterByRef[$ref] = $row;
    }
    if ($name !== '') {
        $masterByName[mb_strtolower($name, 'UTF-8')] = $row;
    }
}

$pdo = ConnectionFactory::getConnection();
$selectProducts = $pdo->prepare(
    "SELECT id, sku, name, description, seo_title, seo_description
     FROM products
     WHERE status='published'
       AND (COALESCE(TRIM(seo_title), '') = '' OR COALESCE(TRIM(seo_description), '') = '')
     ORDER BY updated_at DESC, id DESC
     LIMIT :limit OFFSET :offset"
);
$selectProducts->bindValue(':limit', $limit, PDO::PARAM_INT);
$selectProducts->bindValue(':offset', $offset, PDO::PARAM_INT);
$selectProducts->execute();
$products = $selectProducts->fetchAll(PDO::FETCH_ASSOC);

$selectSpecs = $pdo->prepare(
    'SELECT label, value
     FROM product_technical_specs
     WHERE product_id = :pid
     ORDER BY position ASC, id ASC
     LIMIT 8'
);

$updateSeo = $pdo->prepare(
    'UPDATE products
     SET seo_title = :seo_title,
         seo_description = :seo_description,
         updated_at = NOW()
     WHERE id = :id'
);

$stats = [
    'offset' => $offset,
    'limit' => $limit,
    'dry_run' => $dryRun,
    'selected' => is_array($products) ? count($products) : 0,
    'processed' => 0,
    'updated' => 0,
    'title_filled' => 0,
    'description_filled' => 0,
    'supplier_fetch_ok' => 0,
    'supplier_fetch_fail' => 0,
    'errors' => [],
];

foreach ($products as $row) {
    if (!is_array($row)) {
        continue;
    }
    $stats['processed']++;
    $id = (string) ($row['id'] ?? '');
    $sku = normalizeWhitespace((string) ($row['sku'] ?? ''));
    $name = normalizeWhitespace((string) ($row['name'] ?? ''));
    $desc = normalizeWhitespace((string) ($row['description'] ?? ''));
    $seoTitle = normalizeWhitespace((string) ($row['seo_title'] ?? ''));
    $seoDescription = normalizeWhitespace((string) ($row['seo_description'] ?? ''));

    $masterRow = null;
    if ($sku !== '' && isset($masterBySku[$sku]) && is_array($masterBySku[$sku])) {
        $masterRow = $masterBySku[$sku];
    } elseif ($sku !== '' && isset($masterByRef[$sku]) && is_array($masterByRef[$sku])) {
        $masterRow = $masterByRef[$sku];
    } elseif ($name !== '') {
        $key = mb_strtolower($name, 'UTF-8');
        if (isset($masterByName[$key]) && is_array($masterByName[$key])) {
            $masterRow = $masterByName[$key];
        }
    }

    $brand = '';
    $cat1 = '';
    $cat2 = '';
    $sourceUrl = '';
    if (is_array($masterRow)) {
        $brand = normalizeWhitespace((string) ($masterRow['brand'] ?? $masterRow['supplier'] ?? ''));
        $cat1 = normalizeWhitespace((string) ($masterRow['category_l1'] ?? ''));
        $cat2 = normalizeWhitespace((string) ($masterRow['category_l2'] ?? ''));
        $sourceUrl = normalizeWhitespace((string) ($masterRow['source_url'] ?? ''));
    }

    $selectSpecs->execute(['pid' => $id]);
    $specRows = $selectSpecs->fetchAll(PDO::FETCH_ASSOC);
    $specs = [];
    if (is_array($specRows)) {
        foreach ($specRows as $s) {
            $label = normalizeWhitespace((string) ($s['label'] ?? ''));
            $value = normalizeWhitespace((string) ($s['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                $specs[] = ['label' => $label, 'value' => $value];
            }
        }
    }

    $supplierDesc = '';
    if ($sourceUrl !== '' && preg_match('#^https?://#i', $sourceUrl) === 1) {
        $resp = fetchHtml($sourceUrl);
        if ($resp['status'] >= 200 && $resp['status'] < 400 && $resp['body'] !== '') {
            $supplierDesc = parseHtmlMetaDescription($resp['body']);
            $stats['supplier_fetch_ok']++;
        } else {
            $stats['supplier_fetch_fail']++;
        }
    }

    $newTitle = $seoTitle;
    if ($newTitle === '') {
        $newTitle = buildSeoTitle($name !== '' ? $name : $sku, $brand, $cat1, $cat2);
    }

    $newDescription = $seoDescription;
    if ($newDescription === '') {
        $newDescription = buildSeoDescription(
            $name !== '' ? $name : $sku,
            $sku,
            $brand,
            $cat1,
            $cat2,
            $specs,
            $desc,
            $supplierDesc
        );
    }

    $didFillTitle = $seoTitle === '' && $newTitle !== '';
    $didFillDescription = $seoDescription === '' && $newDescription !== '';

    if ($didFillTitle) {
        $stats['title_filled']++;
    }
    if ($didFillDescription) {
        $stats['description_filled']++;
    }

    if (($didFillTitle || $didFillDescription) && !$dryRun) {
        try {
            $updateSeo->execute([
                'id' => $id,
                'seo_title' => $newTitle,
                'seo_description' => $newDescription,
            ]);
            $stats['updated']++;
        } catch (Throwable $e) {
            $stats['errors'][] = [
                'product_id' => $id,
                'sku' => $sku,
                'reason' => $e->getMessage(),
            ];
        }
    } elseif ($didFillTitle || $didFillDescription) {
        $stats['updated']++;
    }
}

$cacheDir = dirname(__DIR__, 1) . '/var/cache/app';
if (!$dryRun && is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*.json') ?: [] as $file) {
        @unlink($file);
    }
}

fwrite(STDOUT, "SEO batch terminé.\n");
foreach ($stats as $k => $v) {
    if (is_array($v)) {
        fwrite(STDOUT, "{$k}: " . json_encode($v) . "\n");
    } else {
        fwrite(STDOUT, "{$k}: {$v}\n");
    }
}

