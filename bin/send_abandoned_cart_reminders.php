<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Application\Notification\TransactionalEmailService;

$pdo = pdoFromArgv($argv);
$mailer = new TransactionalEmailService();
$now = new DateTimeImmutable();
$threshold = $now->modify('-2 hours')->format('Y-m-d H:i:s');

$sql = 'SELECT c.id AS cart_id, c.customer_id, cu.email,
               SUM(ci.quantity) AS items_count,
               COALESCE(SUM(ci.total), 0) AS cart_total
        FROM carts c
        INNER JOIN cart_items ci ON ci.cart_id = c.id
        LEFT JOIN customers cu ON cu.id = c.customer_id
        LEFT JOIN abandoned_cart_reminders r ON r.cart_id = c.id
        WHERE c.created_at <= :threshold
          AND r.id IS NULL
          AND cu.email IS NOT NULL
        GROUP BY c.id, c.customer_id, cu.email';

$stmt = $pdo->prepare($sql);
$stmt->execute(['threshold' => $threshold]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$insert = $pdo->prepare(
    'INSERT INTO abandoned_cart_reminders (cart_id, customer_id, email, sent_at, status, payload)
     VALUES (:cart_id, :customer_id, :email, :sent_at, :status, :payload)'
);

$sent = 0;
foreach ((array) $rows as $row) {
    $email = (string) ($row['email'] ?? '');
    if ($email === '') {
        continue;
    }
    $ok = $mailer->send('abandoned_cart_reminder', $email, [
        'cart_id' => (string) ($row['cart_id'] ?? ''),
        'items_count' => (string) ($row['items_count'] ?? '0'),
        'cart_total' => (string) ($row['cart_total'] ?? '0'),
    ]);
    $insert->execute([
        'cart_id' => (string) ($row['cart_id'] ?? ''),
        'customer_id' => $row['customer_id'] ?? null,
        'email' => $email,
        'sent_at' => $now->format('Y-m-d H:i:s'),
        'status' => $ok ? 'sent' : 'failed',
        'payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    if ($ok) {
        $sent++;
    }
}

fwrite(STDOUT, 'Abandoned cart reminders processed: ' . count((array) $rows) . ", sent: {$sent}\n");
