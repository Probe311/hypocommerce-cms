<?php

declare(strict_types=1);

namespace App\Infrastructure\Shipping;

use RuntimeException;

final class SendcloudShippingRateProvider
{
    private string $apiBase;
    private string $apiKey;
    private string $apiSecret;
    private int $shippingMethodId;
    private ?int $contractId;

    public function __construct(
        private readonly PluginShippingConfigResolver $configResolver = new PluginShippingConfigResolver()
    ) {
        $runtime = $this->configResolver->resolve('sendcloud');
        $cfg = $runtime['config'];
        $this->apiBase = rtrim((string) ($cfg['sendcloudApiBase'] ?? ($_ENV['SENDCLOUD_API_BASE'] ?? 'https://panel.sendcloud.sc/api/v2')), '/');
        $this->apiKey = trim((string) ($cfg['apiKey'] ?? ($_ENV['SENDCLOUD_API_KEY'] ?? '')));
        $this->apiSecret = trim((string) ($cfg['apiSecret'] ?? ($_ENV['SENDCLOUD_API_SECRET'] ?? '')));
        $this->shippingMethodId = (int) ($cfg['shippingMethodId'] ?? ($cfg['sendcloudShippingMethodId'] ?? ($_ENV['SENDCLOUD_SHIPPING_METHOD_ID'] ?? 0)));
        $contract = (int) ($cfg['contractId'] ?? ($_ENV['SENDCLOUD_CONTRACT_ID'] ?? 0));
        $this->contractId = $contract > 0 ? $contract : null;
    }

    /**
     * @return array{price:float,currency:string,etaMinDays:int,etaMaxDays:int}|null
     */
    public function quote(string $toCountry): ?array
    {
        if ($this->apiKey === '' || $this->apiSecret === '' || $this->shippingMethodId < 1) {
            return null;
        }

        $query = [
            'shipping_method_id' => $this->shippingMethodId,
            'from_country' => 'FR',
            'to_country' => strtoupper(trim($toCountry)),
            'weight' => 1,
            'weight_unit' => 'kilograms',
        ];
        if ($this->contractId !== null) {
            $query['contract'] = $this->contractId;
        }

        $url = $this->apiBase . '/shipping-price?' . http_build_query($query);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize Sendcloud request');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->apiKey . ':' . $this->apiSecret,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Sendcloud empty response' . ($err !== '' ? ' (' . $err . ')' : ''));
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || $code < 200 || $code >= 300) {
            throw new RuntimeException('Sendcloud API error HTTP ' . $code);
        }

        $entry = null;
        if (isset($json['price']) && is_array($json['price']) && isset($json['price'][0]) && is_array($json['price'][0])) {
            $entry = $json['price'][0];
        } elseif (isset($json[0]) && is_array($json[0])) {
            $entry = $json[0];
        } elseif (isset($json['data'][0]) && is_array($json['data'][0])) {
            $entry = $json['data'][0];
        }

        if (!is_array($entry)) {
            return null;
        }

        $priceRaw = $entry['price'] ?? null;
        if (!is_numeric($priceRaw)) {
            return null;
        }

        return [
            'price' => (float) $priceRaw,
            'currency' => strtoupper((string) ($entry['currency'] ?? 'EUR')),
            // Sendcloud shipping-price ne fournit pas systématiquement une ETA exploitable ici.
            'etaMinDays' => 2,
            'etaMaxDays' => 5,
        ];
    }
}

