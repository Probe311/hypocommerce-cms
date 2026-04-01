<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/** @var PDO $pdo */
$pdo = pdoFromArgv($argv);

$recipient = null;
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--email=')) {
        $recipient = trim(substr((string) $arg, 8));
        break;
    }
}
if ($recipient === null || $recipient === '') {
    $recipient = $_ENV['LOW_STOCK_ALERT_EMAIL'] ?? '';
}

$stmt = $pdo->query(
    'SELECT i.id, i.product_id, i.variant_id, i.stock_qty, i.reserved_qty, i.low_stock_threshold, p.sku, p.name
     FROM inventory_items i
     INNER JOIN products p ON p.id = i.product_id
     WHERE (i.stock_qty - i.reserved_qty) <= i.low_stock_threshold
     ORDER BY (i.stock_qty - i.reserved_qty) ASC, p.name ASC'
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
if (!is_array($rows) || $rows === []) {
    fwrite(STDOUT, "Low stock check: no alerts.\n");
    exit(0);
}

$lines = [];
$lines[] = 'Low stock alerts:';
foreach ($rows as $row) {
    $available = (int) $row['stock_qty'] - (int) $row['reserved_qty'];
    $lines[] = sprintf(
        '- %s (%s) variant=%s available=%d threshold=%d',
        (string) $row['name'],
        (string) $row['sku'],
        (string) ($row['variant_id'] ?? 'none'),
        $available,
        (int) $row['low_stock_threshold']
    );
}
$message = implode(PHP_EOL, $lines) . PHP_EOL;
fwrite(STDOUT, $message);

if (is_string($recipient) && $recipient !== '') {
    $subject = '[Backend] Low stock alerts';
    $headers = 'Content-Type: text/plain; charset=UTF-8';
    $sent = @mail($recipient, $subject, $message, $headers);
    if ($sent) {
        fwrite(STDOUT, "Alert email sent to {$recipient}\n");
    } else {
        fwrite(STDERR, "Failed to send alert email to {$recipient}\n");
        exit(1);
    }
}
