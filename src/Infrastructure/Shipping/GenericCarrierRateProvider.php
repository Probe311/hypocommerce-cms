<?php

declare(strict_types=1);

namespace App\Infrastructure\Shipping;

use RuntimeException;

final class GenericCarrierRateProvider
{
    public function __construct(
        private readonly PluginShippingConfigResolver $configResolver = new PluginShippingConfigResolver()
    ) {
    }

    /**
     * @return array{price:float,currency:string,etaMinDays:int,etaMaxDays:int}|null
     */
    public function quote(string $pluginKey, string $toCountry, float $cartSubTotal): ?array
    {
        $envPrefix = strtoupper(str_replace('-', '_', $pluginKey));
        $runtime = $this->configResolver->resolve($pluginKey);
        $cfg = is_array($runtime['config'] ?? null) ? $runtime['config'] : [];
        $apiBase = rtrim((string) ($cfg['apiBase'] ?? ($_ENV[$envPrefix . '_API_BASE'] ?? '')), '/');
        $quotePath = trim((string) ($cfg['quotePath'] ?? ($_ENV[$envPrefix . '_QUOTE_PATH'] ?? '/quotes')));
        $apiKey = trim((string) ($cfg['apiKey'] ?? ($_ENV[$envPrefix . '_API_KEY'] ?? '')));
        $accountId = trim((string) ($cfg['accountId'] ?? ($_ENV[$envPrefix . '_ACCOUNT_ID'] ?? '')));
        if ($apiBase === '' || $apiKey === '' || $accountId === '') {
            return null;
        }

        $url = $apiBase . '/' . ltrim($quotePath, '/');
        $payload = [
            'accountId' => $accountId,
            'destinationCountry' => strtoupper(trim($toCountry)),
            'weightKg' => max(0.1, (float) ($cfg['defaultWeightKg'] ?? 1.0)),
            'amount' => max(1.0, $cartSubTotal),
            'currency' => strtoupper((string) ($cfg['currency'] ?? 'EUR')),
        ];
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($rawBody === false) {
            throw new RuntimeException('Unable to encode generic carrier payload');
        }

        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'X-Account-Id: ' . $accountId,
        ];
        if (isset($cfg['apiSecret']) && trim((string) $cfg['apiSecret']) !== '') {
            $headers[] = 'X-Api-Secret: ' . trim((string) $cfg['apiSecret']);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize generic carrier request');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Generic carrier empty response' . ($err !== '' ? ' (' . $err . ')' : ''));
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || $code < 200 || $code >= 300) {
            throw new RuntimeException('Generic carrier API error HTTP ' . $code);
        }

        $price = $json['price'] ?? ($json['amount'] ?? null);
        if (!is_numeric($price)) {
            return null;
        }
        return [
            'price' => (float) $price,
            'currency' => strtoupper((string) ($json['currency'] ?? ($payload['currency'] ?? 'EUR'))),
            'etaMinDays' => max(1, (int) ($json['etaMinDays'] ?? 2)),
            'etaMaxDays' => max((int) ($json['etaMinDays'] ?? 2), (int) ($json['etaMaxDays'] ?? 5)),
        ];
    }
}

