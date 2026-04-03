<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use App\Application\SeoEeat\SeoSettingsValidator;
use PHPUnit\Framework\TestCase;

final class SeoSettingsValidatorTest extends TestCase
{
    public function testAcceptsValidPayload(): void
    {
        $payload = [
            'site' => [
                'title_template' => '{title} | {site_name}',
                'meta_description_template' => '{title} - {site_name}',
                'canonical_base' => 'https://example.com',
                'robots_default' => 'index,follow',
            ],
            'social' => [
                'default_og_image_url' => 'https://example.com/og.jpg',
            ],
            'schema' => [
                'organization_url' => 'https://example.com',
                'search_url_template' => 'https://example.com/search?q={query}',
            ],
            'eeat' => [
                'min_score_default' => 70,
            ],
            'indexationRules' => [
                ['entity_type' => 'product', 'robots_directive' => 'index,follow', 'include_in_sitemap' => true],
            ],
        ];
        $errors = (new SeoSettingsValidator())->validate($payload);
        self::assertSame([], $errors);
    }

    public function testRejectsInvalidPayload(): void
    {
        $payload = [
            'site' => [
                'canonical_base' => 'ftp://invalid',
                'robots_default' => 'index,all',
            ],
            'schema' => [
                'search_url_template' => 'https://example.com/search',
            ],
            'eeat' => [
                'min_score_default' => 10,
            ],
            'indexationRules' => [
                ['entity_type' => 'unknown', 'robots_directive' => 'index,all'],
            ],
        ];
        $errors = (new SeoSettingsValidator())->validate($payload);
        self::assertNotSame([], $errors);
        self::assertContains('invalid_robots_default', $errors);
        self::assertContains('invalid_min_score_default_range', $errors);
        self::assertContains('missing_search_url_template_query_placeholder', $errors);
    }
}

