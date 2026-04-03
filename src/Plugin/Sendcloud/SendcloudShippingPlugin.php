<?php

declare(strict_types=1);

namespace App\Plugin\Sendcloud;

use App\Infrastructure\Shipping\SendcloudShippingRateProvider;
use App\Plugin\Contract\ShippingPluginInterface;

final class SendcloudShippingPlugin implements ShippingPluginInterface
{
    public function __construct(
        private readonly SendcloudShippingRateProvider $rateProvider = new SendcloudShippingRateProvider()
    ) {
    }

    public function key(): string
    {
        return 'sendcloud';
    }

    public function extraShippingMethods(string $country, float $cartSubTotal): array
    {
        try {
            $quote = $this->rateProvider->quote($country);
            if (is_array($quote)) {
                return [[
                    'id' => 'sendcloud-live',
                    'label' => 'Sendcloud',
                    'carrier' => 'sendcloud',
                    'price' => max(0.0, (float) ($quote['price'] ?? 0.0)),
                    'etaMinDays' => (int) ($quote['etaMinDays'] ?? 2),
                    'etaMaxDays' => (int) ($quote['etaMaxDays'] ?? 5),
                ]];
            }
        } catch (\Throwable) {
            // Fallback local en cas d'indisponibilité API.
        }

        return [[
            'id' => 'sendcloud-flex',
            'label' => 'Sendcloud',
            'carrier' => 'sendcloud',
            'price' => $cartSubTotal >= 99.0 ? 0.0 : 5.90,
            'etaMinDays' => 2,
            'etaMaxDays' => 5,
        ]];
    }
}
