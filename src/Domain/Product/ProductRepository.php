<?php

declare(strict_types=1);

namespace App\Domain\Product;

use Ramsey\Uuid\UuidInterface;

interface ProductRepository
{
    public function findById(UuidInterface $id): ?Product;

    public function findBySlug(string $slug): ?Product;

    /**
     * @return Product[]
     */
    public function search(?string $query, int $limit = 20, int $offset = 0): array;
}

