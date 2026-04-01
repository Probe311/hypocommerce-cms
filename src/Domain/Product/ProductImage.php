<?php

declare(strict_types=1);

namespace App\Domain\Product;

use Ramsey\Uuid\UuidInterface;

final class ProductImage
{
    public function __construct(
        private int $id,
        private UuidInterface $productId,
        private string $url,
        private ?string $alt,
        private int $position
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function productId(): UuidInterface
    {
        return $this->productId;
    }

    public function url(): string
    {
        return $this->url;
    }

    public function alt(): ?string
    {
        return $this->alt;
    }

    public function position(): int
    {
        return $this->position;
    }
}
