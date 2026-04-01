<?php

declare(strict_types=1);

namespace App\Domain\Product;

use Ramsey\Uuid\UuidInterface;

final class ProductVariant
{
    public function __construct(
        private UuidInterface $id,
        private UuidInterface $productId,
        private string $sku,
        private string $name,
        private float $price,
        private int $stockQty,
        private ?float $weight,
        private array $attributes
    ) {
    }

    public function id(): UuidInterface
    {
        return $this->id;
    }

    public function productId(): UuidInterface
    {
        return $this->productId;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function price(): float
    {
        return $this->price;
    }

    public function stockQty(): int
    {
        return $this->stockQty;
    }

    public function weight(): ?float
    {
        return $this->weight;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }
}

