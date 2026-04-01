<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = pdoFromArgv($argv);
$now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

$stmt = $pdo->prepare(
    "UPDATE cms_pages
     SET status = 'published', published_at = :now, updated_at = :now
     WHERE status = 'scheduled'
       AND scheduled_at IS NOT NULL
       AND scheduled_at <= :now"
);
$stmt->execute(['now' => $now]);

fwrite(STDOUT, sprintf("Scheduled pages published: %d\n", $stmt->rowCount()));
