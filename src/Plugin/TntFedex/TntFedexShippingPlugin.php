<?php

declare(strict_types=1);

namespace App\Plugin\TntFedex;

use App\Infrastructure\Shipping\GenericCarrierRateProvider;
use App\Plugin\Contract\ShippingPluginInterface;

final class TntFedexShippingPlugin implements ShippingPluginInterface
{
    public function __construct(
        private readonly GenericCarrierRateProvider $rateProvider = new GenericCarrierRateProvider()
    ) {
    }

    public function key(): string
    {
        return 'tnt_fedex';
    }

    public function extraShippingMethods(string $country, float $cartSubTotal): array
    {
        try {
            $quote = $this->rateProvider->quote($this->key(), $country, $cartSubTotal);
            if (is_array($quote)) {
                return [[
                    'id' => 'tnt-fedex-live',
                    'label' => 'TNT / FedEx',
                    'carrier' => 'tnt_fedex',
                    'price' => max(0.0, (float) ($quote['price'] ?? 0.0)),
                    'etaMinDays' => (int) ($quote['etaMinDays'] ?? 1),
                    'etaMaxDays' => (int) ($quote['etaMaxDays'] ?? 3),
                ]];
            }
        } catch (\Throwable) {
        }

        return [[
            'id' => 'tnt-fedex-flex',
            'label' => 'TNT / FedEx',
            'carrier' => 'tnt_fedex',
            'price' => $cartSubTotal >= 150.0 ? 0.0 : 9.90,
            'etaMinDays' => 1,
            'etaMaxDays' => 3,
        ]];
    }
}
