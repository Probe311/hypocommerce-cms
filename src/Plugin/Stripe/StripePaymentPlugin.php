<?php

declare(strict_types=1);

namespace App\Plugin\Stripe;

use App\Plugin\Contract\PaymentProviderPluginInterface;

final class StripePaymentPlugin implements PaymentProviderPluginInterface
{
    public function key(): string
    {
        return 'stripe';
    }
}
