<?php

declare(strict_types=1);

namespace App\Tests\Application\Auth;

use App\Application\Auth\JwtService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class JwtServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['JWT_SECRET'] = 'test-secret-for-jwt-service-123456';
    }

    public function testCreateAndVerifyToken(): void
    {
        $service = new JwtService();
        $token = $service->createToken('customer-123', 60);

        self::assertNotSame('', $token);
        self::assertSame('customer-123', $service->verifyAndGetSubject($token));
    }

    public function testRejectsExpiredToken(): void
    {
        $service = new JwtService();
        $token = $service->createToken('customer-123', -1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('token_expired');

        $service->verifyAndGetSubject($token);
    }
}
