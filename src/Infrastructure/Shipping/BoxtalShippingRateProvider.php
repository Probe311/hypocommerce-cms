<?php

declare(strict_types=1);

namespace App\Infrastructure\Shipping;

use DateTimeImmutable;
use RuntimeException;

final class BoxtalShippingRateProvider
{
    private string $quoteEndpoint;
    private string $apiKey;
    private string $apiSecret;
    private string $originCountry;
    private string $originCity;
    private string $originPostCode;

    public function __construct(
        private readonly PluginShippingConfigResolver $configResolver = new PluginShippingConfigResolver()
    ) {
        $runtime = $this->configResolver->resolve('boxtal');
        $cfg = $runtime['config'];

        // Endpoint compatible Sendbox/Boxtal quote API (configurable).
        $this->quoteEndpoint = trim((string) ($cfg['boxtalQuoteEndpoint'] ?? ($_ENV['BOXTAL_QUOTE_ENDPOINT'] ?? 'https://live.sendbox.co/shipping/shipment_delivery_quote')));
        $this->apiKey = trim((string) ($cfg['apiKey'] ?? ($_ENV['BOXTAL_API_KEY'] ?? '')));
        $this->apiSecret = trim((string) ($cfg['apiSecret'] ?? ($_ENV['BOXTAL_API_SECRET'] ?? '')));

        $this->originCountry = strtoupper(trim((string) ($cfg['originCountry'] ?? ($_ENV['BOXTAL_ORIGIN_COUNTRY'] ?? 'FR'))));
        $this->originCity = trim((string) ($cfg['originCity'] ?? ($_ENV['BOXTAL_ORIGIN_CITY'] ?? 'Paris')));
        $this->originPostCode = trim((string) ($cfg['originPostCode'] ?? ($_ENV['BOXTAL_ORIGIN_POSTCODE'] ?? '75001')));
    }

    /**
     * @return array{price:float,currency:string,etaMinDays:int,etaMaxDays:int}|null
     */
    public function quote(string $toCountry, float $cartSubTotal): ?array
    {
        if ($this->quoteEndpoint === '' || $this->apiKey === '') {
            return null;
        }

        $payload = [
            'origin' => [
                'country' => $this->originCountry,
                'city' => $this->originCity,
                'post_code' => $this->originPostCode,
                'state' => $this->originCity,
                'street' => 'N/A',
                'street_line_2' => '',
                'phone' => '+33000000000',
                'first_name' => 'Hypo',
                'last_name' => 'Commerce',
            ],
            'destination' => [
                'country' => strtoupper(trim($toCountry)),
                'city' => 'N/A',
                'post_code' => '00000',
                'state' => 'N/A',
                'street' => 'N/A',
                'street_line_2' => '',
                'phone' => '+33000000000',
                'first_name' => 'Client',
                'last_name' => 'Client',
            ],
            'weight' => 1.0,
            'dimension' => ['length' => 10, 'width' => 10, 'height' => 10],
            'incoming_option' => 'pickup',
            'region' => $this->originCountry,
            'service_type' => strtoupper(trim($toCountry)) === $this->originCountry ? 'local' : 'international',
            'package_type' => 'general',
            'total_value' => max(1.0, $cartSubTotal),
            'currency' => 'EUR',
            'channel_code' => 'api',
            'pickup_date' => (new DateTimeImmutable('+1 day'))->format('Y-m-d'),
            'items' => [[
                'name' => 'Order item',
                'quantity' => 1,
                'value' => max(1.0, $cartSubTotal),
                'item_type' => 'general',
            ]],
            'service_code' => 'standard',
            'customs_option' => 'recipient',
        ];

        $headers = ['Content-Type: application/json', 'Authorization: ' . $this->apiKey];
        if ($this->apiSecret !== '') {
            $headers[] = 'X-API-SECRET: ' . $this->apiSecret;
        }

        $ch = curl_init($this->quoteEndpoint);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize Boxtal request');
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Unable to encode Boxtal payload');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 25,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('Boxtal empty response' . ($err !== '' ? ' (' . $err . ')' : ''));
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || $code < 200 || $code >= 300) {
            throw new RuntimeException('Boxtal API error HTTP ' . $code);
        }

        $rate = null;
        if (isset($json['rate']) && is_array($json['rate'])) {
            $rate = $json['rate'];
        } elseif (isset($json['rates']) && is_array($json['rates']) && isset($json['rates'][0]) && is_array($json['rates'][0])) {
            $rate = $json['rates'][0];
        }
        if (!is_array($rate)) {
            return null;
        }

        $fee = $rate['fee'] ?? $rate['base_fee'] ?? null;
        if (!is_numeric($fee)) {
            return null;
        }

        return [
            'price' => (float) $fee,
            'currency' => strtoupper((string) ($rate['currency'] ?? ($json['currency'] ?? 'EUR'))),
            'etaMinDays' => 2,
            'etaMaxDays' => 5,
        ];
    }
}

