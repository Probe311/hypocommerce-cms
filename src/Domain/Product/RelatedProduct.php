<?php

declare(strict_types=1);

namespace App\Domain\Product;

use Ramsey\Uuid\UuidInterface;

final class RelatedProduct
{
    public function __construct(
        private UuidInterface $productId,
        private UuidInterface $relatedId,
        private string $type
    ) {
    }

    public function productId(): UuidInterface
    {
        return $this->productId;
    }

    public function relatedId(): UuidInterface
    {
        return $this->relatedId;
    }

    public function type(): string
    {
        return $this->type;
    }
}
