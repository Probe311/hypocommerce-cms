<?php

declare(strict_types=1);

namespace App\Domain\Cart;

use Ramsey\Uuid\UuidInterface;

final class CartItem
{
    public function __construct(
        private int $id,
        private UuidInterface $cartId,
        private UuidInterface $productId,
        private ?UuidInterface $variantId,
        private int $quantity,
        private float $unitPrice,
        private float $total
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function cartId(): UuidInterface
    {
        return $this->cartId;
    }

    public function productId(): UuidInterface
    {
        return $this->productId;
    }

    public function variantId(): ?UuidInterface
    {
        return $this->variantId;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function unitPrice(): float
    {
        return $this->unitPrice;
    }

    public function total(): float
    {
        return $this->total;
    }
}

