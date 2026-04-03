<?php

declare(strict_types=1);

namespace App\Plugin\Base;

use App\Plugin\Contract\ShippingPluginInterface;

abstract class EmptyShippingExtraPlugin implements ShippingPluginInterface
{
    public function extraShippingMethods(string $country, float $cartSubTotal): array
    {
        return [];
    }
}
