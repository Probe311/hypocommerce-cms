<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use RuntimeException;
use Stripe\Checkout\Session;
use Stripe\Stripe;

final class StripePaymentProvider
{
    private const ALLOWED_MODES = ['payment', 'setup', 'subscription'];

    public function __construct()
    {
        $secretKey = $_ENV['STRIPE_SECRET_KEY'] ?? null;
        if ($secretKey === null) {
            throw new RuntimeException('STRIPE_SECRET_KEY is not configured');
        }

        Stripe::setApiKey($secretKey);
    }

    public function createCheckoutSession(string $orderId, int $amountCents, string $currency, string $successUrl, string $cancelUrl): Session
    {
        return $this->createCheckoutSessionAdvanced(
            $orderId,
            [
                [
                    'name' => 'Order ' . $orderId,
                    'amountCents' => $amountCents,
                    'quantity' => 1,
                ],
            ],
            $currency,
            $successUrl,
            $cancelUrl,
            'payment',
            ['order_id' => $orderId]
        );
    }

    /**
     * @param array<int,array{name:string,amountCents:int,quantity:int}> $lineItems
     * @param array<string,string> $metadata
     */
    public function createCheckoutSessionAdvanced(
        string $orderId,
        array $lineItems,
        string $currency,
        string $successUrl,
        string $cancelUrl,
        string $mode = 'payment',
        array $metadata = []
    ): Session {
        if (!in_array($mode, self::ALLOWED_MODES, true)) {
            throw new RuntimeException('Unsupported checkout mode');
        }
        if ($currency === '') {
            throw new RuntimeException('Currency is required');
        }
        if ($lineItems === []) {
            throw new RuntimeException('At least one line item is required');
        }

        $stripeLineItems = [];
        foreach ($lineItems as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            $amountCents = (int) ($item['amountCents'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            if ($name === '' || $amountCents < 1 || $quantity < 1) {
                throw new RuntimeException('Invalid line item');
            }
            $stripeLineItems[] = [
                'price_data' => [
                    'currency' => strtolower($currency),
                    'product_data' => [
                        'name' => $name,
                    ],
                    'unit_amount' => $amountCents,
                ],
                'quantity' => $quantity,
            ];
        }

        $metadata = array_merge(['order_id' => $orderId], $metadata);

        return Session::create([
            'mode' => $mode,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $orderId,
            'metadata' => $metadata,
            'line_items' => $stripeLineItems,
        ]);
    }
}
