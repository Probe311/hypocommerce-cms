<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/check_cms_remote.php <host> <db> <user> <password>\n");
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

    $tables = [
        'cms_pages',
        'cms_page_sections',
        'blog_categories',
        'blog_articles',
        'faq_categories',
        'faq_items',
        'legal_pages',
        'media_assets',
    ];

    foreach ($tables as $table) {
        $count = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        echo $table . '=' . $count . PHP_EOL;
    }

    $dupePageSlug = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT slug FROM cms_pages GROUP BY slug HAVING COUNT(*) > 1) t')->fetchColumn();
    $dupeBlogSlug = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT category_id, slug FROM blog_articles GROUP BY category_id, slug HAVING COUNT(*) > 1) t')->fetchColumn();
    echo 'duplicate_page_slugs=' . $dupePageSlug . PHP_EOL;
    echo 'duplicate_blog_slugs=' . $dupeBlogSlug . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Check failed: ' . $e->getMessage() . "\n");
    exit(1);
}
