<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Cache\FileCache;
use App\Infrastructure\Database\ConnectionFactory;
use PDO;

final class PdoCmsRepository
{
    private PDO $pdo;
    private FileCache $cache;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
        $this->cache = new FileCache();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findPublishedPageBySlug(string $slug, string $locale = 'fr'): ?array
    {
        $locale = strtolower(trim($locale)) !== '' ? substr(strtolower(trim($locale)), 0, 8) : 'fr';
        $cacheTtl = (int) ($_ENV['CACHE_TTL_PAGES'] ?? 300);
        $cacheKey = 'cms:page:' . $slug . ':' . $locale;
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.slug, COALESCE(t.title, p.title) AS title, p.template, p.status,
                    COALESCE(t.meta_title, p.meta_title) AS meta_title,
                    COALESCE(t.meta_description, p.meta_description) AS meta_description,
                    p.published_at
             FROM cms_pages p
             LEFT JOIN cms_page_translations t ON t.page_id = p.id AND t.locale = :locale
             WHERE slug = :slug AND status = :status
             LIMIT 1'
        );
        $stmt->execute(['slug' => $slug, 'status' => 'published', 'locale' => $locale]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($page)) {
            return null;
        }

        $sectionsStmt = $this->pdo->prepare(
            'SELECT section_key, section_type, order_index, payload
             FROM cms_page_sections
             WHERE page_id = :page_id
             ORDER BY order_index ASC, id ASC'
        );
        $sectionsStmt->execute(['page_id' => (int) $page['id']]);
        $sections = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC);

        $result = ['page' => $page, 'sections' => is_array($sections) ? $sections : []];
        $this->cache->set($cacheKey, $result, $cacheTtl);
        return $result;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findPublishedBlogArticles(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.slug, a.title, a.excerpt, a.body, a.author_name, a.author_job_title, a.published_at,
                    c.slug AS category_slug, c.name AS category_name
             FROM blog_articles a
             INNER JOIN blog_categories c ON c.id = a.category_id
             WHERE a.status = :status
             ORDER BY a.published_at DESC, a.id DESC'
        );
        $stmt->execute(['status' => 'published']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findPublishedBlogArticle(string $categorySlug, string $articleSlug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.slug, a.title, a.excerpt, a.body, a.author_name, a.author_job_title, a.published_at,
                    c.slug AS category_slug, c.name AS category_name
             FROM blog_articles a
             INNER JOIN blog_categories c ON c.id = a.category_id
             WHERE a.status = :status AND c.slug = :category_slug AND a.slug = :article_slug
             LIMIT 1'
        );
        $stmt->execute([
            'status' => 'published',
            'category_slug' => $categorySlug,
            'article_slug' => $articleSlug,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findPublishedFaqItems(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT f.question, f.answer, f.order_index, c.slug AS category_slug, c.name AS category_name
             FROM faq_items f
             LEFT JOIN faq_categories c ON c.id = f.category_id
             WHERE f.status = :status
             ORDER BY f.order_index ASC, f.id ASC'
        );
        $stmt->execute(['status' => 'published']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findPublishedLegalPage(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT slug, title, paragraphs, version, published_at
             FROM legal_pages
             WHERE slug = :slug AND status = :status
             LIMIT 1'
        );
        $stmt->execute(['slug' => $slug, 'status' => 'published']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<int,array{sectionKey:string,sectionType:string,orderIndex:int,payload:string}> $sections
     */
    public function upsertPage(
        string $slug,
        string $title,
        string $template,
        ?string $metaTitle,
        ?string $metaDescription,
        string $status,
        ?string $scheduledAt,
        ?string $reviewNote,
        array $sections
    ): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $publishedAt = $status === 'published' ? $now : null;
        $reviewedAt = $status === 'in_review' ? $now : null;

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare('SELECT id FROM cms_pages WHERE slug = :slug LIMIT 1');
            $select->execute(['slug' => $slug]);
            $existingId = $select->fetchColumn();

            if ($existingId === false) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO cms_pages
                     (slug, title, template, status, meta_title, meta_description, published_at, reviewed_at, scheduled_at, review_note, created_at, updated_at)
                     VALUES
                     (:slug, :title, :template, :status, :meta_title, :meta_description, :published_at, :reviewed_at, :scheduled_at, :review_note, :created_at, :updated_at)'
                );
                $insert->execute([
                    'slug' => $slug,
                    'title' => $title,
                    'template' => $template,
                    'status' => $status,
                    'meta_title' => $metaTitle,
                    'meta_description' => $metaDescription,
                    'published_at' => $publishedAt,
                    'reviewed_at' => $reviewedAt,
                    'scheduled_at' => $scheduledAt,
                    'review_note' => $reviewNote,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $pageId = (int) $this->pdo->lastInsertId();
            } else {
                $pageId = (int) $existingId;
                $update = $this->pdo->prepare(
                    'UPDATE cms_pages
                     SET title = :title, template = :template, status = :status, meta_title = :meta_title,
                         meta_description = :meta_description, published_at = :published_at, reviewed_at = :reviewed_at,
                         scheduled_at = :scheduled_at, review_note = :review_note, updated_at = :updated_at
                     WHERE id = :id'
                );
                $update->execute([
                    'id' => $pageId,
                    'title' => $title,
                    'template' => $template,
                    'status' => $status,
                    'meta_title' => $metaTitle,
                    'meta_description' => $metaDescription,
                    'published_at' => $publishedAt,
                    'reviewed_at' => $reviewedAt,
                    'scheduled_at' => $scheduledAt,
                    'review_note' => $reviewNote,
                    'updated_at' => $now,
                ]);
                $deleteSections = $this->pdo->prepare('DELETE FROM cms_page_sections WHERE page_id = :page_id');
                $deleteSections->execute(['page_id' => $pageId]);
            }

            $insertSection = $this->pdo->prepare(
                'INSERT INTO cms_page_sections (page_id, section_key, section_type, order_index, payload, created_at, updated_at)
                 VALUES (:page_id, :section_key, :section_type, :order_index, :payload, :created_at, :updated_at)'
            );
            foreach ($sections as $section) {
                $insertSection->execute([
                    'page_id' => $pageId,
                    'section_key' => $section['sectionKey'],
                    'section_type' => $section['sectionType'],
                    'order_index' => $section['orderIndex'],
                    'payload' => $section['payload'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $versionStmt = $this->pdo->prepare(
                'SELECT COALESCE(MAX(version), 0) FROM cms_page_versions WHERE page_id = :page_id'
            );
            $versionStmt->execute(['page_id' => $pageId]);
            $nextVersion = ((int) $versionStmt->fetchColumn()) + 1;
            $versionPayload = [
                'slug' => $slug,
                'title' => $title,
                'template' => $template,
                'status' => $status,
                'metaTitle' => $metaTitle,
                'metaDescription' => $metaDescription,
                'publishedAt' => $publishedAt,
                'reviewedAt' => $reviewedAt,
                'scheduledAt' => $scheduledAt,
                'reviewNote' => $reviewNote,
                'sections' => $sections,
            ];
            $insertVersion = $this->pdo->prepare(
                'INSERT INTO cms_page_versions (page_id, version, payload, created_at)
                 VALUES (:page_id, :version, :payload, :created_at)'
            );
            $insertVersion->execute([
                'page_id' => $pageId,
                'version' => $nextVersion,
                'payload' => json_encode($versionPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'created_at' => $now,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
