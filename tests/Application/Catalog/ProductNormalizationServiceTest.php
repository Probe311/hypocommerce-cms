<?php

declare(strict_types=1);

namespace App\Tests\Application\Catalog;

use App\Application\Catalog\ProductNormalizationService;
use PHPUnit\Framework\TestCase;

final class ProductNormalizationServiceTest extends TestCase
{
    public function testNormalizePaintSetInfersFrenchTaxonomyAndColor(): void
    {
        $service = new ProductNormalizationService();

        $result = $service->normalize([
            'name' => 'Orcs And Green Creatures',
            'description' => 'Acrylic paint set with olive and deep green tones',
            'brand_name' => 'AK Interactive',
        ]);

        self::assertSame('Peintures', $result['tags'][0]['name']);
        self::assertSame('Pack & Set', $result['tags'][1]['name']);
        self::assertSame('Vert', $result['color']);
        self::assertCount(2, $result['categoryPath']);
        self::assertSame('peintures', $result['categoryPath'][0]['slug']);
        self::assertSame('peintures-pack-set', $result['categoryPath'][1]['slug']);
        self::assertSame('peintures-pack-set', $result['taxonomy_leaf_slug']);
    }

    public function testNormalizeTuftGoesToVegetation(): void
    {
        $service = new ProductNormalizationService();
        $result = $service->normalize([
            'name' => 'Static grass tufts 6mm',
            'description' => 'Miniature basing',
            'brand_name' => 'Army Painter',
        ]);

        $leaf = $result['categoryPath'][count($result['categoryPath']) - 1]['slug'];
        self::assertSame('basing-decors-vegetation-tufts-herbes', $leaf);
        self::assertSame('basing-decors-vegetation-tufts-herbes', $result['taxonomy_leaf_slug']);
    }

    public function testNormalizeFromMasterRowBundleWithPaintSignalsMapsToPackSet(): void
    {
        $service = new ProductNormalizationService();
        $result = $service->normalizeFromMasterRow([
            'product_name' => 'Mega hobby bundle',
            'description' => 'Accessoires pour figurines.',
            'long_description' => 'Ce lot contient des pots acrylique pour wargame.',
            'brand' => 'Test Brand',
            'product_type' => 'bundle',
            'tags' => ['peinture'],
        ]);

        self::assertSame('peintures-pack-set', $result['taxonomy_leaf_slug']);
    }

    public function testNormalizeFromMasterRowBundleWithTuftsNotForcedToPaintPack(): void
    {
        $service = new ProductNormalizationService();
        $result = $service->normalizeFromMasterRow([
            'product_name' => 'Scenic bundle',
            'long_description' => 'Several static grass tufts for basing.',
            'brand' => 'Army Painter',
            'product_type' => 'bundle',
            'tags' => ['basing'],
        ]);

        self::assertSame('basing-decors-vegetation-tufts-herbes', $result['taxonomy_leaf_slug']);
    }

    public function testNormalizeAkStyleStandardPotToAcrylicLayer(): void
    {
        $service = new ProductNormalizationService();
        $result = $service->normalizeFromMasterRow([
            'product_name' => 'BROWN ROSE – STANDARD',
            'long_description' => 'Acrylique pour figurines, application au pinceau.',
            'brand' => 'AK Interactive',
            'tags' => ['peinture'],
        ]);

        self::assertSame('peintures-acryliques-layer', $result['taxonomy_leaf_slug']);
    }

    public function testNormalizeRealColorsSetToPackSet(): void
    {
        $service = new ProductNormalizationService();
        $result = $service->normalizeFromMasterRow([
            'product_name' => '110 REAL COLORS FOR AIRCRAFT',
            'long_description' => 'Collection de teintes pour maquettes avion.',
            'brand' => 'AK Interactive',
        ]);

        self::assertSame('peintures-pack-set', $result['taxonomy_leaf_slug']);
    }

    public function testNormalizeCementGlueStaysDivers(): void
    {
        $service = new ProductNormalizationService();
        $result = $service->normalizeFromMasterRow([
            'product_name' => '200 ML. REFILL – EXTRA THIN CEMENT (GLUE)',
            'long_description' => 'Colle plastique pour maquettes.',
            'brand' => 'AK Interactive',
        ]);

        self::assertSame('basing-decors-elements-decor-divers', $result['taxonomy_leaf_slug']);
    }

    public function testNormalizeConcreteWargameTerrainToTexturePaint(): void
    {
        $service = new ProductNormalizationService();
        $result = $service->normalizeFromMasterRow([
            'product_name' => 'CONCRETE – WARGAME TERRAINS – 100ML',
            'long_description' => 'Pâte à texture pour socles wargame.',
            'brand' => 'AK Interactive',
        ]);

        self::assertSame('basing-decors-textures-sols-texture-paint', $result['taxonomy_leaf_slug']);
    }

    public function testTaxonomyPathForLeafSlug(): void
    {
        $service = new ProductNormalizationService();
        $path = $service->taxonomyPathForLeafSlug('peintures-acryliques-layer');
        self::assertSame('peintures-acryliques-layer', $path[count($path) - 1]['slug']);
    }
}
