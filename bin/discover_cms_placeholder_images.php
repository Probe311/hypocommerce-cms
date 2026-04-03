<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/discover_cms_placeholder_images.php <host> <db> <user> <password> [--only-host <motif>] [--limit <n>]\n");
    exit(1);
}

$onlyHostMotif = 'placehold.co';
$limit = 200;

for ($i = 5; $i < $argc; $i++) {
    $arg = $argv[$i] ?? '';
    if ($arg === '--only-host' && isset($argv[$i + 1])) {
        $onlyHostMotif = (string) $argv[$i + 1];
        $i++;
        continue;
    }
    if ($arg === '--limit' && isset($argv[$i + 1]) && is_numeric((string) $argv[$i + 1])) {
        $limit = max(1, (int) $argv[$i + 1]);
        $i++;
        continue;
    }
}

try {
    $pdo = pdoFromArgv($argv);
    $scanner = new \App\Application\Cms\CmsPlaceholderScanner($pdo);
    $report = $scanner->discover([
        'onlyHostMotif' => $onlyHostMotif,
        'limit' => $limit,
    ]);

    // Human-friendly summary
    $mediaCount = count($report['mediaCandidates'] ?? []);
    $occCount = (int) ($report['occurrencesCount'] ?? 0);
    fwrite(STDOUT, "Discovered placeholder candidates: {$mediaCount}\n");
    fwrite(STDOUT, "Discovered placeholder occurrences in cms_page_sections.payload: {$occCount}\n");

    // JSON report for tooling
    fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Discovery failed: ' . $e->getMessage() . "\n");
    exit(1);
}

