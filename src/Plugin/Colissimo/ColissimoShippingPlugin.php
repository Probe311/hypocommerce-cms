<?php

declare(strict_types=1);

namespace App\Plugin\Colissimo;

use App\Infrastructure\Shipping\GenericCarrierRateProvider;
use App\Plugin\Contract\ShippingPluginInterface;

final class ColissimoShippingPlugin implements ShippingPluginInterface
{
    public function __construct(
        private readonly GenericCarrierRateProvider $rateProvider = new GenericCarrierRateProvider()
    ) {
    }

    public function key(): string
    {
        return 'colissimo';
    }

    public function extraShippingMethods(string $country, float $cartSubTotal): array
    {
        try {
            $quote = $this->rateProvider->quote($this->key(), $country, $cartSubTotal);
            if (is_array($quote)) {
                return [[
                    'id' => 'colissimo-live',
                    'label' => 'Colissimo',
                    'carrier' => 'colissimo',
                    'price' => max(0.0, (float) ($quote['price'] ?? 0.0)),
                    'etaMinDays' => (int) ($quote['etaMinDays'] ?? 2),
                    'etaMaxDays' => (int) ($quote['etaMaxDays'] ?? 4),
                ]];
            }
        } catch (\Throwable) {
        }

        return [[
            'id' => 'colissimo-flex',
            'label' => 'Colissimo',
            'carrier' => 'colissimo',
            'price' => $cartSubTotal >= 79.0 ? 0.0 : 6.90,
            'etaMinDays' => 2,
            'etaMaxDays' => 4,
        ]];
    }
}
