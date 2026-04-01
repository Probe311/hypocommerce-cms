<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/check_seo_content_coverage.php <host> <db> <user> <password>\n");
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

    $cmsPages = (int) $pdo->query('SELECT COUNT(*) FROM cms_pages')->fetchColumn();
    $cmsSeoPages = (int) $pdo->query("SELECT COUNT(*) FROM cms_pages WHERE slug LIKE '%figurines%' OR slug LIKE '%army-painter%' OR slug LIKE '%speedpaint%' OR slug LIKE '%warpaints%'")->fetchColumn();
    $cmsSections = (int) $pdo->query('SELECT COUNT(*) FROM cms_page_sections')->fetchColumn();
    $faqSections = (int) $pdo->query("SELECT COUNT(*) FROM cms_page_sections WHERE section_key = 'faq'")->fetchColumn();
    $heroSections = (int) $pdo->query("SELECT COUNT(*) FROM cms_page_sections WHERE section_key = 'hero'")->fetchColumn();
    $productsTotal = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $productsWithDescription = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE description IS NOT NULL AND TRIM(description) <> ''")->fetchColumn();
    $productsWithoutDescription = $productsTotal - $productsWithDescription;

    echo "cms_pages={$cmsPages}\n";
    echo "cms_seo_pages_like_cluster={$cmsSeoPages}\n";
    echo "cms_page_sections={$cmsSections}\n";
    echo "cms_hero_sections={$heroSections}\n";
    echo "cms_faq_sections={$faqSections}\n";
    echo "products_total={$productsTotal}\n";
    echo "products_with_description={$productsWithDescription}\n";
    echo "products_without_description={$productsWithoutDescription}\n";

    $sample = $pdo->query(
        "SELECT p.slug, p.template, p.meta_title
         FROM cms_pages p
         WHERE p.slug LIKE '%figurines%' OR p.slug LIKE '%army-painter%' OR p.slug LIKE '%speedpaint%' OR p.slug LIKE '%warpaints%'
         ORDER BY p.slug ASC
         LIMIT 10"
    )->fetchAll(PDO::FETCH_ASSOC);
    echo "sample_seo_slugs:\n";
    foreach ($sample as $row) {
        echo "- {$row['slug']} | {$row['template']} | {$row['meta_title']}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Check failed: ' . $e->getMessage() . "\n");
    exit(1);
}
