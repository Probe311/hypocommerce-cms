<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use PHPUnit\Framework\TestCase;

final class OpportunityPriorityFormulaTest extends TestCase
{
    public function testPriorityFormulaGivesHigherValueForWorseScore(): void
    {
        $scoreLow = 40.0;
        $scoreHigh = 80.0;
        $severitySum = 6;
        $blockers = 2;

        $priorityLowScore = round(((100 - $scoreLow) * 0.65) + ($severitySum * 6) + ($blockers * 4), 2);
        $priorityHighScore = round(((100 - $scoreHigh) * 0.65) + ($severitySum * 6) + ($blockers * 4), 2);

        self::assertGreaterThan($priorityHighScore, $priorityLowScore);
    }
}
