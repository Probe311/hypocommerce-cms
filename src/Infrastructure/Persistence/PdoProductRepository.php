<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Product\Product;
use App\Domain\Product\ProductRepository;
use App\Infrastructure\Database\ConnectionFactory;
use PDO;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class PdoProductRepository implements ProductRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    public function findById(UuidInterface $id): ?Product
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id->toString()]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrateProduct($row);
    }

    public function findBySlug(string $slug): ?Product
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrateProduct($row);
    }

    public function search(?string $query, int $limit = 20, int $offset = 0): array
    {
        $sql = 'SELECT * FROM products WHERE status = :status';
        $params = ['status' => 'published'];

        if ($query !== null && $query !== '') {
            $sql .= ' AND (name LIKE :q OR sku LIKE :q)';
            $params['q'] = '%' . $query . '%';
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('status', $params['status']);

        if (isset($params['q'])) {
            $stmt->bindValue('q', $params['q']);
        }

        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        $products = [];
        while ($row = $stmt->fetch()) {
            $products[] = $this->hydrateProduct($row);
        }

        return $products;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrateProduct(array $row): Product
    {
        return new Product(
            Uuid::fromString($row['id']),
            $row['sku'],
            $row['name'],
            $row['slug'],
            $row['description'],
            (float) $row['price'],
            $row['sale_price'] !== null ? (float) $row['sale_price'] : null,
            $row['status'],
            $row['type']
        );
    }
}
