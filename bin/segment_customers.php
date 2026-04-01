<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = pdoFromArgv($argv);
$now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

$sql = "SELECT c.id,
               COUNT(DISTINCT o.id) AS orders_count,
               COALESCE(SUM(o.total), 0) AS revenue_total
        FROM customers c
        LEFT JOIN orders o ON o.customer_id = c.id AND o.status IN ('paid','fulfilled','refunded')
        GROUP BY c.id";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($rows)) {
    fwrite(STDERR, "No customers found.\n");
    exit(1);
}

$upsert = $pdo->prepare(
    'INSERT INTO customer_segments (customer_id, segment_code, score, computed_at)
     VALUES (:customer_id, :segment_code, :score, :computed_at)
     ON DUPLICATE KEY UPDATE score = VALUES(score), computed_at = VALUES(computed_at)'
);

$count = 0;
foreach ($rows as $row) {
    $orders = (int) ($row['orders_count'] ?? 0);
    $revenue = (float) ($row['revenue_total'] ?? 0);
    $segment = 'new';
    $score = 10;
    if ($orders >= 5 || $revenue >= 500) {
        $segment = 'vip';
        $score = 100;
    } elseif ($orders >= 2 || $revenue >= 150) {
        $segment = 'returning';
        $score = 50;
    }
    $upsert->execute([
        'customer_id' => (string) $row['id'],
        'segment_code' => $segment,
        'score' => $score,
        'computed_at' => $now,
    ]);
    $count++;
}

fwrite(STDOUT, "Customer segments updated: {$count}\n");
