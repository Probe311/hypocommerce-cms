<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment\Provider;

use App\Application\Payment\Provider\PaymentProviderInterface;
use App\Domain\Order\Order;
use App\Infrastructure\Payment\StripePaymentProvider;

final class StripeCheckoutPaymentProvider implements PaymentProviderInterface
{
    public function __construct(
        private readonly StripePaymentProvider $stripe = new StripePaymentProvider()
    ) {
    }

    public function key(): string
    {
        return 'stripe';
    }

    public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
    {
        $lineItems = [];
        foreach ($order->items() as $item) {
            $lineItems[] = [
                'name' => $item->name(),
                'amountCents' => (int) round($item->unitPrice() * 100),
                'quantity' => $item->quantity(),
            ];
        }
        if ($lineItems === []) {
            $lineItems[] = [
                'name' => 'Order ' . $order->number(),
                'amountCents' => (int) round($order->total() * 100),
                'quantity' => 1,
            ];
        }

        $session = $this->stripe->createCheckoutSessionAdvanced(
            $order->id()->toString(),
            $lineItems,
            $order->currency(),
            $successUrl,
            $cancelUrl,
            'payment',
            ['order_number' => $order->number()]
        );

        return [
            'id' => (string) $session->id,
            'url' => (string) ($session->url ?? ''),
            'status' => 'initiated',
            'metadata' => ['checkout_url' => (string) ($session->url ?? '')],
        ];
    }
}
