<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Persistence\PdoEeatRepository;

try {
    pdoFromArgv($argv);
    $days = isset($argv[5]) && is_numeric($argv[5]) ? max(1, min(30, (int) $argv[5])) : 3;
    $limit = isset($argv[6]) && is_numeric($argv[6]) ? max(1, min(500, (int) $argv[6])) : 100;
    $owner = isset($argv[7]) && is_string($argv[7]) && trim($argv[7]) !== '' ? trim($argv[7]) : null;

    $repo = new PdoEeatRepository();
    $report = [
        'digest' => $repo->digestStats($days),
        'dueSoon' => $repo->listDueSoonRecommendations($days, $limit, 0, $owner),
        'overdue' => $repo->listOverdueRecommendations($limit, 0, $owner),
        'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
    ];
    fwrite(STDOUT, json_encode(['ok' => true, 'report' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
