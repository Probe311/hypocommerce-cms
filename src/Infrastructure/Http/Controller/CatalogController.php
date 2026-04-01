<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Infrastructure\Persistence\PdoCatalogApiRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class CatalogController
{
    public function products(Request $request): Response
    {
        $repo = new PdoCatalogApiRepository();
        $locale = (string) $request->query->get('lang', 'fr');

        return $this->json($repo->listProducts($locale));
    }

    public function product(Request $request, string $slug): Response
    {
        $repo = new PdoCatalogApiRepository();
        $locale = (string) $request->query->get('lang', 'fr');
        $product = $repo->findProductBySlug($slug, $locale);

        if ($product === null) {
            return $this->json(['error' => 'Product not found'], 404);
        }

        return $this->json($product);
    }

    public function brands(Request $request): Response
    {
        $repo = new PdoCatalogApiRepository();

        return $this->json($repo->listBrands());
    }

    public function categories(Request $request): Response
    {
        $repo = new PdoCatalogApiRepository();
        $locale = (string) $request->query->get('lang', 'fr');

        return $this->json($repo->listCategories($locale));
    }

    /**
     * @param mixed $payload
     */
    private function json($payload, int $status = 200): Response
    {
        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json']
        );
    }
}
