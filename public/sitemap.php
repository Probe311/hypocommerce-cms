<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = ConnectionFactory::getConnection();
$baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');

header('Content-Type: application/xml; charset=utf-8');

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php
if ($baseUrl === '') {
    $baseUrl = 'http://localhost:8000';
}

$urls = [];

// Products
$stmt = $pdo->query('SELECT slug, updated_at FROM products WHERE status = "published"');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!is_array($row)) {
        continue;
    }
    $urls[] = [
        'loc' => $baseUrl . '/produit/' . (string) $row['slug'],
        'lastmod' => (string) ($row['updated_at'] ?? ''),
    ];
}

// Categories
$stmt = $pdo->query('SELECT slug FROM product_categories ORDER BY id');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!is_array($row)) {
        continue;
    }
    $urls[] = [
        'loc' => $baseUrl . '/boutique/categorie/' . (string) $row['slug'],
        'lastmod' => '',
    ];
}

// CMS pages
$stmt = $pdo->query('SELECT slug, updated_at FROM cms_pages WHERE status = "published" ORDER BY id');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!is_array($row)) {
        continue;
    }
    $urls[] = [
        'loc' => $baseUrl . '/pages/' . (string) $row['slug'],
        'lastmod' => (string) ($row['updated_at'] ?? ''),
    ];
}

// Blog index
$urls[] = ['loc' => $baseUrl . '/blog', 'lastmod' => ''];

// Blog articles
$stmt = $pdo->query(
    "SELECT ba.slug AS article_slug, bc.slug AS category_slug, ba.updated_at
     FROM blog_articles ba
     INNER JOIN blog_categories bc ON bc.id = ba.category_id
     WHERE ba.status = 'published'
     ORDER BY ba.id"
);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!is_array($row)) {
        continue;
    }
    $urls[] = [
        'loc' => $baseUrl . '/blog/' . (string) $row['category_slug'] . '/' . (string) $row['article_slug'],
        'lastmod' => (string) ($row['updated_at'] ?? ''),
    ];
}

foreach ($urls as $url) {
    $loc = htmlspecialchars((string) $url['loc'], ENT_QUOTES);
    $lastmod = trim((string) ($url['lastmod'] ?? ''));
    if ($lastmod !== '') {
        $lastmodXml = htmlspecialchars((new DateTimeImmutable($lastmod))->format('c'), ENT_QUOTES);
        echo "<url><loc>{$loc}</loc><lastmod>{$lastmodXml}</lastmod></url>";
        continue;
    }
    echo "<url><loc>{$loc}</loc></url>";
}
?>
</urlset>

