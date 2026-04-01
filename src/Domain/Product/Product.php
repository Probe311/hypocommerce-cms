<?php

declare(strict_types=1);

namespace App\Domain\Product;

use Ramsey\Uuid\UuidInterface;

final class Product
{
    public function __construct(
        private UuidInterface $id,
        private string $sku,
        private string $name,
        private string $slug,
        private ?string $description,
        private float $price,
        private ?float $salePrice,
        private string $status,
        private string $type
    ) {
    }

    public function id(): UuidInterface
    {
        return $this->id;
    }

    public function sku(): string
    {
        return $this->sku;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function price(): float
    {
        return $this->price;
    }

    public function salePrice(): ?float
    {
        return $this->salePrice;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function type(): string
    {
        return $this->type;
    }
}
