<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Persistence\PdoEeatRepository;

try {
    pdoFromArgv($argv);
    $repo = new PdoEeatRepository();
    $report = $repo->coverageAuditReport();
    $items = is_array($report['items'] ?? null) ? $report['items'] : [];
    $backlog = is_array($report['backlogPrioritized'] ?? null) ? $report['backlogPrioritized'] : [];

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'count' => count($items),
        'backlogCount' => count($backlog),
        'report' => $report,
        'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

