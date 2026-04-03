<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\PdoEeatRepository;

try {
    loadBackendEnv();
    ConnectionFactory::createNewConnection();
    $repo = new PdoEeatRepository();
    $repo->ensureSeoSettingsDefaults();
    fwrite(STDOUT, json_encode(['ok' => true, 'message' => 'seo_defaults_ensured'], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => (string) $e->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

