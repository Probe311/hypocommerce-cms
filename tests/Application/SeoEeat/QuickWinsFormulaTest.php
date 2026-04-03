<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use PHPUnit\Framework\TestCase;

final class QuickWinsFormulaTest extends TestCase
{
    public function testQuickWinPreferenceExample(): void
    {
        $candidateA = ['recommendation_count' => 5, 'score_global' => 55.0, 'blockers_count' => 3];
        $candidateB = ['recommendation_count' => 2, 'score_global' => 55.0, 'blockers_count' => 3];

        $scoreA = ((int) $candidateA['recommendation_count'] * 10) + ((100 - (float) $candidateA['score_global']) * 0.5) + ((int) $candidateA['blockers_count'] * 3);
        $scoreB = ((int) $candidateB['recommendation_count'] * 10) + ((100 - (float) $candidateB['score_global']) * 0.5) + ((int) $candidateB['blockers_count'] * 3);

        self::assertGreaterThan($scoreB, $scoreA - 0.0001); // sanity: more quick-win recos should dominate
    }
}
