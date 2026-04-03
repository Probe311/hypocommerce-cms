<?php

declare(strict_types=1);

namespace App\Plugin\RelaisColis;

use App\Infrastructure\Shipping\GenericCarrierRateProvider;
use App\Plugin\Contract\ShippingPluginInterface;

final class RelaisColisShippingPlugin implements ShippingPluginInterface
{
    public function __construct(
        private readonly GenericCarrierRateProvider $rateProvider = new GenericCarrierRateProvider()
    ) {
    }

    public function key(): string
    {
        return 'relais_colis';
    }

    public function extraShippingMethods(string $country, float $cartSubTotal): array
    {
        try {
            $quote = $this->rateProvider->quote($this->key(), $country, $cartSubTotal);
            if (is_array($quote)) {
                return [[
                    'id' => 'relais-colis-live',
                    'label' => 'Relais Colis',
                    'carrier' => 'relais_colis',
                    'price' => max(0.0, (float) ($quote['price'] ?? 0.0)),
                    'etaMinDays' => (int) ($quote['etaMinDays'] ?? 2),
                    'etaMaxDays' => (int) ($quote['etaMaxDays'] ?? 4),
                ]];
            }
        } catch (\Throwable) {
        }

        return [[
            'id' => 'relais-colis-flex',
            'label' => 'Relais Colis',
            'carrier' => 'relais_colis',
            'price' => $cartSubTotal >= 69.0 ? 0.0 : 5.50,
            'etaMinDays' => 2,
            'etaMaxDays' => 4,
        ]];
    }
}
