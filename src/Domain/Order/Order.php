<?php

declare(strict_types=1);

namespace App\Domain\Order;

use Ramsey\Uuid\UuidInterface;

final class Order
{
    /**
     * @param OrderItem[] $items
     */
    public function __construct(
        private UuidInterface $id,
        private string $number,
        private ?UuidInterface $customerId,
        private string $status,
        private float $total,
        private float $subTotal,
        private float $taxTotal,
        private float $shippingTotal,
        private float $discountTotal,
        private string $currency,
        private ?string $paymentMethod,
        private ?string $shippingMethod,
        private \DateTimeImmutable $placedAt,
        private array $items = []
    ) {
    }

    public function id(): UuidInterface
    {
        return $this->id;
    }

    public function number(): string
    {
        return $this->number;
    }

    public function customerId(): ?UuidInterface
    {
        return $this->customerId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function total(): float
    {
        return $this->total;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function paymentMethod(): ?string
    {
        return $this->paymentMethod;
    }

    /**
     * @return OrderItem[]
     */
    public function items(): array
    {
        return $this->items;
    }
}

