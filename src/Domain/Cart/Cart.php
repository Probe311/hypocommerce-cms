<?php

declare(strict_types=1);

namespace App\Domain\Cart;

use Ramsey\Uuid\UuidInterface;

final class Cart
{
    /**
     * @param CartItem[] $items
     */
    public function __construct(
        private UuidInterface $id,
        private ?UuidInterface $customerId,
        private ?string $sessionId,
        private string $currency,
        private array $items = []
    ) {
    }

    public function id(): UuidInterface
    {
        return $this->id;
    }

    public function customerId(): ?UuidInterface
    {
        return $this->customerId;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * @return CartItem[]
     */
    public function items(): array
    {
        return $this->items;
    }
}

