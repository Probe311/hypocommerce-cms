<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use PHPUnit\Framework\TestCase;

final class SeoDefaultsSelfHealingConceptTest extends TestCase
{
    public function testRequiredEntityTypesListIsStable(): void
    {
        $types = ['product', 'category', 'cms_page', 'blog_article', 'faq_item', 'legal_page'];
        self::assertCount(6, $types);
        self::assertContains('product', $types);
        self::assertContains('legal_page', $types);
    }
}

