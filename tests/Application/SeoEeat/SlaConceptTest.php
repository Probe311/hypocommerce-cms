<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use PHPUnit\Framework\TestCase;

final class SlaConceptTest extends TestCase
{
    public function testOverdueLogicConcept(): void
    {
        $today = new \DateTimeImmutable('2026-04-01');
        $past = new \DateTimeImmutable('2026-03-20');
        $future = new \DateTimeImmutable('2026-04-20');

        self::assertTrue($past < $today);
        self::assertFalse($future < $today);
    }
}
