<?php

declare(strict_types=1);

namespace App\Plugin\Runtime;

use App\Infrastructure\Persistence\PdoAdminPluginRepository;

final class PluginRuntimeInspector
{
    /**
     * @var array<string,array{type:string,capabilities:list<string>,required:list<string>,optional:list<string>}>
     */
    private array $contracts = [
        'stripe' => [
            'type' => 'payment',
            'capabilities' => ['checkout', 'webhook'],
            'required' => ['stripeSecretKey', 'stripeWebhookSecret'],
            'optional' => ['stripePublishableKey'],
        ],
        'paypal' => [
            'type' => 'payment',
            'capabilities' => ['checkout', 'webhook'],
            'required' => ['paypalClientId', 'paypalClientSecret', 'paypalWebhookId'],
            'optional' => ['paypalApiBase'],
        ],
        'boxtal' => [
            'type' => 'shipping',
            'capabilities' => ['quote'],
            'required' => ['apiKey'],
            'optional' => ['apiSecret', 'boxtalQuoteEndpoint', 'originCountry', 'originCity', 'originPostCode'],
        ],
        'sendcloud' => [
            'type' => 'shipping',
            'capabilities' => ['quote'],
            'required' => ['apiKey', 'apiSecret', 'shippingMethodId'],
            'optional' => ['sendcloudApiBase', 'contractId'],
        ],
        'colissimo' => [
            'type' => 'shipping',
            'capabilities' => ['quote'],
            'required' => ['apiKey', 'accountId'],
            'optional' => [],
        ],
        'mondial_relay' => [
            'type' => 'shipping',
            'capabilities' => ['quote'],
            'required' => ['apiKey', 'accountId'],
            'optional' => [],
        ],
        'relais_colis' => [
            'type' => 'shipping',
            'capabilities' => ['quote'],
            'required' => ['apiKey', 'accountId'],
            'optional' => [],
        ],
        'chronopost' => [
            'type' => 'shipping',
            'capabilities' => ['quote'],
            'required' => ['apiKey', 'accountId'],
            'optional' => [],
        ],
        'tnt_fedex' => [
            'type' => 'shipping',
            'capabilities' => ['quote'],
            'required' => ['apiKey', 'accountId'],
            'optional' => [],
        ],
        'dhl_express' => [
            'type' => 'shipping',
            'capabilities' => ['quote'],
            'required' => ['apiKey', 'accountId'],
            'optional' => [],
        ],
    ];

    public function __construct(
        private readonly PdoAdminPluginRepository $repo = new PdoAdminPluginRepository()
    ) {
    }

    /**
     * @return array{ok:bool,errors:list<string>,type:string,capabilities:list<string>,required:list<string>}
     */
    public function validate(string $pluginKey, ?array $config = null, ?string $mode = null): array
    {
        $key = strtolower(trim($pluginKey));
        $contract = $this->contracts[$key] ?? ['type' => 'other', 'capabilities' => [], 'required' => [], 'optional' => []];

        if ($config === null || $mode === null) {
            $runtime = $this->repo->getRuntimeConfig($key);
            $config = $runtime['config'] ?? [];
            $mode = (string) ($runtime['mode'] ?? 'sandbox');
        }
        if (!is_array($config)) {
            $config = [];
        }
        $mode = strtolower(trim((string) $mode));

        $errors = [];
        if (!in_array($mode, ['sandbox', 'live'], true)) {
            $errors[] = 'invalid_mode';
        }

        foreach ($contract['required'] as $field) {
            $v = $config[$field] ?? null;
            if (is_string($v)) {
                if (trim($v) === '') {
                    $errors[] = 'missing_' . $field;
                }
                continue;
            }
            if (is_int($v) || is_float($v)) {
                if ((float) $v <= 0) {
                    $errors[] = 'invalid_' . $field;
                }
                continue;
            }
            $errors[] = 'missing_' . $field;
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'type' => (string) $contract['type'],
            'capabilities' => $contract['capabilities'],
            'required' => $contract['required'],
        ];
    }

    /**
     * @return array<string,array{type:string,capabilities:list<string>,required:list<string>}>
     */
    public function contracts(): array
    {
        $out = [];
        foreach ($this->contracts as $key => $meta) {
            $out[$key] = [
                'type' => $meta['type'],
                'capabilities' => $meta['capabilities'],
                'required' => $meta['required'],
            ];
        }
        return $out;
    }
}

