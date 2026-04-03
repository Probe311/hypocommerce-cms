<?php

declare(strict_types=1);

namespace App\Plugin\Boxtal;

use App\Infrastructure\Shipping\BoxtalShippingRateProvider;
use App\Plugin\Contract\ShippingPluginInterface;

final class BoxtalShippingPlugin implements ShippingPluginInterface
{
    public function __construct(
        private readonly BoxtalShippingRateProvider $rateProvider = new BoxtalShippingRateProvider()
    ) {
    }

    public function key(): string
    {
        return 'boxtal';
    }

    public function extraShippingMethods(string $country, float $cartSubTotal): array
    {
        try {
            $quote = $this->rateProvider->quote($country, $cartSubTotal);
            if (is_array($quote)) {
                return [[
                    'id' => 'boxtal-live',
                    'label' => 'Boxtal',
                    'carrier' => 'boxtal',
                    'price' => max(0.0, (float) ($quote['price'] ?? 0.0)),
                    'etaMinDays' => (int) ($quote['etaMinDays'] ?? 2),
                    'etaMaxDays' => (int) ($quote['etaMaxDays'] ?? 5),
                ]];
            }
        } catch (\Throwable) {
            // Fallback local en cas d'indisponibilité API.
        }

        return [[
            'id' => 'boxtal-flex',
            'label' => 'Boxtal Flex',
            'carrier' => 'boxtal',
            'price' => $cartSubTotal >= 99.0 ? 0.0 : 5.90,
            'etaMinDays' => 2,
            'etaMaxDays' => 4,
        ]];
    }
}
