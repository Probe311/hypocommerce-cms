<?php

declare(strict_types=1);

$report = dirname(__DIR__) . '/var/reports/clover.xml';
if (!is_file($report)) {
    fwrite(STDERR, "Coverage report not found: {$report}\n");
    exit(1);
}

$xml = simplexml_load_file($report);
if ($xml === false) {
    fwrite(STDERR, "Invalid coverage report XML.\n");
    exit(1);
}

$metrics = $xml->project->metrics ?? null;
if ($metrics === null) {
    fwrite(STDERR, "Coverage metrics missing.\n");
    exit(1);
}

$statements = (int) ($metrics['statements'] ?? 0);
$covered = (int) ($metrics['coveredstatements'] ?? 0);
if ($statements < 1) {
    fwrite(STDERR, "Coverage statements metric invalid.\n");
    exit(1);
}

$ratio = ($covered / $statements) * 100;
$threshold = 60.0;

fwrite(STDOUT, sprintf("Coverage: %.2f%% (threshold %.2f%%)\n", $ratio, $threshold));
if ($ratio < $threshold) {
    fwrite(STDERR, "Coverage threshold not met.\n");
    exit(1);
}
