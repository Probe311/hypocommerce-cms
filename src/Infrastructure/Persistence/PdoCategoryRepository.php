<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Product\Category;
use App\Infrastructure\Database\ConnectionFactory;
use PDO;

final class PdoCategoryRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    public function findById(int $id): ?Category
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    public function findBySlug(string $slug): ?Category
    {
        $stmt = $this->pdo->prepare('SELECT * FROM product_categories WHERE slug = :slug');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @return Category[]
     */
    public function all(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM product_categories ORDER BY name');
        $out = [];

        while ($row = $stmt->fetch()) {
            $out[] = $this->hydrate($row);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): Category
    {
        return new Category(
            (int) $row['id'],
            $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            $row['name'],
            $row['slug']
        );
    }
}

