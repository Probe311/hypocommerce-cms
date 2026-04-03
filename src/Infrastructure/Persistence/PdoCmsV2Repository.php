<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use PDO;

final class PdoCmsV2Repository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function upsertPage(array $payload): string
    {
        $slug = $this->slugify((string) ($payload['slug'] ?? ''));
        $title = trim((string) ($payload['title'] ?? ''));
        $template = trim((string) ($payload['template'] ?? 'default'));
        $status = $this->normalizeStatus((string) ($payload['status'] ?? 'draft'));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('SELECT id FROM cms_pages WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $existingId = $stmt->fetchColumn();

        if ($existingId === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO cms_pages (slug, title, template, status, meta_title, meta_description, published_at, scheduled_at, created_at, updated_at)
                 VALUES (:slug, :title, :template, :status, :meta_title, :meta_description, :published_at, :scheduled_at, :created_at, :updated_at)'
            );
            $insert->execute([
                'slug' => $slug,
                'title' => $title,
                'template' => $template,
                'status' => $status,
                'meta_title' => $payload['metaTitle'] ?? null,
                'meta_description' => $payload['metaDescription'] ?? null,
                'published_at' => $status === 'published' ? $now : null,
                'scheduled_at' => $payload['scheduledAt'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $pageId = (int) $this->pdo->lastInsertId();
        } else {
            $pageId = (int) $existingId;
            $update = $this->pdo->prepare(
                'UPDATE cms_pages
                 SET title = :title, template = :template, status = :status, meta_title = :meta_title, meta_description = :meta_description, published_at = :published_at, scheduled_at = :scheduled_at, updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'id' => $pageId,
                'title' => $title,
                'template' => $template,
                'status' => $status,
                'meta_title' => $payload['metaTitle'] ?? null,
                'meta_description' => $payload['metaDescription'] ?? null,
                'published_at' => $status === 'published' ? $now : null,
                'scheduled_at' => $payload['scheduledAt'] ?? null,
                'updated_at' => $now,
            ]);
        }

        $this->storeRevision('page', (string) $pageId, $title, $payload, (int) ($payload['adminUserId'] ?? 0));
        return $slug;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function upsertArticleCategory(array $payload): int
    {
        $slug = $this->slugify((string) ($payload['slug'] ?? ''));
        $name = trim((string) ($payload['name'] ?? ''));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('SELECT id FROM blog_categories WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $existingId = $stmt->fetchColumn();
        if ($existingId === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO blog_categories (parent_id, slug, name, description, status, meta_title, meta_description, created_at, updated_at)
                 VALUES (:parent_id, :slug, :name, :description, :status, :meta_title, :meta_description, :created_at, :updated_at)'
            );
            $insert->execute([
                'parent_id' => isset($payload['parentId']) ? (int) $payload['parentId'] : null,
                'slug' => $slug,
                'name' => $name,
                'description' => $payload['description'] ?? null,
                'status' => $this->normalizeSimpleStatus((string) ($payload['status'] ?? 'published')),
                'meta_title' => $payload['metaTitle'] ?? null,
                'meta_description' => $payload['metaDescription'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->pdo->lastInsertId();
        } else {
            $id = (int) $existingId;
            $update = $this->pdo->prepare(
                'UPDATE blog_categories
                 SET parent_id = :parent_id, name = :name, description = :description, status = :status, meta_title = :meta_title, meta_description = :meta_description, updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'id' => $id,
                'parent_id' => isset($payload['parentId']) ? (int) $payload['parentId'] : null,
                'name' => $name,
                'description' => $payload['description'] ?? null,
                'status' => $this->normalizeSimpleStatus((string) ($payload['status'] ?? 'published')),
                'meta_title' => $payload['metaTitle'] ?? null,
                'meta_description' => $payload['metaDescription'] ?? null,
                'updated_at' => $now,
            ]);
        }
        $this->storeRevision('article_category', (string) $id, $name, $payload, (int) ($payload['adminUserId'] ?? 0));
        return $id;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function upsertArticle(array $payload): string
    {
        $slug = $this->slugify((string) ($payload['slug'] ?? ''));
        $title = trim((string) ($payload['title'] ?? ''));
        $body = (string) ($payload['body'] ?? '');
        $status = $this->normalizeSimpleStatus((string) ($payload['status'] ?? 'draft'));
        $categoryId = (int) ($payload['categoryId'] ?? 0);
        if ($categoryId < 1) {
            throw new \RuntimeException('invalid_category_id');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('SELECT id FROM blog_articles WHERE category_id = :category_id AND slug = :slug LIMIT 1');
        $stmt->execute(['category_id' => $categoryId, 'slug' => $slug]);
        $existingId = $stmt->fetchColumn();
        if ($existingId === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO blog_articles (category_id, slug, title, excerpt, body, author_name, author_job_title, featured_media_id, status, meta_title, meta_description, published_at, created_at, updated_at, updated_by_admin_id)
                 VALUES (:category_id, :slug, :title, :excerpt, :body, :author_name, :author_job_title, :featured_media_id, :status, :meta_title, :meta_description, :published_at, :created_at, :updated_at, :updated_by_admin_id)'
            );
            $insert->execute([
                'category_id' => $categoryId,
                'slug' => $slug,
                'title' => $title,
                'excerpt' => $payload['excerpt'] ?? null,
                'body' => $body,
                'author_name' => $payload['authorName'] ?? null,
                'author_job_title' => $payload['authorJobTitle'] ?? null,
                'featured_media_id' => isset($payload['featuredMediaId']) ? (int) $payload['featuredMediaId'] : null,
                'status' => $status,
                'meta_title' => $payload['metaTitle'] ?? null,
                'meta_description' => $payload['metaDescription'] ?? null,
                'published_at' => $status === 'published' ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
                'updated_by_admin_id' => isset($payload['adminUserId']) ? (int) $payload['adminUserId'] : null,
            ]);
            $articleId = (int) $this->pdo->lastInsertId();
        } else {
            $articleId = (int) $existingId;
            $update = $this->pdo->prepare(
                'UPDATE blog_articles
                 SET title = :title, excerpt = :excerpt, body = :body, author_name = :author_name, author_job_title = :author_job_title, featured_media_id = :featured_media_id, status = :status, meta_title = :meta_title, meta_description = :meta_description, published_at = :published_at, updated_at = :updated_at, updated_by_admin_id = :updated_by_admin_id
                 WHERE id = :id'
            );
            $update->execute([
                'id' => $articleId,
                'title' => $title,
                'excerpt' => $payload['excerpt'] ?? null,
                'body' => $body,
                'author_name' => $payload['authorName'] ?? null,
                'author_job_title' => $payload['authorJobTitle'] ?? null,
                'featured_media_id' => isset($payload['featuredMediaId']) ? (int) $payload['featuredMediaId'] : null,
                'status' => $status,
                'meta_title' => $payload['metaTitle'] ?? null,
                'meta_description' => $payload['metaDescription'] ?? null,
                'published_at' => $status === 'published' ? $now : null,
                'updated_at' => $now,
                'updated_by_admin_id' => isset($payload['adminUserId']) ? (int) $payload['adminUserId'] : null,
            ]);
        }

        if (isset($payload['tagIds']) && is_array($payload['tagIds'])) {
            $this->syncArticleTags($articleId, $payload['tagIds']);
        }
        $this->storeRevision('article', (string) $articleId, $title, $payload, (int) ($payload['adminUserId'] ?? 0));
        return $slug;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listMedia(array $filters = []): array
    {
        $sql = 'SELECT id, url, filename, mime_type, size_bytes, width, height, title, alt_text, caption, folder, status, created_at
                FROM cms_media_library WHERE 1=1';
        $params = [];
        if (isset($filters['folder']) && is_string($filters['folder']) && trim($filters['folder']) !== '') {
            $sql .= ' AND folder = :folder';
            $params['folder'] = trim($filters['folder']);
        }
        if (isset($filters['query']) && is_string($filters['query']) && trim($filters['query']) !== '') {
            $sql .= ' AND (filename LIKE :query OR COALESCE(title, \'\') LIKE :query OR COALESCE(alt_text, \'\') LIKE :query)';
            $params['query'] = '%' . trim($filters['query']) . '%';
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
        $limit = isset($filters['limit']) ? max(1, min(200, (int) $filters['limit'])) : 50;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function upsertMenu(array $payload): int
    {
        $menuKey = $this->slugify((string) ($payload['menuKey'] ?? ''));
        $location = (string) ($payload['location'] ?? 'header');
        if (!in_array($location, ['header', 'footer', 'secondary'], true)) {
            $location = 'header';
        }
        $label = trim((string) ($payload['label'] ?? $menuKey));
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('SELECT id, active_version FROM cms_navigation_menus WHERE menu_key = :menu_key LIMIT 1');
        $stmt->execute(['menu_key' => $menuKey]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($existing)) {
            $insert = $this->pdo->prepare(
                'INSERT INTO cms_navigation_menus (menu_key, label, location, is_active, active_version, created_at, updated_at)
                 VALUES (:menu_key, :label, :location, :is_active, 1, :created_at, :updated_at)'
            );
            $insert->execute([
                'menu_key' => $menuKey,
                'label' => $label,
                'location' => $location,
                'is_active' => (bool) ($payload['isActive'] ?? true) ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $menuId = (int) $this->pdo->lastInsertId();
        } else {
            $menuId = (int) $existing['id'];
            $update = $this->pdo->prepare(
                'UPDATE cms_navigation_menus
                 SET label = :label, location = :location, is_active = :is_active, active_version = :active_version, updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'id' => $menuId,
                'label' => $label,
                'location' => $location,
                'is_active' => (bool) ($payload['isActive'] ?? true) ? 1 : 0,
                'active_version' => ((int) $existing['active_version']) + 1,
                'updated_at' => $now,
            ]);
        }
        if (isset($payload['items']) && is_array($payload['items'])) {
            $this->replaceMenuItems($menuId, $payload['items']);
        }
        $this->storeRevision('menu', (string) $menuId, $label, $payload, (int) ($payload['adminUserId'] ?? 0));
        return $menuId;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function upsertSiteSettings(array $payload): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $settingKey = trim((string) ($payload['settingKey'] ?? ''));
        $settingValue = $payload['settingValue'] ?? [];
        $stmt = $this->pdo->prepare('SELECT id FROM cms_site_settings WHERE setting_key = :setting_key LIMIT 1');
        $stmt->execute(['setting_key' => $settingKey]);
        $existingId = $stmt->fetchColumn();
        if ($existingId === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO cms_site_settings (setting_key, setting_value, updated_by_admin_id, created_at, updated_at)
                 VALUES (:setting_key, :setting_value, :updated_by_admin_id, :created_at, :updated_at)'
            );
            $insert->execute([
                'setting_key' => $settingKey,
                'setting_value' => json_encode($settingValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'updated_by_admin_id' => isset($payload['adminUserId']) ? (int) $payload['adminUserId'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $update = $this->pdo->prepare(
                'UPDATE cms_site_settings
                 SET setting_value = :setting_value, updated_by_admin_id = :updated_by_admin_id, updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'id' => (int) $existingId,
                'setting_value' => json_encode($settingValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                'updated_by_admin_id' => isset($payload['adminUserId']) ? (int) $payload['adminUserId'] : null,
                'updated_at' => $now,
            ]);
        }
        $this->storeRevision('site_setting', $settingKey, $settingKey, $payload, (int) ($payload['adminUserId'] ?? 0));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getSiteSettingByKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM cms_site_settings WHERE setting_key = :k LIMIT 1');
        $stmt->execute(['k' => $key]);
        $raw = $stmt->fetchColumn();
        if ($raw === false) {
            return null;
        }
        if (is_array($raw)) {
            /** @var array<string,mixed> $raw */
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listPublishedArticles(?string $categorySlug = null): array
    {
        $sql = "SELECT a.id, a.slug, a.title, a.excerpt, a.body, a.author_name, a.author_job_title, a.meta_title, a.meta_description, a.published_at,
                       c.slug AS category_slug, c.name AS category_name, ml.url AS featured_media_url
                FROM blog_articles a
                INNER JOIN blog_categories c ON c.id = a.category_id
                LEFT JOIN cms_media_library ml ON ml.id = a.featured_media_id
                WHERE a.status = 'published' AND c.status = 'published'";
        $params = [];
        if ($categorySlug !== null && $categorySlug !== '') {
            $sql .= ' AND c.slug = :category_slug';
            $params['category_slug'] = $categorySlug;
        }
        $sql .= ' ORDER BY a.published_at DESC, a.id DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listPublishedCategories(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, parent_id, slug, name, description, meta_title, meta_description
             FROM blog_categories
             WHERE status = 'published'
             ORDER BY name ASC"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getPublishedPageBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, slug, title, template, meta_title, meta_description, published_at
             FROM cms_pages
             WHERE slug = :slug AND status = 'published'
             LIMIT 1"
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getPublishedPageSections(int $pageId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT section_key, section_type, order_index, payload
             FROM cms_page_sections
             WHERE page_id = :page_id
             ORDER BY order_index ASC, id ASC'
        );
        $stmt->execute(['page_id' => $pageId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listActiveMenu(string $location): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT i.id, i.parent_id, i.label, i.target_type, i.target_ref, i.icon, i.order_index
             FROM cms_navigation_menus m
             INNER JOIN cms_navigation_items i ON i.menu_id = m.id
             WHERE m.location = :location AND m.is_active = 1 AND i.is_active = 1
             ORDER BY i.order_index ASC, i.id ASC'
        );
        $stmt->execute(['location' => $location]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listActiveSocialLinks(): array
    {
        $stmt = $this->pdo->query(
            'SELECT platform, icon_key, url, order_index
             FROM cms_site_social_links
             WHERE is_active = 1
             ORDER BY order_index ASC, id ASC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listActiveFooter(): array
    {
        $stmt = $this->pdo->query(
            'SELECT s.id AS section_id, s.section_key, s.title, s.order_index, l.label, l.href, l.target, l.order_index AS link_order
             FROM cms_site_footer_sections s
             LEFT JOIN cms_site_footer_links l ON l.section_id = s.id AND l.is_active = 1
             WHERE s.is_active = 1
             ORDER BY s.order_index ASC, l.order_index ASC, l.id ASC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int,mixed> $tagIds
     */
    private function syncArticleTags(int $articleId, array $tagIds): void
    {
        $this->pdo->prepare('DELETE FROM blog_article_tags WHERE article_id = :article_id')->execute(['article_id' => $articleId]);
        $insert = $this->pdo->prepare('INSERT INTO blog_article_tags (article_id, tag_id) VALUES (:article_id, :tag_id)');
        foreach ($tagIds as $tagIdRaw) {
            $tagId = (int) $tagIdRaw;
            if ($tagId < 1) {
                continue;
            }
            $insert->execute(['article_id' => $articleId, 'tag_id' => $tagId]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function replaceMenuItems(int $menuId, array $items): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo->prepare('DELETE FROM cms_navigation_items WHERE menu_id = :menu_id')->execute(['menu_id' => $menuId]);
        $insert = $this->pdo->prepare(
            'INSERT INTO cms_navigation_items (menu_id, parent_id, label, target_type, target_ref, icon, order_index, is_active, created_at, updated_at)
             VALUES (:menu_id, :parent_id, :label, :target_type, :target_ref, :icon, :order_index, :is_active, :created_at, :updated_at)'
        );
        foreach ($items as $index => $item) {
            $insert->execute([
                'menu_id' => $menuId,
                'parent_id' => isset($item['parentId']) ? (int) $item['parentId'] : null,
                'label' => trim((string) ($item['label'] ?? 'Item')),
                'target_type' => $this->normalizeTargetType((string) ($item['targetType'] ?? 'external_url')),
                'target_ref' => trim((string) ($item['targetRef'] ?? '#')),
                'icon' => isset($item['icon']) ? (string) $item['icon'] : null,
                'order_index' => isset($item['order']) ? (int) $item['order'] : $index,
                'is_active' => (bool) ($item['isActive'] ?? true) ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function storeRevision(string $entityType, string $entityId, ?string $title, array $payload, int $adminUserId): void
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version), 0) FROM cms_content_revisions WHERE entity_type = :entity_type AND entity_id = :entity_id');
        $stmt->execute(['entity_type' => $entityType, 'entity_id' => $entityId]);
        $version = ((int) $stmt->fetchColumn()) + 1;
        $insert = $this->pdo->prepare(
            'INSERT INTO cms_content_revisions (entity_type, entity_id, version, title, payload, created_by_admin_id, created_at)
             VALUES (:entity_type, :entity_id, :version, :title, :payload, :created_by_admin_id, :created_at)'
        );
        $insert->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'version' => $version,
            'title' => $title,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'created_by_admin_id' => $adminUserId > 0 ? $adminUserId : null,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        if (!is_string($value)) {
            return '';
        }
        return trim($value, '-');
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['draft', 'in_review', 'scheduled', 'published', 'archived'], true)) {
            return 'draft';
        }
        return $status;
    }

    private function normalizeSimpleStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            return 'draft';
        }
        return $status;
    }

    private function normalizeTargetType(string $targetType): string
    {
        if (!in_array($targetType, ['page', 'article', 'article_category', 'external_url', 'anchor'], true)) {
            return 'external_url';
        }
        return $targetType;
    }
}
