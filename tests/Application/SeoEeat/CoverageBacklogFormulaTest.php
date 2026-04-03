<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use PHPUnit\Framework\TestCase;

final class CoverageBacklogFormulaTest extends TestCase
{
    public function testPriorityScoreRanksBlockingHigher(): void
    {
        $a = $this->priorityScore(5, 1, 80.0);
        $b = $this->priorityScore(3, 4, 82.0);
        self::assertGreaterThan($a, $b);
    }

    public function testPriorityScorePenalizesLowCompliance(): void
    {
        $good = $this->priorityScore(2, 1, 90.0);
        $bad = $this->priorityScore(2, 1, 45.0);
        self::assertGreaterThan($good, $bad);
    }

    private function priorityScore(int $missingRequiredFields, int $blockingItems, float $complianceRate): float
    {
        return round(($missingRequiredFields * 2.5) + ($blockingItems * 4.0) + max(0.0, (100.0 - $complianceRate)), 2);
    }
}

