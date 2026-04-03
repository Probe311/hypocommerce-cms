<?php

declare(strict_types=1);

namespace App\Plugin\Chronopost;

use App\Infrastructure\Shipping\GenericCarrierRateProvider;
use App\Plugin\Contract\ShippingPluginInterface;

final class ChronopostShippingPlugin implements ShippingPluginInterface
{
    public function __construct(
        private readonly GenericCarrierRateProvider $rateProvider = new GenericCarrierRateProvider()
    ) {
    }

    public function key(): string
    {
        return 'chronopost';
    }

    public function extraShippingMethods(string $country, float $cartSubTotal): array
    {
        try {
            $quote = $this->rateProvider->quote($this->key(), $country, $cartSubTotal);
            if (is_array($quote)) {
                return [[
                    'id' => 'chronopost-live',
                    'label' => 'Chronopost',
                    'carrier' => 'chronopost',
                    'price' => max(0.0, (float) ($quote['price'] ?? 0.0)),
                    'etaMinDays' => (int) ($quote['etaMinDays'] ?? 1),
                    'etaMaxDays' => (int) ($quote['etaMaxDays'] ?? 3),
                ]];
            }
        } catch (\Throwable) {
        }

        return [[
            'id' => 'chronopost-flex',
            'label' => 'Chronopost',
            'carrier' => 'chronopost',
            'price' => $cartSubTotal >= 120.0 ? 0.0 : 8.90,
            'etaMinDays' => 1,
            'etaMaxDays' => 3,
        ]];
    }
}
