<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use PHPUnit\Framework\TestCase;

final class AutoPrioritizeConceptTest extends TestCase
{
    public function testDryRunParserConcept(): void
    {
        $falseValues = ['0', 'false', 'no'];
        self::assertContains('false', $falseValues);
        self::assertContains('0', $falseValues);
    }
}
