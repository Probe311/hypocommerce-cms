<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use App\Application\SeoEeat\RecommendationEngine;
use PHPUnit\Framework\TestCase;

final class RecommendationEngineTest extends TestCase
{
    public function testBuildReturnsCriticalRecommendationsWhenSignalsAreWeak(): void
    {
        $engine = new RecommendationEngine();
        $recommendations = $engine->build(
            ['entityType' => 'product', 'title' => '', 'author' => ''],
            ['scoreGlobal' => 30, 'signals' => ['meta_title' => 'missing_or_bad_length', 'meta_description' => 'missing_or_bad_length', 'content_depth' => 'low', 'freshness' => 'missing', 'internal_links' => 'missing']]
        );

        self::assertNotEmpty($recommendations);
        $severities = array_map(static fn (array $r): string => (string) $r['severity'], $recommendations);
        self::assertContains('critical', $severities);
    }

    public function testBuildAddsDuplicateRecommendations(): void
    {
        $engine = new RecommendationEngine();
        $recommendations = $engine->build(
            ['entityType' => 'blog_article', 'title' => 'Titre', 'author' => 'Auteur'],
            ['scoreGlobal' => 60, 'signals' => ['duplicate_title' => 'yes', 'duplicate_meta_title' => 'yes']]
        );
        $codes = array_map(static fn (array $r): string => (string) $r['ruleCode'], $recommendations);
        self::assertContains('duplicate_title', $codes);
        self::assertContains('duplicate_meta_title', $codes);
    }

    public function testBuildAddsBusinessPriorityRecommendation(): void
    {
        $engine = new RecommendationEngine();
        $recommendations = $engine->build(
            ['entityType' => 'product', 'title' => 'Produit', 'author' => '', 'trustSignals' => ['businessCritical' => true]],
            ['scoreGlobal' => 50, 'signals' => ['keyword_coverage' => 'low', 'readability' => 'low']]
        );
        $codes = array_map(static fn (array $r): string => (string) $r['ruleCode'], $recommendations);
        self::assertContains('business_priority', $codes);
    }
}
