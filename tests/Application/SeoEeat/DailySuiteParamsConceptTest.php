<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use PHPUnit\Framework\TestCase;

final class DailySuiteParamsConceptTest extends TestCase
{
    public function testDaysClampConcept(): void
    {
        self::assertSame(1, max(1, min(30, -5)));
        self::assertSame(30, max(1, min(30, 99)));
        self::assertSame(7, max(1, min(30, 7)));
    }

    public function testTrendLimitClampConcept(): void
    {
        self::assertSame(1, max(1, min(100, 0)));
        self::assertSame(100, max(1, min(100, 180)));
        self::assertSame(20, max(1, min(100, 20)));
    }
}

