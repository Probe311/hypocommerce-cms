<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Admin\AdminAuthService;
use App\Application\Cms\CmsWorkflowService;
use App\Application\Cms\MediaLibraryService;
use App\Infrastructure\Persistence\PdoCmsV2Repository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CmsV2AdminController
{
    private PdoCmsV2Repository $repo;
    private CmsWorkflowService $workflow;
    private MediaLibraryService $mediaService;

    public function __construct()
    {
        $this->repo = new PdoCmsV2Repository();
        $this->workflow = new CmsWorkflowService();
        $this->mediaService = new MediaLibraryService();
    }

    public function handle(Request $request, string $resource): Response
    {
        $adminIdentity = $this->authorize($request);
        if ($adminIdentity === null) {
            return $this->json(['error' => 'unauthorized'], 401);
        }

        $method = strtoupper($request->getMethod());
        $payload = $this->decode($request);
        $payload['adminUserId'] = isset($adminIdentity['userId']) ? (int) $adminIdentity['userId'] : null;

        try {
            return match ($resource) {
                'pages/upsert' => $method === 'POST'
                    ? $this->json(['ok' => true, 'slug' => $this->repo->upsertPage($payload)], 200)
                    : $this->json(['error' => 'method_not_allowed'], 405),
                'articles/categories/upsert' => $method === 'POST'
                    ? $this->json(['ok' => true, 'id' => $this->repo->upsertArticleCategory($payload)], 200)
                    : $this->json(['error' => 'method_not_allowed'], 405),
                'articles/upsert' => $method === 'POST'
                    ? $this->json(['ok' => true, 'slug' => $this->repo->upsertArticle($payload)], 200)
                    : $this->json(['error' => 'method_not_allowed'], 405),
                'media/upload' => $method === 'POST'
                    ? $this->uploadMedia($request, $payload)
                    : $this->json(['error' => 'method_not_allowed'], 405),
                'media/list' => $method === 'GET'
                    ? $this->json(['ok' => true, 'items' => $this->repo->listMedia($request->query->all())], 200)
                    : $this->json(['error' => 'method_not_allowed'], 405),
                'media/archive' => $method === 'POST'
                    ? $this->json(['ok' => $this->mediaService->archive((int) ($payload['mediaId'] ?? 0))], 200)
                    : $this->json(['error' => 'method_not_allowed'], 405),
                'navigation/menus/upsert' => $method === 'POST'
                    ? $this->json(['ok' => true, 'menuId' => $this->repo->upsertMenu($payload)], 200)
                    : $this->json(['error' => 'method_not_allowed'], 405),
                'site-settings/upsert' => $method === 'POST'
                    ? $this->upsertSettings($payload)
                    : $this->json(['error' => 'method_not_allowed'], 405),
                'workflow/transition' => $method === 'POST'
                    ? $this->transitionWorkflow($payload)
                    : $this->json(['error' => 'method_not_allowed'], 405),
                default => $this->json(['error' => 'not_found'], 404),
            };
        } catch (\Throwable) {
            return $this->json(['error' => 'invalid_request'], 422);
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function upsertSettings(array $payload): Response
    {
        if (isset($payload['socialLinks']) && is_array($payload['socialLinks'])) {
            $this->repo->upsertSiteSettings([
                'settingKey' => 'social_links',
                'settingValue' => $payload['socialLinks'],
                'adminUserId' => $payload['adminUserId'] ?? null,
            ]);
        }
        if (isset($payload['footer']) && is_array($payload['footer'])) {
            $this->repo->upsertSiteSettings([
                'settingKey' => 'footer',
                'settingValue' => $payload['footer'],
                'adminUserId' => $payload['adminUserId'] ?? null,
            ]);
        }
        if (isset($payload['settings']) && is_array($payload['settings'])) {
            foreach ($payload['settings'] as $key => $value) {
                if (!is_string($key) || trim($key) === '') {
                    continue;
                }
                $this->repo->upsertSiteSettings([
                    'settingKey' => $key,
                    'settingValue' => $value,
                    'adminUserId' => $payload['adminUserId'] ?? null,
                ]);
            }
        }
        return $this->json(['ok' => true], 200);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function transitionWorkflow(array $payload): Response
    {
        $entityType = trim((string) ($payload['entityType'] ?? ''));
        $entityId = trim((string) ($payload['entityId'] ?? ''));
        $fromStatus = trim((string) ($payload['fromStatus'] ?? 'draft'));
        $toStatus = trim((string) ($payload['toStatus'] ?? 'draft'));
        $note = isset($payload['note']) ? (string) $payload['note'] : null;
        $adminUserId = isset($payload['adminUserId']) ? (int) $payload['adminUserId'] : null;
        $this->workflow->transition($entityType, $entityId, $fromStatus, $toStatus, $adminUserId, $note);
        return $this->json(['ok' => true], 200);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function uploadMedia(Request $request, array $payload): Response
    {
        $file = $request->files->get('file');
        if ($file === null) {
            return $this->json(['error' => 'missing_file'], 422);
        }
        $result = $this->mediaService->upload([
            'tmp_name' => $file->getPathname(),
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize() ?? 0,
        ], [
            'folder' => $request->request->get('folder', 'general'),
            'title' => $request->request->get('title'),
            'altText' => $request->request->get('altText'),
            'caption' => $request->request->get('caption'),
            'description' => $request->request->get('description'),
            'adminUserId' => $payload['adminUserId'] ?? null,
        ]);
        return $this->json(['ok' => true, 'item' => $result], 201);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function authorize(Request $request): ?array
    {
        $authorization = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($authorization, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($authorization, 7));
        try {
            $identity = (new AdminAuthService())->verifyBearer($token);
        } catch (\Throwable) {
            return null;
        }
        if (!$this->isValidCsrf($request, $token)) {
            return null;
        }
        $role = (string) ($identity['role'] ?? '');
        if (!in_array($role, ['manager', 'admin', 'super_admin'], true)) {
            return null;
        }
        return $identity;
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(Request $request): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent() ?: '{}', true);
        return is_array($decoded) ? $decoded : [];
    }

    private function isValidCsrf(Request $request, string $secret): bool
    {
        $provided = trim((string) $request->headers->get('X-CSRF-Token', ''));
        if ($provided === '') {
            return false;
        }
        $appSecret = trim((string) ($_ENV['APP_SECRET'] ?? ''));
        if ($appSecret === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $secret, $appSecret);
        return hash_equals($expected, $provided);
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
