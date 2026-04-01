<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Infrastructure\Persistence\PdoCmsRepository;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CmsPageController
{
    public function __invoke(Request $request, string $slug): Response
    {
        $repo = new PdoCmsRepository();
        $locale = (string) $request->query->get('lang', 'fr');
        $found = $repo->findPublishedPageBySlug($slug, $locale);
        if ($found === null) {
            return new Response(json_encode(['error' => 'not_found']), 404, ['Content-Type' => 'application/json']);
        }

        /** @var array<string,mixed> $page */
        $page = $found['page'];
        /** @var array<int,array<string,mixed>> $sections */
        $sections = $found['sections'];

        $content = [
            'template' => (string) $page['template'],
            'source' => 'cms',
            'sections' => [],
        ];

        foreach ($sections as $section) {
            try {
                $payload = json_decode((string) $section['payload'], true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $payload = null;
            }
            $content['sections'][] = [
                'key' => $section['section_key'],
                'type' => $section['section_type'],
                'order' => (int) $section['order_index'],
                'payload' => $payload,
            ];
            if ($section['section_key'] === 'hero' && is_array($payload)) {
                $content['hero'] = $payload;
            }
            if ($section['section_key'] === 'faq' && is_array($payload)) {
                $content['faq'] = $payload;
            }
        }

        $payload = [
            'slug' => $page['slug'],
            'canonicalUrl' => $this->canonical('/pages/' . (string) $page['slug']),
            'title' => $page['title'],
            'metaTitle' => $page['meta_title'],
            'metaDescription' => $page['meta_description'],
            'publishedAt' => $page['published_at'],
            'content' => $content,
        ];

        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            ['Content-Type' => 'application/json']
        );
    }

    private function canonical(string $path): ?string
    {
        $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
        if ($baseUrl === '') {
            return null;
        }
        return $baseUrl . $path;
    }
}
