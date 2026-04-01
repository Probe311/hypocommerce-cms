<?php

declare(strict_types=1);

$testsDir = dirname(__DIR__) . '/tests';
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsDir));
$count = 0;
foreach ($files as $file) {
    if (!$file instanceof SplFileInfo) {
        continue;
    }
    if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
        $count++;
    }
}

$minimum = 6;
if ($count < $minimum) {
    fwrite(STDERR, "Test suite too small: {$count} < {$minimum}\n");
    exit(1);
}

fwrite(STDOUT, "Test suite size OK: {$count}\n");
