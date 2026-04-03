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

// Products (URL alignee sur le front: /boutique/{categorie}/{slug})
$stmt = $pdo->query(
    "SELECT p.slug, p.updated_at,
        COALESCE((
            SELECT pc.slug
            FROM product_category_pivot pcp
            INNER JOIN product_categories pc ON pc.id = pcp.category_id
            WHERE pcp.product_id = p.id
            ORDER BY pc.id ASC
            LIMIT 1
        ), 'non-classe') AS category_slug
     FROM products p
     WHERE p.status = 'published'"
);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if (!is_array($row)) {
        continue;
    }
    $cat = rawurlencode((string) ($row['category_slug'] ?? 'non-classe'));
    $slug = rawurlencode((string) $row['slug']);
    $urls[] = [
        'loc' => $baseUrl . '/boutique/' . $cat . '/' . $slug,
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
        'loc' => $baseUrl . '/boutique/' . rawurlencode((string) $row['slug']),
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

