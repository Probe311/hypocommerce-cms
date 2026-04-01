<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Infrastructure\Persistence\PdoCmsRepository;
use JsonException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CmsLegalController
{
    public function __invoke(Request $request, string $slug): Response
    {
        $repo = new PdoCmsRepository();
        $row = $repo->findPublishedLegalPage($slug);
        if ($row === null) {
            return new Response(json_encode(['error' => 'not_found']), 404, ['Content-Type' => 'application/json']);
        }

        try {
            $paragraphs = json_decode((string) $row['paragraphs'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $paragraphs = [];
        }

        $payload = [
            'slug' => $row['slug'],
            'title' => $row['title'],
            'paragraphs' => is_array($paragraphs) ? $paragraphs : [],
            'version' => (int) $row['version'],
            'publishedAt' => $row['published_at'],
        ];

        return new Response(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json',
        ]);
    }
}
