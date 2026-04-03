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
        $filters = [
            'category' => (string) $request->query->get('category', ''),
            'brand' => (string) $request->query->get('brand', ''),
            'tag' => (string) $request->query->get('tag', ''),
            'search' => (string) $request->query->get('search', ''),
            'sort' => (string) $request->query->get('sort', 'featured'),
            'limit' => $request->query->get('limit', null),
            'offset' => $request->query->get('offset', null),
        ];

        return $this->json($repo->listProducts($locale, $filters));
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
        $locale = (string) $request->query->get('lang', 'fr');

        return $this->json($repo->listBrands($locale));
    }

    public function categories(Request $request): Response
    {
        $repo = new PdoCatalogApiRepository();
        $locale = (string) $request->query->get('lang', 'fr');
        $scope = (string) $request->query->get('scope', 'all');

        if ($scope === 'families') {
            return $this->json($repo->listCategoryFamilies($locale));
        }

        return $this->json($repo->listCategories($locale));
    }

    public function navigation(Request $request): Response
    {
        $repo = new PdoCatalogApiRepository();
        $locale = (string) $request->query->get('lang', 'fr');

        return $this->json($repo->listNavigation($locale));
    }

    public function biomes(Request $request): Response
    {
        $repo = new PdoCatalogApiRepository();
        $locale = (string) $request->query->get('lang', 'fr');

        return $this->json($repo->listBiomes($locale));
    }

    public function paints(Request $request): Response
    {
        $repo = new PdoCatalogApiRepository();
        $locale = (string) $request->query->get('lang', 'fr');

        return $this->json($repo->listPaintsTree($locale));
    }

    public function basing(Request $request): Response
    {
        $repo = new PdoCatalogApiRepository();
        $locale = (string) $request->query->get('lang', 'fr');

        return $this->json($repo->listBasingTree($locale));
    }

    public function popularTags(Request $request): Response
    {
        $repo = new PdoCatalogApiRepository();
        $locale = (string) $request->query->get('lang', 'fr');
        $limit = (int) $request->query->get('limit', 8);

        return $this->json($repo->listPopularTags($locale, $limit));
    }

    public function newArrivals(Request $request): Response
    {
        $repo = new PdoCatalogApiRepository();
        $locale = (string) $request->query->get('lang', 'fr');
        $limit = (int) $request->query->get('limit', 6);

        return $this->json($repo->listNewArrivals($locale, $limit));
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
