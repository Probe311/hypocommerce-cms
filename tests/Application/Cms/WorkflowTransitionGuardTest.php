<?php

declare(strict_types=1);

namespace App\Tests\Application\Cms;

use App\Application\Cms\WorkflowTransitionGuard;
use PHPUnit\Framework\TestCase;

final class WorkflowTransitionGuardTest extends TestCase
{
    public function testAllowsValidTransition(): void
    {
        self::assertTrue(WorkflowTransitionGuard::isAllowed('draft', 'in_review'));
        self::assertTrue(WorkflowTransitionGuard::isAllowed('scheduled', 'published'));
    }

    public function testRejectsInvalidTransition(): void
    {
        self::assertFalse(WorkflowTransitionGuard::isAllowed('published', 'in_review'));
        self::assertFalse(WorkflowTransitionGuard::isAllowed('archived', 'published'));
    }
}
