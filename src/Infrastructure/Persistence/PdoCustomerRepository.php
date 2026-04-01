<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class PdoCustomerRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(UuidInterface $customerId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, first_name, last_name, default_billing_id, default_shipping_id, created_at, last_login_at
             FROM customers WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $customerId->toString()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, password_hash, first_name, last_name, default_billing_id, default_shipping_id, created_at, last_login_at
             FROM customers WHERE email = :email LIMIT 1'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function create(string $email, string $passwordHash, ?string $firstName, ?string $lastName): string
    {
        $id = Uuid::uuid4()->toString();
        $stmt = $this->pdo->prepare(
            'INSERT INTO customers (id, email, password_hash, first_name, last_name, default_billing_id, default_shipping_id, created_at, last_login_at)
             VALUES (:id, :email, :password_hash, :first_name, :last_name, NULL, NULL, :created_at, NULL)'
        );
        $stmt->execute([
            'id' => $id,
            'email' => strtolower(trim($email)),
            'password_hash' => $passwordHash,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    public function updateLastLogin(string $customerId): void
    {
        $stmt = $this->pdo->prepare('UPDATE customers SET last_login_at = :last_login_at WHERE id = :id');
        $stmt->execute([
            'id' => $customerId,
            'last_login_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function updateProfile(string $customerId, ?string $firstName, ?string $lastName): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE customers SET first_name = :first_name, last_name = :last_name WHERE id = :id'
        );
        $stmt->execute([
            'id' => $customerId,
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);
    }

    public function updatePassword(string $customerId, string $passwordHash): void
    {
        $stmt = $this->pdo->prepare('UPDATE customers SET password_hash = :password_hash WHERE id = :id');
        $stmt->execute([
            'id' => $customerId,
            'password_hash' => $passwordHash,
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listAddresses(string $customerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, customer_id, label, type, line1, line2, city, postcode, state, country, phone
             FROM customer_addresses
             WHERE customer_id = :customer_id
             ORDER BY id'
        );
        $stmt->execute(['customer_id' => $customerId]);
        /** @var array<int,array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * @param array<string,mixed> $address
     */
    public function upsertAddress(string $customerId, array $address): int
    {
        $id = isset($address['id']) ? (int) $address['id'] : 0;
        if ($id > 0) {
            $update = $this->pdo->prepare(
                'UPDATE customer_addresses
                 SET label = :label, type = :type, line1 = :line1, line2 = :line2, city = :city, postcode = :postcode, state = :state, country = :country, phone = :phone
                 WHERE id = :id AND customer_id = :customer_id'
            );
            $update->execute([
                'id' => $id,
                'customer_id' => $customerId,
                'label' => $address['label'] ?? null,
                'type' => $address['type'],
                'line1' => $address['line1'],
                'line2' => $address['line2'] ?? null,
                'city' => $address['city'],
                'postcode' => $address['postcode'],
                'state' => $address['state'] ?? null,
                'country' => strtoupper((string) $address['country']),
                'phone' => $address['phone'] ?? null,
            ]);
            return $id;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO customer_addresses (customer_id, label, type, line1, line2, city, postcode, state, country, phone)
             VALUES (:customer_id, :label, :type, :line1, :line2, :city, :postcode, :state, :country, :phone)'
        );
        $insert->execute([
            'customer_id' => $customerId,
            'label' => $address['label'] ?? null,
            'type' => $address['type'],
            'line1' => $address['line1'],
            'line2' => $address['line2'] ?? null,
            'city' => $address['city'],
            'postcode' => $address['postcode'],
            'state' => $address['state'] ?? null,
            'country' => strtoupper((string) $address['country']),
            'phone' => $address['phone'] ?? null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteAddress(string $customerId, int $addressId): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM customer_addresses WHERE id = :id AND customer_id = :customer_id');
        $stmt->execute([
            'id' => $addressId,
            'customer_id' => $customerId,
        ]);
        return $stmt->rowCount() > 0;
    }

    public function createPasswordReset(string $customerId, string $tokenHash, int $ttlSeconds = 3600): void
    {
        $expiresAt = (new DateTimeImmutable(sprintf('+%d seconds', $ttlSeconds)))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_resets (customer_id, token, expires_at, used_at)
             VALUES (:customer_id, :token, :expires_at, NULL)'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'token' => $tokenHash,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findValidPasswordReset(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, customer_id, expires_at, used_at FROM password_resets
             WHERE token = :token
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['token' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        if ($row['used_at'] !== null) {
            return null;
        }
        if (new DateTimeImmutable((string) $row['expires_at']) < new DateTimeImmutable()) {
            return null;
        }
        return $row;
    }

    public function markPasswordResetUsed(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE password_resets SET used_at = :used_at WHERE id = :id');
        $stmt->execute([
            'id' => $id,
            'used_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
