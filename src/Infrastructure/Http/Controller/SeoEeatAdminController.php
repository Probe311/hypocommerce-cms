<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Admin\AdminAuthService;
use App\Application\SeoEeat\EEATAnalysisService;
use App\Application\SeoEeat\SeoSettingsDefaultsApplier;
use App\Application\Validation\InputValidator;
use App\Infrastructure\Persistence\PdoEeatRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SeoEeatAdminController
{
    private InputValidator $validator;
    private PdoEeatRepository $repository;

    public function __construct()
    {
        $this->validator = new InputValidator();
        $this->repository = new PdoEeatRepository();
    }

    public function handle(Request $request, string $resource): Response
    {
        $auth = $this->authorize($request);
        if ($auth !== null) {
            return $auth;
        }

        return match ($resource) {
            'scores' => $request->getMethod() === 'GET' ? $this->listScores($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'recommendations' => $request->getMethod() === 'GET' ? $this->listRecommendations($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'recommendations/by-status' => $request->getMethod() === 'GET' ? $this->recommendationsByStatus($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'overview' => $request->getMethod() === 'GET' ? $this->overview() : $this->json(['error' => 'method_not_allowed'], 405),
            'opportunities' => $request->getMethod() === 'GET' ? $this->opportunities($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'quick-wins' => $request->getMethod() === 'GET' ? $this->quickWins($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'progress' => $request->getMethod() === 'GET' ? $this->progress() : $this->json(['error' => 'method_not_allowed'], 405),
            'run-trends' => $request->getMethod() === 'GET' ? $this->runTrends($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'recommendations/status' => $request->getMethod() === 'POST' ? $this->updateRecommendationStatus($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'recommendations/assign' => $request->getMethod() === 'POST' ? $this->assignRecommendation($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'recommendations/owners' => $request->getMethod() === 'GET' ? $this->recommendationOwners() : $this->json(['error' => 'method_not_allowed'], 405),
            'recommendations/overdue' => $request->getMethod() === 'GET' ? $this->overdueRecommendations($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'recommendations/due-soon' => $request->getMethod() === 'GET' ? $this->dueSoonRecommendations($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'sla' => $request->getMethod() === 'GET' ? $this->slaStats() : $this->json(['error' => 'method_not_allowed'], 405),
            'digest' => $request->getMethod() === 'GET' ? $this->digestStats($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'settings' => $request->getMethod() === 'GET' ? $this->getSettings() : ($request->getMethod() === 'POST' ? $this->saveSettings($request) : $this->json(['error' => 'method_not_allowed'], 405)),
            'settings/preview' => $request->getMethod() === 'GET' ? $this->settingsPreview($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'coverage-report' => $request->getMethod() === 'GET' ? $this->coverageReport() : $this->json(['error' => 'method_not_allowed'], 405),
            'auto-prioritize' => $request->getMethod() === 'POST' ? $this->autoPrioritize($request) : $this->json(['error' => 'method_not_allowed'], 405),
            'recompute' => $request->getMethod() === 'POST' ? $this->recompute($request) : $this->json(['error' => 'method_not_allowed'], 405),
            default => $this->scoreDetail($request, $resource),
        };
    }

    private function listScores(Request $request): Response
    {
        $limit = max(1, min(500, (int) $request->query->get('limit', '100')));
        $offset = max(0, (int) $request->query->get('offset', '0'));
        $entityType = trim((string) $request->query->get('entityType', ''));
        $rows = $this->repository->listScores($limit, $offset, $entityType !== '' ? $entityType : null);
        return $this->json(['ok' => true, 'items' => $rows], 200);
    }

    private function scoreDetail(Request $request, string $resource): Response
    {
        if ($request->getMethod() !== 'GET') {
            return $this->json(['error' => 'method_not_allowed'], 405);
        }
        $parts = explode('/', trim($resource, '/'));
        if (count($parts) !== 3 || $parts[0] !== 'scores') {
            return $this->json(['error' => 'not_found'], 404);
        }
        $entityType = trim((string) urldecode($parts[1]));
        $entityId = trim((string) urldecode($parts[2]));
        if ($entityType === '' || $entityId === '') {
            return $this->json(['error' => 'invalid_entity_ref'], 422);
        }

        $item = $this->repository->getScore($entityType, $entityId, 'fr');
        if ($item === null) {
            return $this->json(['error' => 'score_not_found'], 404);
        }
        return $this->json(['ok' => true, 'item' => $item], 200);
    }

    private function listRecommendations(Request $request): Response
    {
        $limit = max(1, min(500, (int) $request->query->get('limit', '100')));
        $offset = max(0, (int) $request->query->get('offset', '0'));
        $entityType = trim((string) $request->query->get('entityType', ''));
        $entityId = trim((string) $request->query->get('entityId', ''));
        $rows = $this->repository->listRecommendations(
            $entityType !== '' ? $entityType : null,
            $entityId !== '' ? $entityId : null,
            $limit,
            $offset
        );
        return $this->json(['ok' => true, 'items' => $rows], 200);
    }

    private function recommendationsByStatus(Request $request): Response
    {
        $status = trim((string) $request->query->get('status', 'open'));
        $limit = max(1, min(500, (int) $request->query->get('limit', '100')));
        $offset = max(0, (int) $request->query->get('offset', '0'));
        $items = $this->repository->listRecommendationsByStatus($status, $limit, $offset);
        return $this->json(['ok' => true, 'items' => $items], 200);
    }

    private function overview(): Response
    {
        return $this->json(['ok' => true, 'item' => $this->repository->overviewStats()], 200);
    }

    private function opportunities(Request $request): Response
    {
        $limit = max(1, min(500, (int) $request->query->get('limit', '100')));
        $offset = max(0, (int) $request->query->get('offset', '0'));
        $entityType = trim((string) $request->query->get('entityType', ''));
        $items = $this->repository->listOpportunities($limit, $offset, $entityType !== '' ? $entityType : null);
        return $this->json(['ok' => true, 'items' => $items], 200);
    }

    private function quickWins(Request $request): Response
    {
        $limit = max(1, min(500, (int) $request->query->get('limit', '100')));
        $offset = max(0, (int) $request->query->get('offset', '0'));
        $entityType = trim((string) $request->query->get('entityType', ''));
        $items = $this->repository->listQuickWins($limit, $offset, $entityType !== '' ? $entityType : null);
        return $this->json(['ok' => true, 'items' => $items], 200);
    }

    private function progress(): Response
    {
        return $this->json(['ok' => true, 'item' => $this->repository->progressStats()], 200);
    }

    private function runTrends(Request $request): Response
    {
        $limit = max(1, min(100, (int) $request->query->get('limit', '20')));
        return $this->json(['ok' => true, 'items' => $this->repository->runTrends($limit)], 200);
    }

    private function updateRecommendationStatus(Request $request): Response
    {
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent() ?: '{}', true);
        $payload = is_array($decoded) ? $decoded : [];
        $id = (int) ($payload['id'] ?? 0);
        $status = trim((string) ($payload['status'] ?? ''));
        if ($id < 1 || $status === '') {
            return $this->json(['error' => 'invalid_payload'], 422);
        }
        $ok = $this->repository->updateRecommendationStatus($id, $status);
        return $ok ? $this->json(['ok' => true], 200) : $this->json(['error' => 'invalid_status_or_not_found'], 422);
    }

    private function assignRecommendation(Request $request): Response
    {
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent() ?: '{}', true);
        $payload = is_array($decoded) ? $decoded : [];
        $id = (int) ($payload['id'] ?? 0);
        if ($id < 1) {
            return $this->json(['error' => 'invalid_id'], 422);
        }
        $ok = $this->repository->updateRecommendationAssignment($id, $payload);
        return $ok ? $this->json(['ok' => true], 200) : $this->json(['error' => 'assignment_update_failed'], 422);
    }

    private function recommendationOwners(): Response
    {
        return $this->json(['ok' => true, 'items' => $this->repository->listRecommendationOwners()], 200);
    }

    private function overdueRecommendations(Request $request): Response
    {
        $limit = max(1, min(500, (int) $request->query->get('limit', '100')));
        $offset = max(0, (int) $request->query->get('offset', '0'));
        $owner = trim((string) $request->query->get('owner', ''));
        $items = $this->repository->listOverdueRecommendations($limit, $offset, $owner !== '' ? $owner : null);
        return $this->json(['ok' => true, 'items' => $items], 200);
    }

    private function dueSoonRecommendations(Request $request): Response
    {
        $limit = max(1, min(500, (int) $request->query->get('limit', '100')));
        $offset = max(0, (int) $request->query->get('offset', '0'));
        $owner = trim((string) $request->query->get('owner', ''));
        $days = max(1, min(30, (int) $request->query->get('days', '3')));
        $items = $this->repository->listDueSoonRecommendations($days, $limit, $offset, $owner !== '' ? $owner : null);
        return $this->json(['ok' => true, 'items' => $items], 200);
    }

    private function slaStats(): Response
    {
        return $this->json(['ok' => true, 'item' => $this->repository->slaStats()], 200);
    }

    private function autoPrioritize(Request $request): Response
    {
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent() ?: '{}', true);
        $payload = is_array($decoded) ? $decoded : [];
        $dryRun = !isset($payload['dryRun']) || (bool) $payload['dryRun'];
        $limit = isset($payload['limit']) && is_numeric($payload['limit']) ? max(1, min(500, (int) $payload['limit'])) : 100;
        $result = $this->repository->autoPrioritizeCriticalOverdue($dryRun, $limit);
        return $this->json(['ok' => true, 'result' => $result], 200);
    }

    private function digestStats(Request $request): Response
    {
        $days = max(1, min(30, (int) $request->query->get('days', '3')));
        return $this->json(['ok' => true, 'item' => $this->repository->digestStats($days)], 200);
    }

    private function getSettings(): Response
    {
        return $this->json(['ok' => true, 'item' => $this->repository->getSeoSettingsBundle()], 200);
    }

    private function saveSettings(Request $request): Response
    {
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent() ?: '{}', true);
        $payload = is_array($decoded) ? $decoded : [];
        $site = is_array($payload['site'] ?? null) ? $payload['site'] : [];
        if (isset($site['title_template']) && mb_strlen((string) $site['title_template']) > 255) {
            return $this->json(['error' => 'invalid_title_template_length'], 422);
        }
        if (isset($site['meta_description_template']) && mb_strlen((string) $site['meta_description_template']) > 255) {
            return $this->json(['error' => 'invalid_meta_description_template_length'], 422);
        }
        $this->repository->saveSeoSettingsBundle($payload, null);
        return $this->json(['ok' => true], 200);
    }

    private function settingsPreview(Request $request): Response
    {
        $entityType = trim((string) $request->query->get('entityType', 'product'));
        if ($entityType === '') {
            $entityType = 'product';
        }
        $settings = $this->repository->getSeoSettingsBundle();
        $preview = (new SeoSettingsDefaultsApplier())->preview($entityType, $settings);
        return $this->json(['ok' => true, 'item' => $preview], 200);
    }

    private function coverageReport(): Response
    {
        return $this->json(['ok' => true, 'item' => $this->repository->coverageAuditReport()], 200);
    }

    private function recompute(Request $request): Response
    {
        /** @var mixed $decoded */
        $decoded = json_decode($request->getContent() ?: '{}', true);
        $payload = is_array($decoded) ? $decoded : [];
        $mode = strtolower(trim((string) ($payload['mode'] ?? 'all')));

        $service = new EEATAnalysisService();
        $result = $mode === 'changed'
            ? $service->recomputeChangedSince((string) ($payload['since'] ?? (new \DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s')))
            : $service->recomputeAll();

        return $this->json(['ok' => true, 'result' => $result], 200);
    }

    private function authorize(Request $request): ?Response
    {
        $uiReview = trim((string) $request->headers->get('X-UI-Review', '')) === '1';
        $appEnv = strtolower((string) ($_ENV['APP_ENV'] ?? 'dev'));
        $nonProd = $appEnv !== 'prod' && $appEnv !== 'production';
        $uiReviewAllowedEnv = filter_var($_ENV['ADMIN_UI_REVIEW_ALLOWED'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($uiReview && $nonProd && $uiReviewAllowedEnv) {
            return null;
        }

        $authorization = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($authorization, 'Bearer ')) {
            return $this->json(['error' => 'unauthorized'], 401);
        }
        $token = trim(substr($authorization, 7));
        try {
            $identity = (new AdminAuthService())->verifyBearer($token);
        } catch (\Throwable) {
            return $this->json(['error' => 'unauthorized'], 401);
        }
        if (!$this->isValidCsrf($request, $token)) {
            return $this->json(['error' => 'invalid_csrf_token'], 403);
        }
        $role = (string) ($identity['role'] ?? '');
        if (!in_array($role, ['admin', 'super_admin'], true)) {
            return $this->json(['error' => 'forbidden'], 403);
        }
        return null;
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
