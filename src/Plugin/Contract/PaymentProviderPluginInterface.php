<?php

declare(strict_types=1);

namespace App\Plugin\Contract;

/**
 * Point d’extension pour les fournisseurs de paiement (Stripe, PayPal, etc.).
 * La logique checkout reste dans Infrastructure\Payment\Provider jusqu’à bascule progressive.
 */
interface PaymentProviderPluginInterface
{
    public function key(): string;
}
