<?php

declare(strict_types=1);

namespace App\Domain\Order;

use Ramsey\Uuid\UuidInterface;

final class OrderItem
{
    public function __construct(
        private int $id,
        private UuidInterface $orderId,
        private UuidInterface $productId,
        private ?UuidInterface $variantId,
        private string $name,
        private int $quantity,
        private float $unitPrice,
        private float $total
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function orderId(): UuidInterface
    {
        return $this->orderId;
    }

    public function productId(): UuidInterface
    {
        return $this->productId;
    }

    public function variantId(): ?UuidInterface
    {
        return $this->variantId;
    }

    public function name(): string
    {
        return $this->name;
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
