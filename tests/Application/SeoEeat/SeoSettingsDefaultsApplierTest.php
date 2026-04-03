<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use App\Application\SeoEeat\SeoSettingsDefaultsApplier;
use PHPUnit\Framework\TestCase;

final class SeoSettingsDefaultsApplierTest extends TestCase
{
    public function testAppliesTemplatesWhenMetaMissing(): void
    {
        $content = [
            'entityType' => 'product',
            'entityId' => '1',
            'entitySlug' => 'produit-test',
            'title' => 'Produit test',
            'metaTitle' => '',
            'metaDescription' => '',
            'trustSignals' => [],
        ];
        $settings = [
            'site' => [
                'site_name' => 'Hypocommerce CMS',
                'title_template' => '{title} | {site_name}',
                'meta_description_template' => '{title} - {site_name}',
                'canonical_base' => 'https://example.com',
            ],
        ];

        $out = (new SeoSettingsDefaultsApplier())->apply($content, $settings);
        self::assertNotSame('', (string) ($out['metaTitle'] ?? ''));
        self::assertNotSame('', (string) ($out['metaDescription'] ?? ''));
        self::assertSame(true, (bool) ($out['trustSignals']['generatedMetaTitle'] ?? false));
        self::assertSame(true, (bool) ($out['trustSignals']['generatedCanonicalUrl'] ?? false));
        self::assertSame('Product', (string) ($out['trustSignals']['schemaType'] ?? ''));
    }
}

