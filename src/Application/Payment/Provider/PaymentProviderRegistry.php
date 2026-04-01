<?php

declare(strict_types=1);

namespace App\Application\Payment\Provider;

final class PaymentProviderRegistry
{
    /**
     * @param iterable<PaymentProviderInterface> $providers
     */
    public function __construct(iterable $providers)
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    /**
     * @var array<string,PaymentProviderInterface>
     */
    private array $providers = [];

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function get(string $key): ?PaymentProviderInterface
    {
        return $this->providers[$key] ?? null;
    }
}
