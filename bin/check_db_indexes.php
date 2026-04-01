<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/** @var PDO $pdo */
$pdo = pdoFromArgv($argv);

$required = [
    'products' => ['idx_products_slug', 'idx_products_status', 'idx_products_type', 'idx_products_updated_at'],
    'customers' => ['idx_customers_email'],
    'orders' => ['idx_orders_status', 'idx_orders_customer', 'idx_orders_placed_at', 'idx_orders_updated_at'],
    'cms_pages' => ['idx_cms_pages_status_updated'],
    'blog_articles' => ['idx_blog_articles_status_published'],
];

$failures = 0;
foreach ($required as $table => $indexes) {
    $stmt = $pdo->prepare(
        'SELECT DISTINCT INDEX_NAME
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);
    $existingRows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $existing = [];
    foreach ((array) $existingRows as $idx) {
        if (is_string($idx)) {
            $existing[$idx] = true;
        }
    }
    $missing = [];
    foreach ($indexes as $idx) {
        if (!isset($existing[$idx])) {
            $missing[] = $idx;
        }
    }
    if ($missing !== []) {
        $failures++;
        fwrite(STDERR, '[FAIL] ' . $table . ' missing indexes: ' . implode(', ', $missing) . PHP_EOL);
    } else {
        fwrite(STDOUT, '[OK] ' . $table . ' indexes are present' . PHP_EOL);
    }
}

if ($failures > 0) {
    fwrite(STDERR, "DB index check failed: {$failures} table(s).\n");
    exit(1);
}

fwrite(STDOUT, "DB index check passed.\n");
