<?php

declare(strict_types=1);

use App\Infrastructure\Http\Kernel;
use App\Infrastructure\Database\ConnectionFactory;
use Symfony\Component\HttpFoundation\Request;

$vendorAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($vendorAutoload)) {
    require $vendorAutoload;
}
require __DIR__ . '/bootstrap.php';

loadBackendEnv();

$strictAdmin = in_array('--strict-admin', $argv, true);
$failures = 0;
$warnings = [];

if (!class_exists(Request::class)) {
    fwrite(STDERR, "SEO smoke cannot run: missing dependencies.\n");
    exit(1);
}

$kernel = new Kernel();

/**
 * @param array<string,string> $headers
 */
function requestJson(Kernel $kernel, string $method, string $path, ?array $body = null, array $headers = []): array
{
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $payload = $body === null ? '' : (json_encode($body, JSON_UNESCAPED_SLASHES) ?: '{}');
    $request = Request::create($path, $method, [], [], [], $server, $payload);
    $response = $kernel->handle($request);
    $decoded = json_decode((string) $response->getContent(), true);
    return [
        'status' => $response->getStatusCode(),
        'json' => is_array($decoded) ? $decoded : [],
    ];
}

function assertStatus(array $response, int $expected, string $label): bool
{
    if ((int) ($response['status'] ?? 0) !== $expected) {
        fwrite(STDERR, "[FAIL] {$label} => " . (int) ($response['status'] ?? 0) . " (expected {$expected})\n");
        return false;
    }
    fwrite(STDOUT, "[OK] {$label} => {$expected}\n");
    return true;
}

// 1) Public SEO config should be exposed and reachable.
$publicSeo = requestJson($kernel, 'GET', '/api/v1/cms/seo/config');
if (!assertStatus($publicSeo, 200, 'GET /api/v1/cms/seo/config')) {
    $failures++;
}

// 2) Basic DB-level sanity checks for new SEO settings tables.
try {
    $pdo = ConnectionFactory::createNewConnection();
    $requiredTables = [
        'seo_site_settings',
        'seo_social_settings',
        'seo_schema_settings',
        'seo_indexation_rules',
        'seo_eeat_defaults',
    ];
    $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $set = [];
    foreach ($rows as $row) {
        if (is_string($row)) {
            $set[$row] = true;
        }
    }
    foreach ($requiredTables as $table) {
        if (!isset($set[$table])) {
            fwrite(STDERR, "[FAIL] missing table {$table}\n");
            $failures++;
        } else {
            fwrite(STDOUT, "[OK] table {$table}\n");
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, "[FAIL] DB check failed: {$e->getMessage()}\n");
    $failures++;
}

// 3) Admin SEO endpoints check (optional unless strict).
$adminToken = trim((string) (getenv('SEO_SMOKE_ADMIN_BEARER') ?: ($_ENV['SEO_SMOKE_ADMIN_BEARER'] ?? '')));
if ($adminToken === '') {
    $msg = 'SEO_SMOKE_ADMIN_BEARER absent: admin endpoint checks skipped.';
    if ($strictAdmin) {
        fwrite(STDERR, "[FAIL] {$msg}\n");
        $failures++;
    } else {
        $warnings[] = $msg;
    }
} else {
    $headers = [
        'Authorization' => 'Bearer ' . $adminToken,
        'Content-Type' => 'application/json',
    ];

    $settings = requestJson($kernel, 'GET', '/api/v1/admin/seo/eeat/settings', null, $headers);
    if (!assertStatus($settings, 200, 'GET /api/v1/admin/seo/eeat/settings')) {
        $failures++;
    }

    $preview = requestJson($kernel, 'GET', '/api/v1/admin/seo/eeat/settings/preview?entityType=product', null, $headers);
    if (!assertStatus($preview, 200, 'GET /api/v1/admin/seo/eeat/settings/preview')) {
        $failures++;
    }

    $coverage = requestJson($kernel, 'GET', '/api/v1/admin/seo/eeat/coverage-report', null, $headers);
    if (!assertStatus($coverage, 200, 'GET /api/v1/admin/seo/eeat/coverage-report')) {
        $failures++;
    }
}

$result = [
    'ok' => $failures === 0,
    'failures' => $failures,
    'warnings' => $warnings,
    'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
];
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);

if ($failures > 0) {
    exit(1);
}

