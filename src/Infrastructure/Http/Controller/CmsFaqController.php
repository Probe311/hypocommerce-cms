<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Infrastructure\Persistence\PdoCmsRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CmsFaqController
{
    public function __invoke(Request $request): Response
    {
        $repo = new PdoCmsRepository();
        $rows = $repo->findPublishedFaqItems();
        $payload = array_map(static fn (array $row): array => [
            'question' => $row['question'],
            'answer' => $row['answer'],
            'categorySlug' => $row['category_slug'],
            'categoryName' => $row['category_name'],
            'order' => (int) $row['order_index'],
        ], $rows);

        return new Response(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 200, [
            'Content-Type' => 'application/json',
        ]);
    }
}
