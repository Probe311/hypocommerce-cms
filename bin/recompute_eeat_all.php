<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Application\SeoEeat\EEATAnalysisService;

try {
    pdoFromArgv($argv);
    $result = (new EEATAnalysisService())->recomputeAll();
    fwrite(STDOUT, json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
