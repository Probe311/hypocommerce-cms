<?php

declare(strict_types=1);

/**
 * Répartition des images locales par produit publié (objectif 1–4) + échantillons SKU.
 *
 * Usage: php backend/bin/diagnose_product_images_distribution.php
 */

require __DIR__ . '/bootstrap.php';

$pdo = App\Infrastructure\Database\ConnectionFactory::getConnection();

$published = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status='published'")->fetchColumn();

$distRows = $pdo->query(
    "SELECT cnt, COUNT(*) AS products
     FROM (
         SELECT p.id, COUNT(pi.id) AS cnt
         FROM products p
         LEFT JOIN product_images pi
           ON pi.product_id = p.id
          AND pi.url LIKE '/uploads/products/%'
         WHERE p.status = 'published'
         GROUP BY p.id
     ) t
     GROUP BY cnt
     ORDER BY cnt ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$dist = [];
foreach ($distRows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $dist[(string) ($row['cnt'] ?? '')] = (int) ($row['products'] ?? 0);
}

$over4 = (int) $pdo->query(
    "SELECT COUNT(*) FROM (
         SELECT p.id
         FROM products p
         INNER JOIN product_images pi
           ON pi.product_id = p.id
          AND pi.url LIKE '/uploads/products/%'
         WHERE p.status = 'published'
         GROUP BY p.id
         HAVING COUNT(pi.id) > 4
     ) x"
)->fetchColumn();

$zeroSample = $pdo->query(
    "SELECT p.sku, p.slug
     FROM products p
     LEFT JOIN product_images pi
       ON pi.product_id = p.id
      AND pi.url LIKE '/uploads/products/%'
     WHERE p.status = 'published'
     GROUP BY p.id, p.sku, p.slug
     HAVING COUNT(pi.id) = 0
     ORDER BY p.sku ASC
     LIMIT 15"
)->fetchAll(PDO::FETCH_ASSOC);

$over4Sample = $pdo->query(
    "SELECT p.sku, p.slug, COUNT(pi.id) AS n
     FROM products p
     INNER JOIN product_images pi
       ON pi.product_id = p.id
      AND pi.url LIKE '/uploads/products/%'
     WHERE p.status = 'published'
     GROUP BY p.id, p.sku, p.slug
     HAVING COUNT(pi.id) > 4
     ORDER BY n DESC
     LIMIT 15"
)->fetchAll(PDO::FETCH_ASSOC);

echo "published_products={$published}\n";
echo "distribution_local_images_count_to_products=" . json_encode($dist, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT) . "\n";
echo "products_with_more_than_4_local_images={$over4}\n";
echo "sample_zero_local_images=" . json_encode($zeroSample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
echo "sample_over_4_local_images=" . json_encode($over4Sample, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
