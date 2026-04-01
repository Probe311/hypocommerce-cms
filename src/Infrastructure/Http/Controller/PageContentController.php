<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Infrastructure\Persistence\PdoPageRepository;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PageContentController
{
    public function __invoke(Request $request, string $slug): Response
    {
        $repository = new PdoPageRepository();
        $row = $repository->findPublishedBySlug($slug);
        if ($row === null) {
            return new Response(json_encode(['error' => 'not_found']), 404, ['Content-Type' => 'application/json']);
        }

        try {
            /** @var mixed $contentDecoded */
            $contentDecoded = json_decode((string) $row['content'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new Response(json_encode(['error' => 'invalid_content']), 500, ['Content-Type' => 'application/json']);
        }

        $payload = [
            'slug' => $row['slug'],
            'title' => $row['title'],
            'metaTitle' => $row['meta_title'],
            'metaDescription' => $row['meta_description'],
            'publishedAt' => $row['published_at'],
            'content' => $contentDecoded,
        ];

        return new Response(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json',
        ]);
    }
}
