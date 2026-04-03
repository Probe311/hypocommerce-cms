<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Database\ConnectionFactory;

$pdo = ConnectionFactory::getConnection();

$sql = <<<SQL
SELECT
    p.*,
    (
        SELECT COUNT(*)
        FROM product_images pi
        WHERE pi.product_id = p.id
          AND pi.url LIKE '/uploads/products/%'
    ) AS local_images_count,
    (
        SELECT COUNT(*)
        FROM product_technical_specs pts
        WHERE pts.product_id = p.id
    ) AS specs_count,
    (
        SELECT COUNT(*)
        FROM product_category_pivot pcp
        WHERE pcp.product_id = p.id
    ) AS categories_count
FROM products p
WHERE p.status = 'published'
ORDER BY
    specs_count DESC,
    local_images_count DESC,
    categories_count DESC,
    p.updated_at DESC
LIMIT 1
SQL;

$stmt = $pdo->query($sql);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    fwrite(STDOUT, "Aucun produit trouvé.\n");
    exit(0);
}

$specStmt = $pdo->prepare(
    'SELECT label, value, position
     FROM product_technical_specs
     WHERE product_id = :pid
     ORDER BY position ASC, id ASC'
);
$specStmt->execute(['pid' => (string) $product['id']]);
$specs = $specStmt->fetchAll(PDO::FETCH_ASSOC);

$imgStmt = $pdo->prepare(
    'SELECT url, alt, position
     FROM product_images
     WHERE product_id = :pid
     ORDER BY position ASC, id ASC'
);
$imgStmt->execute(['pid' => (string) $product['id']]);
$images = $imgStmt->fetchAll(PDO::FETCH_ASSOC);

$catStmt = $pdo->prepare(
    'SELECT c.id, c.slug, c.parent_id, t.name
     FROM product_category_pivot pcp
     INNER JOIN product_categories c ON c.id = pcp.category_id
     LEFT JOIN product_category_translations t
       ON t.category_id = c.id AND t.locale = "fr"
     WHERE pcp.product_id = :pid
     ORDER BY c.id ASC'
);
$catStmt->execute(['pid' => (string) $product['id']]);
$categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);

$out = [
    'product_fields' => array_keys($product),
    'product' => $product,
    'images' => $images,
    'specs' => $specs,
    'categories' => $categories,
];

fwrite(STDOUT, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);

