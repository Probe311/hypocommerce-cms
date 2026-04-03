<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = App\Infrastructure\Database\ConnectionFactory::getConnection();

$publishedCount = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status='published'")->fetchColumn();
$productImagesTotal = (int) $pdo->query("SELECT COUNT(*) FROM product_images")->fetchColumn();
$sampleImage = $pdo->query("SELECT url FROM product_images LIMIT 1")->fetchColumn();

$missingSpecs = (int) $pdo->query(
    "SELECT COUNT(*) FROM products p
     LEFT JOIN product_technical_specs ts ON ts.product_id = p.id
     WHERE p.status='published' AND ts.product_id IS NULL"
)->fetchColumn();

$missingLocalImage = (int) $pdo->query(
    "SELECT COUNT(*) FROM products p
     LEFT JOIN product_images pi ON pi.product_id = p.id AND pi.url LIKE '/uploads/products/%'
     WHERE p.status='published' AND pi.product_id IS NULL"
)->fetchColumn();

$missingSeoTitle = (int) $pdo->query(
    "SELECT COUNT(*) FROM products
     WHERE status='published' AND COALESCE(TRIM(seo_title), '') = ''"
)->fetchColumn();

$missingSeoDescription = (int) $pdo->query(
    "SELECT COUNT(*) FROM products
     WHERE status='published' AND COALESCE(TRIM(seo_description), '') = ''"
)->fetchColumn();

$missingSeoBoth = (int) $pdo->query(
    "SELECT COUNT(*) FROM products
     WHERE status='published'
       AND COALESCE(TRIM(seo_title), '') = ''
       AND COALESCE(TRIM(seo_description), '') = ''"
)->fetchColumn();

$sampleMissingSeo = $pdo->query(
    "SELECT sku, slug, name
     FROM products
     WHERE status='published'
       AND (COALESCE(TRIM(seo_title), '') = '' OR COALESCE(TRIM(seo_description), '') = '')
     ORDER BY updated_at DESC, id DESC
     LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

$totalWithAnyImage = (int) $pdo->query(
    "SELECT COUNT(*) FROM products p
     INNER JOIN product_images pi ON pi.product_id = p.id
     WHERE p.status='published'"
)->fetchColumn();

echo "published={$publishedCount}\n";
echo "product_images_total={$productImagesTotal}\n";
echo "product_images_sample_url=" . var_export($sampleImage, true) . "\n";
echo "missing_specs={$missingSpecs}\n";
echo "missing_local_image={$missingLocalImage}\n";
echo "missing_seo_title={$missingSeoTitle}\n";
echo "missing_seo_description={$missingSeoDescription}\n";
echo "missing_seo_both={$missingSeoBoth}\n";
echo "with_any_image={$totalWithAnyImage}\n";
echo "missing_seo_sample=" . json_encode($sampleMissingSeo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

