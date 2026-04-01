<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Cache\FileCache;
use App\Infrastructure\Database\ConnectionFactory;
use PDO;

final class PdoCatalogApiRepository
{
    private PDO $pdo;
    private FileCache $cache;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
        $this->cache = new FileCache();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listProducts(string $locale = 'fr'): array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheTtl = (int) ($_ENV['CACHE_TTL_PRODUCTS'] ?? 120);
        $cached = $this->cache->get('catalog:products:published:' . $locale);
        if (is_array($cached)) {
            return $cached;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                p.id,
                p.slug,
                COALESCE(pt.name, p.name) AS name,
                COALESCE(pt.description, p.description) AS description,
                p.price,
                p.status,
                p.type,
                COALESCE(pt.seo_title, p.seo_title) AS seo_title,
                COALESCE(pt.seo_description, p.seo_description) AS seo_description,
                COALESCE((
                    SELECT pc.slug
                    FROM product_category_pivot pcp
                    INNER JOIN product_categories pc ON pc.id = pcp.category_id
                    WHERE pcp.product_id = p.id
                    ORDER BY pc.id ASC
                    LIMIT 1
                ), 'non-classe') AS category_slug,
                COALESCE((
                    SELECT COALESCE(pct.name, pc.name)
                    FROM product_category_pivot pcp
                    INNER JOIN product_categories pc ON pc.id = pcp.category_id
                    LEFT JOIN product_category_translations pct ON pct.category_id = pc.id AND pct.locale = :locale
                    WHERE pcp.product_id = p.id
                    ORDER BY pc.id ASC
                    LIMIT 1
                ), 'Non classe') AS category_name,
                (
                    SELECT pi.url
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.position ASC, pi.id ASC
                    LIMIT 1
                ) AS image_url
            FROM products p
            LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.locale = :locale
            WHERE p.status = 'published'
            ORDER BY p.updated_at DESC"
        );
        $stmt->execute(['locale' => $locale]);

        $rows = [];
        while ($row = $stmt->fetch()) {
            $rows[] = $this->mapProductRow($row);
        }

        $this->cache->set('catalog:products:published:' . $locale, $rows, $cacheTtl);

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findProductBySlug(string $slug, string $locale = 'fr'): ?array
    {
        $locale = $this->normalizeLocale($locale);
        $stmt = $this->pdo->prepare(
            "SELECT
                p.id,
                p.slug,
                COALESCE(pt.name, p.name) AS name,
                COALESCE(pt.description, p.description) AS description,
                p.price,
                p.status,
                p.type,
                COALESCE(pt.seo_title, p.seo_title) AS seo_title,
                COALESCE(pt.seo_description, p.seo_description) AS seo_description,
                COALESCE((
                    SELECT pc.slug
                    FROM product_category_pivot pcp
                    INNER JOIN product_categories pc ON pc.id = pcp.category_id
                    WHERE pcp.product_id = p.id
                    ORDER BY pc.id ASC
                    LIMIT 1
                ), 'non-classe') AS category_slug,
                COALESCE((
                    SELECT COALESCE(pct.name, pc.name)
                    FROM product_category_pivot pcp
                    INNER JOIN product_categories pc ON pc.id = pcp.category_id
                    LEFT JOIN product_category_translations pct ON pct.category_id = pc.id AND pct.locale = :locale
                    WHERE pcp.product_id = p.id
                    ORDER BY pc.id ASC
                    LIMIT 1
                ), 'Non classe') AS category_name,
                (
                    SELECT pi.url
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.position ASC, pi.id ASC
                    LIMIT 1
                ) AS image_url
            FROM products p
            LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.locale = :locale
            WHERE p.status = 'published' AND p.slug = :slug
            LIMIT 1"
        );
        $stmt->execute(['slug' => $slug, 'locale' => $locale]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->mapProductRow($row);
    }

    /**
     * @return array<int,array{slug:string,name:string,description:string}>
     */
    public function listCategories(string $locale = 'fr'): array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheTtl = (int) ($_ENV['CACHE_TTL_CATEGORIES'] ?? 300);
        $cached = $this->cache->get('catalog:categories:published:' . $locale);
        if (is_array($cached)) {
            return $cached;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                pc.slug,
                COALESCE(pct.name, pc.name) AS name,
                COALESCE(pct.description, CONCAT('Produits de la categorie ', COALESCE(pct.name, pc.name), '.')) AS description
            FROM product_categories pc
            LEFT JOIN product_category_translations pct ON pct.category_id = pc.id AND pct.locale = :locale
            INNER JOIN product_category_pivot pcp ON pcp.category_id = pc.id
            INNER JOIN products p ON p.id = pcp.product_id AND p.status = 'published'
            GROUP BY pc.id, pc.slug, pc.name, pct.name, pct.description
            ORDER BY pc.name ASC"
        );
        $stmt->execute(['locale' => $locale]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows) && count($rows) > 0) {
            $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
            $result = array_map(static function (array $row) use ($baseUrl): array {
                $slug = (string) $row['slug'];
                $row['canonicalUrl'] = $baseUrl !== '' ? $baseUrl . '/boutique/categorie/' . $slug : null;
                return $row;
            }, $rows);
            $this->cache->set('catalog:categories:published:' . $locale, $result, $cacheTtl);
            return $result;
        }

        $fallback = [[
            'slug' => 'non-classe',
            'name' => 'Non classe',
            'description' => 'Produits sans categorie assignee.',
            'canonicalUrl' => rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/boutique/categorie/non-classe',
        ]];
        $this->cache->set('catalog:categories:published:' . $locale, $fallback, $cacheTtl);
        return $fallback;
    }

    /**
     * @return array<int,array{slug:string,name:string,description:string}>
     */
    public function listBrands(): array
    {
        $products = $this->listProducts();
        $brands = [];

        foreach ($products as $product) {
            $slug = (string) ($product['brandSlug'] ?? '');
            if ($slug === '' || isset($brands[$slug])) {
                continue;
            }

            $brands[$slug] = [
                'slug' => $slug,
                'name' => (string) ($product['brandName'] ?? $slug),
                'description' => 'Produits de la marque ' . (string) ($product['brandName'] ?? $slug) . '.',
            ];
        }

        ksort($brands);

        return array_values($brands);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapProductRow(array $row): array
    {
        $brandSlug = $this->inferBrandSlug((string) $row['slug']);
        $brandName = $this->slugToLabel($brandSlug);
        $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');

        return [
            'slug' => (string) $row['slug'],
            'canonicalUrl' => $baseUrl !== '' ? $baseUrl . '/produit/' . (string) $row['slug'] : null,
            'name' => (string) $row['name'],
            'description' => trim((string) ($row['description'] ?? '')) !== '' ? (string) $row['description'] : 'Description a venir.',
            'price' => (float) $row['price'],
            'priceCurrency' => 'EUR',
            'categorySlug' => (string) $row['category_slug'],
            'categoryName' => (string) $row['category_name'],
            'brandSlug' => $brandSlug,
            'brandName' => $brandName,
            'imageUrl' => $row['image_url'] !== null ? (string) $row['image_url'] : null,
            'status' => (string) $row['status'],
            'type' => (string) $row['type'],
            'seoTitle' => $row['seo_title'] !== null ? (string) $row['seo_title'] : null,
            'seoDescription' => $row['seo_description'] !== null ? (string) $row['seo_description'] : null,
            'schemaOrgProduct' => [
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => (string) $row['name'],
                'sku' => (string) ($row['id'] ?? ''),
                'description' => trim((string) ($row['description'] ?? '')) !== '' ? (string) $row['description'] : null,
                'category' => (string) $row['category_name'],
                'image' => $row['image_url'] !== null ? [(string) $row['image_url']] : [],
                'offers' => [
                    '@type' => 'Offer',
                    'priceCurrency' => 'EUR',
                    'price' => (float) $row['price'],
                    'url' => $baseUrl !== '' ? $baseUrl . '/produit/' . (string) $row['slug'] : null,
                    'availability' => ((string) $row['status']) === 'published'
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                ],
            ],
        ];
    }

    private function inferBrandSlug(string $productSlug): string
    {
        $known = $this->knownBrandSlugs();

        foreach ($known as $brandSlug) {
            if ($productSlug === $brandSlug || str_starts_with($productSlug, $brandSlug . '-')) {
                return $brandSlug;
            }
        }

        $firstToken = explode('-', $productSlug)[0] ?? '';
        $firstToken = strtolower(trim($firstToken));

        return $firstToken !== '' ? $firstToken : 'marque-generique';
    }

    /**
     * @return array<int,string>
     */
    private function knownBrandSlugs(): array
    {
        $stmt = $this->pdo->query(
            "SELECT slug
            FROM cms_pages
            WHERE status = 'published' AND template = 'marque' AND slug LIKE 'marque-%'"
        );

        $slugs = [];
        while ($row = $stmt->fetch()) {
            $pageSlug = (string) ($row['slug'] ?? '');
            $brandSlug = trim(substr($pageSlug, strlen('marque-')));
            if ($brandSlug !== '') {
                $slugs[] = strtolower($brandSlug);
            }
        }

        usort($slugs, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $slugs;
    }

    private function slugToLabel(string $slug): string
    {
        $label = str_replace('-', ' ', trim($slug));
        if ($label === '') {
            return 'Marque';
        }

        return ucwords(strtolower($label));
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if ($locale === '') {
            return 'fr';
        }
        return substr($locale, 0, 8);
    }
}
