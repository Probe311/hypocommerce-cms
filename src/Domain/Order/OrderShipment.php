<?php

declare(strict_types=1);

namespace App\Domain\Order;

final class OrderShipment
{
    public function __construct(
        private int $id,
        private int $orderId,
        private ?string $carrier,
        private ?string $trackingNumber,
        private string $status
    ) {
    }
}

