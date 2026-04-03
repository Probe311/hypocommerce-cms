<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    $targetDir = dirname(__DIR__) . '/var/reports';
    $keepDays = 14;

    foreach (array_slice($argv, 1) as $arg) {
        if (!is_string($arg)) {
            continue;
        }
        if (str_starts_with($arg, '--dir=')) {
            $candidate = trim(substr($arg, strlen('--dir=')));
            if ($candidate !== '') {
                $targetDir = $candidate;
            }
            continue;
        }
        if (str_starts_with($arg, '--keep-days=')) {
            $keepDays = max(1, min(365, (int) substr($arg, strlen('--keep-days='))));
        }
    }

    if (!is_dir($targetDir)) {
        fwrite(STDOUT, json_encode([
            'ok' => true,
            'deleted' => 0,
            'kept' => 0,
            'scanned' => 0,
            'message' => 'directory_not_found',
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    $cutoff = (new DateTimeImmutable('-' . $keepDays . ' days'))->getTimestamp();
    $patterns = [
        'eeat_daily_suite_*.json',
        'eeat_daily_ops*.json',
        'eeat_coverage*.json',
    ];

    $files = [];
    foreach ($patterns as $pattern) {
        $matches = glob(rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $pattern);
        if (!is_array($matches)) {
            continue;
        }
        foreach ($matches as $file) {
            if (is_string($file)) {
                $files[$file] = true;
            }
        }
    }

    $deleted = 0;
    $kept = 0;
    foreach (array_keys($files) as $file) {
        $mtime = @filemtime($file);
        if (!is_int($mtime)) {
            $kept++;
            continue;
        }
        if ($mtime < $cutoff) {
            if (@unlink($file)) {
                $deleted++;
            } else {
                $kept++;
            }
            continue;
        }
        $kept++;
    }

    fwrite(STDOUT, json_encode([
        'ok' => true,
        'deleted' => $deleted,
        'kept' => $kept,
        'scanned' => count($files),
        'keepDays' => $keepDays,
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => (string) $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

