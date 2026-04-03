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
use App\Infrastructure\Http\Controller\SeoEeatAdminController;
use App\Infrastructure\Http\Controller\CatalogController;
use App\Infrastructure\Http\Controller\CmsPublicController;
use App\Infrastructure\Http\Controller\CmsV2AdminController;
use App\Infrastructure\Http\Controller\PaymentWebhookController;
use App\Infrastructure\Http\Controller\StorefrontController;
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

        $static = $this->tryServePublicStatic($path, $method);
        if ($static !== null) {
            return $static;
        }

        if ($path === '/auth/register' && $method === 'POST') {
            return (new StorefrontController())->register($request);
        }
        if ($path === '/auth/login' && $method === 'POST') {
            return (new StorefrontController())->login($request);
        }
        if ($path === '/account/orders' && $method === 'GET') {
            return (new StorefrontController())->customerOrders($request);
        }
        if ($path === '/checkout/quote' && $method === 'POST') {
            return (new StorefrontController())->quoteCart($request);
        }
        if ($path === '/checkout/orders' && $method === 'POST') {
            return (new StorefrontController())->checkoutOrder($request);
        }
        if ($path === '/payments/stripe/session' && $method === 'POST') {
            return (new StorefrontController())->startStripePayment($request);
        }
        if ($path === '/payments/paypal/order' && $method === 'POST') {
            return (new StorefrontController())->startPaypalOrder($request);
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
        if ($path === '/api/v1/catalog/navigation' && $method === 'GET') {
            return (new CatalogController())->navigation($request);
        }
        if ($path === '/api/v1/catalog/biomes' && $method === 'GET') {
            return (new CatalogController())->biomes($request);
        }
        if ($path === '/api/v1/catalog/paints' && $method === 'GET') {
            return (new CatalogController())->paints($request);
        }
        if ($path === '/api/v1/catalog/basing' && $method === 'GET') {
            return (new CatalogController())->basing($request);
        }
        if ($path === '/api/v1/catalog/tags/popular' && $method === 'GET') {
            return (new CatalogController())->popularTags($request);
        }
        if ($path === '/api/v1/catalog/new-arrivals' && $method === 'GET') {
            return (new CatalogController())->newArrivals($request);
        }
        if ($path === '/api/v1/webhooks/stripe' && $method === 'POST') {
            return (new PaymentWebhookController())->handleStripe($request);
        }
        if ($path === '/api/v1/webhooks/paypal' && $method === 'POST') {
            return (new PaymentWebhookController())->handlePaypal($request);
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
            if (str_starts_with($resource, 'cms/')) {
                $cmsResource = trim(substr($resource, strlen('cms/')), '/');
                return (new CmsV2AdminController())->handle($request, $cmsResource);
            }
            if (str_starts_with($resource, 'seo/eeat/')) {
                $seoResource = trim(substr($resource, strlen('seo/eeat/')), '/');
                return (new SeoEeatAdminController())->handle($request, $seoResource);
            }
            return (new CmsAdminController())->handle($request, $resource);
        }
        if (str_starts_with($path, '/api/v1/cms/')) {
            $resource = trim(substr($path, strlen('/api/v1/cms/')), '/');
            return (new CmsPublicController())->handle($request, $resource);
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

    /**
     * Fichiers sous public/ servis même quand tout le trafic passe par index.php (reverse proxy / front controller).
     */
    private function tryServePublicStatic(string $path, string $method): ?Response
    {
        if ($method !== 'GET') {
            return null;
        }
        if (str_starts_with($path, '/uploads/cms/') || str_starts_with($path, '/uploads/products/')) {
            return $this->tryServeUploadStatic($path);
        }
        $mimeByPath = [
            '/favicon.svg' => 'image/svg+xml',
            '/admin/favicon.svg' => 'image/svg+xml',
            '/brand/hippocampus.svg' => 'image/svg+xml',
        ];
        if (!isset($mimeByPath[$path])) {
            return null;
        }
        $publicRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public';
        $relative = ltrim($path, '/');
        $fullPath = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $realPublic = realpath($publicRoot);
        $realFile = realpath($fullPath);
        if ($realPublic === false || $realFile === false || !is_file($realFile)) {
            return null;
        }
        if (!str_starts_with($realFile, $realPublic . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $body = @file_get_contents($realFile);
        if ($body === false) {
            return null;
        }

        return new Response($body, 200, [
            'Content-Type' => $mimeByPath[$path],
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function tryServeUploadStatic(string $path): ?Response
    {
        $uploadsRoot = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'uploads';
        $relative = ltrim($path, '/');
        $fullPath = $uploadsRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $realRoot = realpath($uploadsRoot);
        $realFile = realpath($fullPath);
        if ($realRoot === false || $realFile === false || !is_file($realFile)) {
            return null;
        }
        if (!str_starts_with($realFile, $realRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }
        $body = @file_get_contents($realFile);
        if ($body === false) {
            return null;
        }
        $mime = @mime_content_type($realFile);
        if (!is_string($mime) || $mime === '') {
            $mime = 'application/octet-stream';
        }

        return new Response($body, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=604800, immutable',
        ]);
    }

    private function extractActor(Request $request): ?string
    {
        $auth = (string) $request->headers->get('Authorization', '');
        if (str_starts_with($auth, 'Bearer ')) {
            return 'bearer';
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
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type,Authorization,X-CSRF-Token,X-Request-Id,X-Correlation-Id');
            $response->headers->set('Access-Control-Max-Age', '600');
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));
        if (str_starts_with($contentType, 'image/svg+xml')) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');

            return;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
