<?php

declare(strict_types=1);

namespace App\Infrastructure\Shipping;

use App\Infrastructure\Persistence\PdoAdminPluginRepository;

final class PluginShippingConfigResolver
{
    public function __construct(
        private readonly PdoAdminPluginRepository $pluginRepository = new PdoAdminPluginRepository()
    ) {
    }

    /**
     * @return array{mode:string,config:array<string,mixed>}
     */
    public function resolve(string $pluginKey): array
    {
        $runtime = $this->pluginRepository->getRuntimeConfig($pluginKey);
        if ($runtime === null) {
            return ['mode' => 'sandbox', 'config' => []];
        }

        return [
            'mode' => (string) ($runtime['mode'] ?? 'sandbox'),
            'config' => is_array($runtime['config'] ?? null) ? $runtime['config'] : [],
        ];
    }
}

