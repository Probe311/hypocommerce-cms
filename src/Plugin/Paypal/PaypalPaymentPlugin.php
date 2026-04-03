<?php

declare(strict_types=1);

namespace App\Plugin\Paypal;

use App\Plugin\Contract\PaymentProviderPluginInterface;

final class PaypalPaymentPlugin implements PaymentProviderPluginInterface
{
    public function key(): string
    {
        return 'paypal';
    }
}
