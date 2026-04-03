<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Persistence\PdoEeatRepository;

try {
    pdoFromArgv($argv);
    $limit = isset($argv[5]) && is_numeric($argv[5]) ? max(1, min(500, (int) $argv[5])) : 100;
    $owner = isset($argv[6]) && is_string($argv[6]) && trim($argv[6]) !== '' ? trim($argv[6]) : null;
    $repo = new PdoEeatRepository();
    $report = [
        'sla' => $repo->slaStats(),
        'overdue' => $repo->listOverdueRecommendations($limit, 0, $owner),
        'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
    ];
    fwrite(STDOUT, json_encode(['ok' => true, 'report' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
