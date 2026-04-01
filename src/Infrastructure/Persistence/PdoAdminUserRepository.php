<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;

final class PdoAdminUserRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, password_hash, role, created_at, last_login_at
             FROM admin_users
             WHERE email = :email
             LIMIT 1'
        );
        $stmt->execute(['email' => strtolower(trim($email))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, role, created_at, last_login_at
             FROM admin_users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function updateLastLogin(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE admin_users SET last_login_at = :last_login_at WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'last_login_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
