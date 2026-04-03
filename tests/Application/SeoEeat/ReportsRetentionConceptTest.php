<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use PHPUnit\Framework\TestCase;

final class ReportsRetentionConceptTest extends TestCase
{
    public function testKeepDaysClampConcept(): void
    {
        self::assertSame(1, max(1, min(365, -10)));
        self::assertSame(365, max(1, min(365, 999)));
        self::assertSame(14, max(1, min(365, 14)));
    }
}

