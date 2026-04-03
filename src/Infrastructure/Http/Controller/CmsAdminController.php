<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Admin\AdminAuthService;
use App\Application\Fulfillment\SplitShipmentService;
use App\Application\Notification\TransactionalEmailService;
use App\Application\Payment\RefundService;
use App\Application\Shared\HookDispatcher;
use App\Application\Tracking\TrackingIntegrationsService;
use App\Application\Validation\InputValidator;
use App\Application\Validation\ValidationException;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\PdoCmsRepository;
use App\Infrastructure\Persistence\PdoCmsV2Repository;
use App\Infrastructure\Persistence\PdoCouponRepository;
use App\Infrastructure\Persistence\PdoAdminMutationRepository;
use App\Infrastructure\Persistence\PdoAdminPluginRepository;
use App\Infrastructure\Persistence\PdoAdminPaymentMethodRepository;
use App\Infrastructure\Persistence\PdoAdminShippingCarrierRepository;
use App\Infrastructure\Persistence\PdoAdminTaxRuleRepository;
use App\Infrastructure\Persistence\PdoOrderRepository;
use App\Infrastructure\Persistence\PdoWebhookEventRepository;
use App\Infrastructure\Security\RateLimiter;
use App\Plugin\Runtime\PluginRuntimeInspector;
use JsonException;
use PDO;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CmsAdminController
{
    private PDO $pdo;
    private InputValidator $validator;
    private ?int $currentAdminUserId = null;
    private ?string $currentAdminRole = null;
    private ?string $currentIp = null;
    /** @var array<string,bool> */
    private array $tablePresenceCache = [];

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
        $this->validator = new InputValidator();
    }

    public function handle(Request $request, string $resource): Response
    {
        $auth = $this->assertAuthorized($request, $resource);
        if ($auth !== null) {
            return $auth;
        }

        if ($request->getMethod() !== 'POST') {
            return $this->json(['error' => 'method_not_allowed'], 405);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($request->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->json(['error' => 'invalid_json'], 400);
        }

        if (!is_array($decoded)) {
            return $this->json(['error' => 'invalid_payload'], 400);
        }

        try {
            HookDispatcher::dispatch('cms.admin.before_mutation', [
                'resource' => $resource,
                'payload' => $decoded,
            ]);
        } catch (\Throwable) {
            // Keep admin endpoint robust even if extension hooks fail.
        }

        try {
            $response = match ($resource) {
                'auth/login' => $this->login($decoded),
                'pages' => $this->upsertPage($decoded),
                'pages/list' => $this->listPages(),
                'pages/get' => $this->getPage($decoded),
                'pages/delete' => $this->deletePage($decoded),
                'pages/versions' => $this->listPageVersions($decoded),
                'pages/revert' => $this->revertPageVersion($decoded),
                'blog/articles' => $this->upsertBlogArticle($decoded),
                'blog/articles/list' => $this->listBlogArticles(),
                'blog/articles/get' => $this->getBlogArticle($decoded),
                'blog/articles/delete' => $this->deleteBlogArticle($decoded),
                'faq/items' => $this->upsertFaqItem($decoded),
                'faq/items/list' => $this->listFaqItems(),
                'faq/items/delete' => $this->deleteFaqItem($decoded),
                'legal/pages' => $this->upsertLegalPage($decoded),
                'legal/pages/list' => $this->listLegalPages(),
                'legal/pages/get' => $this->getLegalPage($decoded),
                'legal/pages/delete' => $this->deleteLegalPage($decoded),
                'orders/shipments' => $this->updateOrderShipment($decoded),
                'orders/status' => $this->updateOrderStatus($decoded),
                'orders/refunds' => $this->refundOrder($decoded),
                'orders/split-shipments' => $this->splitOrderShipments($decoded),
                'products/bulk' => $this->bulkUpdateProducts($decoded),
                'translations/upsert' => $this->upsertTranslation($decoded),
                'search/products' => $this->searchProducts($decoded),
            'products/images/list' => $this->listProductImages($decoded),
                'search/orders' => $this->searchOrders($decoded),
                'search/customers' => $this->searchCustomers($decoded),
                'crm/segments' => $this->listCustomerSegments($decoded),
                'coupons' => $this->upsertCoupon($decoded),
                'plugins/list' => $this->listPlugins(),
                'plugins/toggle' => $this->togglePlugin($decoded),
                'plugins/config' => $this->updatePluginConfig($decoded),
                'plugins/validate' => $this->validatePluginConfig($decoded),
                'plugins/diagnostics' => $this->pluginDiagnostics($decoded),
                'settings/taxes/list' => $this->listTaxRules(),
                'settings/taxes/upsert' => $this->upsertTaxRule($decoded),
                'settings/taxes/toggle' => $this->toggleTaxRule($decoded),
                'settings/shipping/list' => $this->listShippingCarriers(),
                'settings/shipping/upsert' => $this->upsertShippingCarrier($decoded),
                'settings/shipping/toggle' => $this->toggleShippingCarrier($decoded),
                'settings/payments/list' => $this->listPaymentMethods(),
                'settings/payments/upsert' => $this->upsertPaymentMethod($decoded),
                'settings/payments/toggle' => $this->togglePaymentMethod($decoded),
                'settings/tracking/get' => $this->getTrackingSettings(),
                'settings/tracking/save' => $this->saveTrackingSettings($decoded),
                default => $this->json(['error' => 'not_found'], 404),
            };
        } catch (ValidationException $e) {
            $response = $this->json(['error' => $e->errorCode()], 422);
        } catch (\Throwable) {
            $response = $this->json(['error' => 'internal_error'], 500);
        }

        try {
            HookDispatcher::dispatch('cms.admin.after_mutation', [
                'resource' => $resource,
                'payload' => $decoded,
                'status' => $response->getStatusCode(),
            ]);
        } catch (\Throwable) {
            // Keep admin endpoint robust even if extension hooks fail.
        }

        return $response;
    }

    private function login(array $payload): Response
    {
        $emailForRateLimit = strtolower(trim((string) ($payload['email'] ?? 'unknown')));
        $limiter = new RateLimiter();
        $rate = $limiter->check('admin_login', $emailForRateLimit, 10, 60);
        if ($rate['allowed'] === false) {
            return $this->json(['error' => 'rate_limited', 'retryAfter' => $rate['retryAfter']], 429);
        }

        try {
            $email = $this->validator->requireNonEmptyString($payload, 'email', 'invalid_email');
            $password = $this->validator->requireNonEmptyString($payload, 'password', 'invalid_password');
            $result = (new AdminAuthService())->login($email, $password);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 401);
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }

        return $this->json([
            'ok' => true,
            'token' => $result['token'],
            'csrfToken' => $this->buildCsrfTokenForSecret($result['token']),
            'userId' => $result['userId'],
            'role' => $result['role'],
            'email' => $result['email'],
        ], 200);
    }

    private function upsertPage(array $payload): Response
    {
        try {
            $slug = $this->validator->requireNonEmptyString($payload, 'slug', 'invalid_slug');
            $title = $this->validator->requireNonEmptyString($payload, 'title', 'invalid_title');
            $template = $this->validator->optionalTrimmedString($payload, 'template') ?? 'default';
            $sections = $this->validator->requireArray($payload, 'sections', 'invalid_sections');
            $status = strtolower($this->validator->optionalTrimmedString($payload, 'status') ?? (((bool) ($payload['publish'] ?? false)) ? 'published' : 'draft'));
            $scheduledAt = $this->validator->optionalTrimmedString($payload, 'scheduledAt');
            $reviewNote = $this->validator->optionalTrimmedString($payload, 'reviewNote');
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }
        if (!in_array($status, ['draft', 'in_review', 'scheduled', 'published', 'archived'], true)) {
            return $this->json(['error' => 'invalid_status'], 422);
        }
        if ($status === 'scheduled' && ($scheduledAt === null || $scheduledAt === '')) {
            return $this->json(['error' => 'missing_scheduled_at'], 422);
        }

        $preparedSections = [];
        foreach ($sections as $i => $section) {
            if (!is_array($section)) {
                continue;
            }
            $key = trim((string) ($section['sectionKey'] ?? $section['key'] ?? "section_{$i}"));
            $type = trim((string) ($section['sectionType'] ?? $section['type'] ?? 'generic'));
            $order = (int) ($section['orderIndex'] ?? $section['order'] ?? $i);
            $content = $section['payload'] ?? [];
            $preparedSections[] = [
                'sectionKey' => $key === '' ? "section_{$i}" : $key,
                'sectionType' => $type === '' ? 'generic' : $type,
                'orderIndex' => $order,
                'payload' => json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            ];
        }

        $before = $this->snapshotEntity('cms_pages', $slug);
        $repo = new PdoCmsRepository();
        $repo->upsertPage(
            $slug,
            $title,
            $template,
            isset($payload['metaTitle']) ? (string) $payload['metaTitle'] : null,
            isset($payload['metaDescription']) ? (string) $payload['metaDescription'] : null,
            $status,
            $scheduledAt,
            $reviewNote,
            $preparedSections
        );

        $after = $this->snapshotEntity('cms_pages', $slug);
        $this->audit('upsert', 'cms_pages', $slug, $payload, $before, $after);
        return $this->json(['ok' => true, 'resource' => 'pages', 'slug' => $slug], 200);
    }

    private function listPages(): Response
    {
        $stmt = $this->pdo->query(
            'SELECT id, slug, title, template, status, published_at, updated_at
             FROM cms_pages
             ORDER BY updated_at DESC, id DESC'
        );
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->json(['ok' => true, 'items' => is_array($items) ? $items : []], 200);
    }

    private function getPage(array $payload): Response
    {
        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            return $this->json(['error' => 'invalid_slug'], 422);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM cms_pages WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $page = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($page)) {
            return $this->json(['error' => 'page_not_found'], 404);
        }
        $sectionsStmt = $this->pdo->prepare(
            'SELECT section_key, section_type, order_index, payload
             FROM cms_page_sections
             WHERE page_id = :page_id
             ORDER BY order_index ASC, id ASC'
        );
        $sectionsStmt->execute(['page_id' => (int) $page['id']]);
        $sections = $sectionsStmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->json(['ok' => true, 'item' => ['page' => $page, 'sections' => is_array($sections) ? $sections : []]], 200);
    }

    private function deletePage(array $payload): Response
    {
        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            return $this->json(['error' => 'invalid_slug'], 422);
        }
        $before = $this->snapshotEntity('cms_pages', $slug);
        if ($before === null) {
            return $this->json(['error' => 'page_not_found'], 404);
        }
        $stmt = $this->pdo->prepare('UPDATE cms_pages SET status = :status, updated_at = :updated_at WHERE slug = :slug');
        $stmt->execute([
            'status' => 'archived',
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'slug' => $slug,
        ]);
        $after = $this->snapshotEntity('cms_pages', $slug);
        $this->audit('archive', 'cms_pages', $slug, $payload, $before, $after);
        return $this->json(['ok' => true, 'resource' => 'pages/delete', 'slug' => $slug], 200);
    }

    private function listPageVersions(array $payload): Response
    {
        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            return $this->json(['error' => 'invalid_slug'], 422);
        }
        $stmt = $this->pdo->prepare(
            'SELECT v.version, v.created_at
             FROM cms_page_versions v
             INNER JOIN cms_pages p ON p.id = v.page_id
             WHERE p.slug = :slug
             ORDER BY v.version DESC'
        );
        $stmt->execute(['slug' => $slug]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->json(['ok' => true, 'items' => is_array($items) ? $items : []], 200);
    }

    private function revertPageVersion(array $payload): Response
    {
        $slug = trim((string) ($payload['slug'] ?? ''));
        $version = (int) ($payload['version'] ?? 0);
        if ($slug === '' || $version < 1) {
            return $this->json(['error' => 'invalid_revert_payload'], 422);
        }
        $stmt = $this->pdo->prepare(
            'SELECT v.payload
             FROM cms_page_versions v
             INNER JOIN cms_pages p ON p.id = v.page_id
             WHERE p.slug = :slug AND v.version = :version
             LIMIT 1'
        );
        $stmt->execute(['slug' => $slug, 'version' => $version]);
        $payloadJson = $stmt->fetchColumn();
        if (!is_string($payloadJson) || trim($payloadJson) === '') {
            return $this->json(['error' => 'version_not_found'], 404);
        }
        $decoded = json_decode($payloadJson, true);
        if (!is_array($decoded)) {
            return $this->json(['error' => 'invalid_version_payload'], 409);
        }
        $sections = isset($decoded['sections']) && is_array($decoded['sections']) ? $decoded['sections'] : [];
        $restorePayload = [
            'slug' => $slug,
            'title' => (string) ($decoded['title'] ?? ''),
            'template' => (string) ($decoded['template'] ?? 'default'),
            'metaTitle' => $decoded['metaTitle'] ?? null,
            'metaDescription' => $decoded['metaDescription'] ?? null,
            'status' => (string) ($decoded['status'] ?? 'draft'),
            'scheduledAt' => $decoded['scheduledAt'] ?? null,
            'reviewNote' => $decoded['reviewNote'] ?? null,
            'sections' => $sections,
        ];
        return $this->upsertPage($restorePayload);
    }

    private function upsertBlogArticle(array $payload): Response
    {
        try {
            $categorySlug = $this->validator->requireNonEmptyString($payload, 'categorySlug', 'invalid_category_slug');
            $categoryName = $this->validator->optionalTrimmedString($payload, 'categoryName') ?? $categorySlug;
            $slug = $this->validator->requireNonEmptyString($payload, 'slug', 'invalid_slug');
            $title = $this->validator->requireNonEmptyString($payload, 'title', 'invalid_title');
            $body = $this->validator->requireNonEmptyString($payload, 'body', 'invalid_body');
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }

        try {
            $tags = $this->normalizeBlogTags($payload['tags'] ?? null);
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }

        if ($tags === []) {
            return $this->json(['error' => 'missing_tags'], 422);
        }

        $before = $this->snapshotEntity('blog_articles', $slug);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $catStmt = $this->pdo->prepare('SELECT id FROM blog_categories WHERE slug = :slug LIMIT 1');
            $catStmt->execute(['slug' => $categorySlug]);
            $categoryId = $catStmt->fetchColumn();
            if ($categoryId === false) {
                $insertCat = $this->pdo->prepare(
                    'INSERT INTO blog_categories (slug, name, description, created_at, updated_at)
                     VALUES (:slug, :name, NULL, :created_at, :updated_at)'
                );
                $insertCat->execute([
                    'slug' => $categorySlug,
                    'name' => $categoryName === '' ? $categorySlug : $categoryName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $categoryId = (int) $this->pdo->lastInsertId();
            } else {
                $categoryId = (int) $categoryId;
            }

            $articleStmt = $this->pdo->prepare(
                'SELECT id FROM blog_articles WHERE category_id = :category_id AND slug = :slug LIMIT 1'
            );
            $articleStmt->execute(['category_id' => $categoryId, 'slug' => $slug]);
            $articleId = $articleStmt->fetchColumn();

            if ($articleId === false) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO blog_articles
                     (category_id, slug, title, excerpt, body, author_name, author_job_title, status, meta_title, meta_description, published_at, created_at, updated_at)
                     VALUES
                     (:category_id, :slug, :title, :excerpt, :body, :author_name, :author_job_title, :status, :meta_title, :meta_description, :published_at, :created_at, :updated_at)'
                );
                $insert->execute([
                    'category_id' => $categoryId,
                    'slug' => $slug,
                    'title' => $title,
                    'excerpt' => $payload['excerpt'] ?? null,
                    'body' => $body,
                    'author_name' => $payload['authorName'] ?? null,
                    'author_job_title' => $payload['authorJobTitle'] ?? null,
                    'status' => ((bool) ($payload['publish'] ?? true)) ? 'published' : 'draft',
                    'meta_title' => $payload['metaTitle'] ?? null,
                    'meta_description' => $payload['metaDescription'] ?? null,
                    'published_at' => ((bool) ($payload['publish'] ?? true)) ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $articleId = (int) $this->pdo->lastInsertId();
            } else {
                $articleId = (int) $articleId;
                $update = $this->pdo->prepare(
                    'UPDATE blog_articles
                     SET title = :title, excerpt = :excerpt, body = :body, author_name = :author_name,
                         author_job_title = :author_job_title, status = :status, meta_title = :meta_title,
                         meta_description = :meta_description, published_at = :published_at, updated_at = :updated_at
                     WHERE id = :id'
                );
                $update->execute([
                    'id' => $articleId,
                    'title' => $title,
                    'excerpt' => $payload['excerpt'] ?? null,
                    'body' => $body,
                    'author_name' => $payload['authorName'] ?? null,
                    'author_job_title' => $payload['authorJobTitle'] ?? null,
                    'status' => ((bool) ($payload['publish'] ?? true)) ? 'published' : 'draft',
                    'meta_title' => $payload['metaTitle'] ?? null,
                    'meta_description' => $payload['metaDescription'] ?? null,
                    'published_at' => ((bool) ($payload['publish'] ?? true)) ? $now : null,
                    'updated_at' => $now,
                ]);
            }

            $this->syncBlogArticleTags($articleId, $tags, $now);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $after = $this->snapshotEntity('blog_articles', $slug);
        $this->audit('upsert', 'blog_articles', $slug, $payload, $before, $after);
        return $this->json(['ok' => true, 'resource' => 'blog/articles', 'slug' => $slug], 200);
    }

    private function listBlogArticles(): Response
    {
        $stmt = $this->pdo->query(
            'SELECT a.id, a.slug, a.title, a.status, a.published_at, a.updated_at, c.slug AS category_slug, c.name AS category_name
             FROM blog_articles a
             INNER JOIN blog_categories c ON c.id = a.category_id
             ORDER BY a.updated_at DESC, a.id DESC'
        );
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->json(['ok' => true, 'items' => is_array($items) ? $items : []], 200);
    }

    private function getBlogArticle(array $payload): Response
    {
        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            return $this->json(['error' => 'invalid_slug'], 422);
        }
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.slug, a.title, a.excerpt, a.body, a.status, a.meta_title, a.meta_description, a.published_at,
                    c.slug AS category_slug, c.name AS category_name
             FROM blog_articles a
             INNER JOIN blog_categories c ON c.id = a.category_id
             WHERE a.slug = :slug
             ORDER BY a.id DESC
             LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($item)) {
            return $this->json(['error' => 'article_not_found'], 404);
        }
        return $this->json(['ok' => true, 'item' => $item], 200);
    }

    private function deleteBlogArticle(array $payload): Response
    {
        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            return $this->json(['error' => 'invalid_slug'], 422);
        }
        $before = $this->snapshotEntity('blog_articles', $slug);
        if ($before === null) {
            return $this->json(['error' => 'article_not_found'], 404);
        }
        $stmt = $this->pdo->prepare('UPDATE blog_articles SET status = :status, updated_at = :updated_at WHERE slug = :slug');
        $stmt->execute([
            'status' => 'archived',
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'slug' => $slug,
        ]);
        $after = $this->snapshotEntity('blog_articles', $slug);
        $this->audit('archive', 'blog_articles', $slug, $payload, $before, $after);
        return $this->json(['ok' => true, 'resource' => 'blog/articles/delete', 'slug' => $slug], 200);
    }

    /**
     * @return array<int,array{slug:string,name:string}>
     */
    private function normalizeBlogTags(mixed $rawTags): array
    {
        if (!is_array($rawTags)) {
            throw new ValidationException('invalid_tags');
        }

        $normalized = [];
        foreach ($rawTags as $rawTag) {
            if (is_string($rawTag)) {
                $slug = $this->slugify($rawTag);
                if ($slug === '') {
                    continue;
                }
                $normalized[$slug] = ['slug' => $slug, 'name' => ucfirst(str_replace('-', ' ', $slug))];
                continue;
            }

            if (!is_array($rawTag)) {
                continue;
            }

            $source = (string) ($rawTag['slug'] ?? $rawTag['name'] ?? '');
            $slug = $this->slugify($source);
            if ($slug === '') {
                continue;
            }
            $name = trim((string) ($rawTag['name'] ?? ''));
            $normalized[$slug] = [
                'slug' => $slug,
                'name' => $name !== '' ? $name : ucfirst(str_replace('-', ' ', $slug)),
            ];
        }

        return array_values($normalized);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/[^a-z0-9]+/', '-', $value);
        if (!is_string($value)) {
            return '';
        }
        return trim($value, '-');
    }

    /**
     * @param array<int,array{slug:string,name:string}> $tags
     */
    private function syncBlogArticleTags(int $articleId, array $tags, string $now): void
    {
        if (!$this->hasTable('blog_tags') || !$this->hasTable('blog_article_tags')) {
            return;
        }
        $tagIds = [];
        $selectTag = $this->pdo->prepare('SELECT id FROM blog_tags WHERE slug = :slug LIMIT 1');
        $insertTag = $this->pdo->prepare(
            'INSERT INTO blog_tags (slug, name, created_at, updated_at)
             VALUES (:slug, :name, :created_at, :updated_at)'
        );
        $updateTag = $this->pdo->prepare(
            'UPDATE blog_tags
             SET name = :name, updated_at = :updated_at
             WHERE id = :id'
        );

        foreach ($tags as $tag) {
            $selectTag->execute(['slug' => $tag['slug']]);
            $tagId = $selectTag->fetchColumn();
            if ($tagId === false) {
                $insertTag->execute([
                    'slug' => $tag['slug'],
                    'name' => $tag['name'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $tagIds[] = (int) $this->pdo->lastInsertId();
                continue;
            }

            $tagId = (int) $tagId;
            $updateTag->execute([
                'id' => $tagId,
                'name' => $tag['name'],
                'updated_at' => $now,
            ]);
            $tagIds[] = $tagId;
        }

        $this->pdo->prepare('DELETE FROM blog_article_tags WHERE article_id = :article_id')
            ->execute(['article_id' => $articleId]);
        $insertPivot = $this->pdo->prepare(
            'INSERT INTO blog_article_tags (article_id, tag_id)
             VALUES (:article_id, :tag_id)'
        );
        foreach ($tagIds as $tagId) {
            $insertPivot->execute([
                'article_id' => $articleId,
                'tag_id' => $tagId,
            ]);
        }
    }

    private function upsertFaqItem(array $payload): Response
    {
        try {
            $question = $this->validator->requireNonEmptyString($payload, 'question', 'invalid_question');
            $answer = $this->validator->requireNonEmptyString($payload, 'answer', 'invalid_answer');
            $categorySlug = $this->validator->optionalTrimmedString($payload, 'categorySlug') ?: 'general';
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $before = null;

        $this->pdo->beginTransaction();
        try {
            $catStmt = $this->pdo->prepare('SELECT id FROM faq_categories WHERE slug = :slug LIMIT 1');
            $catStmt->execute(['slug' => $categorySlug]);
            $categoryId = $catStmt->fetchColumn();
            if ($categoryId === false) {
                $insertCat = $this->pdo->prepare(
                    'INSERT INTO faq_categories (slug, name, created_at, updated_at)
                     VALUES (:slug, :name, :created_at, :updated_at)'
                );
                $insertCat->execute([
                    'slug' => $categorySlug,
                    'name' => (string) ($payload['categoryName'] ?? ucfirst($categorySlug)),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $categoryId = (int) $this->pdo->lastInsertId();
            } else {
                $categoryId = (int) $categoryId;
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO faq_items (category_id, question, answer, order_index, status, created_at, updated_at)
                 VALUES (:category_id, :question, :answer, :order_index, :status, :created_at, :updated_at)'
            );
            $insert->execute([
                'category_id' => $categoryId,
                'question' => $question,
                'answer' => $answer,
                'order_index' => (int) ($payload['order'] ?? 0),
                'status' => ((bool) ($payload['publish'] ?? true)) ? 'published' : 'draft',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $after = $this->snapshotEntity('faq_items', (string) $id);
        $this->audit('create', 'faq_items', (string) $id, $payload, $before, $after);
        return $this->json(['ok' => true, 'resource' => 'faq/items', 'id' => $id], 201);
    }

    private function listFaqItems(): Response
    {
        $stmt = $this->pdo->query(
            'SELECT f.id, f.question, f.answer, f.order_index, f.status, c.slug AS category_slug, c.name AS category_name
             FROM faq_items f
             LEFT JOIN faq_categories c ON c.id = f.category_id
             ORDER BY f.order_index ASC, f.id ASC'
        );
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->json(['ok' => true, 'items' => is_array($items) ? $items : []], 200);
    }

    private function deleteFaqItem(array $payload): Response
    {
        $id = (int) ($payload['id'] ?? 0);
        if ($id < 1) {
            return $this->json(['error' => 'invalid_id'], 422);
        }
        $before = $this->snapshotEntity('faq_items', (string) $id);
        if ($before === null) {
            return $this->json(['error' => 'faq_item_not_found'], 404);
        }
        $stmt = $this->pdo->prepare('DELETE FROM faq_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $this->audit('delete', 'faq_items', (string) $id, $payload, $before, null);
        return $this->json(['ok' => true, 'resource' => 'faq/items/delete', 'id' => $id], 200);
    }

    private function upsertLegalPage(array $payload): Response
    {
        try {
            $slug = $this->validator->requireNonEmptyString($payload, 'slug', 'invalid_slug');
            $title = $this->validator->requireNonEmptyString($payload, 'title', 'invalid_title');
            $paragraphs = $this->validator->requireArray($payload, 'paragraphs', 'invalid_paragraphs');
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }

        $before = $this->snapshotEntity('legal_pages', $slug);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $status = ((bool) ($payload['publish'] ?? true)) ? 'published' : 'draft';
        $publishedAt = $status === 'published' ? $now : null;
        $paragraphsJson = json_encode(array_values($paragraphs), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($paragraphsJson === false) {
            return $this->json(['error' => 'invalid_payload'], 422);
        }

        $stmt = $this->pdo->prepare('SELECT id, version FROM legal_pages WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            $insert = $this->pdo->prepare(
                'INSERT INTO legal_pages (slug, title, paragraphs, version, status, published_at, created_at, updated_at)
                 VALUES (:slug, :title, :paragraphs, 1, :status, :published_at, :created_at, :updated_at)'
            );
            $insert->execute([
                'slug' => $slug,
                'title' => $title,
                'paragraphs' => $paragraphsJson,
                'status' => $status,
                'published_at' => $publishedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $version = (int) $row['version'] + 1;
            $update = $this->pdo->prepare(
                'UPDATE legal_pages
                 SET title = :title, paragraphs = :paragraphs, version = :version, status = :status, published_at = :published_at, updated_at = :updated_at
                 WHERE id = :id'
            );
            $update->execute([
                'id' => (int) $row['id'],
                'title' => $title,
                'paragraphs' => $paragraphsJson,
                'version' => $version,
                'status' => $status,
                'published_at' => $publishedAt,
                'updated_at' => $now,
            ]);
        }

        $after = $this->snapshotEntity('legal_pages', $slug);
        $this->audit('upsert', 'legal_pages', $slug, $payload, $before, $after);
        return $this->json(['ok' => true, 'resource' => 'legal/pages', 'slug' => $slug], 200);
    }

    private function listLegalPages(): Response
    {
        $stmt = $this->pdo->query(
            'SELECT id, slug, title, version, status, published_at, updated_at
             FROM legal_pages
             ORDER BY updated_at DESC, id DESC'
        );
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->json(['ok' => true, 'items' => is_array($items) ? $items : []], 200);
    }

    private function getLegalPage(array $payload): Response
    {
        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            return $this->json(['error' => 'invalid_slug'], 422);
        }
        $stmt = $this->pdo->prepare('SELECT * FROM legal_pages WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($item)) {
            return $this->json(['error' => 'legal_page_not_found'], 404);
        }
        return $this->json(['ok' => true, 'item' => $item], 200);
    }

    private function deleteLegalPage(array $payload): Response
    {
        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            return $this->json(['error' => 'invalid_slug'], 422);
        }
        $before = $this->snapshotEntity('legal_pages', $slug);
        if ($before === null) {
            return $this->json(['error' => 'legal_page_not_found'], 404);
        }
        $stmt = $this->pdo->prepare('UPDATE legal_pages SET status = :status, updated_at = :updated_at WHERE slug = :slug');
        $stmt->execute([
            'status' => 'archived',
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'slug' => $slug,
        ]);
        $after = $this->snapshotEntity('legal_pages', $slug);
        $this->audit('archive', 'legal_pages', $slug, $payload, $before, $after);
        return $this->json(['ok' => true, 'resource' => 'legal/pages/delete', 'slug' => $slug], 200);
    }

    private function updateOrderShipment(array $payload): Response
    {
        try {
            $orderId = $this->validator->requireUuidString($payload, 'orderId', 'invalid_order_id');
            $carrier = $this->validator->requireNonEmptyString($payload, 'carrier', 'invalid_carrier');
            $trackingNumber = $this->validator->requireNonEmptyString($payload, 'trackingNumber', 'invalid_tracking_number');
            $status = strtolower($this->validator->optionalTrimmedString($payload, 'status') ?? 'shipped');
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }

        $before = $this->snapshotEntity('order_shipments', $orderId);
        $ok = (new PdoOrderRepository())->updateShipmentTracking(
            Uuid::fromString($orderId),
            $carrier,
            $trackingNumber,
            $status
        );
        if (!$ok) {
            return $this->json(['error' => 'shipment_update_failed'], 409);
        }

        $after = $this->snapshotEntity('order_shipments', $orderId);
        $this->audit('update', 'order_shipments', $orderId, $payload, $before, $after);
        $notificationData = (new PdoOrderRepository())->findOrderNotificationData(Uuid::fromString($orderId));
        if (is_array($notificationData) && isset($notificationData['customer_email']) && is_string($notificationData['customer_email']) && $notificationData['customer_email'] !== '') {
            (new TransactionalEmailService())->send('order_shipped', $notificationData['customer_email'], [
                'order_id' => $orderId,
                'tracking_number' => $trackingNumber,
                'carrier' => $carrier,
                'status' => $status,
            ]);
        }
        return $this->json(['ok' => true, 'resource' => 'orders/shipments', 'orderId' => $orderId], 200);
    }

    private function updateOrderStatus(array $payload): Response
    {
        try {
            $orderId = $this->validator->requireUuidString($payload, 'orderId', 'invalid_order_id');
            $toStatus = strtolower($this->validator->requireNonEmptyString($payload, 'status', 'invalid_status'));
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }

        $allowed = ['pending', 'authorized', 'paid', 'fulfilled', 'cancelled', 'refunded'];
        if (!in_array($toStatus, $allowed, true)) {
            return $this->json(['error' => 'invalid_status'], 422);
        }

        $before = $this->snapshotEntity('orders', $orderId);
        $ok = (new PdoOrderRepository())->transitionStatus(Uuid::fromString($orderId), $toStatus);
        if (!$ok) {
            return $this->json(['error' => 'invalid_status_transition_or_missing_order'], 409);
        }
        $after = $this->snapshotEntity('orders', $orderId);
        $this->audit('update_status', 'orders', $orderId, $payload, $before, $after);

        return $this->json([
            'ok' => true,
            'resource' => 'orders/status',
            'orderId' => $orderId,
            'status' => $toStatus,
        ], 200);
    }

    private function upsertCoupon(array $payload): Response
    {
        try {
            $code = strtoupper($this->validator->requireNonEmptyString($payload, 'code', 'invalid_code'));
            $type = strtolower($this->validator->requireNonEmptyString($payload, 'type', 'invalid_type'));
            if (!in_array($type, ['percent', 'fixed'], true)) {
                return $this->json(['error' => 'invalid_type'], 422);
            }
            $value = (float) ($payload['value'] ?? 0);
            if ($value <= 0) {
                return $this->json(['error' => 'invalid_value'], 422);
            }

            $before = $this->snapshotEntity('coupons', $code);
            $couponId = (new PdoCouponRepository())->upsert([
                'code' => $code,
                'type' => $type,
                'value' => $value,
                'minOrderTotal' => isset($payload['minOrderTotal']) ? (float) $payload['minOrderTotal'] : null,
                'maxUses' => isset($payload['maxUses']) ? (int) $payload['maxUses'] : null,
                'maxUsesPerUser' => isset($payload['maxUsesPerUser']) ? (int) $payload['maxUsesPerUser'] : null,
                'startsAt' => $payload['startsAt'] ?? null,
                'endsAt' => $payload['endsAt'] ?? null,
                'appliesTo' => isset($payload['appliesTo']) && is_array($payload['appliesTo']) ? $payload['appliesTo'] : ['scope' => 'global'],
            ]);
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }

        $after = $this->snapshotEntity('coupons', $code);
        $this->audit('upsert', 'coupons', (string) $couponId, $payload, $before ?? null, $after);
        return $this->json(['ok' => true, 'resource' => 'coupons', 'id' => $couponId, 'code' => $code], 200);
    }

    private function listPlugins(): Response
    {
        $items = (new PdoAdminPluginRepository())->listAll();
        $inspector = new PluginRuntimeInspector();
        foreach ($items as &$item) {
            if (!is_array($item)) {
                continue;
            }
            $pluginKey = strtolower(trim((string) ($item['plugin_key'] ?? '')));
            $config = isset($item['config']) && is_array($item['config']) ? $item['config'] : [];
            $mode = (string) ($item['mode'] ?? 'sandbox');
            $item['runtime'] = $inspector->validate($pluginKey, $config, $mode);
        }
        unset($item);
        return $this->json(['ok' => true, 'items' => $items], 200);
    }

    private function togglePlugin(array $payload): Response
    {
        try {
            $pluginKey = strtolower($this->validator->requireNonEmptyString($payload, 'pluginKey', 'invalid_plugin_key'));
            $enabled = (bool) ($payload['enabled'] ?? false);
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }

        $repo = new PdoAdminPluginRepository();
        if ($enabled) {
            $validation = (new PluginRuntimeInspector())->validate($pluginKey);
            if (($validation['ok'] ?? false) !== true) {
                return $this->json([
                    'error' => 'plugin_config_invalid',
                    'pluginKey' => $pluginKey,
                    'validation' => $validation,
                ], 422);
            }
        }
        $before = $this->snapshotEntity('admin_plugins', $pluginKey);
        $ok = $repo->setEnabled($pluginKey, $enabled);
        if (!$ok) {
            return $this->json(['error' => 'plugin_update_failed'], 409);
        }
        $after = $this->snapshotEntity('admin_plugins', $pluginKey);
        $this->audit('toggle_plugin', 'admin_plugins', $pluginKey, $payload, $before, $after);

        return $this->json(['ok' => true, 'pluginKey' => $pluginKey, 'enabled' => $enabled], 200);
    }

    private function updatePluginConfig(array $payload): Response
    {
        try {
            $pluginKey = strtolower($this->validator->requireNonEmptyString($payload, 'pluginKey', 'invalid_plugin_key'));
            $mode = strtolower($this->validator->optionalTrimmedString($payload, 'mode') ?? 'sandbox');
            $priority = (int) ($payload['priority'] ?? 100);
            $publicLabel = $this->validator->optionalTrimmedString($payload, 'publicLabel');
            $config = isset($payload['config']) && is_array($payload['config']) ? $payload['config'] : [];
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }

        $repo = new PdoAdminPluginRepository();
        $before = $this->snapshotEntity('admin_plugins', $pluginKey);
        $ok = $repo->upsertConfig($pluginKey, $mode, $priority, $publicLabel, $config);
        if (!$ok) {
            return $this->json(['error' => 'plugin_config_update_failed'], 409);
        }
        $after = $this->snapshotEntity('admin_plugins', $pluginKey);
        $this->audit('plugin_config', 'admin_plugins', $pluginKey, $payload, $before, $after);

        return $this->json(['ok' => true, 'pluginKey' => $pluginKey], 200);
    }

    private function validatePluginConfig(array $payload): Response
    {
        try {
            $pluginKey = strtolower($this->validator->requireNonEmptyString($payload, 'pluginKey', 'invalid_plugin_key'));
            $mode = strtolower($this->validator->optionalTrimmedString($payload, 'mode') ?? 'sandbox');
            $config = isset($payload['config']) && is_array($payload['config']) ? $payload['config'] : null;
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }

        $validation = (new PluginRuntimeInspector())->validate($pluginKey, $config, $mode);
        return $this->json(['ok' => true, 'pluginKey' => $pluginKey, 'validation' => $validation], 200);
    }

    private function pluginDiagnostics(array $payload): Response
    {
        try {
            $pluginKey = strtolower($this->validator->requireNonEmptyString($payload, 'pluginKey', 'invalid_plugin_key'));
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }

        $repo = new PdoAdminPluginRepository();
        $runtime = $repo->getRuntimeConfig($pluginKey);
        $validation = (new PluginRuntimeInspector())->validate($pluginKey);
        $webhooks = [];
        if (in_array($pluginKey, ['stripe', 'paypal'], true)) {
            $webhooks = (new PdoWebhookEventRepository())->listRecentByProvider($pluginKey, 10);
        }

        return $this->json([
            'ok' => true,
            'pluginKey' => $pluginKey,
            'runtime' => [
                'mode' => (string) ($runtime['mode'] ?? 'sandbox'),
                'config' => $this->redactSecrets(is_array($runtime['config'] ?? null) ? $runtime['config'] : []),
            ],
            'validation' => $validation,
            'webhooks' => $webhooks,
        ], 200);
    }

    private function getTrackingSettings(): Response
    {
        $repo = new PdoCmsV2Repository();
        $stored = $repo->getSiteSettingByKey(TrackingIntegrationsService::SETTING_KEY);
        $integrations = $stored !== null
            ? TrackingIntegrationsService::mergeWithDefaults($stored)
            : TrackingIntegrationsService::defaults();

        return $this->json(['ok' => true, 'integrations' => $integrations], 200);
    }

    private function saveTrackingSettings(array $payload): Response
    {
        $raw = [];
        if (isset($payload['integrations']) && is_array($payload['integrations'])) {
            $raw = $payload['integrations'];
        }

        try {
            $normalized = TrackingIntegrationsService::validateAndMerge($raw);
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }

        $upsert = [
            'settingKey' => TrackingIntegrationsService::SETTING_KEY,
            'settingValue' => $normalized,
        ];
        if ($this->currentAdminUserId !== null && $this->currentAdminUserId > 0) {
            $upsert['adminUserId'] = $this->currentAdminUserId;
        }
        (new PdoCmsV2Repository())->upsertSiteSettings($upsert);
        $this->audit('upsert', 'cms_site_settings', TrackingIntegrationsService::SETTING_KEY, $payload);

        return $this->json(['ok' => true, 'integrations' => $normalized], 200);
    }

    private function listTaxRules(): Response
    {
        $items = (new PdoAdminTaxRuleRepository())->listAll();
        return $this->json(['ok' => true, 'items' => $items], 200);
    }

    private function upsertTaxRule(array $payload): Response
    {
        try {
            $countryCode = strtoupper($this->validator->requireNonEmptyString($payload, 'countryCode', 'invalid_country_code'));
            $regionCode = $this->validator->optionalTrimmedString($payload, 'regionCode');
            $taxType = strtolower($this->validator->optionalTrimmedString($payload, 'taxType') ?? 'vat');
            $rate = (float) ($payload['rate'] ?? 0);
            $isDefault = (bool) ($payload['isDefault'] ?? false);
            $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }
        $ok = (new PdoAdminTaxRuleRepository())->upsert($countryCode, $regionCode, $taxType, $rate, $isDefault, $metadata);
        if (!$ok) {
            return $this->json(['error' => 'tax_rule_upsert_failed'], 409);
        }
        $this->audit('upsert', 'admin_tax_rules', $countryCode . ':' . ($regionCode ?? '-') . ':' . $taxType, $payload);
        return $this->json(['ok' => true], 200);
    }

    private function toggleTaxRule(array $payload): Response
    {
        $id = (int) ($payload['id'] ?? 0);
        $enabled = (bool) ($payload['enabled'] ?? false);
        if ($id < 1) {
            return $this->json(['error' => 'invalid_id'], 422);
        }
        $ok = (new PdoAdminTaxRuleRepository())->toggle($id, $enabled);
        if (!$ok) {
            return $this->json(['error' => 'tax_rule_toggle_failed'], 409);
        }
        $this->audit('toggle', 'admin_tax_rules', (string) $id, $payload);
        return $this->json(['ok' => true], 200);
    }

    private function listShippingCarriers(): Response
    {
        $items = (new PdoAdminShippingCarrierRepository())->listAll();
        return $this->json(['ok' => true, 'items' => $items], 200);
    }

    private function upsertShippingCarrier(array $payload): Response
    {
        try {
            $carrierKey = strtolower($this->validator->requireNonEmptyString($payload, 'carrierKey', 'invalid_carrier_key'));
            $label = $this->validator->requireNonEmptyString($payload, 'label', 'invalid_label');
            $zonesRaw = isset($payload['zones']) && is_array($payload['zones']) ? $payload['zones'] : [];
            $priority = (int) ($payload['priority'] ?? 100);
            $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }
        $zones = [];
        foreach ($zonesRaw as $zone) {
            if (is_string($zone) && trim($zone) !== '') {
                $zones[] = strtoupper(trim($zone));
            }
        }
        $ok = (new PdoAdminShippingCarrierRepository())->upsert($carrierKey, $label, $zones, $priority, $metadata);
        if (!$ok) {
            return $this->json(['error' => 'shipping_carrier_upsert_failed'], 409);
        }
        $this->audit('upsert', 'admin_shipping_carriers', $carrierKey, $payload);
        return $this->json(['ok' => true], 200);
    }

    private function toggleShippingCarrier(array $payload): Response
    {
        $id = (int) ($payload['id'] ?? 0);
        $enabled = (bool) ($payload['enabled'] ?? false);
        if ($id < 1) {
            return $this->json(['error' => 'invalid_id'], 422);
        }
        $ok = (new PdoAdminShippingCarrierRepository())->toggle($id, $enabled);
        if (!$ok) {
            return $this->json(['error' => 'shipping_carrier_toggle_failed'], 409);
        }
        $this->audit('toggle', 'admin_shipping_carriers', (string) $id, $payload);
        return $this->json(['ok' => true], 200);
    }

    private function listPaymentMethods(): Response
    {
        $items = (new PdoAdminPaymentMethodRepository())->listAll();
        return $this->json(['ok' => true, 'items' => $items], 200);
    }

    private function upsertPaymentMethod(array $payload): Response
    {
        try {
            $methodKey = strtolower($this->validator->requireNonEmptyString($payload, 'methodKey', 'invalid_method_key'));
            $label = $this->validator->requireNonEmptyString($payload, 'label', 'invalid_label');
            $provider = strtolower($this->validator->requireNonEmptyString($payload, 'provider', 'invalid_provider'));
            $mode = strtolower($this->validator->optionalTrimmedString($payload, 'mode') ?? 'sandbox');
            $priority = (int) ($payload['priority'] ?? 100);
            $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        }
        $ok = (new PdoAdminPaymentMethodRepository())->upsert($methodKey, $label, $provider, $mode, $priority, $metadata);
        if (!$ok) {
            return $this->json(['error' => 'payment_method_upsert_failed'], 409);
        }
        $this->audit('upsert', 'admin_payment_methods', $methodKey, $payload);
        return $this->json(['ok' => true], 200);
    }

    private function togglePaymentMethod(array $payload): Response
    {
        $id = (int) ($payload['id'] ?? 0);
        $enabled = (bool) ($payload['enabled'] ?? false);
        if ($id < 1) {
            return $this->json(['error' => 'invalid_id'], 422);
        }
        $ok = (new PdoAdminPaymentMethodRepository())->toggle($id, $enabled);
        if (!$ok) {
            return $this->json(['error' => 'payment_method_toggle_failed'], 409);
        }
        $this->audit('toggle', 'admin_payment_methods', (string) $id, $payload);
        return $this->json(['ok' => true], 200);
    }

    private function refundOrder(array $payload): Response
    {
        try {
            $orderId = $this->validator->requireUuidString($payload, 'orderId', 'invalid_order_id');
            $amount = isset($payload['amount']) ? (float) $payload['amount'] : 0.0;
            if ($amount <= 0) {
                return $this->json(['error' => 'invalid_refund_amount'], 422);
            }
            $reason = $this->validator->optionalTrimmedString($payload, 'reason');
            $before = $this->snapshotEntity('orders', $orderId);
            $result = (new RefundService())->refundOrder($orderId, $amount, $reason);
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }

        $after = $this->snapshotEntity('orders', $orderId);
        $this->audit('refund', 'orders', $orderId, $payload, $before ?? null, $after);
        return $this->json([
            'ok' => true,
            'resource' => 'orders/refunds',
            'refundId' => $result['refundId'],
            'orderId' => $result['orderId'],
            'amount' => $result['amount'],
            'currency' => $result['currency'],
            'status' => $result['status'],
            'fullyRefunded' => $result['fullyRefunded'],
        ], 200);
    }

    private function searchProducts(array $payload): Response
    {
        $q = strtolower(trim((string) ($payload['q'] ?? '')));
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        $type = strtolower(trim((string) ($payload['type'] ?? '')));
        $limit = max(1, min(100, (int) ($payload['limit'] ?? 30)));
        $offset = max(0, (int) ($payload['offset'] ?? 0));

        $sql = "SELECT
                p.id,
                p.sku,
                p.name,
                p.slug,
                p.price,
                p.sale_price,
                p.status,
                p.type,
                p.updated_at,
                COALESCE((
                    SELECT b.name
                    FROM product_brand_pivot pbp
                    INNER JOIN brands b ON b.id = pbp.brand_id
                    WHERE pbp.product_id = p.id
                    ORDER BY b.id ASC
                    LIMIT 1
                ), 'Marque') AS brand_name,
                EXISTS(
                    SELECT 1
                    FROM product_images pi
                    WHERE pi.product_id = p.id
                    LIMIT 1
                ) AS has_image,
                (
                    SELECT CAST(REPLACE(NULLIF(ts.value, ''), ',', '.') AS DECIMAL(12,2))
                    FROM product_technical_specs ts
                    WHERE ts.product_id = p.id
                      AND LOWER(ts.label) LIKE '%achat%'
                    ORDER BY ts.position ASC, ts.id ASC
                    LIMIT 1
                ) AS purchase_price,
                (
                    SELECT CAST(REPLACE(NULLIF(ts.value, ''), ',', '.') AS DECIMAL(12,2))
                    FROM product_technical_specs ts
                    WHERE ts.product_id = p.id
                      AND LOWER(ts.label) LIKE '%marge%'
                    ORDER BY ts.position ASC, ts.id ASC
                    LIMIT 1
                ) AS margin,
                (
                    SELECT s.score_global
                    FROM eeat_scores s
                    WHERE s.entity_type = 'product'
                      AND s.entity_id = p.id
                      AND s.locale = 'fr'
                    ORDER BY s.computed_at DESC
                    LIMIT 1
                ) AS seo_score
                FROM products p
                WHERE 1=1";
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (LOWER(p.name) LIKE :q OR LOWER(p.sku) LIKE :q OR LOWER(p.slug) LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($status !== '') {
            $sql .= ' AND p.status = :status';
            $params['status'] = $status;
        }
        if ($type !== '') {
            $sql .= ' AND p.type = :type';
            $params['type'] = $type;
        }
        $sql .= ' ORDER BY p.updated_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->json(['ok' => true, 'items' => is_array($items) ? $items : []], 200);
    }

    private function listProductImages(array $payload): Response
    {
        $productId = trim((string) ($payload['productId'] ?? ''));
        if (!Uuid::isValid($productId)) {
            return $this->json(['error' => 'invalid_product_id'], 422);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, product_id, url, alt, position
             FROM product_images
             WHERE product_id = :product_id
             ORDER BY position ASC, id ASC'
        );
        $stmt->execute(['product_id' => $productId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->json(['ok' => true, 'items' => is_array($items) ? $items : []], 200);
    }

    private function bulkUpdateProducts(array $payload): Response
    {
        if (!isset($payload['operations']) || !is_array($payload['operations'])) {
            return $this->json(['error' => 'invalid_operations'], 422);
        }
        $operations = $payload['operations'];
        $result = (new PdoAdminMutationRepository())->bulkUpdateProducts($operations);
        $this->audit('bulk_update', 'products', 'multiple', $payload);
        return $this->json([
            'ok' => true,
            'resource' => 'products/bulk',
            'updated' => $result['updated'],
            'errors' => $result['errors'],
        ], 200);
    }

    private function upsertTranslation(array $payload): Response
    {
        $entity = strtolower(trim((string) ($payload['entity'] ?? '')));
        $locale = strtolower(trim((string) ($payload['locale'] ?? 'fr')));
        if ($locale === '' || strlen($locale) > 8) {
            return $this->json(['error' => 'invalid_locale'], 422);
        }

        if ($entity === 'product') {
            if (!$this->hasTable('product_translations')) {
                return $this->json(['error' => 'missing_table_product_translations'], 409);
            }
            $productId = trim((string) ($payload['productId'] ?? ''));
            if (!Uuid::isValid($productId)) {
                return $this->json(['error' => 'invalid_product_id'], 422);
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO product_translations (product_id, locale, name, description, seo_title, seo_description)
                 VALUES (:id, :locale, :name, :description, :seo_title, :seo_description)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    description = VALUES(description),
                    seo_title = VALUES(seo_title),
                    seo_description = VALUES(seo_description)'
            );
            $stmt->execute([
                'id' => $productId,
                'locale' => $locale,
                'name' => (string) ($payload['name'] ?? ''),
                'description' => $payload['description'] ?? null,
                'seo_title' => $payload['seoTitle'] ?? null,
                'seo_description' => $payload['seoDescription'] ?? null,
            ]);
            $this->audit('upsert_translation', 'product_translations', $productId . ':' . $locale, $payload);
            return $this->json(['ok' => true], 200);
        }

        if ($entity === 'category') {
            if (!$this->hasTable('product_category_translations')) {
                return $this->json(['error' => 'missing_table_category_translations'], 409);
            }
            $categoryId = (int) ($payload['categoryId'] ?? 0);
            if ($categoryId < 1) {
                return $this->json(['error' => 'invalid_category_id'], 422);
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO product_category_translations (category_id, locale, name, description)
                 VALUES (:id, :locale, :name, :description)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    description = VALUES(description)'
            );
            $stmt->execute([
                'id' => $categoryId,
                'locale' => $locale,
                'name' => (string) ($payload['name'] ?? ''),
                'description' => $payload['description'] ?? null,
            ]);
            $this->audit('upsert_translation', 'product_category_translations', (string) $categoryId . ':' . $locale, $payload);
            return $this->json(['ok' => true], 200);
        }

        if ($entity === 'cms_page') {
            if (!$this->hasTable('cms_page_translations')) {
                return $this->json(['error' => 'missing_table_cms_page_translations'], 409);
            }
            $pageSlug = trim((string) ($payload['pageSlug'] ?? ''));
            if ($pageSlug === '') {
                return $this->json(['error' => 'invalid_page_slug'], 422);
            }
            $idStmt = $this->pdo->prepare('SELECT id FROM cms_pages WHERE slug = :slug LIMIT 1');
            $idStmt->execute(['slug' => $pageSlug]);
            $pageId = $idStmt->fetchColumn();
            if ($pageId === false) {
                return $this->json(['error' => 'page_not_found'], 404);
            }
            $stmt = $this->pdo->prepare(
                'INSERT INTO cms_page_translations (page_id, locale, title, meta_title, meta_description)
                 VALUES (:id, :locale, :title, :meta_title, :meta_description)
                 ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    meta_title = VALUES(meta_title),
                    meta_description = VALUES(meta_description)'
            );
            $stmt->execute([
                'id' => (int) $pageId,
                'locale' => $locale,
                'title' => (string) ($payload['title'] ?? ''),
                'meta_title' => $payload['metaTitle'] ?? null,
                'meta_description' => $payload['metaDescription'] ?? null,
            ]);
            $this->audit('upsert_translation', 'cms_page_translations', (string) $pageId . ':' . $locale, $payload);
            return $this->json(['ok' => true], 200);
        }

        return $this->json(['error' => 'invalid_entity'], 422);
    }

    private function splitOrderShipments(array $payload): Response
    {
        try {
            $orderId = $this->validator->requireUuidString($payload, 'orderId', 'invalid_order_id');
            $shipments = $this->validator->requireArray($payload, 'shipments', 'invalid_shipments');
            $before = $this->snapshotEntity('orders', $orderId);
            $result = (new SplitShipmentService())->split($orderId, $shipments);
            $after = $this->snapshotEntity('orders', $orderId);
            $this->audit('split_shipments', 'orders', $orderId, $payload, $before, $after);
            return $this->json([
                'ok' => true,
                'resource' => 'orders/split-shipments',
                'orderId' => $orderId,
                'createdShipments' => $result['created'],
                'fullyAllocated' => $result['fullyAllocated'],
            ], 200);
        } catch (ValidationException $e) {
            return $this->json(['error' => $e->errorCode()], 422);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 409);
        }
    }

    private function searchOrders(array $payload): Response
    {
        $q = strtolower(trim((string) ($payload['q'] ?? '')));
        $status = strtolower(trim((string) ($payload['status'] ?? '')));
        $paymentMethod = strtolower(trim((string) ($payload['paymentMethod'] ?? '')));
        $limit = max(1, min(100, (int) ($payload['limit'] ?? 30)));
        $offset = max(0, (int) ($payload['offset'] ?? 0));

        $sql = 'SELECT o.id, o.number, o.status, o.total, o.currency, o.payment_method, o.placed_at, o.updated_at, c.email AS customer_email
                FROM orders o
                LEFT JOIN customers c ON c.id = o.customer_id
                WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (LOWER(o.number) LIKE :q OR LOWER(o.id) LIKE :q OR LOWER(COALESCE(c.email, \'\')) LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        if ($status !== '') {
            $sql .= ' AND o.status = :status';
            $params['status'] = $status;
        }
        if ($paymentMethod !== '') {
            $sql .= ' AND o.payment_method = :payment_method';
            $params['payment_method'] = $paymentMethod;
        }
        $sql .= ' ORDER BY o.placed_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->json(['ok' => true, 'items' => is_array($items) ? $items : []], 200);
    }

    private function searchCustomers(array $payload): Response
    {
        $q = strtolower(trim((string) ($payload['q'] ?? '')));
        $limit = max(1, min(100, (int) ($payload['limit'] ?? 30)));
        $offset = max(0, (int) ($payload['offset'] ?? 0));

        $sql = 'SELECT id, email, first_name, last_name, created_at, last_login_at
                FROM customers
                WHERE 1=1';
        $params = [];
        if ($q !== '') {
            $sql .= ' AND (LOWER(email) LIKE :q OR LOWER(COALESCE(first_name, \'\')) LIKE :q OR LOWER(COALESCE(last_name, \'\')) LIKE :q)';
            $params['q'] = '%' . $q . '%';
        }
        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $this->json(['ok' => true, 'items' => is_array($items) ? $items : []], 200);
    }

    private function listCustomerSegments(array $payload): Response
    {
        $segment = strtolower(trim((string) ($payload['segment'] ?? '')));
        $limit = max(1, min(200, (int) ($payload['limit'] ?? 50)));
        $offset = max(0, (int) ($payload['offset'] ?? 0));

        $sql = 'SELECT cs.customer_id, cs.segment_code, cs.score, cs.computed_at, c.email, c.first_name, c.last_name
                FROM customer_segments cs
                INNER JOIN customers c ON c.id = cs.customer_id
                WHERE 1=1';
        $params = [];
        if ($segment !== '') {
            $sql .= ' AND cs.segment_code = :segment';
            $params['segment'] = $segment;
        }
        $sql .= ' ORDER BY cs.score DESC, cs.computed_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->json(['ok' => true, 'items' => is_array($items) ? $items : []], 200);
    }

    private function assertAuthorized(Request $request, string $resource): ?Response
    {
        $this->currentIp = $request->getClientIp();
        if ($resource === 'auth/login') {
            return null;
        }

        $uiReview = trim((string) $request->headers->get('X-UI-Review', '')) === '1';
        $appEnv = strtolower((string) ($_ENV['APP_ENV'] ?? 'dev'));
        $nonProd = $appEnv !== 'prod' && $appEnv !== 'production';
        $uiReviewAllowedEnv = filter_var($_ENV['ADMIN_UI_REVIEW_ALLOWED'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($uiReview && $nonProd && $uiReviewAllowedEnv) {
            $this->currentAdminUserId = null;
            $this->currentAdminRole = 'super_admin';
            return null;
        }

        $requiredRole = $this->requiredRoleForResource($resource);

        $authorization = (string) $request->headers->get('Authorization', '');
        if (str_starts_with($authorization, 'Bearer ')) {
            $token = trim(substr($authorization, 7));
            try {
                $identity = (new AdminAuthService())->verifyBearer($token);
            } catch (\RuntimeException $e) {
                return $this->json(['error' => $e->getMessage()], 401);
            }
            if (!$this->isValidCsrf($request, $token)) {
                return $this->json(['error' => 'invalid_csrf_token'], 403);
            }
            $this->currentAdminUserId = (int) $identity['userId'];
            $this->currentAdminRole = (string) $identity['role'];
            if (!$this->roleMeetsRequirement($this->currentAdminRole, $requiredRole)) {
                return $this->json(['error' => 'forbidden'], 403);
            }
            return null;
        }

        // Legacy fallback is disabled by default; enable only for controlled transitions.
        $legacyAllowed = filter_var($_ENV['ALLOW_LEGACY_ADMIN_TOKEN'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$legacyAllowed) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        // Legacy fallback: static admin token, elevated to super_admin.
        $expected = $_ENV['ADMIN_API_TOKEN'] ?? null;
        if (!is_string($expected) || $expected === '') {
            return $this->json(['error' => 'admin_token_not_configured'], 500);
        }
        $provided = (string) $request->headers->get('X-Admin-Token', '');
        if ($provided === '' || !hash_equals($expected, $provided)) {
            return $this->json(['error' => 'unauthorized'], 401);
        }
        if (!$this->isValidCsrf($request, $expected)) {
            return $this->json(['error' => 'invalid_csrf_token'], 403);
        }
        $this->currentAdminUserId = null;
        $this->currentAdminRole = 'super_admin';

        return null;
    }

    private function requiredRoleForResource(string $resource): string
    {
        return match ($resource) {
            'orders/shipments' => 'admin',
            'orders/status' => 'admin',
            'orders/refunds' => 'admin',
            'orders/split-shipments' => 'admin',
            'translations/upsert' => 'admin',
            'coupons' => 'admin',
            'crm/segments' => 'admin',
            'plugins/list' => 'admin',
            'plugins/toggle' => 'admin',
            'plugins/config' => 'admin',
            'plugins/validate' => 'admin',
            'plugins/diagnostics' => 'admin',
            'settings/taxes/list' => 'admin',
            'settings/taxes/upsert' => 'admin',
            'settings/taxes/toggle' => 'admin',
            'settings/shipping/list' => 'admin',
            'settings/shipping/upsert' => 'admin',
            'settings/shipping/toggle' => 'admin',
            'settings/payments/list' => 'admin',
            'settings/payments/upsert' => 'admin',
            'settings/payments/toggle' => 'admin',
            'settings/tracking/get' => 'admin',
            'settings/tracking/save' => 'admin',
            default => 'manager',
        };
    }

    private function roleMeetsRequirement(string $role, string $requiredRole): bool
    {
        $weights = [
            'manager' => 1,
            'admin' => 2,
            'super_admin' => 3,
        ];
        $roleWeight = $weights[$role] ?? 0;
        $requiredWeight = $weights[$requiredRole] ?? PHP_INT_MAX;
        return $roleWeight >= $requiredWeight;
    }

    private function isValidCsrf(Request $request, string $secret): bool
    {
        $provided = trim((string) $request->headers->get('X-CSRF-Token', ''));
        if ($provided === '') {
            return false;
        }
        $expected = $this->buildCsrfTokenForSecret($secret);
        return hash_equals($expected, $provided);
    }

    private function buildCsrfTokenForSecret(string $secret): string
    {
        $appSecret = trim((string) ($_ENV['APP_SECRET'] ?? ''));
        if ($appSecret === '') {
            throw new \RuntimeException('app_secret_not_configured');
        }
        return hash_hmac('sha256', $secret, $appSecret);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function audit(string $action, string $entityType, string $entityId, array $data, ?array $before = null, ?array $after = null): void
    {
        $payload = json_encode([
            'who' => [
                'user_id' => $this->currentAdminUserId,
                'role' => $this->currentAdminRole,
            ],
            'when' => (new \DateTimeImmutable())->format(DATE_ATOM),
            'what' => [
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
            ],
            'ip' => $this->currentIp,
            'input' => $this->redactSecrets($data),
            'before' => $before,
            'after' => $after,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = '{}';
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, data, created_at, ip)
             VALUES (:user_id, :action, :entity_type, :entity_id, :data, :created_at, :ip)'
        );
        $stmt->execute([
            'user_id' => $this->currentAdminUserId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'data' => $payload,
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'ip' => $this->currentIp,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function snapshotEntity(string $entityType, string $entityId): ?array
    {
        return match ($entityType) {
            'cms_pages' => $this->snapshotBySql('SELECT * FROM cms_pages WHERE slug = :id LIMIT 1', $entityId),
            'blog_articles' => $this->snapshotBySql(
                'SELECT a.*, c.slug AS category_slug
                 FROM blog_articles a
                 INNER JOIN blog_categories c ON c.id = a.category_id
                 WHERE a.slug = :id
                 LIMIT 1',
                $entityId
            ),
            'faq_items' => $this->snapshotBySql('SELECT * FROM faq_items WHERE id = :id LIMIT 1', $entityId),
            'legal_pages' => $this->snapshotBySql('SELECT * FROM legal_pages WHERE slug = :id LIMIT 1', $entityId),
            'order_shipments' => $this->snapshotBySql('SELECT * FROM order_shipments WHERE order_id = :id LIMIT 1', $entityId),
            'coupons' => $this->snapshotBySql('SELECT * FROM coupons WHERE code = :id LIMIT 1', strtoupper($entityId)),
            'orders' => $this->snapshotBySql('SELECT * FROM orders WHERE id = :id LIMIT 1', $entityId),
            'admin_plugins' => $this->snapshotBySql('SELECT * FROM admin_plugins WHERE plugin_key = :id LIMIT 1', $entityId),
            default => null,
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private function snapshotBySql(string $sql, string $id): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function redactSecrets(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = strtolower((string) $k);
            $isSensitive = str_contains($key, 'secret')
                || str_contains($key, 'token')
                || str_contains($key, 'password')
                || str_contains($key, 'apikey')
                || str_contains($key, 'api_key')
                || str_contains($key, 'clientsecret')
                || str_contains($key, 'webhook');
            if (is_array($v)) {
                $out[$k] = $this->redactSecrets($v);
                continue;
            }
            if ($isSensitive && is_string($v) && $v !== '') {
                $out[$k] = '***';
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    private function hasTable(string $table): bool
    {
        if (array_key_exists($table, $this->tablePresenceCache)) {
            return $this->tablePresenceCache[$table];
        }
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table_name"
        );
        $stmt->execute(['table_name' => $table]);
        $exists = ((int) $stmt->fetchColumn()) > 0;
        $this->tablePresenceCache[$table] = $exists;
        return $exists;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function json(array $payload, int $status): Response
    {
        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json']
        );
    }
}
