<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use PHPUnit\Framework\TestCase;

final class DigestConceptTest extends TestCase
{
    public function testDigestWindowBoundaries(): void
    {
        $days = 3;
        $normalized = max(1, min(30, $days));
        self::assertSame(3, $normalized);

        $tooHigh = 80;
        self::assertSame(30, max(1, min(30, $tooHigh)));
    }
}
