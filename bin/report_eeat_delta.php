<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/report_eeat_delta.php <host> <db> <user> <password> [output_json]\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];
$outputPath = $argv[5] ?? dirname(__DIR__) . '/var/reports/eeat_delta_report.json';

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $metrics = [];
    $metrics['products_total'] = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $metrics['products_with_description'] = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE description IS NOT NULL AND TRIM(description) <> ''")->fetchColumn();
    $metrics['products_with_seo_title'] = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE seo_title IS NOT NULL AND TRIM(seo_title) <> ''")->fetchColumn();
    $metrics['products_with_seo_description'] = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE seo_description IS NOT NULL AND TRIM(seo_description) <> ''")->fetchColumn();
    $metrics['p1_products_detected'] = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE type='bundle' OR LOWER(name) REGEXP 'bundle|pack|set|starter|most wanted|box|kit'")->fetchColumn();
    $metrics['brand_pages_total'] = (int) $pdo->query("SELECT COUNT(*) FROM cms_pages WHERE template='marque' OR slug LIKE '%army-painter%' OR slug LIKE '%marque-%'")->fetchColumn();
    $metrics['brand_pages_with_eeat_positioning'] = (int) $pdo->query("SELECT COUNT(DISTINCT page_id) FROM cms_page_sections WHERE section_key='eeat_positioning'")->fetchColumn();
    $metrics['brand_pages_with_eeat_trust'] = (int) $pdo->query("SELECT COUNT(DISTINCT page_id) FROM cms_page_sections WHERE section_key='eeat_trust'")->fetchColumn();
    $metrics['cms_pages_total'] = (int) $pdo->query("SELECT COUNT(*) FROM cms_pages")->fetchColumn();
    $metrics['cms_sections_total'] = (int) $pdo->query("SELECT COUNT(*) FROM cms_page_sections")->fetchColumn();

    $sampleProducts = $pdo->query(
        "SELECT sku, name, LEFT(description, 180) AS description_preview, seo_title, seo_description
         FROM products
         WHERE type='bundle' OR LOWER(name) REGEXP 'bundle|pack|set|starter|most wanted|box|kit'
         ORDER BY updated_at DESC
         LIMIT 8"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($sampleProducts)) {
        $sampleProducts = [];
    }

    $sampleBrandPages = $pdo->query(
        "SELECT p.slug, p.title, s.section_key
         FROM cms_pages p
         LEFT JOIN cms_page_sections s ON s.page_id = p.id
         WHERE (p.template='marque' OR p.slug LIKE '%army-painter%' OR p.slug LIKE '%marque-%')
           AND s.section_key IN ('eeat_positioning','eeat_use_cases','eeat_trust')
         ORDER BY p.slug, s.section_key"
    )->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($sampleBrandPages)) {
        $sampleBrandPages = [];
    }

    $report = [
        'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        'metrics' => $metrics,
        'samples' => [
            'products' => $sampleProducts,
            'brand_pages' => $sampleBrandPages,
        ],
    ];

    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($outputPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

    fwrite(STDOUT, "Rapport EEAT genere.\n");
    foreach ($metrics as $k => $v) {
        fwrite(STDOUT, "{$k}: {$v}\n");
    }
    fwrite(STDOUT, "output: {$outputPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Echec rapport EEAT: " . $e->getMessage() . "\n");
    exit(1);
}
