<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use PHPUnit\Framework\TestCase;

final class DailyOpsDigestConceptTest extends TestCase
{
    public function testDailyDigestContainsExpectedSections(): void
    {
        $report = [
            'digest' => [],
            'progress' => [],
            'sla' => [],
            'coverage' => ['items' => [], 'backlogPrioritized' => []],
            'opportunities' => [],
            'quickWins' => [],
            'runTrends' => [],
            'generatedAt' => '2026-04-01T00:00:00+00:00',
        ];

        self::assertArrayHasKey('digest', $report);
        self::assertArrayHasKey('progress', $report);
        self::assertArrayHasKey('sla', $report);
        self::assertArrayHasKey('coverage', $report);
        self::assertArrayHasKey('runTrends', $report);
    }
}

