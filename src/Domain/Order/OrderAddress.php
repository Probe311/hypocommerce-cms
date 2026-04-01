<?php

declare(strict_types=1);

namespace App\Domain\Order;

final class OrderAddress
{
    public function __construct(
        private int $id,
        private int $orderId,
        private string $type,
        private string $line1,
        private ?string $line2,
        private string $city,
        private string $postcode,
        private ?string $state,
        private string $country,
        private ?string $phone,
        private ?string $company,
        private string $firstName,
        private string $lastName
    ) {
    }
}

