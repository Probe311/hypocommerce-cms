<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Persistence\PdoEeatRepository;

try {
    pdoFromArgv($argv);
    $dryRun = true;
    if (isset($argv[5]) && is_string($argv[5])) {
        $raw = strtolower(trim($argv[5]));
        $dryRun = !in_array($raw, ['0', 'false', 'no'], true);
    }
    $limit = isset($argv[6]) && is_numeric($argv[6]) ? max(1, min(500, (int) $argv[6])) : 100;
    $repo = new PdoEeatRepository();
    $result = $repo->autoPrioritizeCriticalOverdue($dryRun, $limit);

    $logLine = sprintf(
        "[%s] dryRun=%s updated=%d criticalOverdue=%d\n",
        (new DateTimeImmutable())->format(DATE_ATOM),
        $result['dryRun'] ? 'true' : 'false',
        (int) ($result['updated'] ?? 0),
        count($result['items'] ?? [])
    );
    @file_put_contents(dirname(__DIR__) . '/var/log/seo-alerts.log', $logLine, FILE_APPEND);

    fwrite(STDOUT, json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
