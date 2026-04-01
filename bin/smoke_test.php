<?php

declare(strict_types=1);

use App\Infrastructure\Http\Kernel;
use Symfony\Component\HttpFoundation\Request;

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}
require __DIR__ . '/bootstrap.php';

loadBackendEnv();

$failures = 0;
$withoutDb = in_array('--without-db', $argv, true);

if (!class_exists(Request::class)) {
    if ($withoutDb) {
        fwrite(STDOUT, "Smoke tests skipped: dependencies missing (vendor/autoload.php).\n");
        exit(0);
    }
    fwrite(STDERR, "Smoke tests cannot run: dependencies missing (vendor/autoload.php).\n");
    exit(1);
}

$kernel = new Kernel();

/**
 * @param array<string,string> $headers
 */
function runCheck(Kernel $kernel, string $method, string $path, ?string $body, int $expectedStatus, array $headers = []): bool
{
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $request = Request::create($path, $method, [], [], [], $server, $body ?? '');
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    if ($status !== $expectedStatus) {
        fwrite(STDERR, "[FAIL] {$method} {$path} => {$status} (expected {$expectedStatus})\n");
        return false;
    }
    fwrite(STDOUT, "[OK] {$method} {$path} => {$status}\n");
    return true;
}

if (!runCheck($kernel, 'GET', '/health', null, 200)) {
    $failures++;
}

if (!$withoutDb) {
    $graphqlBody = json_encode([
        'query' => 'query Smoke { products { id slug name } }',
    ], JSON_UNESCAPED_SLASHES);
    if (!runCheck(
        $kernel,
        'POST',
        '/graphql',
        $graphqlBody === false ? '{"query":"query Smoke { products { id } }"}' : $graphqlBody,
        200,
        ['Content-Type' => 'application/json']
    )) {
        $failures++;
    }

    $restChecks = [
        ['/api/v1/articles', 200],
        ['/api/v1/faq', 200],
        ['/api/v1/pages/accueil', 200],
        ['/api/v1/legal/mentions-legales', 200],
    ];

    foreach ($restChecks as [$path, $expected]) {
        if (!runCheck($kernel, 'GET', (string) $path, null, (int) $expected)) {
            $failures++;
        }
    }
}

if ($failures > 0) {
    fwrite(STDERR, "Smoke tests failed: {$failures}\n");
    exit(1);
}

fwrite(STDOUT, "Smoke tests passed.\n");
