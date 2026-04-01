<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/check_remote_products.php <host> <db> <user> <password>\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $total = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $published = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'published'")->fetchColumn();
    $withSlug = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE slug IS NOT NULL AND slug <> ''")->fetchColumn();
    $withPrice = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE price > 0')->fetchColumn();
    $sample = $pdo->query('SELECT sku, name, slug, price FROM products ORDER BY created_at DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);

    echo "total_products={$total}\n";
    echo "published_products={$published}\n";
    echo "products_with_slug={$withSlug}\n";
    echo "products_with_price={$withPrice}\n";
    echo "sample:\n";
    foreach ($sample as $row) {
        echo "- {$row['sku']} | {$row['name']} | {$row['slug']} | {$row['price']}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Check failed: " . $e->getMessage() . "\n");
    exit(1);
}
