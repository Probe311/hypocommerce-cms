<?php

declare(strict_types=1);

namespace App\Domain\Order;

final class OrderPayment
{
    public function __construct(
        private int $id,
        private int $orderId,
        private string $provider,
        private string $providerRef,
        private float $amount,
        private string $status
    ) {
    }
}
