<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Infrastructure\Persistence\PdoCmsRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CmsBlogController
{
    public function list(Request $request): Response
    {
        $repo = new PdoCmsRepository();
        $rows = $repo->findPublishedBlogArticles();
        $out = array_map(static function (array $row): array {
            $baseUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
            return [
                'slug' => $row['slug'],
                'categorySlug' => $row['category_slug'],
                'categoryName' => $row['category_name'],
                'tags' => is_array($row['tags'] ?? null) ? $row['tags'] : [],
                'canonicalUrl' => $baseUrl !== '' ? $baseUrl . '/blog/' . $row['category_slug'] . '/' . $row['slug'] : null,
                'title' => $row['title'],
                'excerpt' => $row['excerpt'],
                'body' => $row['body'],
                'authorName' => $row['author_name'],
                'authorJobTitle' => $row['author_job_title'],
                'datePublished' => $row['published_at'],
                'dateModified' => $row['published_at'],
            ];
        }, $rows);

        return new Response(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json',
        ]);
    }

    public function one(Request $request, string $categorySlug, string $articleSlug): Response
    {
        $repo = new PdoCmsRepository();
        $row = $repo->findPublishedBlogArticle($categorySlug, $articleSlug);
        if ($row === null) {
            return new Response(json_encode(['error' => 'not_found']), 404, ['Content-Type' => 'application/json']);
        }

        $payload = [
            'slug' => $row['slug'],
            'categorySlug' => $row['category_slug'],
            'canonicalUrl' => $this->canonical('/blog/' . (string) $row['category_slug'] . '/' . (string) $row['slug']),
            'categoryName' => $row['category_name'],
            'tags' => is_array($row['tags'] ?? null) ? $row['tags'] : [],
            'title' => $row['title'],
            'excerpt' => $row['excerpt'],
            'body' => $row['body'],
            'authorName' => $row['author_name'],
            'authorJobTitle' => $row['author_job_title'],
            'datePublished' => $row['published_at'],
            'dateModified' => $row['published_at'],
        ];

        return new Response(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json',
        ]);
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
