<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use PHPUnit\Framework\TestCase;

final class RecommendationStatusLifecycleTest extends TestCase
{
    public function testAllowedStatusLifecycleValues(): void
    {
        $allowed = ['open', 'in_progress', 'done', 'dismissed'];
        self::assertContains('open', $allowed);
        self::assertContains('in_progress', $allowed);
        self::assertContains('done', $allowed);
        self::assertContains('dismissed', $allowed);
    }
}
