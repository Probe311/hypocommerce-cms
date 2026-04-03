<?php

declare(strict_types=1);

namespace App\Application\Shipping;

use App\Plugin\Registry\PluginRegistry;

final class ShippingService
{
    public function __construct(
        private readonly PluginRegistry $pluginRegistry = new PluginRegistry(),
    ) {
    }

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

        foreach ($this->pluginRegistry->enabledExtraShippingMethods($country, $cartSubTotal) as $extra) {
            $result[] = $extra;
        }

        return $this->normalizeAndSortMethods($result);
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

    /**
     * @param array<int,array{id:string,label:string,carrier:string,price:float,etaMinDays:int,etaMaxDays:int}> $methods
     * @return array<int,array{id:string,label:string,carrier:string,price:float,etaMinDays:int,etaMaxDays:int}>
     */
    private function normalizeAndSortMethods(array $methods): array
    {
        $normalized = [];
        $seen = [];
        foreach ($methods as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $etaMin = max(1, (int) ($row['etaMinDays'] ?? 2));
            $etaMax = max($etaMin, (int) ($row['etaMaxDays'] ?? 5));
            $normalized[] = [
                'id' => $id,
                'label' => (string) ($row['label'] ?? $id),
                'carrier' => (string) ($row['carrier'] ?? 'unknown'),
                'price' => max(0.0, (float) ($row['price'] ?? 0.0)),
                'etaMinDays' => $etaMin,
                'etaMaxDays' => $etaMax,
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            if ($a['price'] === $b['price']) {
                return $a['etaMinDays'] <=> $b['etaMinDays'];
            }
            return $a['price'] <=> $b['price'];
        });

        return $normalized;
    }
}
