<?php

declare(strict_types=1);

namespace App\Tests\Application\SeoEeat;

use PHPUnit\Framework\TestCase;

final class AssignmentPayloadTest extends TestCase
{
    public function testAssignmentPayloadAllowsOwnerDueDateAndNote(): void
    {
        $payload = [
            'owner' => 'seo-team',
            'dueDate' => '2026-05-01',
            'note' => 'Priorite sprint SEO.',
        ];

        self::assertSame('seo-team', $payload['owner']);
        self::assertSame('2026-05-01', $payload['dueDate']);
        self::assertStringContainsString('Priorite', $payload['note']);
    }
}
