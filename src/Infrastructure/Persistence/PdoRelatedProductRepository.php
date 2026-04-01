<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Product\RelatedProduct;
use App\Infrastructure\Database\ConnectionFactory;
use PDO;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class PdoRelatedProductRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @return RelatedProduct[]
     */
    public function findByProductId(UuidInterface $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_related WHERE product_id = :pid');
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
    private function hydrate(array $row): RelatedProduct
    {
        return new RelatedProduct(
            Uuid::fromString($row['product_id']),
            Uuid::fromString($row['related_id']),
            $row['type']
        );
    }
}

