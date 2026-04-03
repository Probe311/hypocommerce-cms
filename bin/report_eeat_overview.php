<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Persistence\PdoEeatRepository;

try {
    pdoFromArgv($argv);
    $repo = new PdoEeatRepository();
    $rows = $repo->listScores(5000, 0, null);
    $total = count($rows);
    $sum = 0.0;
    $grades = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
    foreach ($rows as $row) {
        $sum += (float) ($row['score_global'] ?? 0);
        $grade = (string) ($row['grade'] ?? 'D');
        if (isset($grades[$grade])) {
            $grades[$grade]++;
        }
    }
    $report = [
        'total' => $total,
        'averageScore' => $total > 0 ? round($sum / $total, 2) : 0.0,
        'grades' => $grades,
        'overview' => $repo->overviewStats(),
        'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
    ];
    fwrite(STDOUT, json_encode(['ok' => true, 'report' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
