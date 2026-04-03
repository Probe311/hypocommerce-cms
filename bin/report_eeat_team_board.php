<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Persistence\PdoEeatRepository;

try {
    pdoFromArgv($argv);
    $repo = new PdoEeatRepository();
    $report = [
        'owners' => $repo->listRecommendationOwners(),
        'open' => $repo->listRecommendationsByStatus('open', 200, 0),
        'in_progress' => $repo->listRecommendationsByStatus('in_progress', 200, 0),
        'done' => $repo->listRecommendationsByStatus('done', 200, 0),
        'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
    ];
    fwrite(STDOUT, json_encode(['ok' => true, 'report' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
