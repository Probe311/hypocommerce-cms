<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;
use PDOException;

final class PdoIdempotencyRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    public function findResourceId(string $scope, string $key): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT resource_id FROM idempotency_keys WHERE scope = :scope AND key_hash = :key_hash LIMIT 1'
        );
        $stmt->execute([
            'scope' => $scope,
            'key_hash' => $this->hashKey($key),
        ]);

        $resourceId = $stmt->fetchColumn();
        if (!is_string($resourceId) || $resourceId === '') {
            return null;
        }

        return $resourceId;
    }

    public function markOnce(string $scope, string $key, ?string $resourceId = null): bool
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO idempotency_keys (scope, key_hash, resource_id, created_at, updated_at)
                 VALUES (:scope, :key_hash, :resource_id, :created_at, :updated_at)'
            );
            $stmt->execute([
                'scope' => $scope,
                'key_hash' => $this->hashKey($key),
                'resource_id' => $resourceId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function hashKey(string $key): string
    {
        return hash('sha256', trim($key));
    }
}
