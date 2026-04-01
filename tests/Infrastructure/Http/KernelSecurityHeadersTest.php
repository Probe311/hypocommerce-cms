<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Infrastructure\Http\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class KernelSecurityHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['APP_URL'] = 'http://localhost:8000';
        $_ENV['CORS_ALLOW_ORIGIN'] = 'http://localhost:3000';
    }

    public function testHealthEndpointAddsSecurityHeaders(): void
    {
        $request = Request::create('/health', 'GET');
        $response = (new Kernel())->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertNotNull($response->headers->get('Content-Security-Policy'));
    }

    public function testPreflightOptionsReturnsNoContent(): void
    {
        $request = Request::create('/graphql', 'OPTIONS', [], [], [], ['HTTP_ORIGIN' => 'http://localhost:3000']);
        $response = (new Kernel())->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame('http://localhost:3000', $response->headers->get('Access-Control-Allow-Origin'));
    }
}
