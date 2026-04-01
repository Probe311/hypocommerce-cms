<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Product\ProductVariant;
use App\Infrastructure\Database\ConnectionFactory;
use PDO;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class PdoProductVariantRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    public function findById(UuidInterface $id): ?ProductVariant
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_variants WHERE id = :id');
        $stmt->execute(['id' => $id->toString()]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @return ProductVariant[]
     */
    public function findByProductId(UuidInterface $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_variants WHERE product_id = :pid ORDER BY id');
        $stmt->execute(['pid' => $productId->toString()]);

        $out = [];
        while ($row = $stmt->fetch()) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): ProductVariant
    {
        return new ProductVariant(
            Uuid::fromString($row['id']),
            Uuid::fromString($row['product_id']),
            $row['sku'],
            $row['name'],
            (float) $row['price'],
            (int) $row['stock_qty'],
            $row['weight'] !== null ? (float) $row['weight'] : null,
            $row['attributes'] ? json_decode($row['attributes'], true, 512, JSON_THROW_ON_ERROR) : []
        );
    }
}

