<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Persistence\PdoEeatRepository;
use App\Infrastructure\Database\ConnectionFactory;

try {
    ConnectionFactory::createNewConnection();
    $days = isset($argv[1]) && is_numeric($argv[1]) ? max(1, min(30, (int) $argv[1])) : 3;
    $trendLimit = isset($argv[2]) && is_numeric($argv[2]) ? max(1, min(100, (int) $argv[2])) : 20;
    $outputPath = isset($argv[3]) && is_string($argv[3]) && trim($argv[3]) !== '' ? trim($argv[3]) : null;

    $repo = new PdoEeatRepository();
    $report = [
        'digest' => $repo->digestStats($days),
        'progress' => $repo->progressStats(),
        'sla' => $repo->slaStats(),
        'coverage' => $repo->coverageAuditReport(),
        'opportunities' => $repo->listOpportunities(30, 0, null),
        'quickWins' => $repo->listQuickWins(30, 0, null),
        'runTrends' => $repo->runTrends($trendLimit),
        'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
    ];
    $payload = json_encode(['ok' => true, 'report' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('json_encode_failed');
    }

    if ($outputPath !== null) {
        $written = @file_put_contents($outputPath, $payload . PHP_EOL);
        if ($written === false) {
            throw new RuntimeException('output_write_failed');
        }
    }

    fwrite(STDOUT, $payload . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    $errorPayload = json_encode(['ok' => false, 'error' => (string) $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    fwrite(STDERR, (is_string($errorPayload) ? $errorPayload : '{"ok":false,"error":"serialization_error"}') . PHP_EOL);
    exit(1);
}

