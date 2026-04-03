<?php

declare(strict_types=1);

namespace App\Plugin\Registry;

use App\Application\Shared\HookDispatcher;
use App\Infrastructure\Persistence\PdoAdminPluginRepository;
use App\Plugin\Boxtal\BoxtalShippingPlugin;
use App\Plugin\Chronopost\ChronopostShippingPlugin;
use App\Plugin\Colissimo\ColissimoShippingPlugin;
use App\Plugin\Contract\PaymentProviderPluginInterface;
use App\Plugin\Contract\ShippingPluginInterface;
use App\Plugin\DhlExpress\DhlExpressShippingPlugin;
use App\Plugin\MondialRelay\MondialRelayShippingPlugin;
use App\Plugin\Paypal\PaypalPaymentPlugin;
use App\Plugin\RelaisColis\RelaisColisShippingPlugin;
use App\Plugin\Sendcloud\SendcloudShippingPlugin;
use App\Plugin\Stripe\StripePaymentPlugin;
use App\Plugin\TntFedex\TntFedexShippingPlugin;

final class PluginRegistry
{
    /** @var list<ShippingPluginInterface> */
    private array $shippingPlugins;

    /** @var list<PaymentProviderPluginInterface> */
    private array $paymentPlugins;

    public function __construct(
        private readonly PdoAdminPluginRepository $pluginRepository = new PdoAdminPluginRepository(),
        ?array $shippingPlugins = null,
        ?array $paymentPlugins = null,
    ) {
        $this->shippingPlugins = $shippingPlugins ?? self::defaultShippingPlugins();
        $this->paymentPlugins = $paymentPlugins ?? self::defaultPaymentPlugins();
    }

    /**
     * @return list<ShippingPluginInterface>
     */
    public static function defaultShippingPlugins(): array
    {
        return [
            new BoxtalShippingPlugin(),
            new SendcloudShippingPlugin(),
            new ColissimoShippingPlugin(),
            new MondialRelayShippingPlugin(),
            new RelaisColisShippingPlugin(),
            new ChronopostShippingPlugin(),
            new TntFedexShippingPlugin(),
            new DhlExpressShippingPlugin(),
        ];
    }

    /**
     * @return list<PaymentProviderPluginInterface>
     */
    public static function defaultPaymentPlugins(): array
    {
        return [
            new StripePaymentPlugin(),
            new PaypalPaymentPlugin(),
        ];
    }

    /**
     * @return list<array{id:string,label:string,carrier:string,price:float,etaMinDays:int,etaMaxDays:int}>
     */
    public function enabledExtraShippingMethods(string $country, float $cartSubTotal): array
    {
        $out = [];
        foreach ($this->shippingPlugins as $plugin) {
            if (!$this->pluginRepository->isEnabled($plugin->key())) {
                continue;
            }
            if (!$this->isPluginRoutable($plugin->key(), $country . ':' . (string) $cartSubTotal)) {
                continue;
            }
            $startedAt = microtime(true);
            foreach ($plugin->extraShippingMethods($country, $cartSubTotal) as $row) {
                $out[] = $row;
            }
            try {
                HookDispatcher::dispatch('plugin.shipping.quote', [
                    'pluginKey' => $plugin->key(),
                    'country' => strtoupper(trim($country)),
                    'cartSubTotal' => $cartSubTotal,
                    'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
                    'methodsCount' => count($out),
                ]);
            } catch (\Throwable) {
                // No-op: monitoring event must never break checkout flow.
            }
        }

        return $out;
    }

    /**
     * @return list<PaymentProviderPluginInterface>
     */
    public function paymentPlugins(): array
    {
        return $this->paymentPlugins;
    }

    /**
     * @return list<ShippingPluginInterface>
     */
    public function shippingPlugins(): array
    {
        return $this->shippingPlugins;
    }

    private function isPluginRoutable(string $pluginKey, string $seed): bool
    {
        $runtime = $this->pluginRepository->getRuntimeConfig($pluginKey);
        if ($runtime === null) {
            return true;
        }
        $config = is_array($runtime['config'] ?? null) ? $runtime['config'] : [];
        $rollout = (int) ($config['rolloutPercent'] ?? 100);
        $rollout = max(0, min(100, $rollout));
        if ($rollout >= 100) {
            return true;
        }
        if ($rollout <= 0) {
            return false;
        }
        $bucket = (int) (hexdec(substr(sha1($pluginKey . '|' . $seed), 0, 2)) % 100);
        return $bucket < $rollout;
    }
}
