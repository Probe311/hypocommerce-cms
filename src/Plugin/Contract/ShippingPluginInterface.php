<?php

declare(strict_types=1);

namespace App\Plugin\Contract;

interface ShippingPluginInterface
{
    public function key(): string;

    /**
     * Méthodes de livraison supplémentaires lorsque le plugin est activé (hors tarification API réelle).
     *
     * @return list<array{id:string,label:string,carrier:string,price:float,etaMinDays:int,etaMaxDays:int}>
     */
    public function extraShippingMethods(string $country, float $cartSubTotal): array;
}
