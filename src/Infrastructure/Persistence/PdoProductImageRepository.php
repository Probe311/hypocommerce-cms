<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Product\ProductImage;
use App\Infrastructure\Database\ConnectionFactory;
use PDO;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class PdoProductImageRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @return ProductImage[]
     */
    public function findByProductId(UuidInterface $productId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_images WHERE product_id = :pid ORDER BY position');
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
    private function hydrate(array $row): ProductImage
    {
        return new ProductImage(
            (int) $row['id'],
            Uuid::fromString($row['product_id']),
            $row['url'],
            $row['alt'],
            (int) $row['position']
        );
    }
}
