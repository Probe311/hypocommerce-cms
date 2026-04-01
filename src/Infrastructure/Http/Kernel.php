<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use App\Infrastructure\Http\Controller\GraphqlController;
use App\Infrastructure\Http\Controller\HealthController;
use App\Infrastructure\Http\Controller\NotFoundController;
use App\Infrastructure\Http\Controller\PageContentController;
use App\Infrastructure\Http\Controller\CmsPageController;
use App\Infrastructure\Http\Controller\CmsBlogController;
use App\Infrastructure\Http\Controller\CmsFaqController;
use App\Infrastructure\Http\Controller\CmsLegalController;
use App\Infrastructure\Http\Controller\CmsAdminController;
use App\Infrastructure\Http\Controller\CatalogController;
use App\Infrastructure\Logging\StructuredLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class Kernel
{
    public function handle(Request $request): Response
    {
        $start = microtime(true);
        $requestId = trim((string) $request->headers->get('X-Request-Id', ''));
        if ($requestId === '') {
            $requestId = bin2hex(random_bytes(8));
        }
        $logger = new StructuredLogger();
        $path = $request->getPathInfo();
        $method = strtoupper($request->getMethod());

        try {
            $response = $this->dispatch($request);
        } catch (\Throwable $e) {
            $logger->error('request_failed', [
                'request_id' => $requestId,
                'method' => $method,
                'path' => $path,
                'ip' => $request->getClientIp(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $response->headers->set('X-Request-Id', $requestId);
        $this->applySecurityHeaders($request, $response);
        $logger->info('request_completed', [
            'request_id' => $requestId,
            'method' => $method,
            'path' => $path,
            'status' => $response->getStatusCode(),
            'duration_ms' => $durationMs,
            'actor_id' => $this->extractActor($request),
            'correlation_id' => (string) $request->headers->get('X-Correlation-Id', $requestId),
            'ip' => $request->getClientIp(),
        ]);

        return $response;
    }

    private function dispatch(Request $request): Response
    {
        $path = $request->getPathInfo();
        $method = strtoupper($request->getMethod());
        if ($method === 'OPTIONS') {
            return new Response('', 204);
        }

        if ($path === '/api/v1/articles' && $method === 'GET') {
            return (new CmsBlogController())->list($request);
        }
        if ($path === '/api/v1/products' && $method === 'GET') {
            return (new CatalogController())->products($request);
        }
        if ($path === '/api/v1/brands' && $method === 'GET') {
            return (new CatalogController())->brands($request);
        }
        if ($path === '/api/v1/categories' && $method === 'GET') {
            return (new CatalogController())->categories($request);
        }
        if ($path === '/api/v1/blog' && $method === 'GET') {
            return (new CmsBlogController())->list($request);
        }
        if ($path === '/api/v1/faq' && $method === 'GET') {
            return (new CmsFaqController())($request);
        }
        if (str_starts_with($path, '/api/v1/pages/')) {
            $rawSlug = substr($path, strlen('/api/v1/pages/'));
            $slug = trim(urldecode($rawSlug), '/');
            if ($slug === '') {
                return (new NotFoundController())($request);
            }
            return (new CmsPageController())($request, $slug);
        }
        if (str_starts_with($path, '/api/v1/products/')) {
            $rawSlug = substr($path, strlen('/api/v1/products/'));
            $slug = trim(urldecode($rawSlug), '/');
            if ($slug === '') {
                return (new NotFoundController())($request);
            }
            return (new CatalogController())->product($request, $slug);
        }
        if (str_starts_with($path, '/api/v1/legal/')) {
            $rawSlug = substr($path, strlen('/api/v1/legal/'));
            $slug = trim(urldecode($rawSlug), '/');
            if ($slug === '') {
                return (new NotFoundController())($request);
            }
            return (new CmsLegalController())($request, $slug);
        }
        if (str_starts_with($path, '/api/v1/blog/')) {
            $rest = trim(substr($path, strlen('/api/v1/blog/')), '/');
            $parts = $rest === '' ? [] : explode('/', $rest);
            if (count($parts) === 2 && $method === 'GET') {
                return (new CmsBlogController())->one($request, urldecode($parts[0]), urldecode($parts[1]));
            }
        }
        if (str_starts_with($path, '/api/v1/admin/')) {
            $resource = trim(substr($path, strlen('/api/v1/admin/')), '/');
            return (new CmsAdminController())->handle($request, $resource);
        }

        if (str_starts_with($path, '/pages/')) {
            $rawSlug = substr($path, strlen('/pages/'));
            $slug = trim(urldecode($rawSlug), '/');
            if ($slug === '') {
                return (new NotFoundController())($request);
            }

            return (new PageContentController())($request, $slug); // Compat legacy
        }

        return match ($path) {
            '/graphql' => (new GraphqlController())($request),
            '/health' => (new HealthController())($request),
            default => (new NotFoundController())($request),
        };
    }

    private function extractActor(Request $request): ?string
    {
        $auth = (string) $request->headers->get('Authorization', '');
        if (str_starts_with($auth, 'Bearer ')) {
            return 'bearer';
        }
        if ((string) $request->headers->get('X-Admin-Token', '') !== '') {
            return 'admin_token';
        }
        return null;
    }

    private function applySecurityHeaders(Request $request, Response $response): void
    {
        $origin = (string) $request->headers->get('Origin', '');
        $allowedOrigin = (string) ($_ENV['CORS_ALLOW_ORIGIN'] ?? ($_ENV['APP_URL'] ?? ''));
        if ($origin !== '' && $allowedOrigin !== '' && ($allowedOrigin === '*' || $origin === $allowedOrigin)) {
            $response->headers->set('Access-Control-Allow-Origin', $allowedOrigin === '*' ? '*' : $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Methods', 'GET,POST,OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type,Authorization,X-Admin-Token,X-CSRF-Token,X-Request-Id,X-Correlation-Id');
            $response->headers->set('Access-Control-Max-Age', '600');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
    }
}
