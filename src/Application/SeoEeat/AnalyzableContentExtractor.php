<?php

declare(strict_types=1);

namespace App\Application\SeoEeat;

use App\Infrastructure\Database\ConnectionFactory;
use PDO;

final class AnalyzableContentExtractor
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function extractAll(): array
    {
        return array_merge(
            $this->extractProducts(),
            $this->extractCategories(),
            $this->extractCmsPages(),
            $this->extractBlogArticles(),
            $this->extractFaqItems(),
            $this->extractLegalPages(),
            $this->extractLegacyPages()
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function extractChangedSince(string $sinceDateTime): array
    {
        return array_merge(
            $this->extractProducts($sinceDateTime),
            $this->extractCategories($sinceDateTime),
            $this->extractCmsPages($sinceDateTime),
            $this->extractBlogArticles($sinceDateTime),
            $this->extractFaqItems($sinceDateTime),
            $this->extractLegalPages($sinceDateTime),
            $this->extractLegacyPages($sinceDateTime)
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractProducts(?string $since = null): array
    {
        $sql = "SELECT id, slug, name, description, seo_title, seo_description, status, updated_at
                FROM products
                WHERE status IN ('published', 'draft')";
        $params = [];
        if ($since !== null && $since !== '') {
            $sql .= ' AND updated_at >= :since';
            $params['since'] = $since;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->mapRows((array) $rows, 'product');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractCategories(?string $since = null): array
    {
        $sql = 'SELECT id, slug, name, description, updated_at FROM product_categories WHERE 1=1';
        $params = [];
        if ($since !== null && $since !== '') {
            $sql .= ' AND updated_at >= :since';
            $params['since'] = $since;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->mapRows((array) $rows, 'category');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractCmsPages(?string $since = null): array
    {
        $sql = "SELECT p.id, p.slug, p.title AS name, p.meta_title AS seo_title, p.meta_description AS seo_description, p.status, p.updated_at,
                       GROUP_CONCAT(s.payload SEPARATOR '\n') AS body
                FROM cms_pages p
                LEFT JOIN cms_page_sections s ON s.page_id = p.id
                GROUP BY p.id, p.slug, p.title, p.meta_title, p.meta_description, p.status, p.updated_at";
        if ($since !== null && $since !== '') {
            $sql = "SELECT * FROM ({$sql}) scoped WHERE scoped.updated_at >= :since";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($since !== null && $since !== '' ? ['since' => $since] : []);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->mapRows((array) $rows, 'cms_page');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractBlogArticles(?string $since = null): array
    {
        $sql = "SELECT a.id, a.slug, a.title AS name, a.body, a.meta_title AS seo_title, a.meta_description AS seo_description,
                       a.status, a.author_name, a.updated_at
                FROM blog_articles a
                WHERE 1=1";
        $params = [];
        if ($since !== null && $since !== '') {
            $sql .= ' AND a.updated_at >= :since';
            $params['since'] = $since;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->mapRows((array) $rows, 'blog_article');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractFaqItems(?string $since = null): array
    {
        $sql = 'SELECT id, question AS name, answer AS body, status, updated_at FROM faq_items WHERE 1=1';
        $params = [];
        if ($since !== null && $since !== '') {
            $sql .= ' AND updated_at >= :since';
            $params['since'] = $since;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->mapRows((array) $rows, 'faq_item');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractLegalPages(?string $since = null): array
    {
        $sql = 'SELECT id, slug, title AS name, paragraphs AS body, status, updated_at FROM legal_pages WHERE 1=1';
        $params = [];
        if ($since !== null && $since !== '') {
            $sql .= ' AND updated_at >= :since';
            $params['since'] = $since;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->mapRows((array) $rows, 'legal_page');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function extractLegacyPages(?string $since = null): array
    {
        $sql = 'SELECT id, slug, title AS name, content AS body, meta_title AS seo_title, meta_description AS seo_description, published_at AS updated_at
                FROM pages WHERE 1=1';
        $params = [];
        if ($since !== null && $since !== '') {
            $sql .= ' AND published_at >= :since';
            $params['since'] = $since;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->mapRows((array) $rows, 'legacy_page');
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function mapRows(array $rows, string $entityType): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            $entityId = isset($row['id']) ? (string) $row['id'] : (string) ($row['slug'] ?? '');
            if ($entityId === '') {
                continue;
            }
            $body = trim((string) ($row['body'] ?? $row['description'] ?? ''));
            $normalized[] = [
                'entityType' => $entityType,
                'entityId' => $entityId,
                'entitySlug' => isset($row['slug']) ? (string) $row['slug'] : null,
                'locale' => 'fr',
                'title' => trim((string) ($row['name'] ?? $row['title'] ?? '')),
                'metaTitle' => trim((string) ($row['seo_title'] ?? '')),
                'metaDescription' => trim((string) ($row['seo_description'] ?? '')),
                'body' => $body,
                'author' => isset($row['author_name']) ? trim((string) $row['author_name']) : '',
                'updatedAt' => (string) ($row['updated_at'] ?? ''),
                'trustSignals' => [
                    'hasAuthor' => isset($row['author_name']) && trim((string) $row['author_name']) !== '',
                    'hasMeta' => trim((string) ($row['seo_title'] ?? '')) !== '' && trim((string) ($row['seo_description'] ?? '')) !== '',
                    'hasFreshness' => (string) ($row['updated_at'] ?? '') !== '',
                    'businessCritical' => in_array($entityType, ['product', 'category'], true),
                    'conversionIntent' => in_array($entityType, ['product', 'category', 'cms_page'], true),
                ],
            ];
        }
        return $normalized;
    }
}
