<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Product\AttributeValue;
use App\Infrastructure\Database\ConnectionFactory;
use PDO;

final class PdoAttributeValueRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    public function findById(int $id): ?AttributeValue
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_attribute_values WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @return AttributeValue[]
     */
    public function findByAttributeId(int $attributeId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_attribute_values WHERE attribute_id = :aid ORDER BY value');
        $stmt->execute(['aid' => $attributeId]);

        $out = [];
        while ($row = $stmt->fetch()) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): AttributeValue
    {
        return new AttributeValue(
            (int) $row['id'],
            (int) $row['attribute_id'],
            $row['value'],
            $row['slug']
        );
    }
}

