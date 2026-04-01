<?php

declare(strict_types=1);

namespace App\Application\Payment\Provider;

use App\Domain\Order\Order;

interface PaymentProviderInterface
{
    public function key(): string;

    /**
     * @return array{id:string,url:string,status:string,metadata?:array<string,mixed>}
     */
    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array;
}
