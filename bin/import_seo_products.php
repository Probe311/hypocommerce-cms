<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/import_seo_products.php [host db user password] [json_path] [--dry-run]\n");
}

$dryRun = in_array('--dry-run', $argv, true);
$jsonPath = $argv[5] ?? dirname(__DIR__, 2) . '/seo-suppliers/normalized/products_normalized_deduped.json';

if (!is_file($jsonPath)) {
    fwrite(STDERR, "JSON source introuvable: {$jsonPath}\n");
    exit(1);
}

$rawJson = file_get_contents($jsonPath);
if ($rawJson === false) {
    fwrite(STDERR, "Impossible de lire le JSON source.\n");
    exit(1);
}

/** @var mixed $decoded */
$decoded = json_decode($rawJson, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "JSON invalide.\n");
    exit(1);
}

function slugify(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
    if ($translit !== false) {
        $value = $translit;
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    if ($value === '') {
        return 'product';
    }

    return $value;
}

function normalizePrice(mixed $raw): ?string
{
    $s = trim((string) $raw);
    if ($s === '') {
        return null;
    }
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) {
        return null;
    }
    $n = (float) $s;
    if ($n <= 0) {
        return null;
    }

    return number_format($n, 2, '.', '');
}

function normalizeType(mixed $raw): string
{
    return strtolower((string) $raw) === 'bundle' ? 'bundle' : 'simple';
}

function safeDateTime(mixed $raw, string $fallback): string
{
    $text = trim((string) $raw);
    if ($text === '') {
        return $fallback;
    }
    try {
        $dt = new DateTimeImmutable($text);
        return $dt->format('Y-m-d H:i:s');
    } catch (Throwable) {
        return $fallback;
    }
}

function makeUuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12)
    );
}

$now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
$rows = [];
$rejected = [];
$skuSeen = [];
$slugSeen = [];

foreach ($decoded as $idx => $item) {
    if (!is_array($item)) {
        $rejected[] = "row {$idx}: format invalide";
        continue;
    }

    $name = trim((string) ($item['product_name'] ?? ''));
    if ($name === '') {
        $rejected[] = "row {$idx}: product_name manquant";
        continue;
    }

    $price = normalizePrice($item['sale_price'] ?? null);
    if ($price === null) {
        $rejected[] = "row {$idx}: prix invalide";
        continue;
    }

    $rawSku = trim((string) ($item['sku'] ?? ''));
    $rawRef = trim((string) ($item['reference'] ?? ''));
    $rawSource = trim((string) ($item['source_url'] ?? ''));
    $baseSku = $rawSku !== '' ? $rawSku : ($rawRef !== '' ? $rawRef : ('AUTO-' . substr(sha1($name . '|' . $rawSource), 0, 20)));
    $baseSku = substr($baseSku, 0, 64);
    $sku = $baseSku;
    $skuSuffix = 2;
    while (isset($skuSeen[$sku])) {
        $suffix = '-' . $skuSuffix;
        $sku = substr($baseSku, 0, max(1, 64 - strlen($suffix))) . $suffix;
        $skuSuffix++;
    }
    $skuSeen[$sku] = true;

    $baseSlug = slugify($name);
    $slug = $baseSlug;
    $slugSuffix = 2;
    while (isset($slugSeen[$slug])) {
        $suffix = '-' . $slugSuffix;
        $slug = substr($baseSlug, 0, max(1, 255 - strlen($suffix))) . $suffix;
        $slugSuffix++;
    }
    $slugSeen[$slug] = true;

    $rows[] = [
        'id' => makeUuidV4(),
        'sku' => $sku,
        'name' => mb_substr($name, 0, 255),
        'slug' => mb_substr($slug, 0, 255),
        'description' => trim((string) ($item['description'] ?? '')) ?: null,
        'price' => $price,
        'sale_price' => null,
        'status' => 'published',
        'type' => normalizeType($item['product_type'] ?? null),
        'seo_title' => null,
        'seo_description' => null,
        'created_at' => safeDateTime($item['last_seen_at'] ?? null, $now),
        'updated_at' => $now,
    ];
}

if ($rows === []) {
    fwrite(STDERR, "Aucune ligne valide a importer.\n");
    exit(1);
}

try {
    if ($dryRun) {
        fwrite(STDOUT, "Dry-run: aucune ecriture en base.\n");
        fwrite(STDOUT, 'Source rows: ' . count($decoded) . "\n");
        fwrite(STDOUT, 'Rows valides: ' . count($rows) . "\n");
        fwrite(STDOUT, 'Rows rejetees: ' . count($rejected) . "\n");
        exit(0);
    }

    $pdo = pdoFromArgv($argv);

    $pdo->beginTransaction();
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

    // Reset catalogue (tables dependantes puis produits)
    $resetOrder = [
        'inventory_movements',
        'inventory_items',
        'cart_items',
        'product_related',
        'product_images',
        'product_category_pivot',
        'product_variants',
        'products',
    ];
    foreach ($resetOrder as $table) {
        $pdo->exec("DELETE FROM `{$table}`");
    }

    $stmt = $pdo->prepare(
        'INSERT INTO products (id, sku, name, slug, description, price, sale_price, status, type, seo_title, seo_description, created_at, updated_at)
         VALUES (:id, :sku, :name, :slug, :description, :price, :sale_price, :status, :type, :seo_title, :seo_description, :created_at, :updated_at)'
    );

    foreach ($rows as $row) {
        $stmt->execute($row);
    }

    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    $pdo->commit();

    $countProducts = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $countPublished = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'published'")->fetchColumn();
    $dupSku = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT sku FROM products GROUP BY sku HAVING COUNT(*) > 1) d')->fetchColumn();
    $dupSlug = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT slug FROM products GROUP BY slug HAVING COUNT(*) > 1) d')->fetchColumn();

    fwrite(STDOUT, "Import termine.\n");
    fwrite(STDOUT, 'Source rows: ' . count($decoded) . "\n");
    fwrite(STDOUT, 'Rows valides: ' . count($rows) . "\n");
    fwrite(STDOUT, 'Rows rejetees: ' . count($rejected) . "\n");
    fwrite(STDOUT, "Products en base: {$countProducts}\n");
    fwrite(STDOUT, "Products publies: {$countPublished}\n");
    fwrite(STDOUT, "Duplicats SKU: {$dupSku}\n");
    fwrite(STDOUT, "Duplicats slug: {$dupSlug}\n");
    if ($rejected !== []) {
        fwrite(STDOUT, "Exemples de rejets:\n");
        $sample = array_slice($rejected, 0, 10);
        foreach ($sample as $line) {
            fwrite(STDOUT, "- {$line}\n");
        }
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        } catch (Throwable) {
        }
    }
    fwrite(STDERR, 'Import echec: ' . $e->getMessage() . "\n");
    exit(1);
}
