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
    public function listProducts(string $locale = 'fr', array $filters = []): array
    {
        $locale = $this->normalizeLocale($locale);
        $category = $this->normalizeSlug((string) ($filters['category'] ?? ''));
        $brand = $this->normalizeSlug((string) ($filters['brand'] ?? ''));
        $tag = $this->normalizeSlug((string) ($filters['tag'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));
        $sort = (string) ($filters['sort'] ?? 'featured');
        $limit = $this->normalizePositiveInt($filters['limit'] ?? null, 48);
        $offset = $this->normalizePositiveInt($filters['offset'] ?? null, 0);

        $cacheTtl = (int) ($_ENV['CACHE_TTL_PRODUCTS'] ?? 120);
        $cacheKey = sprintf(
            'catalog:products:published:%s:%s:%s:%s:%s:%s:%d:%d',
            $locale,
            $category,
            $brand,
            $tag,
            md5($search),
            $sort,
            $limit,
            $offset
        );
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $params = [
            'locale_pt' => $locale,
            'locale_pct' => $locale,
            'locale_mainct' => $locale,
            'locale_subct' => $locale,
        ];
        $where = ["p.status = 'published'"];

        if ($category !== '') {
            $categoryIds = $this->getDescendantCategoryIds($category);
            if ($categoryIds === []) {
                $where[] = '1=0';
            } else {
                $inParams = [];
                foreach ($categoryIds as $i => $id) {
                    $key = 'cat_id_' . $i;
                    $inParams[] = ':' . $key;
                    $params[$key] = $id;
                }
                $where[] = 'pc.id IN (' . implode(', ', $inParams) . ')';
            }
        }
        if ($brand !== '') {
            $where[] = 'b.slug = :brand';
            $params['brand'] = $brand;
        }
        if ($tag !== '') {
            $where[] = 'ptag.slug = :tag';
            $params['tag'] = $tag;
        }
        if ($search !== '') {
            $where[] = '(COALESCE(pt.name, p.name) LIKE :search OR COALESCE(pt.description, p.description) LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $orderBy = match ($sort) {
            'price-asc' => 'p.price ASC, p.updated_at DESC',
            'price-desc' => 'p.price DESC, p.updated_at DESC',
            'name' => 'COALESCE(pt.name, p.name) ASC',
            default => 'p.updated_at DESC',
        };

        $sql = "SELECT
                p.id,
                p.slug,
                p.sku,
                COALESCE(pt.name, p.normalized_name_fr, p.name) AS name,
                COALESCE(pt.description, p.description) AS description,
                p.price,
                p.sale_price,
                p.status,
                p.type,
                COALESCE(pt.seo_title, p.seo_title) AS seo_title,
                COALESCE(pt.seo_description, p.seo_description) AS seo_description,
                p.gtin,
                p.mpn,
                p.normalized_color,
                p.editorial_author,
                p.editorial_reviewer,
                p.reviewed_at,
                pc.slug AS category_slug,
                COALESCE(pct.name, pc.name) AS category_name,
                COALESCE(b.slug, 'marque-generique') AS brand_slug,
                COALESCE(b.name, 'Marque') AS brand_name,
                mainc.slug AS main_category_slug,
                COALESCE(mainct.name, mainc.name) AS main_category_name,
                subc.slug AS sub_category_slug,
                COALESCE(subct.name, subc.name) AS sub_category_name,
                GROUP_CONCAT(DISTINCT ptag.slug ORDER BY ptag.slug SEPARATOR '|') AS tag_slugs,
                GROUP_CONCAT(DISTINCT ptag.name ORDER BY ptag.slug SEPARATOR '|') AS tag_names,
                GROUP_CONCAT(DISTINCT ptag.type ORDER BY ptag.slug SEPARATOR '|') AS tag_types,
                (
                    SELECT pi.url
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.position ASC, pi.id ASC
                    LIMIT 1
                ) AS image_url
                ,
                (
                    SELECT pi.alt
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.position ASC, pi.id ASC
                    LIMIT 1
                ) AS image_alt
                ,
                (
                    SELECT IFNULL(
                        CONCAT('[', GROUP_CONCAT(JSON_QUOTE(t.url) ORDER BY t.p ASC, t.iid ASC SEPARATOR ','), ']'),
                        '[]'
                    )
                    FROM (
                        SELECT pi.url AS url, pi.position AS p, pi.id AS iid
                        FROM product_images pi
                        WHERE pi.product_id = p.id
                        ORDER BY pi.position ASC, pi.id ASC
                        LIMIT 4
                    ) AS t
                ) AS image_urls_json
                ,
                (
                    SELECT COALESCE(
                        JSON_ARRAYAGG(
                            JSON_OBJECT('label', ts.label, 'value', ts.value)
                            ORDER BY ts.position ASC, ts.id ASC
                        ),
                        JSON_ARRAY()
                    )
                    FROM product_technical_specs ts
                    WHERE ts.product_id = p.id
                ) AS specs_json
            FROM products p
            LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.locale = :locale_pt
            LEFT JOIN product_category_pivot pcp ON pcp.product_id = p.id
            LEFT JOIN product_categories pc ON pc.id = pcp.category_id
            LEFT JOIN product_category_translations pct ON pct.category_id = pc.id AND pct.locale = :locale_pct
            LEFT JOIN product_brand_pivot pbp ON pbp.product_id = p.id
            LEFT JOIN brands b ON b.id = pbp.brand_id
            LEFT JOIN product_categories mainc ON mainc.id = p.main_category_id
            LEFT JOIN product_category_translations mainct ON mainct.category_id = mainc.id AND mainct.locale = :locale_mainct
            LEFT JOIN product_categories subc ON subc.id = p.sub_category_id
            LEFT JOIN product_category_translations subct ON subct.category_id = subc.id AND subct.locale = :locale_subct
            LEFT JOIN product_tag_pivot ptp ON ptp.product_id = p.id
            LEFT JOIN product_tags ptag ON ptag.id = ptp.tag_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY p.id, p.slug, p.sku, pt.name, p.normalized_name_fr, p.name, pt.description, p.description, p.price, p.sale_price, p.status, p.type, pt.seo_title, p.seo_title, pt.seo_description, p.seo_description, p.gtin, p.mpn, p.normalized_color, p.editorial_author, p.editorial_reviewer, p.reviewed_at, pc.slug, pct.name, pc.name, b.slug, b.name, mainc.slug, mainct.name, mainc.name, subc.slug, subct.name, subc.name
            ORDER BY " . $orderBy . "
            LIMIT " . (int) $limit . " OFFSET " . (int) $offset;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        while ($row = $stmt->fetch()) {
            $rows[] = $this->mapProductRow($row);
        }

        $this->cache->set($cacheKey, $rows, $cacheTtl);

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
                p.sku,
                COALESCE(pt.name, p.normalized_name_fr, p.name) AS name,
                COALESCE(pt.description, p.description) AS description,
                p.price,
                p.sale_price,
                p.status,
                p.type,
                COALESCE(pt.seo_title, p.seo_title) AS seo_title,
                COALESCE(pt.seo_description, p.seo_description) AS seo_description,
                p.gtin,
                p.mpn,
                p.normalized_color,
                p.editorial_author,
                p.editorial_reviewer,
                p.reviewed_at,
                (
                    SELECT mc.slug
                    FROM product_categories mc
                    WHERE mc.id = p.main_category_id
                    LIMIT 1
                ) AS main_category_slug,
                (
                    SELECT COALESCE(mct.name, mc.name)
                    FROM product_categories mc
                    LEFT JOIN product_category_translations mct ON mct.category_id = mc.id AND mct.locale = :locale_mct
                    WHERE mc.id = p.main_category_id
                    LIMIT 1
                ) AS main_category_name,
                (
                    SELECT sc.slug
                    FROM product_categories sc
                    WHERE sc.id = p.sub_category_id
                    LIMIT 1
                ) AS sub_category_slug,
                (
                    SELECT COALESCE(sct.name, sc.name)
                    FROM product_categories sc
                    LEFT JOIN product_category_translations sct ON sct.category_id = sc.id AND sct.locale = :locale_sct
                    WHERE sc.id = p.sub_category_id
                    LIMIT 1
                ) AS sub_category_name,
                (
                    SELECT pc.slug
                    FROM product_category_pivot pcp
                    INNER JOIN product_categories pc ON pc.id = pcp.category_id
                    WHERE pcp.product_id = p.id
                    ORDER BY pc.id ASC
                    LIMIT 1
                ) AS category_slug,
                (
                    SELECT COALESCE(pct.name, pc.name)
                    FROM product_category_pivot pcp
                    INNER JOIN product_categories pc ON pc.id = pcp.category_id
                    LEFT JOIN product_category_translations pct ON pct.category_id = pc.id AND pct.locale = :locale_pct2
                    WHERE pcp.product_id = p.id
                    ORDER BY pc.id ASC
                    LIMIT 1
                ) AS category_name,
                COALESCE((
                    SELECT b.slug
                    FROM product_brand_pivot pbp
                    INNER JOIN brands b ON b.id = pbp.brand_id
                    WHERE pbp.product_id = p.id
                    ORDER BY b.id ASC
                    LIMIT 1
                ), 'marque-generique') AS brand_slug,
                COALESCE((
                    SELECT b.name
                    FROM product_brand_pivot pbp
                    INNER JOIN brands b ON b.id = pbp.brand_id
                    WHERE pbp.product_id = p.id
                    ORDER BY b.id ASC
                    LIMIT 1
                ), 'Marque') AS brand_name,
                (
                    SELECT GROUP_CONCAT(ptag.slug ORDER BY ptag.slug SEPARATOR '|')
                    FROM product_tag_pivot ptp
                    INNER JOIN product_tags ptag ON ptag.id = ptp.tag_id
                    WHERE ptp.product_id = p.id
                ) AS tag_slugs,
                (
                    SELECT GROUP_CONCAT(ptag.name ORDER BY ptag.slug SEPARATOR '|')
                    FROM product_tag_pivot ptp
                    INNER JOIN product_tags ptag ON ptag.id = ptp.tag_id
                    WHERE ptp.product_id = p.id
                ) AS tag_names,
                (
                    SELECT GROUP_CONCAT(ptag.type ORDER BY ptag.slug SEPARATOR '|')
                    FROM product_tag_pivot ptp
                    INNER JOIN product_tags ptag ON ptag.id = ptp.tag_id
                    WHERE ptp.product_id = p.id
                ) AS tag_types,
                (
                    SELECT pi.url
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.position ASC, pi.id ASC
                    LIMIT 1
                ) AS image_url
                ,
                (
                    SELECT pi.alt
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    ORDER BY pi.position ASC, pi.id ASC
                    LIMIT 1
                ) AS image_alt
                ,
                (
                    SELECT IFNULL(
                        CONCAT('[', GROUP_CONCAT(JSON_QUOTE(t.url) ORDER BY t.p ASC, t.iid ASC SEPARATOR ','), ']'),
                        '[]'
                    )
                    FROM (
                        SELECT pi.url AS url, pi.position AS p, pi.id AS iid
                        FROM product_images pi
                        WHERE pi.product_id = p.id
                        ORDER BY pi.position ASC, pi.id ASC
                        LIMIT 4
                    ) AS t
                ) AS image_urls_json
                ,
                (
                    SELECT COALESCE(
                        JSON_ARRAYAGG(
                            JSON_OBJECT('label', ts.label, 'value', ts.value)
                            ORDER BY ts.position ASC, ts.id ASC
                        ),
                        JSON_ARRAY()
                    )
                    FROM product_technical_specs ts
                    WHERE ts.product_id = p.id
                ) AS specs_json
            FROM products p
            LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.locale = :locale_pt
            WHERE p.status = 'published' AND p.slug = :slug
            LIMIT 1"
        );
        $stmt->execute([
            'slug' => $slug,
            'locale_pt' => $locale,
            'locale_mct' => $locale,
            'locale_sct' => $locale,
            'locale_pct2' => $locale,
        ]);
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
                COALESCE(pct.description, CONCAT('Produits de la categorie ', COALESCE(pct.name, pc.name), '.')) AS description,
                COUNT(DISTINCT p.id) AS product_count
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
                $productCount = (int) ($row['product_count'] ?? 0);
                $row['canonicalUrl'] = $baseUrl !== '' ? $baseUrl . '/boutique/' . $slug : null;
                $row['productCount'] = $productCount;
                return $row;
            }, $rows);
            $this->cache->set('catalog:categories:published:' . $locale, $result, $cacheTtl);
            return $result;
        }

        $fallback = [[
            'slug' => 'non-classe',
            'name' => 'Non classe',
            'description' => 'Produits sans categorie assignee.',
            'canonicalUrl' => rtrim((string) ($_ENV['APP_URL'] ?? ''), '/') . '/boutique/non-classe',
            'productCount' => 0,
        ]];
        $this->cache->set('catalog:categories:published:' . $locale, $fallback, $cacheTtl);
        return $fallback;
    }

    /**
     * Grandes familles (racines) : Peintures, Basing & décors, etc. — comptage via main_category_id.
     *
     * @return array<int,array{slug:string,name:string,description:string,productCount?:int,canonicalUrl?:string|null}>
     */
    public function listCategoryFamilies(string $locale = 'fr'): array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheTtl = (int) ($_ENV['CACHE_TTL_CATEGORIES'] ?? 300);
        $cacheKey = 'catalog:categories:families:published:' . $locale;
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                pc.slug,
                COALESCE(pct.name, pc.name) AS name,
                COALESCE(pct.description, CONCAT('Produits de la famille ', COALESCE(pct.name, pc.name), '.')) AS description,
                COUNT(DISTINCT p.id) AS product_count
            FROM product_categories pc
            LEFT JOIN product_category_translations pct ON pct.category_id = pc.id AND pct.locale = :locale
            INNER JOIN products p ON p.main_category_id = pc.id AND p.status = 'published'
            WHERE pc.parent_id IS NULL
            GROUP BY pc.id, pc.slug, pc.name, pct.name, pct.description
            HAVING product_count > 0
            ORDER BY pc.name ASC"
        );
        $stmt->execute(['locale' => $locale]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($rows) || count($rows) === 0) {
            $this->cache->set($cacheKey, [], $cacheTtl);

            return [];
        }

        $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        $result = array_map(static function (array $row) use ($baseUrl): array {
            $slug = (string) $row['slug'];
            $productCount = (int) ($row['product_count'] ?? 0);

            return [
                'slug' => $slug,
                'name' => (string) ($row['name'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'canonicalUrl' => $baseUrl !== '' ? $baseUrl . '/boutique/' . $slug : null,
                'productCount' => $productCount,
            ];
        }, $rows);
        $this->cache->set($cacheKey, $result, $cacheTtl);

        return $result;
    }

    /**
     * @return array<int,array{slug:string,name:string,description:string}>
     */
    public function listBrands(string $locale = 'fr'): array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheTtl = (int) ($_ENV['CACHE_TTL_BRANDS'] ?? 300);
        $cacheKey = 'catalog:brands:published:' . $locale;
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $stmt = $this->pdo->query(
            "SELECT
                b.slug,
                b.name,
                COALESCE(NULLIF(b.description, ''), CONCAT('Produits de la marque ', b.name, '.')) AS description,
                b.logo_url,
                b.favicon_url,
                COUNT(DISTINCT p.id) AS product_count
            FROM brands b
            INNER JOIN product_brand_pivot pbp ON pbp.brand_id = b.id
            INNER JOIN products p ON p.id = pbp.product_id AND p.status = 'published'
            GROUP BY b.id, b.slug, b.name, b.description, b.logo_url, b.favicon_url
            HAVING product_count > 0
            ORDER BY b.name ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = is_array($rows) ? array_map(static function (array $row): array {
            $logoUrl = $row['logo_url'] ?? null;
            $faviconUrl = $row['favicon_url'] ?? null;
            $productCount = (int) ($row['product_count'] ?? 0);
            $row['logoUrl'] = is_string($logoUrl) ? $logoUrl : null;
            $row['faviconUrl'] = is_string($faviconUrl) ? $faviconUrl : null;
            $row['productCount'] = $productCount;
            return $row;
        }, $rows) : [];
        $this->cache->set($cacheKey, $result, $cacheTtl);
        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function listNavigation(string $locale = 'fr'): array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheTtl = (int) ($_ENV['CACHE_TTL_CATEGORIES'] ?? 300);
        $cacheKey = 'catalog:navigation:published:' . $locale;
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $stmt = $this->pdo->prepare(
            "SELECT
                pc.id,
                pc.parent_id,
                pc.slug,
                COALESCE(pct.name, pc.name) AS name,
                COUNT(DISTINCT p.id) AS product_count
            FROM product_categories pc
            LEFT JOIN product_category_translations pct ON pct.category_id = pc.id AND pct.locale = :locale
            INNER JOIN product_category_pivot pcp ON pcp.category_id = pc.id
            INNER JOIN products p ON p.id = pcp.product_id AND p.status = 'published'
            GROUP BY pc.id, pc.parent_id, pc.slug, pc.name, pct.name
            HAVING product_count > 0
            ORDER BY name ASC"
        );
        $stmt->execute(['locale' => $locale]);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($categories)) {
            $categories = [];
        }

        $byId = [];
        foreach ($categories as $category) {
            $id = (int) ($category['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $byId[$id] = [
                'slug' => (string) ($category['slug'] ?? ''),
                'name' => (string) ($category['name'] ?? ''),
                'productCount' => (int) ($category['product_count'] ?? 0),
                'childIds' => [],
            ];
        }

        foreach ($categories as $category) {
            $id = (int) ($category['id'] ?? 0);
            $parentId = isset($category['parent_id']) ? (int) $category['parent_id'] : null;
            if ($id <= 0 || $parentId === null || !isset($byId[$id], $byId[$parentId])) {
                continue;
            }
            $byId[$parentId]['childIds'][] = $id;
        }

        $buildNavTree = static function (int $id) use (&$buildNavTree, &$byId): array {
            $node = $byId[$id];
            $children = [];
            foreach ($node['childIds'] as $childId) {
                if (isset($byId[$childId])) {
                    $children[] = $buildNavTree($childId);
                }
            }
            usort($children, static function (array $a, array $b): int {
                return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
            });

            return [
                'slug' => (string) $node['slug'],
                'name' => (string) $node['name'],
                'productCount' => (int) $node['productCount'],
                'children' => $children,
            ];
        };

        $rootCategories = [];
        foreach ($categories as $category) {
            $id = (int) ($category['id'] ?? 0);
            $parentId = isset($category['parent_id']) ? (int) $category['parent_id'] : null;
            if ($id <= 0 || $parentId !== null || !isset($byId[$id])) {
                continue;
            }
            $rootCategories[] = $buildNavTree($id);
        }
        usort($rootCategories, static function (array $a, array $b): int {
            return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        $tagStmt = $this->pdo->prepare(
            "SELECT
                pc.slug AS category_slug,
                ptag.slug,
                ptag.name,
                ptag.type,
                COUNT(DISTINCT p.id) AS product_count
            FROM product_categories pc
            INNER JOIN product_category_pivot pcp ON pcp.category_id = pc.id
            INNER JOIN products p ON p.id = pcp.product_id AND p.status = 'published'
            INNER JOIN product_tag_pivot ptp ON ptp.product_id = p.id
            INNER JOIN product_tags ptag ON ptag.id = ptp.tag_id
            GROUP BY pc.slug, ptag.id, ptag.slug, ptag.name, ptag.type
            HAVING product_count > 0
            ORDER BY ptag.type ASC, ptag.name ASC"
        );
        $tagStmt->execute();
        $tagRows = $tagStmt->fetchAll(PDO::FETCH_ASSOC);

        $tagsByCategory = [];
        if (is_array($tagRows)) {
            foreach ($tagRows as $row) {
                $categorySlug = (string) ($row['category_slug'] ?? '');
                if ($categorySlug === '') {
                    continue;
                }
                if (!isset($tagsByCategory[$categorySlug])) {
                    $tagsByCategory[$categorySlug] = [];
                }
                $tagsByCategory[$categorySlug][] = [
                    'slug' => (string) ($row['slug'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'type' => self::normalizeProductTagType((string) ($row['type'] ?? '')),
                    'productCount' => (int) ($row['product_count'] ?? 0),
                ];
            }
        }

        $result = [
            'categories' => array_map(static function (array $category) use ($tagsByCategory): array {
                $slug = (string) ($category['slug'] ?? '');
                return [
                    'slug' => $slug,
                    'name' => (string) ($category['name'] ?? ''),
                    'productCount' => (int) ($category['productCount'] ?? 0),
                    'children' => $category['children'],
                    'tags' => $tagsByCategory[$slug] ?? [],
                ];
            }, $rootCategories),
        ];

        $this->cache->set($cacheKey, $result, $cacheTtl);

        return $result;
    }

    /**
     * Retourne les biomes principaux (top 6) à partir des catégories liées aux produits.
     *
     * Les biomes sont identifiés par une liste de slugs attendus (issus du configurateur),
     * puis triés par nombre de produits publiés.
     *
     * @return array<int,array{slug:string,name:string,productCount:int}>
     */
    public function listBiomes(string $locale = 'fr'): array
    {
        $locale = $this->normalizeLocale($locale);
        $cacheTtl = (int) ($_ENV['CACHE_TTL_CATEGORIES'] ?? 300);
        $cacheKey = 'catalog:biomes:published:' . $locale;
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $sql = "SELECT
                    pc.slug,
                    COALESCE(pct.name, pc.name) AS name,
                    COUNT(DISTINCT p.id) AS product_count
                FROM product_categories pc
                INNER JOIN product_categories bio_root ON bio_root.slug = 'basing-decors-biomes' AND pc.parent_id = bio_root.id
                LEFT JOIN product_category_translations pct ON pct.category_id = pc.id AND pct.locale = :locale
                INNER JOIN product_category_pivot pcp ON pcp.category_id = pc.id
                INNER JOIN products p ON p.id = pcp.product_id AND p.status = 'published'
                GROUP BY pc.id, pc.slug, pc.name, pct.name
                HAVING product_count > 0
                ORDER BY product_count DESC, name ASC
                LIMIT 6";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['locale' => $locale]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = is_array($rows)
            ? array_values(array_map(static function (array $row): array {
                return [
                    'slug' => (string) ($row['slug'] ?? ''),
                    'name' => (string) ($row['name'] ?? ''),
                    'productCount' => (int) ($row['product_count'] ?? 0),
                ];
            }, $rows))
            : [];

        $this->cache->set($cacheKey, $result, $cacheTtl);
        return $result;
    }

    /**
     * @return array{slug:string,name:string,children:array<int,mixed>}|array{}
     */
    public function listPaintsTree(string $locale = 'fr'): array
    {
        $tree = $this->listCategoryTreeByRootSlug($locale, 'peintures');
        if ($tree !== null) {
            return $tree;
        }

        return $this->listCategoryTreeByNeedles($locale, ['peintur', 'paint']) ?? [];
    }

    /**
     * @return array{slug:string,name:string,children:array<int,mixed>}|array{}
     */
    public function listBasingTree(string $locale = 'fr'): array
    {
        $tree = $this->listCategoryTreeByRootSlug($locale, 'basing-decors');
        if ($tree !== null) {
            return $tree;
        }

        return $this->listCategoryTreeByNeedles($locale, ['basing', 'soclag', 'decor']) ?? [];
    }

    /**
     * @return array<int,array{slug:string,name:string,type:string}>
     */
    public function listPopularTags(string $locale = 'fr', int $limit = 8): array
    {
        $locale = $this->normalizeLocale($locale);
        $safeLimit = max(1, min(20, $limit));
        $cacheTtl = (int) ($_ENV['CACHE_TTL_CATEGORIES'] ?? 300);
        $cacheKey = sprintf('catalog:tags:popular:%s:%d', $locale, $safeLimit);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $sql = "SELECT
                    ptag.slug,
                    ptag.name,
                    ptag.type,
                    COUNT(DISTINCT p.id) AS product_count
                FROM product_tags ptag
                INNER JOIN product_tag_pivot ptp ON ptp.tag_id = ptag.id
                INNER JOIN products p ON p.id = ptp.product_id AND p.status = 'published'
                GROUP BY ptag.id, ptag.slug, ptag.name, ptag.type
                HAVING product_count > 0
                ORDER BY product_count DESC, ptag.name ASC
                LIMIT " . (int) $safeLimit;

        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = is_array($rows)
            ? array_values(array_filter(array_map(static function (array $row): ?array {
                $slug = trim((string) ($row['slug'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));
                if ($slug === '' || $name === '') {
                    return null;
                }
                return [
                    'slug' => $slug,
                    'name' => $name,
                    'type' => self::normalizeProductTagType((string) ($row['type'] ?? 'autre')),
                ];
            }, $rows)))
            : [];

        $this->cache->set($cacheKey, $result, $cacheTtl);
        return $result;
    }

    /**
     * @return array<int,array{slug:string,name:string,price:float,salePrice:float|null,imageUrl:string|null}>
     */
    public function listNewArrivals(string $locale = 'fr', int $limit = 6): array
    {
        $locale = $this->normalizeLocale($locale);
        $safeLimit = max(1, min(20, $limit));
        $cacheTtl = (int) ($_ENV['CACHE_TTL_PRODUCTS'] ?? 120);
        $cacheKey = sprintf('catalog:new-arrivals:%s:%d', $locale, $safeLimit);
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $sql = "SELECT
                    p.slug,
                    COALESCE(pt.name, p.name) AS name,
                    p.price,
                    p.sale_price,
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
                ORDER BY COALESCE(p.reviewed_at, p.updated_at, p.created_at) DESC, p.id DESC
                LIMIT " . (int) $safeLimit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['locale' => $locale]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = is_array($rows)
            ? array_values(array_filter(array_map(function (array $row): ?array {
                $slug = trim((string) ($row['slug'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));
                if ($slug === '' || $name === '') {
                    return null;
                }
                return [
                    'slug' => $slug,
                    'name' => $name,
                    'price' => (float) ($row['price'] ?? 0),
                    'salePrice' => isset($row['sale_price']) && $row['sale_price'] !== null ? (float) $row['sale_price'] : null,
                    'imageUrl' => $this->resolveMediaUrl($row['image_url'] ?? null),
                ];
            }, $rows)))
            : [];

        $this->cache->set($cacheKey, $result, $cacheTtl);
        return $result;
    }

    /**
     * Unifie les anciens types « catégorie » stockés à tort pour des facettes marque.
     */
    private static function normalizeProductTagType(string $type): string
    {
        $t = mb_strtolower(trim($type), 'UTF-8');
        if ($t === '') {
            return 'autre';
        }

        return match ($t) {
            'categorie', 'catégorie', 'category', 'categories', 'catégories' => 'marque',
            default => trim($type) !== '' ? trim($type) : 'autre',
        };
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapProductRow(array $row): array
    {
        $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        $tagSlugs = array_filter(explode('|', (string) ($row['tag_slugs'] ?? '')));
        $tagNames = explode('|', (string) ($row['tag_names'] ?? ''));
        $tagTypes = explode('|', (string) ($row['tag_types'] ?? ''));
        $tags = [];
        foreach ($tagSlugs as $index => $slug) {
            $tags[] = [
                'slug' => $slug,
                'name' => (string) ($tagNames[$index] ?? $slug),
                'type' => self::normalizeProductTagType((string) ($tagTypes[$index] ?? 'autre')),
            ];
        }

        $imageUrl = $this->resolveMediaUrl($row['image_url'] ?? null);
        $imageUrls = $this->resolveImageUrlsList($row['image_urls_json'] ?? null);
        if ($imageUrl === null && $imageUrls !== []) {
            $imageUrl = $imageUrls[0];
        }

        $specs = [];
        $specsJson = $row['specs_json'] ?? null;
        if (is_string($specsJson) && $specsJson !== '') {
            /** @var mixed $decodedSpecs */
            $decodedSpecs = json_decode($specsJson, true);
            if (is_array($decodedSpecs)) {
                $specs = array_values(array_filter(array_map(static function (mixed $s): ?array {
                    if (!is_array($s)) {
                        return null;
                    }
                    $label = isset($s['label']) ? trim((string) $s['label']) : '';
                    $value = isset($s['value']) ? trim((string) $s['value']) : '';
                    if ($label === '' || $value === '') {
                        return null;
                    }
                    return ['label' => $label, 'value' => $value];
                }, $decodedSpecs)));
            }
        }

        $sku = trim((string) ($row['sku'] ?? ''));
        $schemaImages = $imageUrls !== [] ? $imageUrls : ($imageUrl !== null ? [$imageUrl] : []);

        return [
            'id' => (string) $row['id'],
            'slug' => (string) $row['slug'],
            'sku' => $sku !== '' ? $sku : null,
            'canonicalUrl' => $baseUrl !== '' ? $baseUrl . '/boutique/' . rawurlencode((string) ($row['category_slug'] ?? 'non-classe')) . '/' . rawurlencode((string) $row['slug']) : null,
            'name' => (string) $row['name'],
            'description' => trim((string) ($row['description'] ?? '')) !== '' ? (string) $row['description'] : 'Description a venir.',
            'price' => (float) $row['price'],
            'salePrice' => isset($row['sale_price']) && $row['sale_price'] !== null ? (float) $row['sale_price'] : null,
            'priceCurrency' => 'EUR',
            'categorySlug' => (string) ($row['category_slug'] ?? 'non-classe'),
            'categoryName' => (string) $row['category_name'],
            'mainCategorySlug' => $row['main_category_slug'] !== null ? (string) $row['main_category_slug'] : null,
            'mainCategoryName' => $row['main_category_name'] !== null ? (string) $row['main_category_name'] : null,
            'subCategorySlug' => $row['sub_category_slug'] !== null ? (string) $row['sub_category_slug'] : null,
            'subCategoryName' => $row['sub_category_name'] !== null ? (string) $row['sub_category_name'] : null,
            'brandSlug' => (string) ($row['brand_slug'] ?? 'marque-generique'),
            'brandName' => (string) ($row['brand_name'] ?? 'Marque'),
            'color' => $row['normalized_color'] !== null ? (string) $row['normalized_color'] : null,
            'tags' => $tags,
            'imageUrl' => $imageUrl,
            'imageUrls' => $imageUrls,
            'status' => (string) $row['status'],
            'type' => (string) $row['type'],
            'seoTitle' => $row['seo_title'] !== null ? (string) $row['seo_title'] : null,
            'seoDescription' => $row['seo_description'] !== null ? (string) $row['seo_description'] : null,
            'gtin' => $row['gtin'] !== null ? (string) $row['gtin'] : null,
            'mpn' => $row['mpn'] !== null ? (string) $row['mpn'] : null,
            'editorialAuthor' => $row['editorial_author'] !== null ? (string) $row['editorial_author'] : null,
            'editorialReviewer' => $row['editorial_reviewer'] !== null ? (string) $row['editorial_reviewer'] : null,
            'dateModified' => $row['reviewed_at'] !== null ? (string) $row['reviewed_at'] : null,
            'imageAlt' => $row['image_alt'] !== null ? (string) $row['image_alt'] : null,
            'specs' => $specs,
            'schemaOrgProduct' => [
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => (string) $row['name'],
                'sku' => $sku !== '' ? $sku : (string) $row['slug'],
                'gtin' => $row['gtin'] !== null ? (string) $row['gtin'] : null,
                'mpn' => $row['mpn'] !== null ? (string) $row['mpn'] : null,
                'description' => trim((string) ($row['description'] ?? '')) !== '' ? (string) $row['description'] : null,
                'category' => (string) $row['category_name'],
                'image' => $schemaImages,
                'offers' => [
                    '@type' => 'Offer',
                    'priceCurrency' => 'EUR',
                    'price' => isset($row['sale_price']) && $row['sale_price'] !== null ? (float) $row['sale_price'] : (float) $row['price'],
                    'url' => $baseUrl !== '' ? $baseUrl . '/boutique/' . rawurlencode((string) ($row['category_slug'] ?? 'non-classe')) . '/' . rawurlencode((string) $row['slug']) : null,
                    'availability' => ((string) $row['status']) === 'published'
                        ? 'https://schema.org/InStock'
                        : 'https://schema.org/OutOfStock',
                ],
            ],
        ];
    }

    /**
     * Récupère l'id de la catégorie et de toutes ses descendants (arborescence parent/enfant).
     *
     * @return list<int>
     */
    private function getDescendantCategoryIds(string $rootSlug): array
    {
        $stmt = $this->pdo->prepare(
            "WITH RECURSIVE category_tree AS (
                SELECT id
                FROM product_categories
                WHERE slug = :slug
                UNION ALL
                SELECT pc.id
                FROM product_categories pc
                INNER JOIN category_tree ct ON pc.parent_id = ct.id
            )
            SELECT id FROM category_tree"
        );
        $stmt->execute(['slug' => $rootSlug]);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $out = [];
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $out[] = (int) $id;
            }
        }

        // Dédupe et stabilité.
        $out = array_values(array_unique($out));
        return $out;
    }

    /**
     * @return array{slug:string,name:string,children:array<int,mixed>}|null
     */
    private function listCategoryTreeByRootSlug(string $locale, string $rootSlug): ?array
    {
        $navigation = $this->listNavigation($locale);
        $categories = is_array($navigation['categories'] ?? null) ? $navigation['categories'] : [];
        foreach ($categories as $category) {
            $slug = trim((string) ($category['slug'] ?? ''));
            if ($slug !== $rootSlug) {
                continue;
            }

            return $this->menuCategoryTreeFromNavNode($category);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $node
     * @return array{slug:string,name:string,children:array<int,mixed>}
     */
    private function menuCategoryTreeFromNavNode(array $node): array
    {
        $slug = trim((string) ($node['slug'] ?? ''));
        $name = trim((string) ($node['name'] ?? ''));
        $childrenRaw = is_array($node['children'] ?? null) ? $node['children'] : [];
        $children = [];
        foreach ($childrenRaw as $child) {
            if (!is_array($child)) {
                continue;
            }
            $children[] = $this->menuCategoryTreeFromNavNode($child);
        }

        return [
            'slug' => $slug,
            'name' => $name !== '' ? $name : $slug,
            'children' => $children,
        ];
    }

    /**
     * @return array{slug:string,name:string,children:array<int,mixed>}|null
     */
    private function listCategoryTreeByNeedles(string $locale, array $needles): ?array
    {
        $navigation = $this->listNavigation($locale);
        $categories = is_array($navigation['categories'] ?? null) ? $navigation['categories'] : [];
        foreach ($categories as $category) {
            $name = strtolower(trim((string) ($category['name'] ?? '')));
            $slug = strtolower(trim((string) ($category['slug'] ?? '')));
            $haystack = $name . ' ' . $slug;
            $isMatch = false;
            foreach ($needles as $needle) {
                if (str_contains($haystack, strtolower($needle))) {
                    $isMatch = true;
                    break;
                }
            }
            if (!$isMatch || $slug === '') {
                continue;
            }

            return $this->menuCategoryTreeFromNavNode($category);
        }

        return null;
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));
        if ($locale === '') {
            return 'fr';
        }
        return substr($locale, 0, 8);
    }

    /**
     * @param mixed $value
     */
    private function normalizePositiveInt($value, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }
        $parsed = (int) $value;
        if ($parsed < 0) {
            return $fallback;
        }
        return $parsed;
    }

    private function normalizeSlug(string $value): string
    {
        return strtolower(trim($value));
    }

    /**
     * Jusqu'à 4 URLs d'images (chemins relatifs ou absolus), résolues comme imageUrl.
     *
     * @return list<string>
     */
    private function resolveImageUrlsList(mixed $imageUrlsJson): array
    {
        if (!is_string($imageUrlsJson) || $imageUrlsJson === '') {
            return [];
        }
        /** @var mixed $decoded */
        $decoded = json_decode($imageUrlsJson, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $u) {
            if (!is_string($u) || trim($u) === '') {
                continue;
            }
            $resolved = $this->resolveMediaUrl($u);
            if ($resolved !== null && $resolved !== '') {
                $out[] = $resolved;
            }
        }

        return array_values(array_unique($out));
    }
    /**
     * @param mixed $value
     */
    private function resolveMediaUrl($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $url = trim($value);
        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $baseUrl = rtrim((string) ($_ENV['MEDIA_PUBLIC_URL'] ?? $_ENV['APP_URL'] ?? ''), '/');
        if ($baseUrl === '') {
            return $url;
        }

        return $baseUrl . '/' . ltrim($url, '/');
    }
}
