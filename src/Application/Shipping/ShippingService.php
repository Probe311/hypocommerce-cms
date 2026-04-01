<?php

declare(strict_types=1);

namespace App\Application\Shipping;

final class ShippingService
{
    /**
     * @return array<int,array{id:string,label:string,carrier:string,price:float,etaMinDays:int,etaMaxDays:int}>
     */
    public function availableMethods(string $country, float $cartSubTotal): array
    {
        $country = strtoupper(trim($country));
        if ($country === '') {
            $country = 'FR';
        }

        $zones = [
            'FR' => [
                ['id' => 'colissimo-home', 'label' => 'Colissimo Domicile', 'carrier' => 'colissimo', 'base' => 6.90, 'freeFrom' => 79.00, 'etaMin' => 2, 'etaMax' => 4],
                ['id' => 'mondial-relay', 'label' => 'Mondial Relay', 'carrier' => 'mondial_relay', 'base' => 4.90, 'freeFrom' => 59.00, 'etaMin' => 3, 'etaMax' => 5],
            ],
            'EU' => [
                ['id' => 'eu-standard', 'label' => 'EU Standard', 'carrier' => 'dhl', 'base' => 9.90, 'freeFrom' => 120.00, 'etaMin' => 3, 'etaMax' => 7],
            ],
            'INTL' => [
                ['id' => 'intl-priority', 'label' => 'International Priority', 'carrier' => 'dhl_express', 'base' => 19.90, 'freeFrom' => 200.00, 'etaMin' => 4, 'etaMax' => 10],
            ],
        ];

        $zone = $this->resolveZone($country);
        $methods = $zones[$zone];
        $result = [];
        foreach ($methods as $method) {
            $price = $cartSubTotal >= $method['freeFrom'] ? 0.0 : (float) $method['base'];
            $result[] = [
                'id' => $method['id'],
                'label' => $method['label'],
                'carrier' => $method['carrier'],
                'price' => $price,
                'etaMinDays' => (int) $method['etaMin'],
                'etaMaxDays' => (int) $method['etaMax'],
            ];
        }

        return $result;
    }

    private function resolveZone(string $country): string
    {
        if ($country === 'FR') {
            return 'FR';
        }
        $eu = ['BE', 'DE', 'ES', 'IT', 'NL', 'PT', 'IE', 'LU', 'AT', 'PL', 'SE', 'DK', 'FI', 'CZ'];
        if (in_array($country, $eu, true)) {
            return 'EU';
        }

        return 'INTL';
    }
}
