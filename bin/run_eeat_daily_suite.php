<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Application\SeoEeat\EEATAnalysisService;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\PdoEeatRepository;

try {
    loadBackendEnv();
    ConnectionFactory::createNewConnection();

    $days = 3;
    $trendLimit = 20;
    $outputDir = dirname(__DIR__) . '/var/reports';
    $recomputeChanged = false;
    $cleanupAfter = true;
    $keepDays = 14;

    foreach (array_slice($argv, 1) as $arg) {
        if (!is_string($arg)) {
            continue;
        }
        if ($arg === '--recompute-changed') {
            $recomputeChanged = true;
            continue;
        }
        if (str_starts_with($arg, '--days=')) {
            $days = max(1, min(30, (int) substr($arg, strlen('--days='))));
            continue;
        }
        if (str_starts_with($arg, '--trend-limit=')) {
            $trendLimit = max(1, min(100, (int) substr($arg, strlen('--trend-limit='))));
            continue;
        }
        if (str_starts_with($arg, '--output-dir=')) {
            $candidate = trim(substr($arg, strlen('--output-dir=')));
            if ($candidate !== '') {
                $outputDir = $candidate;
            }
            continue;
        }
        if ($arg === '--no-cleanup') {
            $cleanupAfter = false;
            continue;
        }
        if (str_starts_with($arg, '--keep-days=')) {
            $keepDays = max(1, min(365, (int) substr($arg, strlen('--keep-days='))));
        }
    }

    $recomputeResult = null;
    if ($recomputeChanged) {
        $since = (new DateTimeImmutable('-1 day'))->format('Y-m-d H:i:s');
        $recomputeResult = (new EEATAnalysisService())->recomputeChangedSince($since);
    }

    $repo = new PdoEeatRepository();
    $repo->ensureSeoSettingsDefaults();
    $report = [
        'recomputeChanged' => $recomputeResult,
        'overview' => $repo->overviewStats(),
        'digest' => $repo->digestStats($days),
        'progress' => $repo->progressStats(),
        'sla' => $repo->slaStats(),
        'coverage' => $repo->coverageAuditReport(),
        'opportunities' => $repo->listOpportunities(30, 0, null),
        'quickWins' => $repo->listQuickWins(30, 0, null),
        'runTrends' => $repo->runTrends($trendLimit),
        'generatedAt' => (new DateTimeImmutable())->format(DATE_ATOM),
    ];

    if (!is_dir($outputDir)) {
        @mkdir($outputDir, 0775, true);
    }
    $filePath = rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . 'eeat_daily_suite_' . (new DateTimeImmutable())->format('Ymd_His') . '.json';

    $payload = json_encode(['ok' => true, 'report' => $report], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        throw new RuntimeException('json_encode_failed');
    }
    $written = @file_put_contents($filePath, $payload . PHP_EOL);
    if ($written === false) {
        throw new RuntimeException('report_write_failed');
    }

    $cleanupResult = null;
    if ($cleanupAfter) {
        $cutoff = (new DateTimeImmutable('-' . $keepDays . ' days'))->getTimestamp();
        $matches = glob(rtrim($outputDir, '/\\') . DIRECTORY_SEPARATOR . 'eeat_daily_suite_*.json');
        $deleted = 0;
        if (is_array($matches)) {
            foreach ($matches as $match) {
                if (!is_string($match) || $match === $filePath) {
                    continue;
                }
                $mtime = @filemtime($match);
                if (is_int($mtime) && $mtime < $cutoff && @unlink($match)) {
                    $deleted++;
                }
            }
        }
        $cleanupResult = ['enabled' => true, 'keepDays' => $keepDays, 'deleted' => $deleted];
    } else {
        $cleanupResult = ['enabled' => false, 'keepDays' => $keepDays, 'deleted' => 0];
    }

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'output' => $filePath,
        'cleanup' => $cleanupResult,
        'generatedAt' => $report['generatedAt'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    $errorPayload = json_encode(['ok' => false, 'error' => (string) $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    fwrite(STDERR, (is_string($errorPayload) ? $errorPayload : '{"ok":false,"error":"serialization_error"}') . PHP_EOL);
    exit(1);
}

