<?php

declare(strict_types=1);

use App\Application\Auth\JwtService;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Http\Kernel;
use App\Infrastructure\Persistence\PdoEeatRepository;
use Symfony\Component\HttpFoundation\Request;

require __DIR__ . '/bootstrap.php';

loadBackendEnv();

$failures = 0;
$checks = [];

/**
 * @param array<string,string> $headers
 */
function callJson(Kernel $kernel, string $method, string $path, ?array $payload = null, array $headers = []): array
{
    $server = [];
    foreach ($headers as $name => $value) {
        $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $body = $payload === null ? '' : (json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}');
    $req = Request::create($path, $method, [], [], [], $server, $body);
    $res = $kernel->handle($req);
    $decoded = json_decode((string) $res->getContent(), true);
    return [
        'status' => $res->getStatusCode(),
        'json' => is_array($decoded) ? $decoded : [],
    ];
}

try {
    $pdo = ConnectionFactory::createNewConnection();
    $kernel = new Kernel();
    $repo = new PdoEeatRepository();
    $repo->ensureSeoSettingsDefaults();

    $public = callJson($kernel, 'GET', '/api/v1/cms/seo/config');
    $checks[] = ['name' => 'public_seo_config', 'status' => $public['status']];
    if ((int) $public['status'] !== 200) {
        $failures++;
    }

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $set = [];
    foreach ($tables as $t) {
        if (is_string($t)) {
            $set[$t] = true;
        }
    }
    foreach (['seo_site_settings', 'seo_social_settings', 'seo_schema_settings', 'seo_indexation_rules', 'seo_eeat_defaults'] as $table) {
        $ok = isset($set[$table]);
        $checks[] = ['name' => 'table_' . $table, 'status' => $ok ? 200 : 500];
        if (!$ok) {
            $failures++;
        }
    }

    $row = $pdo->query("SELECT id, role FROM admin_users WHERE role IN ('super_admin','admin') ORDER BY (role='super_admin') DESC, id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        $checks[] = ['name' => 'admin_user_exists', 'status' => 500];
        $failures++;
    } else {
        $token = (new JwtService())->createToken(sprintf('admin:%d:%s', (int) $row['id'], (string) $row['role']), 1800);
        $headers = ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'];
        foreach ([
            ['admin_settings', 'GET', '/api/v1/admin/seo/eeat/settings'],
            ['admin_preview', 'GET', '/api/v1/admin/seo/eeat/settings/preview?entityType=product'],
            ['admin_coverage', 'GET', '/api/v1/admin/seo/eeat/coverage-report'],
        ] as $item) {
            [$name, $method, $path] = $item;
            $result = callJson($kernel, $method, $path, null, $headers);
            $checks[] = ['name' => $name, 'status' => $result['status']];
            if ((int) $result['status'] !== 200) {
                $failures++;
            }
        }
    }
} catch (Throwable $e) {
    $checks[] = ['name' => 'runtime_exception', 'status' => 500, 'error' => $e->getMessage()];
    $failures++;
}

$result = [
    'ok' => $failures === 0,
    'failures' => $failures,
    'checks' => $checks,
    'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
];
fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === 0 ? 0 : 1);

