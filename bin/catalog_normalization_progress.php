<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = pdoFromArgv($argv);

$published = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'published'")->fetchColumn();
$normalized = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'published' AND normalized_name_fr IS NOT NULL")->fetchColumn();

fwrite(STDOUT, sprintf("published=%d normalized=%d\n", $published, $normalized));

