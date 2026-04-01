<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (($argv[1] ?? null) === null) {
    fwrite(STDERR, "Usage: php bin/rgpd_anonymize_customer.php <customer_id_or_email>\n");
    exit(1);
}

$needle = (string) $argv[1];
$pdo = pdoFromArgv($argv);

$customerStmt = str_contains($needle, '@')
    ? $pdo->prepare('SELECT id, email FROM customers WHERE email = :needle LIMIT 1')
    : $pdo->prepare('SELECT id, email FROM customers WHERE id = :needle LIMIT 1');
$customerStmt->execute(['needle' => $needle]);
$customer = $customerStmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($customer)) {
    fwrite(STDERR, "Customer not found.\n");
    exit(1);
}

$customerId = (string) $customer['id'];
$hash = substr(hash('sha256', $customerId), 0, 16);
$anonymousEmail = 'anonymized+' . $hash . '@example.invalid';

$pdo->beginTransaction();
try {
    $updateCustomer = $pdo->prepare(
        'UPDATE customers
         SET email = :email, first_name = NULL, last_name = NULL
         WHERE id = :id'
    );
    $updateCustomer->execute([
        'id' => $customerId,
        'email' => $anonymousEmail,
    ]);

    $updateAddresses = $pdo->prepare(
        'UPDATE customer_addresses
         SET label = NULL, line1 = :line1, line2 = NULL, city = :city, postcode = :postcode, state = NULL, phone = NULL
         WHERE customer_id = :customer_id'
    );
    $updateAddresses->execute([
        'customer_id' => $customerId,
        'line1' => 'ANONYMIZED',
        'city' => 'ANONYMIZED',
        'postcode' => '00000',
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

fwrite(STDOUT, "Customer anonymized: {$customerId}\n");
