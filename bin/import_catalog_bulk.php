<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (($argv[1] ?? null) === null) {
    fwrite(STDERR, "Usage: php bin/import_catalog_bulk.php <json_file> [host db user password]\n");
    exit(1);
}

$jsonFile = (string) $argv[1];
if (!is_file($jsonFile)) {
    fwrite(STDERR, "JSON file not found: {$jsonFile}\n");
    exit(1);
}

$raw = file_get_contents($jsonFile);
if ($raw === false) {
    fwrite(STDERR, "Unable to read JSON file.\n");
    exit(1);
}
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "Invalid JSON payload.\n");
    exit(1);
}

$dbArgv = $argv;
array_shift($dbArgv); // script
array_shift($dbArgv); // json file
array_unshift($dbArgv, $argv[0]);
$pdo = pdoFromArgv($dbArgv);

function slugifyBulk(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value === '' ? 'product' : $value;
}

function uuid4Bulk(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
}

$insert = $pdo->prepare(
    'INSERT INTO products (id, sku, name, slug, description, price, sale_price, status, type, seo_title, seo_description,
     gtin, source_url, supplier_image_url, supplier_reference, currency, created_at, updated_at)
     VALUES (:id, :sku, :name, :slug, :description, :price, :sale_price, :status, :type, :seo_title, :seo_description,
     :gtin, :source_url, :supplier_image_url, :supplier_reference, :currency, :created_at, :updated_at)
     ON DUPLICATE KEY UPDATE
       name = VALUES(name),
       slug = VALUES(slug),
       description = VALUES(description),
       price = VALUES(price),
       sale_price = VALUES(sale_price),
       status = VALUES(status),
       type = VALUES(type),
       seo_title = VALUES(seo_title),
       seo_description = VALUES(seo_description),
       gtin = COALESCE(VALUES(gtin), gtin),
       source_url = COALESCE(VALUES(source_url), source_url),
       supplier_image_url = COALESCE(VALUES(supplier_image_url), supplier_image_url),
       supplier_reference = COALESCE(VALUES(supplier_reference), supplier_reference),
       currency = VALUES(currency),
       updated_at = VALUES(updated_at)'
);

$now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
$ok = 0;
$errors = [];
foreach ($decoded as $i => $row) {
    if (!is_array($row)) {
        $errors[] = "row {$i}: invalid_format";
        continue;
    }
    $name = trim((string) ($row['name'] ?? ''));
    $sku = trim((string) ($row['sku'] ?? ''));
    $price = isset($row['price']) ? (float) $row['price'] : 0.0;
    if ($name === '' || $sku === '' || $price <= 0) {
        $errors[] = "row {$i}: missing_required_fields";
        continue;
    }
    $id = (isset($row['id']) && is_string($row['id']) && preg_match('/^[a-f0-9-]{36}$/i', $row['id'])) ? $row['id'] : uuid4Bulk();
    $slug = trim((string) ($row['slug'] ?? ''));
    if ($slug === '') {
        $slug = slugifyBulk($name);
    }
    $status = strtolower(trim((string) ($row['status'] ?? 'draft')));
    if (!in_array($status, ['draft', 'published', 'archived'], true)) {
        $status = 'draft';
    }
    $type = strtolower(trim((string) ($row['type'] ?? 'simple')));
    if (!in_array($type, ['simple', 'variable', 'digital', 'bundle'], true)) {
        $type = 'simple';
    }
    $eanRaw = trim((string) ($row['ean'] ?? $row['gtin'] ?? ''));
    $gtin = $eanRaw !== '' ? substr($eanRaw, 0, 32) : null;
    $sourceUrl = trim((string) ($row['sourceUrl'] ?? $row['source_url'] ?? ''));
    $supplierImageUrl = trim((string) ($row['supplierImageUrl'] ?? $row['supplier_image_url'] ?? $row['image_url_hd'] ?? ''));
    $ref = trim((string) ($row['supplierReference'] ?? $row['supplier_reference'] ?? $row['reference'] ?? ''));
    $supplierRef = $ref !== '' ? substr($ref, 0, 128) : null;
    $currency = strtoupper(trim((string) ($row['currency'] ?? 'EUR')));
    if (strlen($currency) !== 3) {
        $currency = 'EUR';
    }

    $insert->execute([
        'id' => $id,
        'sku' => $sku,
        'name' => $name,
        'slug' => $slug,
        'description' => isset($row['description']) ? (string) $row['description'] : null,
        'price' => $price,
        'sale_price' => isset($row['salePrice']) ? (float) $row['salePrice'] : null,
        'status' => $status,
        'type' => $type,
        'seo_title' => isset($row['seoTitle']) ? (string) $row['seoTitle'] : null,
        'seo_description' => isset($row['seoDescription']) ? (string) $row['seoDescription'] : null,
        'gtin' => $gtin,
        'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
        'supplier_image_url' => $supplierImageUrl !== '' ? $supplierImageUrl : null,
        'supplier_reference' => $supplierRef,
        'currency' => $currency,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $ok++;
}

fwrite(STDOUT, "Bulk import done. processed={$ok} errors=" . count($errors) . PHP_EOL);
if ($errors !== []) {
    foreach (array_slice($errors, 0, 20) as $e) {
        fwrite(STDOUT, "- {$e}\n");
    }
}
