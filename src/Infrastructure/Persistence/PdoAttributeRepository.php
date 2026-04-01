<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Product\Attribute;
use App\Infrastructure\Database\ConnectionFactory;
use PDO;

final class PdoAttributeRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    public function findById(int $id): ?Attribute
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_attributes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findBySlug(string $slug): ?Attribute
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_attributes WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @return Attribute[]
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM product_attributes ORDER BY name');
        $out = [];

        while ($row = $stmt->fetch()) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): Attribute
    {
        return new Attribute(
            (int) $row['id'],
            $row['name'],
            $row['slug']
        );
    }
}
