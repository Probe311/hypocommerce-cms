<?php

declare(strict_types=1);

namespace App\Plugin\DhlExpress;

use App\Infrastructure\Shipping\GenericCarrierRateProvider;
use App\Plugin\Contract\ShippingPluginInterface;

final class DhlExpressShippingPlugin implements ShippingPluginInterface
{
    public function __construct(
        private readonly GenericCarrierRateProvider $rateProvider = new GenericCarrierRateProvider()
    ) {
    }

    public function key(): string
    {
        return 'dhl_express';
    }

    public function extraShippingMethods(string $country, float $cartSubTotal): array
    {
        try {
            $quote = $this->rateProvider->quote($this->key(), $country, $cartSubTotal);
            if (is_array($quote)) {
                return [[
                    'id' => 'dhl-express-live',
                    'label' => 'DHL Express',
                    'carrier' => 'dhl_express',
                    'price' => max(0.0, (float) ($quote['price'] ?? 0.0)),
                    'etaMinDays' => (int) ($quote['etaMinDays'] ?? 1),
                    'etaMaxDays' => (int) ($quote['etaMaxDays'] ?? 3),
                ]];
            }
        } catch (\Throwable) {
        }

        return [[
            'id' => 'dhl-express-flex',
            'label' => 'DHL Express',
            'carrier' => 'dhl_express',
            'price' => $cartSubTotal >= 200.0 ? 0.0 : 19.90,
            'etaMinDays' => 1,
            'etaMaxDays' => 3,
        ]];
    }
}
