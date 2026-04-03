<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Tracking\TrackingIntegrationsService;
use App\Infrastructure\Persistence\PdoCmsV2Repository;
use App\Infrastructure\Persistence\PdoEeatRepository;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CmsPublicController
{
    private PdoCmsV2Repository $repo;
    private PdoEeatRepository $eeatRepo;

    public function __construct()
    {
        $this->repo = new PdoCmsV2Repository();
        $this->eeatRepo = new PdoEeatRepository();
    }

    public function handle(Request $request, string $resource): Response
    {
        $method = strtoupper($request->getMethod());
        if ($method !== 'GET') {
            return $this->json(['error' => 'method_not_allowed'], 405);
        }

        if ($resource === 'articles') {
            $category = trim((string) $request->query->get('category', ''));
            return $this->json($this->repo->listPublishedArticles($category !== '' ? $category : null), 200);
        }
        if ($resource === 'article-categories') {
            return $this->json($this->repo->listPublishedCategories(), 200);
        }
        if ($resource === 'navigation/header' || $resource === 'navigation/footer' || $resource === 'navigation/secondary') {
            $location = substr($resource, strlen('navigation/'));
            return $this->json($this->repo->listActiveMenu($location), 200);
        }
        if ($resource === 'social-links') {
            return $this->json($this->repo->listActiveSocialLinks(), 200);
        }
        if ($resource === 'footer') {
            return $this->json($this->repo->listActiveFooter(), 200);
        }
        if ($resource === 'seo/config') {
            return $this->json($this->eeatRepo->getSeoSettingsBundle(), 200);
        }
        if ($resource === 'tracking-config') {
            $stored = $this->repo->getSiteSettingByKey(TrackingIntegrationsService::SETTING_KEY);
            $merged = $stored !== null
                ? TrackingIntegrationsService::mergeWithDefaults($stored)
                : TrackingIntegrationsService::defaults();

            return $this->json(TrackingIntegrationsService::toPublicBundle($merged), 200);
        }
        if (str_starts_with($resource, 'pages/')) {
            $slug = trim(substr($resource, strlen('pages/')));
            return $this->pageBySlug($slug);
        }

        return $this->json(['error' => 'not_found'], 404);
    }

    private function pageBySlug(string $slug): Response
    {
        $page = $this->repo->getPublishedPageBySlug($slug);
        if ($page === null) {
            return $this->json(['error' => 'not_found'], 404);
        }
        $sections = $this->repo->getPublishedPageSections((int) $page['id']);
        $normalizedSections = [];
        foreach ($sections as $section) {
            try {
                $payload = json_decode((string) ($section['payload'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $payload = null;
            }
            $normalizedSections[] = [
                'key' => $section['section_key'],
                'type' => $section['section_type'],
                'order' => (int) ($section['order_index'] ?? 0),
                'payload' => $payload,
            ];
        }

        return $this->json([
            'slug' => $page['slug'],
            'title' => $page['title'],
            'template' => $page['template'],
            'metaTitle' => $page['meta_title'],
            'metaDescription' => $page['meta_description'],
            'publishedAt' => $page['published_at'],
            'sections' => $normalizedSections,
        ], 200);
    }

    /**
     * @param mixed $payload
     */
    private function json($payload, int $status): Response
    {
        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json']
        );
    }
}
