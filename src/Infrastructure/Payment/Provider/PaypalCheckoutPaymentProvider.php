<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment\Provider;

use App\Application\Payment\Provider\PaymentProviderInterface;
use App\Domain\Order\Order;
use App\Infrastructure\Payment\PaypalPaymentProvider;

final class PaypalCheckoutPaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly PaypalPaymentProvider $paypal = new PaypalPaymentProvider()
    ) {
    }

    public function key(): string
    {
        return 'paypal';
    }

    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        $session = $this->paypal->createCheckoutSession(
            $order->id()->toString(),
            $order->total(),
            $order->currency(),
            $successUrl,
            $cancelUrl
        );

        return [
            'id' => $session['id'],
            'url' => $session['approvalUrl'],
            'status' => 'initiated',
            'metadata' => ['approval_url' => $session['approvalUrl']],
        ];
    }
}
