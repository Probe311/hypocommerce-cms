<?php

declare(strict_types=1);

namespace App\Plugin\MondialRelay;

use App\Infrastructure\Shipping\GenericCarrierRateProvider;
use App\Plugin\Contract\ShippingPluginInterface;

final class MondialRelayShippingPlugin implements ShippingPluginInterface
{
    public function __construct(
        private readonly GenericCarrierRateProvider $rateProvider = new GenericCarrierRateProvider()
    ) {
    }

    public function key(): string
    {
        return 'mondial_relay';
    }

    public function extraShippingMethods(string $country, float $cartSubTotal): array
    {
        try {
            $quote = $this->rateProvider->quote($this->key(), $country, $cartSubTotal);
            if (is_array($quote)) {
                return [[
                    'id' => 'mondial-relay-live',
                    'label' => 'Mondial Relay',
                    'carrier' => 'mondial_relay',
                    'price' => max(0.0, (float) ($quote['price'] ?? 0.0)),
                    'etaMinDays' => (int) ($quote['etaMinDays'] ?? 3),
                    'etaMaxDays' => (int) ($quote['etaMaxDays'] ?? 5),
                ]];
            }
        } catch (\Throwable) {
        }

        return [[
            'id' => 'mondial-relay-flex',
            'label' => 'Mondial Relay',
            'carrier' => 'mondial_relay',
            'price' => $cartSubTotal >= 59.0 ? 0.0 : 4.90,
            'etaMinDays' => 3,
            'etaMaxDays' => 5,
        ]];
    }
}
