<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (($argv[1] ?? null) === null) {
    fwrite(STDERR, "Usage: php bin/rgpd_export_customer.php <customer_id_or_email>\n");
    exit(1);
}

$needle = (string) $argv[1];
$pdo = pdoFromArgv($argv);

$customerStmt = str_contains($needle, '@')
    ? $pdo->prepare('SELECT * FROM customers WHERE email = :needle LIMIT 1')
    : $pdo->prepare('SELECT * FROM customers WHERE id = :needle LIMIT 1');
$customerStmt->execute(['needle' => $needle]);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($customer)) {
    fwrite(STDERR, "Customer not found.\n");
    exit(1);
}
$customerId = (string) $customer['id'];

$addresses = $pdo->prepare('SELECT * FROM customer_addresses WHERE customer_id = :customer_id ORDER BY id');
$addresses->execute(['customer_id' => $customerId]);
$orders = $pdo->prepare('SELECT * FROM orders WHERE customer_id = :customer_id ORDER BY placed_at DESC');
$orders->execute(['customer_id' => $customerId]);
$resets = $pdo->prepare('SELECT * FROM password_resets WHERE customer_id = :customer_id ORDER BY id DESC');
$resets->execute(['customer_id' => $customerId]);

$payload = [
    'customer' => $customer,
    'addresses' => $addresses->fetchAll(PDO::FETCH_ASSOC),
    'orders' => $orders->fetchAll(PDO::FETCH_ASSOC),
    'password_resets' => $resets->fetchAll(PDO::FETCH_ASSOC),
    'exported_at' => (new DateTimeImmutable())->format(DATE_ATOM),
];

fwrite(STDOUT, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
