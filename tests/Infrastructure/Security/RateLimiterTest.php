<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Security;

use App\Infrastructure\Security\RateLimiter;
use PHPUnit\Framework\TestCase;

final class RateLimiterTest extends TestCase
{
    public function testBlocksAfterMaxAttempts(): void
    {
        $scope = 'test_scope_' . bin2hex(random_bytes(6));
        $identifier = 'test_user_' . bin2hex(random_bytes(6));
        $rateLimiter = new RateLimiter();

        $first = $rateLimiter->check($scope, $identifier, 2, 30);
        $second = $rateLimiter->check($scope, $identifier, 2, 30);
        $third = $rateLimiter->check($scope, $identifier, 2, 30);

        self::assertTrue($first['allowed']);
        self::assertTrue($second['allowed']);
        self::assertFalse($third['allowed']);
        self::assertGreaterThanOrEqual(1, $third['retryAfter']);
    }
}
