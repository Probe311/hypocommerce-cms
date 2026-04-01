<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$pdo = pdoFromArgv($argv);

$sql = "SELECT
            o.id,
            o.number,
            o.status,
            o.total,
            COALESCE(SUM(CASE WHEN r.status IN ('requested', 'succeeded') THEN r.amount ELSE 0 END), 0) AS refunded_total
        FROM orders o
        LEFT JOIN order_refunds r ON r.order_id = o.id
        GROUP BY o.id, o.number, o.status, o.total
        HAVING refunded_total > o.total + 0.00001
            OR (refunded_total >= o.total - 0.00001 AND o.status <> 'refunded')
        ORDER BY o.placed_at DESC";

$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($rows) || $rows === []) {
    fwrite(STDOUT, "Refund reconciliation OK.\n");
    exit(0);
}

fwrite(STDERR, "Refund reconciliation mismatches:\n");
foreach ($rows as $row) {
    fwrite(
        STDERR,
        sprintf(
            "- order=%s number=%s status=%s total=%.2f refunded=%.2f\n",
            (string) ($row['id'] ?? ''),
            (string) ($row['number'] ?? ''),
            (string) ($row['status'] ?? ''),
            (float) ($row['total'] ?? 0),
            (float) ($row['refunded_total'] ?? 0)
        )
    );
}
exit(1);
